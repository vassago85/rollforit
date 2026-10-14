<?php

use App\Models\Block;
use App\Models\Roll;
use App\Models\Shop;
use App\Models\TermsVersion;
use App\Promotion\EmailHasher;
use App\Promotion\PreviewLinks;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function roll(Shop $shop, array $payload = [], array $headers = []): TestResponse
{
    return test()->withHeaders($headers + ['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload($payload));
}

function forceNextWin(Shop $shop): Block
{
    return Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1]);
}

function forceNextLoss(Shop $shop): Block
{
    return Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 10, 'next_position' => 1]);
}

function winnerToday(Shop $shop, int $payout): Roll
{
    return Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => bin2hex(random_bytes(32)),
        'promo_date' => now('Africa/Johannesburg')->toDateString(),
        'cart_subtotal' => $payout, 'eligible_subtotal' => $payout,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => $payout, 'discount_code' => 'ROLL-OLD'.random_int(100, 999),
        'code_status' => Roll::CODE_CREATED,
    ]);
}

it('allows one roll per email per promo day and returns the stored result on repeat', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);

    $first = roll($shop)->assertOk()->json();
    $second = roll($shop, ['email' => '  Customer@Example.COM ', 'picked_number' => 3])->assertOk()->json();

    expect($first['repeat'])->toBeFalse()
        ->and($second['repeat'])->toBeTrue()
        ->and($second['won'])->toBe($first['won'])
        ->and($second['displayed_number'])->toBe($first['displayed_number'])
        ->and($second['picked_number'])->toBe(7)
        ->and($second['code'])->toBe($first['code'])
        ->and(Roll::count())->toBe(1);
});

it('stores only a hash of the email', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);
    roll($shop)->assertOk();

    $roll = Roll::first();
    expect($roll->email_hash)->toBe(EmailHasher::hash('customer@example.com'))
        ->and(json_encode($roll->getAttributes()))->not->toContain('customer@example.com');
});

it('blocks duplicate email rows at the database level', function () {
    $shop = makeShop();
    $row = fn () => Roll::create([
        'shop_id' => $shop->id, 'email_hash' => 'same', 'promo_date' => '2026-10-09',
        'cart_subtotal' => 1, 'eligible_subtotal' => 1, 'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
    ]);
    $row();
    $row();
})->throws(UniqueConstraintViolationException::class);

it('refuses to roll when the cart is below min_order', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '499.99']]);

    roll($shop)->assertStatus(422)->assertJson(['error' => 'below_min']);
    expect(Roll::count())->toBe(0);
});

it('does not count gift cards towards min_order', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '100.00'], 2 => ['price' => '1000.00', 'gift' => true]]);

    roll($shop, ['lines' => [['variant_id' => 1, 'quantity' => 1], ['variant_id' => 2, 'quantity' => 1]]])
        ->assertStatus(422)->assertJson(['error' => 'below_min']);
});

it('ignores client prices and re-prices from Shopify', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '100.00']]);

    roll($shop, ['lines' => [['variant_id' => 1, 'quantity' => 1, 'price' => 999999]]])
        ->assertStatus(422)->assertJson(['error' => 'below_min']);
});

it('pays max_payout on a cart above it, so the customer pays the remainder', function () {
    $shop = makeShop();
    forceNextWin($shop);
    $calls = fakeShopify([1 => ['price' => '1500.00']]);

    $result = roll($shop)->assertOk()->json();

    expect($result['won'])->toBeTrue()
        ->and($result['payout'])->toBe(100000)
        ->and($result['payout_formatted'])->toBe('R1,000')
        ->and($result['displayed_number'])->toBe(7)
        ->and($result['code'])->toStartWith('ROLL-');

    $create = collect($calls)->firstWhere('operation', 'CreateRollCode')['variables']['basicCodeDiscount'];
    expect($create['customerGets']['value']['discountAmount'])->toBe(['amount' => '1000.00', 'appliesOnEachItem' => false])
        ->and($create['usageLimit'])->toBe(1)
        ->and($create['context'])->toBe(['all' => 'ALL'])
        ->and($create['combinesWith'])->toBe(['productDiscounts' => false, 'orderDiscounts' => false, 'shippingDiscounts' => false])
        ->and($create['minimumRequirement']['subtotal']['greaterThanOrEqualToSubtotal'])->toBe('500.00')
        ->and($create['customerGets']['items'])->toBe(['all' => true])
        ->and($create)->not->toHaveKey('customerSelection');
});

