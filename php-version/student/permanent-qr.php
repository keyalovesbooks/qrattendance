<?php
/**
 * student/permanent-qr.php – the semester-long, printable QR code.
 *
 * Elementary students use ONE code for the whole semester instead of a
 * time-limited pass. The code lives on the student's roster row
 * (students.qr_code), is unique, never expires, and is generated once.
 */
require dirname(__DIR__) . '/config.php';
$user = require_role('student');

// the roster row the code belongs to (a student may sit in several sections;
// the first one owns the permanent code, since a card identifies the person)
$roster = q("SELECT st.id, st.name, st.student_number, st.qr_code, s.name AS section_name,
        COALESCE(sub.code,'SECTION') AS code
    FROM students st JOIN sections s ON s.id = st.section_id LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE st.user_id = ? ORDER BY st.id LIMIT 1", [(int) $user['id']])->fetch();

if (!$roster) {
    flash('Join a class first — your QR code is created from your class list entry.', 'error');
    redirect('student/index.php');
}

// issue the permanent code once, and keep it forever after
if (empty($roster['qr_code'])) {
    $code = strtoupper(bin2hex(random_bytes(12))); // 96-bit permanent identity, stored once per roster row
    $qr = qr_data_uri('QRS:' . $code);
    q('UPDATE students SET qr_code = ? WHERE id = ? AND qr_code IS NULL', [$code, $roster['id']]);
    $roster['qr_code'] = q('SELECT qr_code FROM students WHERE id = ?', [(int) $roster['id']])->fetchColumn();
} else {
    $qr = qr_data_uri('QRS:' . $roster['qr_code']);
}

$name = $roster['name'];
layout_start('My QR code', 'rfid');
?>
<a class="back" href="<?= e(url('student/index.php')) ?>"><?= icon('arrow-left', 14) ?> Back</a>

<div class="page-head">
  <div><span class="eyebrow"><?= icon('qr-code', 14) ?> Your semester pass</span><h2>My QR code</h2>
    <p class="muted">One code for the whole semester — print it, keep it in your ID pouch, and present it every class.</p></div>
  <div class="actions"><button class="btn btn-primary" onclick="window.print()"><?= icon('printer', 17) ?> Print my QR</button></div>
</div>

<section class="card permanent-qr-card" data-permanent-qr>
  <div class="permanent-qr-frame">
    <img src="<?= e($qr) ?>" alt="Your permanent QR code">
    <span class="permanent-qr-code"><?= e($roster['qr_code']) ?></span>
  </div>
  <div class="permanent-qr-side">
    <h3><?= e($name) ?></h3>
    <p class="muted"><?= e($roster['student_number']) ?><?= $roster['section_name'] ? ' · ' . e($roster['section_name']) : '' ?><?= $roster['code'] ? ' · ' . e($roster['code']) : '' ?></p>
    <ul class="rfid-steps">
      <li><b>Valid all semester.</b> This code does not expire — no need to generate a new one.</li>
      <li><b>Show it to your teacher.</b> They scan it once per class to mark you present.</li>
      <li><b>Keep it private.</b> It is linked to your name, so never share or sell copies.</li>
      <li><b>Lost it?</b> Sign in and open this page again to print another copy.</li>
    </ul>
    <p class="note"><?= icon('shield-check', 14) ?> Your code is stored once and cannot be reused by another student.</p>
    <button class="btn btn-secondary" onclick="window.print()"><?= icon('printer', 15) ?> Print</button>
  </div>
</section>
<?php layout_end(); ?>
