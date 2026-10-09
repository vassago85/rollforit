<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Models\TermsVersion;
use App\Promotion\Exceptions\PayoutGuardViolation;
use App\Promotion\Exceptions\RollRejected;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The server decides every outcome. The client only animates what it receives.
 */
class RollService
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly OddsAllocator $allocator,
        private readonly DiscountCodeService $discounts,
    ) {}

    /**
     * @return array<string, mixed> Storefront-safe result (never contains block positions).
     *
     * @throws RollRejected
     * @throws PayoutGuardViolation
     */
    public function roll(Shop $shop, RollInput $input): array
    {
        $settings = $shop->settingsOrCreate();
        $isTest = PreviewLinks::isValid($settings, $input->previewToken);
        $window = new PromoWindow($settings, $shop->timezone);
        $promoDate = $window->promoDate();
        $emailHash = EmailHasher::hash($input->email);

        if (! $isTest) {
            $existing = $this->existingRoll($shop, $emailHash, $promoDate);
            if ($existing) {
                return $this->present($shop, $settings, $this->ensureCode($shop, $settings, $existing), repeat: true);
            }

            $this->assertOpen($settings, $window);
            $this->throttle($shop, $input->ip, $promoDate);
        }

        if ($input->currency && strtoupper($input->currency) !== strtoupper($shop->currency)) {
            throw new RollRejected(RollRejected::CURRENCY, 'This promotion is only available in the store currency.');
        }

        $priced = $this->pricer->price($shop, $settings, $input->lines);

        if ($priced->qualifyingSubtotal() < (int) $settings->min_order) {
            throw new RollRejected(RollRejected::BELOW_MIN, WidgetCopy::spendToRoll($shop, $settings).'.');
        }
        if ($priced->eligibleSubtotal <= 0) {
            throw new RollRejected(RollRejected::NO_ELIGIBLE_ITEMS, 'Your cart has no items that qualify for this promotion.');
        }

        try {
            $roll = DB::transaction(fn () => $this->allocateAndRecord(
                $shop, $settings, $input, $priced, $emailHash, $promoDate, $isTest, $window,
            ));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request for the same email won the race; show its result.
            $roll = $this->existingRoll($shop, $emailHash, $promoDate);
            if (! $roll) {
                throw new RollRejected(RollRejected::CLOSED, 'Please try again.', 409);
            }

            return $this->present($shop, $settings, $this->ensureCode($shop, $settings, $roll), repeat: true);
        }

        return $this->present($shop, $settings, $this->ensureCode($shop, $settings, $roll), repeat: ! $roll->wasRecentlyCreated);
    }

    private function allocateAndRecord(
        Shop $shop,
        ShopSettings $settings,
        RollInput $input,
        PricedCart $priced,
        string $emailHash,
        string $promoDate,
        bool $isTest,
        PromoWindow $window,
    ): Roll {
        /** @var ShopSettings $locked */
        $locked = ShopSettings::whereKey($settings->id)->lockForUpdate()->firstOrFail();
        $budgetRemaining = null;
        $allocation = null;

        if ($isTest) {
            $won = $locked->preview_outcome === 'win';
        } else {
            $existing = $this->existingRoll($shop, $emailHash, $promoDate);
            if ($existing) {
                return $existing;
            }

            $this->assertOpen($locked, new PromoWindow($locked, $window->timezone()));

            $stats = DayStats::for($shop->id, $promoDate);
            if ($stats->closedReason($locked) !== null) {
                throw new RollRejected(RollRejected::SOLD_OUT, 'Today\'s prizes have all been won. Try again next promo day.');
            }

            $allocation = $this->allocator->allocate($locked);
            $won = $allocation->won;
            $budgetRemaining = $stats->budgetRemaining($locked);
        }

        $payout = $won
            ? PayoutCalculator::calculate($priced->eligibleSubtotal, (int) $locked->max_payout, $budgetRemaining)
            : 0;
        $won = $won && $payout > 0;

        $roll = Roll::create([
            'shop_id' => $shop->id,
            'block_id' => $allocation?->blockId,
            'position' => $allocation?->position,
            'terms_version_id' => TermsVersion::where('shop_id', $shop->id)->max('id'),
            'email_hash' => $emailHash,
            'ip' => $input->ip,
            'cart_token' => $input->cartToken ? substr($input->cartToken, 0, 255) : null,
            'promo_date' => $promoDate,
            'cart_subtotal' => $priced->cartSubtotal,
            'eligible_subtotal' => $priced->eligibleSubtotal,
            'eligible_product_ids' => $priced->eligibleProductIds,
            'picked_number' => $input->pickedNumber,
            'displayed_number' => $won ? $input->pickedNumber : self::losingFace($input->pickedNumber),
            'won' => $won,
            'payout' => $payout,
            'code_status' => $won ? Roll::CODE_PENDING : Roll::CODE_NONE,
            'is_test' => $isTest,
        ]);

        return $roll;
    }

    private function ensureCode(Shop $shop, ShopSettings $settings, Roll $roll): Roll
    {
        if (! $roll->won || $roll->code_status === Roll::CODE_CREATED) {
            return $roll;
        }

        try {
            return $this->discounts->createFor($shop, $settings, $roll);
        } catch (PayoutGuardViolation $e) {
            $roll->forceFill(['code_status' => Roll::CODE_REFUSED])->save();

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Shop $shop, ShopSettings $settings, Roll $roll, bool $repeat): array
    {
        $codeReady = ! $roll->won || $roll->code_status === Roll::CODE_CREATED;

        return [
            'repeat' => $repeat,
            'test' => $roll->is_test,
            'won' => $roll->won,
            'displayed_number' => $roll->displayed_number,
            'picked_number' => $roll->picked_number,
            'payout' => $roll->payout,
            'payout_formatted' => Money::format($roll->payout, Money::symbolFor($shop->currency)),
            'code' => $roll->won && $codeReady ? $roll->discount_code : null,
            'code_ready' => $codeReady,
            'ends_at' => $roll->discount_ends_at?->toIso8601String(),
            'message' => $codeReady
                ? WidgetCopy::resultMessage($shop, $settings, $roll)
                : 'You won! We could not create your code just now. Roll again with the same email in a moment to collect it.',
        ];
    }

    private function existingRoll(Shop $shop, string $emailHash, string $promoDate): ?Roll
    {
        return Roll::where('shop_id', $shop->id)
            ->where('email_hash', $emailHash)
            ->whereDate('promo_date', $promoDate)
            ->where('is_test', false)
            ->first();
    }

    private function assertOpen(ShopSettings $settings, PromoWindow $window): void
    {
        if (! $settings->enabled || ! $settings->canEnable() || ! $window->isOpen()) {
            throw new RollRejected(RollRejected::CLOSED, 'The promotion is not running right now.');
        }
    }

    private function throttle(Shop $shop, ?string $ip, string $promoDate): void
    {
        if (! $ip) {
            return;
        }

        $dayKey = "roll-ip-day:{$shop->id}:{$promoDate}:{$ip}";
        $burstKey = "roll-ip-burst:{$shop->id}:{$ip}";

        if (RateLimiter::tooManyAttempts($dayKey, config('shopify.roll.ip_rolls_per_day'))
            || RateLimiter::tooManyAttempts($burstKey, config('shopify.roll.ip_rolls_per_10_minutes'))) {
            throw new RollRejected(RollRejected::RATE_LIMITED, 'Too many rolls from this connection. Please try again later.', 429);
        }

        RateLimiter::hit($dayKey, 86400);
        RateLimiter::hit($burstKey, 600);
    }

    public static function losingFace(int $picked): int
    {
        do {
            $face = random_int(1, 10);
        } while ($face === $picked);

        return $face;
    }
}
