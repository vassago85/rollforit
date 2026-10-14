/* Test lab frame: runs the real storefront widget. The admin page answers the app proxy and cart calls, because only it holds the session token. */
(() => {
  const RESULT_KEY = 'rfi_result';
  const STREAK_KEY = 'rfi_streak';
  const pending = new Map();
  let seq = 0;
  let config = null;
  let rolling = false;
  let configured;
  const ready = new Promise((resolve) => { configured = resolve; });

  const post = (msg) => parent.postMessage(Object.assign({ source: 'rfi-lab-frame' }, msg), location.origin);
  const json = (data, status) => new Response(JSON.stringify(data), { status: status || 200, headers: { 'Content-Type': 'application/json' } });
  const widget = () => {
    const host = document.querySelector('[data-rfi]');
    return host && host.__rfi;
  };

  function cart(codes) {
    return {
      token: 'lab-cart',
      currency: config.status.currency,
      items: config.cart > 0 ? [{ variant_id: 1, quantity: 1, original_line_price: config.cart, gift_card: false }] : [],
      discount_codes: codes || [],
    };
  }

  /* Seed the streak so this roll lands on the requested day count, and forget any earlier lab result. */
  function clearTable() {
    sessionStorage.removeItem(RESULT_KEY);
    const before = Math.max(1, config.streak || 1) - 1;
    localStorage.setItem(STREAK_KEY, JSON.stringify({
      count: before,
      last_promo_date: before ? config.status.previous_promo_date : null,
    }));
  }

  function status() {
    return ready.then(() => {
      const w = widget();
      if (w && !w.email) w.email = config.email;
      return json(config.status);
    });
  }

  function roll(body) {
    const id = ++seq;
    rolling = true;
    post({ type: 'roll', id, picked_number: body.picked_number });
    return new Promise((resolve) => pending.set(id, resolve));
  }

  const realFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const path = url.replace(/^[a-z]+:\/\/[^/]*/i, '').split('?')[0];
    if (path === '/apps/roll-for-it/status') return status();
    if (path === '/apps/roll-for-it/roll') return roll(JSON.parse(init.body));
    if (path === '/cart.js') return ready.then(() => json(cart()));
    if (path === '/cart/update.js') {
      const code = JSON.parse(init.body).discount;
      return Promise.resolve(json(cart([{ code, applicable: true }])));
    }
    if (path.startsWith('/cart/')) return Promise.resolve(json(cart()));
    return realFetch(input, init);
  };

  /* Sharing would post the admin domain to WhatsApp, so show the merchant the message instead. */
  const share = (text) => post({ type: 'share', text });
  try {
    Object.defineProperty(navigator, 'share', {
      configurable: true,
      value: (data) => { share([data.text, data.url].filter(Boolean).join(' ')); return Promise.resolve(); },
    });
  } catch (e) { /* the widget falls back to window.open, caught below */ }
  const realOpen = window.open.bind(window);
  window.open = function (url) {
    const match = /^https:\/\/wa\.me\/\?text=(.*)$/.exec(String(url));
    if (match) { share(decodeURIComponent(match[1])); return null; }
    return realOpen.apply(window, arguments);
  };

  /* The widget has no result hook, so watch for its result message to know when the die has landed. */
  new MutationObserver(() => {
    if (!rolling) return;
    const msg = document.querySelector('.rfi-msg--win, .rfi-msg--lose');
    if (!msg) return;
    rolling = false;
    post({ type: 'landed', won: msg.classList.contains('rfi-msg--win') });
  }).observe(document.documentElement, { childList: true, subtree: true });

  window.addEventListener('message', (e) => {
    if (e.source !== parent || e.origin !== location.origin) return;
    const msg = e.data || {};
    if (msg.source !== 'rfi-lab') return;

    if (msg.type === 'config') {
      const first = config === null;
      config = msg.config;
      clearTable();
      if (first) { configured(); return; }
      const w = widget();
      if (w && !w.busy) w.init(true);
    } else if (msg.type === 'cart' && config) {
      config.cart = msg.cart;
      window.fetch('/cart/change.js', { method: 'POST' });
    } else if (msg.type === 'rolled' && pending.has(msg.id)) {
      if (msg.status !== 200) rolling = false;
      pending.get(msg.id)(json(msg.body, msg.status));
      pending.delete(msg.id);
    }
  });

  const reportHeight = () => post({ type: 'height', value: Math.ceil(document.body.getBoundingClientRect().height) });
  window.addEventListener('load', () => {
    if ('ResizeObserver' in window) new ResizeObserver(reportHeight).observe(document.body);
    reportHeight();
  });

  post({ type: 'hello' });
})();
