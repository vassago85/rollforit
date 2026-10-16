<?php

use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\IdentityHasher;
use App\Shopify\PosSignature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $body
 */
function posRequest(Shop $shop, string $path, array $body, array $headerOverrides = []): TestResponse
{
    $json = json_encode($body) ?: '{}';
    $timestamp = $headerOverrides['X-RFI-Timestamp'] ?? (string) time();
    $nonce = $headerOverrides['X-RFI-Nonce'] ?? ('nonce-'.bin2hex(random_bytes(6)));
    $secret = $headerOverrides['_secret'] ?? $shop->settingsOrCreate()->pos_secret;
    $signature = $headerOverrides['X-RFI-Signature'] ?? PosSignature::compute($timestamp, $nonce, $json, (string) $secret);

    $headers = [
        'X-RFI-Shop' => $headerOverrides['X-RFI-Shop'] ?? $shop->domain,
        'X-RFI-Timestamp' => $timestamp,
        'X-RFI-Nonce' => $nonce,
        'X-RFI-Signature' => $signature,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    return test()->call('POST', '/api/pos/'.$path, [], [], [], $toServer = array_combine(
        array_map(fn ($k) => 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)),
        array_values($headers),
    ) + ['CONTENT_TYPE' => 'application/json'], $json);
}

function enablePos(Shop $shop, string $secret = 'test-pos-secret'): string
{
    $s = $shop->settingsOrCreate();
    $s->forceFill([
        'pos_enabled' => true,
        'pos_secret' => $secret,
        'pos_secret_last_four' => substr($secret, -4),
        'pos_secret_rotated_at' => now(),
    ])->save();

    return $secret;
}

beforeEach(function () {
    Cache::flush();
});

it('rejects a POS request without the required headers', function () {
    $shop = makeShop();
    enablePos($shop);

    test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/pos/status', [])
        ->assertStatus(401);
});

it('rejects a POS request when POS is disabled on the shop', function () {
    $shop = makeShop();
    // No enablePos() call — pos_enabled stays false by default.

    posRequest($shop, 'status', ['check' => true], ['_secret' => 'any'])
        ->assertStatus(403);
});

it('rejects a POS request with a bad signature', function () {
    $shop = makeShop();
    enablePos($shop);

    posRequest($shop, 'status', [], ['X-RFI-Signature' => str_repeat('0', 64)])
        ->assertStatus(401)
        ->assertJsonFragment(['message' => 'Bad POS signature.']);
});

it('rejects a replayed POS request', function () {
    $shop = makeShop();
    enablePos($shop);

    $body = ['probe' => 1];
    $timestamp = (string) time();
    $nonce = 'single-use-nonce';
    $json = json_encode($body);
    $sig = PosSignature::compute($timestamp, $nonce, $json, 'test-pos-secret');

    posRequest($shop, 'status', $body, ['X-RFI-Timestamp' => $timestamp, 'X-RFI-Nonce' => $nonce, 'X-RFI-Signature' => $sig])
        ->assertOk();
    posRequest($shop, 'status', $body, ['X-RFI-Timestamp' => $timestamp, 'X-RFI-Nonce' => $nonce, 'X-RFI-Signature' => $sig])
        ->assertStatus(409);
});

it('returns the current promotion status', function () {
    $shop = makeShop();
    enablePos($shop);

    $status = posRequest($shop, 'status', [])->assertOk()->json();

    expect($status['open'])->toBeTrue()
        ->and($status['min_order'])->toBe(50000)
        ->and($status['max_payout'])->toBe(100000)
        ->and($status['currency'])->toBe('ZAR');
});

it('records a POS roll with phone identity only, applies the win in-cents and never issues a Shopify code', function () {
    $shop = makeShop();
    enablePos($shop);
    forceNextWin($shop);

    $result = posRequest($shop, 'roll', [
        'phone' => '082 123 4567',
        'picked_number' => 4,
        'subtotal_cents' => 120000,
    ])->assertOk()->json();

    expect($result['won'])->toBeTrue()
        ->and($result['payout'])->toBe(100000) // capped at max_payout = R1,000
        ->and($result['channel'])->toBe('pos')
        ->and($result['code'])->toBeNull()
        ->and($result['code_ready'])->toBeTrue();

    $roll = Roll::first();
    expect($roll->channel)->toBe('pos')
        ->and($roll->phone_hash)->toBe(IdentityHasher::hashPhone('082 123 4567'))
        ->and($roll->email_hash)->toBeNull()
        ->and($roll->discount_code)->toBeNull()
        ->and($roll->code_status)->toBe(Roll::CODE_NONE);
});

it('enforces the one-roll-per-day rule across online and in-store with the same email', function () {
    $shop = makeShop();
    enablePos($shop);
    forceNextWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);

    // Online roll first (pre-verify buyer's email because the Mailgun gate guards /roll).
    preVerifyEmail($shop, 'buyer@example.com');
    $online = roll($shop, ['email' => 'buyer@example.com'])->assertOk()->json();
    expect($online['won'])->toBeTrue();

    // Same person walks into the store and tries to roll via the till.
    $instore = posRequest($shop, 'roll', [
        'email' => 'BUYER@EXAMPLE.com',
        'picked_number' => 2,
        'subtotal_cents' => 120000,
    ])->assertOk()->json();

    expect($instore['repeat'])->toBeTrue()
        ->and($instore['won'])->toBe($online['won'])
        ->and(Roll::count())->toBe(1);
});

it('refuses a POS roll when both email and phone are missing', function () {
    $shop = makeShop();
    enablePos($shop);

    posRequest($shop, 'roll', [
        'picked_number' => 1,
        'subtotal_cents' => 100000,
    ])->assertStatus(422);
});

it('rejects a POS roll below the shop minimum', function () {
    $shop = makeShop();
    enablePos($shop);

    posRequest($shop, 'roll', [
        'phone' => '0821234567',
        'picked_number' => 1,
        'subtotal_cents' => 10000, // R100, below the R500 minimum
    ])->assertStatus(422)
      ->assertJsonFragment(['error' => 'below_min']);

    expect(Roll::count())->toBe(0);
});
