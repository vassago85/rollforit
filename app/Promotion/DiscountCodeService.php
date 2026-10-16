<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Promotion\Exceptions\PayoutGuardViolation;
use App\Shopify\Exceptions\ShopifyApiException;
use App\Shopify\ShopifyAdmin;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Creates the single-use, fixed-amount discount code for a winning roll.
 */
class DiscountCodeService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly ShopifyAdmin $admin) {}

    /**
     * @throws PayoutGuardViolation When the payout breaks the hard cap. The code is never created.
     */
    public function createFor(Shop $shop, ShopSettings $settings, Roll $roll): Roll
    {
        PayoutGuard::assert(
            $roll->payout,
            $settings->max_payout,
            (int) $roll->eligible_subtotal,
            $settings->max_payout_percent !== null ? (int) $settings->max_payout_percent : null,
            ['shop' => $shop->domain, 'roll_id' => $roll->id],
        );

        if ($roll->code_status === Roll::CODE_CREATED) {
            return $roll;
        }

        $code = $roll->discount_code ?: self::generateCode($roll->is_test);
        // Codes have a short, hard deadline from the moment they're issued so the
        // win rewards the cart the shopper rolled on, not a future visit. POS
        // rolls are redeemed in-store immediately so this limit is irrelevant to
        // them; test rolls use their own merchant-tunable preview window.
        $endsAt = $roll->is_test
            ? CarbonImmutable::now()->addMinutes(config('shopify.test_mode.code_minutes'))
            : CarbonImmutable::now()->addMinutes((int) config('shopify.roll.code_minutes', 30));

        $roll->forceFill([
            'discount_code' => $code,
            'discount_ends_at' => $endsAt,
            'code_status' => Roll::CODE_PENDING,
        ])->save();

        $input = self::buildInput($settings, $roll, $code, $endsAt);

        try {
            $data = $this->admin->graphql($shop, <<<'GQL'
                mutation CreateRollCode($basicCodeDiscount: DiscountCodeBasicInput!) {
                  discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
                    codeDiscountNode { id }
                    userErrors { field message code }
                  }
                }
                GQL, ['basicCodeDiscount' => $input]);

            $payload = $data['discountCodeBasicCreate'] ?? [];
            ShopifyAdmin::assertNoUserErrors($payload, 'discountCodeBasicCreate');

            $roll->forceFill([
                'discount_node_id' => $payload['codeDiscountNode']['id'] ?? null,
                'code_status' => Roll::CODE_CREATED,
            ])->save();
        } catch (ShopifyApiException $e) {
            Log::error('Roll for It could not create a discount code.', [
                'shop' => $shop->domain,
                'roll_id' => $roll->id,
                'error' => $e->getMessage(),
            ]);
            $roll->forceFill(['code_status' => Roll::CODE_FAILED])->save();
        }

        return $roll;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildInput(ShopSettings $settings, Roll $roll, string $code, CarbonImmutable $endsAt): array
    {
        $productIds = $roll->eligible_product_ids ?? [];
        $scoped = $settings->excludedCollectionIds() !== [] && $productIds !== [];

        // The code is for THIS cart. Lock the minimum to the eligible subtotal the
        // shopper rolled on, so they cannot win on a R5,000 cart, remove items
        // down to R500, and redeem the discount on a smaller purchase. Adding
        // more items (bigger cart) is still fine — only downgrades are blocked.
        // They already passed min_order when they rolled, so we don't need to
        // re-enforce it here.
        $minimum = (int) $roll->eligible_subtotal;

        return [
            'title' => sprintf('Roll4It %s%s (%s)', $roll->is_test ? 'TEST ' : '', $roll->promo_date->toDateString(), $code),
            'code' => $code,
            'startsAt' => CarbonImmutable::now()->subMinute()->utc()->toIso8601String(),
            'endsAt' => $endsAt->utc()->toIso8601String(),
            'context' => ['all' => 'ALL'],
            'customerGets' => [
                'value' => [
                    'discountAmount' => [
                        'amount' => Money::toDecimal($roll->payout),
                        'appliesOnEachItem' => false,
                    ],
                ],
                'items' => $scoped
                    ? ['products' => ['productsToAdd' => array_values($productIds)]]
                    : ['all' => true],
            ],
            'minimumRequirement' => [
                'subtotal' => ['greaterThanOrEqualToSubtotal' => Money::toDecimal($minimum)],
            ],
            'usageLimit' => 1,
            'appliesOncePerCustomer' => true,
            'combinesWith' => [
                'productDiscounts' => false,
                'orderDiscounts' => false,
                'shippingDiscounts' => false,
            ],
        ];
    }

    public static function generateCode(bool $test = false): string
    {
        $suffix = '';
        for ($i = 0; $i < 8; $i++) {
            $suffix .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return ($test ? 'ROLLTEST-' : 'ROLL-').$suffix;
    }
}
