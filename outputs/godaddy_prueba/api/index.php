<?php

declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

$configCandidates = array_filter([
    getenv('CHECK50M_CONFIG') ?: null,
    dirname(__DIR__, 3) . '/sealy_config.php',
    dirname(__DIR__, 3) . '/check50m_config.php',
    dirname(__DIR__, 2) . '/sealy_config.php',
    dirname(__DIR__, 2) . '/check50m_config.php',
    __DIR__ . '/config.php',
]);
$configPath = null;
foreach ($configCandidates as $candidate) {
    if (file_exists($candidate)) {
        $configPath = $candidate;
        break;
    }
}
if (!$configPath) {
    http_response_code(500);
    echo json_encode(['error' => 'Falta configuracion privada. Usa sealy_config.php fuera de public_html o api/config.php protegido.']);
    exit;
}

$config = require $configPath;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($config['cors_origin'] ?? '*'));
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '-06:00'");
    return $pdo;
}

function begin_csv_download(string $filename)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('No se pudo crear el reporte CSV');
    }
    fwrite($out, "\xEF\xBB\xBF");
    return $out;
}

function ensure_runtime_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $column = $pdo->query("SHOW COLUMNS FROM stores LIKE 'chain'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN chain VARCHAR(100) NULL AFTER id");
        $pdo->exec("CREATE INDEX idx_stores_chain_status ON stores (chain, status)");
    }
    $column = $pdo->query("SHOW COLUMNS FROM stores LIKE 'timezone'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City' AFTER allowed_radius_meters");
        $pdo->exec("CREATE INDEX idx_stores_timezone ON stores (timezone)");
    }
    $column = $pdo->query("SHOW COLUMNS FROM check_records LIKE 'timezone_at_check'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE check_records ADD COLUMN timezone_at_check VARCHAR(64) NULL AFTER checked_at");
        $pdo->exec("ALTER TABLE check_records ADD COLUMN timezone_offset_minutes SMALLINT NULL AFTER timezone_at_check");
        $pdo->exec("CREATE INDEX idx_check_timezone_at_check ON check_records (timezone_at_check)");
    }
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'requires_location_verification'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE users ADD COLUMN requires_location_verification TINYINT(1) NOT NULL DEFAULT 0 AFTER supervisor_id");
        $pdo->exec("CREATE INDEX idx_users_location_verification ON users (requires_location_verification)");
    }
    $pdo->exec(
        "ALTER TABLE check_records
         MODIFY phase ENUM('ingreso', 'salida_comer', 'entrada_comer', 'salida',
                           'verificacion_ubicacion_1', 'verificacion_ubicacion_2', 'verificacion_ubicacion_3') NOT NULL"
    );
    $pdo->exec(
        "ALTER TABLE check_attempts
         MODIFY phase ENUM('ingreso', 'salida_comer', 'entrada_comer', 'salida',
                           'verificacion_ubicacion_1', 'verificacion_ubicacion_2', 'verificacion_ubicacion_3') NOT NULL"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notices (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(160) NOT NULL,
          body TEXT NULL,
          image_path VARCHAR(255) NULL,
          status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
          created_by BIGINT UNSIGNED NOT NULL,
          published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_notices_status_date (status, published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function response_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        response_json(['error' => 'JSON invalido'], 400);
    }
    return $data;
}

function decode_data_file(string $value): string
{
    if (strpos($value, ',') !== false) {
        [, $value] = explode(',', $value, 2);
    }
    $bytes = base64_decode($value, true);
    if ($bytes === false || strlen($bytes) < 10) {
        response_json(['error' => 'Archivo invalido'], 400);
    }
    return $bytes;
}

function column_letters_to_index(string $letters): int
{
    $index = 0;
    foreach (str_split(strtoupper($letters)) as $char) {
        $index = $index * 26 + (ord($char) - 64);
    }
    return max(0, $index - 1);
}

function parse_xlsx_rows(string $fileBase64): array
{
    if (!class_exists('ZipArchive')) {
        response_json(['error' => 'El servidor no tiene soporte ZipArchive para leer Excel. Sube CSV o activa ZipArchive en PHP.'], 500);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'sealy_xlsx_');
    if ($tmp === false) {
        response_json(['error' => 'No se pudo preparar el archivo Excel'], 500);
    }
    file_put_contents($tmp, decode_data_file($fileBase64));
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        response_json(['error' => 'No se pudo abrir el archivo Excel. Usa formato .xlsx'], 400);
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = @simplexml_load_string($sharedXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);
    if ($sheetXml === false) {
        response_json(['error' => 'El Excel no contiene una hoja valida'], 400);
    }
    $xml = @simplexml_load_string($sheetXml);
    if (!$xml || !isset($xml->sheetData)) {
        response_json(['error' => 'No se pudo leer la hoja de Excel'], 400);
    }
    $rows = [];
    foreach ($xml->sheetData->row as $rowXml) {
        $row = [];
        foreach ($rowXml->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/^[A-Z]+/i', $ref, $match);
            $index = column_letters_to_index($match[0] ?? 'A');
            $type = (string)$cell['t'];
            if ($type === 's') {
                $value = $sharedStrings[(int)$cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)($cell->is->t ?? '');
            } else {
                $value = (string)($cell->v ?? '');
            }
            $row[$index] = trim($value);
        }
        if ($row) {
            ksort($row);
            $dense = [];
            for ($i = 0, $max = max(array_keys($row)); $i <= $max; $i++) {
                $dense[] = $row[$i] ?? '';
            }
            $rows[] = $dense;
        }
    }
    return $rows;
}

function parse_csv_rows(string $csv): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $rows[] = str_getcsv($line);
        }
    }
    return $rows;
}

function route_match(string $pattern, string $path): ?array
{
    $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
    if (preg_match($regex, $path, $matches) !== 1) {
        return null;
    }
    return $matches;
}

function route_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($base && $base !== '/' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');
    return $path === '/' ? '/' : $path;
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return null;
}

function current_token_hash(): ?string
{
    $token = bearer_token();
    return $token ? hash('sha256', $token) : null;
}

function auth_user(PDO $pdo): array
{
    $token = bearer_token();
    if (!$token) {
        response_json(['error' => 'Sesion requerida'], 401);
    }

    $stmt = $pdo->prepare(
        "SELECT u.*
         FROM auth_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch();
    if (!$user) {
        response_json(['error' => 'Sesion invalida o expirada'], 401);
    }
    return $user;
}

function verify_user_password(string $password, string $storedHash): bool
{
    if (strpos($storedHash, 'sha256$') === 0) {
        return hash_equals(substr($storedHash, 7), hash('sha256', $password));
    }

    return password_verify($password, $storedHash);
}

function require_role(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        response_json(['error' => 'No tienes permiso para esta accion'], 403);
    }
}

function nullable_int($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return (int)$value;
}

function require_admin(array $user): void
{
    require_role($user, ['admin']);
}

function require_text_field(array $data, string $key, string $label): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '') {
        response_json(['error' => $label . ' es obligatorio'], 400);
    }
    return $value;
}

function normalize_rfc(string $rfc): string
{
    $upper = strtoupper(trim($rfc));
    return preg_replace('/[^A-Z0-9&Ñ]/u', '', $upper) ?? '';
}

function require_rfc_field(array $data): string
{
    $rfc = normalize_rfc((string)($data['rfc'] ?? ''));
    if ($rfc === '') {
        response_json(['error' => 'El RFC es obligatorio'], 400);
    }
    if (strlen($rfc) > 13) {
        response_json(['error' => 'El RFC no debe exceder 13 caracteres'], 400);
    }
    return $rfc;
}

function require_email_field(array $data, string $key, string $label): string
{
    $value = require_text_field($data, $key, $label);
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        response_json(['error' => $label . ' no tiene un formato valido'], 400);
    }
    return $value;
}

function require_float_field(array $data, string $key, string $label): float
{
    if (!isset($data[$key]) || trim((string)$data[$key]) === '' || !is_numeric($data[$key])) {
        response_json(['error' => $label . ' es obligatorio'], 400);
    }
    return (float)$data[$key];
}

function require_status(?string $status): string
{
    $status = $status ?: 'active';
    if (!in_array($status, ['active', 'inactive'], true)) {
        response_json(['error' => 'Estado invalido'], 400);
    }
    return $status;
}

function timezone_options(): array
{
    return [
        'AUTO' => ['label' => 'Automatico por coordenadas', 'timezone' => 'AUTO'],
        'CENTRO' => ['label' => 'Centro / Monterrey', 'timezone' => 'America/Mexico_City'],
        'SONORA' => ['label' => 'Sonora / Hermosillo', 'timezone' => 'America/Hermosillo'],
        'PACIFICO' => ['label' => 'Pacifico / Sinaloa / BCS', 'timezone' => 'America/Mazatlan'],
        'BAJA' => ['label' => 'Baja California', 'timezone' => 'America/Tijuana'],
        'CANCUN' => ['label' => 'Quintana Roo / Cancun', 'timezone' => 'America/Cancun'],
        'FRONTERA_CENTRO' => ['label' => 'Frontera norte centro', 'timezone' => 'America/Matamoros'],
        'FRONTERA_CHIHUAHUA' => ['label' => 'Frontera Chihuahua', 'timezone' => 'America/Ojinaga'],
    ];
}

function first_available_timezone(array $candidates, string $fallback): string
{
    $available = timezone_identifiers_list();
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $available, true)) {
            return $candidate;
        }
    }
    return $fallback;
}

function external_timezone_from_coordinates(float $lat, float $lng, int $timestamp): ?string
{
    global $config;
    $key = trim((string)($config['google_timezone_api_key'] ?? getenv('GOOGLE_TIMEZONE_API_KEY') ?: ''));
    if ($key === '') {
        return null;
    }
    $url = 'https://maps.googleapis.com/maps/api/timezone/json?location=' .
        rawurlencode($lat . ',' . $lng) .
        '&timestamp=' . rawurlencode((string)$timestamp) .
        '&key=' . rawurlencode($key);
    $context = stream_context_create(['http' => ['timeout' => 4]]);
    $json = @file_get_contents($url, false, $context);
    if (!$json) {
        return null;
    }
    $data = json_decode($json, true);
    $timezone = (string)($data['timeZoneId'] ?? '');
    if (($data['status'] ?? '') === 'OK' && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
        return $timezone;
    }
    return null;
}

