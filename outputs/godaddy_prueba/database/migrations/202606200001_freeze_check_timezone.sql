ALTER TABLE check_records ADD COLUMN timezone_at_check VARCHAR(64) NULL AFTER checked_at;
ALTER TABLE check_records ADD COLUMN timezone_offset_minutes SMALLINT NULL AFTER timezone_at_check;
CREATE INDEX idx_check_timezone_at_check ON check_records (timezone_at_check);
