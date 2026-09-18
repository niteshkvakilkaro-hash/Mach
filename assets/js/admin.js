/* MACH CRM — admin panel behaviour */
(() => {
  'use strict';

  const ADMIN = window.ADMIN || {};
  const $ = (s, c = document) => c.querySelector(s);

  /** POST helper for admin AJAX endpoints; always sends the CSRF token. */
  async function post(path, data = {}) {
    const body = new FormData();
    body.append('_csrf', ADMIN.csrf);
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    const res = await fetch(`${ADMIN.baseUrl}/${path}`, {
      method: 'POST', body, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    });
    if (res.status === 401) { window.location.reload(); return { success: false }; }
    return res.json();
  }
  window.adminPost = post;

  /* ------------------------------------------------ toasts (survive one reload) */
  function toast(message, type = 'success') {
    let wrap = $('#toastWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'toastWrap';
      wrap.className = 'toast-wrap';
      wrap.setAttribute('aria-live', 'polite');
      document.body.appendChild(wrap);
    }
    const el = document.createElement('div');
    el.className = `toast-msg toast-${type}`;
    el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    el.innerHTML = `<i class="bi ${type === 'danger' ? 'bi-exclamation-circle' : 'bi-check2-circle'}"></i>`;
    el.appendChild(document.createTextNode(' ' + message));
    wrap.appendChild(el);
    setTimeout(() => el.classList.add('is-hiding'), 3500);
    setTimeout(() => el.remove(), 4000);
  }
  window.adminToast = toast;
  const reloadWithToast = (message) => {
    try { sessionStorage.setItem('admin_toast', message); } catch (e) { /* ignore */ }
    window.location.reload();
  };
  try {
    const pending = sessionStorage.getItem('admin_toast');
    if (pending) { sessionStorage.removeItem('admin_toast'); toast(pending); }
  } catch (e) { /* ignore */ }

  const formAlert = (form, message) => {
    const box = form && form.querySelector('[data-form-alert]');
    if (!box) { toast(message, 'danger'); return; }
    box.innerHTML = '';
    const div = document.createElement('div');
    div.className = 'alert alert-danger py-2 small mb-2';
    div.textContent = message;
    box.appendChild(div);
  };

  /* ------------------------------------------------ AJAX forms: <form data-ajax action="api/…"> */
  document.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('form[data-ajax]');
    if (!form) return;
    ev.preventDefault();
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    const box = form.querySelector('[data-form-alert]');
    if (box) box.innerHTML = '';
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const btn = form.querySelector('[type=submit]');
    if (btn) btn.disabled = true;
    try {
      const data = Object.fromEntries(new FormData(form).entries());
      const path = form.getAttribute('action').replace(`${ADMIN.baseUrl}/`, '');
      const json = await post(path, data);
      if (json.success) { reloadWithToast(json.message); return; }
      Object.keys(json.errors || {}).forEach((name) => {
        const field = form.elements[name];
        if (field && field.classList) field.classList.add('is-invalid');
      });
      formAlert(form, json.message || 'Something went wrong.');
    } catch (e) {
      formAlert(form, 'Network error. Please try again.');
    } finally {
      if (btn) btn.disabled = false;
    }
  });

  /* Status select: closing statuses need a reason */
  const statusSel = $('#stSel');
  if (statusSel) {
    const lost = (statusSel.dataset.lost || '').split(',');
    const note = $('#stNote');
    const hint = $('[data-reason-hint]');
    const sync = () => {
      const needs = lost.includes(statusSel.value);
      note.required = needs;
      hint.textContent = needs ? '(reason required)' : '(optional)';
    };
    statusSel.addEventListener('change', sync);
    sync();
  }

  /* ------------------------------------------------ lead context (view page) */
  const ctxEl = $('#leadContext');
  const ctx = ctxEl ? JSON.parse(ctxEl.textContent) : {};

  /* Deep links like #notes / #followups open the matching tab; #status etc. scroll + focus */
  if (location.hash && window.bootstrap) {
    const target = document.getElementById(location.hash.slice(1));
    if (target && target.matches('[data-bs-toggle="tab"]')) {
      bootstrap.Tab.getOrCreateInstance(target).show();
      target.scrollIntoView({ block: 'center' });
    } else if (target) {
      target.scrollIntoView({ block: 'start' });
      const first = target.querySelector('select, textarea, input:not([type=hidden])');
      if (first) first.focus({ preventScroll: true });
    }
  }

  /* ------------------------------------------------ follow-up done / cancel */
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-followup-close]');
    if (!btn) return;
    const status = btn.dataset.status;
    const outcome = status === 'completed'
      ? window.prompt('Outcome (optional) — e.g. "Customer confirmed visit for Monday"', '')
      : window.prompt('Reason for cancelling this follow-up (optional)', '');
    if (outcome === null) return;            // user pressed Cancel
    btn.disabled = true;
    const json = await post('api/leads/followup.php', {
      number: btn.dataset.number || ctx.number, action: 'close', id: btn.dataset.followupClose, status, outcome,
    });
    if (json.success) reloadWithToast(json.message);
    else { btn.disabled = false; toast(json.message || 'Could not update follow-up.', 'danger'); }
  });

  /* ------------------------------------------------ delete lead with confirmation */
  const delModalEl = $('#deleteLeadModal');
  if (delModalEl && window.bootstrap) {
    const modal = bootstrap.Modal.getOrCreateInstance(delModalEl);
    let current = null;
    document.addEventListener('click', (ev) => {
      const btn = ev.target.closest('[data-delete-lead]');
      if (!btn) return;
      current = btn;
      delModalEl.querySelector('[data-delete-number]').textContent = btn.dataset.deleteLead;
      delModalEl.querySelector('[data-form-alert]').innerHTML = '';
      modal.show();
    });
    delModalEl.querySelector('[data-delete-confirm]').addEventListener('click', async (ev) => {
      if (!current) return;
      ev.currentTarget.disabled = true;
      const json = await post('api/leads/delete.php', { number: current.dataset.deleteLead });
      ev.currentTarget.disabled = false;
      if (!json.success) { formAlert(delModalEl, json.message || 'Could not delete.'); return; }
      try { sessionStorage.setItem('admin_toast', json.message); } catch (e) { /* ignore */ }
      if (current.dataset.afterDelete) window.location.href = current.dataset.afterDelete;
      else window.location.reload();
    });
  }

  /* ------------------------------------------------ generic confirm + POST
     <button data-confirm-post="api/x.php" data-number="…" data-confirm-title data-confirm-text data-after="url"> */
  let confirmModal = null;
  function getConfirmModal() {
    if (confirmModal) return confirmModal;
    const el = document.createElement('div');
    el.className = 'modal fade';
    el.tabIndex = -1;
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content admin-modal">
      <div class="modal-header"><h2 class="modal-title h5"><i class="bi bi-exclamation-triangle text-danger me-2"></i><span data-title></span></h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><p class="mb-0" data-text></p><div class="form-alert mt-3" data-form-alert></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-danger" data-ok>Confirm</button></div></div></div>`;
    document.body.appendChild(el);
    confirmModal = { el, modal: bootstrap.Modal.getOrCreateInstance(el), btn: null };
    el.querySelector('[data-ok]').addEventListener('click', async (ev) => {
      const src = confirmModal.btn;
      if (!src) return;
      ev.currentTarget.disabled = true;
      const json = await post(src.dataset.confirmPost, { number: src.dataset.number || '' });
      ev.currentTarget.disabled = false;
      if (!json.success) { formAlert(el, json.message || 'Could not complete the action.'); return; }
      try { sessionStorage.setItem('admin_toast', json.message); } catch (e) { /* ignore */ }
      if (src.dataset.after) window.location.href = src.dataset.after; else window.location.reload();
    });
    return confirmModal;
  }
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-confirm-post]');
    if (!btn || !window.bootstrap) return;
    const m = getConfirmModal();
    m.btn = btn;
    m.el.querySelector('[data-title]').textContent = btn.dataset.confirmTitle || 'Are you sure?';
    m.el.querySelector('[data-text]').textContent = btn.dataset.confirmText || '';
    m.el.querySelector('[data-ok]').textContent = btn.dataset.confirmOk || 'Delete';
    m.el.querySelector('[data-form-alert]').innerHTML = '';
    m.modal.show();
  });

  /* ------------------------------------------------ booking form: price + technician load */
  const svcSel = $('select[data-price-target]');
  if (svcSel) {
    svcSel.addEventListener('change', () => {
      const target = $(svcSel.dataset.priceTarget);
      const opt = svcSel.options[svcSel.selectedIndex];
      if (target && opt && opt.dataset.price && !target.dataset.touched) target.value = opt.dataset.price;
    });
    const target = $(svcSel.dataset.priceTarget);
    if (target) target.addEventListener('input', () => { target.dataset.touched = '1'; });
  }
  const loadDate = $('[data-load-date]');
  if (loadDate) {
    loadDate.addEventListener('change', async () => {
      if (!loadDate.value) return;
      try {
        const res = await fetch(`${ADMIN.baseUrl}/api/bookings/availability.php?date=${encodeURIComponent(loadDate.value)}`, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (!json.success) return;
        json.data.technicians.forEach((t) => {
          const pill = document.querySelector(`[data-load-for="${t.id}"]`);
          if (!pill) return;
          pill.textContent = `${t.jobs} job${t.jobs === 1 ? '' : 's'}`;
          pill.title = t.slots.join(', ');
          pill.classList.toggle('is-busy', t.jobs >= 4);
        });
        const label = $('[data-load-label]');
        if (label) label.textContent = 'Jobs on ' + new Date(loadDate.value + 'T00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
      } catch (e) { /* keep previous numbers */ }
    });
  }

  /* ------------------------------------------------ technician app: pick a different stage */
  const otherStatus = $('[data-other-status]');
  if (otherStatus) {
    otherStatus.addEventListener('change', () => {
      if (!otherStatus.value) return;
      const form = otherStatus.closest('form');
      const label = otherStatus.options[otherStatus.selectedIndex].text;
      if (!window.confirm(`Change this job to “${label}”?`)) { otherStatus.value = ''; return; }
      let input = form.querySelector('[data-next-status]');
      if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'status';
        input.dataset.nextStatus = '';
        form.appendChild(input);
      }
      input.value = otherStatus.value;
      form.querySelectorAll('input[name$="_amount"]').forEach((el) => { el.required = false; });
      form.requestSubmit();
    });
  }

  /* ------------------------------------------------ payments: mark paid / failed / refund */
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-payment-status]');
    if (!btn) return;
    const to = btn.dataset.paymentStatus;
    const question = { paid: 'Mark this payment as received? (optional note)', failed: 'Why did this payment fail? (optional)', refunded: 'Reason for the refund (required):' }[to];
    const note = window.prompt(`${btn.dataset.number}\n\n${question}`, '');
    if (note === null) return;
    if (to === 'refunded' && note.trim().length < 3) { toast('Please give a reason for the refund.', 'danger'); return; }
    const json = await post('api/payments/status.php', { number: btn.dataset.number, status: to, note });
    if (json.success) reloadWithToast(json.message); else toast(json.message || 'Could not update the payment.', 'danger');
  });

  /* Technician app: UPI / card need a reference number */
  document.querySelectorAll('[data-pay-method]').forEach((radio) => {
    radio.addEventListener('change', () => {
      const ref = radio.closest('form').querySelector('[data-pay-ref]');
      if (!ref) return;
      const needs = radio.value !== 'cash';
      ref.classList.toggle('d-none', !needs);
      ref.required = needs;
      if (needs) ref.focus();
    });
  });

  /* ------------------------------------------------ plain forms that need a confirmation */
  document.addEventListener('submit', (ev) => {
    const form = ev.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) ev.preventDefault();
  }, true);

  /* ------------------------------------------------ service editor: price rows + Google preview */
  const addPrice = $('[data-add-price-row]');
  if (addPrice) {
    addPrice.addEventListener('click', () => {
      const rows = $('[data-price-rows]');
      const clone = rows.lastElementChild.cloneNode(true);
      clone.querySelectorAll('input').forEach((i) => { i.value = ''; });
      clone.querySelector('select').value = 'starting';
      rows.appendChild(clone);
      clone.querySelector('input').focus();
    });
  }
  const serpTitle = $('[data-serp-title]');
  if (serpTitle) {
    const t = $('#f_seo_title'), d = $('#f_seo_description'), n = $('#f_name'), s = $('#f_short_description');
    const sync = () => {
      serpTitle.textContent = (t.value || `${n.value || 'Service'}`).slice(0, 70);
      $('[data-serp-desc]').textContent = (d.value || s.value).slice(0, 170);
    };
    [t, d, n, s].forEach((el) => el && el.addEventListener('input', sync));
  }

  /* ------------------------------------------------ settings: SMTP provider presets */
  document.querySelectorAll('[data-smtp-preset]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const [host, port, enc] = btn.dataset.smtpPreset.split('|');
      const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
      set('f_smtp_host', host); set('f_smtp_port', port); set('f_smtp_encryption', enc);
      const user = document.getElementById('f_smtp_username');
      if (user) user.focus();
    });
  });

  /* ------------------------------------------------ "/" focuses search */
  document.addEventListener('keydown', (e) => {
    const search = $('.top-search input');
    if (e.key === '/' && search && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
      e.preventDefault();
      search.focus();
    }
  });

  /* ------------------------------------------------ notifications */
  const list = $('#notifList');
  const countEl = $('#notifCount');
  const ICONS = { 'lead.new': 'bi-person-plus', 'lead.repeat': 'bi-arrow-repeat', 'lead.assigned': 'bi-person-check', 'booking.created': 'bi-calendar-plus', 'payment.received': 'bi-currency-rupee', 'followup.overdue': 'bi-alarm' };

  const setCount = (n) => {
    if (!countEl) return;
    countEl.textContent = n > 99 ? '99+' : String(n);
    countEl.classList.toggle('d-none', !n);
    document.title = document.title.replace(/^\(\d+\+?\) /, '');
    if (n) document.title = `(${n > 99 ? '99+' : n}) ${document.title}`;
  };

  const render = (items) => {
    list.replaceChildren();
    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'notif-empty';
      empty.textContent = 'No notifications yet.';
      list.appendChild(empty);
      return;
    }
    items.forEach((n) => {
      const row = document.createElement(n.link ? 'a' : 'div');
      row.className = `notif-item${n.read ? '' : ' is-unread'}`;
      if (n.link) row.href = n.link;
      const icon = document.createElement('span');
      icon.className = 'ni-icon';
      icon.innerHTML = `<i class="bi ${ICONS[n.type] || 'bi-bell'}"></i>`;
      const body = document.createElement('div');
      const title = document.createElement('strong');
      title.textContent = n.title;
      const msg = document.createElement('small');
      msg.textContent = [n.message, n.ago].filter(Boolean).join(' · ');
      body.append(title, msg);
      row.append(icon, body);
      row.addEventListener('click', () => { if (!n.read) post('api/notifications/read.php', { id: n.id }); });
      list.appendChild(row);
    });
  };

  async function loadNotifications() {
    if (!list) return;
    try {
      const res = await fetch(`${ADMIN.baseUrl}/api/notifications/list.php`, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (res.status === 401) return;
      const json = await res.json();
      if (json.success) { setCount(json.data.unread); render(json.data.items); }
    } catch (e) { /* offline — try again on next poll */ }
  }

  if (list) {
    loadNotifications();
    setInterval(() => { if (!document.hidden) loadNotifications(); }, 60000);
    const readAll = $('#notifReadAll');
    if (readAll) {
      readAll.addEventListener('click', async () => {
        const json = await post('api/notifications/read.php', { all: 1 });
        if (json.success) loadNotifications();
      });
    }
  }

  /* ------------------------------------------------ dashboard charts (Chart.js) */
  const dataEl = $('#chartData');
  if (dataEl && window.Chart) {
    const data = JSON.parse(dataEl.textContent);
    const css = getComputedStyle(document.documentElement);
    const color = css.getPropertyValue('--viz-1').trim() || '#9085e9';
    const muted = css.getPropertyValue('--muted').trim() || '#a39ebf';
    const grid = 'rgba(255,255,255,0.06)';
    const inr = (v) => '₹' + Number(v).toLocaleString('en-IN');

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = muted;

    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
      const series = data[canvas.dataset.chart] || [];
      const horizontal = canvas.dataset.type === 'hbar';
      const money = canvas.dataset.money === '1';
      const fmt = (v) => (money ? inr(v) : Number(v).toLocaleString('en-IN'));

      const valueAxis = {
        beginAtZero: true,
        grid: { color: grid, drawTicks: false },
        border: { display: false },
        ticks: { precision: 0, padding: 8, callback: (v) => (money ? inr(v) : v), maxTicksLimit: 5 },
      };
      const labelAxis = {
        grid: { display: false },
        border: { color: grid },
        ticks: horizontal ? { padding: 6 } : { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
      };

      new Chart(canvas, {
        type: 'bar',
        data: {
          labels: series.map((r) => r.label),
          datasets: [{
            label: canvas.dataset.label || 'Value',
            data: series.map((r) => r.value),
            backgroundColor: color,
            hoverBackgroundColor: '#a79ff0',
            borderRadius: { topLeft: horizontal ? 0 : 4, topRight: 4, bottomRight: horizontal ? 4 : 0, bottomLeft: 0 },
            borderSkipped: 'start',
            maxBarThickness: horizontal ? 18 : 16,
            categoryPercentage: 0.8,
            barPercentage: 0.9,
          }],
        },
        options: {
          indexAxis: horizontal ? 'y' : 'x',
          maintainAspectRatio: false,
          animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 500 },
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: '#1a1538', borderColor: 'rgba(255,255,255,0.14)', borderWidth: 1,
              titleColor: '#ece9f8', bodyColor: '#c9c4e0', padding: 10, cornerRadius: 10, displayColors: false,
              callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed[horizontal ? 'x' : 'y'])}` },
            },
          },
          scales: horizontal ? { x: valueAxis, y: labelAxis } : { x: labelAxis, y: valueAxis },
        },
      });
    });
  }
})();
