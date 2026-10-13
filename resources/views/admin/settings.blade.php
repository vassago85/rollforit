@extends('layouts.admin', ['title' => 'Roll4It: Settings'])

@section('content')
<s-page heading="Roll4It settings">
    <s-button slot="primary-action" variant="primary" id="save">Save</s-button>

    <s-banner id="load-error" tone="critical" heading="Could not load settings" hidden></s-banner>
    <s-banner id="terms-error" tone="warning" heading="Terms page not updated" hidden></s-banner>

    <s-section heading="Status">
        <s-stack gap="base">
            <s-stack direction="inline" gap="base" alignItems="center">
                <s-switch id="enabled" data-field="enabled" label="Promotion enabled"></s-switch>
                <s-badge id="open-badge"></s-badge>
            </s-stack>
            <s-banner id="blockers" tone="info" heading="Before you can switch the promotion on" hidden>
                <s-unordered-list id="blockers-list"></s-unordered-list>
            </s-banner>
            <s-banner id="warnings" tone="warning" heading="Check these settings" hidden>
                <s-unordered-list id="warnings-list"></s-unordered-list>
            </s-banner>
        </s-stack>
    </s-section>

    <s-section heading="When it runs">
        <s-stack gap="base">
            <s-choice-list id="promo_days" data-field="promo_days" label="Promo days" multiple>
                <s-choice value="1">Monday</s-choice>
                <s-choice value="2">Tuesday</s-choice>
                <s-choice value="3">Wednesday</s-choice>
                <s-choice value="4">Thursday</s-choice>
                <s-choice value="5">Friday</s-choice>
                <s-choice value="6">Saturday</s-choice>
                <s-choice value="7">Sunday</s-choice>
            </s-choice-list>
            <s-stack direction="inline" gap="base">
                <s-text-field id="start_time" data-field="start_time" label="Start time" placeholder="08:00" details="24-hour time"></s-text-field>
                <s-text-field id="end_time" data-field="end_time" label="End time" placeholder="20:00" details="24-hour time"></s-text-field>
            </s-stack>
            <s-text id="timezone-note" color="subdued"></s-text>
        </s-stack>
    </s-section>

    <s-section heading="Spend and prizes">
        <s-stack gap="base">
            <s-stack direction="inline" gap="base">
                <s-number-field id="min_order" data-field="min_order" label="Minimum order value" prefix="R" min="0.01" step="0.01" required details="Cart value (excluding gift cards and shipping) needed to roll."></s-number-field>
                <s-number-field id="max_payout" data-field="max_payout" label="Maximum payout" prefix="R" min="0.01" step="0.01" required details="The most a single win can take off."></s-number-field>
            </s-stack>
            <s-stack direction="inline" gap="base">
                <s-number-field id="daily_budget" data-field="daily_budget" label="Daily prize budget (optional)" prefix="R" min="0.01" step="0.01" details="Closes the promotion for the day once prizes reach this total."></s-number-field>
                <s-number-field id="max_winners_per_day" data-field="max_winners_per_day" label="Max winners per day (optional)" min="1" step="1"></s-number-field>
            </s-stack>
            <s-box padding="base" background="subdued" borderRadius="base">
                <s-stack gap="small-200">
                    <s-number-field id="example_cart" label="Example cart value" prefix="R" min="0" step="1"></s-number-field>
                    <s-paragraph id="example"></s-paragraph>
                    <s-paragraph id="cost"></s-paragraph>
                </s-stack>
            </s-box>
        </s-stack>
    </s-section>

    <s-section heading="Odds">
        <s-stack gap="base">
            <s-stack direction="inline" gap="base">
                <s-select id="odds_mode" data-field="odds_mode" label="Odds mode">
                    <s-option value="guaranteed">Guaranteed: one winner in every N rolls</s-option>
                    <s-option value="chance">Random: every roll has a 1 in N chance</s-option>
                </s-select>
                <s-number-field id="odds_n" data-field="odds_n" label="N" min="2" max="1000" step="1"></s-number-field>
            </s-stack>
            <s-paragraph id="odds-statement"></s-paragraph>
        </s-stack>
    </s-section>

    <s-section heading="Excluded collections">
        <s-stack gap="base">
            <s-paragraph>Products in these collections don't count towards the minimum or the prize. Gift cards are always excluded and shipping is never covered.</s-paragraph>
            <s-stack direction="inline" gap="small" id="collections"></s-stack>
            <s-stack direction="inline" gap="small">
                <s-button id="pick-collections">Choose collections</s-button>
                <s-button id="clear-collections" variant="tertiary">Clear</s-button>
            </s-stack>
        </s-stack>
    </s-section>

    <s-section heading="Widget">
        <s-stack gap="base">
            <s-text-field id="headline" data-field="headline" label="Headline"></s-text-field>
            <s-text-area id="win_message" data-field="win_message" label="Win message" rows="2" details="Use {payout} and {code}."></s-text-area>
            <s-text-area id="lose_message" data-field="lose_message" label="Lose message" rows="2"></s-text-area>
            <s-stack direction="inline" gap="base">
                <s-color-field id="die_color" data-field="die_color" label="Die colour"></s-color-field>
                <s-color-field id="die_number_color" data-field="die_number_color" label="Number colour"></s-color-field>
                <s-color-field id="accent_color" data-field="accent_color" label="Accent colour"></s-color-field>
            </s-stack>
            <s-number-field id="roll_duration_ms" data-field="roll_duration_ms" label="Roll duration (ms)" min="1000" max="8000" step="100"></s-number-field>
            <s-stack direction="inline" gap="base">
                <s-checkbox id="sound_enabled" data-field="sound_enabled" label="Sound"></s-checkbox>
                <s-checkbox id="celebration_enabled" data-field="celebration_enabled" label="Confetti on a win"></s-checkbox>
            </s-stack>
            <s-checkbox id="show_countdown" data-field="show_countdown" label="Show countdown when closed" details="Between promo days the widget stays on the cart with a live countdown to the next roll. Untick to hide it until the promotion opens."></s-checkbox>
            <s-text-field id="drawer_selector" data-field="drawer_selector" label="Cart drawer selector (advanced)" details="Only needed if the widget doesn't appear in your theme's cart drawer. Leave blank to auto-detect."></s-text-field>
        </s-stack>
    </s-section>

    <s-section heading="Promoter details (used in the terms)">
        <s-stack gap="base">
            <s-text-field id="promoter_name" data-field="promoter_name" label="Promoter name"></s-text-field>
            <s-text-field id="promoter_registration_number" data-field="promoter_registration_number" label="Company registration number"></s-text-field>
            <s-text-area id="promoter_address" data-field="promoter_address" label="Physical address" rows="2"></s-text-area>
            <s-stack direction="inline" gap="base">
                <s-email-field id="promoter_email" data-field="promoter_email" label="Contact email"></s-email-field>
                <s-text-field id="promoter_phone" data-field="promoter_phone" label="Contact phone"></s-text-field>
            </s-stack>
        </s-stack>
    </s-section>

    <s-section heading="Point of sale (in-store)">
        <s-stack gap="base">
            <s-paragraph>Let staff offer a roll on the till. HuntexPos verifies the roll with Roll4It before any money changes hands; winnings come off the invoice directly.</s-paragraph>
            <s-stack direction="inline" gap="base" alignItems="center">
                <s-switch id="pos_enabled" data-field="pos_enabled" label="POS integration enabled"></s-switch>
                <s-badge id="pos-badge" tone="neutral">Not configured</s-badge>
            </s-stack>
            <s-stack direction="inline" gap="small">
                <s-button id="pos-rotate">Generate POS secret</s-button>
                <s-button id="pos-disable" variant="tertiary" hidden>Disable and clear secret</s-button>
            </s-stack>
            <s-banner id="pos-secret-banner" tone="success" heading="Copy this secret into HuntexPos now" hidden>
                <s-stack gap="small-200">
                    <s-paragraph>You'll only see it this one time. If you lose it, generate a new one — the old secret stops working the moment a new one is created.</s-paragraph>
                    <s-text id="pos-secret-value" font-weight="bold"></s-text>
                    <s-stack direction="inline" gap="small">
                        <s-button id="pos-secret-copy" variant="primary">Copy</s-button>
                        <s-button id="pos-secret-done" variant="tertiary">I've saved it</s-button>
                    </s-stack>
                    <s-paragraph>In HuntexPos appsettings:</s-paragraph>
                    <s-text id="pos-env-example" font-family="monospace"></s-text>
                </s-stack>
            </s-banner>
        </s-stack>
    </s-section>

    <s-section heading="Test mode">
        <s-stack gap="base">
            <s-paragraph>Create a private link that lets you roll on your storefront even when the promotion is off. Test rolls force the outcome you choose, don't count towards limits or reports, and winning codes expire after an hour. The link works for 2 hours.</s-paragraph>
            <s-stack direction="inline" gap="small">
                <s-button id="preview-win">Preview a win</s-button>
                <s-button id="preview-loss">Preview a loss</s-button>
            </s-stack>
            <s-banner id="preview-result" tone="info" hidden>
                <s-stack gap="small-200">
                    <s-text id="preview-text"></s-text>
                    <s-stack direction="inline" gap="small">
                        <s-button id="preview-open" variant="primary">Open storefront</s-button>
                        <s-button id="preview-copy">Copy link</s-button>
                    </s-stack>
                </s-stack>
            </s-banner>
        </s-stack>
    </s-section>