function infer_timezone_from_coordinates(float $lat, float $lng): string
{
    // Baja California uses US Pacific DST rules statewide.
    if ($lat >= 27.5 && $lat <= 33.5 && $lng >= -118.6 && $lng <= -114.0) {
        return 'America/Tijuana';
    }

    // Northern border municipalities with US DST alignment. This is an approximation
    // by coordinate band because the upload only includes latitude/longitude.
    if ($lat >= 30.8 && $lat <= 32.2 && $lng >= -109.5 && $lng < -106.0) {
        return first_available_timezone(['America/Ciudad_Juarez', 'America/Ojinaga'], 'America/Ojinaga');
    }
    if ($lat >= 27.0 && $lat <= 29.9 && $lng >= -106.0 && $lng <= -96.5) {
        return first_available_timezone(['America/Matamoros', 'America/Ojinaga'], 'America/Matamoros');
    }

    // Quintana Roo stays on Eastern time without DST.
    if ($lat >= 17.5 && $lat <= 22.0 && $lng >= -89.8 && $lng <= -86.5) {
        return 'America/Cancun';
    }

    // Sonora does not observe DST.
    if ($lat >= 26.0 && $lat <= 33.4 && $lng >= -115.5 && $lng <= -108.2) {
        return 'America/Hermosillo';
    }

    // Pacific zone: Baja California Sur and Sinaloa.
    if ($lat >= 22.0 && $lat <= 28.5 && $lng >= -115.5 && $lng <= -105.0) {
        return 'America/Mazatlan';
    }

    // Bahia de Banderas, Nayarit follows Central time.
    if ($lat >= 20.55 && $lat <= 20.95 && $lng >= -105.55 && $lng <= -105.05) {
        return 'America/Mexico_City';
    }

    // Nayarit mostly belongs to Pacific time.
    if ($lat >= 20.5 && $lat <= 23.2 && $lng >= -106.9 && $lng <= -104.2) {
        return 'America/Mazatlan';
    }

    return 'America/Mexico_City';
}

function resolve_timezone(?string $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $iana = str_replace(' ', '_', $raw);
    if (in_array($iana, timezone_identifiers_list(), true)) {
        return $iana;
    }
    foreach (timezone_identifiers_list() as $identifier) {
        if (strcasecmp($identifier, $iana) === 0) {
            return $identifier;
        }
    }

    $value = strtoupper($raw);
    if ($value === 'AUTO' || $value === 'AUTOMATICO') {
        return null;
    }
    $aliases = [
        'CDMX' => 'CENTRO',
        'MEXICO' => 'CENTRO',
        'MEXICO_CITY' => 'CENTRO',
        'MONTERREY' => 'CENTRO',
        'MTY' => 'CENTRO',
        'GUADALAJARA' => 'CENTRO',
        'GDL' => 'CENTRO',
        'CENTRO' => 'CENTRO',
        'AUTO' => 'AUTO',
        'AUTOMATICO' => 'AUTO',
        'HERMOSILLO' => 'SONORA',
        'SONORA' => 'SONORA',
        'PACIFICO' => 'PACIFICO',
        'SINALOA' => 'PACIFICO',
        'CULIACAN' => 'PACIFICO',
        'MAZATLAN' => 'PACIFICO',
        'BCS' => 'PACIFICO',
        'LA_PAZ' => 'PACIFICO',
        'BAJA' => 'BAJA',
        'TIJUANA' => 'BAJA',
        'MEXICALI' => 'BAJA',
        'CANCUN' => 'CANCUN',
        'QUINTANA_ROO' => 'CANCUN',
        'FRONTERA' => 'FRONTERA_CENTRO',
        'FRONTERA_CENTRO' => 'FRONTERA_CENTRO',
        'FRONTERA_CHIHUAHUA' => 'FRONTERA_CHIHUAHUA',
        'JUAREZ' => 'FRONTERA_CHIHUAHUA',
        'CD_JUAREZ' => 'FRONTERA_CHIHUAHUA',
        'CIUDAD_JUAREZ' => 'FRONTERA_CHIHUAHUA',
        'OJINAGA' => 'FRONTERA_CHIHUAHUA',
        'REYNOSA' => 'FRONTERA_CENTRO',
        'MATAMOROS' => 'FRONTERA_CENTRO',
        'NUEVO_LAREDO' => 'FRONTERA_CENTRO',
        'PIEDRAS_NEGRAS' => 'FRONTERA_CENTRO',
    ];
    $key = $aliases[$value] ?? $value;
    if ($key === 'AUTO') {
        return null;
    }
    $options = timezone_options();
    if (isset($options[$key])) {
        return $options[$key]['timezone'];
    }
    return null;
}

function normalize_timezone(?string $value): string
{
    $timezone = resolve_timezone($value);
    if ($timezone) {
        return $timezone;
    }
    response_json(['error' => 'Zona horaria invalida. Usa CENTRO, SONORA, PACIFICO, BAJA o CANCUN'], 400);
}

function timezone_for_store(?string $value, float $lat, float $lng): string
{
    $timezone = resolve_timezone($value);
    return $timezone
        ?: external_timezone_from_coordinates($lat, $lng, time())
        ?: infer_timezone_from_coordinates($lat, $lng);
}

function timezone_offset_minutes(int $timestamp, string $timezone): int
{
    $timezone = resolve_timezone($timezone) ?: 'America/Mexico_City';
    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($timezone));
    return (int)($date->getOffset() / 60);
}

function admin_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
}

function timezone_label(?string $timezone): string
{
    $timezone = $timezone ?: 'America/Mexico_City';
    foreach (timezone_options() as $key => $option) {
        if ($option['timezone'] === $timezone) {
            return $key . ' - ' . $option['label'];
        }
    }
    return $timezone;
}

function parse_store_time(string $value, string $timezone): int
{
    $timezone = resolve_timezone($timezone) ?: 'America/Mexico_City';
    $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
    try {
        $date = $hasOffset
            ? new DateTimeImmutable($value)
            : new DateTimeImmutable($value, new DateTimeZone($timezone));
        return $date->getTimestamp();
    } catch (Throwable $e) {
        return time();
    }
}

function local_datetime(int $timestamp, string $timezone): string
{
    $timezone = resolve_timezone($timezone) ?: 'America/Mexico_City';
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('Y-m-d H:i:s');
}

function check_datetime_label(?string $value, ?string $timezone): string
{
    if (!$value) {
        return '';
    }
    return $value . ' (' . timezone_label($timezone) . ')';
}

function convert_server_datetime_to_store(?string $value, ?string $timezone): string
{
    if (!$value) {
        return '';
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('America/Mexico_City'));
        return local_datetime($date->getTimestamp(), $timezone ?: 'America/Mexico_City');
    } catch (Throwable $e) {
        return $value;
    }
}

function display_check_datetime(array $row): string
{
    if (!empty($row['timezone_at_check'])) {
        return (string)($row['checked_at'] ?: ($row['captured_at_device'] ?? ''));
    }
    $source = (string)($row['source'] ?? '');
    if ($source === 'offline_sync') {
        return (string)($row['checked_at'] ?: ($row['captured_at_device'] ?? ''));
    }
    return convert_server_datetime_to_store(
        (string)($row['created_at'] ?? $row['checked_at'] ?? ''),
        (string)($row['store_timezone'] ?? 'America/Mexico_City')
    );
}

function can_manage_staff_user(PDO $pdo, array $user, int $targetId): array
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        response_json(['error' => 'Usuario no encontrado'], 404);
    }
    if ($user['role'] === 'admin') {
        return $target;
    }
    if ($target['role'] !== 'staff') {
        response_json(['error' => 'Los supervisores solo pueden modificar promotores'], 403);
    }
    return $target;
}

function haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function normalize_photo_path(?string $path): ?string
{
    if (!$path) {
        return null;
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    return strpos($path, 'uploads/') === 0 ? substr($path, 8) : $path;
}

function photo_absolute_path(?string $path, array $config): ?string
{
    $path = normalize_photo_path($path);
    if (!$path || !preg_match('#^checks/[0-9]{8}/[a-f0-9]{24}\.jpg$#', $path)) {
        return null;
    }
    return rtrim($config['upload_dir'], '/\\') . '/' . $path;
}

function notice_absolute_path(?string $path, array $config): ?string
{
    $path = normalize_photo_path($path);
    if (!$path || !preg_match('#^notices/[a-f0-9]{24}\.jpg$#', $path)) {
        return null;
    }
    return rtrim($config['upload_dir'], '/\\') . '/' . $path;
}

function save_photo(?string $photoBase64, array $config): ?string
{
    if (!$photoBase64) {
        return null;
    }

    if (strpos($photoBase64, ',') !== false) {
        [, $photoBase64] = explode(',', $photoBase64, 2);
    }

    $bytes = base64_decode($photoBase64, true);
    if ($bytes === false || strlen($bytes) < 100) {
        response_json(['error' => 'Foto invalida'], 400);
    }

    if (strlen($bytes) > 2 * 1024 * 1024) {
        response_json(['error' => 'La foto es demasiado grande'], 413);
    }

    $orientation = 1;
    if (function_exists('exif_read_data')) {
        $tmp = tempnam(sys_get_temp_dir(), 'sealy_photo_');
        if ($tmp !== false) {
            file_put_contents($tmp, $bytes);
            $exif = @exif_read_data($tmp);
            if (is_array($exif) && isset($exif['Orientation'])) {
                $orientation = (int)$exif['Orientation'];
            }
            @unlink($tmp);
        }
    }

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            response_json(['error' => 'Foto invalida'], 400);
        }
        if (function_exists('imagerotate')) {
            if ($orientation === 3) {
                $image = imagerotate($image, 180, 0);
            } elseif ($orientation === 6) {
                $image = imagerotate($image, -90, 0);
            } elseif ($orientation === 8) {
                $image = imagerotate($image, 90, 0);
            }
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $maxSide = 720;
        $scale = min(1, $maxSide / max($width, $height));
        if ($scale < 1) {
            $newWidth = max(1, (int)round($width * $scale));
            $newHeight = max(1, (int)round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }
        ob_start();
        imagejpeg($image, null, 58);
        $compressed = ob_get_clean();
        imagedestroy($image);
        if ($compressed !== false && strlen($compressed) > 100) {
            $bytes = $compressed;
        }
    }

    $day = date('Ymd');
    $dir = rtrim($config['upload_dir'], '/\\') . '/checks/' . $day;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        response_json(['error' => 'No se pudo crear la carpeta de fotos'], 500);
    }

    $name = bin2hex(random_bytes(12)) . '.jpg';
    $path = $dir . '/' . $name;
    file_put_contents($path, $bytes);

    return 'checks/' . $day . '/' . $name;
}

