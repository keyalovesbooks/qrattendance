-- ============================================================================
-- Primary-teacher section modes + saved workflow preference + selectable
-- time-in / time-out recording + 30-minute attendance windows.
-- MariaDB 10.x / InfinityFree; no stored procedures. For plain MySQL 5.7/8.0
-- use the professor-only migrate.php page instead.
-- ============================================================================

ALTER TABLE sections MODIFY subject_id INT UNSIGNED NULL;
ALTER TABLE sections ADD COLUMN IF NOT EXISTS term_id INT UNSIGNED NULL;
ALTER TABLE sections ADD COLUMN IF NOT EXISTS professor_id INT UNSIGNED NULL;
ALTER TABLE sections ADD COLUMN IF NOT EXISTS section_mode ENUM('subject','direct') NOT NULL DEFAULT 'subject';

UPDATE sections s
JOIN subjects sub ON sub.id = s.subject_id
JOIN terms t ON t.id = sub.term_id
SET s.term_id = t.id, s.professor_id = t.professor_id
WHERE s.term_id IS NULL OR s.professor_id IS NULL;

ALTER TABLE sections MODIFY term_id INT UNSIGNED NOT NULL;
ALTER TABLE sections MODIFY professor_id INT UNSIGNED NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sections_term ON sections (term_id);
CREATE INDEX IF NOT EXISTS idx_sections_professor ON sections (professor_id);

ALTER TABLE class_sessions ADD COLUMN IF NOT EXISTS session_date DATE NULL;
ALTER TABLE class_sessions ADD COLUMN IF NOT EXISTS attendance_slot ENUM('am_in','am_out','pm_in','pm_out') NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_section_attendance_slot ON class_sessions (section_id, session_date, attendance_slot);

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
ALTER TABLE section_attendance_times ADD COLUMN IF NOT EXISTS attendance_mode ENUM('time_in','time_out','both') NOT NULL DEFAULT 'both' AFTER section_id;
