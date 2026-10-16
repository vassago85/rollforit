<?php

use App\Models\Block;
use App\Models\EmailVerifyAttempt;
use App\Models\Roll;
use App\Models\Shop;
use App\Models\TermsVersion;
use App\Promotion\EmailHasher;
use Illuminate\Support\Facades\Http;

// admin() lives in tests/Pest.php so ParaTest workers that only load
// SimulatorTest.php or AppProxyTest.php still see it.

/**
 * @return array<string, mixed>
 */
function settingsForm(array $overrides = []): array
{
    return array_merge([
        'enabled' => false,
        'odds_mode' => 'guaranteed',
        'odds_n' => '10',
        'promo_days' => [5],
        'start_time' => '08:00',
        'end_time' => '20:00',
        'min_order' => '500',
        'max_payout' => '1000',
        'daily_budget' => null,
        // Keeps the default form at the runaway-payout guard's happy path.
        // Tests that specifically assert the guard pass max_winners_per_day => null.
        'max_winners_per_day' => '50',
        'excluded_collections' => [],
        'die_color' => '#1f2937',
        'die_number_color' => '#ffffff',
        'accent_color' => '#f59e0b',
        'roll_duration_ms' => '2500',
        'sound_enabled' => true,
        'celebration_enabled' => true,
        'promoter_name' => 'McTactical (Pty) Ltd',
        'promoter_address' => '1 Test Street, Pretoria',
        'promoter_email' => 'promo@example.com',
    ], $overrides);
}

it('returns 401 with the retry header when the ID token is missing or invalid', function () {
    $shop = makeShop();

    $this->getJson('/api/admin/settings')
        ->assertStatus(401)->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    $this->withHeaders(['Authorization' => 'Bearer '.idToken($shop, [], 'shpss_wrong_secret_wrong_secret_wrong_00')])->getJson('/api/admin/settings')
        ->assertStatus(401)->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    $this->withHeaders(['Authorization' => 'Bearer '.idToken($shop, ['exp' => time() - 120])])->getJson('/api/admin/settings')
        ->assertStatus(401);
    $this->withHeaders(['Authorization' => 'Bearer '.idToken($shop, ['aud' => 'someone-else'])])->getJson('/api/admin/settings')
        ->assertStatus(401);
});

it('loads settings for an installed shop', function () {
    $shop = makeShop();

    admin($shop)->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('form.min_order', '500.00')
        ->assertJsonPath('form.max_payout', '1000.00')
        ->assertJsonPath('cost_per_roll_formatted', 'R100');
});

it('exchanges the ID token for an offline token on first load', function () {
    $domain = 'new-shop.myshopify.com';
    Http::fake([
        "https://{$domain}/admin/oauth/access_token" => Http::response([
            'access_token' => 'shpat_new', 'scope' => config('shopify.scopes'), 'expires_in' => 3600,
            'refresh_token' => 'shprt_new', 'refresh_token_expires_in' => 7776000,
        ]),
        "https://{$domain}/admin/api/*" => Http::response(['data' => ['shop' => [
            'name' => 'New Shop', 'email' => 'o@example.com', 'ianaTimezone' => 'Africa/Johannesburg',
            'currencyCode' => 'ZAR', 'primaryDomain' => ['url' => 'https://new.example.com'],
        ]]]),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.idToken($domain)])->getJson('/api/admin/settings')->assertOk();

    $shop = Shop::where('domain', $domain)->first();
    expect($shop->access_token)->toBe('shpat_new')
        ->and($shop->refresh_token)->toBe('shprt_new')
        ->and($shop->name)->toBe('New Shop')
        ->and($shop->primary_domain_url)->toBe('https://new.example.com');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth/access_token')
        && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
        && $r['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
        && (string) $r['expiring'] === '1');
});

it('saves a campaign-mode schedule as a list of specific dates and reads it back sorted', function () {
    $shop = makeShop();
    fakeShopify(); // schedule_mode flips a TERMS_FIELD so settings save triggers a terms republish

    admin($shop)->putJson('/api/admin/settings', settingsForm([
        'schedule_mode' => 'dates',
        'promo_dates' => ['2026-12-24', '2026-11-30', '2027-01-02'], // deliberately out of order
    ]))->assertOk()
        ->assertJsonPath('form.schedule_mode', 'dates')
        // Server sorts so the merchant and the storefront widget both see a canonical list.
        ->assertJsonPath('form.promo_dates', ['2026-11-30', '2026-12-24', '2027-01-02']);
});

