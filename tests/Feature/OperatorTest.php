<?php

use App\Models\Roll;
use App\Models\Shop;
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
