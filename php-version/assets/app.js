// QRAttendance – shared UI behaviour (vanilla JS)
(function () {
  // Render Lucide icons (same set & version as lucide-react in the Next.js app; same 2px default stroke)
  window.renderIcons = function () {
    if (window.lucide) window.lucide.createIcons({ attrs: { 'stroke-width': 2 } });
  };
  window.renderIcons();

  // Mobile sidebar
  const menuBtn = document.getElementById('menuBtn');
  const overlay = document.getElementById('overlay');
  menuBtn && menuBtn.addEventListener('click', () => document.body.classList.add('nav-open'));
  overlay && overlay.addEventListener('click', () => document.body.classList.remove('nav-open'));

  // Show / hide password
  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.togglePassword);
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.querySelector('.pw-show').hidden = show;
      btn.querySelector('.pw-hide').hidden = !show;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // Dialogs: <button data-open="id"> / <button data-close>
  document.addEventListener('click', (ev) => {
    const opener = ev.target.closest('[data-open]');
    if (opener) {
      const dlg = document.getElementById(opener.dataset.open);
      if (!dlg) return;
      if (opener.dataset.subject !== undefined) {           // add-section modal
        dlg.querySelector('#sectionSubjectId').value = opener.dataset.subject;
        dlg.querySelector('#sectionSubjectName').textContent = '· ' + (opener.dataset.subjectName || '');
      }
      if (opener.dataset.student !== undefined) {           // add / edit student modal
        const editing = opener.dataset.student !== '';
        dlg.querySelector('#studentModalTitle').textContent = editing ? 'Edit student' : 'Add student';
        dlg.querySelector('#fStudentId').value = opener.dataset.student;
        dlg.querySelector('#fNum').value = opener.dataset.num || '';
        dlg.querySelector('#fName').value = opener.dataset.name || '';
      }
      dlg.showModal();
      return;
    }
    if (ev.target.closest('[data-close]')) ev.target.closest('dialog').close();
    if (ev.target.tagName === 'DIALOG') ev.target.close();   // click on backdrop
  });

  // Top bar dropdowns (notifications + user menu) and ⌘K search, like the Next.js app
  document.addEventListener('click', (ev) => {
    const trigger = ev.target.closest('[data-dropdown]');
    document.querySelectorAll('[data-dropdown-panel].open').forEach((panel) => {
      if (!trigger || panel.dataset.dropdownPanel !== trigger.dataset.dropdown) panel.classList.remove('open');
    });
    if (trigger) {
      const panel = document.querySelector('[data-dropdown-panel="' + trigger.dataset.dropdown + '"]');
      panel && panel.classList.toggle('open');
      ev.stopPropagation();
    }
  });
  document.addEventListener('keydown', (ev) => {
    if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 'k') {
      const input = document.querySelector('.global-search input');
      if (input) { ev.preventDefault(); input.focus(); input.select(); }
    }
    if (ev.key === 'Escape') document.querySelectorAll('[data-dropdown-panel].open').forEach((p) => p.classList.remove('open'));
  });

  // Meeting schedules: add / remove day rows (sections can meet several days).
  // The dialog always opens with one day/time row; the button only adds more days.
  function refreshScheduleState(scope) {
    const box = scope.querySelector('[data-schedule-rows]');
    if (!box) return;
    const label = scope.querySelector('[data-schedule-label]');
    if (label) label.textContent = 'Add another day';
  }
  document.querySelectorAll('form').forEach(refreshScheduleState);

  document.addEventListener('click', (ev) => {
    const add = ev.target.closest('[data-schedule-add]');
    if (add) {
      const scope = add.closest('form') || document;
      const tpl = scope.querySelector('template[data-schedule-template]') || document.querySelector('template[data-schedule-template]');
      const box = scope.querySelector('[data-schedule-rows]');
      if (tpl && box) {
        box.appendChild(tpl.content.cloneNode(true));
        window.renderIcons();
        refreshScheduleState(scope);
      }
      return;
    }
    const remove = ev.target.closest('[data-schedule-remove]');
    if (remove) {
      const row = remove.closest('.schedule-row');
      const scope = remove.closest('form') || document;
      if (row) row.remove();
      refreshScheduleState(scope);
    }
  });

  // Profile picture preview before upload
  const avatarInput = document.getElementById('avatarInput');
  avatarInput && avatarInput.addEventListener('change', () => {
    const file = avatarInput.files && avatarInput.files[0];
    const preview = document.getElementById('avatarPreview');
    if (!file || !preview) return;
    const img = preview.tagName === 'IMG' ? preview : document.createElement('img');
    img.className = 'avatar xl';
    img.id = 'avatarPreview';
    img.src = URL.createObjectURL(file);
    if (preview.tagName !== 'IMG') preview.replaceWith(img);
  });

  // Direct-section recording choice: show only the enabled time-in/out slots.
  function syncRecordingMode(form) {
    if (!form) return;
    const selected = form.querySelector('[data-recording-mode]:checked');
    const mode = selected ? selected.value : 'both';
    form.querySelectorAll('[data-direct-slot]').forEach((slot) => {
      const key = slot.dataset.directSlot || '';
      const enabled = mode === 'both' || (mode === 'time_in' && key.endsWith('_in')) || (mode === 'time_out' && key.endsWith('_out'));
      slot.hidden = !enabled;
      slot.querySelectorAll('input,select').forEach((field) => { field.disabled = !enabled; });
    });
    form.querySelectorAll('[data-recording-mode]').forEach((input) => {
      const label = input.closest('.recording-mode-option');
      label && label.classList.toggle('selected', input.checked);
    });
  }
  document.querySelectorAll('form').forEach((form) => {
    if (form.querySelector('[data-recording-mode]')) syncRecordingMode(form);
  });
  document.addEventListener('change', (ev) => {
    if (ev.target.matches('[data-recording-mode]')) syncRecordingMode(ev.target.closest('form'));
  });

  // Student QR countdown → auto refresh when expired
  const cd = document.querySelector('.countdown[data-expires]');
  if (cd) {
    const expires = Number(cd.dataset.expires);
    const out = document.getElementById('cd');
    const tick = () => {
      const left = Math.max(0, Math.ceil((expires - Date.now()) / 1000));
      out.textContent = left;
      cd.classList.toggle('warn', left <= 10);
      if (left <= 0) { window.location.href = cd.dataset.statusUrl; return; }
      setTimeout(tick, 250);
    };
    tick();
  }
})();

// Workspace settings: reflect the selected primary attendance flow immediately.
document.querySelectorAll('.workflow-settings-form input[name="primary_attendance_mode"]').forEach((input) => {
  input.addEventListener('change', () => {
    document.querySelectorAll('.workflow-settings-form .attendance-mode-option').forEach((card) => {
      const radio = card.querySelector('input[name="primary_attendance_mode"]');
      card.classList.toggle('active', !!radio && radio.checked);
    });
  });
});