it('rejects duplicate promo dates with a per-field validation error', function () {
    $shop = makeShop();

    admin($shop)->putJson('/api/admin/settings', settingsForm([
        'schedule_mode' => 'dates',
        'promo_dates' => ['2026-11-30', '2026-11-30'],
    ]))->assertStatus(422)->assertJsonValidationErrors(['promo_dates.1']);
});

it('blocks enabling the promotion in dates mode with no promo dates listed', function () {
    $shop = makeShop();

    admin($shop)->putJson('/api/admin/settings', settingsForm([
        'schedule_mode' => 'dates',
        'promo_dates' => [],
        'enabled' => true,
    ]))->assertStatus(422)->assertJsonValidationErrors(['enabled']);
});

it('cannot enable the promotion without min_order, max_payout and a published terms page', function () {
    $shop = makeShop(['enabled' => false, 'terms_url' => null, 'terms_published_at' => null]);

    admin($shop)->putJson('/api/admin/settings', settingsForm(['enabled' => true]))
        ->assertStatus(422)->assertJsonValidationErrors('enabled');

    admin($shop)->putJson('/api/admin/settings', settingsForm(['enabled' => true, 'min_order' => null]))
        ->assertStatus(422)->assertJsonValidationErrors('min_order');

    admin($shop)->putJson('/api/admin/settings', settingsForm(['enabled' => true, 'max_payout' => null]))
        ->assertStatus(422)->assertJsonValidationErrors('max_payout');

    expect($shop->settings->fresh()->enabled)->toBeFalse();

    $shop->settings->forceFill(['terms_url' => 'https://x/pages/roll-for-it-terms', 'terms_published_at' => now()])->save();
    fakeShopify();
    admin($shop)->putJson('/api/admin/settings', settingsForm(['enabled' => true]))->assertOk()->assertJsonPath('form.enabled', true);
});

/* Guaranteed-mode blocks can roll over mid-day, so a second block that happens
 * to put its winning_position early can mint more max_payout winners the same
 * day. Without either a daily cash cap or a winners-per-day cap the only brake
 * is the per-roll max, which has already produced the "wins look crazy high"
 * complaints we saw on the dev shop. Refusing to enable until at least one
 * cap is set is the hard guard the merchant asked for. */
it('refuses to enable the promotion when both the daily budget and winners-per-day caps are empty', function () {
    $shop = makeShop(['enabled' => false]);

    $response = admin($shop)->putJson('/api/admin/settings', settingsForm([
        'enabled' => true,
        'daily_budget' => null,
        'max_winners_per_day' => null,
    ]))->assertStatus(422);

    // The specific message is user-facing, so pin it so we don't silently soften it later.
    expect($response->json('errors.enabled.0'))
        ->toContain('daily budget')->toContain('winners per day');
    expect($shop->settings->fresh()->enabled)->toBeFalse();
});

it('accepts enabling when either cap alone is set', function () {
    $shop = makeShop(['enabled' => false]);
    fakeShopify();

    admin($shop)->putJson('/api/admin/settings', settingsForm([
        'enabled' => true, 'daily_budget' => '10000', 'max_winners_per_day' => null,
    ]))->assertOk()->assertJsonPath('form.enabled', true);

    admin($shop)->putJson('/api/admin/settings', settingsForm([
        'enabled' => true, 'daily_budget' => null, 'max_winners_per_day' => '25',
    ]))->assertOk()->assertJsonPath('form.enabled', true);
});

/* enable_blockers is what the admin UI reads to render the "fix these to turn
 * the promo on" list. Make sure the runaway-payout item shows up with the
 * exact wording the merchant will see on screen. */
it('exposes the runaway-payout blocker in the status payload so the UI can render the fix list', function () {
    $shop = makeShop(['enabled' => false, 'daily_budget' => null, 'max_winners_per_day' => null]);

    $blockers = admin($shop)->getJson('/api/admin/settings')->assertOk()->json('status.enable_blockers');

    expect($blockers)->toContain('Set a daily budget or a maximum number of winners per day. Without one of these, a bad run could rack up unlimited payouts in a single day.');
});

it('requires max_payout to be greater than 0', function () {
    $shop = makeShop();

    admin($shop)->putJson('/api/admin/settings', settingsForm(['max_payout' => '0']))
        ->assertStatus(422)->assertJsonValidationErrors('max_payout');
});

it('warns without blocking when max_payout is below min_order', function () {
    $shop = makeShop(['terms_published_at' => null]);

    $response = admin($shop)->putJson('/api/admin/settings', settingsForm(['min_order' => '500', 'max_payout' => '300']))->assertOk();

    expect($response->json('warnings.0'))->toContain('less than your minimum order')
        ->and($shop->settings->fresh()->max_payout)->toBe(30000);
});

