/**
 * assets/rfid.js – polling + UI for the RFID feature.
 *
 * student/rfid.php   : generate a pairing code, countdown, live "card linked"
 * reader.php         : live reader list, pairing-code polling, revoke
 * scan.php           : "RFID readers" widget next to the camera
 *
 * Browser actions are CSRF-protected via the data-csrf token; reader endpoints
 * are never called from here.
 */
(function () {
  const POLL_MS = 3000;

  const readCsrf = (root) => (root && root.dataset.csrf ? root.dataset.csrf : '');

  function message(root, text, tone) {
    const box = (root || document).querySelector('[data-rfid-message]');
    if (!box) return;
    box.hidden = !text;
    box.textContent = text || '';
    box.className = 'rfid-message' + (text ? ' rfid-message-' + (tone || 'ok') : '');
  }

  async function post(url, data, csrf) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '' },
      credentials: 'same-origin',
      body: JSON.stringify(data || {}),
    });
    let body = null;
    try { body = await res.json(); } catch (e) { body = { ok: false, error: 'Bad response.' }; }
    return { res, body };
  }

  function clock(ms) {
    const total = Math.max(0, Math.ceil(ms / 1000));
    return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
  }

  /* ---------------- student card linking ---------------- */
  const card = document.querySelector('[data-rfid-card]');
  if (card) {
    const endpoint = card.dataset.endpoint;
    const stateUrl = card.dataset.state;
    const csrf = readCsrf(card);
    const panel = document.getElementById('rfidCodePanel');
    let expiresAt = 0;

    function tick() {
      const counter = document.getElementById('rfidCountdown');
      if (!counter || !expiresAt) return;
      const left = expiresAt - Date.now();
      counter.textContent = clock(left);
      if (left <= 0) {
        expiresAt = 0;
        counter.textContent = 'expired';
        message(card, 'Your pairing code expired — generate a new one.', 'error');
      }
    }

    async function pollCard() {
      if (!expiresAt) return;
      try {
        const res = await fetch(stateUrl, { credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.linked) {
          message(card, 'Card linked. You can now tap to check in.', 'ok');
          setTimeout(() => window.location.reload(), 900);
        }
      } catch (e) { /* keep polling */ }
    }

    const generate = card.querySelector('[data-rfid-generate]');
    generate && generate.addEventListener('click', async () => {
      generate.disabled = true;
      const { res, body } = await post(endpoint, { action: 'generate' }, csrf);
      generate.disabled = false;
      if (!res.ok || !body.ok) {
        message(card, body && body.error ? body.error : 'Could not create a pairing code.', 'error');
        return;
      }
      document.getElementById('rfidCode').textContent = body.pairing_code;
      expiresAt = Date.parse(String(body.expires_at).replace(' ', 'T')) || Date.now() + 300000;
      if (panel) panel.hidden = false;
      document.getElementById('rfidHeadline').textContent = 'Pairing code created';
      document.getElementById('rfidSubline').textContent = 'Enter this code on the reader, then tap your ID.';
      generate.hidden = true;
      tick();
      setInterval(tick, 500);
      message(card, 'Waiting for the reader…', 'ok');
    });

    const unlink = card.querySelector('[data-rfid-unlink]');
    unlink && unlink.addEventListener('click', async () => {
      if (!confirm('Unlink this card? You’ll have to tap it again to check in.')) return;
      unlink.disabled = true;
      const { res, body } = await post(endpoint, { action: 'unlink' }, csrf);
      unlink.disabled = false;
      if (!res.ok || !body.ok) { message(card, body && body.error ? body.error : 'Could not unlink the card.', 'error'); return; }
      setTimeout(() => window.location.reload(), 700);
    });
  }

  /* ---------------- reader page: pairing + live list ---------------- */
  function readerRow(r) {
    const online = r.status === 'online';
    const when = r.last_tap_at
      ? new Date(String(r.last_tap_at).replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
      : 'waiting for a tap';
    return '<div class="reader-row">'
      + '<span class="reader-dot ' + (online ? 'is-online' : '') + '"></span>'
      + '<span class="reader-info"><b>' + escapeHtml(r.name) + '</b><small>'
      + escapeHtml(r.section_name || 'Not assigned') + ' · ' + (online ? 'online now' : 'offline') + '</small></span>'
      + '<span class="reader-tap"><b>' + escapeHtml(r.last_tap_student || 'No taps yet') + '</b><small>' + when + '</small></span>'
      + '<span class="badge badge-blue">' + r.taps_today + ' today</span>'
      + '<button class="btn btn-danger-ghost btn-sm" data-revoke-reader="' + r.id + '">Revoke</button>'
      + '</div>';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
  }

  const pairRoot = document.querySelector('[data-rfid-pair-root]');
  const list = document.querySelector('[data-rfid-list]');
  const pairCard = document.querySelector('[data-rfid-pair-card]');

  if (pairRoot || list) {
    const stateUrl = (pairRoot || list).dataset.state;

    async function refreshList() {
      try {
        const res = await fetch(stateUrl + '?action=readers', { credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.ok && list) list.innerHTML = (data.readers || []).map(readerRow).join('');
      } catch (e) { /* keep the last known state */ }
    }

    async function watchPairing() {
      const value = document.querySelector('[data-rfid-pair-value]');
      const code = value ? value.textContent.trim() : (pairRoot && pairRoot.dataset.rfidPairCode);
      if (!code) return;
      try {
        const res = await fetch(stateUrl + '?action=pair_status&code=' + encodeURIComponent(code), { credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.paired) {
          if (pairCard) {
            pairCard.innerHTML = '<div class="card-head"><h3>Reader paired</h3><span class="badge badge-green">Connected</span></div>'
              + '<div class="rfid-status rfid-status-on"><span class="rfid-status-icon">' + (window.renderIcons ? '◈' : '◈') + '</span>'
              + '<div><b>' + escapeHtml((data.reader && data.reader.name) || 'Reader') + '</b>'
              + '<small>The reader is now assigned and ready to record taps.</small></div></div>';
          }
          clearInterval(pairTimer);
          refreshList();
        }
      } catch (e) { /* keep polling */ }
    }

    refreshList();
    const pairTimer = setInterval(watchPairing, POLL_MS);
    if (pairCard) watchPairing();
    setInterval(refreshList, POLL_MS);
    // countdown on the pairing card
    document.querySelectorAll('[data-rfid-countdown][data-expires]').forEach((el) => {
      const ends = Number(el.dataset.expires);
      const tick = () => {
        el.textContent = clock(ends - Date.now());
        if (ends - Date.now() <= 0) el.textContent = 'expired';
      };
      tick();
      setInterval(tick, 1000);
    });
  }

  /* ---------------- scan.php widget ---------------- */
  document.querySelectorAll('[data-rfid-widget]').forEach((widget) => {
    const stateUrl = widget.dataset.state;
    const tbody = widget.querySelector('[data-rfid-widget-list]');
    const empty = widget.querySelector('[data-rfid-widget-empty]');
    async function draw() {
      try {
        const res = await fetch(stateUrl + '?action=readers', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data || !data.ok) return;
        const readers = data.readers || [];
        if (empty) empty.hidden = readers.length > 0;
        if (tbody) {
          tbody.innerHTML = readers.map((r) => {
            const online = r.status === 'online';
            const when = r.last_tap_at
              ? new Date(String(r.last_tap_at).replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
              : '—';
            return '<li><span class="reader-dot ' + (online ? 'is-online' : '') + '"></span>'
              + '<span><b>' + escapeHtml(r.name) + '</b><small>' + escapeHtml(r.last_tap_student || 'No taps yet') + '</small></span>'
              + '<em>' + when + '</em></li>';
          }).join('');
        }
      } catch (e) { /* keep the last known state */ }
    }
    draw();
    setInterval(draw, POLL_MS);
  });

  /* ---------------- revoke ---------------- */
  document.addEventListener('click', (ev) => {
    const button = ev.target.closest('[data-revoke-reader]');
    if (!button) return;
    const id = button.dataset.revokeReader;
    const form = document.getElementById('revokeForm');
    if (!form || !confirm('Revoke this reader? It will stop recording attendance immediately.')) return;
    document.getElementById('revokeReaderId').value = id;
    form.submit();
  });
})();
