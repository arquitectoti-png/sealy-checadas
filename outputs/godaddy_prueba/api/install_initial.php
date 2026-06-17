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

if (($_GET['key'] ?? '') !== ($config['setup_key'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid setup key']);
    exit;
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$schema = [
    "CREATE TABLE IF NOT EXISTS users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      full_name VARCHAR(160) NOT NULL,
      email VARCHAR(190) NULL UNIQUE,
      phone VARCHAR(40) NULL,
      employee_number VARCHAR(60) NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      role ENUM('admin', 'supervisor', 'staff') NOT NULL DEFAULT 'staff',
      supervisor_id BIGINT UNSIGNED NULL,
      requires_location_verification TINYINT(1) NOT NULL DEFAULT 0,
      status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_users_role (role),
      INDEX idx_users_supervisor (supervisor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS stores (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      chain VARCHAR(100) NULL,
      name VARCHAR(160) NOT NULL,
      address VARCHAR(255) NULL,
      latitude DECIMAL(10, 7) NOT NULL,
      longitude DECIMAL(10, 7) NOT NULL,
      allowed_radius_meters INT UNSIGNED NOT NULL DEFAULT 50,
      timezone VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City',
      status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_store_name (name),
      INDEX idx_stores_status (status),
      INDEX idx_stores_chain_status (chain, status),
      INDEX idx_stores_timezone (timezone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS auth_tokens (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      token_hash CHAR(64) NOT NULL UNIQUE,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_auth_user (user_id),
      INDEX idx_auth_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS check_records (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      store_id BIGINT UNSIGNED NOT NULL,
      phase ENUM('ingreso', 'salida_comer', 'entrada_comer', 'salida',
                 'verificacion_ubicacion_1', 'verificacion_ubicacion_2', 'verificacion_ubicacion_3') NOT NULL,
      check_date DATE NOT NULL,
      checked_at DATETIME NOT NULL,
      captured_at_device DATETIME NULL,
      latitude DECIMAL(10, 7) NOT NULL,
      longitude DECIMAL(10, 7) NOT NULL,
      distance_meters DECIMAL(8, 2) NOT NULL,
      within_range TINYINT(1) NOT NULL DEFAULT 0,
      photo_path VARCHAR(255) NULL,
      device_id VARCHAR(120) NULL,
      source ENUM('online', 'offline_sync', 'manual') NOT NULL DEFAULT 'online',
      status ENUM('valid', 'synced', 'blocked_out_of_range', 'manual_review', 'rejected') NOT NULL DEFAULT 'valid',
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_check_user_date_phase (user_id, check_date, phase),
      INDEX idx_check_date (check_date),
      INDEX idx_check_store_date (store_id, check_date),
      INDEX idx_check_status (status),
      INDEX idx_check_user_date (user_id, check_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS check_attempts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      store_id BIGINT UNSIGNED NULL,
      phase ENUM('ingreso', 'salida_comer', 'entrada_comer', 'salida',
                 'verificacion_ubicacion_1', 'verificacion_ubicacion_2', 'verificacion_ubicacion_3') NOT NULL,
      attempted_at DATETIME NOT NULL,
      latitude DECIMAL(10, 7) NULL,
      longitude DECIMAL(10, 7) NULL,
      distance_meters DECIMAL(8, 2) NULL,
      reason ENUM('out_of_range', 'gps_unavailable', 'duplicate_phase', 'invalid_order', 'device_time_suspicious') NOT NULL,
      device_id VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_attempt_user_date (user_id, attempted_at),
      INDEX idx_attempt_reason (reason)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS schema_migrations (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      migration VARCHAR(190) NOT NULL UNIQUE,
      checksum CHAR(64) NOT NULL,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($schema as $statement) {
    $pdo->exec($statement);
}

if (!$pdo->query("SHOW COLUMNS FROM stores LIKE 'chain'")->fetch()) {
    $pdo->exec("ALTER TABLE stores ADD COLUMN chain VARCHAR(100) NULL AFTER id");
    $pdo->exec("CREATE INDEX idx_stores_chain_status ON stores (chain, status)");
}
if (!$pdo->query("SHOW COLUMNS FROM stores LIKE 'timezone'")->fetch()) {
    $pdo->exec("ALTER TABLE stores ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City' AFTER allowed_radius_meters");
    $pdo->exec("CREATE INDEX idx_stores_timezone ON stores (timezone)");
}
if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'requires_location_verification'")->fetch()) {
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

$baseline = dirname(__DIR__) . '/database/migrations/202606130001_baseline_current_schema.sql';
if (is_file($baseline)) {
    $stmt = $pdo->prepare(
        "INSERT INTO schema_migrations (migration, checksum)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)"
    );
    $stmt->execute([basename($baseline), hash_file('sha256', $baseline)]);
}

$pdo->exec("DROP TABLE IF EXISTS user_store_assignments");
$pdo->exec("DELETE FROM auth_tokens");

$initialPassword = 'Cambiar123!';
$hash = password_hash($initialPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "INSERT INTO users (full_name, email, employee_number, password_hash, role, supervisor_id, status)
     VALUES (?, ?, ?, ?, ?, ?, 'active')
     ON DUPLICATE KEY UPDATE
       full_name = VALUES(full_name),
       employee_number = VALUES(employee_number),
       password_hash = VALUES(password_hash),
       role = VALUES(role),
       supervisor_id = VALUES(supervisor_id),
       status = 'active'"
);

$stmt->execute(['Administrador General', 'admin@staraz.site', 'ADM001', $hash, 'admin', null]);

for ($i = 1; $i <= 3; $i++) {
    $stmt->execute([
        'Supervisor ' . $i,
        'supervisor' . $i . '@staraz.site',
        'SUP' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
        $hash,
        'supervisor',
        null
    ]);
}

$supervisorIds = [];
for ($i = 1; $i <= 3; $i++) {
    $select = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $select->execute(['supervisor' . $i . '@staraz.site']);
    $supervisorIds[$i] = (int)$select->fetch()['id'];
}

for ($i = 1; $i <= 30; $i++) {
    $supervisorIndex = (int)ceil($i / 10);
    $stmt->execute([
        'Promotor ' . $i,
        'promotor' . $i . '@staraz.site',
        'PRO' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
        $hash,
        'staff',
        $supervisorIds[$supervisorIndex]
    ]);
}

if (!is_dir($config['upload_dir'])) {
    mkdir($config['upload_dir'], 0755, true);
}

echo json_encode([
    'ok' => true,
    'message' => 'Initial production database installed',
    'rule' => 'Stores are global active locations selected by nearest GPS within 50m. Supervisors can see all promoters.',
    'admin' => 'admin@staraz.site',
    'supervisors' => ['supervisor1@staraz.site', 'supervisor2@staraz.site', 'supervisor3@staraz.site'],
    'promoters' => 'promotor1@staraz.site ... promotor30@staraz.site',
    'initial_password' => $initialPassword,
    'next_step' => 'Delete or rename api/install_initial.php after setup. Future DB changes should use api/migrate.php.'
], JSON_UNESCAPED_SLASHES);