it('saves the countdown toggle, which defaults to on', function () {
    $shop = makeShop(['terms_published_at' => null]);

    admin($shop)->getJson('/api/admin/settings')->assertJsonPath('form.show_countdown', true);
    admin($shop)->putJson('/api/admin/settings', settingsForm(['show_countdown' => false]))
        ->assertOk()->assertJsonPath('form.show_countdown', false);
});

it('creates the terms page with the right handle when published', function () {
    $shop = makeShop(['terms_url' => null, 'terms_published_at' => null]);
    $calls = fakeShopify();

    admin($shop)->postJson('/api/admin/terms/publish')->assertOk()
        ->assertJsonPath('published', true)
        ->assertJsonPath('terms_url', 'https://shop.example.co.za/pages/roll-for-it-terms');

    $create = collect($calls)->firstWhere('operation', 'CreateTermsPage')['variables']['page'];
    expect($create['handle'])->toBe('roll-for-it-terms')
        ->and($create['isPublished'])->toBeTrue()
        ->and($create['body'])->toContain('R500')->toContain('R1,000')
        ->and(TermsVersion::count())->toBe(1);
});

it('updates the terms page when max_payout or min_order changes', function (array $change, string $expected) {
    $shop = makeShop(['terms_url' => null, 'terms_published_at' => null]);
    $calls = fakeShopify();
    admin($shop)->putJson('/api/admin/settings', settingsForm())->assertOk();
    admin($shop)->postJson('/api/admin/terms/publish')->assertOk();

    admin($shop)->putJson('/api/admin/settings', settingsForm($change))->assertOk()->assertJsonPath('terms_error', null);

    $update = collect($calls)->firstWhere('operation', 'UpdateTermsPage');
    expect($update)->not->toBeNull()
        ->and($update['variables']['id'])->toBe('gid://shopify/Page/777')
        ->and($update['variables']['page']['body'])->toContain($expected)
        ->and(TermsVersion::count())->toBe(2);
})->with([
    'max_payout' => [['max_payout' => '750'], 'R750'],
    'min_order' => [['min_order' => '650'], 'R650'],
]);

it('does not touch the terms page when nothing in the terms changed', function () {
    $shop = makeShop(['terms_url' => null, 'terms_published_at' => null]);
    $calls = fakeShopify();
    admin($shop)->putJson('/api/admin/settings', settingsForm())->assertOk();
    admin($shop)->postJson('/api/admin/terms/publish')->assertOk();

    admin($shop)->putJson('/api/admin/settings', settingsForm(['die_color' => '#000000']))->assertOk();

    expect(collect($calls)->pluck('operation'))->not->toContain('UpdateTermsPage')
        ->and(TermsVersion::count())->toBe(1);
});

it('keeps the lawyer review flag until terms are marked reviewed', function () {
    $shop = makeShop();

    admin($shop)->getJson('/api/admin/terms')->assertJsonPath('reviewed', false);
    admin($shop)->postJson('/api/admin/terms/reviewed', ['reviewed' => true])->assertJsonPath('reviewed', true);
});

it('issues a preview link for test mode', function () {
    $shop = makeShop();

    $link = admin($shop)->postJson('/api/admin/settings/preview', ['outcome' => 'win'])->assertOk()->json();

    expect($link['url'])->toStartWith('https://shop.example.co.za/cart?rfi_preview=');
});

it('shows reporting with block progress but never the winning position', function () {
    $shop = makeShop();
    Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 6, 'next_position' => 4]);

    $content = admin($shop)->getJson('/api/admin/reporting')->assertOk()
        ->assertJsonPath('block.rolls_in_block', 3)
        ->getContent();

    expect($content)->not->toContain('winning_position');
});

