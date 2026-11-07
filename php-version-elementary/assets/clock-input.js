/* Circular clock-face picker for elementary section/window time fields. */
(function () {
  'use strict';

  var activeInput = null;
  var activeTrigger = null;
  var stage = 'hour';
  var draft = { hour: 8, minute: 0, period: 'AM' };
  var dialog = document.createElement('dialog');
  dialog.className = 'clock-picker-dialog';
  dialog.setAttribute('aria-label', 'Choose a time');
  dialog.innerHTML = [
    '<div class="clock-picker-panel">',
      '<div class="clock-picker-heading">',
        '<div><small>Choose a time</small><div class="clock-picker-readout" aria-live="polite">',
          '<button type="button" data-clock-part="hour" aria-label="Choose hour"></button>',
          '<span>:</span>',
          '<button type="button" data-clock-part="minute" aria-label="Choose minute"></button>',
          '<span class="clock-picker-period-readout" data-clock-period-readout></span>',
        '</div></div>',
        '<button type="button" class="clock-picker-x" data-clock-cancel aria-label="Close">×</button>',
      '</div>',
      '<div class="clock-picker-stage"><button type="button" data-clock-stage="hour">Hours</button><button type="button" data-clock-stage="minute">Minutes</button></div>',
      '<div class="clock-dial" role="group" aria-label="Clock face"><div class="clock-dial-hand"></div><div class="clock-dial-center"></div><div class="clock-dial-numbers"></div></div>',
      '<div class="clock-picker-period"><button type="button" data-clock-period="AM">AM</button><button type="button" data-clock-period="PM">PM</button></div>',
      '<div class="clock-picker-actions"><button type="button" class="btn btn-secondary" data-clock-cancel>Cancel</button><button type="button" class="btn btn-primary" data-clock-set>Set time</button></div>',
    '</div>'
  ].join('');
  document.body.appendChild(dialog);

  var numbers = dialog.querySelector('.clock-dial-numbers');
  var hand = dialog.querySelector('.clock-dial-hand');
  var hourReadout = dialog.querySelector('[data-clock-part="hour"]');
  var minuteReadout = dialog.querySelector('[data-clock-part="minute"]');
  var periodReadout = dialog.querySelector('[data-clock-period-readout]');

  function pad(value) { return String(value).padStart(2, '0'); }
  function hour24() { return (draft.hour % 12) + (draft.period === 'PM' ? 12 : 0); }
  function displayValue() {
    if (!activeInput.value) return 'Choose time';
    var parts = activeInput.value.split(':');
    var h = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
    return (h % 12 || 12) + ':' + pad(m) + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  function render() {
    hourReadout.textContent = String(draft.hour);
    minuteReadout.textContent = pad(draft.minute);
    periodReadout.textContent = draft.period;
    dialog.querySelectorAll('[data-clock-stage]').forEach(function (button) {
      button.classList.toggle('is-active', button.dataset.clockStage === stage);
    });
    dialog.querySelectorAll('[data-clock-period]').forEach(function (button) {
      button.classList.toggle('is-active', button.dataset.clockPeriod === draft.period);
    });

    var choices = stage === 'hour' ? [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] : [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];
    numbers.innerHTML = '';
    var face = dialog.querySelector('.clock-dial');
    var size = face.clientWidth || 260;
    var center = size / 2;
    var radius = size * 0.37;
    choices.forEach(function (choice, index) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'clock-dial-number';
      button.dataset.clockValue = String(choice);
      button.textContent = stage === 'hour' ? String(choice) : pad(choice);
      button.setAttribute('aria-label', stage === 'hour' ? choice + ' o’clock' : pad(choice) + ' minutes');
      var angle = index * 30 * Math.PI / 180;
      button.style.left = (center + Math.sin(angle) * radius) + 'px';
      button.style.top = (center - Math.cos(angle) * radius) + 'px';
      var selected = stage === 'hour' ? draft.hour === choice : draft.minute === choice;
      if (selected) button.classList.add('is-selected');
      button.addEventListener('click', function () {
        if (stage === 'hour') {
          draft.hour = choice;
          stage = 'minute';
          render();
        } else {
          draft.minute = choice;
          render();
        }
      });
      numbers.appendChild(button);
    });

    var angleValue = stage === 'hour' ? (draft.hour % 12) * 30 : draft.minute * 6;
    hand.style.height = radius + 'px';
    hand.style.transform = 'translate(-50%, -100%) rotate(' + angleValue + 'deg)';
  }

  function openPicker(input, trigger) {
    activeInput = input;
    activeTrigger = trigger;
    var parts = (input.value || '08:00').split(':');
    var h = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    draft.hour = h % 12 || 12;
    draft.minute = Number.isFinite(m) ? m : 0;
    draft.period = h >= 12 ? 'PM' : 'AM';
    stage = 'hour';
    render();
    dialog.showModal();
    render();
  }

  function enhance(root) {
    root.querySelectorAll('input[type="time"]:not([data-clock-enhanced])').forEach(function (input) {
      input.dataset.clockEnhanced = '1';
      input.dataset.clockRequired = input.required ? '1' : '0';
      input.removeAttribute('required');
      var wrapper = document.createElement('span');
      wrapper.className = 'clock-time-control';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);
      input.type = 'hidden';

      var trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'clock-time-display input';
      trigger.setAttribute('aria-haspopup', 'dialog');
      trigger.innerHTML = '<span class="clock-time-label"></span><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>';
      trigger.querySelector('.clock-time-label').textContent = displayValueFor(input);
      trigger.addEventListener('click', function () { openPicker(input, trigger); });
      wrapper.appendChild(trigger);
      input._clockTrigger = trigger;

      var form = input.form;
      if (form && !form.dataset.clockValidate) {
        form.dataset.clockValidate = '1';
        form.addEventListener('submit', function (event) {
          var requiredInput = Array.from(form.querySelectorAll('input[data-clock-enhanced="1"]')).find(function (field) {
            return field.dataset.clockRequired === '1' && !field.value;
          });
          if (requiredInput) {
            event.preventDefault();
            requiredInput._clockTrigger.setAttribute('aria-invalid', 'true');
            requiredInput._clockTrigger.focus();
            openPicker(requiredInput, requiredInput._clockTrigger);
          }
        });
      }
    });
  }

  function displayValueFor(input) {
    if (!input.value) return 'Choose time';
    var parts = input.value.split(':');
    var h = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
    return (h % 12 || 12) + ':' + pad(m) + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  dialog.addEventListener('click', function (event) {
    var target = event.target.closest('button');
    if (!target) return;
    if (target.hasAttribute('data-clock-cancel')) { dialog.close(); return; }
    if (target.dataset.clockStage) { stage = target.dataset.clockStage; render(); return; }
    if (target.dataset.clockPart) { stage = target.dataset.clockPart; render(); return; }
    if (target.dataset.clockPeriod) { draft.period = target.dataset.clockPeriod; render(); return; }
    if (target.hasAttribute('data-clock-set') && activeInput) {
      activeInput.value = pad(hour24()) + ':' + pad(draft.minute);
      activeInput.dispatchEvent(new Event('input', { bubbles: true }));
      activeInput.dispatchEvent(new Event('change', { bubbles: true }));
      activeTrigger.querySelector('.clock-time-label').textContent = displayValue();
      activeTrigger.removeAttribute('aria-invalid');
      dialog.close();
    }
  });
  dialog.addEventListener('close', function () { if (activeTrigger) activeTrigger.focus(); });

  enhance(document);
  new MutationObserver(function (records) {
    records.forEach(function (record) {
      record.addedNodes.forEach(function (node) {
        if (node.nodeType === 1) enhance(node);
      });
    });
  }).observe(document.body, { childList: true, subtree: true });
})();
