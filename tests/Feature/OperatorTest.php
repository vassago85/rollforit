<?php

use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function operator(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'paul@local.test',
        'password' => Hash::make('super-secret-dev'),
        'is_operator' => true,
    ], $overrides));
}

it('redirects guests from the operator dashboard to the operator login', function () {
    $this->get('/op')->assertRedirect('/op/login');
    $this->get('/op/shops')->assertRedirect('/op/login');
});

it('redirects visitors from the apex URL to the demo when there is no shop param', function () {
    $this->get('/')->assertRedirect('/demo/');
});

it('keeps rendering the embedded admin at / when loaded by Shopify', function () {
    $this->get('/?shop=mctactical.myshopify.com&host=abc&embedded=1')->assertOk();
});

it('rejects non-operator users at the login step', function () {
    User::factory()->create([
        'email' => 'merchant@example.com',
        'password' => Hash::make('super-secret-dev'),
        'is_operator' => false,
    ]);

    $this->post('/op/login', [
        'email' => 'merchant@example.com',
        'password' => 'super-secret-dev',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rate-limits a wrong-password sequence', function () {
    operator();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/op/login', [
            'email' => 'paul@local.test',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');
    }

    $this->post('/op/login', [
        'email' => 'paul@local.test',
        'password' => 'super-secret-dev',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

function installedShop(array $overrides = []): Shop
{
    $shop = Shop::create(array_merge([
        'domain' => 'mctactical.myshopify.com',
        'name' => 'McTactical',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ], $overrides));
    $shop->settingsOrCreate();

    return $shop;
}

it('logs operators in and renders the dashboard with cross-shop totals', function () {
    $operator = operator();
    $shop = installedShop();

    Roll::create([
        'shop_id' => $shop->id,
        'promo_date' => now()->toDateString(),
        'email_hash' => str_repeat('a', 64),
        'cart_subtotal' => 100_000,
        'eligible_subtotal' => 100_000,
        'picked_number' => 5,
        'displayed_number' => 5,
        'won' => true,
        'payout' => 50_000,
        'is_test' => false,
    ]);

    $this->actingAs($operator);

    $this->get('/op')
        ->assertOk()
        ->assertSee('Overview')
        ->assertSee('Installed shops')
        ->assertSee('R500'); // Formatted R50_000 cents payout
});

it('shows the weekly promo days on the shop detail view when the shop runs on a weekly schedule', function () {
    // Weekly is the default, but assert the labels explicitly so the operator view
    // never regresses to showing "Promo dates" for a shop that is using days-of-week.
    $operator = operator();
    $shop = installedShop();
    $shop->settingsOrCreate()->forceFill([
        'schedule_mode' => ShopSettings::SCHEDULE_WEEKLY,
        'promo_days' => [1, 3, 5],
        'promo_dates' => [],
    ])->save();

    $this->actingAs($operator)
        ->get("/op/shops/{$shop->id}")
        ->assertOk()
        ->assertSee('Schedule mode')
        ->assertSee('Weekly')
        ->assertSee('Promo days')
        ->assertSee('1, 3, 5')
        ->assertDontSee('Promo dates');
});

it('shows a human calendar list on the shop detail view when the shop runs on specific dates', function () {
    $operator = operator();
    $shop = installedShop();
    $shop->settingsOrCreate()->forceFill([
        'schedule_mode' => ShopSettings::SCHEDULE_DATES,
        'promo_dates' => ['2026-11-30', '2026-12-24'],
        // The weekly list is deliberately present but should be ignored once the
        // schedule mode is 'dates' — the view must branch on schedule_mode, not
        // on whether promo_days happens to be non-empty.
        'promo_days' => [1, 2, 3, 4, 5, 6, 7],
    ])->save();

    $this->actingAs($operator)
        ->get("/op/shops/{$shop->id}")
        ->assertOk()
        ->assertSee('Specific dates')
        ->assertSee('Promo dates')
        ->assertSee('30 November 2026')
        ->assertSee('24 December 2026')
        // The weekly list of day numbers must not appear in dates mode, or the
        // operator will think the shop is running every day too.
        ->assertDontSee('1, 2, 3, 4, 5, 6, 7');
});

it('redacts the cleartext POS secret on the shop detail view', function () {
    $operator = operator();
    $shop = installedShop();
    $shop->settingsOrCreate()->forceFill([
        'pos_enabled' => true,
        'pos_secret' => 'super-secret-value-9999',
        'pos_secret_last_four' => '9999',
        'pos_secret_rotated_at' => now(),
    ])->save();

    $this->actingAs($operator)
        ->get("/op/shops/{$shop->id}")
        ->assertOk()
        ->assertSee('****9999')
        ->assertDontSee('super-secret-value-9999');
});

it('kicks a user out mid-session if their operator flag is revoked', function () {
    $operator = operator();
    $this->actingAs($operator);

    $this->get('/op')->assertOk();

    $operator->is_operator = false;
    $operator->save();

    $this->get('/op')
        ->assertRedirect('/op/login')
        ->assertSessionHasErrors('email');
});
