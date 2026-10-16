<?php

use App\Models\Block;
use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Promotion\IdentityHasher;
use App\Shopify\AppProxySignature;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Concurrency');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * An installed shop whose promotion is open right now (every day, all day),
 * with min_order R500 and max_payout R1,000 unless overridden. Amounts in cents.
 *
 * @param  array<string, mixed>  $settings
 */
function makeShop(array $settings = [], array $shop = []): Shop
{
    $model = Shop::create(array_merge([
        'domain' => 'test-'.bin2hex(random_bytes(4)).'.myshopify.com',
        'name' => 'McTactical',
        'email' => 'owner@example.com',
        'access_token' => 'shpat_test',
        'scopes' => config('shopify.scopes'),
        'timezone' => 'Africa/Johannesburg',
        'currency' => 'ZAR',
        'primary_domain_url' => 'https://shop.example.co.za',
        'installed_at' => now(),
    ], $shop));

    $model->settings()->create(array_merge([
        'enabled' => true,
        'odds_mode' => ShopSettings::MODE_GUARANTEED,
        'odds_n' => 10,
        'promo_days' => [1, 2, 3, 4, 5, 6, 7],
        'start_time' => '00:00',
        'end_time' => '23:59',
        'min_order' => 50000,
        'max_payout' => 100000,
        // Default to a sane daily cap so canEnable() passes. The runaway-payout
        // guard requires at least one of daily_budget or max_winners_per_day;
        // tests that specifically exercise the "no cap" branch pass null here.
        'max_winners_per_day' => 50,
        'promoter_name' => 'McTactical (Pty) Ltd',
        'promoter_address' => '1 Test Street, Pretoria',
        'promoter_email' => 'promo@example.com',
        'terms_url' => 'https://shop.example.co.za/pages/roll-for-it-terms',
        'terms_published_at' => now(),
    ], $settings));

    $model = $model->fresh();

    // Most roll tests use the default rollPayload() email. Pre-mark it as
    // verified so individual tests don't have to walk the Mailgun code dance
    // every time. Tests that specifically exercise the verification flow use
    // their own emails and/or Cache::flush() in beforeEach to opt out.
    preVerifyEmail($model, 'customer@example.com');

    return $model;
}

/**
 * Cheat-code for tests: mark an email as already-verified for a shop + promo
 * day, bypassing the Mailgun round trip. Used by makeShop() for the default
 * email and by individual tests that want a specific email ready to roll.
 */
function preVerifyEmail(Shop $shop, string $email, ?string $promoDate = null): void
{
    $promoDate ??= now($shop->timezone)->toDateString();
    $hash = IdentityHasher::hashEmail($email);
    Cache::put(
        "verify:ok:{$shop->id}:{$hash}:{$promoDate}",
        ['email' => $email, 'verified_at' => now()->toIso8601String()],
        86400,
    );
}

/**
 * Fakes the Admin GraphQL API.
 *
 * $variants: numeric variant id => ['price' => '600.00', 'product' => 1, 'gift' => false, 'collections' => [gid, ...]]
 * $orders: list of Order nodes returned for any orders() query.
 * Returns an ArrayObject that records every operation name received.
 *
 * @param  array<int|string, array<string, mixed>>  $variants
 * @param  list<array<string, mixed>>  $orders
 */
function fakeShopify(array $variants = [], array $orders = [], array $overrides = []): ArrayObject
{
    $log = new ArrayObject;

    Http::fake(function (HttpRequest $request) use ($variants, $orders, $overrides, $log) {
        $body = json_decode($request->body(), true) ?: [];
        $query = (string) ($body['query'] ?? '');
        $vars = $body['variables'] ?? [];
        preg_match('/(?:query|mutation)\s+(\w+)/', $query, $m);
        $operation = $m[1] ?? 'unknown';
        $log[] = ['operation' => $operation, 'variables' => $vars];

        if (isset($overrides[$operation])) {
            return Http::response($overrides[$operation]($vars), 200);
        }

        return match ($operation) {
            'CartPricing' => Http::response(['data' => ['nodes' => fakeVariantNodes($query, $vars['ids'] ?? [], $variants)]]),
            'CreateRollCode' => Http::response(['data' => ['discountCodeBasicCreate' => [
                'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/'.random_int(1, 999999)],
                'userErrors' => [],
            ]]]),
            'CreateTermsPage' => Http::response(['data' => ['pageCreate' => [
                'page' => ['id' => 'gid://shopify/Page/777', 'handle' => $vars['page']['handle'] ?? 'roll-for-it-terms', 'isPublished' => true],
                'userErrors' => [],
            ]]]),
            'UpdateTermsPage' => Http::response(['data' => ['pageUpdate' => [
                'page' => ['id' => $vars['id'], 'handle' => 'roll-for-it-terms', 'isPublished' => true],
                'userErrors' => [],
            ]]]),
            'PromoOrders' => Http::response(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => $orders,
            ]]]),
            default => Http::response(['errors' => [['message' => "Unfaked operation {$operation}"]]], 200),
        };
    });

    return $log;
}

