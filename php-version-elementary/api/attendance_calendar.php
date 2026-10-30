<?php
/**
 * api/attendance_calendar.php – JSON feed for the student attendance calendar.
 *
 * Returns, for one student in one section:
 *   present[]  – dates the student checked in (green)
 *   sessions[] – dates the section held a class (used to derive absences → red)
 *   holidays[] – school holidays (yellow)
 *
 * SECURITY: professor-only; the section must belong to the signed-in professor
 * and the student must be on that section's roster (prevents IDOR).
 */
require dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u || $u['role'] !== 'professor') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Professor sign-in required.']);
    exit;
}

$sectionId = (int) ($_GET['section'] ?? 0);
$studentId = (int) ($_GET['student'] ?? 0);
if ($sectionId <= 0 || $studentId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing section or student.']);
    exit;
}

// The section must be owned by this professor.
$section = q('SELECT s.id, s.name FROM sections s JOIN terms t ON t.id = s.term_id
    WHERE s.id = ? AND COALESCE(s.professor_id, t.professor_id) = ?', [$sectionId, professor_id()])->fetch();
if (!$section) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Section not found in your workspace.']);
    exit;
}

// The student must be on THIS section's roster (not merely somewhere in the DB).
$student = q('SELECT id, name, student_number FROM students WHERE id = ? AND section_id = ?', [$studentId, $sectionId])->fetch();
if (!$student) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Student is not on this section roster.']);
    exit;
}

$presentRows = q('SELECT DISTINCT DATE(a.scanned_at) AS d FROM attendance a
    JOIN class_sessions cs ON cs.id = a.session_id
    WHERE a.student_id = ? AND cs.section_id = ?', [$studentId, $sectionId])->fetchAll();
$sessionRows = q('SELECT DISTINCT DATE(started_at) AS d FROM class_sessions WHERE section_id = ?', [$sectionId])->fetchAll();
$holidayRows = q('SELECT holiday_date, name FROM holidays WHERE professor_id = ? ORDER BY holiday_date', [professor_id()])->fetchAll();
require dirname(__DIR__) . '/ph_holidays.php';
$holidayMap = philippine_national_holidays();
foreach ($holidayRows as $holiday) {
    $holidayMap[(string) $holiday['holiday_date']] = (string) ($holiday['name'] ?: 'Holiday');
}
ksort($holidayMap);
$calendarHolidays = array_map(static fn(string $date, string $name): array => ['date' => $date, 'name' => $name], array_keys($holidayMap), array_values($holidayMap));

echo json_encode([
    'ok' => true,
    'student' => $student,
    'section' => $section['name'],
    'present' => array_values(array_filter(array_column($presentRows, 'd'))),
    'sessions' => array_values(array_filter(array_column($sessionRows, 'd'))),
    'holidays' => $calendarHolidays,
]);
