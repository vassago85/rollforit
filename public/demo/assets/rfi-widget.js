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
  const VERIFY_KEY = 'rfi_verified_email'; // { email, promo_date } once the Mailgun code is confirmed
  const EMAIL_RX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function readVerified(status) {
    if (!status || !status.promo_date) return null;
    try {
      const raw = JSON.parse(LOCAL.getItem(VERIFY_KEY) || 'null');
      if (!raw || !raw.email || !raw.promo_date) return null;
      if (raw.promo_date !== status.promo_date) return null; // yesterday's confirmation doesn't carry over
      return raw.email;
    } catch (e) { return null; }
  }
  function writeVerified(email, promo_date) {
    try { LOCAL.setItem(VERIFY_KEY, JSON.stringify({ email: email, promo_date: promo_date })); } catch (e) {}
  }
  function clearVerified() {
    try { LOCAL.removeItem(VERIFY_KEY); } catch (e) {}
  }
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

  const VISIBLE_WHEN_CLOSED = ['sold_out', 'upcoming', 'owed'];
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

  /* Saved result lives in localStorage so closing the browser does NOT reset the lock.
     Scoped to the server's promo_date, which naturally rolls it over on the next promo day.
     48h backstop handles edge cases (timezone drift, late-night rolls). */
  function savedResult(status) {
    try {
      const saved = JSON.parse(LOCAL.getItem(RESULT_KEY) || 'null');
      if (!saved) return null;
      if (status && saved.promo_date && status.promo_date && saved.promo_date !== status.promo_date) return null;
      if (Date.now() - saved.at > 48 * 3600 * 1000) return null;
      return saved.result;
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

  /* Split a target sum (2..12) into two valid d6 faces, random valid pair. */
  function splitTwoDice(sum) {
    const target = Math.max(2, Math.min(12, sum | 0));
    const minFirst = Math.max(1, target - 6);
    const maxFirst = Math.min(6, target - 1);
    const first = minFirst + Math.floor(Math.random() * (maxFirst - minFirst + 1));
    return [first, target - first];
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

  /* Pip grid for a d6 face. Nine cells, data-face selects which cells light up (CSS). */
  const PIP_GRID = '<span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>';
  const d6Html = (anim, extra) => '<div class="rfi-cssdie rfi-cssdie--d6' + (extra ? ' ' + extra : '') + '" style="--rfi-die:'
    + esc(anim.die_color) + ';--rfi-num:' + esc(anim.number_color) + '">'
    + '<div class="rfi-pips" data-face="?">' + PIP_GRID + '</div></div>';

  /* Two-dice fallback: a pair of d6s whose pips sum to the server's result. */
  function cssTwoDice(stage, anim) {
    stage.innerHTML = '<div class="rfi-twodice">' + d6Html(anim) + d6Html(anim) + '</div>';
    const [left, right] = stage.firstChild.children;
    const pips = [left.firstChild, right.firstChild];
    const setFace = (i, n) => pips[i].setAttribute('data-face', String(n));
    const randPair = () => [1 + Math.floor(Math.random() * 6), 1 + Math.floor(Math.random() * 6)];
    let timer = null;
    return {
      start() {
        [left, right].forEach((d) => d.classList.add('rfi-cssdie--rolling'));
        if (reduceMotion) return;
        timer = setInterval(() => { const [a, b] = randPair(); setFace(0, a); setFace(1, b); }, 70);
      },
      async settle(sum) {
        clearInterval(timer);
        if (!reduceMotion) {
          for (const delay of [90, 130, 180]) {
            const [a, b] = randPair();
            setFace(0, a); setFace(1, b);
            await sleep(delay);
          }
        }
        const [a, b] = splitTwoDice(sum);
        setFace(0, a); setFace(1, b);
        [left, right].forEach((d) => { d.classList.remove('rfi-cssdie--rolling'); d.classList.add('rfi-cssdie--landed'); });
      },
      show(sum) {
        const [a, b] = splitTwoDice(sum);
        setFace(0, a); setFace(1, b);
        [left, right].forEach((d) => d.classList.add('rfi-cssdie--landed'));
      },
      stop() { clearInterval(timer); [left, right].forEach((d) => d.classList.remove('rfi-cssdie--rolling')); },
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

  /**
   * Celebratory confetti: two bottom-corner cannons fire first, then a top-center encore
   * drops a wave of mixed-shape pieces (rectangles, discs, streamers). Honours
   * prefers-reduced-motion by skipping the whole animation.
   */
  function confetti(colors) {
    if (reduceMotion) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const canvas = document.createElement('canvas');
    canvas.className = 'rfi-confetti';
    const sizeCanvas = () => {
      canvas.width = Math.max(1, innerWidth * dpr);
      canvas.height = Math.max(1, innerHeight * dpr);
      canvas.style.width = innerWidth + 'px';
      canvas.style.height = innerHeight + 'px';
    };
    sizeCanvas();
    const onResize = () => sizeCanvas();
    window.addEventListener('resize', onResize);
    document.body.appendChild(canvas);
    const g = canvas.getContext('2d');

    const SHAPES = ['rect', 'circle', 'streamer'];
    const makePiece = (x, y, vx, vy) => ({
      x, y, vx, vy,
      r: Math.random() * Math.PI * 2,
      vr: (Math.random() - 0.5) * 0.4,
      shape: SHAPES[Math.floor(Math.random() * SHAPES.length)],
      c: colors[Math.floor(Math.random() * colors.length)],
      w: 7 + Math.random() * 8,
      alpha: 1,
    });
    // Cannon from a bottom corner, aimed inward and up.
    const cannon = (originX, dir, count) => Array.from({ length: count }, () => {
      const angle = (-Math.PI / 2) + dir * (Math.random() * 0.45 + 0.15);
      const speed = 14 + Math.random() * 10;
      return makePiece(originX, innerHeight - 10, Math.cos(angle) * speed, Math.sin(angle) * speed);
    });
    // Encore from the top-center, raining down.
    const encore = (count) => Array.from({ length: count }, () => makePiece(
      innerWidth / 2 + (Math.random() - 0.5) * 240,
      innerHeight / 4,
      (Math.random() - 0.5) * 12,
      -6 - Math.random() * 6,
    ));

    const bits = cannon(10, 1, 90).concat(cannon(innerWidth - 10, -1, 90));
    const start = performance.now();
    const encoreAt = start + 350;
    const end = start + 4500;
    const fadeStart = end - 900;
    let fired = false;

    (function frame(now) {
      g.setTransform(dpr, 0, 0, dpr, 0, 0);
      g.clearRect(0, 0, innerWidth, innerHeight);
      if (!fired && now >= encoreAt) { fired = true; bits.push(...encore(60)); }
      for (let i = 0; i < bits.length; i++) {
        const b = bits[i];
        b.vy += 0.22; b.vx *= 0.993; b.x += b.vx; b.y += b.vy; b.r += b.vr;
        if (now > fadeStart) b.alpha = Math.max(0, 1 - (now - fadeStart) / 900);
        g.save();
        g.globalAlpha = b.alpha;
        g.translate(b.x, b.y);
        g.rotate(b.r);
        g.fillStyle = b.c;
        if (b.shape === 'rect') g.fillRect(-b.w / 2, -b.w / 3, b.w, b.w * 0.6);
        else if (b.shape === 'circle') { g.beginPath(); g.arc(0, 0, b.w / 2.4, 0, Math.PI * 2); g.fill(); }
        else { g.fillRect(-b.w, -1.2, b.w * 2, 2.4); }
        g.restore();
      }
      if (now < end) requestAnimationFrame(frame);
      else { canvas.remove(); window.removeEventListener('resize', onResize); }
    })(start);
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
      this.verifiedEmail = null; // set once the Mailgun code is confirmed for this promo day
      this.pendingEmail = null;  // email currently waiting for its 6-digit code
      host.__rfi = this;
    }

    async init(fresh) {
      this.stopCountdown();
      this.stopCodeCountdown();
      const status = (this.status = await getStatus(this.cfg.proxy, fresh));
      const hide = !visible(status) || (this.cfg.currency && status.currency && this.cfg.currency !== status.currency);
      if (hide) { this.host.hidden = true; return; }
      this.host.hidden = false;
      this.renderShell();
      if (!status.open) this.startCountdown();
      this.verifiedEmail = status.requires_email_verification ? readVerified(status) : null;
      const saved = status.test ? null : savedResult(status);
      if (saved) this.showResult(saved, false);
      else if (status.reason === 'upcoming') this.renderUpcoming();
      else if (status.reason === 'sold_out') this.body.innerHTML = '<p class="rfi-msg">' + esc(status.sold_out_message) + '</p>';
      else if (status.reason === 'owed') this.renderOwed();
      else if (status.requires_email_verification && !this.verifiedEmail) this.renderEmailForm();
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
      // Today's winners + total paid out, surfaced as a stand-out stats card so the
      // numbers (amount, count) read at a glance instead of being buried in a sentence.
      const stats = s.today_stats;
      const socialHtml = stats && stats.winners > 0
        ? '<div class="rfi-social" role="status">'
          + '<span class="rfi-social-dot" aria-hidden="true"></span>'
          + '<span class="rfi-social-amount">' + esc(stats.total_payout_formatted) + '</span>'
          + '<span class="rfi-social-label">given away today</span>'
          + '<span class="rfi-social-sep" aria-hidden="true">&middot;</span>'
          + '<span class="rfi-social-count"><b>' + stats.winners + '</b> ' + (stats.winners === 1 ? 'winner' : 'winners') + ' so far</span>'
          + '</div>'
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
      const teaser = this.status.dice_count === 2
        ? '<div class="rfi-twodice">' + d6Html(a, 'rfi-cssdie--idle') + d6Html(a, 'rfi-cssdie--idle') + '</div>'
        : '<div class="rfi-cssdie rfi-cssdie--idle" style="--rfi-die:' + esc(a.die_color) + ';--rfi-num:' + esc(a.number_color) + '"><span>?</span></div>';
      this.body.innerHTML = '<div class="rfi-stage rfi-stage--teaser" aria-hidden="true">' + teaser + '</div>'
        + '<p class="rfi-msg">The dice are resting. Rolling opens ' + esc(this.status.next_roll.label) + '.</p>';
    }

    renderOwed() {
      const owed = this.status.owed_roll || {};
      const codeLine = owed.code
        ? '<p class="rfi-note">Your previous winning code: <b>' + esc(owed.code) + '</b></p>'
        : '';
      this.body.innerHTML = '<p class="rfi-msg">' + esc(owed.message || 'Complete a purchase to unlock your next roll.') + '</p>'
        + codeLine;
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
          LOCAL.removeItem(RESULT_KEY);
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

    stopCodeCountdown() {
      clearInterval(this.codeCountdown);
      this.codeCountdown = null;
    }

    /**
     * Shows a MM:SS timer counting down to result.ends_at (ISO 8601). When the
     * code lapses, the chip flips red and the code itself is hidden so the
     * shopper doesn't paste a dead code at checkout.
     */
    startCodeCountdown(result) {
      this.stopCodeCountdown();
      if (!result || !result.ends_at) return;

      const target = new Date(result.ends_at).getTime();
      if (!isFinite(target)) return;

      const chip = document.createElement('p');
      chip.className = 'rfi-code-timer';
      this.body.appendChild(chip);

      const tick = () => {
        const remaining = Math.round((target - Date.now()) / 1000);
        if (remaining <= 0) {
          this.stopCodeCountdown();
          chip.classList.add('rfi-code-timer--dead');
          chip.textContent = 'Your code has expired. The win was only valid for this cart.';
          // Scrub the code line so no one copy-pastes a dead code at checkout.
          const notes = this.body.querySelectorAll('.rfi-note');
          notes.forEach((n) => {
            if (n.textContent && n.textContent.indexOf(result.code) >= 0) {
              n.textContent = 'Code expired. Finish checkout before the next roll opens to lock in a win.';
              n.classList.add('rfi-note--muted');
            }
          });
          return;
        }
        const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
        const ss = String(remaining % 60).padStart(2, '0');
        chip.textContent = 'Code expires in ' + mm + ':' + ss + ' — redeem this cart now.';
        if (remaining <= 60) chip.classList.add('rfi-code-timer--urgent');
      };
      tick();
      this.codeCountdown = setInterval(tick, 1000);
    }

    /**
     * Step 1: ask for an email. The widget blocks /roll until the shopper
     * confirms a 6-digit code we send through Mailgun. Logged-in Shopify
     * customers skip this entirely (status.requires_email_verification is false).
     */
    renderEmailForm(error, prefill) {
      const value = esc(prefill || this.pendingEmail || '');
      // Use <div>, not <form>: on cart pages the widget is often injected inside
      // the theme's <form action="/cart">, and HTML parsers silently drop nested
      // <form> elements — which left button clicks submitting the parent cart
      // form (page reload) and the widget's own submit listener never attached.
      this.body.innerHTML = ''
        + '<p class="rfi-label">Enter your email to roll</p>'
        + '<p class="rfi-note">We send you a 6-digit code to confirm it. No spam, used only to issue your prize.</p>'
        + '<div class="rfi-email-form">'
        +   '<input type="email" class="rfi-email" name="email" autocomplete="email" required placeholder="you@example.com" value="' + value + '" />'
        +   (error ? '<p class="rfi-error" role="alert">' + esc(error) + '</p>' : '')
        +   '<button type="button" class="rfi-roll">Send me a code</button>'
        + '</div>';

      const input = this.body.querySelector('.rfi-email');
      const button = this.body.querySelector('.rfi-roll');
      const submit = () => {
        if (this.busy) return;
        const email = (input.value || '').trim();
        if (!EMAIL_RX.test(email)) {
          this.renderEmailForm('That email does not look right. Please check and try again.', email);
          return;
        }
        this.busy = true;
        button.disabled = true;
        button.textContent = 'Sending…';
        this.sendCode(email);
      };
      button.addEventListener('click', (e) => { e.preventDefault(); submit(); });
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
      input.focus();
    }

    async sendCode(email) {
      let data = null, httpOk = false;
      try {
        const res = await fetch(this.cfg.proxy + '/email/send-code', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ email: email }),
        });
        httpOk = res.ok;
        data = await json(res);
      } catch (e) {
        this.busy = false;
        this.renderEmailForm('We could not reach the store. Please try again.', email);
        return;
      }
      this.busy = false;
      if (!httpOk) {
        this.renderEmailForm((data && data.message) || 'We could not send a code just now. Please try again.', email);
        return;
      }
      this.pendingEmail = email;
      this.renderCodeForm();
    }

    /**
     * Step 2: the shopper types the 6-digit code from their inbox. On success
     * we flip to the pick form.
     */
    renderCodeForm(error) {
      // See renderEmailForm: <div> + type="button" avoids nested-form breakage
      // when the widget mounts inside the theme's cart <form>.
      this.body.innerHTML = ''
        + '<p class="rfi-label">Check your inbox</p>'
        + '<p class="rfi-note">We sent a 6-digit code to <b>' + esc(this.pendingEmail) + '</b>. Enter it below to roll.</p>'
        + '<div class="rfi-code-form">'
        +   '<input type="text" class="rfi-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\\d{6}" required placeholder="123456" />'
        +   (error ? '<p class="rfi-error" role="alert">' + esc(error) + '</p>' : '')
        +   '<button type="button" class="rfi-roll">Confirm</button>'
        + '</div>'
        + '<p class="rfi-note rfi-code-resend">Did not get it? '
        +   '<a href="#" class="rfi-link-resend">Resend code</a> &middot; '
        +   '<a href="#" class="rfi-link-change">Change email</a>'
        + '</p>';

      const input = this.body.querySelector('.rfi-code');
      const button = this.body.querySelector('.rfi-roll');
      const submit = () => {
        if (this.busy) return;
        const code = (input.value || '').trim();
        if (!/^\d{6}$/.test(code)) {
          this.renderCodeForm('Enter the 6-digit code from your email.');
          return;
        }
        this.busy = true;
        button.disabled = true;
        button.textContent = 'Confirming…';
        this.submitCode(code);
      };
      button.addEventListener('click', (e) => { e.preventDefault(); submit(); });
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
      this.body.querySelector('.rfi-link-resend').addEventListener('click', (e) => {
        e.preventDefault();
        if (this.busy) return;
        this.busy = true;
        this.sendCode(this.pendingEmail);
      });
      this.body.querySelector('.rfi-link-change').addEventListener('click', (e) => {
        e.preventDefault();
        this.pendingEmail = null;
        this.renderEmailForm();
      });
      input.focus();
    }

    async submitCode(code) {
      let data = null, httpOk = false;
      try {
        const res = await fetch(this.cfg.proxy + '/email/verify-code', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ email: this.pendingEmail, code: code }),
        });
        httpOk = res.ok;
        data = await json(res);
      } catch (e) {
        this.busy = false;
        this.renderCodeForm('We could not reach the store. Please try again.');
        return;
      }
      this.busy = false;
      if (!httpOk) {
        const err = data && data.error;
        if (err === 'code_expired' || err === 'too_many_attempts') {
          // The code is dead; send the shopper back to the email step.
          this.renderEmailForm((data && data.message) || 'That code is no longer valid. Please request a new one.', this.pendingEmail);
          return;
        }
        this.renderCodeForm((data && data.message) || 'That code is not right. Please check your inbox and try again.');
        return;
      }
      this.verifiedEmail = this.pendingEmail;
      this.pendingEmail = null;
      writeVerified(this.verifiedEmail, this.status.promo_date);
      this.renderForm();
      this.refreshCart();
    }

    renderForm(error) {
      const range = (this.status.pick_range && typeof this.status.pick_range.min === 'number')
        ? this.status.pick_range
        : { min: 1, max: 10 };
      const count = range.max - range.min + 1;
      const cards = this.status.game === 'double' && count <= 12;
      const grid = cards ? ' rfi-grid--cards' : (this.status.dice_count === 2 ? ' rfi-grid--wide' : '');
      const nums = Array.from({ length: count }, (_, i) => i + range.min)
        .map((n) => '<button type="button" class="rfi-num" data-n="' + n + '" aria-pressed="' + (this.picked === n) + '">' + n + '</button>').join('');
      const playLabel = this.status.game === 'double'
        ? 'Play'
        : ('Roll ' + (this.status.dice_count === 2 ? 'the dice' : 'the die'));
      this.body.innerHTML = '<p class="rfi-label">' + (this.status.game === 'double' ? 'Pick one' : 'Pick your number') + '</p><div class="rfi-grid' + grid + '" role="group" aria-label="Pick from ' + range.min + ' to ' + range.max + '">' + nums + '</div>'
        + '<p class="rfi-note">' + esc(this.status.disclaimer) + '</p>'
        + '<p class="rfi-min" hidden></p>'
        + (error ? '<p class="rfi-error" role="alert">' + esc(error) + '</p>' : '')
        + '<button type="button" class="rfi-roll" disabled>' + playLabel + '</button>';
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
      // Only the pick form has the number grid + min-spend hint. On the email or
      // code form the .rfi-roll button is the Mailgun submit — we must not touch
      // its disabled state (that's owned by submit()) and .rfi-min won't exist.
      if (!this.body || !this.body.querySelector('.rfi-grid')) return;
      const button = this.body.querySelector('.rfi-roll');
      if (!button) return;
      const min = this.body.querySelector('.rfi-min');
      const have = this.cart ? qualifying(this.cart) : 0;
      const short = this.status.min_order - have;
      if (min) {
        if (this.cart && short > 0) {
          min.hidden = false;
          min.textContent = this.status.spend_to_roll + '. Add ' + money(short) + ' more.';
        } else {
          min.hidden = true;
        }
      }
      button.disabled = this.busy || !this.cart || short > 0 || !this.picked;
    }

    async makeDie(stage) {
      const anim = this.status.animation;
      // Two-dice mode uses the paired CSS dice; the 3D die binary only renders a single die.
      if (this.status.dice_count === 2) {
        return cssTwoDice(stage, anim);
      }
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
      const cards = this.status.game === 'double';
      this.body.innerHTML = '<div class="rfi-stage" aria-hidden="true"></div><p class="rfi-msg">' + (cards ? 'Revealing…' : 'Rolling…') + '</p>';
      const stage = this.body.querySelector('.rfi-stage');
      let die = null;
      if (cards) {
        stage.innerHTML = '<div class="rfi-face">' + esc(String(this.picked)) + '</div>';
      } else {
        die = await this.makeDie(stage);
        die.start();
        sound.rattle();
      }
      const started = performance.now();

      let result = null;
      let error = null;
      try {
        const res = await fetch(this.cfg.proxy + '/roll', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            email: this.verifiedEmail || null,
            picked_number: this.picked,
            lines: (this.cart.items || []).map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })),
            cart_token: this.cart.token,
            currency: this.cart.currency,
            preview: preview() || null,
          }),
        });
        const data = await json(res);
        if (res.ok) {
          result = data;
        } else if (data && (data.error === 'email_required' || data.error === 'email_not_verified')) {
          // Server dropped the verification (cache cleared, promo rolled over, etc.).
          // Reset local state and send the shopper back to the email step.
          sound.stop();
          die.dispose && die.dispose();
          clearVerified();
          this.verifiedEmail = null;
          this.busy = false;
          this.renderEmailForm(data.message || 'Please confirm your email before rolling.');
          return;
        } else {
          error = data.message || (data.errors && Object.values(data.errors)[0][0]) || 'Something went wrong. Please try again.';
        }
      } catch (e) {
        error = 'We could not reach the store. Please try again.';
      }

      if (error) {
        sound.stop();
        die && die.dispose && die.dispose();
        this.busy = false;
        this.renderForm(error);
        this.refreshCart();
        return;
      }

      const settleAt = started + Math.max(800, anim.duration_ms - 500);
      if (!cards && !result.repeat) await sleep(Math.max(0, settleAt - performance.now()));
      if (cards) await sleep(result.repeat ? 0 : 500);
      else await die.settle(result.displayed_number);
      sound.stop();
      this.busy = false;
      if (!result.test) LOCAL.setItem(RESULT_KEY, JSON.stringify({ at: Date.now(), promo_date: this.status.promo_date, result }));
      this.showResult(result, true, die, sound);
    }

    async showResult(result, fresh, die, sound) {
      if (result && result.decision === 'pending') {
        this.renderOffer(result, fresh, sound);
        return;
      }
      const anim = this.status.animation;
      if (!die) {
        this.body.innerHTML = '<div class="rfi-stage" aria-hidden="true"></div>';
        const stage = this.body.querySelector('.rfi-stage');
        if (this.status.game === 'double') {
          stage.innerHTML = '<div class="rfi-face">' + esc(String(result.displayed_number)) + '</div>';
        } else {
          const renderer = this.status.dice_count === 2 ? cssTwoDice(stage, anim) : cssDie(stage, anim);
          renderer.show(result.displayed_number);
        }
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
        this.startCodeCountdown(result);
      } else if (result.won && result.code) {
        const note = document.createElement('p');
        note.className = 'rfi-note';
        note.textContent = 'Your code: ' + result.code;
        this.body.appendChild(note);
        this.startCodeCountdown(result);
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

    renderOffer(result, fresh, sound) {
      this.lastOffer = result;
      if (fresh && sound) sound.win();
      const anim = this.status.animation;
      if (fresh && anim && anim.celebration) confetti([anim.accent_color, anim.die_color, '#ffffff', '#22c55e']);
      const base = result.base_percent || this.status.base_percent;
      const higher = result.double_percent || this.status.double_percent;
      this.body.innerHTML = '<p class="rfi-msg rfi-msg--win">' + esc(result.message) + '</p>'
        + '<div class="rfi-offer">'
        + '<button type="button" class="rfi-roll" data-decide="take">Take ' + esc(String(base)) + '% (' + esc(result.payout_formatted) + ')</button>'
        + (result.offer_double
          ? '<button type="button" class="rfi-risk" data-decide="bet">Risk it for ' + esc(String(higher)) + '% (' + esc(result.double_payout_formatted) + ')</button>'
          : '')
        + '</div>'
        + '<p class="rfi-note">A blank on the second pick pays nothing. If you leave this, you keep the first prize.</p>';
      this.live.textContent = result.message;
      this.body.querySelector('[data-decide="take"]').addEventListener('click', () => this.decide('take'));
      const risk = this.body.querySelector('[data-decide="bet"]');
      if (risk) risk.addEventListener('click', () => this.renderRisk(result));
    }

    renderRisk(result) {
      this.body.innerHTML = '<p class="rfi-label">Pick a card. One pays ' + esc(String(result.double_percent)) + '%. The other pays nothing.</p>'
        + '<div class="rfi-grid rfi-grid--cards" role="group" aria-label="Pick one of two cards">'
        + '<button type="button" class="rfi-num" data-card="1">?</button>'
        + '<button type="button" class="rfi-num" data-card="2">?</button>'
        + '</div>'
        + '<p class="rfi-note">A blank cancels the ' + esc(String(result.base_percent)) + '% you already won.</p>';
      this.body.querySelector('.rfi-grid').addEventListener('click', (e) => {
        const b = e.target.closest('[data-card]');
        if (!b || this.busy) return;
        this.decide('bet');
      });
    }

    async decide(path) {
      if (this.busy) return;
      const offer = this.lastOffer;
      if (offer && offer.simulated) {
        const next = Object.assign({}, offer);
        if (path === 'take') {
          next.decision = 'taken';
          next.code = next.code || ('SIM-' + Math.random().toString(36).slice(2, 8).toUpperCase());
          next.code_ready = true;
          next.won = true;
          next.message = 'You kept ' + next.payout_formatted + '.';
        } else if (Math.random() < 0.5) {
          next.decision = 'doubled';
          next.won = true;
          next.payout = next.double_payout;
          next.payout_formatted = next.double_payout_formatted;
          next.code = next.code || ('SIM-' + Math.random().toString(36).slice(2, 8).toUpperCase());
          next.code_ready = true;
          next.message = 'You won ' + next.payout_formatted + '.';
        } else {
          next.decision = 'busted';
          next.won = false;
          next.payout = 0;
          next.code = null;
          next.code_ready = true;
          next.message = 'Blank card. The first prize is gone.';
        }
        this.showResult(next, true, null, makeSound(this.status.animation && this.status.animation.sound));
        return;
      }
      this.busy = true;
      let result = null;
      let error = null;
      try {
        const res = await fetch(this.cfg.proxy + '/' + path, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            email: this.verifiedEmail || null,
            preview: preview() || null,
          }),
        });
        const data = await json(res);
        if (res.ok) result = data;
        else error = (data && data.message) || 'Something went wrong. Please try again.';
      } catch (e) {
        error = 'We could not reach the store. Please try again.';
      }
      this.busy = false;
      if (error) {
        this.renderOffer(this.lastOffer || { message: error, offer_double: false, payout_formatted: '', base_percent: '' }, false, null);
        const alert = document.createElement('p');
        alert.className = 'rfi-error';
        alert.setAttribute('role', 'alert');
        alert.textContent = error;
        this.body.appendChild(alert);
        return;
      }
      if (!result.test) LOCAL.setItem(RESULT_KEY, JSON.stringify({ at: Date.now(), promo_date: this.status.promo_date, result }));
      this.showResult(result, true, null, makeSound(this.status.animation && this.status.animation.sound));
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
