<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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
    echo json_encode(['error' => 'Falta configuracion privada']);
    exit;
}

$config = require $configPath;

if (empty($config['allow_web_migrations'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Migraciones web deshabilitadas en produccion']);
    exit;
}

if (($_GET['key'] ?? '') !== ($config['setup_key'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid setup key']);
    exit;
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$allowDestructive = !empty($config['allow_destructive_migrations']);

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '-06:00'");

function migration_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_migration_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          migration VARCHAR(190) NOT NULL UNIQUE,
          checksum CHAR(64) NOT NULL,
          applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function strip_sql_comments(string $sql): string
{
    $lines = preg_split('/\r\n|\r|\n/', $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $clean[] = $line;
    }
    return trim(implode("\n", $clean));
}

function split_sql_statements(string $sql): array
{
    $sql = strip_sql_comments($sql);
    if ($sql === '') {
        return [];
    }

    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function assert_safe_migration(string $sql, bool $allowDestructive): void
{
    if ($allowDestructive) {
        return;
    }

    $patterns = [
        '/\bDROP\s+TABLE\b/i',
        '/\bTRUNCATE\b/i',
        '/\bDROP\s+COLUMN\b/i',
        '/\bALTER\s+TABLE\b.*\bDROP\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $sql) === 1) {
            migration_response([
                'error' => 'Migracion bloqueada por seguridad',
                'detail' => 'Operacion destructiva detectada. Haz respaldo y habilita allow_destructive_migrations si realmente es necesario.'
            ], 400);
        }
    }
}

function is_ignorable_additive_duplicate(Throwable $e, string $statement): bool
{
    $message = $e->getMessage();
    $isAdditive = preg_match('/\bADD\s+COLUMN\b|\bCREATE\s+INDEX\b/i', $statement) === 1;
    if (!$isAdditive) {
        return false;
    }
    return stripos($message, 'Duplicate column') !== false
        || stripos($message, 'Duplicate key name') !== false
        || stripos($message, 'already exists') !== false;
}

ensure_migration_table($pdo);

$migrationsDir = dirname(__DIR__) . '/database/migrations';
if (!is_dir($migrationsDir)) {
    migration_response(['error' => 'No existe database/migrations'], 500);
}

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$stmt = $pdo->query("SELECT migration, checksum FROM schema_migrations");
$applied = [];
foreach ($stmt->fetchAll() as $row) {
    $applied[(string)$row['migration']] = (string)$row['checksum'];
}

$pending = [];
$alreadyApplied = [];
foreach ($files as $file) {
    $name = basename($file);
    $checksum = hash_file('sha256', $file);
    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            migration_response([
                'error' => 'Checksum de migracion no coincide',
                'migration' => $name,
                'detail' => 'No modifiques migraciones ya aplicadas. Crea una nueva migracion.'
            ], 409);
        }
        $alreadyApplied[] = $name;
        continue;
    }
    $pending[] = [
        'name' => $name,
        'path' => $file,
        'checksum' => $checksum,
    ];
}

if ($dryRun) {
    migration_response([
        'ok' => true,
        'dry_run' => true,
        'applied' => $alreadyApplied,
        'pending' => array_map(fn($item) => $item['name'], $pending),
    ]);
}

$executed = [];
foreach ($pending as $migration) {
    $sql = file_get_contents($migration['path']);
    if ($sql === false) {
        migration_response(['error' => 'No se pudo leer migracion', 'migration' => $migration['name']], 500);
    }

    assert_safe_migration($sql, $allowDestructive);
    $statements = split_sql_statements($sql);

    try {
        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
            } catch (Throwable $e) {
                if (!is_ignorable_additive_duplicate($e, $statement)) {
                    throw $e;
                }
            }
        }
        $insert = $pdo->prepare("INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)");
        $insert->execute([$migration['name'], $migration['checksum']]);
        $executed[] = [
            'migration' => $migration['name'],
            'statements' => count($statements),
        ];
    } catch (Throwable $e) {
        migration_response([
            'error' => 'No se pudo aplicar migracion',
            'migration' => $migration['name'],
            'message' => $e->getMessage(),
            'executed_before_error' => $executed,
        ], 500);
    }
}

migration_response([
    'ok' => true,
    'executed' => $executed,
    'already_applied' => $alreadyApplied,
    'pending_after_run' => 0,
    'message' => 'Migraciones aplicadas sin borrar datos'
]);