/**
 * @param  list<string>  $ids
 * @param  array<int|string, array<string, mixed>>  $variants
 * @return list<array<string, mixed>|null>
 */
function fakeVariantNodes(string $query, array $ids, array $variants): array
{
    preg_match_all('/ex(\d+): inCollection\(id: "([^"]+)"\)/', $query, $aliases, PREG_SET_ORDER);

    return array_map(function (string $gid) use ($variants, $aliases) {
        $numeric = (int) str_replace('gid://shopify/ProductVariant/', '', $gid);
        $variant = $variants[$numeric] ?? null;
        if (! $variant) {
            return null;
        }

        $product = [
            'id' => 'gid://shopify/Product/'.($variant['product'] ?? $numeric),
            'isGiftCard' => (bool) ($variant['gift'] ?? false),
        ];
        foreach ($aliases as [, $index, $collection]) {
            $product["ex{$index}"] = in_array($collection, $variant['collections'] ?? [], true);
        }

        return ['id' => $gid, 'price' => $variant['price'], 'product' => $product];
    }, $ids);
}

/**
 * A signed app proxy query string for the given shop.
 *
 * @param  array<string, string>  $extra
 */
function proxyQuery(Shop $shop, array $extra = [], ?int $timestamp = null): string
{
    return AppProxySignature::sign(array_merge([
        'shop' => $shop->domain,
        'logged_in_customer_id' => '',
        'path_prefix' => '/apps/roll-for-it',
        'timestamp' => (string) ($timestamp ?? time()),
    ], $extra), config('shopify.api_secret'));
}

/**
 * An App Bridge ID token signed like Shopify's.
 *
 * @param  array<string, mixed>  $overrides
 */
function idToken(Shop|string $shop, array $overrides = [], ?string $secret = null): string
{
    $domain = $shop instanceof Shop ? $shop->domain : $shop;

    return JWT::encode(array_merge([
        'iss' => "https://{$domain}/admin",
        'dest' => "https://{$domain}",
        'aud' => config('shopify.api_key'),
        'sub' => '1',
        'exp' => time() + 60,
        'nbf' => time() - 5,
        'iat' => time() - 5,
        'jti' => bin2hex(random_bytes(8)),
        'sid' => bin2hex(random_bytes(8)),
    ], $overrides), $secret ?? config('shopify.api_secret'), 'HS256');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rollPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'customer@example.com',
        'picked_number' => 7,
        'lines' => [['variant_id' => 1, 'quantity' => 1]],
        'currency' => 'ZAR',
    ], $overrides);
}

/**
 * Admin API request helper. Attaches a valid App Bridge ID token so the
 * `shopify.session` middleware accepts the call. Hoisted here (rather than
 * living in AdminTest.php) so ParaTest workers that pick up SimulatorTest or
 * AppProxyTest can still call it — a sibling-file function definition is NOT
 * loaded by a worker that only runs one of its callers.
 */
function admin(Shop $shop): TestCase
{
    return test()->withHeaders(['Authorization' => 'Bearer '.idToken($shop), 'Accept' => 'application/json']);
}

/**
 * Fires a storefront roll request through the signed app proxy. Hoisted from
 * RollTest.php so PosRollTest and OwedRollTest workers find it too.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 */
function roll(Shop $shop, array $payload = [], array $headers = []): TestResponse
{
    return test()->withHeaders($headers + ['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload($payload));
}

/**
 * Seed the block log so the next roll on this shop lands on a win. Hoisted
 * from RollTest.php for the same cross-file ParaTest reason as roll().
 */
function forceNextWin(Shop $shop): Block
{
    return Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1]);
}

/**
 * Seed the block log so the next roll on this shop lands on a loss.
 */
function forceNextLoss(Shop $shop): Block
{
    return Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 10, 'next_position' => 1]);
}

/**
 * Create a settled winning roll today so social-proof and cap-exhaustion
 * tests don't have to walk the full roll flow.
 */
function winnerToday(Shop $shop, int $payout): Roll
{
    return Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => bin2hex(random_bytes(32)),
        'promo_date' => now('Africa/Johannesburg')->toDateString(),
        'cart_subtotal' => $payout,
        'eligible_subtotal' => $payout,
        'picked_number' => 1,
        'displayed_number' => 1,
        'won' => true,
        'payout' => $payout,
        'discount_code' => 'ROLL-OLD'.random_int(100, 999),
        'code_status' => Roll::CODE_CREATED,
    ]);
}