function save_notice_image(?string $imageBase64, array $config): ?string
{
    if (!$imageBase64) {
        return null;
    }
    if (strpos($imageBase64, ',') !== false) {
        [, $imageBase64] = explode(',', $imageBase64, 2);
    }
    $bytes = base64_decode($imageBase64, true);
    if ($bytes === false || strlen($bytes) < 100) {
        response_json(['error' => 'Imagen invalida'], 400);
    }
    if (strlen($bytes) > 2_000_000) {
        response_json(['error' => 'La imagen del aviso es demasiado grande'], 413);
    }

    $dir = rtrim($config['upload_dir'], '/\\') . '/notices';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        response_json(['error' => 'No se pudo crear la carpeta de avisos'], 500);
    }
    $name = bin2hex(random_bytes(12)) . '.jpg';
    file_put_contents($dir . '/' . $name, $bytes);
    return 'notices/' . $name;
}

function get_store(PDO $pdo, int $storeId): array
{
    $stmt = $pdo->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$storeId]);
    $store = $stmt->fetch();
    if (!$store) {
        response_json(['error' => 'Tienda no encontrada'], 404);
    }
    return $store;
}

function normalize_phase(string $phase): string
{
    $allowed = [
        'ingreso',
        'salida_comer',
        'entrada_comer',
        'salida',
        'verificacion_ubicacion_1',
        'verificacion_ubicacion_2',
        'verificacion_ubicacion_3',
    ];
    if (!in_array($phase, $allowed, true)) {
        response_json(['error' => 'Fase invalida'], 400);
    }
    return $phase;
}

function phase_order(): array
{
    return ['ingreso', 'salida_comer', 'entrada_comer', 'salida'];
}

function is_verification_phase(string $phase): bool
{
    return in_array($phase, ['verificacion_ubicacion_1', 'verificacion_ubicacion_2', 'verificacion_ubicacion_3'], true);
}

function validate_phase_sequence(PDO $pdo, int $userId, string $phase, string $checkDate, int $storeId, float $lat, float $lng, string $deviceId): void
{
    $order = phase_order();
    $index = array_search($phase, $order, true);
    if ($index === false) {
        return;
    }

    $stmt = $pdo->prepare("SELECT phase FROM check_records WHERE user_id = ? AND check_date = ?");
    $stmt->execute([$userId, $checkDate]);
    $checked = array_column($stmt->fetchAll(), 'phase');
    for ($i = 0; $i < $index; $i++) {
        if (!in_array($order[$i], $checked, true)) {
            $attempt = $pdo->prepare(
                "INSERT INTO check_attempts
                 (user_id, store_id, phase, attempted_at, latitude, longitude, distance_meters, reason, device_id)
                 VALUES (?, ?, ?, NOW(), ?, ?, NULL, 'invalid_order', ?)"
            );
            $attempt->execute([$userId, $storeId, $phase, $lat, $lng, $deviceId]);
            throw new RuntimeException('No puedes registrar ' . phase_label($phase) . ' sin registrar primero ' . phase_label($order[$i]));
        }
    }
}

function minutes_between(?string $start, ?string $end): ?int
{
    if (!$start || !$end) {
        return null;
    }
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if (!$startTs || !$endTs || $endTs < $startTs) {
        return null;
    }
    return (int)round(($endTs - $startTs) / 60);
}

function format_minutes(?int $minutes): string
{
    if ($minutes === null) {
        return '';
    }
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $rest);
}

function phase_label(string $phase): string
{
    return [
        'ingreso' => 'Ingreso',
        'salida_comer' => 'Salida a comer',
        'entrada_comer' => 'Entrada de comer',
        'salida' => 'Salida',
        'verificacion_ubicacion_1' => 'Verificación de ubicación 1',
        'verificacion_ubicacion_2' => 'Verificación de ubicación 2',
        'verificacion_ubicacion_3' => 'Verificación de ubicación 3',
    ][$phase] ?? $phase;
}

function status_label(string $status): string
{
    return [
        'valid' => 'Válida',
        'synced' => 'Sincronizada',
        'blocked_out_of_range' => 'Fuera de rango',
        'manual_review' => 'Revisión manual',
        'rejected' => 'Rechazada',
    ][$status] ?? $status;
}

function source_label(string $source): string
{
    return [
        'online' => 'En línea',
        'offline_sync' => 'Sincronizada offline',
        'manual' => 'Manual',
    ][$source] ?? $source;
}

function attach_work_times(array $rows): array
{
    $groups = [];
    foreach ($rows as $index => $row) {
        $key = $row['user_id'] . '|' . $row['check_date'];
        $groups[$key]['indexes'][] = $index;
        $groups[$key]['phases'][$row['phase']] = $row['display_checked_at'] ?? $row['checked_at'];
    }

    foreach ($groups as $group) {
        $phases = $group['phases'];
        $mealMinutes = minutes_between($phases['salida_comer'] ?? null, $phases['entrada_comer'] ?? null);
        $totalMinutes = minutes_between($phases['ingreso'] ?? null, $phases['salida'] ?? null);
        $workedMinutes = $totalMinutes === null ? null : max(0, $totalMinutes - ($mealMinutes ?? 0));
        foreach ($group['indexes'] as $index) {
            $rows[$index]['tiempo_laborado_minutos'] = $workedMinutes;
            $rows[$index]['tiempo_laborado'] = format_minutes($workedMinutes);
            $rows[$index]['tiempo_comida_minutos'] = $mealMinutes;
            $rows[$index]['tiempo_comida'] = format_minutes($mealMinutes);
        }
    }

    return $rows;
}

function photo_url(?string $path): ?string
{
    $path = normalize_photo_path($path);
    return $path ? '/photos/' . $path : null;
}

function notice_image_url(?string $path): ?string
{
    $path = normalize_photo_path($path);
    return $path ? '/notice-images/' . $path : null;
}

function build_daily_check_rows(array $rows): array
{
    $daily = [];
    foreach ($rows as $row) {
        $key = $row['user_id'] . '|' . $row['check_date'];
        if (!isset($daily[$key])) {
            $daily[$key] = [
                'fecha' => $row['check_date'],
                'user_id' => $row['user_id'],
                'promotor' => $row['staff_name'] ?? '',
                'rfc' => $row['staff_rfc'] ?? '',
                'supervisor' => $row['supervisor_name'] ?? '',
                'tiempo_laborado' => '',
                'tiempo_comida' => '',
                'ingreso' => '',
                'ingreso_cadena' => '',
                'ingreso_tienda' => '',
                'ingreso_distancia_m' => '',
                'ingreso_estado' => '',
                'ingreso_foto_url' => null,
                'salida_comer' => '',
                'salida_comer_cadena' => '',
                'salida_comer_tienda' => '',
                'salida_comer_distancia_m' => '',
                'salida_comer_estado' => '',
                'salida_comer_foto_url' => null,
                'entrada_comer' => '',
                'entrada_comer_cadena' => '',
                'entrada_comer_tienda' => '',
                'entrada_comer_distancia_m' => '',
                'entrada_comer_estado' => '',
                'entrada_comer_foto_url' => null,
                'salida' => '',
                'salida_cadena' => '',
                'salida_tienda' => '',
                'salida_distancia_m' => '',
                'salida_estado' => '',
                'salida_foto_url' => null,
                'verificacion_ubicacion_1' => '',
                'verificacion_ubicacion_1_cadena' => '',
                'verificacion_ubicacion_1_tienda' => '',
                'verificacion_ubicacion_1_distancia_m' => '',
                'verificacion_ubicacion_1_estado' => '',
                'verificacion_ubicacion_1_foto_url' => null,
                'verificacion_ubicacion_2' => '',
                'verificacion_ubicacion_2_cadena' => '',
                'verificacion_ubicacion_2_tienda' => '',
                'verificacion_ubicacion_2_distancia_m' => '',
                'verificacion_ubicacion_2_estado' => '',
                'verificacion_ubicacion_2_foto_url' => null,
                'verificacion_ubicacion_3' => '',
                'verificacion_ubicacion_3_cadena' => '',
                'verificacion_ubicacion_3_tienda' => '',
                'verificacion_ubicacion_3_distancia_m' => '',
                'verificacion_ubicacion_3_estado' => '',
                'verificacion_ubicacion_3_foto_url' => null,
            ];
        }
        $phase = $row['phase'];
        $displayCheckedAt = $row['display_checked_at'] ?? display_check_datetime($row);
        $phaseTimezone = $row['timezone_at_check'] ?? $row['store_timezone'] ?? null;
        $daily[$key][$phase] = check_datetime_label($displayCheckedAt, $phaseTimezone);
        $daily[$key][$phase . '_zona_horaria'] = timezone_label($phaseTimezone);
        $daily[$key][$phase . '_cadena'] = $row['store_chain'] ?? '';
        $daily[$key][$phase . '_tienda'] = $row['store_name'] ?? '';
        $daily[$key][$phase . '_distancia_m'] = $row['distance_meters'] ?? '';
        $daily[$key][$phase . '_estado'] = status_label((string)($row['status'] ?? ''));
        $daily[$key][$phase . '_foto_url'] = photo_url($row['photo_path'] ?? null);
    }

    foreach (attach_work_times($rows) as $row) {
        $key = $row['user_id'] . '|' . $row['check_date'];
        if (isset($daily[$key])) {
            $daily[$key]['tiempo_laborado'] = $row['tiempo_laborado'];
            $daily[$key]['tiempo_comida'] = $row['tiempo_comida'];
        }
    }

    return array_values($daily);
}

