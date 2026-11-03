-- ============================================================================
-- QRAttendance – per-professor storage isolation
--
-- Ownership root: terms.professor_id. Everything else inherits it through
-- subjects(term_id) → sections(subject_id) → students/sessions → attendance.
--
-- No stored procedures: InfinityFree (MariaDB) denies ALTER ROUTINE (#1370).
-- Idempotent except for the last statement, which can only be run once.
-- On plain MySQL 5.7/8.0 use migrate.php instead.
-- ============================================================================

-- 1) owner column
ALTER TABLE terms ADD COLUMN IF NOT EXISTS professor_id INT UNSIGNED NULL AFTER is_current;

-- 2) give every pre-existing term to the oldest professor account.
--    To hand a term to somebody else: UPDATE terms SET professor_id = <user id> WHERE id = <term id>;
UPDATE terms t
   SET t.professor_id = (
        SELECT u.id FROM users u WHERE u.role = 'professor' ORDER BY u.id ASC LIMIT 1
   )
 WHERE t.professor_id IS NULL;

-- 3) lookup index
CREATE INDEX IF NOT EXISTS idx_terms_professor ON terms (professor_id);

-- 4) per-professor uniqueness, so two professors can both have
--    "2026-2027 · 1st Semester"
CREATE UNIQUE INDEX IF NOT EXISTS uq_term_owner ON terms (professor_id, school_year, semester);

-- 5) LAST STATEMENT – the old global unique key. Run it alone if you like:
--    if you get error 1091 "Unknown key 'uq_term'" everything is already applied.
ALTER TABLE terms DROP INDEX uq_term;
