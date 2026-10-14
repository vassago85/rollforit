/* Stands in for Shopify's cart API and the Roll for It server so the real widget runs without a store. */
(() => {
  const params = new URLSearchParams(location.search);
  const state = params.get('state') || 'open';
  const MIN_ORDER = 100000;
  const MAX_PAYOUT = 150000;
  const MAX_PERCENT = 50;
  const demo = {
    subtotal: Number(params.get('subtotal') || 194900),
    outcome: params.get('outcome') || 'fair',
    lastPayout: 0,
  };
  sessionStorage.removeItem('rfi_result');

  const post = (msg) => parent !== window && parent.postMessage(Object.assign({ source: 'rfi-demo', state }, msg), '*');
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const rand = (n) => Math.floor(Math.random() * n) + 1;
  const money = (cents) => 'R' + (cents / 100).toLocaleString('en-ZA', { minimumFractionDigits: cents % 100 ? 2 : 0 }).replace(/\u00a0|\s/g, ',');
  const json = (data, status) => new Response(JSON.stringify(data), { status: status || 200, headers: { 'Content-Type': 'application/json' } });

  function nextFriday(skipToday) {
    const now = new Date();
    for (let i = skipToday ? 1 : 0; i <= 7; i++) {
      const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i, 8, 0, 0);
      if (d.getDay() === 5 && d > now) return d;
    }
    return null;
  }

  function label(d) {
    const now = new Date();
    const days = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()) - new Date(now.getFullYear(), now.getMonth(), now.getDate())) / 864e5);
    const day = d.toLocaleDateString('en-ZA', { weekday: 'long' });
    if (days === 0) return 'today at 08:00';
    if (days === 1) return 'tomorrow at 08:00';
    return (days >= 7 ? 'next ' : '') + day + ' at 08:00';
  }

  function status() {
    const next = nextFriday(state === 'open');
    return {
      open: state === 'open',
      reason: state === 'open' ? null : 'upcoming',
      next_roll: next ? { starts_at: next.toISOString(), starts_in: Math.round((next - new Date()) / 1000), label: label(next) } : null,
      test: false,
      logged_in: false,
      currency: 'ZAR',
      min_order: MIN_ORDER,
      banner: 'Spend R1,000, win up to 50% of your cart (max R1,500) off. T&Cs apply.',
      headline: 'Roll4It!',
      odds: 'One in every 10 entries wins.',
      disclaimer: 'Your number is just for fun. It does not change your odds: the result is decided when you roll.',
      spend_to_roll: 'Spend R1,000 to roll',
      one_roll: 'One roll per customer per day.',
      sold_out_message: "Today's prizes have all been won. Try again next promo day.",
      terms_url: '#terms',
      min_order_formatted: 'R1,000',
      max_payout_formatted: 'R1,500',
      promo_date: new Date().toISOString().slice(0, 10),
      previous_promo_date: null,
      today_stats: { winners: 3, total_payout: 337500, total_payout_formatted: 'R3,375', message: 'R3,375 given away today, 3 winners so far.' },
      animation: { die_color: '#1f2937', number_color: '#ffffff', accent_color: '#f59e0b', duration_ms: 2500, sound: true, celebration: true },
      drawer_selector: null,
    };
  }

  const cart = (codes) => ({
    token: 'demo-cart',
    currency: 'ZAR',
    items: demo.subtotal > 0 ? [{ variant_id: 1, quantity: 1, original_line_price: demo.subtotal, gift_card: false }] : [],
    discount_codes: codes || [],
  });

  async function roll(body) {
    await sleep(400);
    if (demo.subtotal < MIN_ORDER) {
      return json({ error: 'below_min_order', message: 'Your cart must be at least R1,000 (excluding gift cards) to roll.' }, 422);
    }
    const won = demo.outcome === 'win' || (demo.outcome === 'fair' && rand(10) === 1);
    const picked = Number(body.picked_number);
    let shown = picked;
    while (!won && shown === picked) shown = rand(10);
    const percentCap = Math.floor(demo.subtotal * MAX_PERCENT / 100);
    const payout = won ? Math.min(demo.subtotal, MAX_PAYOUT, percentCap) : 0;
    const potential = Math.min(demo.subtotal, MAX_PAYOUT, percentCap);
    const code = won ? 'ROLL-' + Math.random().toString(36).slice(2, 8).toUpperCase() : null;
    demo.lastPayout = payout;
    post({ type: 'rolled', won, payout });
    return json({
      repeat: false, test: false, won, displayed_number: shown, picked_number: picked,
      payout, payout_formatted: money(payout),
      potential_payout: won ? null : potential,
      potential_payout_formatted: won ? null : money(potential),
      code, code_ready: true, ends_at: null, channel: 'online',
      message: won
        ? 'You won ' + money(payout) + ' off your order! Code ' + code + ' has been applied to your cart.'
        : 'Not this time. Thanks for rolling, and good luck next time!',
    });
  }

  const realFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const path = url.replace(/^[a-z]+:\/\/[^/]*/i, '').split('?')[0];
    if (path.endsWith('/apps/roll-for-it/status')) return sleep(150).then(() => json(status()));
    if (path.endsWith('/apps/roll-for-it/roll')) return roll(JSON.parse(init.body));
    if (path === '/cart.js') return Promise.resolve(json(cart()));
    if (path === '/cart/update.js') {
      const code = JSON.parse(init.body).discount;
      post({ type: 'applied', code, payout: demo.lastPayout });
      return Promise.resolve(json(cart([{ code, applicable: true }])));
    }
    if (path.startsWith('/cart/')) return Promise.resolve(json(cart()));
    return realFetch(input, init);
  };

  window.addEventListener('message', (e) => {
    const msg = e.data || {};
    if (msg.target !== 'rfi-demo') return;
    if (msg.type === 'outcome') demo.outcome = msg.value;
    if (msg.type === 'cart') {
      demo.subtotal = msg.subtotal;
      window.fetch('/cart/change.js', { method: 'POST' });
    }
  });

  const report = () => post({ type: 'height', value: document.documentElement.scrollHeight });
  window.addEventListener('load', () => {
    if ('ResizeObserver' in window) new ResizeObserver(report).observe(document.body);
    report();
  });
})();
