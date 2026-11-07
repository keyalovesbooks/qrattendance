<?php
require __DIR__ . '/config.php';
require_role('professor');
close_expired_sessions();

$sid = (int) ($_GET['session'] ?? 0);
$session = q("SELECT cs.*, s.name AS section_name, s.id AS sec_id, COALESCE(sub.code,'SECTION') AS code
    FROM class_sessions cs JOIN sections s ON s.id = cs.section_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE cs.id = ? AND s.term_id IN (SELECT id FROM terms WHERE professor_id = ?)", [$sid, professor_id()])->fetch();
if (!$session) { http_response_code(404); exit('Session not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    csrf_check();
    if (!owns_session((string) $sid)) { http_response_code(404); exit('Session not found.'); }
    q("UPDATE class_sessions SET status = 'closed' WHERE id = ?", [$sid]);
    flash('Session closed.');
    redirect('section.php?id=' . $session['sec_id']);
}

$present = q('SELECT st.name, st.student_number, a.scanned_at FROM attendance a JOIN students st ON st.id = a.student_id WHERE a.session_id = ? ORDER BY a.scanned_at DESC', [$sid])->fetchAll();
$total = (int) q('SELECT COUNT(*) FROM students WHERE section_id = ?', [$session['sec_id']])->fetchColumn();

layout_start('Scan attendance', 'dashboard');
?>
<a class="back" href="<?= e(url('section.php?id=' . $session['sec_id'])) ?>"><?= icon('arrow-left', 14) ?> Back to section</a>
<div class="page-head">
  <div><span class="eyebrow"><?= $session['status'] === 'open' ? '<span class="dot-live"></span> Live session' : 'Closed session' ?></span>
    <h2><?= e($session['code'] . ' · ' . $session['section_name']) ?></h2>
    <p class="muted"><?= e($session['title']) ?> · open until <?= e(date('g:i A', strtotime($session['expires_at']))) ?></p></div>
  <?php if ($session['status'] === 'open'): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="close"><button class="btn btn-secondary"><?= icon('circle-stop', 16) ?> End session</button></form>
  <?php endif; ?>
</div>

<div class="scan-layout">
  <section class="card scanner-card">
    <?php if ($session['status'] !== 'open'): ?>
      <div class="empty"><h3>This session is closed</h3><p class="muted">Start a new session from the section page.</p></div>
    <?php else: ?>
    <div class="camera" id="camera">
      <video id="video" playsinline muted></video>
      <div class="reticle"><span></span></div>
      <div class="camera-msg" id="cameraMsg">Tap “Start camera” to begin scanning.</div>
    </div>
    <div class="scan-controls">
      <button class="btn btn-primary" id="startCam"><?= icon('camera', 17) ?> <span>Start camera</span></button>
      <select class="input" id="cameraSelect" hidden></select>
    </div>
    <div class="scan-result" id="scanResult" aria-live="polite"></div>
    <form class="form-row" id="manualForm"><input class="input" id="manualCode" placeholder="Or paste a QR code value"><button class="btn btn-secondary">Submit</button></form>
    <?php endif; ?>
  </section>

  <aside class="card">
    <div class="card-head"><h3>Checked in</h3><span class="count"><b id="presentCount"><?= count($present) ?></b> / <?= $total ?></span></div>
    <div id="presentList" class="present-list">
      <?php foreach ($present as $p): ?>
        <div class="list-row compact"><span class="person"><span class="avatar sm"><?= e(initials($p['name'])) ?></span><span><b><?= e($p['name']) ?></b><small class="muted"><?= e($p['student_number']) ?></small></span></span><small class="muted"><?= e(date('g:i A', strtotime($p['scanned_at']))) ?></small></div>
      <?php endforeach; ?>
      <?php if (!$present): ?><p class="muted small pad" id="emptyPresent">No one has checked in yet.</p><?php endif; ?>
    </div>
  </aside>

  <section class="card rfid-widget" data-rfid-widget data-state="<?= e(url('api/rfid_state.php')) ?>">
    <div class="card-head"><h3><?= icon('nfc', 17) ?> RFID readers</h3><span class="badge badge-green"><span class="dot-live"></span> live</span></div>
    <ul class="rfid-widget-list" data-rfid-widget-list></ul>
    <p class="muted small" data-rfid-widget-empty>No readers registered yet — pair one from <b>RFID readers</b> in the sidebar.</p>
    <p class="rfid-widget-note"><?= icon('shield-check', 13) ?> Taps from the door reader land in the same session as QR check-ins.</p>
  </section>
</div>
<?php
layout_end('<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>window.SCAN_CONFIG = ' . json_encode(['endpoint' => url('api/checkin.php'), 'session' => $sid, 'csrf' => csrf_token()]) . ';</script>
<script src="' . e(url('assets/scanner.js')) . '"></script>
<script src="' . e(url('assets/rfid.js')) . '"></script>');
