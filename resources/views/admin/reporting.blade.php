@extends('layouts.admin', ['title' => 'Roll for It: Reporting'])

@section('content')
<s-page heading="Reporting">
    <s-button slot="primary-action" id="refresh">Refresh</s-button>
    <s-banner id="error" tone="critical" hidden></s-banner>

    <s-section heading="Today">
        <s-stack gap="base">
            <s-stack direction="inline" gap="small">
                <s-badge id="today-open"></s-badge>
                <s-badge id="today-closed" tone="warning" hidden></s-badge>
            </s-stack>
            <s-grid gridTemplateColumns="repeat(4, 1fr)" gap="base">
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Rolls</s-text><s-heading id="t-rolls">0</s-heading></s-box>
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Winners</s-text><s-heading id="t-winners">0</s-heading></s-box>
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Prizes issued</s-text><s-heading id="t-payout">R0</s-heading></s-box>
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Budget left</s-text><s-heading id="t-budget">No limit</s-heading></s-box>
            </s-grid>
            <div class="rfi-meter" id="budget-meter" hidden><span id="budget-bar" style="width:0"></span></div>
            <s-paragraph id="block-info" hidden></s-paragraph>
        </s-stack>
    </s-section>

    <s-section heading="Revenue vs prizes">
        <s-stack gap="base">
            <s-paragraph color="subdued">Orders from the promo window start to midnight (codes stay valid all day). Test and cancelled orders are excluded; amounts are after refunds. Shopify only lets apps read the last 60 days of orders, so each promo day is saved automatically 30 minutes after it ends.</s-paragraph>
            <s-banner id="today-metrics-error" tone="warning" hidden></s-banner>
            <s-box id="today-metrics" padding="base" background="subdued" borderRadius="base" hidden>
                <s-stack gap="small-200">
                    <s-stack direction="inline" gap="small" alignItems="center">
                        <s-heading>Today so far</s-heading>
                        <s-text id="tm-updated" color="subdued"></s-text>
                        <s-button id="tm-refresh" variant="tertiary">Update now</s-button>
                    </s-stack>
                    <s-paragraph id="tm-summary"></s-paragraph>
                </s-stack>
            </s-box>
            <s-stack direction="inline" gap="base" alignItems="end">
                <s-date-field id="from" label="From"></s-date-field>
                <s-date-field id="to" label="To"></s-date-field>
                <s-button id="apply-range">Apply</s-button>
                <s-button id="export" variant="secondary">Export CSV</s-button>
            </s-stack>
            <s-grid gridTemplateColumns="repeat(4, 1fr)" gap="base">
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Revenue</s-text><s-heading id="c-revenue">R0</s-heading></s-box>
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Prizes redeemed</s-text><s-heading id="c-redeemed">R0</s-heading><s-text id="c-issued" color="subdued"></s-text></s-box>
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Prizes as % of revenue</s-text><s-heading id="c-pct">–</s-heading></s-box>
                <s-box padding="base" background="subdued" borderRadius="base"><s-text color="subdued">Revenue after prizes</s-text><s-heading id="c-after">R0</s-heading></s-box>
            </s-grid>
            <s-paragraph id="c-extra"></s-paragraph>
            <s-table>
                <s-table-header-row>
                    <s-table-header>Promo day</s-table-header>
                    <s-table-header>Orders</s-table-header>
                    <s-table-header>Revenue</s-table-header>
                    <s-table-header>Roller orders</s-table-header>
                    <s-table-header>Winner orders</s-table-header>
                    <s-table-header>Prizes issued</s-table-header>
                    <s-table-header>Prizes redeemed</s-table-header>
                    <s-table-header>% of revenue</s-table-header>
                    <s-table-header></s-table-header>
                </s-table-header-row>
                <s-table-body id="metrics-days"></s-table-body>
            </s-table>
        </s-stack>
    </s-section>

    <s-section heading="Rolls today">
        <s-stack gap="base">
            <s-stack direction="inline" gap="small" alignItems="center">
                <s-text color="subdued">Filter:</s-text>
                <s-button id="chan-all" variant="primary">All</s-button>
                <s-button id="chan-online" variant="tertiary">Online</s-button>
                <s-button id="chan-pos" variant="tertiary">POS</s-button>
                <s-text id="chan-summary" color="subdued"></s-text>
            </s-stack>
        <s-table>
            <s-table-header-row>
                <s-table-header>Time</s-table-header>
                <s-table-header>Channel</s-table-header>
                <s-table-header>Cart</s-table-header>
                <s-table-header>Picked</s-table-header>
                <s-table-header>Shown</s-table-header>
                <s-table-header>Result</s-table-header>
                <s-table-header>Code</s-table-header>
            </s-table-header-row>
            <s-table-body id="recent"></s-table-body>
        </s-table>
        </s-stack>
    </s-section>

    <s-section heading="History">
        <s-table>
            <s-table-header-row>
                <s-table-header>Promo day</s-table-header>
                <s-table-header>Rolls</s-table-header>
                <s-table-header>Winners</s-table-header>
                <s-table-header>Prizes issued</s-table-header>
            </s-table-header-row>
            <s-table-body id="history"></s-table-body>
        </s-table>
    </s-section>

    <s-section heading="Privacy requests">
        <s-paragraph color="subdued">Customer data requests and deletions sent by Shopify. Deletions are handled automatically. For data requests, tell the customer that we hold only a hashed email, the IP address (kept up to 90 days), cart totals, the outcome and any discount code.</s-paragraph>
        <s-table>
            <s-table-header-row>
                <s-table-header>Received</s-table-header>
                <s-table-header>Type</s-table-header>
                <s-table-header>Customer ID</s-table-header>
                <s-table-header>Matching rolls</s-table-header>
            </s-table-header-row>
            <s-table-body id="privacy"></s-table-body>
        </s-table>
    </s-section>
