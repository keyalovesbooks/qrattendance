<?php
/**
 * reader.php (professor) – register, pair and revoke ESP32 classroom readers,
 * see which are online, and watch live taps.
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/config_rfid_helpers.php';
$user = require_role('professor');
close_expired_sessions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_reader') {
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $section = q('SELECT s.id, s.name FROM sections s
                WHERE s.id = ? AND s.term_id = ? AND s.professor_id = ?', [$sectionId, current_term()['id'] ?? 0, professor_id()])->fetch();
            if (!$section) throw new RuntimeException('Choose one of your sections first.');
            if ($name === '') {
                $name = 'Reader ' . (((int) q('SELECT COUNT(*) FROM rfid_readers')->fetchColumn()) + 1);
            }
            $code = rfid_create_pairing_code((int) $user['id'], 'register_reader', (int) $section['id'], $name);
            $_SESSION['rfid_pair_code'] = $code;
            $_SESSION['rfid_pair_expires'] = time() + 300;
            flash('Pairing code ready — enter it on the reader.');
        } elseif ($action === 'revoke_reader') {
            $readerId = (int) ($_POST['reader_id'] ?? 0);
            $mine = (bool) q('SELECT 1 FROM rfid_readers r JOIN sections s ON s.id = r.section_id
                WHERE r.id = ? AND s.professor_id = ?', [$readerId, professor_id()])->fetchColumn();
            if (!$mine) throw new RuntimeException('That reader does not belong to your workspace.');
            q('UPDATE rfid_readers SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [now(), $readerId]);
            flash('Reader revoked. It can no longer record attendance.');
        } elseif ($action === 'clear_code') {
            unset($_SESSION['rfid_pair_code'], $_SESSION['rfid_pair_expires']);
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    } catch (PDOException) {
        flash('Database error. Please try again.', 'error');
    }
    redirect('reader.php');
}

// drop the pairing code once it has expired
if (!empty($_SESSION['rfid_pair_expires']) && $_SESSION['rfid_pair_expires'] < time()) {
    unset($_SESSION['rfid_pair_code'], $_SESSION['rfid_pair_expires']);
}
$pairCode = $_SESSION['rfid_pair_code'] ?? null;
$pairExpires = (int) ($_SESSION['rfid_pair_expires'] ?? 0);

$sections = q("SELECT s.id, s.name, COALESCE(sub.code,'SECTION') AS code FROM sections s
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.term_id = ? AND s.professor_id = ? ORDER BY COALESCE(sub.code,'SECTION'), s.name", [current_term()['id'] ?? 0, professor_id()])->fetchAll();

$readers = q('SELECT r.*, s.name AS section_name
    FROM rfid_readers r JOIN sections s ON s.id = r.section_id
    WHERE s.professor_id = ? ORDER BY r.revoked_at IS NOT NULL, r.id', [professor_id()])->fetchAll();

/** one row of the readers widget (also rendered live by assets/rfid.js) */
function reader_row(array $r): string
{
    $online = rfid_reader_status($r) === 'online';
    $today = $r['section_id']
        ? (int) q('SELECT COUNT(*) FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
            WHERE cs.section_id = ? AND DATE(a.scanned_at) = CURDATE() AND a.method = ?',
            [(int) $r['section_id'], 'rfid'])->fetchColumn()
        : 0;
    $revoked = !empty($r['revoked_at']);
    return '<div class="reader-row' . ($revoked ? ' reader-revoked' : '') . '">'
        . '<span class="reader-dot ' . ($online ? 'is-online' : '') . '" title="' . ($online ? 'Online' : 'Offline') . '"></span>'
        . '<span class="reader-info"><b>' . e($r['name']) . '</b>'
        . '<small>' . e($r['section_name'] ?? 'Not assigned') . ' · ' . ($online ? 'online now' : 'offline') . '</small></span>'
        . '<span class="reader-tap"><b>' . e($r['last_tap_student'] ?? 'No taps yet') . '</b>'
        . '<small>' . ($r['last_tap_at'] ? e(date('g:i A', strtotime($r['last_tap_at']))) : 'waiting for a tap') . '</small></span>'
        . '<span class="badge badge-blue">' . $today . ' today</span>'
        . ($revoked ? '<span class="badge">Revoked</span>'
            : '<button class="btn btn-danger-ghost btn-sm" data-revoke-reader="' . (int) $r['id'] . '">'
              . icon('trash-2', 14) . ' Revoke</button>')
        . '</div>';
}

