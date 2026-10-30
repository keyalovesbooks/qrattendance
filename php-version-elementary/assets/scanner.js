// Professor camera scanner – uses jsQR (loaded from CDN) + getUserMedia
(function () {
  const cfg = window.SCAN_CONFIG;
  const video = document.getElementById('video');
  if (!cfg || !video) return;

  const startBtn = document.getElementById('startCam');
  const select = document.getElementById('cameraSelect');
  const msg = document.getElementById('cameraMsg');
  const result = document.getElementById('scanResult');
  const list = document.getElementById('presentList');
  const countEl = document.getElementById('presentCount');
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  let stream = null, busy = false, lastCode = '', lastTime = 0;

  function show(text, ok) {
    result.innerHTML = '<div class="alert ' + (ok ? 'alert-success' : 'alert-error') + '"></div>';
    result.firstChild.textContent = text;
    if (navigator.vibrate) navigator.vibrate(ok ? 80 : [60, 40, 60]);
  }

  function addPresent(s) {
    document.getElementById('emptyPresent')?.remove();
    const row = document.createElement('div');
    row.className = 'list-row compact';
    row.innerHTML = '<span class="person"><span class="avatar sm"></span><span><b></b><small class="muted"></small></span></span><small class="muted"></small>';
    row.querySelector('.avatar').textContent = s.initials;
    row.querySelector('b').textContent = s.name;
    row.querySelector('span small').textContent = s.student_number;
    row.lastChild.textContent = s.time;
    list.prepend(row);
    countEl.textContent = Number(countEl.textContent) + 1;
  }

  async function submit(code) {
    busy = true;
    try {
      const body = new URLSearchParams({ code, session: cfg.session, csrf: cfg.csrf });
      const res = await fetch(cfg.endpoint, { method: 'POST', body, credentials: 'same-origin' });
      const data = await res.json();
      show(data.message, data.ok);
      if (data.ok) addPresent(data.student);
    } catch (e) {
      show('Network error – please try again.', false);
    } finally {
      setTimeout(() => { busy = false; }, 900);
    }
  }

  function loop() {
    if (!stream) return;
    if (!busy && video.readyState >= 2) {
      const w = video.videoWidth, h = video.videoHeight;
      const scale = Math.min(1, 640 / w);           // downscale for speed on phones
      canvas.width = w * scale; canvas.height = h * scale;
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const code = window.jsQR && jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
      if (code && code.data) {
        const now = Date.now();
        if (code.data !== lastCode || now - lastTime > 4000) {   // ignore repeated reads of the same QR
          lastCode = code.data; lastTime = now;
          submit(code.data);
        }
      }
    }
    requestAnimationFrame(loop);
  }

  async function start(deviceId) {
    if (!navigator.mediaDevices?.getUserMedia) { msg.textContent = 'Camera not supported. Use HTTPS and a modern browser.'; return; }
    stream?.getTracks().forEach((t) => t.stop());
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }, audio: false,
      });
      video.srcObject = stream;
      await video.play();
      msg.textContent = 'Point the camera at a student’s QR code.';
      startBtn.querySelector('span').textContent = 'Camera on';
      startBtn.disabled = true;
      const cams = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
      if (cams.length > 1 && select.options.length === 0) {
        cams.forEach((c, i) => select.add(new Option(c.label || 'Camera ' + (i + 1), c.deviceId)));
        select.hidden = false;
      }
      requestAnimationFrame(loop);
    } catch (e) {
      msg.textContent = 'Camera permission denied. Allow camera access and reload.';
    }
  }

  startBtn.addEventListener('click', () => start());
  select.addEventListener('change', () => start(select.value));
  document.getElementById('manualForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const v = document.getElementById('manualCode').value.trim();
    if (v) submit(v);
  });
})();
