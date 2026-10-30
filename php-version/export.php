<?php
require __DIR__ . '/config.php';
require_role('professor');

$type = $_GET['type'] ?? 'roster';
$id = (int) ($_GET['id'] ?? 0);

function send_csv(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        // neutralise spreadsheet formula injection
        $r = array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, $r);
        fputcsv($out, $r, ',', '"', '\\');
    }
    exit;
}

if ($type === 'session') {
    $s = q("SELECT cs.*, sec.name AS section_name, COALESCE(sub.code,'SECTION') AS code FROM class_sessions cs
        JOIN sections sec ON sec.id = cs.section_id LEFT JOIN subjects sub ON sub.id = sec.subject_id WHERE cs.id = ?", [$id])->fetch();
    if (!$s || !owns_session((string) $id)) exit('Not found');
    $hasMethod = (bool) q("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'method'")->fetchColumn();
    $select = $hasMethod
        ? 'SELECT st.student_number, st.name, IF(a.id IS NULL, "Absent", "Present") AS status, IFNULL(a.method, "") AS method, a.scanned_at'
        : 'SELECT st.student_number, st.name, IF(a.id IS NULL, "Absent", "Present") AS status, a.scanned_at';
    $rows = q($select . '
        FROM students st LEFT JOIN attendance a ON a.student_id = st.id AND a.session_id = ? WHERE st.section_id = ? ORDER BY st.name', [$id, $s['section_id']])->fetchAll(PDO::FETCH_NUM);
    $header = $hasMethod
        ? ['student_id', 'name', 'status', 'method', 'scanned_at']
        : ['student_id', 'name', 'status', 'scanned_at'];
    send_csv("{$s['code']}-{$s['section_name']}-" . date('Y-m-d', strtotime($s['started_at'])) . '.csv', $header, $rows);
}

$sec = q("SELECT s.name, COALESCE(sub.code,'SECTION') AS code FROM sections s LEFT JOIN subjects sub ON sub.id = s.subject_id WHERE s.id = ?", [$id])->fetch();
if (!$sec || !owns_section($id)) exit('Not found');
$rows = q('SELECT st.student_number, st.name, st.email, IF(st.user_id IS NULL, "Pending", "Enrolled"),
    (SELECT COUNT(*) FROM attendance a WHERE a.student_id = st.id) FROM students st WHERE st.section_id = ? ORDER BY st.name', [$id])->fetchAll(PDO::FETCH_NUM);
send_csv("{$sec['code']}-{$sec['name']}-roster.csv", ['student_id', 'name', 'email', 'enrollment', 'sessions_attended'], $rows);