function insert_check(PDO $pdo, array $config, array $user, array $data, string $source): array
{
    require_role($user, ['staff']);

    $storeId = (int)($data['store_id'] ?? 0);
    $phase = normalize_phase((string)($data['phase'] ?? ''));
    if (is_verification_phase($phase) && (int)($user['requires_location_verification'] ?? 0) !== 1) {
        response_json(['error' => 'No tienes verificaciones de ubicacion asignadas'], 403);
    }
    $lat = (float)($data['latitude'] ?? 0);
    $lng = (float)($data['longitude'] ?? 0);
    $deviceId = (string)($data['device_id'] ?? '');

    if (!$storeId || !$lat || !$lng) {
        response_json(['error' => 'Tienda, latitud y longitud son obligatorias'], 400);
    }

    $store = get_store($pdo, $storeId);
    $storeTimezone = (string)($store['timezone'] ?? 'America/Mexico_City');
    $captured = (string)($data['captured_at_device'] ?? local_datetime(time(), $storeTimezone));
    $timestamp = parse_store_time($captured, $storeTimezone);
    $serverNow = time();
    $timeDriftSeconds = abs($serverNow - $timestamp);
    $futureDriftSeconds = $timestamp - $serverNow;
    $timezoneTimestamp = $source === 'offline_sync' ? $timestamp : $serverNow;
    $capturedAt = local_datetime($timestamp, $storeTimezone);
    $checkedAt = $source === 'offline_sync' ? $capturedAt : local_datetime($serverNow, $storeTimezone);
    $checkDate = substr($checkedAt, 0, 10);
    $timezoneOffsetMinutes = timezone_offset_minutes($timezoneTimestamp, $storeTimezone);
    $gpsAccuracy = isset($data['gps_accuracy_meters']) ? (float)$data['gps_accuracy_meters'] : null;
    $gpsIsMocked = !empty($data['gps_is_mocked']);
    try {
        validate_phase_sequence($pdo, (int)$user['id'], $phase, $checkDate, $storeId, $lat, $lng, $deviceId);
    } catch (RuntimeException $e) {
        if ($source === 'offline_sync') {
            throw $e;
        }
        response_json(['error' => $e->getMessage()], 409);
    }

    if ($gpsIsMocked) {
        $stmt = $pdo->prepare(
            "INSERT INTO check_attempts
             (user_id, store_id, phase, attempted_at, latitude, longitude, distance_meters, reason, device_id)
             VALUES (?, ?, ?, ?, ?, ?, NULL, 'device_time_suspicious', ?)"
        );
        $stmt->execute([(int)$user['id'], $storeId, $phase, $checkedAt, $lat, $lng, $deviceId]);
        return [
            'status' => 'rejected',
            'message' => 'Ubicacion simulada detectada. Desactiva aplicaciones de ubicacion falsa.'
        ];
    }

    if ($gpsAccuracy !== null && $gpsAccuracy > 75) {
        return [
            'status' => 'rejected',
            'message' => 'GPS con baja precision. Sal a un area abierta e intenta de nuevo.',
            'gps_accuracy_meters' => round($gpsAccuracy, 2)
        ];
    }

    $distance = haversine_meters($lat, $lng, (float)$store['latitude'], (float)$store['longitude']);
    $withinRange = $distance <= (float)$store['allowed_radius_meters'];

    if (!$withinRange) {
        $stmt = $pdo->prepare(
            "INSERT INTO check_attempts
             (user_id, store_id, phase, attempted_at, latitude, longitude, distance_meters, reason, device_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'out_of_range', ?)"
        );
        $stmt->execute([(int)$user['id'], $storeId, $phase, $checkedAt, $lat, $lng, $distance, $deviceId]);
        return [
            'status' => 'blocked_out_of_range',
            'distance_meters' => round($distance, 2),
            'allowed_radius_meters' => (int)$store['allowed_radius_meters'],
            'message' => 'Estas fuera del rango permitido'
        ];
    }

    $photoPath = save_photo($data['photo_base64'] ?? null, $config);
    if (!$photoPath) {
        response_json(['error' => 'La foto es obligatoria'], 400);
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO check_records
             (user_id, store_id, phase, check_date, checked_at, timezone_at_check, timezone_offset_minutes,
              captured_at_device, latitude, longitude,
              distance_meters, within_range, photo_path, device_id, source, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
        );
        $status = $source === 'offline_sync' ? 'synced' : 'valid';
        if ($futureDriftSeconds > 300 || ($source !== 'offline_sync' && $timeDriftSeconds > 900)) {
            $status = 'manual_review';
        }
        $stmt->execute([
            (int)$user['id'],
            $storeId,
            $phase,
            $checkDate,
            $checkedAt,
            $storeTimezone,
            $timezoneOffsetMinutes,
            $capturedAt,
            $lat,
            $lng,
            $distance,
            $photoPath,
            $deviceId,
            $source,
            $status
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && $source === 'offline_sync') {
            throw new RuntimeException('Ya existe una checada para esta fase del dia');
        }
        if ($e->getCode() === '23000') {
            response_json(['error' => 'Ya existe una checada para esta fase del dia'], 409);
        }
        throw $e;
    }

    return [
        'status' => $status,
        'check_id' => (int)$pdo->lastInsertId(),
        'distance_meters' => round($distance, 2),
        'message' => $status === 'manual_review'
            ? 'Checada registrada para revision por diferencia de hora.'
            : 'Checada registrada'
    ];
}

$pdo = db($config);
ensure_runtime_schema($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$path = route_path();

try {
    if ($method === 'GET' && $path === '/') {
        response_json(['ok' => true, 'service' => 'sealy-api']);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $data = body_json();
        $login = trim((string)($data['login'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $clientType = (string)($data['client_type'] ?? 'mobile');

        $stmt = $pdo->prepare(
            "SELECT * FROM users
             WHERE status = 'active'
             AND (email = ? OR phone = ? OR employee_number = ?)
             LIMIT 1"
        );
        $stmt->execute([$login, $login, $login]);
        $user = $stmt->fetch();

        if (!$user || !verify_user_password($password, $user['password_hash'])) {
            response_json(['error' => 'Usuario o contrasena incorrectos'], 401);
        }

        if ($clientType === 'mobile' && $user['role'] !== 'staff') {
            response_json(['error' => 'La app movil solo permite promotores'], 403);
        }
        if ($clientType === 'web' && !in_array($user['role'], ['admin', 'supervisor'], true)) {
            response_json(['error' => 'El panel web solo permite supervisores o administradores'], 403);
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + ((int)$config['token_ttl_days'] * 86400));
        $stmt = $pdo->prepare(
            "INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([(int)$user['id'], hash('sha256', $token), $expires]);

        response_json([
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ]);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        $user = auth_user($pdo);
        $hash = current_token_hash();
        if ($hash) {
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ? AND token_hash = ?");
            $stmt->execute([(int)$user['id'], $hash]);
        }
        response_json(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/me/change-password') {
        $user = auth_user($pdo);
        require_role($user, ['staff', 'admin', 'supervisor']);
        $data = body_json();
        $current = (string)($data['current_password'] ?? '');
        $new = (string)($data['new_password'] ?? '');
        if (!verify_user_password($current, $user['password_hash'])) {
            response_json(['error' => 'La contraseña actual no es correcta'], 400);
        }
        if (strlen($new) < 8) {
            response_json(['error' => 'La nueva contraseña debe tener al menos 8 caracteres'], 400);
        }
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
        response_json(['ok' => true, 'message' => 'Contraseña actualizada']);
    }

    if ($method === 'GET' && preg_match('#^/photos/(checks/[0-9]{8}/[a-f0-9]{24}\.jpg)$#', $path, $photoMatch)) {
        $user = auth_user($pdo);
        $photoPath = $photoMatch[1];
        $stmt = $pdo->prepare(
            "SELECT c.user_id, u.supervisor_id
             FROM check_records c
             JOIN users u ON u.id = c.user_id
             WHERE c.photo_path IN (?, ?)
             LIMIT 1"
        );
        $stmt->execute([$photoPath, 'uploads/' . $photoPath]);
        $record = $stmt->fetch();
        if (!$record) {
            response_json(['error' => 'Foto no encontrada'], 404);
        }
        $allowed = $user['role'] === 'admin'
            || $user['role'] === 'supervisor'
            || ($user['role'] === 'staff' && (int)$record['user_id'] === (int)$user['id']);
        if (!$allowed) {
            response_json(['error' => 'No tienes permiso para ver esta foto'], 403);
        }
        $file = photo_absolute_path($photoPath, $config);
        if (!$file || !is_file($file)) {
            response_json(['error' => 'Archivo no encontrado'], 404);
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=300');
        readfile($file);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/notice-images/(notices/[a-f0-9]{24}\.jpg)$#', $path, $noticeMatch)) {
        auth_user($pdo);
        $file = notice_absolute_path($noticeMatch[1], $config);
        if (!$file || !is_file($file)) {
            response_json(['error' => 'Imagen no encontrada'], 404);
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=300');
        readfile($file);
        exit;
    }

    if ($method === 'GET' && $path === '/me/mobile-bootstrap') {
        $user = auth_user($pdo);
        require_role($user, ['staff']);

        $stmt = $pdo->prepare(
            "SELECT s.id, s.chain, s.name, s.address, s.latitude, s.longitude, s.allowed_radius_meters, s.timezone
             FROM stores s
             WHERE s.status = 'active'
             ORDER BY s.chain ASC, s.name ASC"
        );
        $stmt->execute();
        $stores = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT id, store_id, phase, checked_at, distance_meters, status
             FROM check_records
             WHERE user_id = ? AND check_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
             ORDER BY checked_at ASC"
        );
        $stmt->execute([(int)$user['id']]);

        response_json([
            'server_time' => date('c'),
            'user' => [
                'id' => (int)$user['id'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ],
            'requires_location_verification' => (int)($user['requires_location_verification'] ?? 0) === 1,
            'active_stores' => $stores,
            'today_checks' => $stmt->fetchAll()
        ]);
    }

    if ($method === 'GET' && $path === '/me/notices') {
        $user = auth_user($pdo);
        require_role($user, ['staff']);
        $stmt = $pdo->query(
            "SELECT id, title, body, image_path, published_at
             FROM notices
             WHERE status = 'active'
             ORDER BY published_at DESC
             LIMIT 30"
        );
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $item['image_url'] = notice_image_url($item['image_path'] ?? null);
            unset($item['image_path']);
        }
        response_json(['items' => $items]);
    }

    if ($method === 'POST' && $path === '/checks') {
        $user = auth_user($pdo);
        $result = insert_check($pdo, $config, $user, body_json(), 'online');
        $status = $result['status'] === 'blocked_out_of_range' ? 422 : 200;
        response_json($result, $status);
    }

    if ($method === 'POST' && $path === '/checks/sync') {
        $user = auth_user($pdo);
        require_role($user, ['staff']);
        $data = body_json();
        $synced = [];
        $rejected = [];

        foreach (($data['items'] ?? []) as $item) {
            try {
                $item['device_id'] = $data['device_id'] ?? ($item['device_id'] ?? '');
                $result = insert_check($pdo, $config, $user, $item, 'offline_sync');
                if (($result['status'] ?? '') === 'synced') {
                    $synced[] = [
                        'local_id' => $item['local_id'] ?? null,
                        'server_id' => $result['check_id'],
                        'status' => 'synced'
                    ];
                } else {
                    $rejected[] = [
                        'local_id' => $item['local_id'] ?? null,
                        'status' => $result['status'] ?? 'rejected',
                        'message' => $result['message'] ?? 'Rechazada'
                    ];
                }
            } catch (Throwable $e) {
                $rejected[] = [
                    'local_id' => $item['local_id'] ?? null,
                    'status' => 'rejected',
                    'message' => $e->getMessage()
                ];
            }
        }

        response_json(['synced' => $synced, 'rejected' => $rejected]);
    }

    if ($method === 'GET' && $path === '/checks/my') {
        $user = auth_user($pdo);
        require_role($user, ['staff']);
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT c.*, s.name AS store_name, s.chain AS store_chain
             FROM check_records c
             JOIN stores s ON s.id = c.store_id
             WHERE c.user_id = ? AND c.check_date BETWEEN ? AND ?
             ORDER BY c.checked_at DESC"
        );
        $stmt->execute([(int)$user['id'], $start, $end]);
        $items = attach_work_times($stmt->fetchAll());
        foreach ($items as &$item) {
            $item['photo_url'] = photo_url($item['photo_path'] ?? null);
            unset($item['photo_path']);
        }
        response_json(['items' => $items]);
    }

    if ($method === 'GET' && $path === '/admin/dashboard') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);

        $date = $_GET['date'] ?? admin_today();
        $staffFilter = '';

        $stmt = $pdo->prepare("SELECT COUNT(*) total FROM users u WHERE u.role = 'staff' AND u.status = 'active' $staffFilter");
        $stmt->execute();
        $totalStaff = (int)$stmt->fetch()['total'];

        $stmt = $pdo->prepare("SELECT COUNT(*) total FROM users u WHERE u.role = 'supervisor' AND u.status = 'active'");
        $stmt->execute();
        $totalSupervisors = (int)$stmt->fetch()['total'];

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) inactive
             FROM stores"
        );
        $stmt->execute();
        $storeCounts = $stmt->fetch();
        $totalStores = (int)$storeCounts['total'];
        $activeStores = (int)$storeCounts['active'];
        $inactiveStores = (int)$storeCounts['inactive'];

        $params = [$date];
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN c.phase = 'ingreso' THEN c.user_id END) ingreso,
                COUNT(DISTINCT CASE WHEN c.phase = 'salida_comer' THEN c.user_id END) salida_comer,
                COUNT(DISTINCT CASE WHEN c.phase = 'entrada_comer' THEN c.user_id END) entrada_comer,
                COUNT(DISTINCT CASE WHEN c.phase = 'salida' THEN c.user_id END) completos,
                SUM(CASE WHEN c.status = 'manual_review' THEN 1 ELSE 0 END) manual_review,
                SUM(CASE WHEN c.source = 'offline_sync' THEN 1 ELSE 0 END) offline_sync
             FROM check_records c
             JOIN users u ON u.id = c.user_id
             WHERE c.check_date = ? $staffFilter"
        );
        $stmt->execute($params);
        $counts = $stmt->fetch();

        $storeParams = [$date];
        $stmt = $pdo->prepare(
            "SELECT s.chain, s.name, COUNT(*) checks_count, COUNT(DISTINCT c.user_id) staff_count
             FROM check_records c
             JOIN users u ON u.id = c.user_id
             JOIN stores s ON s.id = c.store_id
             WHERE c.check_date = ? $staffFilter
             GROUP BY s.id, s.chain, s.name
             ORDER BY checks_count DESC, s.name ASC
             LIMIT 25"
        );
        $stmt->execute($storeParams);
        $byStore = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(chain, ''), 'Sin cadena') chain,
                    COUNT(*) total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) active,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) inactive
             FROM stores
             GROUP BY COALESCE(NULLIF(chain, ''), 'Sin cadena')
             ORDER BY chain ASC"
        );
        $stmt->execute();
        $storesByChain = $stmt->fetchAll();

        $supervisorParams = [$date];
        $supervisorWhere = '';
        $stmt = $pdo->prepare(
            "SELECT COALESCE(sup.full_name, 'Sin supervisor') supervisor_name,
                    COUNT(DISTINCT u.id) staff_total,
                    COUNT(DISTINCT CASE WHEN c.phase = 'ingreso' THEN c.user_id END) with_ingreso,
                    COUNT(DISTINCT CASE WHEN c.phase = 'salida' THEN c.user_id END) completed
             FROM users u
             LEFT JOIN users sup ON sup.id = u.supervisor_id
             LEFT JOIN check_records c ON c.user_id = u.id AND c.check_date = ?
             WHERE u.role = 'staff' AND u.status = 'active' $supervisorWhere
             GROUP BY sup.id, sup.full_name
             ORDER BY supervisor_name ASC"
        );
        $stmt->execute($supervisorParams);
        $bySupervisor = $stmt->fetchAll();

        $attemptParams = [$date . ' 00:00:00', $date . ' 23:59:59'];
        $attemptJoin = '';
        $attemptFilter = '';
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) total
             FROM check_attempts a
             $attemptJoin
             WHERE a.attempted_at BETWEEN ? AND ? $attemptFilter"
        );
        $stmt->execute($attemptParams);
        $incidents = (int)$stmt->fetch()['total'];

        $timeParams = [$date];
        $stmt = $pdo->prepare(
            "SELECT c.user_id, c.check_date, c.phase, c.checked_at
             FROM check_records c
             JOIN users u ON u.id = c.user_id
             WHERE c.check_date = ? $staffFilter
             ORDER BY c.user_id, c.checked_at ASC"
        );
        $stmt->execute($timeParams);
        $timeRows = attach_work_times($stmt->fetchAll());
        $timeByUser = [];
        foreach ($timeRows as $row) {
            $key = $row['user_id'] . '|' . $row['check_date'];
            $timeByUser[$key] = [
                'worked' => $row['tiempo_laborado_minutos'],
                'meal' => $row['tiempo_comida_minutos'],
            ];
        }
        $workedTotal = 0;
        $mealTotal = 0;
        $workedCount = 0;
        $mealCount = 0;
        foreach ($timeByUser as $time) {
            if ($time['worked'] !== null) {
                $workedTotal += (int)$time['worked'];
                $workedCount++;
            }
            if ($time['meal'] !== null) {
                $mealTotal += (int)$time['meal'];
                $mealCount++;
            }
        }

        response_json([
            'date' => $date,
            'total_staff' => $totalStaff,
            'total_supervisors' => $totalSupervisors,
            'total_stores' => $totalStores,
            'active_stores' => $activeStores,
            'inactive_stores' => $inactiveStores,
            'with_ingreso' => (int)$counts['ingreso'],
            'completed' => (int)$counts['completos'],
            'pending_staff' => max(0, $totalStaff - (int)$counts['ingreso']),
            'incidents' => $incidents,
            'manual_review' => (int)$counts['manual_review'],
            'offline_synced' => (int)$counts['offline_sync'],
            'worked_total' => format_minutes($workedTotal),
            'meal_total' => format_minutes($mealTotal),
            'worked_average' => format_minutes($workedCount ? (int)round($workedTotal / $workedCount) : null),
            'meal_average' => format_minutes($mealCount ? (int)round($mealTotal / $mealCount) : null),
            'by_phase' => [
                'ingreso' => (int)$counts['ingreso'],
                'salida_comer' => (int)$counts['salida_comer'],
                'entrada_comer' => (int)$counts['entrada_comer'],
                'salida' => (int)$counts['completos'],
            ],
            'by_store' => $byStore,
            'stores_by_chain' => $storesByChain,
            'by_supervisor' => $bySupervisor
        ]);
    }

    if ($method === 'GET' && $path === '/admin/checks') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');
        $where = ["c.check_date BETWEEN ? AND ?"];
        $params = [$start, $end];

        if (isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' && $user['role'] === 'admin') {
            $where[] = "u.supervisor_id = ?";
            $params[] = (int)$_GET['supervisor_id'];
        }
        if (isset($_GET['staff_id']) && $_GET['staff_id'] !== '') {
            $where[] = "c.user_id = ?";
            $params[] = (int)$_GET['staff_id'];
        }
        if (isset($_GET['chain']) && trim((string)$_GET['chain']) !== '') {
            $where[] = "s.chain LIKE ?";
            $params[] = '%' . trim((string)$_GET['chain']) . '%';
        }
        if (isset($_GET['promoter']) && trim((string)$_GET['promoter']) !== '') {
            $term = '%' . trim((string)$_GET['promoter']) . '%';
            $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.employee_number LIKE ? OR u.rfc LIKE ?)";
            array_push($params, $term, $term, $term, $term);
        }
        if (isset($_GET['store_query']) && trim((string)$_GET['store_query']) !== '') {
            $term = '%' . trim((string)$_GET['store_query']) . '%';
            $where[] = "(s.name LIKE ? OR s.address LIKE ?)";
            array_push($params, $term, $term);
        }

        $stmt = $pdo->prepare(
            "SELECT c.id, c.user_id, c.store_id, c.check_date, c.phase, c.checked_at,
                    c.timezone_at_check, c.timezone_offset_minutes, c.captured_at_device, c.created_at,
                    c.latitude, c.longitude, c.distance_meters, c.photo_path, c.source, c.status,
                    u.full_name AS staff_name, u.rfc AS staff_rfc, sup.full_name AS supervisor_name,
                    s.name AS store_name, s.chain AS store_chain, s.timezone AS store_timezone
             FROM check_records c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN users sup ON sup.id = u.supervisor_id
             JOIN stores s ON s.id = c.store_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.checked_at DESC
             LIMIT 500"
        );
        $stmt->execute($params);
        $rows = array_map(function ($row) {
            $row['display_checked_at'] = display_check_datetime($row);
            return $row;
        }, $stmt->fetchAll());
        response_json(['items' => build_daily_check_rows($rows)]);
    }

    if ($method === 'GET' && $path === '/admin/stores') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $stmt = $pdo->query("SELECT * FROM stores ORDER BY chain ASC, name ASC");
        response_json(['items' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/admin/stores') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $data = body_json();
        $chain = require_text_field($data, 'chain', 'La cadena');
        $name = require_text_field($data, 'name', 'El nombre de la tienda');
        $address = require_text_field($data, 'address', 'La direccion');
        $lat = require_float_field($data, 'latitude', 'La latitud');
        $lng = require_float_field($data, 'longitude', 'La longitud');
        $radius = (int)($data['allowed_radius_meters'] ?? 0);
        if ($radius <= 0) {
            response_json(['error' => 'El radio debe ser mayor a cero'], 400);
        }
        $timezone = timezone_for_store($data['timezone'] ?? $data['zona_horaria'] ?? $data['horario'] ?? 'AUTO', $lat, $lng);
        $status = require_status($data['status'] ?? 'active');
        $stmt = $pdo->prepare(
            "INSERT INTO stores (chain, name, address, latitude, longitude, allowed_radius_meters, timezone, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $chain,
            $name,
            $address,
            $lat,
            $lng,
            $radius,
            $timezone,
            $status,
        ]);
        response_json(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($method === 'PATCH' && ($match = route_match('/admin/stores/{id}', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $id = (int)$match['id'];
        $data = body_json();
        $chain = require_text_field($data, 'chain', 'La cadena');
        $name = require_text_field($data, 'name', 'El nombre de la tienda');
        $address = require_text_field($data, 'address', 'La direccion');
        $lat = require_float_field($data, 'latitude', 'La latitud');
        $lng = require_float_field($data, 'longitude', 'La longitud');
        $radius = (int)($data['allowed_radius_meters'] ?? 0);
        if ($radius <= 0) {
            response_json(['error' => 'El radio debe ser mayor a cero'], 400);
        }
        $timezone = timezone_for_store($data['timezone'] ?? $data['zona_horaria'] ?? $data['horario'] ?? 'AUTO', $lat, $lng);
        $status = require_status($data['status'] ?? 'active');
        $stmt = $pdo->prepare(
            "UPDATE stores
             SET chain = ?, name = ?, address = ?, latitude = ?, longitude = ?, allowed_radius_meters = ?, timezone = ?, status = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $chain,
            $name,
            $address,
            $lat,
            $lng,
            $radius,
            $timezone,
            $status,
            $id,
        ]);
        response_json(['ok' => true]);
    }

    if ($method === 'DELETE' && ($match = route_match('/admin/stores/{id}', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $id = (int)$match['id'];
        $stmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM check_records WHERE store_id = ?) +
                (SELECT COUNT(*) FROM check_attempts WHERE store_id = ?) AS total"
        );
        $stmt->execute([$id, $id]);
        $linked = (int)($stmt->fetch()['total'] ?? 0);
        if ($linked > 0) {
            $stmt = $pdo->prepare("UPDATE stores SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            response_json(['ok' => true, 'deleted' => false, 'message' => 'La tienda tiene registros ligados y se desactivo para conservar el historial']);
        }
        $stmt = $pdo->prepare("DELETE FROM stores WHERE id = ?");
        $stmt->execute([$id]);
        response_json(['ok' => true, 'deleted' => true]);
    }

    if ($method === 'POST' && $path === '/admin/stores/import-csv') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $data = body_json();
        $csv = trim((string)($data['csv'] ?? ''));
        $fileBase64 = (string)($data['file_base64'] ?? '');
        if ($csv === '' && $fileBase64 === '') {
            response_json(['error' => 'Pega CSV o sube un archivo Excel .xlsx'], 400);
        }
        $rows = $fileBase64 !== '' ? parse_xlsx_rows($fileBase64) : parse_csv_rows($csv);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $stmtInsert = $pdo->prepare(
            "INSERT INTO stores (chain, name, address, latitude, longitude, allowed_radius_meters, timezone, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmtUpdate = $pdo->prepare(
            "UPDATE stores SET chain = ?, address = ?, latitude = ?, longitude = ?, allowed_radius_meters = ?, timezone = ?, status = 'active'
             WHERE name = ?"
        );
        $header = null;
        foreach ($rows as $i => $cols) {
            if (!array_filter($cols, fn($value) => trim((string)$value) !== '')) {
                continue;
            }
            if ($i === 0 && preg_match('/name|nombre|cadena/i', implode(',', $cols))) {
                $header = array_map(fn($value) => strtolower(str_replace([' ', '-'], '_', trim((string)$value))), $cols);
                continue;
            }
            if ($header) {
                $row = [];
                foreach ($header as $index => $key) {
                    $row[$key] = $cols[$index] ?? '';
                }
                $chain = trim((string)($row['cadena'] ?? $row['chain'] ?? ''));
                $name = trim((string)($row['nombre'] ?? $row['name'] ?? $row['tienda'] ?? ''));
                $address = trim((string)($row['direccion'] ?? $row['address'] ?? ''));
                $lat = (float)($row['latitud'] ?? $row['latitude'] ?? $row['lat'] ?? 0);
                $lng = (float)($row['longitud'] ?? $row['longitude'] ?? $row['lng'] ?? $row['lon'] ?? 0);
                $radius = (int)($row['radio'] ?? $row['radius'] ?? $row['allowed_radius_meters'] ?? 50);
                $timezoneInput = trim((string)($row['zona_horaria'] ?? $row['horario'] ?? $row['timezone'] ?? $row['zona'] ?? 'AUTO'));
            } elseif (count($cols) >= 6) {
                [$chain, $name, $address, $lat, $lng, $radius, $timezoneInput] = [
                    trim((string)$cols[0]),
                    trim((string)$cols[1]),
                    trim((string)$cols[2]),
                    (float)$cols[3],
                    (float)$cols[4],
                    (int)$cols[5],
                    trim((string)($cols[6] ?? 'AUTO')),
                ];
            } else {
                $chain = '';
                $name = trim((string)($cols[0] ?? ''));
                $address = trim((string)($cols[1] ?? ''));
                $lat = (float)($cols[2] ?? 0);
                $lng = (float)($cols[3] ?? 0);
                $radius = (int)($cols[4] ?? 50);
                $timezoneInput = trim((string)($cols[5] ?? 'AUTO'));
            }
            if ($chain === '' || $name === '' || $address === '' || !$lat || !$lng || $radius <= 0) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Linea ' . ($i + 1) . ': falta cadena, nombre, direccion, latitud, longitud o radio';
                }
                continue;
            }
            $timezone = $timezoneInput === '' || strtoupper($timezoneInput) === 'AUTO' || strtoupper($timezoneInput) === 'AUTOMATICO'
                ? timezone_for_store('AUTO', (float)$lat, (float)$lng)
                : resolve_timezone($timezoneInput);
            if (!$timezone) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Linea ' . ($i + 1) . ': zona horaria invalida';
                }
                continue;
            }
            $radius = $radius > 0 ? $radius : 50;
            $exists = $pdo->prepare("SELECT id FROM stores WHERE name = ? LIMIT 1");
            $exists->execute([$name]);
            if ($exists->fetch()) {
                $stmtUpdate->execute([$chain, $address, $lat, $lng, $radius, $timezone, $name]);
                $updated++;
            } else {
                $stmtInsert->execute([$chain, $name, $address, $lat, $lng, $radius, $timezone]);
                $created++;
            }
        }
        response_json(['ok' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);
    }

    if ($method === 'POST' && $path === '/admin/users/import-csv') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $data = body_json();
        $csv = trim((string)($data['csv'] ?? ''));
        $fileBase64 = (string)($data['file_base64'] ?? '');
        if ($csv === '' && $fileBase64 === '') {
            response_json(['error' => 'Pega CSV o sube un archivo Excel .xlsx'], 400);
        }
        $rows = $fileBase64 !== '' ? parse_xlsx_rows($fileBase64) : parse_csv_rows($csv);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $defaultPassword = (string)($data['default_password'] ?? 'Cambiar123!');
        $stmtInsert = $pdo->prepare(
            "INSERT INTO users (full_name, email, phone, employee_number, rfc, password_hash, role, supervisor_id, status)
             VALUES (?, ?, ?, ?, ?, ?, 'staff', ?, 'active')"
        );
        $stmtUpdate = $pdo->prepare(
            "UPDATE users SET full_name = ?, email = ?, phone = ?, rfc = ?, supervisor_id = ?, role = 'staff', status = 'active'
             WHERE employee_number = ?"
        );
        $findSupervisor = $pdo->prepare(
            "SELECT id FROM users
             WHERE role = 'supervisor' AND status = 'active'
             AND (employee_number = ? OR email = ? OR full_name = ?)
             LIMIT 1"
        );
        $header = null;
        foreach ($rows as $i => $cols) {
            if (!array_filter($cols, fn($value) => trim((string)$value) !== '')) {
                continue;
            }
            if ($i === 0 && preg_match('/nombre|promotor|empleado|email|supervisor/i', implode(',', $cols))) {
                $header = array_map(fn($value) => strtolower(str_replace([' ', '-'], '_', trim((string)$value))), $cols);
                continue;
            }
            $row = [];
            if ($header) {
                foreach ($header as $index => $key) {
                    $row[$key] = $cols[$index] ?? '';
                }
                $name = trim((string)($row['nombre'] ?? $row['promotor'] ?? $row['full_name'] ?? $row['name'] ?? ''));
                $email = trim((string)($row['email'] ?? $row['correo'] ?? ''));
                $phone = trim((string)($row['telefono'] ?? $row['phone'] ?? ''));
                $employee = trim((string)($row['numero_empleado'] ?? $row['empleado'] ?? $row['employee_number'] ?? ''));
                $rfc = normalize_rfc((string)($row['rfc'] ?? ''));
                $supervisorKey = trim((string)($row['supervisor'] ?? $row['supervisor_email'] ?? $row['supervisor_empleado'] ?? ''));
                $password = trim((string)($row['password'] ?? $row['contrasena'] ?? '')) ?: $defaultPassword;
            } else {
                $name = trim((string)($cols[0] ?? ''));
                $email = trim((string)($cols[1] ?? ''));
                $employee = trim((string)($cols[2] ?? ''));
                $rfc = normalize_rfc((string)($cols[3] ?? ''));
                $phone = trim((string)($cols[4] ?? ''));
                $supervisorKey = trim((string)($cols[5] ?? ''));
                $password = trim((string)($cols[6] ?? '')) ?: $defaultPassword;
            }
            if ($name === '' || $email === '' || $employee === '' || $rfc === '' || $phone === '' || $password === '') {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Linea ' . ($i + 1) . ': falta nombre, email, RFC, telefono, numero de empleado o contrasena';
                }
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Linea ' . ($i + 1) . ': email invalido';
                }
                continue;
            }
            $supervisorId = null;
            if ($supervisorKey !== '') {
                $findSupervisor->execute([$supervisorKey, $supervisorKey, $supervisorKey]);
                $found = $findSupervisor->fetch();
                $supervisorId = $found ? (int)$found['id'] : null;
                if (!$supervisorId) {
                    $skipped++;
                    if (count($errors) < 10) {
                        $errors[] = 'Linea ' . ($i + 1) . ': supervisor no encontrado';
                    }
                    continue;
                }
            }
            $exists = $pdo->prepare("SELECT id, role, supervisor_id FROM users WHERE employee_number = ? LIMIT 1");
            $exists->execute([$employee]);
            $existing = $exists->fetch();
            if ($existing) {
                if ($user['role'] === 'supervisor' && (string)$existing['role'] !== 'staff') {
                    $skipped++;
                    if (count($errors) < 10) {
                        $errors[] = 'Linea ' . ($i + 1) . ': solo se pueden actualizar promotores';
                    }
                    continue;
                }
                $stmtUpdate->execute([$name, $email ?: null, $phone ?: null, $rfc, $supervisorId, $employee]);
                $updated++;
            } else {
                $stmtInsert->execute([$name, $email ?: null, $phone ?: null, $employee, $rfc, password_hash($password, PASSWORD_DEFAULT), $supervisorId]);
                $created++;
            }
        }
        response_json(['ok' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);
    }

    if ($method === 'GET' && $path === '/admin/users') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $where = '';
        $params = [];
        if ($user['role'] === 'supervisor') {
            $where = "WHERE u.role = 'staff'";
        }
        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.email, u.phone, u.employee_number, u.rfc, u.role, u.supervisor_id,
                    u.requires_location_verification,
                    s.full_name AS supervisor_name, u.status
             FROM users u
             LEFT JOIN users s ON s.id = u.supervisor_id
             $where
             ORDER BY u.full_name ASC"
        );
        $stmt->execute($params);
        response_json(['items' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/admin/users') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $data = body_json();
        $fullName = require_text_field($data, 'full_name', 'El nombre');
        $email = require_email_field($data, 'email', 'El email');
        $phone = require_text_field($data, 'phone', 'El telefono');
        $employeeNumber = require_text_field($data, 'employee_number', 'El numero de empleado');
        $rfc = require_rfc_field($data);
        $password = require_text_field($data, 'password', 'La contrasena');
        if (strlen($password) < 8) {
            response_json(['error' => 'La contrasena debe tener al menos 8 caracteres'], 400);
        }
        $role = $user['role'] === 'supervisor' ? 'staff' : (string)($data['role'] ?? 'staff');
        if (!in_array($role, ['admin', 'supervisor', 'staff'], true)) {
            response_json(['error' => 'Rol invalido'], 400);
        }
        $supervisorId = null;
        if ($role === 'staff') {
            $supervisorId = nullable_int($data['supervisor_id'] ?? null);
        }
        $status = require_status($data['status'] ?? 'active');
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, phone, employee_number, rfc, password_hash, role, supervisor_id, requires_location_verification, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $fullName,
            $email,
            $phone,
            $employeeNumber,
            $rfc,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $supervisorId,
            !empty($data['requires_location_verification']) ? 1 : 0,
            $status,
        ]);
        response_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'temporary_password' => $password]);
    }

    if ($method === 'PATCH' && ($match = route_match('/admin/users/{id}', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        can_manage_staff_user($pdo, $user, (int)$match['id']);
        $data = body_json();
        $fullName = require_text_field($data, 'full_name', 'El nombre');
        $email = require_email_field($data, 'email', 'El email');
        $phone = require_text_field($data, 'phone', 'El telefono');
        $employeeNumber = require_text_field($data, 'employee_number', 'El numero de empleado');
        $rfc = require_rfc_field($data);
        $role = $user['role'] === 'supervisor' ? 'staff' : (string)($data['role'] ?? 'staff');
        if (!in_array($role, ['admin', 'supervisor', 'staff'], true)) {
            response_json(['error' => 'Rol invalido'], 400);
        }
        $supervisorId = null;
        if ($role === 'staff') {
            $supervisorId = nullable_int($data['supervisor_id'] ?? null);
        }
        $status = require_status($data['status'] ?? 'active');
        $stmt = $pdo->prepare(
            "UPDATE users
             SET full_name = ?, email = ?, phone = ?, employee_number = ?, rfc = ?, role = ?, supervisor_id = ?, requires_location_verification = ?, status = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $fullName,
            $email,
            $phone,
            $employeeNumber,
            $rfc,
            $role,
            $supervisorId,
            !empty($data['requires_location_verification']) ? 1 : 0,
            $status,
            (int)$match['id'],
        ]);
        response_json(['ok' => true]);
    }

    if ($method === 'POST' && ($match = route_match('/admin/users/{id}/location-verification', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $id = (int)$match['id'];
        $data = body_json();
        if ($user['role'] === 'supervisor') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'staff'");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                response_json(['error' => 'Los supervisores solo pueden modificar promotores'], 403);
            }
        }
        $stmt = $pdo->prepare("UPDATE users SET requires_location_verification = ? WHERE id = ? AND role = 'staff'");
        $stmt->execute([!empty($data['enabled']) ? 1 : 0, $id]);
        response_json(['ok' => true]);
    }

    if ($method === 'DELETE' && ($match = route_match('/admin/users/{id}', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $id = (int)$match['id'];
        if ($id === (int)$user['id']) {
            response_json(['error' => 'No puedes eliminar tu propio usuario'], 400);
        }
        can_manage_staff_user($pdo, $user, $id);
        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        response_json(['ok' => true]);
    }

    if ($method === 'POST' && ($match = route_match('/admin/users/{id}/reset-password', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        can_manage_staff_user($pdo, $user, (int)$match['id']);
        $data = body_json();
        $password = (string)($data['password'] ?? '');
        if ($password === '') {
            $password = bin2hex(random_bytes(4));
        } elseif (strlen($password) < 8) {
            response_json(['error' => 'La contrasena debe tener al menos 8 caracteres'], 400);
        }
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), (int)$match['id']]);
        response_json(['ok' => true, 'temporary_password' => $password]);
    }

    if ($method === 'POST' && ($match = route_match('/admin/supervisors/{id}/assign-promoters', $path))) {
        $user = auth_user($pdo);
        require_admin($user);
        $supervisorId = (int)$match['id'];
        $data = body_json();
        $staffIds = $data['staff_ids'] ?? [];
        if (!is_array($staffIds)) {
            response_json(['error' => 'Selecciona promotores validos'], 400);
        }
        $stmt = $pdo->prepare("UPDATE users SET supervisor_id = ? WHERE role = 'staff' AND id = ?");
        foreach ($staffIds as $staffId) {
            $stmt->execute([$supervisorId, (int)$staffId]);
        }
        response_json(['ok' => true, 'assigned' => count($staffIds)]);
    }

    if ($method === 'GET' && $path === '/admin/notices') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $stmt = $pdo->query(
            "SELECT n.id, n.title, n.body, n.image_path, n.status, n.published_at, u.full_name AS created_by_name
             FROM notices n
             LEFT JOIN users u ON u.id = n.created_by
             ORDER BY n.published_at DESC
             LIMIT 100"
        );
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $item['image_url'] = notice_image_url($item['image_path'] ?? null);
            unset($item['image_path']);
        }
        response_json(['items' => $items]);
    }

    if ($method === 'POST' && $path === '/admin/notices') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $data = body_json();
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            response_json(['error' => 'El titulo del aviso es obligatorio'], 400);
        }
        $status = require_status($data['status'] ?? 'active');
        $imagePath = save_notice_image($data['image_base64'] ?? null, $config);
        $stmt = $pdo->prepare(
            "INSERT INTO notices (title, body, image_path, status, created_by, published_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $title,
            $data['body'] ?? null,
            $imagePath,
            $status,
            (int)$user['id'],
        ]);
        response_json(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($method === 'PATCH' && ($match = route_match('/admin/notices/{id}', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $data = body_json();
        $stmt = $pdo->prepare("UPDATE notices SET status = ? WHERE id = ?");
        $stmt->execute([require_status($data['status'] ?? 'inactive'), (int)$match['id']]);
        response_json(['ok' => true]);
    }

    if ($method === 'DELETE' && ($match = route_match('/admin/notices/{id}', $path))) {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $id = (int)$match['id'];
        $stmt = $pdo->prepare("SELECT image_path FROM notices WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $notice = $stmt->fetch();
        if (!$notice) {
            response_json(['error' => 'Aviso no encontrado'], 404);
        }
        $imagePath = $notice['image_path'] ?? null;
        $stmt = $pdo->prepare("DELETE FROM notices WHERE id = ?");
        $stmt->execute([$id]);
        if ($imagePath) {
            $stmt = $pdo->prepare("SELECT COUNT(*) total FROM notices WHERE image_path = ?");
            $stmt->execute([$imagePath]);
            if ((int)($stmt->fetch()['total'] ?? 0) === 0) {
                $file = notice_absolute_path($imagePath, $config);
                if ($file && is_file($file)) {
                    @unlink($file);
                }
            }
        }
        response_json(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/admin/reports/checks.csv') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');
        $where = ["c.check_date BETWEEN ? AND ?"];
        $params = [$start, $end];
        foreach (['store_id' => 'c.store_id', 'staff_id' => 'c.user_id', 'status' => 'c.status'] as $key => $column) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $where[] = "$column = ?";
                $params[] = $_GET[$key];
            }
        }
        if (isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' && $user['role'] === 'admin') {
            $where[] = "u.supervisor_id = ?";
            $params[] = (int)$_GET['supervisor_id'];
        }
        if (isset($_GET['chain']) && trim((string)$_GET['chain']) !== '') {
            $where[] = "s.chain LIKE ?";
            $params[] = '%' . trim((string)$_GET['chain']) . '%';
        }
        if (isset($_GET['promoter']) && trim((string)$_GET['promoter']) !== '') {
            $term = '%' . trim((string)$_GET['promoter']) . '%';
            $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.employee_number LIKE ? OR u.rfc LIKE ?)";
            array_push($params, $term, $term, $term, $term);
        }
        if (isset($_GET['store_query']) && trim((string)$_GET['store_query']) !== '') {
            $term = '%' . trim((string)$_GET['store_query']) . '%';
            $where[] = "(s.name LIKE ? OR s.address LIKE ?)";
            array_push($params, $term, $term);
        }
        $stmt = $pdo->prepare(
            "SELECT c.user_id, c.check_date, u.full_name AS staff_name, u.rfc AS staff_rfc, sup.full_name AS supervisor_name,
                    s.name AS store_name, s.chain AS store_chain, s.timezone AS store_timezone,
                    c.phase, c.checked_at, c.timezone_at_check, c.timezone_offset_minutes,
                    c.captured_at_device, c.created_at, c.distance_meters, c.status, c.source, c.photo_path
             FROM check_records c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN users sup ON sup.id = u.supervisor_id
             JOIN stores s ON s.id = c.store_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.check_date DESC, u.full_name ASC, c.checked_at ASC"
        );
        $stmt->execute($params);
        $columns = [
            'fecha' => 'Fecha',
            'promotor' => 'Promotor',
            'rfc' => 'RFC',
            'supervisor' => 'Supervisor',
            'ingreso' => 'Ingreso',
            'ingreso_zona_horaria' => 'Zona horaria ingreso',
            'ingreso_cadena' => 'Cadena ingreso',
            'ingreso_tienda' => 'Tienda ingreso',
            'ingreso_distancia_m' => 'Distancia ingreso m',
            'ingreso_estado' => 'Estado ingreso',
            'ingreso_foto_url' => 'Foto ingreso',
            'salida_comer' => 'Salida a comer',
            'salida_comer_zona_horaria' => 'Zona horaria salida a comer',
            'salida_comer_cadena' => 'Cadena salida a comer',
            'salida_comer_tienda' => 'Tienda salida a comer',
            'salida_comer_distancia_m' => 'Distancia salida a comer m',
            'salida_comer_estado' => 'Estado salida a comer',
            'salida_comer_foto_url' => 'Foto salida a comer',
            'entrada_comer' => 'Entrada de comer',
            'entrada_comer_zona_horaria' => 'Zona horaria entrada de comer',
            'entrada_comer_cadena' => 'Cadena entrada de comer',
            'entrada_comer_tienda' => 'Tienda entrada de comer',
            'entrada_comer_distancia_m' => 'Distancia entrada de comer m',
            'entrada_comer_estado' => 'Estado entrada de comer',
            'entrada_comer_foto_url' => 'Foto entrada de comer',
            'salida' => 'Salida',
            'salida_zona_horaria' => 'Zona horaria salida',
            'salida_cadena' => 'Cadena salida',
            'salida_tienda' => 'Tienda salida',
            'salida_distancia_m' => 'Distancia salida m',
            'salida_estado' => 'Estado salida',
            'salida_foto_url' => 'Foto salida',
            'verificacion_ubicacion_1' => 'Verificación ubicación 1',
            'verificacion_ubicacion_1_zona_horaria' => 'Zona horaria verificación 1',
            'verificacion_ubicacion_1_cadena' => 'Cadena verificacion 1',
            'verificacion_ubicacion_1_tienda' => 'Tienda verificacion 1',
            'verificacion_ubicacion_1_distancia_m' => 'Distancia verificacion 1 m',
            'verificacion_ubicacion_1_estado' => 'Estado verificacion 1',
            'verificacion_ubicacion_1_foto_url' => 'Foto verificacion 1',
            'verificacion_ubicacion_2' => 'Verificación ubicación 2',
            'verificacion_ubicacion_2_zona_horaria' => 'Zona horaria verificación 2',
            'verificacion_ubicacion_2_cadena' => 'Cadena verificacion 2',
            'verificacion_ubicacion_2_tienda' => 'Tienda verificacion 2',
            'verificacion_ubicacion_2_distancia_m' => 'Distancia verificacion 2 m',
            'verificacion_ubicacion_2_estado' => 'Estado verificacion 2',
            'verificacion_ubicacion_2_foto_url' => 'Foto verificacion 2',
            'verificacion_ubicacion_3' => 'Verificación ubicación 3',
            'verificacion_ubicacion_3_zona_horaria' => 'Zona horaria verificación 3',
            'verificacion_ubicacion_3_cadena' => 'Cadena verificacion 3',
            'verificacion_ubicacion_3_tienda' => 'Tienda verificacion 3',
            'verificacion_ubicacion_3_distancia_m' => 'Distancia verificacion 3 m',
            'verificacion_ubicacion_3_estado' => 'Estado verificacion 3',
            'verificacion_ubicacion_3_foto_url' => 'Foto verificacion 3',
            'tiempo_laborado' => 'Tiempo laborado',
            'tiempo_comida' => 'Tiempo comida',
        ];
        $selected = array_keys($columns);
        if (isset($_GET['columns']) && $_GET['columns'] !== '') {
            $requested = array_filter(array_map('trim', explode(',', (string)$_GET['columns'])));
            $selected = array_values(array_intersect($requested, array_keys($columns)));
            if (!$selected) {
                $selected = array_keys($columns);
            }
        }
        $rows = array_map(function ($row) {
            $row['display_checked_at'] = display_check_datetime($row);
            return $row;
        }, $stmt->fetchAll());
        $dailyRows = build_daily_check_rows($rows);
        $out = begin_csv_download('reporte_checadas.csv');
        fputcsv($out, array_map(fn($key) => $columns[$key], $selected));
        foreach ($dailyRows as $row) {
            fputcsv($out, array_map(fn($key) => $row[$key] ?? '', $selected));
        }
        exit;
    }

    if ($method === 'GET' && $path === '/admin/reports/checked.csv') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $date = $_GET['date'] ?? admin_today();
        $where = ["c.check_date = ?", "c.phase = 'ingreso'"];
        $params = [$date];
        if (isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' && $user['role'] === 'admin') {
            $where[] = "u.supervisor_id = ?";
            $params[] = (int)$_GET['supervisor_id'];
        }
        if (isset($_GET['promoter']) && trim((string)$_GET['promoter']) !== '') {
            $term = '%' . trim((string)$_GET['promoter']) . '%';
            $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.employee_number LIKE ? OR u.rfc LIKE ?)";
            array_push($params, $term, $term, $term, $term);
        }
        if (isset($_GET['chain']) && trim((string)$_GET['chain']) !== '') {
            $where[] = "s.chain LIKE ?";
            $params[] = '%' . trim((string)$_GET['chain']) . '%';
        }
        if (isset($_GET['store_query']) && trim((string)$_GET['store_query']) !== '') {
            $term = '%' . trim((string)$_GET['store_query']) . '%';
            $where[] = "(s.name LIKE ? OR s.address LIKE ?)";
            array_push($params, $term, $term);
        }
        $stmt = $pdo->prepare(
            "SELECT u.full_name AS promotor, u.rfc, u.employee_number, u.email, sup.full_name AS supervisor,
                    s.chain AS cadena, s.name AS tienda, s.address AS direccion, s.timezone AS store_timezone,
                    c.checked_at AS ingreso, c.timezone_at_check, c.timezone_offset_minutes,
                    c.captured_at_device, c.created_at, c.source, c.distance_meters, c.status
             FROM check_records c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN users sup ON sup.id = u.supervisor_id
             JOIN stores s ON s.id = c.store_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY u.full_name ASC"
        );
        $stmt->execute($params);
        $out = begin_csv_download('promotores_que_si_checaron.csv');
        fputcsv($out, ['fecha', 'promotor', 'rfc', 'numero_empleado', 'email', 'supervisor', 'cadena', 'tienda', 'direccion', 'ingreso', 'zona_horaria', 'distancia_m', 'estado']);
        foreach ($stmt->fetchAll() as $row) {
            $row['checked_at'] = $row['ingreso'];
            $rowTimezone = $row['timezone_at_check'] ?? $row['store_timezone'] ?? null;
            fputcsv($out, [$date, $row['promotor'], $row['rfc'], $row['employee_number'], $row['email'], $row['supervisor'], $row['cadena'], $row['tienda'], $row['direccion'], check_datetime_label(display_check_datetime($row), $rowTimezone), timezone_label($rowTimezone), $row['distance_meters'], status_label((string)$row['status'])]);
        }
        exit;
    }

    if ($method === 'GET' && $path === '/admin/reports/not-checked.csv') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $date = $_GET['date'] ?? admin_today();
        $where = ["u.role = 'staff'", "u.status = 'active'", "c.id IS NULL"];
        $params = [$date];
        if (isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' && $user['role'] === 'admin') {
            $where[] = "u.supervisor_id = ?";
            $params[] = (int)$_GET['supervisor_id'];
        }
        if (isset($_GET['promoter']) && trim((string)$_GET['promoter']) !== '') {
            $term = '%' . trim((string)$_GET['promoter']) . '%';
            $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.employee_number LIKE ? OR u.rfc LIKE ?)";
            array_push($params, $term, $term, $term, $term);
        }
        $stmt = $pdo->prepare(
            "SELECT u.full_name AS promotor, u.rfc, u.employee_number, u.email, sup.full_name AS supervisor
             FROM users u
             LEFT JOIN users sup ON sup.id = u.supervisor_id
             LEFT JOIN check_records c ON c.user_id = u.id AND c.check_date = ? AND c.phase = 'ingreso'
             WHERE " . implode(' AND ', $where) . "
             ORDER BY sup.full_name, u.full_name"
        );
        $stmt->execute($params);
        $out = begin_csv_download('promotores_que_no_checaron.csv');
        fputcsv($out, ['fecha', 'promotor', 'rfc', 'numero_empleado', 'email', 'supervisor', 'estado']);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [$date, $row['promotor'], $row['rfc'], $row['employee_number'], $row['email'], $row['supervisor'], 'Sin ingreso']);
        }
        exit;
    }

    if ($method === 'GET' && $path === '/admin/reports/assignments.csv') {
        $user = auth_user($pdo);
        require_role($user, ['admin', 'supervisor']);
        $where = ["u.role = 'staff'"];
        $params = [];
        if (isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' && $user['role'] === 'admin') {
            $where[] = "u.supervisor_id = ?";
            $params[] = (int)$_GET['supervisor_id'];
        }
        $stmt = $pdo->prepare(
            "SELECT u.full_name AS promotor, u.rfc, u.email, u.employee_number, sup.full_name AS supervisor, u.status
             FROM users u
             LEFT JOIN users sup ON sup.id = u.supervisor_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY sup.full_name, u.full_name"
        );
        $stmt->execute($params);
        $out = begin_csv_download('promotores_por_supervisor.csv');
        fputcsv($out, ['promotor', 'rfc', 'email', 'numero_empleado', 'supervisor', 'estado']);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, $row);
        }
        exit;
    }

    response_json(['error' => 'Ruta no encontrada', 'path' => $path], 404);
} catch (Throwable $e) {
    response_json(['error' => 'Error del servidor', 'message' => $e->getMessage()], 500);
}