it('expires the code at the end of the promo day in shop time', function () {
    $shop = makeShop();
    forceNextWin($shop);
    $calls = fakeShopify([1 => ['price' => '600.00']]);
    roll($shop)->assertOk();

    $endsAt = collect($calls)->firstWhere('operation', 'CreateRollCode')['variables']['basicCodeDiscount']['endsAt'];
    $expected = now('Africa/Johannesburg')->endOfDay()->utc();

    expect(CarbonImmutable::parse($endsAt)->timestamp)->toBe($expected->timestamp);
});

it('scopes the code to eligible products when collections are excluded', function () {
    $shop = makeShop(['excluded_collections' => [['id' => 'gid://shopify/Collection/9', 'title' => 'Ammo']]]);
    forceNextWin($shop);
    $calls = fakeShopify([
        1 => ['price' => '600.00', 'product' => 11],
        2 => ['price' => '900.00', 'product' => 22, 'collections' => ['gid://shopify/Collection/9']],
    ]);

    $result = roll($shop, ['lines' => [['variant_id' => 1, 'quantity' => 1], ['variant_id' => 2, 'quantity' => 1]]])->assertOk()->json();
    $create = collect($calls)->firstWhere('operation', 'CreateRollCode')['variables']['basicCodeDiscount'];

    expect($result['payout'])->toBe(60000)
        ->and($create['customerGets']['items'])->toBe(['products' => ['productsToAdd' => ['gid://shopify/Product/11']]])
        ->and($create['minimumRequirement']['subtotal']['greaterThanOrEqualToSubtotal'])->toBe('500.00');
});

it('reduces a win near the daily budget to the remaining budget', function () {
    $shop = makeShop(['daily_budget' => 120000]);
    winnerToday($shop, 100000);
    forceNextWin($shop);
    fakeShopify([1 => ['price' => '800.00']]);

    $result = roll($shop)->assertOk()->json();

    expect($result['won'])->toBeTrue()->and($result['payout'])->toBe(20000);
});

it('closes the promotion once the daily budget is used', function () {
    $shop = makeShop(['daily_budget' => 100000]);
    winnerToday($shop, 100000);
    fakeShopify([1 => ['price' => '800.00']]);

    roll($shop)->assertStatus(422)->assertJson(['error' => 'sold_out']);
    $this->getJson('/api/proxy/status?'.proxyQuery($shop))->assertOk()->assertJson(['open' => false, 'reason' => 'sold_out']);
});

it('closes the promotion for the day when max winners is reached', function () {
    $shop = makeShop(['max_winners_per_day' => 1]);
    winnerToday($shop, 50000);
    fakeShopify([1 => ['price' => '800.00']]);

    roll($shop)->assertStatus(422)->assertJson(['error' => 'sold_out']);
    expect(Roll::count())->toBe(1);
});

it('refuses rolls outside the promo days, hours or when disabled', function (array $settings) {
    $shop = makeShop($settings);
    fakeShopify([1 => ['price' => '800.00']]);

    roll($shop)->assertStatus(422)->assertJson(['error' => 'closed']);
    $this->getJson('/api/proxy/status?'.proxyQuery($shop))->assertOk()->assertJson(['open' => false]);
})->with([
    'disabled' => [['enabled' => false]],
    'not a promo day' => [['promo_days' => [now('Africa/Johannesburg')->addDay()->dayOfWeekIso]]],
    'terms unpublished' => [['terms_published_at' => null]],
]);

it('shows a loss on a different face than the picked number', function () {
    $shop = makeShop();
    forceNextLoss($shop);
    fakeShopify([1 => ['price' => '600.00']]);

    $result = roll($shop, ['picked_number' => 4])->assertOk()->json();

    expect($result['won'])->toBeFalse()
        ->and($result['displayed_number'])->not->toBe(4)
        ->and($result['displayed_number'])->toBeBetween(1, 10)
        ->and($result['code'])->toBeNull();
});

it('never exposes block or winning positions to the storefront', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);

    $roll = roll($shop)->assertOk()->getContent();
    $status = $this->getJson('/api/proxy/status?'.proxyQuery($shop))->assertOk()->getContent();

    foreach (['block', 'position', 'winning', 'next_position', 'email_hash'] as $needle) {
        expect($roll)->not->toContain($needle)->and($status)->not->toContain($needle);
    }
});

it('records the terms version on every roll', function () {
    $shop = makeShop();
    $version = TermsVersion::create(['shop_id' => $shop->id, 'version' => 1, 'source' => 'generated', 'content' => 'x', 'content_hash' => 'h', 'published_at' => now()]);
    fakeShopify([1 => ['price' => '600.00']]);

    roll($shop)->assertOk();

    expect(Roll::first()->terms_version_id)->toBe($version->id);
});

