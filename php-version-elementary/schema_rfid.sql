-- ============================================================================
-- QRAttendance – RFID card check-in migration (schema_rfid.sql)
-- No stored procedures: InfinityFree (MariaDB) denies ALTER ROUTINE (#1370).
-- Everything here is idempotent on MariaDB 10.x, so re-running is harmless.
-- On plain MySQL 5.7/8.0 use migrate.php instead (it applies these via PHP).
-- ============================================================================

-- ---- readers + pairing tokens -------------------------------------------
CREATE TABLE IF NOT EXISTS pairing_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('enroll_card','register_reader') NOT NULL DEFAULT 'enroll_card',
  section_id INT UNSIGNED NULL,
  reader_name VARCHAR(60) NULL,
  pairing_code CHAR(6) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_pairing_hash (token_hash),
  KEY idx_pairing_code (pairing_code),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rfid_readers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  section_id INT UNSIGNED NULL,
  device_uid VARCHAR(64) NULL,
  pairing_code CHAR(6) NULL,
  token_hash CHAR(64) NOT NULL,
  revoked_at DATETIME NULL,
  last_seen DATETIME NULL,
  last_tap_student VARCHAR(120) NULL,
  last_tap_at DATETIME NULL,
  rate_count INT UNSIGNED NOT NULL DEFAULT 0,
  rate_window_start DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reader_token (token_hash),
  KEY idx_reader_section (section_id),
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- hashed card UID on the roster row (raw UID is never stored) ---------
ALTER TABLE students ADD COLUMN IF NOT EXISTS uid_hash CHAR(64) NULL;
ALTER TABLE students ADD COLUMN IF NOT EXISTS card_linked_at DATETIME NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_students_uid_hash ON students (uid_hash);

-- ---- how the student was recorded ---------------------------------------
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS method ENUM('qr','rfid') DEFAULT 'qr';

-- ---- pairing code kept on the reader ------------------------------------
ALTER TABLE rfid_readers ADD COLUMN IF NOT EXISTS pairing_code CHAR(6) NULL;
