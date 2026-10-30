<?php
/**
 * student/rfid.php – link / unlink the school ID card used at the door reader.
 * Only a SHA-256 hash of the card UID is stored (see schema_rfid.sql).
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_rfid_helpers.php';
$user = require_role('student');

$roster = q('SELECT s.id, s.name, s.student_number, s.card_linked_at, sec.name AS section_name
    FROM students s LEFT JOIN sections sec ON sec.id = s.section_id
    WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1', [(int) $user['id']])->fetch();

layout_start('Link your school ID', 'rfid');
?>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('nfc', 14) ?> Tap-to-check-in</span><h2>Link your school ID</h2>
    <p class="muted">Tap your card on the classroom reader and you’re marked present — no QR needed.</p></div>
</div>

<?php if (!$roster): ?>
  <section class="card empty"><div class="empty-icon"><?= icon('id-card', 23) ?></div>
    <h3>Join a class first</h3>
    <p class="muted">Your card links to your class roster entry, so enroll in a section before tapping.</p>
    <a class="btn btn-primary" href="<?= e(url('student/index.php')) ?>"><?= icon('plus', 15) ?> Join a class</a></section>
<?php else: ?>

<section class="card rfid-card" id="rfidCard" data-rfid-card
         data-csrf="<?= e(csrf_token()) ?>"
         data-endpoint="<?= e(url('api/rfid_enroll.php')) ?>"
         data-state="<?= e(url('api/rfid_state.php?action=my_card')) ?>">
  <?php if ($roster['card_linked_at']): ?>
    <div class="rfid-status rfid-status-on">
      <span class="rfid-status-icon"><?= icon('credit-card', 22) ?></span>
      <div><b>Card linked. You can now tap to check in.</b>
        <small>Card ending in •••• · linked on <?= e(date('M j, Y', strtotime($roster['card_linked_at']))) ?> · <?= e($roster['student_number']) ?> · <?= e($roster['section_name'] ?? 'your section') ?></small></div>
    </div>
    <p class="muted small">Your card UID is stored as a one-way hash — never as plain text.</p>
    <button class="btn btn-secondary" type="button" data-rfid-unlink><?= icon('trash-2', 15) ?> Unlink</button>
  <?php else: ?>
    <div class="rfid-status">
      <span class="rfid-status-icon rfid-status-idle"><?= icon('id-card', 22) ?></span>
      <div><b id="rfidHeadline">Link your school ID card</b>
        <small id="rfidSubline">Tap your card on the reader to link it.</small></div>
    </div>

    <div class="rfid-code-panel" id="rfidCodePanel" hidden>
      <div class="rfid-code-pill">Pairing code: <b id="rfidCode">—</b> <span>(expires in <i id="rfidCountdown">5:00</i>)</span></div>
      <ol class="rfid-steps">
        <li>Go to the classroom reader and press its <b>Pair</b> button.</li>
        <li>Enter this 6-character code on the reader’s screen.</li>
        <li>Once it shows <b>waiting for card</b>, tap your school ID on it.</li>
      </ol>
      <p class="rfid-waiting"><span class="rfid-pulse"></span> Waiting for the reader…</p>
    </div>

    <button class="btn btn-primary" type="button" data-rfid-generate><?= icon('nfc', 17) ?> Link my school ID</button>
    <p class="muted small">One pairing code every 5 minutes · single use · works only with a reader you control.</p>
  <?php endif; ?>
  <div class="rfid-message" data-rfid-message hidden></div>
</section>

<section class="card">
  <div class="card-head"><h3><?= icon('wifi', 17) ?> How check-in works</h3></div>
  <div class="rfid-how">
    <div><span>1</span><p><b>Walk in.</b> The reader at the door is always on.</p></div>
    <div><span>2</span><p><b>Tap your ID.</b> One short beep and a green light mean you’re recorded.</p></div>
    <div><span>3</span><p><b>Check your record.</b> The tap shows up in My attendance right away.</p></div>
  </div>
</section>
<?php endif; ?>
<?php layout_end('<script src="' . e(url('assets/rfid.js')) . '"></script>'); ?>
