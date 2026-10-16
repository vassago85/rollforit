(() => {
  const MIN_ORDER = 100000;
  const $ = (id) => document.getElementById(id);
  const money = (cents) => 'R' + Math.round(cents / 100).toLocaleString('en-ZA').replace(/\u00a0|\s/g, ',');

  /* Pretend cart */
  const products = [
    { name: 'Tactical backpack 45L', price: 129900, qty: 1, colour: '#4d5b3a', initials: 'BP' },
    { name: 'Two-point rifle sling', price: 65000, qty: 1, colour: '#7c5a2e', initials: 'SL' },
    { name: 'Shooting gloves', price: 34900, qty: 0, colour: '#3f3f46', initials: 'GL' },
  ];
  let outcome = 'fair';
  let applied = null;
  const tally = { rolls: 0, wins: 0 };

  const frames = { open: $('frame-open'), upcoming: $('frame-upcoming') };
  const subtotal = () => products.reduce((s, p) => s + p.price * p.qty, 0);
  const send = (frame, msg) => frame.contentWindow && frame.contentWindow.postMessage(Object.assign({ target: 'rfi-demo' }, msg), '*');

  function renderCart() {
    $('cart-lines').innerHTML = products.map((p, i) => '<div class="line">'
      + '<div class="thumb" style="background:' + p.colour + '">' + p.initials + '</div>'
      + '<div><div class="name">' + p.name + '</div><div class="price">' + money(p.price) + '</div>'
      + '<div class="qty"><button type="button" data-i="' + i + '" data-d="-1" aria-label="Fewer ' + p.name + '">−</button>'
      + '<output>' + p.qty + '</output>'
      + '<button type="button" data-i="' + i + '" data-d="1" aria-label="More ' + p.name + '">+</button></div></div>'
      + '<div class="line-total">' + money(p.price * p.qty) + '</div></div>').join('');

    const sub = subtotal();
    const valid = applied && sub >= MIN_ORDER;
    const discount = valid ? Math.min(applied.payout, sub) : 0;
    $('subtotal').textContent = money(sub);
    $('discount-row').hidden = !applied;
    $('discount-label').textContent = applied
      ? (valid ? 'Code ' + applied.code : 'Code ' + applied.code + ' needs ' + money(MIN_ORDER) + ' in the cart')
      : '';
    $('discount').textContent = '−' + money(discount);
    $('total').textContent = money(sub - discount);
  }

  $('cart-lines').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-i]');
    if (!b) return;
    const p = products[Number(b.dataset.i)];
    p.qty = Math.max(0, Math.min(9, p.qty + Number(b.dataset.d)));
    renderCart();
    send(frames.open, { type: 'cart', subtotal: subtotal() });
  });

  function loadFrame(state) {
    const q = new URLSearchParams({ state, subtotal: String(subtotal()), outcome });
    frames[state].src = '/demo/widget.html?' + q.toString();
  }

  document.querySelectorAll('[data-outcome]').forEach((b) => b.addEventListener('click', () => {
    outcome = b.dataset.outcome;
    document.querySelectorAll('[data-outcome]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    send(frames.open, { type: 'outcome', value: outcome });
  }));

  $('roll-again').addEventListener('click', () => {
    applied = null;
    renderCart();
    loadFrame('open');
  });

  window.addEventListener('message', (e) => {
    const msg = e.data || {};
    if (msg.source !== 'rfi-demo') return;
    const frame = Object.values(frames).find((f) => f.contentWindow === e.source);
    if (msg.type === 'height' && frame) frame.style.height = Math.max(msg.value, 200) + 'px';
    if (msg.type === 'rolled') {
      tally.rolls++;
      if (msg.won) tally.wins++;
      $('tally').innerHTML = 'Rolls: <b>' + tally.rolls + '</b> · Wins: <b>' + tally.wins + '</b>';
    }
    if (msg.type === 'applied') {
      applied = { code: msg.code, payout: msg.payout };
      renderCart();
    }
  });

  renderCart();
  loadFrame('open');
  loadFrame('upcoming');

  /* Cost calculator */
  const num = (id) => Math.max(0, Number($(id).value) || 0);
  function calc() {
    const min = num('c-min') * 100;
    const max = num('c-max') * 100;
    const pct = num('c-pct');
    const n = Number($('c-n').value);
    const rolls = num('c-rolls');
    const aov = num('c-aov') * 100;
    const budget = num('c-budget') * 100;
    const days = num('c-days') || 1;

    const percentCap = pct > 0 && aov > 0 ? Math.floor(aov * pct / 100) : Infinity;
    const typicalWin = Math.min(aov || max, max, percentCap === Infinity ? max : percentCap);
    const winners = rolls / n;
    let perDay = winners * typicalWin;
    const capped = budget > 0 && perDay > budget;
    if (capped) perDay = budget;
    const revenue = rolls * aov;

    $('o-per-roll').textContent = money(max / n);
    $('o-winners').textContent = winners.toLocaleString('en-ZA', { maximumFractionDigits: 1 });
    $('o-day').textContent = money(perDay);
    $('o-revenue').textContent = money(revenue);
    $('o-pct').textContent = revenue > 0 ? (perDay / revenue * 100).toFixed(1) + '%' : '–';
    $('o-month').textContent = money(perDay * days);

    const notes = [
      'Each roll costs you at most ' + money(max / n) + ' on average: the maximum prize divided by ' + n + '.',
      'A typical win on a ' + money(aov) + ' cart is ' + money(typicalWin)
        + (pct > 0 ? ' (capped at ' + pct + '% of the cart, and at ' + money(max) + ').' : ' (capped at ' + money(max) + ').'),
    ];
    if (capped) notes.push('Your daily budget kicks in: rolling closes for the day once ' + money(budget) + ' has been won.');
    if (max > 0 && min > 0 && max < min) notes.push('The maximum prize is below the minimum spend, so winners always still pay something.');
    if (aov > 0 && aov < min) notes.push('The average cart is below the minimum spend. Everyone who rolls has at least ' + money(min) + ' in their cart.');
    $('o-note').textContent = notes.join(' ');
  }
  document.querySelectorAll('.calc input, .calc select').forEach((i) => i.addEventListener('input', calc));
  calc();
})();
