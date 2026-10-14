@extends('layouts.admin', ['title' => 'Roll4It: Test lab'])

@push('scripts')
<link rel="stylesheet" href="{{ asset('demo/assets/rfi-widget.css') }}">
<style>
    .rfi-lab { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(320px, 1.5fr); gap: 20px; align-items: start; }
    @media (max-width: 760px) { .rfi-lab { grid-template-columns: 1fr; } }
    .rfi-lab-card { border: 1px solid #e3e3e3; border-radius: 10px; padding: 16px; background: #fff; }
    .rfi-lab-preview { min-height: 360px; display: flex; align-items: center; justify-content: center; }
    .rfi-lab-preview .rfi-root { width: 100%; margin: 0; }
    .rfi-lab-diag { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; margin-top: 10px; font-size: 0.9em; }
    .rfi-lab-diag dt { color: #475569; }
    .rfi-lab-diag dd { margin: 0; font-weight: 600; }
    .rfi-lab-diag dd.hit { color: #0b6b3b; }
    .rfi-lab-note { margin: 0 0 12px; font-size: 0.9em; color: #475569; }
    .rfi-lab-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #e0e7ff; color: #1e3a8a; font-size: 0.75em; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; margin-left: 8px; vertical-align: middle; }
    .rfi-lab-empty { color: #64748b; font-style: italic; text-align: center; padding: 60px 20px; }
</style>
@endpush

@section('content')
<s-page heading="Test lab">
    <s-banner tone="info" heading="This is a sandbox">
        <s-paragraph>Nothing you do here reaches a real customer. No discount codes are created, no entries are recorded, your daily budget is untouched, and the generated code starts with <b>SIM-</b> so it can never be mistaken for a real one. Use it to see exactly what a customer sees, double-check your caps, and sanity-check the result copy.</s-paragraph>
    </s-banner>

    <s-banner id="lab-blockers" tone="warning" heading="Finish setup before testing" hidden>
        <s-unordered-list id="lab-blockers-list"></s-unordered-list>
    </s-banner>

    <s-section heading="Controls">
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
                    <s-number-field id="lab-picked" label="Picked number" min="1" max="10" step="1" details="The number the customer chooses on the die face. It never changes the outcome."></s-number-field>
                    <s-button id="lab-run" variant="primary">Run the roll</s-button>
                </s-stack>

                <s-box padding="base" background="subdued" borderRadius="base" style="margin-top:16px">
                    <s-stack gap="small-200">
                        <s-text font-weight="bold">Current caps</s-text>
                        <dl class="rfi-lab-diag" id="lab-caps">
                            <dt>Min order</dt><dd id="lab-cap-min">-</dd>
                            <dt>Max payout</dt><dd id="lab-cap-max">-</dd>
                            <dt>Max % of cart</dt><dd id="lab-cap-pct">-</dd>
                            <dt>Odds</dt><dd id="lab-cap-odds">-</dd>
                        </dl>
                    </s-stack>
                </s-box>
            </div>

            <div class="rfi-lab-card rfi-lab-preview">
                <div class="rfi-lab-empty" id="lab-empty">Run a roll to see what the customer sees.</div>
                <div class="rfi-root" id="lab-widget" hidden></div>
            </div>
        </div>
    </s-section>

    <s-section heading="Diagnostics" id="lab-diag-section" hidden>
        <s-stack gap="base">
            <p class="rfi-lab-note">What the maths said, step by step, for the roll you just simulated.</p>
            <dl class="rfi-lab-diag" id="lab-diag"></dl>
        </s-stack>
    </s-section>
</s-page>
@endsection

@push('scripts')
<script>
(() => {
  const { api, el, esc, centsToMoney, toast, showErrors } = RFI;
  let state = null;

  function setCapsFromState() {
    if (!state) return;
    const s = state.settings;
    const sym = state.shop.symbol;
    el('lab-cap-min').textContent = s.min_order ? centsToMoney(s.min_order, sym) : 'not set';
    el('lab-cap-max').textContent = s.max_payout ? centsToMoney(s.max_payout, sym) : 'not set';
    el('lab-cap-pct').textContent = s.max_payout_percent ? (s.max_payout_percent + '%') : 'no percentage cap';
    el('lab-cap-odds').textContent = (s.odds_mode === 'guaranteed' ? '1 in every ' : '1 in ') + s.odds_n + (s.odds_mode === 'guaranteed' ? ' entries' : ' chance');
    if (s.min_order && !el('lab-cart').value) {
      el('lab-cart').value = String(Math.round(s.min_order * 1.2) / 100);
    }
    if (!el('lab-picked').value) el('lab-picked').value = '7';
  }

  function renderBlockers(list) {
    const banner = el('lab-blockers');
    const target = el('lab-blockers-list');
    if (!list || !list.length) { banner.hidden = true; return; }
    banner.hidden = false;
    target.innerHTML = list.map((i) => '<s-list-item>' + esc(i) + '</s-list-item>').join('');
    el('lab-run').disabled = true;
  }

  async function load() {
    try {
      state = await api('GET', 'simulator');
      setCapsFromState();
      renderBlockers(state.blockers);
    } catch (e) {
      toast(e.message, true);
    }
  }

  const CSS_SPAN = (n) => '<span>' + esc(n) + '</span>';

  function dieHtml(number, settled) {
    const cls = 'rfi-cssdie' + (settled ? ' rfi-cssdie--landed' : ' rfi-cssdie--rolling');
    return '<div class="' + cls + '" style="--rfi-die:#1f2937;--rfi-num:#fff">' + CSS_SPAN(settled ? number : '?') + '</div>';
  }

  function renderResult(result) {
    const host = el('lab-widget');
    el('lab-empty').hidden = true;
    host.hidden = false;
    const banner = state.settings.max_payout_percent
      ? 'Simulated roll <span class="rfi-lab-badge">SIM</span>'
      : 'Simulated roll <span class="rfi-lab-badge">SIM</span>';
    const stage = '<div class="rfi-stage">' + dieHtml(result.displayed_number, true) + '</div>';
    const msgCls = 'rfi-msg rfi-msg--' + (result.won ? 'win' : 'lose');
    const codeLine = result.code
      ? '<p class="rfi-note">Simulated code: <b>' + esc(result.code) + '</b> (not a real discount)</p>'
      : '';
    host.innerHTML =
      '<div class="rfi-card" style="--rfi-accent:#f59e0b">'
      + '<p class="rfi-banner">' + banner + '</p>'
      + '<h3 class="rfi-title">Roll4It <span class="rfi-test">Test mode</span></h3>'
      + '<div class="rfi-body">'
      + stage
      + '<p class="' + msgCls + '">' + esc(result.message) + '</p>'
      + codeLine
      + '</div>'
      + '</div>';
  }

  function renderDiagnostics(d) {
    const section = el('lab-diag-section');
    section.hidden = false;
    const rows = [
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

  async function run() {
    showErrors({});
    el('lab-run').loading = true;
    try {
      const body = {
        cart_value: el('lab-cart').value,
        outcome: el('lab-outcome').value,
        picked_number: parseInt(el('lab-picked').value, 10) || 7,
        channel: el('lab-channel').value,
      };
      const data = await api('POST', 'simulator/roll', body);
      renderResult(data.result);
      renderDiagnostics(data.diagnostics);
    } catch (e) {
      if (e.status === 422 && e.errors) { showErrors(e.errors); return; }
      el('lab-widget').hidden = true;
      el('lab-empty').hidden = false;
      el('lab-empty').textContent = e.message || 'Could not run the roll.';
    } finally {
      el('lab-run').loading = false;
    }
  }

  el('lab-run').addEventListener('click', run);
  load();
})();
</script>
@endpush