</s-page>
@endsection

@push('scripts')
<script>
(() => {
  const { api, download, toast, el, esc } = RFI;
  const cell = (v) => '<s-table-cell>' + v + '</s-table-cell>';
  const row = (cells) => '<s-table-row>' + cells.map(cell).join('') + '</s-table-row>';
  const empty = (n, text) => row([esc(text)].concat(Array(n - 1).fill('')));
  const pct = (v) => (v === null || v === undefined ? '–' : v + '%');

  function renderReport(d) {
    const t = d.today;
    el('today-open').textContent = t.open ? 'Running now' : (t.is_promo_day ? 'Promo day, not running now' : 'Not a promo day');
    el('today-open').tone = t.open ? 'success' : 'neutral';
    el('today-closed').hidden = !t.closed_reason;
    el('today-closed').textContent = t.closed_reason === 'budget' ? 'Closed: daily budget used' : 'Closed: max winners reached';
    el('t-rolls').textContent = t.rolls;
    el('t-winners').textContent = t.winners + (t.max_winners_per_day ? ' / ' + t.max_winners_per_day : '');
    el('t-payout').textContent = t.payout_formatted + (t.daily_budget_formatted ? ' of ' + t.daily_budget_formatted : '');
    el('t-budget').textContent = t.budget_remaining_formatted || 'No limit';
    el('budget-meter').hidden = t.budget_used_pct === null;
    el('budget-bar').style.width = (t.budget_used_pct || 0) + '%';

    el('block-info').hidden = !d.block;
    if (d.block) {
      el('block-info').textContent = 'Guaranteed mode: ' + d.block.rolls_in_block + ' of ' + d.block.size
        + ' rolls used in the current block (' + d.block.completed_blocks + ' blocks completed). The winning position is never shown.';
    }

    el('recent').innerHTML = d.recent.length ? d.recent.map((r) => row([
      esc(r.time),
      '<s-badge tone="' + (r.channel === 'pos' ? 'info' : 'neutral') + '">' + (r.channel === 'pos' ? 'In-store' : 'Online') + '</s-badge>',
      esc(r.cart_formatted), r.picked, r.displayed,
      (r.won ? '<s-badge tone="success">Won ' + esc(r.payout_formatted) + '</s-badge>' : '<s-badge>Lost</s-badge>')
        + (r.test ? ' <s-badge tone="info">Test</s-badge>' : ''),
      esc(r.code || '') + (r.won && r.code_status !== 'created' && r.channel !== 'pos' ? ' <s-badge tone="critical">' + esc(r.code_status) + '</s-badge>' : (r.channel === 'pos' ? '<s-text color="subdued">(applied in-store)</s-text>' : '')),
    ])).join('') : empty(7, 'No rolls yet today');

    const cb = d.channel_breakdown;
    el('chan-summary').textContent =
      'Online: ' + cb.online.rolls + ' rolls · ' + cb.online.winners + ' winners · ' + cb.online.payout_formatted + '. ' +
      'POS: ' + cb.pos.rolls + ' rolls · ' + cb.pos.winners + ' winners · ' + cb.pos.payout_formatted + '.';
    ['all', 'online', 'pos'].forEach((c) => {
      el('chan-' + c).variant = ((d.recent_channel || 'all') === c) ? 'primary' : 'tertiary';
    });

    el('history').innerHTML = d.history.length ? d.history.map((h) => row([
      esc(h.promo_date), h.rolls, h.winners, esc(h.payout_formatted),
    ])).join('') : empty(4, 'No rolls yet');

    el('privacy').innerHTML = d.privacy_requests.length ? d.privacy_requests.map((p) => row([
      esc(new Date(p.received_at).toLocaleString()), esc(p.topic), esc(p.customer_id || ''), p.matched_rolls,
    ])).join('') : empty(4, 'None');
  }

  function renderMetrics(m) {
    el('today-metrics-error').hidden = !m.today_error;
    el('today-metrics-error').textContent = m.today_error ? 'Could not load today\'s orders: ' + m.today_error : '';
    el('today-metrics').hidden = !m.today;
    if (m.today) {
      const t = m.today, f = t.formatted;
      el('tm-updated').textContent = 'as of ' + new Date(t.computed_at).toLocaleTimeString();
      el('tm-summary').textContent = t.orders_count + ' orders, ' + f.revenue + ' revenue. '
        + t.rolls_count + ' rolls, ' + t.winners_count + ' winners. Prizes issued ' + f.prizes_issued
        + ', redeemed ' + f.prizes_redeemed + ' (' + pct(t.prizes_pct_of_revenue) + ' of revenue). '
        + 'Orders from people who rolled: ' + t.roller_orders_count + ' (' + f.roller_revenue + ').';
    }

    const c = m.campaign.totals, cf = c.formatted;
    el('c-revenue').textContent = cf.revenue;
    el('c-redeemed').textContent = cf.prizes_redeemed;
    el('c-issued').textContent = 'of ' + cf.prizes_issued + ' issued';
    el('c-pct').textContent = pct(c.prizes_pct_of_revenue);
    el('c-after').textContent = cf.revenue_after_prizes;
    el('c-extra').textContent = c.days_count
      ? c.days_count + ' promo days. ' + c.orders_count + ' orders. Redemption rate ' + pct(c.redemption_rate)
        + '. Revenue per R1 of prizes: ' + (c.revenue_per_prize_rand === null ? '–' : 'R' + c.revenue_per_prize_rand)
        + '. Average order: rollers ' + (cf.aov_rollers || '–') + ', everyone else ' + (cf.aov_others || '–') + '.'
      : 'No saved promo days yet. Each day appears here 30 minutes after it ends.';

    el('metrics-days').innerHTML = m.campaign.days.length ? m.campaign.days.map((d) => row([
      esc(d.promo_date) + (d.is_final ? '' : ' <s-badge tone="info">Provisional</s-badge>'),
      d.orders_count, esc(d.formatted.revenue),
      d.roller_orders_count + ' (' + esc(d.formatted.roller_revenue) + ')',
      d.winner_orders_count + ' (' + esc(d.formatted.winner_revenue) + ')',
      esc(d.formatted.prizes_issued), esc(d.formatted.prizes_redeemed), pct(d.prizes_pct_of_revenue),
      '<s-button variant="tertiary" data-recompute="' + esc(d.promo_date) + '">Recompute</s-button>',
    ])).join('') : empty(9, 'No saved promo days yet');
  }

  function rangeQuery(extra) {
    const p = new URLSearchParams();
    if (el('from').value) p.set('from', el('from').value);
    if (el('to').value) p.set('to', el('to').value);
    if (extra) Object.entries(extra).forEach(([k, v]) => p.set(k, v));
    const s = p.toString();
    return s ? '?' + s : '';
  }

  let activeChannel = 'all';

  async function load(fresh) {
    el('error').hidden = true;
    try {
      const reportQuery = activeChannel === 'all' ? '' : '?channel=' + activeChannel;
      const [report, metrics] = await Promise.all([
        api('GET', 'reporting' + reportQuery),
        api('GET', 'reporting/metrics' + rangeQuery(fresh ? { fresh: 1 } : null)),
      ]);
      renderReport(report);
      renderMetrics(metrics);
    } catch (e) {
      el('error').hidden = false;
      el('error').textContent = e.message;
    }
  }

  ['all', 'online', 'pos'].forEach((c) => el('chan-' + c).addEventListener('click', () => {
    activeChannel = c; load(false);
  }));
  el('refresh').addEventListener('click', () => load(false));
  el('tm-refresh').addEventListener('click', () => load(true));
  el('apply-range').addEventListener('click', () => load(false));
  el('export').addEventListener('click', () => download('reporting/export' + rangeQuery(), 'roll-for-it-revenue-vs-prizes.csv')
    .catch((e) => toast(e.message, true)));
  el('metrics-days').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-recompute]');
    if (!button) return;
    button.loading = true;
    try {
      renderMetrics(await api('POST', 'reporting/recompute' + rangeQuery(), { date: button.dataset.recompute }));
      toast('Recomputed');
    } catch (e) {
      toast(e.message, true);
    } finally {
      button.loading = false;
    }
  });

  load(false);
})();
</script>
@endpush
