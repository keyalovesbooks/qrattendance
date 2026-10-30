<?php
require __DIR__ . '/config.php';
require_role('professor');
close_expired_sessions();

$terms = q('SELECT * FROM terms WHERE professor_id = ? ORDER BY id DESC', [professor_id()])->fetchAll();
$termId = (int) ($_GET['term'] ?? (current_term()['id'] ?? 0));
if (!owns_term($termId)) $termId = (int) (current_term()['id'] ?? 0);   // ignore terms that are not ours
$search = trim((string) ($_GET['q'] ?? ''));

/* ---------------- attendance charts (line/bar · day/week/month) ---------------- */
$prof = current_user();
$isElementary = (($prof['teacher_level'] ?? '') === 'primary');
$chartType = (($_GET['chart'] ?? 'line') === 'bar') ? 'bar' : 'line';
$chartPeriod = in_array($_GET['period'] ?? '', ['day', 'week', 'month'], true) ? ($_GET['period'] ?? 'week') : 'week';
$chartSubjectId = (int) ($_GET['chart_subject'] ?? 0);
$chartSectionId = (int) ($_GET['chart_section'] ?? 0);
$chartSubjects = [];
$chartSections = [];
if ($isElementary) {
    // Elementary: one graph per section (direct sections have no subject).
    $chartSections = q("SELECT s.id, s.name, sub.code FROM sections s JOIN subjects sub ON sub.id = s.subject_id
        WHERE s.term_id = ? AND s.section_mode = 'direct' ORDER BY sub.code, s.name", [$termId])->fetchAll();
    if (!$chartSectionId) $chartSectionId = (int) ($chartSections[0]['id'] ?? 0);
} else {
    // High school / college: graph per section inside a subject.
    $chartSubjects = q('SELECT sub.id, sub.code, sub.name FROM subjects sub WHERE sub.term_id = ? ORDER BY sub.code', [$termId])->fetchAll();
    if (!$chartSubjectId) $chartSubjectId = (int) ($chartSubjects[0]['id'] ?? 0);
    $chartSections = q('SELECT s.id, s.name FROM sections s JOIN subjects sub ON sub.id = s.subject_id WHERE s.subject_id = ? ORDER BY s.name', [$chartSubjectId])->fetchAll();
    if (!$chartSectionId) $chartSectionId = (int) ($chartSections[0]['id'] ?? 0);
}
$chart = ['type' => $chartType, 'period' => $chartPeriod, 'title' => '', 'labels' => [], 'values' => [], 'avg' => 0, 'checkins' => 0, 'sessions' => 0];
$selectedChartSection = null;
foreach ($chartSections as $cs) if ((int) $cs['id'] === $chartSectionId) { $selectedChartSection = $cs; break; }
if ($selectedChartSection) {
    $chart['title'] = $isElementary
        ? 'Section · ' . $selectedChartSection['name']
        : (q('SELECT code, name FROM subjects WHERE id = ?', [$chartSubjectId])->fetch()['name'] ?? 'Subject') . ' · ' . $selectedChartSection['name'];
    $count = $chartPeriod === 'day' ? 14 : ($chartPeriod === 'week' ? 8 : 6);
    $labels = [];
    $keys = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        if ($chartPeriod === 'day') {
            $ts = strtotime(date('Y-m-d', time() - $i * 86400));
            $keys[] = date('Y-m-d', $ts);
            $labels[] = date('M j', $ts);
        } elseif ($chartPeriod === 'week') {
            $ts = strtotime(date('Y-m-d', time() - $i * 7 * 86400));
            $mon = strtotime(date('Y-m-d', strtotime('monday this week', $ts)));
            $keys[] = date('Y-m-d', $mon);
            $labels[] = 'Wk ' . date('M j', $mon);
        } else {
            $ts = strtotime(date('Y-m-01', strtotime("-$i months")));
            $keys[] = date('Y-m', $ts);
            $labels[] = date('M Y', $ts);
        }
    }
    $winStart = date('Y-m-d H:i:s', strtotime($keys[0]));
    $enrolled = (int) q('SELECT COUNT(*) c FROM students WHERE section_id = ?', [$chartSectionId])->fetchColumn();
    $sessionsIn = q('SELECT started_at FROM class_sessions WHERE section_id = ? AND started_at >= ? ORDER BY started_at', [$chartSectionId, $winStart])->fetchAll();
    $tapsIn = q('SELECT a.scanned_at FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id WHERE cs.section_id = ? AND a.scanned_at >= ?', [$chartSectionId, $winStart])->fetchAll();
    $bucketOf = static function (string $datetime) use ($chartPeriod): string {
        $ts = strtotime($datetime);
        if ($chartPeriod === 'day') return date('Y-m-d', $ts);
        if ($chartPeriod === 'week') return date('Y-m-d', strtotime('monday this week', $ts));
        return date('Y-m', $ts);
    };
    $possible = [];
    foreach ($sessionsIn as $s) $possible[$bucketOf($s['started_at'])] = ($possible[$bucketOf($s['started_at'])] ?? 0) + $enrolled;
    $attended = [];
    foreach ($tapsIn as $t) $attended[$bucketOf($t['scanned_at'])] = ($attended[$bucketOf($t['scanned_at'])] ?? 0) + 1;
    $totalPossible = 0;
    $totalAttended = 0;
    $values = [];
    foreach ($keys as $k) {
        $p = (int) ($possible[$k] ?? 0);
        $a = (int) ($attended[$k] ?? 0);
        $totalPossible += $p;
        $totalAttended += $a;
        $values[] = $p ? (int) round($a / $p * 100) : 0;
    }
    $chart['labels'] = $labels;
    $chart['values'] = $values;
    $chart['sessions'] = count($sessionsIn);
    $chart['checkins'] = $totalAttended;
    $chart['avg'] = $totalPossible ? (int) round($totalAttended / $totalPossible * 100) : 0;
}
/* --------------------------------------------------------------------------------- */

