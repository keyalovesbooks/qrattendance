<?php
/**
 * attendance_modes.php – primary direct-section windows.
 *
 * A direct section has four daily time points (am_in, am_out, pm_in, pm_out).
 * Each point opens a separate 30-minute class session. Sessions are created
 * lazily when a page/API is requested during the window, and are reused by QR
 * and RFID check-in. Subject-based sessions continue to use the existing
 * manual Start attendance flow.
 */
require_once __DIR__ . '/config.php';

const DIRECT_ATTENDANCE_WINDOW_MINUTES = 30;

function direct_slot_labels(?string $mode = null): array
{
    $all = [
        'am_in' => 'Morning · Time in',
        'am_out' => 'Morning · Time out',
        'pm_in' => 'Afternoon · Time in',
        'pm_out' => 'Afternoon · Time out',
    ];
    return match ($mode) {
        'time_in' => array_intersect_key($all, array_flip(['am_in', 'pm_in'])),
        'time_out' => array_intersect_key($all, array_flip(['am_out', 'pm_out'])),
        default => $all,
    };
}

function direct_attendance_modes(): array
{
    return ['time_in' => 'Record only time in', 'time_out' => 'Record only time out', 'both' => 'Record both time in and time out'];
}

/** Create (if needed) and return the direct-section windows active right now. */
function sync_direct_attendance_sessions(int $sectionId): array
{
    $section = q('SELECT id, name, section_mode, term_id, professor_id FROM sections
        WHERE id = ? AND section_mode = ?', [$sectionId, 'direct'])->fetch();
    if (!$section) return [];
    $times = q('SELECT * FROM section_attendance_times WHERE section_id = ?', [$sectionId])->fetch();
    if (!$times) return [];

    $mode = in_array($times['attendance_mode'] ?? 'both', ['time_in', 'time_out', 'both'], true)
        ? ($times['attendance_mode'] ?? 'both') : 'both';
    $enabledSlots = direct_slot_labels($mode);
    $allSlots = direct_slot_labels();
    $disabledSlots = array_diff_key($allSlots, $enabledSlots);
    if ($disabledSlots) {
        $disabled = array_keys($disabledSlots);
        $placeholders = implode(',', array_fill(0, count($disabled), '?'));
        q("UPDATE class_sessions SET status = 'closed' WHERE section_id = ? AND session_date = ? AND status = 'open' AND attendance_slot IN ($placeholders)",
            array_merge([$sectionId, date('Y-m-d')], $disabled));
    }
    $today = date('Y-m-d');
    $nowSeconds = time();
    $active = [];
    foreach ($enabledSlots as $slot => $title) {
        if (empty($times[$slot])) continue;
        $start = strtotime($today . ' ' . $times[$slot]);
        $end = $start + DIRECT_ATTENDANCE_WINDOW_MINUTES * 60;
        if ($nowSeconds < $start || $nowSeconds >= $end) continue;

        $startedAt = date('Y-m-d H:i:s', $start);
        $expiresAt = date('Y-m-d H:i:s', $end);
        q('INSERT IGNORE INTO class_sessions
              (section_id, title, status, started_at, expires_at, session_date, attendance_slot)
           VALUES (?,?,?,?,?,?,?)', [$sectionId, $title, 'open', $startedAt, $expiresAt, $today, $slot]);

        $session = q('SELECT * FROM class_sessions WHERE section_id = ? AND session_date = ? AND attendance_slot = ? LIMIT 1',
            [$sectionId, $today, $slot])->fetch();
        if ($session && $session['status'] === 'open' && strtotime($session['expires_at']) > $nowSeconds) {
            $active[] = $session;
        } elseif ($session && $session['status'] === 'open') {
            q("UPDATE class_sessions SET status = 'closed' WHERE id = ?", [$session['id']]);
        }
    }
    return $active;
}

/** Open direct attendance windows for every direct section currently owned by a professor. */
function sync_professor_direct_sessions(int $professorId): array
{
    $rows = q('SELECT id FROM sections WHERE professor_id = ? AND section_mode = ?', [$professorId, 'direct'])->fetchAll();
    $active = [];
    foreach ($rows as $row) $active = array_merge($active, sync_direct_attendance_sessions((int) $row['id']));
    return $active;
}

/** Ensure the enrolled student's direct-section windows exist before dashboard query. */
function sync_student_direct_sessions(int $userId): void
{
    $rows = q('SELECT DISTINCT s.id FROM students st JOIN sections s ON s.id = st.section_id
        WHERE st.user_id = ? AND s.section_mode = ?', [$userId, 'direct'])->fetchAll();
    foreach ($rows as $row) sync_direct_attendance_sessions((int) $row['id']);
}

function direct_sections_for_professor(int $termId): array
{
    return q('SELECT s.*, (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS student_count
        FROM sections s WHERE s.term_id = ? AND s.professor_id = ? AND s.section_mode = ? ORDER BY s.name',
        [$termId, professor_id(), 'direct'])->fetchAll();
}

function save_direct_times(int $sectionId, array $post): void
{
    $section = q('SELECT term_id, professor_id FROM sections WHERE id = ? AND section_mode = ?', [$sectionId, 'direct'])->fetch();
    if (!$section) throw new RuntimeException('Direct section not found.');

    $previousMode = q('SELECT attendance_mode FROM section_attendance_times WHERE section_id = ?', [$sectionId])->fetchColumn() ?: 'both';
    $mode = (string) ($post['attendance_mode'] ?? $previousMode);
    if (!in_array($mode, ['time_in', 'time_out', 'both'], true)) throw new RuntimeException('Choose what attendance to record.');
    $enabledSlots = direct_slot_labels($mode);
    $slots = [];
    foreach (array_keys(direct_slot_labels()) as $slot) {
        $value = trim((string) ($post[$slot] ?? ''));
        if (!isset($enabledSlots[$slot])) { $slots[$slot] = null; continue; }
        if ($value !== '' && !preg_match('/^\\d{2}:\\d{2}(:\\d{2})?$/', $value)) {
            throw new RuntimeException('Enter a valid time for each enabled attendance window.');
        }
        $slots[$slot] = $value ?: null;
    }
    if (!array_filter($slots, static fn($v) => $v !== null)) throw new RuntimeException('Add at least one attendance time for the selected mode.');
    $minutes = static function (?string $time): ?int {
        if (!$time) return null;
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return $hour * 60 + $minute;
    };
    $newWindows = [];
    foreach ($slots as $slot => $time) {
        if ($time !== null) $newWindows[$slot] = ['start' => $minutes($time), 'end' => $minutes($time) + DIRECT_ATTENDANCE_WINDOW_MINUTES, 'label' => direct_slot_labels($mode)[$slot] ?? direct_slot_labels()[$slot]];
    }

    // Do not allow this section's own enabled 30-minute windows to collide.
    $newNames = array_keys($newWindows);
    for ($i = 0; $i < count($newNames); $i++) {
        for ($j = $i + 1; $j < count($newNames); $j++) {
            $a = $newWindows[$newNames[$i]]; $b = $newWindows[$newNames[$j]];
            if ($a['start'] < $b['end'] && $b['start'] < $a['end']) {
                throw new RuntimeException($a['label'] . ' overlaps ' . $b['label'] . '. Set the windows at least 30 minutes apart.');
            }
        }
    }

    // Compare against every other section owned by this professor in this term.
    // Direct windows recur daily, so they must not overlap any weekly subject meeting.
    $others = q('SELECT s.id, s.name, s.section_mode, t.attendance_mode, t.am_in, t.am_out, t.pm_in, t.pm_out,
            ss.day, ss.start_time, ss.end_time
        FROM sections s
        LEFT JOIN section_attendance_times t ON t.section_id = s.id
        LEFT JOIN section_schedules ss ON ss.section_id = s.id
        WHERE s.term_id = ? AND s.professor_id = ? AND s.id <> ?',
        [(int) $section['term_id'], (int) $section['professor_id'], $sectionId])->fetchAll();

    $reported = [];
    foreach ($others as $other) {
        $otherWindows = [];
        if ($other['section_mode'] === 'direct') {
            $otherMode = in_array($other['attendance_mode'] ?? 'both', ['time_in', 'time_out', 'both'], true) ? ($other['attendance_mode'] ?? 'both') : 'both';
            foreach (direct_slot_labels($otherMode) as $slot => $slotLabel) {
                $start = $minutes($other[$slot] ?? null);
                if ($start !== null) $otherWindows[] = ['start' => $start, 'end' => $start + DIRECT_ATTENDANCE_WINDOW_MINUTES, 'label' => $slotLabel];
            }
        } elseif ($other['start_time'] !== null && $other['end_time'] !== null) {
            $otherWindows[] = ['start' => $minutes($other['start_time']), 'end' => $minutes($other['end_time']),
                'label' => day_label($other['day']) . ' ' . time_label($other['start_time']) . '–' . time_label($other['end_time'])];
        }
        foreach ($newWindows as $window) foreach ($otherWindows as $otherWindow) {
            if ($window['start'] < $otherWindow['end'] && $otherWindow['start'] < $window['end']) {
                $key = $other['id'] . ':' . $window['label'] . ':' . $otherWindow['label'];
                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    throw new RuntimeException('Schedule conflict: ' . $other['name'] . ' has ' . $otherWindow['label'] . ', which overlaps ' . $window['label'] . '. Choose another time.');
                }
            }
        }
    }

    q('INSERT INTO section_attendance_times (section_id, attendance_mode, am_in, am_out, pm_in, pm_out) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE attendance_mode=VALUES(attendance_mode), am_in=VALUES(am_in), am_out=VALUES(am_out), pm_in=VALUES(pm_in), pm_out=VALUES(pm_out)',
       [$sectionId, $mode, $slots['am_in'], $slots['am_out'], $slots['pm_in'], $slots['pm_out']]);
}