it('includes recent email verification attempts in the reporting payload', function () {
    $shop = makeShop();

    // Three attempts: one successful send, one invalid-email send, one wrong-code verify.
    EmailVerifyAttempt::create([
        'shop_id' => $shop->id, 'email' => 'ok@example.com', 'ip' => '198.51.100.1',
        'action' => 'send', 'outcome' => 'queued',
    ]);
    EmailVerifyAttempt::create([
        'shop_id' => $shop->id, 'email' => 'not-an-email', 'ip' => '198.51.100.2',
        'action' => 'send', 'outcome' => 'invalid_email',
    ]);
    EmailVerifyAttempt::create([
        'shop_id' => $shop->id, 'email' => 'ok@example.com', 'ip' => '198.51.100.1',
        'action' => 'verify', 'outcome' => 'wrong_code', 'attempt_number' => 2,
    ]);

    $payload = admin($shop)->getJson('/api/admin/reporting')->assertOk()->json('verify_attempts');

    expect($payload['window_days'])->toBe(7)
        // Key order is not guaranteed across DB drivers; compare the contents.
        ->and($payload['totals']['send'])->toEqualCanonicalizing(['queued' => 1, 'invalid_email' => 1])
        ->and($payload['totals']['verify'])->toBe(['wrong_code' => 1])
        ->and($payload['recent'])->toHaveCount(3);

    // Newest first, with email + outcome exposed to the merchant.
    expect($payload['recent'][0]['email'])->toBe('ok@example.com')
        ->and($payload['recent'][0]['action'])->toBe('verify')
        ->and($payload['recent'][0]['outcome'])->toBe('wrong_code')
        ->and($payload['recent'][0]['attempt_number'])->toBe(2)
        ->and($payload['recent'][0]['success'])->toBeFalse()
        ->and($payload['recent'][2]['outcome'])->toBe('queued')
        ->and($payload['recent'][2]['success'])->toBeTrue();
});

it('does not leak another shop\'s verify attempts into reporting', function () {
    $shopA = makeShop();
    $shopB = makeShop();

    EmailVerifyAttempt::create([
        'shop_id' => $shopB->id, 'email' => 'other@example.com',
        'action' => 'send', 'outcome' => 'queued',
    ]);

    $payload = admin($shopA)->getJson('/api/admin/reporting')->assertOk()->json('verify_attempts');

    expect($payload['recent'])->toBe([])
        ->and($payload['totals']['send'])->toBe([])
        ->and($payload['totals']['verify'])->toBe([]);
});

/* The merchant's "Rolls today" view must distinguish between prizes ISSUED
 * (every winning code we created) and prizes CONVERTED (codes that were
 * actually spent in a paid order). Without this split, the merchant sees
 * "3 winners · R3,000" and assumes the store took a R3,000 hit when the real
 * cost may be R2,000 because one winner never checked out. */
it('reports redeemed vs issued separately on today, per channel, and per row', function () {
    $shop = makeShop();
    $today = now('Africa/Johannesburg')->toDateString();

    $base = [
        'shop_id' => $shop->id,
        'promo_date' => $today,
        'cart_subtotal' => 80000, 'eligible_subtotal' => 80000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'code_status' => Roll::CODE_CREATED,
        'channel' => Roll::CHANNEL_ONLINE,
    ];
    // Online: 2 winners worth R500 + R1000 issued; only the R500 one redeemed.
    Roll::create(array_merge($base, ['email_hash' => EmailHasher::hash('a@example.com'),
        'payout' => 50000, 'discount_code' => 'ROLL-A1', 'redeemed_at' => now(),
        'redeemed_order_id' => 'gid://shopify/Order/1', 'redeemed_amount' => 50000]));
    Roll::create(array_merge($base, ['email_hash' => EmailHasher::hash('b@example.com'),
        'payout' => 100000, 'discount_code' => 'ROLL-B2']));
    // Online loser (counts to rolls, not winners / not redeemed).
    Roll::create(array_merge($base, ['email_hash' => EmailHasher::hash('c@example.com'),
        'won' => false, 'payout' => 0, 'discount_code' => null, 'displayed_number' => 2,
        'code_status' => Roll::CODE_NONE]));
    // POS win that got applied at the till (counted but not "redeemed" by code).
    Roll::create(array_merge($base, ['email_hash' => EmailHasher::hash('p@example.com'),
        'payout' => 25000, 'discount_code' => 'ROLL-P3', 'channel' => Roll::CHANNEL_POS]));

    $payload = admin($shop)->getJson('/api/admin/reporting')->assertOk()->json();

    // today card: 3 winners total (2 online + 1 POS), 1 redeemed for R500.
    expect($payload['today']['winners'])->toBe(3)
        ->and($payload['today']['payout'])->toBe(175000)
        ->and($payload['today']['redeemed_count'])->toBe(1)
        ->and($payload['today']['redeemed'])->toBe(50000);

    // Per-channel breakdown: online shows 1 of 2 converted; POS shows 0 of 1.
    expect($payload['channel_breakdown']['online']['winners'])->toBe(2)
        ->and($payload['channel_breakdown']['online']['payout'])->toBe(150000)
        ->and($payload['channel_breakdown']['online']['redeemed_count'])->toBe(1)
        ->and($payload['channel_breakdown']['online']['redeemed'])->toBe(50000)
        ->and($payload['channel_breakdown']['pos']['winners'])->toBe(1)
        ->and($payload['channel_breakdown']['pos']['redeemed_count'])->toBe(0);

    // Per-row annotation: ROLL-A1 shows a redeemed_amount, ROLL-B2 does not.
    $byCode = collect($payload['recent'])->keyBy('code');
    expect($byCode['ROLL-A1']['redeemed_amount'])->toBe(50000)
        ->and($byCode['ROLL-A1']['redeemed_amount_formatted'])->toBe('R500')
        ->and($byCode['ROLL-B2']['redeemed_amount'])->toBeNull()
        ->and($byCode['ROLL-B2']['redeemed_at'])->toBeNull();
});