$sql = "SELECT cs.*, s.name AS section_name, COALESCE(sub.code,'SECTION') AS code,
          COALESCE(sub.name,'Section attendance') AS subject_name,
          (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS present,
          (SELECT COUNT(*) FROM students st WHERE st.section_id = cs.section_id) AS total
        FROM class_sessions cs JOIN sections s ON s.id = cs.section_id LEFT JOIN subjects sub ON sub.id = s.subject_id
        WHERE s.term_id = ?";
$params = [$termId];
$hasMethod = (bool) q("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'method'")->fetchColumn();
if ($hasMethod) {
    $sql = str_replace('(SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS present',
        "(SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id AND a.method = 'qr') AS qr_count,
         (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id AND a.method = 'rfid') AS rfid_count,
         (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS present", $sql);
}
if ($search !== '') { $sql .= ' AND (COALESCE(sub.code,\'SECTION\') LIKE ? OR COALESCE(sub.name,\'Section attendance\') LIKE ? OR s.name LIKE ? OR cs.title LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }
$sessions = q($sql . ' ORDER BY cs.started_at DESC', $params)->fetchAll();

layout_start('Attendance history', 'history');
?>
<div class="page-head">
  <div><span class="eyebrow">Records</span><h2>Attendance history</h2><p class="muted">Every class session, grouped by school year and semester.</p></div>
</div>
<form class="card toolbar" method="get" id="historyForm">
  <select class="input" name="term" onchange="this.form.submit()">
    <?php foreach ($terms as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $termId ? 'selected' : '' ?>><?= e($t['school_year'] . ' · ' . $t['semester']) ?></option><?php endforeach; ?>
  </select>
  <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search subject, section or title">
  <button class="btn btn-secondary" type="submit"><?= icon('search', 15) ?> Filter</button>
  <?php if ($chartSections): ?>
    <span class="toolbar-sep" aria-hidden="true"></span>
    <div class="seg" role="radiogroup" aria-label="Chart type">
      <label><input type="radio" name="chart" value="line" <?= $chartType === 'line' ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= icon('trending-up', 15) ?> Line</span></label>
      <label><input type="radio" name="chart" value="bar" <?= $chartType === 'bar' ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= icon('chart-column', 15) ?> Bar</span></label>
    </div>
    <select class="input seg-select" name="period" onchange="this.form.submit()" aria-label="Attendance period">
      <option value="day" <?= $chartPeriod === 'day' ? 'selected' : '' ?>>By day</option>
      <option value="week" <?= $chartPeriod === 'week' ? 'selected' : '' ?>>By week</option>
      <option value="month" <?= $chartPeriod === 'month' ? 'selected' : '' ?>>By month</option>
    </select>
    <?php if (!$isElementary): ?>
      <select class="input seg-select" name="chart_subject" onchange="this.form.submit()" aria-label="Chart subject">
        <?php foreach ($chartSubjects as $cs): ?><option value="<?= (int) $cs['id'] ?>" <?= (int) $cs['id'] === $chartSubjectId ? 'selected' : '' ?>><?= e($cs['code'] . ' · ' . $cs['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select class="input seg-select" name="chart_section" onchange="this.form.submit()" aria-label="Chart section">
      <?php foreach ($chartSections as $cs): ?><option value="<?= (int) $cs['id'] ?>" <?= (int) $cs['id'] === $chartSectionId ? 'selected' : '' ?>><?= e($cs['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
</form>

<?php if ($chartSections): ?>
<section class="card chart-card">
  <div class="card-head">
    <div><h3><?= $chartType === 'line' ? icon('trending-up', 17) : icon('chart-column', 17) ?> Attendance rate by <?= $chartPeriod ?></h3>
      <p class="muted"><?= e($chart['title']) ?> · attendance ÷ possible attendance for each period</p></div>
    <span class="badge badge-blue"><?= e(ucfirst($chartType)) ?> chart</span>
  </div>
  <div class="chart-summary">
    <div class="chart-stat"><b><?= $chart['avg'] ?>%</b><span>average rate</span></div>
    <div class="chart-stat"><b><?= $chart['checkins'] ?></b><span>check-ins</span></div>
    <div class="chart-stat"><b><?= $chart['sessions'] ?></b><span>sessions</span></div>
    <div class="chart-stat"><b><?= count($chart['labels']) ?></b><span><?= $chartPeriod ?><?= count($chart['labels']) === 1 ? '' : 's' ?></span></div>
  </div>
  <div id="attendanceChart" data-chart="<?= e(json_encode($chart)) ?>"></div>
  <script>
  (function () {
    var el = document.getElementById('attendanceChart');
    var data = JSON.parse(el.getAttribute('data-chart') || 'null');
    if (!data || !data.labels.length) { el.innerHTML = '<p class="muted">No data for this selection yet.</p>'; return; }
    var W = 760, H = 300, P = { l: 46, r: 18, t: 20, b: 44 };
    var labels = data.labels, values = data.values, n = labels.length;
    var innerW = W - P.l - P.r, innerH = H - P.t - P.b;
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Attendance rate chart">';
    var step = Math.max(1, Math.ceil(n / 8));
    for (var g = 0; g <= 4; g++) {
      var gy = P.t + innerH - (g / 4) * innerH;
      svg += '<line x1="' + P.l + '" y1="' + gy + '" x2="' + (W - P.r) + '" y2="' + gy + '" class="chart-grid" />';
      svg += '<text x="' + (P.l - 8) + '" y="' + (gy + 4) + '" class="chart-ylabel" text-anchor="end">' + (g * 25) + '%</text>';
    }
    if (data.type === 'line') {
      var pts = [];
      for (var i = 0; i < n; i++) {
        var x = P.l + (n === 1 ? innerW / 2 : (i / (n - 1)) * innerW);
        var y = P.t + innerH - (values[i] / 100) * innerH;
        pts.push([x, y, i]);
      }
      svg += '<polygon points="' + P.l + ',' + (P.t + innerH) + ' ' + pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' ') + ' ' + (P.l + innerW) + ',' + (P.t + innerH) + '" class="chart-area" />';
      svg += '<polyline points="' + pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' ') + '" class="chart-line" />';
      pts.forEach(function (p) {
        svg += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="4" class="chart-dot"><title>' + labels[p[2]] + ': ' + values[p[2]] + '%</title></circle>';
      });
    } else {
      var slot = innerW / n, barW = Math.min(46, slot * 0.55);
      for (var b = 0; b < n; b++) {
        var bx = P.l + slot * b + (slot - barW) / 2;
        var bh = Math.max(2, (values[b] / 100) * innerH);
        var by = P.t + innerH - bh;
        svg += '<rect x="' + bx + '" y="' + by + '" width="' + barW + '" height="' + bh + '" rx="5" class="chart-bar"><title>' + labels[b] + ': ' + values[b] + '%</title></rect>';
        if (n <= 8) svg += '<text x="' + (bx + barW / 2) + '" y="' + (by - 6) + '" class="chart-val" text-anchor="middle">' + values[b] + '%</text>';
      }
    }
    for (var l2 = 0; l2 < n; l2 += step) {
      var lx = data.type === 'line'
        ? (P.l + (n === 1 ? innerW / 2 : (l2 / (n - 1)) * innerW))
        : (P.l + slot * l2 + slot / 2);
      svg += '<text x="' + lx + '" y="' + (H - P.b + 18) + '" class="chart-xlabel" text-anchor="middle">' + labels[l2] + '</text>';
    }
    svg += '</svg>';
    el.innerHTML = svg;
  })();
  </script>
</section>
<?php else: ?>
<section class="card"><div class="empty"><div class="empty-icon"><?= icon('trending-up', 20) ?></div><h3>No sections in this term</h3><p class="muted">Create a section first — its attendance history will be charted here.</p></div></section>
<?php endif; ?>
<section class="card">
  <?php if (!$sessions): ?>
    <div class="empty"><div class="empty-icon"><?= icon('history', 20) ?></div><h3>No sessions found</h3><p class="muted">Sessions you start from a section page appear here.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Subject / section</th><th>Session</th><th>Present</th><th>Rate</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s): $rate = $s['total'] ? round($s['present'] / $s['total'] * 100) : 0; ?>
      <tr>
        <td data-label="Date"><?= e(date('M j, Y g:i A', strtotime($s['started_at']))) ?></td>
        <td data-label="Class"><b><?= e($s['code']) ?></b> · <?= e($s['section_name']) ?></td>
        <td data-label="Session"><?= e($s['title']) ?></td>
        <td data-label="Present"><?= (int) $s['present'] ?> / <?= (int) $s['total'] ?><?php if (!empty($hasMethod)): ?><br><span class="badge badge-blue">QR <?= (int) ($s['qr_count'] ?? 0) ?></span> <span class="badge badge-green">RFID <?= (int) ($s['rfid_count'] ?? 0) ?></span><?php endif; ?></td>
        <td data-label="Rate"><div class="bar"><span style="width:<?= $rate ?>%"></span></div><?= $rate ?>%</td>
        <td data-label="Status"><span class="badge <?= $s['status'] === 'open' ? 'badge-green' : '' ?>"><?= $s['status'] === 'open' ? 'Live' : 'Closed' ?></span></td>
        <td data-label=""><a class="btn btn-soft btn-sm" href="<?= e(url('export.php?type=session&id=' . $s['id'])) ?>"><?= icon('download', 15) ?> Export CSV</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php layout_end(); ?>
