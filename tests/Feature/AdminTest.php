<?php

use App\Models\Block;
use App\Models\Shop;
use App\Models\TermsVersion;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

function admin(Shop $shop): TestCase
{
    return test()->withHeaders(['Authorization' => 'Bearer '.idToken($shop), 'Accept' => 'application/json']);
}

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
        'max_winners_per_day' => null,
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
