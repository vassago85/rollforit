/*
 * Test-lab iframe glue. The right-hand pane of the Test lab is the REAL
 * storefront widget bundle running inside this iframe. Our job is:
 *
 *   1. Monkey-patch window.fetch so `/apps/roll-for-it/*` and `/cart*` calls
 *      resolve against the parent's config instead of hitting the network.
 *      The widget's first getStatus() awaits our `ready` Promise, so even
 *      though the bundle loads synchronously it can't boot with stale defaults.
 *   2. On a `config` message, update config and (if the widget has already
 *      booted) tear the widget host down and re-mount so dice_count /
 *      pick_range changes always take effect. The widget's own init(true)
 *      short-circuits on `busy`, so we never trust it.
 *   3. After each pick-form render, post a `rendered` message back to the
 *      parent with the dice_count / pick_range the widget actually drew. The
 *      parent displays this as "What the widget sees" so a settings↔widget
 *      disagreement is impossible to miss.
 */
(() => {
  const RESULT_KEY = 'rfi_result';
  const STREAK_KEY = 'rfi_streak';
  const pending = new Map();
  let seq = 0;
  let config = null;
  let rolling = false;
  let resolveReady;
  const ready = new Promise((resolve) => { resolveReady = resolve; });

  const post = (msg) => parent.postMessage(Object.assign({ source: 'rfi-lab-frame' }, msg), location.origin);
  const json = (data, status) => new Response(JSON.stringify(data), { status: status || 200, headers: { 'Content-Type': 'application/json' } });
  const widgetHost = () => document.querySelector('[data-rfi]');
  const widget = () => {
    const host = widgetHost();
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

  /* Seed the streak so this roll lands on the requested day count, and forget any
     earlier lab result. The widget stores results in localStorage now (so storefront
     customers can't dodge the per-day limit by closing the browser), so the lab
     clears both for backwards compatibility with older cached bundles. */
  function clearTable() {
    sessionStorage.removeItem(RESULT_KEY);
    localStorage.removeItem(RESULT_KEY);
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

  /* The widget names the site it runs on, which here is the app's domain. Rewrite
     any share messages to use the shop's storefront host, and route the share
     intent back to the parent instead of actually opening a window. */
  function share(text) {
    if (config && config.storefront_url) {
      const store = new URL(config.storefront_url);
      text = text.split(location.origin).join(store.origin).split(location.hostname).join(store.hostname);
    }
    post({ type: 'share', text });
  }
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

  /* Read what the widget just drew and tell the parent. The parent surfaces this
     as "What the widget sees" so merchants can spot immediately when the widget
     has fallen out of sync with settings (the original 2-dice-stuck-on-1 bug). */
  let lastReportedKey = null;
  function reportRender() {
    const host = widgetHost();
    if (!host) return;
    const grid = host.querySelector('.rfi-grid');
    if (!grid) return;
    const buttons = Array.from(grid.querySelectorAll('.rfi-num'));
    if (buttons.length === 0) return;
    const picks = buttons.map((b) => Number(b.dataset.n)).filter((n) => !Number.isNaN(n));
    if (picks.length === 0) return;
    const w = widget();
    const dice = (w && w.status && w.status.dice_count) || (picks.length === 11 && picks[0] === 2 ? 2 : 1);
    const key = dice + '|' + Math.min(...picks) + '|' + Math.max(...picks) + '|' + picks.length;
    if (key === lastReportedKey) return;
    lastReportedKey = key;
    post({
      type: 'rendered',
      rendered: {
        dice_count: dice,
        pick_min: Math.min(...picks),
        pick_max: Math.max(...picks),
        pick_count: picks.length,
        roll_button_label: (host.querySelector('.rfi-roll') || {}).textContent || null,
      },
    });
  }

  /* The widget has no public result hook, so watch the DOM for its result message
     to tell the parent when the die has landed. The same observer handles the
     render-report callback above. */
  new MutationObserver(() => {
    if (rolling) {
      const msg = document.querySelector('.rfi-msg--win, .rfi-msg--lose');
      if (msg) {
        rolling = false;
        post({ type: 'landed', won: msg.classList.contains('rfi-msg--win') });
      }
    }
    reportRender();
  }).observe(document.documentElement, { childList: true, subtree: true });

  /* Reload the iframe on every config change after the first. The widget caches
     the status response in a MODULE-SCOPED `statusPromise`, so even a fresh
     [data-rfi] host, a Widget constructor and an init() call all resolve
     against the ORIGINAL status (dice_count=2 stays dice_count=2 even after
     settings change to 1). The only way to drop that cache is to drop the
     bundle's IIFE scope entirely, i.e. reload the iframe. Nuclear but correct:
     the parent re-posts the latest config on the fresh frame's `hello`. */
  function remountWidget() {
    // Mark the frame as awaiting a reload so the parent knows to re-send its
    // config after the fresh `hello`. The parent stores the latest config
    // regardless, so we just need to kick a reload.
    location.reload();
  }

  window.addEventListener('message', (e) => {
    if (e.source !== parent || e.origin !== location.origin) return;
    const msg = e.data || {};
    if (msg.source !== 'rfi-lab') return;

    if (msg.type === 'config') {
      const first = config === null;
      config = msg.config;
      clearTable();
      if (first) {
        resolveReady();
      } else {
        remountWidget();
      }
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