</s-page>
@endsection

@push('scripts')
<script>
(() => {
  const { api, toast, el, esc, copy, centsToMoney, moneyToCents, showErrors } = RFI;
  const TEXT = ['start_time', 'end_time', 'min_order', 'max_payout', 'daily_budget', 'max_winners_per_day', 'odds_n',
    'headline', 'win_message', 'lose_message', 'die_color', 'die_number_color', 'accent_color', 'roll_duration_ms',
    'drawer_selector', 'promoter_name', 'promoter_registration_number', 'promoter_address', 'promoter_email', 'promoter_phone'];
  const BOOLS = ['enabled', 'sound_enabled', 'celebration_enabled', 'show_countdown', 'pos_enabled'];
  let state = null;
  let collections = [];
  let previewUrl = null;

  function render(data) {
    state = data;
    const f = data.form;
    TEXT.forEach((k) => { el(k).value = f[k] === null || f[k] === undefined ? '' : String(f[k]); });
    BOOLS.forEach((k) => { el(k).checked = !!f[k]; });
    el('odds_mode').value = f.odds_mode;
    el('promo_days').querySelectorAll('s-choice').forEach((c) => {
      c.selected = f.promo_days.includes(Number(c.getAttribute('value')));
    });
    ['headline', 'win_message', 'lose_message'].forEach((k) => { el(k).placeholder = data.defaults[k]; });
    ['min_order', 'max_payout', 'daily_budget', 'example_cart'].forEach((k) => { el(k).prefix = data.shop.symbol; });
    collections = f.excluded_collections || [];
    renderCollections();

    renderPos(data);

    el('timezone-note').textContent = 'Times are in your store timezone: ' + data.shop.timezone + '.';
    el('enabled').disabled = !data.status.can_enable && !f.enabled;
    el('open-badge').textContent = data.status.open_now ? 'Running now' : 'Not running now';
    el('open-badge').tone = data.status.open_now ? 'success' : 'neutral';

    list('blockers', data.status.enable_blockers);
    list('warnings', data.warnings);
    if (!el('example_cart').value) {
      const min = Number(f.min_order || 0), max = Number(f.max_payout || 0);
      el('example_cart').value = String(Math.max(min, Math.round(max * 1.5)) || '');
    }
    updateLive();
  }

  function list(id, items) {
    el(id).hidden = !items.length;
    el(id + '-list').innerHTML = items.map((i) => '<s-list-item>' + esc(i) + '</s-list-item>').join('');
  }

  function renderPos(data) {
    const f = data.form;
    const configured = !!f.pos_secret_last_four;
    el('pos-badge').textContent = configured
      ? (f.pos_enabled ? 'Active · ending ' + f.pos_secret_last_four : 'Paused · ending ' + f.pos_secret_last_four)
      : 'Not configured';
    el('pos-badge').tone = configured && f.pos_enabled ? 'success' : 'neutral';
    el('pos-rotate').textContent = configured ? 'Rotate POS secret' : 'Generate POS secret';
    el('pos-disable').hidden = !configured;
    el('pos_enabled').disabled = !configured;
  }

  function renderCollections() {
    el('collections').innerHTML = collections.length
      ? collections.map((c) => '<s-badge>' + esc(c.title || c.id) + '</s-badge>').join('')
      : '<s-text color="subdued">None</s-text>';
  }

  function updateLive() {
    const symbol = state ? state.shop.symbol : 'R';
    const cart = moneyToCents(el('example_cart').value);
    const max = moneyToCents(el('max_payout').value);
    const n = parseInt(el('odds_n').value, 10);

    if (cart && max) {
      const payout = Math.min(cart, max);
      el('example').textContent = 'A ' + centsToMoney(cart, symbol) + ' cart that wins gets ' + centsToMoney(payout, symbol)
        + ' off and pays ' + centsToMoney(cart - payout, symbol) + '.';
    } else {
      el('example').textContent = 'Enter a maximum payout to see an example.';
    }
    el('cost').textContent = max && n > 1
      ? 'Worst-case cost per roll: ' + centsToMoney(Math.round(max / n), symbol) + ' (maximum payout ÷ ' + n + ').'
      : '';

    const guaranteed = el('odds_mode').value === 'guaranteed';
    el('odds-statement').textContent = n > 1
      ? 'Customers will see: "' + (guaranteed ? 'One in every ' + n + ' entries wins.' : 'Each roll has a 1 in ' + n + ' chance to win.') + '"'
        + (guaranteed ? ' Rolls are dealt in blocks of ' + n + ' with exactly one winner per block; blocks carry over between days.' : '')
      : '';
  }

  function collect() {
    const body = {};
    TEXT.forEach((k) => { const v = el(k).value; body[k] = v === '' ? null : v; });
    BOOLS.forEach((k) => { body[k] = !!el(k).checked; });
    body.odds_mode = el('odds_mode').value;
    body.promo_days = (el('promo_days').values || []).map(Number);
    body.excluded_collections = collections;
    return body;
  }

  async function load() {
    try {
      render(await api('GET', 'settings'));
    } catch (e) {
      el('load-error').hidden = false;
      el('load-error').textContent = e.message;
    }
  }

  async function save() {
    el('save').loading = true;
    try {
      const data = await api('PUT', 'settings', collect());
      showErrors({});
      render(data);
      el('terms-error').hidden = !data.terms_error;
      el('terms-error').textContent = data.terms_error || '';
      toast('Settings saved');
    } catch (e) {
      showErrors(e.errors);
      toast(e.status === 422 ? 'Fix the highlighted fields' : e.message, true);
    } finally {
      el('save').loading = false;
    }
  }

  async function preview(outcome) {
    try {
      const data = await api('POST', 'settings/preview', { outcome });
      previewUrl = data.url;
      el('preview-result').hidden = false;
      el('preview-text').textContent = 'Preview link (' + outcome + ') ready. It expires at '
        + new Date(data.expires_at).toLocaleTimeString() + '. Add a product worth the minimum to your cart, then open the link.';
    } catch (e) {
      toast(e.message, true);
    }
  }

  el('save').addEventListener('click', save);
  ['example_cart', 'max_payout', 'odds_n', 'odds_mode'].forEach((id) => {
    el(id).addEventListener('input', updateLive);
    el(id).addEventListener('change', updateLive);
  });
  el('pick-collections').addEventListener('click', async () => {
    const picked = await shopify.resourcePicker({
      type: 'collection',
      multiple: true,
      selectionIds: collections.map((c) => ({ id: c.id })),
    });
    if (picked) {
      collections = picked.map((c) => ({ id: c.id, title: c.title }));
      renderCollections();
    }
  });
  el('clear-collections').addEventListener('click', () => { collections = []; renderCollections(); });
  let lastPosSecret = null;

  async function rotatePos() {
    el('pos-rotate').loading = true;
    try {
      const data = await api('POST', 'settings/pos-secret');
      lastPosSecret = data.secret;
      el('pos-secret-value').textContent = data.secret;
      el('pos-env-example').textContent =
        'RollForIt__BaseUrl=' + data.base_url + '\n' +
        'RollForIt__ShopDomain=' + data.shop_domain + '\n' +
        'RollForIt__PosSecret=' + data.secret;
      el('pos-secret-banner').hidden = false;
      await load();
      toast('POS secret generated. Copy it now.');
    } catch (e) {
      toast(e.message, true);
    } finally {
      el('pos-rotate').loading = false;
    }
  }

  async function disablePos() {
    if (!window.confirm('Disable POS integration and clear the secret? HuntexPos will stop being able to call Roll4It until you generate a new secret.')) {
      return;
    }
    el('pos-disable').loading = true;
    try {
      await api('DELETE', 'settings/pos-secret');
      el('pos-secret-banner').hidden = true;
      await load();
      toast('POS integration disabled');
    } catch (e) {
      toast(e.message, true);
    } finally {
      el('pos-disable').loading = false;
    }
  }

  el('pos-rotate').addEventListener('click', rotatePos);
  el('pos-disable').addEventListener('click', disablePos);
  el('pos-secret-copy').addEventListener('click', () => lastPosSecret && copy(lastPosSecret));
  el('pos-secret-done').addEventListener('click', () => { lastPosSecret = null; el('pos-secret-banner').hidden = true; });

  el('preview-win').addEventListener('click', () => preview('win'));
  el('preview-loss').addEventListener('click', () => preview('loss'));
  el('preview-open').addEventListener('click', () => previewUrl && window.open(previewUrl, '_blank'));
  el('preview-copy').addEventListener('click', () => previewUrl && copy(previewUrl));

  load();
})();
</script>
@endpush