it('refuses the code and returns an error when the payout guard fails', function () {
    $shop = makeShop();
    $calls = fakeShopify([1 => ['price' => '600.00']]);
    Roll::create([
        'shop_id' => $shop->id, 'email_hash' => EmailHasher::hash('customer@example.com'),
        'promo_date' => now('Africa/Johannesburg')->toDateString(),
        'cart_subtotal' => 300000, 'eligible_subtotal' => 300000, 'picked_number' => 7, 'displayed_number' => 7,
        'won' => true, 'payout' => 200000, 'code_status' => Roll::CODE_PENDING,
    ]);

    roll($shop)->assertStatus(500)->assertJson(['error' => 'prize_unavailable']);

    expect(collect($calls)->pluck('operation'))->not->toContain('CreateRollCode')
        ->and(Roll::first()->code_status)->toBe(Roll::CODE_REFUSED);
});

it('retries code creation on a repeat roll after a failure', function () {
    $shop = makeShop();
    forceNextWin($shop);
    $attempts = 0;
    fakeShopify([1 => ['price' => '600.00']], [], ['CreateRollCode' => function () use (&$attempts) {
        $attempts++;

        return ['data' => ['discountCodeBasicCreate' => $attempts === 1
            ? ['codeDiscountNode' => null, 'userErrors' => [['field' => ['code'], 'message' => 'Temporary', 'code' => 'INTERNAL']]]
            : ['codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/1'], 'userErrors' => []]]];
    }]);

    $first = roll($shop)->assertOk()->json();
    $second = roll($shop)->assertOk()->json();

    expect($first['won'])->toBeTrue()->and($first['code_ready'])->toBeFalse()->and($first['code'])->toBeNull()
        ->and($second['code_ready'])->toBeTrue()->and($second['code'])->toStartWith('ROLL-')
        ->and(Roll::first()->code_status)->toBe(Roll::CODE_CREATED);
});

it('rate-limits rolls by customer IP', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);
    $ip = ['X-Forwarded-For' => '198.51.100.7, 10.0.0.1'];

    foreach (range(1, 3) as $i) {
        roll($shop, ['email' => "c{$i}@example.com"], $ip)->assertOk();
    }
    roll($shop, ['email' => 'c4@example.com'], $ip)->assertStatus(429)->assertJson(['error' => 'rate_limited']);
    roll($shop, ['email' => 'c5@example.com'], ['X-Forwarded-For' => '203.0.113.9'])->assertOk();

    expect(Roll::where('ip', '198.51.100.7')->count())->toBe(3);
});

it('refuses a cart in another currency', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);

    roll($shop, ['currency' => 'USD'])->assertStatus(422)->assertJson(['error' => 'currency']);
});

it('lets the merchant preview a forced win even while the promotion is off', function () {
    $shop = makeShop(['enabled' => false]);
    $link = PreviewLinks::issue($shop, $shop->settings, 'win');
    fakeShopify([1 => ['price' => '600.00']]);

    $status = $this->getJson('/api/proxy/status?'.proxyQuery($shop, ['preview' => $link['token']]))->assertOk()->json();
    $first = roll($shop, ['preview' => $link['token']])->assertOk()->json();
    $again = roll($shop, ['preview' => $link['token']])->assertOk()->json();

    expect($status['open'])->toBeTrue()->and($status['test'])->toBeTrue()
        ->and($first['test'])->toBeTrue()->and($first['won'])->toBeTrue()
        ->and($first['code'])->toStartWith('ROLLTEST-')
        ->and($again['repeat'])->toBeFalse()
        ->and(Roll::where('is_test', true)->count())->toBe(2)
        ->and(Block::count())->toBe(0);

    roll($shop, ['preview' => 'wrong-token'])->assertStatus(422)->assertJson(['error' => 'closed']);
});

it('does not call Shopify for discount codes on a loss', function () {
    $shop = makeShop();
    forceNextLoss($shop);
    $calls = fakeShopify([1 => ['price' => '600.00']]);
    roll($shop)->assertOk();

    expect(collect($calls)->pluck('operation')->all())->toBe(['CartPricing']);
    Http::assertSentCount(1);
});

it('caps a winning payout at max_payout_percent of the eligible subtotal', function () {
    // 40% cap on a R500 cart = R200, even though max_payout (R1,000) would allow R500.
    $shop = makeShop(['max_payout_percent' => 40]);
    forceNextWin($shop);
    $calls = fakeShopify([1 => ['price' => '500.00']]);

    $result = roll($shop)->assertOk()->json();

    expect($result['won'])->toBeTrue()
        ->and($result['payout'])->toBe(20000)
        ->and($result['payout_formatted'])->toBe('R200');

    $create = collect($calls)->firstWhere('operation', 'CreateRollCode')['variables']['basicCodeDiscount'];
    expect($create['customerGets']['value']['discountAmount']['amount'])->toBe('200.00');
});
