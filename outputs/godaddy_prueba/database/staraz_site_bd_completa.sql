-- Sealy - esquema inicial final.
-- No existe relacion promotor-sucursal. Las tiendas activas son globales.
-- La app selecciona por GPS la tienda activa mas cercana dentro de 50 metros.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS user_store_assignments;

CREATE TABLE IF NOT EXISTS users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stores (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_user (user_id),
  INDEX idx_auth_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS check_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  phase ENUM('ingreso', 'salida_comer', 'entrada_comer', 'salida', 'verificacion_ubicacion_1', 'verificacion_ubicacion_2', 'verificacion_ubicacion_3') NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS check_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NULL,
  phase ENUM('ingreso', 'salida_comer', 'entrada_comer', 'salida', 'verificacion_ubicacion_1', 'verificacion_ubicacion_2', 'verificacion_ubicacion_3') NOT NULL,
  attempted_at DATETIME NOT NULL,
  latitude DECIMAL(10, 7) NULL,
  longitude DECIMAL(10, 7) NULL,
  distance_meters DECIMAL(8, 2) NULL,
  reason ENUM('out_of_range', 'gps_unavailable', 'duplicate_phase', 'invalid_order', 'device_time_suspicious') NOT NULL,
  device_id VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt_user_date (user_id, attempted_at),
  INDEX idx_attempt_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notices (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- La contrasena inicial de todos es: Cambiar123!
-- Se guarda con sha256$ porque la API valida este formato y permite cambiarla despues desde el panel.
SET @initial_password_hash = CONCAT('sha256$', SHA2('Cambiar123!', 256));

INSERT INTO users (full_name, email, employee_number, password_hash, role, supervisor_id, status)
VALUES ('Administrador General', 'admin@staraz.site', 'ADM001', @initial_password_hash, 'admin', NULL, 'active')
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  employee_number = VALUES(employee_number),
  password_hash = VALUES(password_hash),
  role = VALUES(role),
  supervisor_id = VALUES(supervisor_id),
  status = 'active';

INSERT INTO users (full_name, email, employee_number, password_hash, role, supervisor_id, status)
VALUES
  ('Supervisor 1', 'supervisor1@staraz.site', 'SUP001', @initial_password_hash, 'supervisor', NULL, 'active'),
  ('Supervisor 2', 'supervisor2@staraz.site', 'SUP002', @initial_password_hash, 'supervisor', NULL, 'active'),
  ('Supervisor 3', 'supervisor3@staraz.site', 'SUP003', @initial_password_hash, 'supervisor', NULL, 'active')
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  employee_number = VALUES(employee_number),
  password_hash = VALUES(password_hash),
  role = VALUES(role),
  supervisor_id = VALUES(supervisor_id),
  status = 'active';

INSERT INTO users (full_name, email, employee_number, password_hash, role, supervisor_id, status)
SELECT CONCAT('Promotor ', n), CONCAT('promotor', n, '@staraz.site'), CONCAT('PRO', LPAD(n, 3, '0')), @initial_password_hash, 'staff',
       CASE
         WHEN n <= 10 THEN (SELECT id FROM users WHERE email = 'supervisor1@staraz.site')
         WHEN n <= 20 THEN (SELECT id FROM users WHERE email = 'supervisor2@staraz.site')
         ELSE (SELECT id FROM users WHERE email = 'supervisor3@staraz.site')
       END,
       'active'
FROM (
  SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
  UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
  UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
  UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20
  UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25
  UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29 UNION ALL SELECT 30
) seed
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  employee_number = VALUES(employee_number),
  password_hash = VALUES(password_hash),
  role = VALUES(role),
  supervisor_id = VALUES(supervisor_id),
  status = 'active';

-- Las tiendas reales se cargan desde el panel con CSV:
-- cadena,nombre,direccion,latitud,longitud,radio

-- Los avisos para promotores se crean desde el panel web.
