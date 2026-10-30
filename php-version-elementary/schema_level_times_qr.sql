-- ============================================================================
-- QRAttendance – student level · section time in/out · permanent student QR
--
-- Adds three things to an existing database (all idempotent on MariaDB 10.x):
--   1. users.student_level      – elementary | highschool | college
--   2. section_attendance_times – per-section AM/PM time in and time out
--   3. students.qr_code         – permanent semester-long student QR
--
-- No stored procedures: InfinityFree (MariaDB) denies ALTER ROUTINE (#1370).
-- On plain MySQL 5.7/8.0 use migrate.php instead.
-- ============================================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS student_level VARCHAR(20) NULL AFTER teacher_level;
ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(60) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS middle_name VARCHAR(60) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(60) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS primary_attendance_mode ENUM('subject','direct') NULL AFTER teacher_level;
CREATE TABLE IF NOT EXISTS section_attendance_times (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  attendance_mode ENUM('time_in','time_out','both') NOT NULL DEFAULT 'both',
  am_in TIME NULL,
  am_out TIME NULL,
  pm_in TIME NULL,
  pm_out TIME NULL,
  UNIQUE KEY uq_section_attendance_times (section_id),
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE section_attendance_times ADD COLUMN IF NOT EXISTS attendance_mode ENUM('time_in','time_out','both') NOT NULL DEFAULT 'both' AFTER section_id;
ALTER TABLE students ADD COLUMN IF NOT EXISTS qr_code CHAR(24) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_students_qr_code ON students (qr_code);
