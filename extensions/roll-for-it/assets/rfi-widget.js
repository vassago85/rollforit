/* Roll for It storefront widget. The server decides every outcome; this file only animates it. */
(() => {
  if (window.__rfiLoaded) return;
  window.__rfiLoaded = true;

  const DRAWER_PRESETS = [
    'cart-drawer .drawer__footer',
    '#CartDrawer .drawer__footer',
    'cart-drawer-component .cart-drawer__summary',
    'cart-drawer-component .cart__summary-totals',
    '.cart-drawer .cart-drawer__footer',
    '#cart-drawer .cart__footer',
  ];
  const STORE = window.sessionStorage;
  const LOCAL = window.localStorage;
  const RESULT_KEY = 'rfi_result';
  const STREAK_KEY = 'rfi_streak';
  const reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Streak lives in localStorage so it survives sessions, but holds no PII. */
  function readStreak() {
    try {
      const raw = JSON.parse(LOCAL.getItem(STREAK_KEY) || 'null');
      return raw && typeof raw.count === 'number' ? raw : { count: 0, last_promo_date: null };
    } catch (e) { return { count: 0, last_promo_date: null }; }
  }
  function writeStreak(s) { try { LOCAL.setItem(STREAK_KEY, JSON.stringify(s)); } catch (e) {} }
  function updateStreak(result, status) {
    if (result.test || result.repeat || !status.promo_date) return readStreak();
    const current = readStreak();
    const next = current.last_promo_date === status.promo_date
      ? current // already counted for today, don't double-count on repeat renders
      : current.last_promo_date === status.previous_promo_date
        ? { count: current.count + 1, last_promo_date: status.promo_date }
        : { count: 1, last_promo_date: status.promo_date };
    writeStreak(next);
    return next;
  }
  function streakBadge(count) {
    if (count < 2) return '';
    const flame = count >= 30 ? ' 🔥🔥🔥' : count >= 14 ? ' 🔥🔥' : count >= 7 ? ' 🔥' : '';
    return 'Day ' + count + ' streak!' + flame;
  }

  const params = new URLSearchParams(location.search);
  if (params.get('rfi_preview')) STORE.setItem('rfi_preview', params.get('rfi_preview'));
  const preview = () => STORE.getItem('rfi_preview') || '';

  const embedNode = document.getElementById('rfi-embed-config');
  let embed = null;
  try { embed = embedNode ? JSON.parse(embedNode.textContent) : null; } catch (e) { embed = null; }

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const json = (r) => r.json().catch(() => ({}));

  const VISIBLE_WHEN_CLOSED = ['sold_out', 'upcoming'];
  const visible = (status) => !!status && (status.open || VISIBLE_WHEN_CLOSED.includes(status.reason));

  let statusPromise = null;
  function getStatus(proxy, fresh) {
    if (fresh) statusPromise = null;
    if (!statusPromise) {
      const q = preview() ? '?preview=' + encodeURIComponent(preview()) : '';
      statusPromise = fetch(proxy + '/status' + q, { cache: 'no-store', headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : { open: false }))
        .catch(() => ({ open: false }));
    }
    return statusPromise;
  }

  const getCart = () => fetch('/cart.js', { headers: { Accept: 'application/json' } }).then(json);
  const qualifying = (cart) => (cart.items || []).filter((i) => !i.gift_card).reduce((s, i) => s + (i.original_line_price || 0), 0);
  const pad = (n) => String(n).padStart(2, '0');
  function clock(seconds) {
    const d = Math.floor(seconds / 86400);
    const parts = [[pad(Math.floor(seconds / 3600) % 24), 'hrs'], [pad(Math.floor(seconds / 60) % 60), 'min'], [pad(seconds % 60), 'sec']];
    if (d) parts.unshift([d, d === 1 ? 'day' : 'days']);
    return parts.map(([v, unit]) => '<span class="rfi-t"><b>' + v + '</b><small>' + unit + '</small></span>').join('');
  }
  const money = (cents) => 'R' + (cents / 100).toLocaleString('en-ZA', { minimumFractionDigits: cents % 100 ? 2 : 0 }).replace(/\u00a0|\s/g, ',');

  function savedResult() {
    try {
      const saved = JSON.parse(STORE.getItem(RESULT_KEY) || 'null');
      return saved && Date.now() - saved.at < 12 * 3600 * 1000 ? saved.result : null;
    } catch (e) { return null; }
  }

  function wants3D() {
    if (reduceMotion) return false;
    if ((navigator.hardwareConcurrency || 4) <= 2) return false;
    if (navigator.deviceMemory && navigator.deviceMemory <= 2) return false;
    try {
      const c = document.createElement('canvas');
      return !!(c.getContext('webgl2') || c.getContext('webgl'));
    } catch (e) { return false; }
  }

  let die3dPromise = null;
  function load3D(url) {
    if (!die3dPromise) {
      die3dPromise = new Promise((resolve, reject) => {
        if (window.RFIDie3D) return resolve(window.RFIDie3D);
        const s = document.createElement('script');
        s.src = url;
        s.async = true;
        s.onload = () => (window.RFIDie3D ? resolve(window.RFIDie3D) : reject(new Error('3D die missing')));
        s.onerror = reject;
        document.head.appendChild(s);
      });
    }
    return die3dPromise;
  }

  /* CSS fallback die: a kite-shaped face that flicks through numbers and lands on the target. */
  function cssDie(stage, anim) {
    stage.innerHTML = '<div class="rfi-cssdie" style="--rfi-die:' + esc(anim.die_color) + ';--rfi-num:' + esc(anim.number_color) + '"><span>?</span></div>';
    const face = stage.firstChild;
    const label = face.firstChild;
    let timer = null;
    return {
      start() {
        face.classList.add('rfi-cssdie--rolling');
        if (reduceMotion) return;
        timer = setInterval(() => { label.textContent = 1 + Math.floor(Math.random() * 10); }, 70);
      },
      async settle(n) {
        clearInterval(timer);
        if (!reduceMotion) {
          for (const delay of [90, 130, 180]) { label.textContent = 1 + Math.floor(Math.random() * 10); await sleep(delay); }
        }
        label.textContent = n;
        face.classList.remove('rfi-cssdie--rolling');
        face.classList.add('rfi-cssdie--landed');
      },
      show(n) { label.textContent = n; face.classList.add('rfi-cssdie--landed'); },
      stop() { clearInterval(timer); face.classList.remove('rfi-cssdie--rolling'); },
      dispose() { clearInterval(timer); },
    };
  }

  /* Synthesised sound: dice rattle while tumbling, a chime on a win. */
  function makeSound(enabled) {
    if (!enabled) return { rattle() {}, stop() {}, win() {}, lose() {} };
    let ctx = null;
    let timer = null;
    const audio = () => (ctx = ctx || new (window.AudioContext || window.webkitAudioContext)());
    const tone = (freq, at, len, type, gain) => {
      const c = audio();
      const o = c.createOscillator();
      const g = c.createGain();
      o.type = type;
      o.frequency.value = freq;
      g.gain.setValueAtTime(gain, c.currentTime + at);
      g.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + at + len);
      o.connect(g).connect(c.destination);
      o.start(c.currentTime + at);
      o.stop(c.currentTime + at + len + 0.02);
    };
    return {
      rattle() {
        try { audio(); } catch (e) { return; }
        const click = () => { tone(500 + Math.random() * 900, 0, 0.035, 'square', 0.05); timer = setTimeout(click, 60 + Math.random() * 120); };
        click();
      },
      stop() { clearTimeout(timer); },
      win() { [523, 659, 784, 1047].forEach((f, i) => tone(f, i * 0.11, 0.35, 'sine', 0.18)); },
      lose() { tone(196, 0, 0.3, 'triangle', 0.12); },
    };
  }

  function confetti(colors) {
    if (reduceMotion) return;
    const canvas = document.createElement('canvas');
    canvas.className = 'rfi-confetti';
    canvas.width = innerWidth;
    canvas.height = innerHeight;
    document.body.appendChild(canvas);
    const g = canvas.getContext('2d');
    const bits = Array.from({ length: 140 }, () => ({
      x: innerWidth / 2 + (Math.random() - 0.5) * 200, y: innerHeight / 3,
      vx: (Math.random() - 0.5) * 14, vy: -Math.random() * 14 - 4,
      r: Math.random() * Math.PI, vr: (Math.random() - 0.5) * 0.4,
      c: colors[Math.floor(Math.random() * colors.length)], w: 6 + Math.random() * 6,
    }));
    const end = performance.now() + 2800;
    (function frame(now) {
      g.clearRect(0, 0, canvas.width, canvas.height);
      bits.forEach((b) => {
        b.vy += 0.35; b.x += b.vx; b.y += b.vy; b.r += b.vr; b.vx *= 0.99;
        g.save(); g.translate(b.x, b.y); g.rotate(b.r); g.fillStyle = b.c; g.fillRect(-b.w / 2, -b.w / 4, b.w, b.w / 2); g.restore();
      });
      if (now < end) requestAnimationFrame(frame); else canvas.remove();
    })(performance.now());
  }

  async function applyCode(code) {
    const res = await fetch('/cart/update.js', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ discount: code }),
    });
    const cart = await json(res);
    const entry = (cart.discount_codes || []).find((d) => String(d.code).toUpperCase() === code.toUpperCase());
    return { applied: !!(entry && entry.applicable), cart };
  }

  async function refreshCartUi(cart, placement) {
    if (placement === 'cart') { setTimeout(() => location.reload(), 2500); return; }
    const drawer = document.querySelector('cart-drawer');
    if (drawer && typeof drawer.renderContents === 'function') {
      try {
        const sections = await fetch('/?sections=cart-drawer,cart-icon-bubble').then(json);
        drawer.renderContents({ id: null, sections });
        return;
      } catch (e) { /* fall through to events */ }
    }
    document.dispatchEvent(new CustomEvent('cart:update', { bubbles: true, detail: { resource: cart, sourceId: 'roll-for-it', data: { source: 'roll-for-it' } } }));
    document.dispatchEvent(new CustomEvent('cart:refresh', { bubbles: true }));
  }

  class Widget {
    constructor(host, cfg) {
      this.host = host;
      this.cfg = cfg;
      this.picked = null;
      this.cart = null;
      this.busy = false;
      host.__rfi = this;
    }

    async init(fresh) {
      this.stopCountdown();
      const status = (this.status = await getStatus(this.cfg.proxy, fresh));
      const hide = !visible(status) || (this.cfg.currency && status.currency && this.cfg.currency !== status.currency);
      if (hide) { this.host.hidden = true; return; }
      this.host.hidden = false;
      this.renderShell();
      if (!status.open) this.startCountdown();
      const saved = status.test ? null : savedResult();
      if (saved) this.showResult(saved, false);
      else if (status.reason === 'upcoming') this.renderUpcoming();
      else if (status.reason === 'sold_out') this.body.innerHTML = '<p class="rfi-msg">' + esc(status.sold_out_message) + '</p>';
      else this.renderForm();
      this.refreshCart();
      if ('IntersectionObserver' in window && wants3D()) {
        const io = new IntersectionObserver((entries) => {
          if (entries.some((e) => e.isIntersecting)) { io.disconnect(); load3D(this.cfg.die3d).catch(() => {}); }
        });
        io.observe(this.host);
      }
    }

    renderShell() {
      const s = this.status;
      const a = s.animation;
      const banner = esc(s.banner).replace('T&amp;Cs apply.', '<a href="' + esc(s.terms_url) + '" target="_blank" rel="noopener">T&amp;Cs apply.</a>');
      const streakText = streakBadge(readStreak().count);
      const streakHtml = streakText ? '<span class="rfi-streak">' + esc(streakText) + '</span>' : '';
      const testBadge = s.test ? ' <span class="rfi-test">Test mode</span>' : '';
      const socialHtml = s.today_stats && s.today_stats.message
        ? '<p class="rfi-social"><span class="rfi-social-dot" aria-hidden="true"></span>' + esc(s.today_stats.message) + '</p>'
        : '';
      this.host.innerHTML = '<div class="rfi-card" style="--rfi-accent:' + esc(a.accent_color) + '">'
        + '<p class="rfi-banner">' + banner + '</p>'
        + socialHtml
        + '<h3 class="rfi-title">' + esc(s.headline) + testBadge + streakHtml + '</h3>'
        + '<div class="rfi-body"></div>'
        + '<div class="rfi-next" hidden></div>'
        + '<p class="rfi-fine">' + esc(s.odds) + ' ' + esc(s.one_roll) + '</p>'
        + '<div class="rfi-live" aria-live="polite"></div></div>';
      this.body = this.host.querySelector('.rfi-body');
      this.live = this.host.querySelector('.rfi-live');
    }

    renderUpcoming() {
      const a = this.status.animation;
      this.body.innerHTML = '<div class="rfi-stage rfi-stage--teaser" aria-hidden="true">'
        + '<div class="rfi-cssdie rfi-cssdie--idle" style="--rfi-die:' + esc(a.die_color) + ';--rfi-num:' + esc(a.number_color) + '"><span>?</span></div></div>'
        + '<p class="rfi-msg">The die is resting. Rolling opens ' + esc(this.status.next_roll.label) + '.</p>';
    }

    /* Counts down from the server's starts_in, so a wrong device clock doesn't matter. At zero, re-check with the server. */
    startCountdown() {
      const next = this.status.next_roll;
      const box = this.host.querySelector('.rfi-next');
      if (!next || !box) return;
      const deadline = performance.now() + Math.max(2, next.starts_in) * 1000;
      const streak = readStreak().count;
      const streakLine = streak >= 2
        ? '<p class="rfi-next-streak">You\'re on a ' + streak + '-day streak. Don\'t break it.</p>'
        : '';
      box.hidden = false;
      box.innerHTML = '<p class="rfi-next-label">Next roll: ' + esc(next.label) + '</p>'
        + '<p class="rfi-timer" role="timer" aria-label="Time until the next roll"></p>'
        + streakLine
        + '<button type="button" class="rfi-ics">Add to calendar</button>';
      const timer = box.querySelector('.rfi-timer');
      const ics = box.querySelector('.rfi-ics');
      if (ics) ics.addEventListener('click', () => this.downloadIcs(next));
      const tick = () => {
        const left = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
        timer.innerHTML = clock(left);
        if (left === 0 && !this.busy) {
          this.stopCountdown();
          STORE.removeItem(RESULT_KEY);
          this.init(true);
        }
      };
      tick();
      this.countdown = setInterval(tick, 1000);
    }

    /* ICS file is built client-side so there's no backend to call. */
    downloadIcs(next) {
      const start = new Date(next.starts_at);
      if (isNaN(start.getTime())) return;
      const end = new Date(start.getTime() + 30 * 60 * 1000); // 30-minute window
      const fmt = (d) => d.toISOString().replace(/[-:]|\.\d{3}/g, '');
      const uid = 'rfi-' + fmt(start) + '-' + Math.random().toString(36).slice(2, 8) + '@roll4it';
      const name = (this.status && this.status.headline) || 'Roll4It';
      const ics = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Roll4It//EN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' + uid,
        'DTSTAMP:' + fmt(new Date()),
        'DTSTART:' + fmt(start),
        'DTEND:' + fmt(end),
        'SUMMARY:' + name + ' opens',
        'DESCRIPTION:Your next chance to roll for a discount.',
        'END:VEVENT', 'END:VCALENDAR',
      ].join('\r\n');
      const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'roll4it-next-roll.ics';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    stopCountdown() {
      clearInterval(this.countdown);
      this.countdown = null;
    }

    renderForm(error) {
      const nums = Array.from({ length: 10 }, (_, i) => i + 1)
        .map((n) => '<button type="button" class="rfi-num" data-n="' + n + '" aria-pressed="' + (this.picked === n) + '">' + n + '</button>').join('');
      this.body.innerHTML = '<p class="rfi-label">Pick your number</p><div class="rfi-grid" role="group" aria-label="Pick a number from 1 to 10">' + nums + '</div>'
        + '<p class="rfi-note">' + esc(this.status.disclaimer) + '</p>'
        + '<p class="rfi-min" hidden></p>'
        + (error ? '<p class="rfi-error" role="alert">' + esc(error) + '</p>' : '')
        + '<button type="button" class="rfi-roll" disabled>Roll the die</button>';
      this.body.querySelector('.rfi-grid').addEventListener('click', (e) => {
        const b = e.target.closest('.rfi-num');
        if (!b) return;
        this.picked = Number(b.dataset.n);
        this.body.querySelectorAll('.rfi-num').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        this.updateButton();
      });
      this.body.querySelector('.rfi-roll').addEventListener('click', () => this.roll());
      this.updateButton();
    }

    async refreshCart() {
      try { this.cart = await getCart(); } catch (e) { this.cart = null; }
      this.updateButton();
    }

    updateButton() {
      const button = this.body && this.body.querySelector('.rfi-roll');
      if (!button) return;
      const min = this.body.querySelector('.rfi-min');
      const have = this.cart ? qualifying(this.cart) : 0;
      const short = this.status.min_order - have;
      if (this.cart && short > 0) {
        min.hidden = false;
        min.textContent = this.status.spend_to_roll + '. Add ' + money(short) + ' more.';
      } else {
        min.hidden = true;
      }
      button.disabled = this.busy || !this.cart || short > 0 || !this.picked;
    }

    async makeDie(stage) {
      const anim = this.status.animation;
      if (wants3D()) {
        try {
          const lib = await Promise.race([load3D(this.cfg.die3d), sleep(2500).then(() => { throw new Error('slow'); })]);
          return lib.create(stage, { dieColor: anim.die_color, numberColor: anim.number_color, accentColor: anim.accent_color });
        } catch (e) { /* fall back to CSS */ }
      }
      return cssDie(stage, anim);
    }

    async roll() {
      if (this.busy) return;
      this.busy = true;
      const anim = this.status.animation;
      const sound = makeSound(anim.sound);
      this.body.innerHTML = '<div class="rfi-stage" aria-hidden="true"></div><p class="rfi-msg">Rolling…</p>';
      const stage = this.body.querySelector('.rfi-stage');
      const die = await this.makeDie(stage);
      const started = performance.now();
      die.start();
      sound.rattle();

      let result = null;
      let error = null;
      try {
        const res = await fetch(this.cfg.proxy + '/roll', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            picked_number: this.picked,
            lines: (this.cart.items || []).map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })),
            cart_token: this.cart.token,
            currency: this.cart.currency,
            preview: preview() || null,
          }),
        });
        const data = await json(res);
        if (res.ok) result = data;
        else error = data.message || (data.errors && Object.values(data.errors)[0][0]) || 'Something went wrong. Please try again.';
      } catch (e) {
        error = 'We could not reach the store. Please try again.';
      }

      if (error) {
        sound.stop();
        die.dispose && die.dispose();
        this.busy = false;
        this.renderForm(error);
        this.refreshCart();
        return;
      }

      const settleAt = started + Math.max(800, anim.duration_ms - 500);
      if (!result.repeat) await sleep(Math.max(0, settleAt - performance.now()));
      await die.settle(result.displayed_number);
      sound.stop();
      this.busy = false;
      if (!result.test) STORE.setItem(RESULT_KEY, JSON.stringify({ at: Date.now(), result }));
      this.showResult(result, true, die, sound);
    }

    async showResult(result, fresh, die, sound) {
      const anim = this.status.animation;
      if (!die) {
        this.body.innerHTML = '<div class="rfi-stage" aria-hidden="true"></div>';
        cssDie(this.body.querySelector('.rfi-stage'), anim).show(result.displayed_number);
      }
      const stageHtml = this.body.querySelector('.rfi-stage');
      this.body.innerHTML = '';
      this.body.appendChild(stageHtml);
      const msg = document.createElement('p');
      msg.className = 'rfi-msg rfi-msg--' + (result.won ? 'win' : 'lose');
      msg.textContent = (result.repeat ? 'You have already rolled today. ' : '') + result.message;
      this.body.appendChild(msg);
      this.live.textContent = msg.textContent;

      // Streak: only tick for a fresh, non-test, non-repeat roll.
      if (fresh) updateStreak(result, this.status);
      if (!this.countdown) this.startCountdown();
      this.updateStreakBadge();

      // Loss teaser: tell them what the cart could have won, and when they can come back.
      if (!result.won && result.potential_payout_formatted) {
        const nextLabel = this.status.next_roll && this.status.next_roll.label;
        const tease = document.createElement('p');
        tease.className = 'rfi-teaser';
        tease.textContent = 'That cart could have won ' + result.potential_payout_formatted + '.'
          + (nextLabel ? ' Come back ' + nextLabel + '.' : ' Come back on the next promo day.');
        this.body.appendChild(tease);
      }

      if (fresh && result.won) {
        sound && sound.win();
        if (anim.celebration) confetti([anim.accent_color, anim.die_color, '#ffffff', '#22c55e']);
      } else if (fresh) {
        sound && sound.lose();
      }

      if (result.won && result.code && fresh) {
        const note = document.createElement('p');
        note.className = 'rfi-note';
        note.textContent = 'Applying your code…';
        this.body.appendChild(note);
        try {
          const { applied, cart } = await applyCode(result.code);
          note.textContent = applied
            ? 'Code ' + result.code + ' is applied to your cart.'
            : 'Use code ' + result.code + ' at checkout. Your cart must still be worth at least ' + this.status.min_order_formatted + '.';
          if (applied) refreshCartUi(cart, this.cfg.placement);
        } catch (e) {
          note.textContent = 'Use code ' + result.code + ' at checkout.';
        }
      } else if (result.won && result.code) {
        const note = document.createElement('p');
        note.className = 'rfi-note';
        note.textContent = 'Your code: ' + result.code;
        this.body.appendChild(note);
      }

      // Share-a-win: only on a real win (not test / not POS), and only when the result makes sense to share.
      if (result.won && !result.test && result.channel !== 'pos') {
        this.appendShareButton(result);
      }
    }

    updateStreakBadge() {
      const title = this.host.querySelector('.rfi-title');
      if (!title) return;
      const existing = title.querySelector('.rfi-streak');
      const text = streakBadge(readStreak().count);
      if (existing) existing.remove();
      if (text) {
        const span = document.createElement('span');
        span.className = 'rfi-streak';
        span.textContent = text;
        title.appendChild(document.createTextNode(' '));
        title.appendChild(span);
      }
    }

    appendShareButton(result) {
      const wrap = document.createElement('div');
      wrap.className = 'rfi-share';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'rfi-share-btn';
      btn.textContent = 'Share this win';
      const text = 'I just won ' + (result.payout_formatted || 'a discount') + ' off at ' + location.hostname + ' with Roll4It!';
      const url = location.origin;
      btn.addEventListener('click', async () => {
        if (navigator.share) {
          try { await navigator.share({ title: 'Roll4It win', text, url }); return; } catch (e) { /* fall through */ }
        }
        // Fallback: open a WhatsApp intent in a new tab + copy a link.
        const wa = 'https://wa.me/?text=' + encodeURIComponent(text + ' ' + url);
        window.open(wa, '_blank', 'noopener');
      });
      wrap.appendChild(btn);
      this.body.appendChild(wrap);
    }
  }

  /* Prefer the cart summary column (above CHECK OUT) over the line-item stack. */
  const CART_CHECKOUT_SELS = [
    'button[name="checkout"]',
    'input[name="checkout"]',
    'button[type="submit"][name="checkout"]',
    'form[action*="/cart"] [name="checkout"]',
    'a[href="/checkout"]',
    'a[href$="/checkout"]',
  ];
  const CART_SUMMARY_SELS = [
    '.cart__footer',
    '.cart-footer',
    '.cart__summary',
    '.cart-summary',
    '.cart__ctas',
    '[data-cart-footer]',
    '.cart__blocks',
  ];

  function cartSummarySlot() {
    for (const sel of CART_CHECKOUT_SELS) {
      const checkout = document.querySelector(sel);
      if (!checkout) continue;
      if (checkout.closest('cart-drawer, #CartDrawer, .cart-drawer, [id*="CartDrawer"]')) continue;
      const parent = checkout.parentNode;
      if (parent) return { parent, before: checkout };
    }
    for (const sel of CART_SUMMARY_SELS) {
      const box = document.querySelector(sel);
      if (!box || box.closest('cart-drawer, #CartDrawer, .cart-drawer')) continue;
      return { parent: box, before: box.firstChild };
    }
    return null;
  }

  function placeOnCartPage(host) {
    if (!host || host.dataset.rfiSummary === '1') return false;
    const slot = cartSummarySlot();
    if (!slot) return false;
    host.classList.add('rfi-root--page', 'rfi-root--summary');
    slot.parent.insertBefore(host, slot.before);
    host.dataset.rfiSummary = '1';
    return true;
  }

  function mount(host, cfg) {
    if (cfg.placement === 'cart') placeOnCartPage(host);
    if (host.__rfi) return;
    new Widget(host, cfg).init();
  }

  function mountBlocks() {
    document.querySelectorAll('[data-rfi]').forEach((host) => mount(host, {
      proxy: host.dataset.proxy, currency: host.dataset.currency, die3d: host.dataset.die3d, placement: host.dataset.placement || 'cart',
    }));
  }

  async function mountEmbed() {
    if (!embed) return;
    const status = await getStatus(embed.proxy);
    if (!visible(status)) return;
    const cfg = { proxy: embed.proxy, currency: embed.currency, die3d: embed.die3d, placement: 'drawer' };
    const selectors = [status.drawer_selector, embed.drawerSelector].filter(Boolean).concat(DRAWER_PRESETS);

    for (const selector of selectors) {
      let targets = [];
      try { targets = document.querySelectorAll(selector); } catch (e) { continue; }
      targets.forEach((target) => {
        if (target.querySelector(':scope > .rfi-root')) return;
        const host = document.createElement('div');
        host.className = 'rfi-root rfi-root--drawer';
        host.hidden = true;
        target.insertBefore(host, target.firstChild);
        mount(host, cfg);
      });
      if (targets.length) break;
    }

    if (embed.cartPageFallback && embed.template === 'cart' && !document.querySelector('[data-rfi]') && !document.querySelector('.rfi-root--page')) {
      const host = document.createElement('div');
      host.className = 'rfi-root rfi-root--page';
      host.hidden = true;
      if (!placeOnCartPage(host)) {
        const form = document.querySelector('form[action$="/cart"], form[action*="/cart?"]');
        if (!form) return;
        form.parentNode.insertBefore(host, form.nextSibling);
      }
      mount(host, Object.assign({}, cfg, { placement: 'cart' }));
    }
  }

  function refreshAll() {
    document.querySelectorAll('.rfi-root').forEach((host) => host.__rfi && !host.__rfi.busy && host.__rfi.refreshCart());
  }

  function boot() {
    mountBlocks();
    mountEmbed();
    let pending = null;
    new MutationObserver(() => {
      clearTimeout(pending);
      pending = setTimeout(() => { mountBlocks(); mountEmbed(); }, 150);
    }).observe(document.body, { childList: true, subtree: true });

    const nativeFetch = window.fetch;
    window.fetch = function (input) {
      const url = typeof input === 'string' ? input : (input && input.url) || '';
      const p = nativeFetch.apply(this, arguments);
      if (/\/cart\/(add|change|update|clear)/.test(url)) p.then(() => setTimeout(refreshAll, 50)).catch(() => {});
      return p;
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshAll(); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
