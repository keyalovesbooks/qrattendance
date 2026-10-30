-- ============================================================================
-- QRAttendance – teacher level (primary / secondary / tertiary)
-- No stored procedures are used: InfinityFree (MariaDB) denies ALTER ROUTINE.
-- MariaDB 10.x supports ADD COLUMN IF NOT EXISTS, so this file is safe to
-- re-run. On plain MySQL (5.7/8.0) use migrate.php instead.
-- Fresh installs already get this from schema.sql.
-- ============================================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS teacher_level VARCHAR(20) NULL AFTER student_number;
