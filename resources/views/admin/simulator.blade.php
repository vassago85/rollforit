@extends('layouts.admin', ['title' => 'Roll4It: Test lab'])

@push('scripts')
<style>
    .rfi-lab { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(340px, 1.6fr); gap: 20px; align-items: start; }
    @media (max-width: 760px) { .rfi-lab { grid-template-columns: 1fr; } }
    .rfi-lab-diag { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; margin-top: 10px; font-size: 0.9em; }
    .rfi-lab-diag dt { color: #475569; }
    .rfi-lab-diag dd { margin: 0; font-weight: 600; }
    .rfi-lab-diag dd.hit { color: #0b6b3b; }
    .rfi-lab-diag dd.warn { color: #b45309; font-weight: 700; }
    .rfi-lab-note { margin: 0 0 12px; font-size: 0.9em; color: #475569; }

    .rfi-casino {
        --rfi-chase: 1.2s;
        position: relative; padding: 30px 22px 24px; border-radius: 26px;
        background: radial-gradient(ellipse at 50% 30%, #1f7a4a 0%, #14532d 55%, #0a2e1a 100%);
        box-shadow: inset 0 0 0 10px #3b1d0e, inset 0 0 0 12px #a16207, inset 0 0 70px rgba(0, 0, 0, 0.55), 0 14px 34px rgba(15, 23, 42, 0.25);
        transition: box-shadow 0.3s ease;
    }
    .rfi-casino.is-rolling { --rfi-chase: 0.36s; }
    .rfi-casino.is-won { --rfi-chase: 0.18s; animation: rfi-jackpot 0.5s ease-in-out 6 alternate; }
    .rfi-casino.is-lost { --rfi-chase: 2.4s; }
    @keyframes rfi-jackpot {
        to { box-shadow: inset 0 0 0 10px #3b1d0e, inset 0 0 0 12px #facc15, inset 0 0 90px rgba(250, 204, 21, 0.35), 0 0 40px 6px rgba(250, 204, 21, 0.55); }
    }
    .rfi-bulb {
        position: absolute; width: 8px; height: 8px; margin: -4px 0 0 -4px; border-radius: 50%;
        background: #92400e; animation: rfi-bulb var(--rfi-chase) steps(1) infinite;
    }
    .rfi-bulb:nth-child(odd) { animation-delay: calc(var(--rfi-chase) / -2); }
    @keyframes rfi-bulb {
        0% { background: #fde68a; box-shadow: 0 0 8px 2px rgba(253, 224, 71, 0.85); }
        50% { background: #92400e; box-shadow: none; }
    }
    .rfi-casino-sign {
        margin: 0 0 14px; text-align: center; font-weight: 800; font-size: 0.85em; letter-spacing: 0.22em; text-transform: uppercase;
        color: #fef3c7; text-shadow: 0 0 6px #f59e0b, 0 0 16px #f59e0b;
    }
    .rfi-casino.is-won .rfi-casino-sign { color: #fff; font-size: 1em; text-shadow: 0 0 8px #facc15, 0 0 22px #facc15, 0 0 34px #f97316; }
    .rfi-casino.is-lost .rfi-casino-sign { color: #e5e7eb; text-shadow: 0 0 6px rgba(148, 163, 184, 0.8); }
    .rfi-casino-frame { display: block; width: 100%; height: 560px; border: 0; background: transparent; }
    .rfi-casino-empty { color: #d1fae5; text-align: center; padding: 80px 20px; font-style: italic; }
    .rfi-casino-foot { margin: 12px 0 0; text-align: center; color: #bbf7d0; font-size: 0.8em; }

    .rfi-tally { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; text-align: center; }
    .rfi-tally b { display: block; font-size: 1.35em; font-variant-numeric: tabular-nums; }
    .rfi-tally small { color: #475569; font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.05em; }

    @media (prefers-reduced-motion: reduce) {
        .rfi-bulb, .rfi-casino.is-won { animation: none; }
        .rfi-bulb:nth-child(odd) { background: #fde68a; }
    }
</style>
@endpush

@section('content')
<s-page heading="Test lab">
    <s-banner tone="info" heading="This is a sandbox">
        <s-paragraph>The table plays the real storefront widget: the 3D die, the sound, confetti on a win, the countdown to the next roll, the streak badge and the "could have won" teaser. Nothing reaches a real customer. No discount codes are created, no entries are recorded, your daily budget is untouched, and every code starts with <b>SIM-</b>.</s-paragraph>
    </s-banner>

    <s-banner id="lab-blockers" tone="warning" heading="Finish setup before testing" hidden>
        <s-unordered-list id="lab-blockers-list"></s-unordered-list>
    </s-banner>

    <s-section heading="Test table">
        <div class="rfi-lab">
            <div>
                <s-stack gap="base">
                    <s-number-field id="lab-cart" label="Cart value" prefix="R" min="0.01" step="0.01" details="What the eligible items in the simulated cart are worth."></s-number-field>
                    <s-select id="lab-outcome" label="Outcome">
                        <s-option value="random">Use the real odds</s-option>
                        <s-option value="win" selected>Force a win</s-option>
                        <s-option value="loss">Force a loss</s-option>
                    </s-select>
                    <s-select id="lab-channel" label="Channel">
                        <s-option value="online" selected>Online storefront</s-option>
                        <s-option value="pos">Point of sale (POS)</s-option>
                    </s-select>
                    <s-select id="lab-customer" label="Customer">
                        <s-option value="guest" selected>Guest (enters an email)</s-option>
                        <s-option value="signed_in">Signed in to their account</s-option>
                    </s-select>
                    <s-number-field id="lab-streak" label="Streak" min="1" max="60" step="1" value="3" details="Promo days in a row this customer has rolled, counting this roll. Two or more shows the streak badge."></s-number-field>
                    <s-button id="lab-deal" variant="primary">New roll</s-button>
                </s-stack>

                <s-box padding="base" background="subdued" borderRadius="base" style="margin-top:16px">
                    <s-stack gap="small-200">
                        <s-text font-weight="bold">This session</s-text>
                        <div class="rfi-tally">
                            <div><b id="lab-tally-rolls">0</b><small>Rolls</small></div>
                            <div><b id="lab-tally-wins">0</b><small>Wins</small></div>
                            <div><b id="lab-tally-rate">-</b><small>Win rate</small></div>
                            <div><b id="lab-tally-paid">-</b><small>Paid out</small></div>
                        </div>
                    </s-stack>
                </s-box>

                <s-box padding="base" background="subdued" borderRadius="base" style="margin-top:16px">
                    <s-stack gap="small-200">
                        <s-text font-weight="bold">Current caps</s-text>
                        <dl class="rfi-lab-diag" id="lab-caps">
                            <dt>Min order</dt><dd id="lab-cap-min">-</dd>
                            <dt>Max payout</dt><dd id="lab-cap-max">-</dd>
                            <dt>Max % of cart</dt><dd id="lab-cap-pct">-</dd>
                            <dt>Odds</dt><dd id="lab-cap-odds">-</dd>
                            <dt>Dice</dt><dd id="lab-cap-dice">-</dd>
                        </dl>
                    </s-stack>
                </s-box>

                <s-box padding="base" background="subdued" borderRadius="base" style="margin-top:16px">
                    <s-stack gap="small-200">
                        <s-text font-weight="bold">What the widget sees</s-text>
                        <p class="rfi-lab-note" style="margin:0">The actual dice count and pick range the widget just drew. If this disagrees with the Current caps card above, the widget is out of sync — fix it by starting a new roll.</p>
                        <dl class="rfi-lab-diag" id="lab-seen">
                            <dt>Dice drawn</dt><dd id="lab-seen-dice">waiting for the widget…</dd>
                            <dt>Pick buttons</dt><dd id="lab-seen-range">—</dd>
                            <dt>Roll button</dt><dd id="lab-seen-label">—</dd>
                        </dl>
                    </s-stack>
                </s-box>
            </div>

            <div class="rfi-casino" id="lab-casino">
                <p class="rfi-casino-sign" id="lab-sign">Roll4It · Test table</p>
                <div class="rfi-casino-empty" id="lab-empty">Shuffling the table…</div>
                <iframe class="rfi-casino-frame" id="lab-frame" title="Roll4It as your customers see it" hidden></iframe>
                <p class="rfi-casino-foot" id="lab-foot" hidden></p>
            </div>
        </div>
    </s-section>

    <s-section heading="Diagnostics" id="lab-diag-section" hidden>
        <s-stack gap="base">
            <p class="rfi-lab-note">What the maths said, step by step, for the roll you just played.</p>
            <dl class="rfi-lab-diag" id="lab-diag"></dl>
        </s-stack>
    </s-section>
</s-page>
@endsection

@push('scripts')
<script>
(() => {
  const { api, el, esc, centsToMoney, moneyToCents, toast } = RFI;
  const frame = el('lab-frame');
  const casino = el('lab-casino');
  const tally = { rolls: 0, wins: 0, paid: 0 };
  let state = null;
  let rolling = false;
  let landing = null;

  function hangLights() {
    const spots = [];
    for (let i = 0; i < 16; i++) spots.push([8 + i * 84 / 15 + '%', '5px']);
    for (let i = 0; i < 9; i++) spots.push(['calc(100% - 5px)', 10 + i * 80 / 8 + '%']);
    for (let i = 15; i >= 0; i--) spots.push([8 + i * 84 / 15 + '%', 'calc(100% - 5px)']);
    for (let i = 8; i >= 0; i--) spots.push(['5px', 10 + i * 80 / 8 + '%']);
    const lights = document.createElement('div');
    lights.setAttribute('aria-hidden', 'true');
    lights.innerHTML = spots.map(([left, top]) => '<span class="rfi-bulb" style="left:' + left + ';top:' + top + '"></span>').join('');
    casino.prepend(lights);
  }

  function setTable(mode, sign) {
    casino.classList.remove('is-rolling', 'is-won', 'is-lost');
    if (mode) casino.classList.add('is-' + mode);
    el('lab-sign').textContent = sign;
  }

  function setCaps() {
    const s = state.settings;
    const sym = state.shop.symbol;
    el('lab-cap-min').textContent = s.min_order ? centsToMoney(s.min_order, sym) : 'not set';
    el('lab-cap-max').textContent = s.max_payout ? centsToMoney(s.max_payout, sym) : 'not set';
    el('lab-cap-pct').textContent = s.max_payout_percent ? (s.max_payout_percent + '%') : 'no percentage cap';
    el('lab-cap-odds').textContent = (s.odds_mode === 'guaranteed' ? '1 in every ' : '1 in ') + s.odds_n + (s.odds_mode === 'guaranteed' ? ' entries' : ' chance');
    el('lab-cap-dice').textContent = s.dice_count === 2
      ? '2 dice (pick ' + s.pick_range.min + '–' + s.pick_range.max + ')'
      : '1 die (pick ' + s.pick_range.min + '–' + s.pick_range.max + ')';
    if (s.min_order && !el('lab-cart').value) {
      el('lab-cart').value = String(Math.round(s.min_order * 1.2) / 100);
    }
  }

  function pickCopy() {
    const s = state && state.settings;
    if (s && s.dice_count === 2) return 'Pick a sum · Roll the dice';
    return 'Pick a number · Roll the die';
  }

  function renderBlockers(list) {
    if (!list || !list.length) return false;
    el('lab-blockers').hidden = false;
    el('lab-blockers-list').innerHTML = list.map((i) => '<s-list-item>' + esc(i) + '</s-list-item>').join('');
    el('lab-deal').disabled = true;
    el('lab-empty').textContent = 'The table opens once setup is finished.';
    return true;
  }

  function renderTally() {
    el('lab-tally-rolls').textContent = tally.rolls;
    el('lab-tally-wins').textContent = tally.wins;
    el('lab-tally-rate').textContent = tally.rolls ? Math.round(tally.wins / tally.rolls * 100) + '%' : '-';
    el('lab-tally-paid').textContent = tally.rolls ? centsToMoney(tally.paid, state.shop.symbol) : '-';
  }

  /* Fed by the `rendered` message the iframe posts after every pick-form draw.
     Compare what the widget actually drew to what the shop's settings say and
     flag any disagreement, so a dice_count=2 shop never silently renders a
     1-die grid without the merchant noticing. */
  function renderSeen(seen) {
    if (!state) return;
    const s = state.settings;
    const diceOk = Number(seen.dice_count) === Number(s.dice_count);
    const rangeOk = Number(seen.pick_min) === Number(s.pick_range.min) && Number(seen.pick_max) === Number(s.pick_range.max);
    const warn = (txt, ok) => ok ? esc(txt) : '⚠ ' + esc(txt) + ' (settings say ';
    el('lab-seen-dice').textContent = (diceOk ? '' : '⚠ ') + seen.dice_count + (seen.dice_count === 2 ? ' dice' : ' die')
      + (diceOk ? '' : ' — settings say ' + s.dice_count);
    el('lab-seen-range').textContent = (rangeOk ? '' : '⚠ ') + seen.pick_min + '–' + seen.pick_max + ' (' + seen.pick_count + ' buttons)'
      + (rangeOk ? '' : ' — settings say ' + s.pick_range.min + '–' + s.pick_range.max);
    el('lab-seen-label').textContent = seen.roll_button_label || '—';
    el('lab-seen-dice').className = diceOk ? '' : 'warn';
    el('lab-seen-range').className = rangeOk ? '' : 'warn';
  }

  function renderDiagnostics(d, result) {
    el('lab-diag-section').hidden = false;
    const rows = [
      ['Picked number', esc(result.picked_number), ''],
      ['Die landed on', esc(result.displayed_number), ''],
      ['Cart subtotal', esc(d.cart_subtotal_formatted), ''],
      ['Minimum order', esc(d.min_order_formatted), ''],
      ['Max payout cap', esc(d.max_payout_formatted), d.binding_cap === 'max_payout' ? 'hit' : ''],
      ['Percent cap', d.max_payout_percent ? (d.max_payout_percent + '% = ' + esc(d.percent_cap_formatted)) : 'none', d.binding_cap === 'percent' ? 'hit' : ''],
      ['Cart subtotal (as cap)', esc(d.cart_subtotal_formatted), d.binding_cap === 'cart' ? 'hit' : ''],
      ['Which cap bit this roll', d.binding_cap === 'none' ? '(loss, no cap applied)' : d.binding_cap.replace('_', ' '), ''],
      ['Odds statement', esc(d.odds), ''],
      ['Channel', esc(d.channel), ''],
    ];
    el('lab-diag').innerHTML = rows.map(([k, v, cls]) =>
      '<dt>' + esc(k) + '</dt><dd class="' + cls + '">' + v + '</dd>'
    ).join('');
  }

  const send = (msg) => frame.contentWindow && frame.contentWindow.postMessage(Object.assign({ source: 'rfi-lab' }, msg), location.origin);
  const cartCents = () => moneyToCents(el('lab-cart').value) || 0;

  function frameConfig() {
    // The caps block above reads dice_count/pick_range straight from state.settings.
    // Overlay the same values onto the widget status so the two displays can never
    // disagree — if a stale `widget.status.dice_count` ever slips in (e.g. an older
    // cached simulator payload), the merchant still sees the widget match the caps.
    const s = state.settings;
    return {
      status: Object.assign({}, state.widget.status, {
        logged_in: el('lab-customer').value === 'signed_in',
        dice_count: s.dice_count,
        pick_range: s.pick_range,
      }),
      cart: cartCents(),
      streak: parseInt(el('lab-streak').value, 10) || 1,
      email: 'customer@example.com',
      storefront_url: state.shop.storefront_url,
    };
  }

  async function deal() {
    if (rolling) return;
    // Pull fresh settings on every deal so the merchant can tweak Dice / Odds in
    // another browser tab and immediately see the Test lab reflect it, without
    // having to hard-reload the embedded admin.
    try { state = await api('GET', 'simulator'); setCaps(); } catch (e) { /* keep last state */ }
    if (!state || !state.widget) return;
    landing = null;
    setTable(null, pickCopy());
    el('lab-seen-dice').textContent = 'waiting for the widget…';
    el('lab-seen-dice').className = '';
    el('lab-seen-range').textContent = '—';
    el('lab-seen-range').className = '';
    el('lab-seen-label').textContent = '—';
    send({ type: 'config', config: frameConfig() });
  }

  async function onRoll(msg) {
    rolling = true;
    el('lab-deal').disabled = true;
    setTable('rolling', 'No more bets…');
    let reply;
    try {
      const data = await api('POST', 'simulator/roll', {
        cart_value: el('lab-cart').value,
        outcome: el('lab-outcome').value,
        picked_number: msg.picked_number,
        channel: el('lab-channel').value,
      });
      landing = data;
      reply = { status: 200, body: Object.assign({}, data.result, { test: false }) };
    } catch (e) {
      rolling = false;
      el('lab-deal').disabled = false;
      setTable(null, pickCopy());
      reply = { status: e.status || 500, body: { message: e.message || 'Could not run the roll.' } };
    }
    send({ type: 'rolled', id: msg.id, status: reply.status, body: reply.body });
  }

  function onLanded() {
    rolling = false;
    el('lab-deal').disabled = false;
    if (!landing) return;
    const result = landing.result;
    tally.rolls++;
    if (result.won) { tally.wins++; tally.paid += result.payout; }
    renderTally();
    renderDiagnostics(landing.diagnostics, result);
    setTable(result.won ? 'won' : 'lost', result.won ? 'Winner! ' + result.payout_formatted + ' off' : 'The house wins this one');
  }

  window.addEventListener('message', (e) => {
    if (e.source !== frame.contentWindow || e.origin !== location.origin) return;
    const msg = e.data || {};
    if (msg.source !== 'rfi-lab-frame') return;
    if (msg.type === 'hello') deal();
    else if (msg.type === 'height') frame.style.height = Math.max(320, msg.value) + 'px';
    else if (msg.type === 'roll') onRoll(msg);
    else if (msg.type === 'landed') onLanded();
    else if (msg.type === 'rendered') renderSeen(msg.rendered);
    else if (msg.type === 'share') toast('On a real win the customer shares: "' + msg.text + '"');
  });

  async function load() {
    try {
      state = await api('GET', 'simulator');
    } catch (e) {
      el('lab-empty').textContent = e.message;
      return;
    }
    setCaps();
    if (renderBlockers(state.blockers) || !state.widget) return;
    if (state.widget.example_social_proof) {
      el('lab-foot').hidden = false;
      el('lab-foot').textContent = 'The "given away today" line uses example numbers until someone wins for real today.';
    }
    el('lab-empty').hidden = true;
    frame.hidden = false;
    frame.src = '/admin/lab-frame.html';
  }

  el('lab-deal').addEventListener('click', deal);
  el('lab-cart').addEventListener('input', () => send({ type: 'cart', cart: cartCents() }));
  el('lab-customer').addEventListener('change', deal);
  el('lab-streak').addEventListener('change', deal);

  // Shopify Admin is a SPA and happily keeps this page's DOM alive when the
  // merchant navigates away and back (or into a modal). Without this, state
  // stays frozen from first load — a dice_count change made elsewhere never
  // reaches the Test lab until a hard reload. Re-fetch on every return.
  async function refreshIfStale() {
    if (document.visibilityState !== 'visible') return;
    try {
      state = await api('GET', 'simulator');
      setCaps();
      if (frame && !frame.hidden) send({ type: 'config', config: frameConfig() });
    } catch (e) { /* keep last state so a transient blip doesn't blank the lab */ }
  }
  document.addEventListener('visibilitychange', refreshIfStale);
  window.addEventListener('pageshow', (e) => { if (e.persisted) refreshIfStale(); });

  hangLights();
  load();
})();
</script>
@endpush
