/* MACH Home Services — public site behaviour (no dependencies besides Bootstrap). */
(() => {
  'use strict';

  const APP = window.APP || {};
  const DATA = APP.booking || { categories: [], services: [], cities: [] };
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

  const storage = (type) => ({
    get(k) { try { return window[type].getItem(k); } catch (e) { return null; } },
    set(k, v) { try { window[type].setItem(k, v); } catch (e) { /* storage blocked */ } },
  });
  const local = storage('localStorage');
  const session = storage('sessionStorage');

  /* ------------------------------------------------ sticky header */
  const header = $('#siteHeader');
  const onScroll = () => header && header.classList.toggle('is-scrolled', window.scrollY > 10);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  /* ------------------------------------------------ attribution (UTM / click IDs / referrer) */
  const TRACK_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ref'];
  const attribution = (() => {
    const params = new URLSearchParams(location.search);
    const incoming = {};
    TRACK_KEYS.forEach((k) => { const v = params.get(k); if (v) incoming[k] = v.slice(0, 255); });

    let externalRef = '';
    try {
      if (document.referrer && new URL(document.referrer).host !== location.host) externalRef = document.referrer;
    } catch (e) { /* invalid referrer */ }

    let saved = null;
    try { saved = JSON.parse(local.get('mach_attr') || 'null'); } catch (e) { saved = null; }

    // New campaign click or first visit → start a new attribution record.
    if (!saved || Object.keys(incoming).length || externalRef) {
      saved = Object.assign({}, incoming, {
        referrer: externalRef,
        landing_page: location.href.split('#')[0].slice(0, 500),
      });
      local.set('mach_attr', JSON.stringify(saved));
    }
    return saved;
  })();

  $$('[data-track]').forEach((input) => {
    const v = attribution[input.dataset.track];
    if (v) input.value = v;
  });

  /* ------------------------------------------------ booking form dependent dropdowns */
  const opt = (label, value = label, selected = false) => new Option(label, value, false, selected);

  function initBookingForm(form) {
    const cat = $('[data-field=category]', form);
    const svc = $('[data-field=service]', form);
    const app = $('[data-field=appliance]', form);
    const prob = $('[data-field=problem]', form);
    const city = $('[data-field=city]', form);
    const area = $('[data-field=area]', form);
    const pin = $('[data-field=pincode]', form);

    const fillServices = () => {
      const current = svc.value;
      svc.replaceChildren(opt('Select service', ''));
      DATA.services
        .filter((s) => !cat.value || String(s.category_id) === cat.value)
        .forEach((s) => svc.add(opt(s.name, s.id, String(s.id) === current)));
    };
    const fillAppliances = () => {
      const c = DATA.categories.find((x) => String(x.id) === cat.value);
      const current = app.value;
      app.replaceChildren(opt('Select type', ''));
      (c ? c.appliances : []).forEach((a) => app.add(opt(a, a, a === current)));
      app.add(opt('Other / Not sure'));
    };
    const fillProblems = () => {
      const s = DATA.services.find((x) => String(x.id) === svc.value);
      prob.replaceChildren(opt('Select problem', ''));
      (s ? s.problems : []).forEach((p) => prob.add(opt(p)));
      prob.add(opt('Other / Not sure'));
    };
    const fillAreas = () => {
      const selected = city.options[city.selectedIndex];
      const c = DATA.cities.find((x) => selected && String(x.id) === selected.dataset.id);
      area.replaceChildren(opt('Select area', ''));
      (c ? c.areas : []).forEach((a) => {
        const o = opt(a.name, a.id);
        o.dataset.pincode = a.pincode || '';
        area.add(o);
      });
    };

    if (cat && svc) {
      cat.addEventListener('change', () => { fillServices(); fillAppliances(); fillProblems(); });
      svc.addEventListener('change', () => {
        const s = DATA.services.find((x) => String(x.id) === svc.value);
        if (s && cat.value !== String(s.category_id)) {
          cat.value = String(s.category_id);
          fillAppliances();
        }
        fillProblems();
      });
    }
    if (city && area) city.addEventListener('change', fillAreas);
    if (area && pin) {
      area.addEventListener('change', () => {
        const o = area.options[area.selectedIndex];
        if (o && o.dataset.pincode && !pin.value) pin.value = o.dataset.pincode;
      });
    }
  }
  $$('[data-booking-form]').forEach(initBookingForm);

  /* ------------------------------------------------ AJAX lead submission */
  const showAlert = (form, type, message) => {
    const box = $('[data-form-alert]', form);
    if (!box) return;
    box.replaceChildren();
    const div = document.createElement('div');
    div.className = `alert alert-${type}`;
    div.setAttribute('role', 'alert');
    div.textContent = message;
    box.appendChild(div);
  };

  const clearErrors = (form) => {
    $$('.is-invalid', form).forEach((el) => el.classList.remove('is-invalid'));
    const box = $('[data-form-alert]', form);
    if (box) box.replaceChildren();
  };

  const showFieldErrors = (form, errors) => {
    let first = null;
    Object.entries(errors).forEach(([name, message]) => {
      const field = form.elements[name];
      if (!field || !field.classList) return;
      field.classList.add('is-invalid');
      const fb = $(`[data-error-for="${name}"]`, form);
      if (fb) fb.textContent = message;
      first = first || field;
    });
    if (first) first.focus();
  };

  const setLoading = (btn, loading) => {
    if (!btn) return;
    if (loading) {
      btn.dataset.label = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border" aria-hidden="true"></span> Sending…';
    } else if (btn.dataset.label) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.label;
    }
  };

  $$('form[data-lead-form]').forEach((form) => {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      clearErrors(form);

      if (!form.checkValidity()) {
        form.classList.add('was-validated');
        const invalid = $(':invalid', form);
        if (invalid) invalid.focus();
        return;
      }

      const btn = $('[type=submit]', form);
      setLoading(btn, true);
      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        const json = await res.json().catch(() => ({ success: false, message: 'Unexpected response. Please call us.' }));

        if (json.success) {
          session.set('mach_cb_shown', '1');
          if (json.data && json.data.redirect) {
            window.location.href = json.data.redirect;
            return;
          }
          showAlert(form, 'success', json.message);
          form.reset();
        } else {
          form.classList.remove('was-validated');
          if (json.errors) showFieldErrors(form, json.errors);
          showAlert(form, 'danger', json.message || 'Something went wrong. Please try again.');
        }
      } catch (e) {
        showAlert(form, 'danger', 'Network error. Please check your connection or call us directly.');
      } finally {
        setLoading(btn, false);
      }
    });
  });

  /* ------------------------------------------------ hero search */
  const heroSearch = $('[data-hero-search]');
  if (heroSearch) {
    heroSearch.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const q = $('input', heroSearch).value.trim().toLowerCase();
      const words = q.split(/\s+/).filter((w) => w.length > 2);
      const match = DATA.services.find((s) => s.name.toLowerCase() === q)
        || DATA.services.find((s) => q && s.name.toLowerCase().includes(q))
        || DATA.services.find((s) => words.some((w) => s.name.toLowerCase().includes(w)));
      window.location.href = match
        ? `${APP.baseUrl}/services/${match.slug}`
        : `${APP.baseUrl}/book-service${q ? `?q=${encodeURIComponent(q)}` : ''}`;
    });
  }

  /* ------------------------------------------------ exit-intent callback popup */
  const modalEl = $('#callbackModal');
  if (modalEl && !document.body.hasAttribute('data-no-exit')) {
    let armed = false;
    setTimeout(() => { armed = true; }, 8000);

    const open = () => {
      if (!armed || !window.bootstrap || session.get('mach_cb_shown')) return;
      if ($('.modal.show') || $('.offcanvas.show')) return;
      const active = document.activeElement;
      if (active && active.closest && active.closest('form')) return; // user is typing
      session.set('mach_cb_shown', '1');
      window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    document.addEventListener('mouseout', (e) => {
      if (!e.relatedTarget && e.clientY <= 8) open();
    });
    if (window.matchMedia('(pointer: coarse)').matches) {
      setTimeout(open, 45000);
    }
  }

  /* ------------------------------------------------ reveal on scroll + counters */
  const animateCount = (el) => {
    const target = parseInt(el.dataset.count, 10) || 0;
    const start = performance.now();
    const duration = 1400;
    const tick = (now) => {
      const p = Math.min((now - start) / duration, 1);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString('en-IN');
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        if (entry.target.dataset.count) animateCount(entry.target);
        io.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    $$('.reveal, [data-count]').forEach((el) => io.observe(el));
  } else {
    $$('.reveal').forEach((el) => el.classList.add('is-visible'));
    $$('[data-count]').forEach((el) => { el.textContent = Number(el.dataset.count).toLocaleString('en-IN'); });
  }
})();

/* Feedback page: star hint + show "went well" or "went wrong" tags depending on the rating */
(() => {
  const form = document.querySelector('[data-feedback-form]');
  if (!form) return;
  document.documentElement.classList.add('js-feedback');
  const hint = form.querySelector('[data-star-hint]');
  const good = form.querySelector('[data-tags="good"]');
  const bad = form.querySelector('[data-tags="bad"]');
  const words = { 1: 'Very bad 😞', 2: 'Bad', 3: 'Okay', 4: 'Good', 5: 'Excellent! 🎉' };
  good.hidden = true; bad.hidden = true;
  form.querySelectorAll('input[name=rating]').forEach((r) => r.addEventListener('change', () => {
    const v = Number(r.value);
    hint.textContent = words[v];
    good.hidden = v < 4; bad.hidden = v >= 4;
    (v >= 4 ? bad : good).querySelectorAll('input').forEach((i) => { i.checked = false; });
  }));
})();
