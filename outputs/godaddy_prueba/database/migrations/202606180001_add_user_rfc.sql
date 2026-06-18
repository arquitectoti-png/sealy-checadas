ALTER TABLE users ADD COLUMN rfc VARCHAR(13) NULL AFTER employee_number;

UPDATE users
SET rfc = CONCAT('RFC', LPAD(id, 10, '0'))
WHERE rfc IS NULL OR rfc = '';

ALTER TABLE users MODIFY rfc VARCHAR(13) NOT NULL;

CREATE INDEX idx_users_rfc ON users (rfc);