layout_start('RFID readers', 'reader');
?>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('nfc', 14) ?> Classroom hardware</span><h2>RFID readers</h2>
    <p class="muted">Pair the door readers to a section, then students tap their school ID to check in.</p></div>
  <div class="actions">
    <?php if ($sections): ?><button class="btn btn-primary" data-open="readerModal"><?= icon('plus', 17) ?> Add reader</button><?php endif; ?>
  </div>
</div>

<section class="card"
  data-rfid-pair-root
  data-csrf="<?= e(csrf_token()) ?>"
  data-state="<?= e(url('api/rfid_state.php')) ?>"
  <?= $pairCode ? 'data-rfid-pair-code="' . e($pairCode) . '"' : '' ?>>
  <div class="card-head"><h3><?= icon('radio', 17) ?> Readers</h3>
    <span class="muted small"><?= count($readers) ?> registered</span></div>

  <?php if ($readers): ?>
    <div class="reader-list" data-rfid-list>
      <?php foreach ($readers as $r): ?><?= reader_row($r) ?><?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty"><div class="empty-icon"><?= icon('nfc', 20) ?></div>
      <h3>No readers yet</h3>
      <p class="muted">Plug in the ESP32, press its Pair button and add the reader here — you’ll get a 6-character code that lasts 5 minutes.</p>
      <?php if ($sections): ?><button class="btn btn-primary btn-sm" data-open="readerModal"><?= icon('plus', 15) ?> Add reader</button><?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($pairCode): ?>
<section class="card rfid-pair-card" data-rfid-pair-card>
  <div class="card-head"><h3><?= icon('wifi', 17) ?> Pairing in progress</h3>
    <span class="badge badge-green"><span class="dot-live"></span> Listening</span></div>
  <div class="rfid-code-pill">Pairing code: <b data-rfid-pair-value><?= e($pairCode) ?></b>
    <span>(expires in <i data-rfid-countdown data-expires="<?= $pairExpires * 1000 ?>">5:00</i>)</span></div>
  <ol class="rfid-steps">
    <li>Press <b>Pair</b> on the ESP32 reader (or hold the pair button while it boots).</li>
    <li>Type <b><?= e($pairCode) ?></b> on the reader’s screen.</li>
    <li>Keep this page open — the reader appears in the list below as soon as it checks in.</li>
  </ol>
  <p class="rfid-waiting"><span class="rfid-pulse"></span> Waiting for the reader…</p>
</section>
<?php endif; ?>

<?php if ($sections): ?>
<dialog class="modal" id="readerModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="add_reader">
    <h3>Add reader</h3>
    <p class="muted small">The reader stores its device token in ESP32 NVS, so it only needs to be paired once.</p>
    <label class="label">Reader name</label>
    <input class="input" name="name" placeholder="Reader 1 (optional)">
    <label class="label">Assign to section</label>
    <select class="input" name="section_id" required>
      <option value="">Choose a section…</option>
      <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?>
    </select>
    <p class="note"><?= icon('shield-check', 14) ?> The device token is shown once to the reader and never to anyone else.</p>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button>
      <button class="btn btn-primary"><?= icon('plus', 15) ?> Generate pairing code</button></div>
  </form>
</dialog>

<?php endif; ?>
<form method="post" id="revokeForm" hidden><?= csrf_field() ?>
  <input type="hidden" name="action" value="revoke_reader"><input type="hidden" name="reader_id" id="revokeReaderId"></form>
<?php layout_end('<script src="' . e(url('assets/rfid.js')) . '"></script>'); ?>