/* The reporting payload must surface redeemed_email_matched for every recent
 * row (null for unredeemed, true when the checkout email matched the roll
 * email, false when they differed). The UI uses the explicit-false value to
 * paint a "Different email at checkout" warning, so a regression here hides
 * the code-sharing abuse signal from the merchant. */
it('surfaces the email-match flag on each recent row so the warning badge can render', function () {
    $shop = makeShop();
    $today = now('Africa/Johannesburg')->toDateString();
    $base = [
        'shop_id' => $shop->id, 'promo_date' => $today,
        'cart_subtotal' => 80000, 'eligible_subtotal' => 80000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'code_status' => Roll::CODE_CREATED,
        'channel' => Roll::CHANNEL_ONLINE,
    ];
    // Matched redemption.
    Roll::create(array_merge($base, [
        'email_hash' => EmailHasher::hash('alice@example.com'),
        'payout' => 50000, 'discount_code' => 'ROLL-MATCH1',
        'redeemed_at' => now(), 'redeemed_order_id' => 'gid://shopify/Order/1',
        'redeemed_amount' => 50000, 'redeemed_email_matched' => true,
    ]));
    // Mismatched redemption — the code was spent by someone other than the roller.
    Roll::create(array_merge($base, [
        'email_hash' => EmailHasher::hash('bob@example.com'),
        'payout' => 50000, 'discount_code' => 'ROLL-SHARED1',
        'redeemed_at' => now(), 'redeemed_order_id' => 'gid://shopify/Order/2',
        'redeemed_amount' => 50000, 'redeemed_email_matched' => false,
    ]));
    // Winning but not yet redeemed — flag stays null.
    Roll::create(array_merge($base, [
        'email_hash' => EmailHasher::hash('carol@example.com'),
        'payout' => 50000, 'discount_code' => 'ROLL-WAITING',
    ]));

    $recent = admin($shop)->getJson('/api/admin/reporting')->assertOk()->json('recent');
    $byCode = collect($recent)->keyBy('code');

    expect($byCode['ROLL-MATCH1']['redeemed_email_matched'])->toBeTrue()
        ->and($byCode['ROLL-SHARED1']['redeemed_email_matched'])->toBeFalse()
        ->and($byCode['ROLL-WAITING']['redeemed_email_matched'])->toBeNull();
});

/* History rows must also carry redemption so merchants can see the gap over
 * time, not just today — a shop whose win codes rarely convert is a different
 * conversation from one where every code is spent. */
it('includes redemption counts and totals on each history day', function () {
    $shop = makeShop();
    $yesterday = now('Africa/Johannesburg')->subDay()->toDateString();

    $base = [
        'shop_id' => $shop->id, 'promo_date' => $yesterday,
        'cart_subtotal' => 60000, 'eligible_subtotal' => 60000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'code_status' => Roll::CODE_CREATED, 'channel' => Roll::CHANNEL_ONLINE,
    ];
    Roll::create(array_merge($base, ['email_hash' => EmailHasher::hash('h1@example.com'),
        'payout' => 30000, 'discount_code' => 'ROLL-H1',
        'redeemed_at' => now()->subDay(), 'redeemed_order_id' => 'gid://shopify/Order/100', 'redeemed_amount' => 30000]));
    Roll::create(array_merge($base, ['email_hash' => EmailHasher::hash('h2@example.com'),
        'payout' => 40000, 'discount_code' => 'ROLL-H2']));

    $history = admin($shop)->getJson('/api/admin/reporting')->assertOk()->json('history');
    $row = collect($history)->firstWhere('promo_date', $yesterday);

    expect($row['winners'])->toBe(2)
        ->and($row['payout'])->toBe(70000)
        ->and($row['redeemed_count'])->toBe(1)
        ->and($row['redeemed'])->toBe(30000)
        ->and($row['redeemed_formatted'])->toBe('R300');
});
