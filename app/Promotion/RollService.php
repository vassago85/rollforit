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
    public const DECISION_MINUTES = 10;

    public function __construct(
        private readonly CartPricer $pricer,
        private readonly OddsAllocator $allocator,
        private readonly DiscountCodeService $discounts,
        private readonly RollFulfillment $fulfillment,
        private readonly MerchantNotifier $notifier,
        private readonly Draw $draw,
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
        $emailHash = $input->email !== null && $input->email !== '' ? IdentityHasher::hashEmail($input->email) : null;
        $phoneHash = $input->phone !== null && $input->phone !== '' ? IdentityHasher::hashPhone($input->phone) : null;
        $customerHash = $input->shopifyCustomerId !== null && $input->shopifyCustomerId !== ''
            ? IdentityHasher::hashShopifyCustomerId($input->shopifyCustomerId)
            : null;

        if ($input->phone && ! $phoneHash) {
            throw new RollRejected(RollRejected::CURRENCY, 'That phone number does not look right. Please check and try again.');
        }
        if (! $emailHash && ! $phoneHash && ! $customerHash && ! $input->ip) {
            throw new RollRejected(RollRejected::CURRENCY, 'We could not verify this connection. Please try again.');
        }

        if (! $isTest) {
            $existing = $this->existingRoll($shop, $emailHash, $phoneHash, $customerHash, $promoDate, $input->ip);
            if ($existing) {
                return $this->present($shop, $settings, $this->releaseIfReady($shop, $settings, $existing), repeat: true);
            }

            // Repeat rollers must have completed a paid order since their previous roll.
            // POS rolls skip this check because the till applies the discount immediately,
            // so there is no abandon-the-cart loophole to close.
            if ($input->channel === Roll::CHANNEL_ONLINE) {
                $owed = $this->fulfillment->owedRoll($shop, $emailHash, $phoneHash, $customerHash, $input->ip, $promoDate);
                if ($owed) {
                    throw new RollRejected(
                        RollRejected::OWED,
                        'Finish checkout on your previous roll before rolling again.',
                    );
                }
            }

            $this->assertOpen($settings, $window);
            if ($input->channel === Roll::CHANNEL_ONLINE) {
                $this->throttle($shop, $input->ip, $promoDate);
            }
        }

        if ($input->currency && strtoupper($input->currency) !== strtoupper($shop->currency)) {
            throw new RollRejected(RollRejected::CURRENCY, 'This promotion is only available in the store currency.');
        }

        [$minPick, $maxPick] = $settings->pickRange();
        if ($input->pickedNumber < $minPick || $input->pickedNumber > $maxPick) {
            throw new RollRejected(RollRejected::CURRENCY, "Pick a number between {$minPick} and {$maxPick}.");
        }

        $priced = $input->channel === Roll::CHANNEL_POS
            ? PricedCart::fromPos((int) $input->posSubtotalCents)
            : $this->pricer->price($shop, $settings, $input->lines);

        if ($priced->qualifyingSubtotal() < (int) $settings->min_order) {
            throw new RollRejected(RollRejected::BELOW_MIN, WidgetCopy::spendToRoll($shop, $settings).'.');
        }
        if ($priced->eligibleSubtotal <= 0) {
            throw new RollRejected(RollRejected::NO_ELIGIBLE_ITEMS, 'Your cart has no items that qualify for this promotion.');
        }

        try {
            $roll = DB::transaction(fn () => $this->allocateAndRecord(
                $shop, $settings, $input, $priced, $emailHash, $phoneHash, $customerHash, $promoDate, $isTest, $window,
            ));
        } catch (UniqueConstraintViolationException) {
            $roll = $this->existingRoll($shop, $emailHash, $phoneHash, $customerHash, $promoDate, $input->ip);
            if (! $roll) {
                throw new RollRejected(RollRejected::CLOSED, 'Please try again.', 409);
            }

            return $this->present($shop, $settings, $this->releaseIfReady($shop, $settings, $roll), repeat: true);
        }

        $roll = $this->releaseIfReady($shop, $settings, $roll);

        return $this->present($shop, $settings, $roll, repeat: ! $roll->wasRecentlyCreated);
    }

    /**
     * Keep the base prize. Issues the code when this roll was waiting on a choice.
     *
     * @return array<string, mixed>
     */
    public function take(Shop $shop, RollInput $input): array
    {
        return $this->resolveDecision($shop, $input, risk: false);
    }

    /**
     * Risk the base prize on a fair coin. A miss clears the win.
     *
     * @return array<string, mixed>
     */
    public function bet(Shop $shop, RollInput $input): array
    {
        return $this->resolveDecision($shop, $input, risk: true);
    }

    private function allocateAndRecord(
        Shop $shop,
        ShopSettings $settings,
        RollInput $input,
        PricedCart $priced,
        ?string $emailHash,
        ?string $phoneHash,
        ?string $customerHash,
        string $promoDate,
        bool $isTest,
        PromoWindow $window,
    ): Roll {
        /** @var ShopSettings $locked */
        $locked = ShopSettings::whereKey($settings->id)->lockForUpdate()->firstOrFail();
        $budgetRemaining = null;
        $allocation = null;

        $isPos = $input->channel === Roll::CHANNEL_POS;
        [$minPick, $maxPick] = $locked->pickRange();
        $displayed = null;

        if ($isTest) {
            $won = $locked->preview_outcome === 'win';
            $displayed = $won ? $input->pickedNumber : self::losingFace($input->pickedNumber, $minPick, $maxPick);
        } else {
            $existing = $this->existingRoll($shop, $emailHash, $phoneHash, $customerHash, $promoDate, $input->ip);
            if ($existing) {
                return $existing;
            }

            $this->assertOpen($locked, new PromoWindow($locked, $window->timezone()));

            $stats = DayStats::for($shop->id, $promoDate);
            if ($stats->closedReason($locked) !== null) {
                throw new RollRejected(RollRejected::SOLD_OUT, 'Today\'s prizes have all been won. Try again next promo day.');
            }

            $budgetRemaining = $stats->budgetRemaining($locked);

            if ($locked->isDoubleGame() && ! $locked->isGuaranteed()) {
                $displayed = $this->draw->winningNumber($minPick, $maxPick);
                $won = $input->pickedNumber === $displayed;
            } else {
                $allocation = $this->allocator->allocate($locked);
                $won = $allocation->won;
                $displayed = $won ? $input->pickedNumber : self::losingFace($input->pickedNumber, $minPick, $maxPick);
            }
        }

        $payout = $won
            ? PayoutCalculator::calculate(
                $priced->eligibleSubtotal,
                (int) $locked->max_payout,
                $locked->activePayoutPercent(),
                $budgetRemaining,
            )
            : 0;
        $won = $won && $payout > 0;

        $decision = null;
        $decisionExpires = null;
        if ($won && $locked->isDoubleGame()) {
            $higher = PayoutCalculator::calculate(
                $priced->eligibleSubtotal,
                (int) $locked->max_payout,
                $locked->doublePayoutPercent(),
                $budgetRemaining,
            );
            if ($isPos || $higher <= $payout) {
                $decision = Roll::DECISION_TAKEN;
            } else {
                $decision = Roll::DECISION_PENDING;
                $decisionExpires = now()->addMinutes(self::DECISION_MINUTES);
            }
        }

        $roll = Roll::create([
            'shop_id' => $shop->id,
            'block_id' => $allocation?->blockId,
            'position' => $allocation?->position,
            'terms_version_id' => TermsVersion::where('shop_id', $shop->id)->max('id'),
            'email_hash' => $emailHash,
            'phone_hash' => $phoneHash,
            'shopify_customer_hash' => $customerHash,
            'channel' => $input->channel,
            'ip' => $input->ip,
            'cart_token' => $input->cartToken ? substr($input->cartToken, 0, 255) : null,
            'promo_date' => $promoDate,
            'cart_subtotal' => $priced->cartSubtotal,
            'eligible_subtotal' => $priced->eligibleSubtotal,
            'eligible_product_ids' => $priced->eligibleProductIds,
            'picked_number' => $input->pickedNumber,
            'displayed_number' => $displayed ?? ($won ? $input->pickedNumber : self::losingFace($input->pickedNumber, $minPick, $maxPick)),
            'won' => $won,
            'payout' => $payout,
            'decision' => $decision,
            'decision_expires_at' => $decisionExpires,
            // POS wins are applied directly to the till invoice, so no Shopify code is ever created.
            // A pending double-or-nothing choice waits until they take it or the window expires.
            'code_status' => $won && ! $isPos && $decision !== Roll::DECISION_PENDING ? Roll::CODE_PENDING : Roll::CODE_NONE,
            'is_test' => $isTest,
        ]);

        return $roll;
    }

    /**
     * Issue a code once the prize is final. An expired choice keeps the base prize.
     * A fresh pending choice is left alone. Merchant mail waits until the prize is final.
     */
    private function releaseIfReady(Shop $shop, ShopSettings $settings, Roll $roll): Roll
    {
        if ($roll->decision === Roll::DECISION_PENDING
            && $roll->decision_expires_at !== null
            && $roll->decision_expires_at->isPast()) {
            return $this->finish($shop, $settings, $roll, risk: false);
        }

        if ($roll->decision === Roll::DECISION_PENDING) {
            return $roll;
        }

        $ready = $this->ensureCode($shop, $settings, $roll);
        if ($roll->wasRecentlyCreated) {
            $this->notifier->rollLanded($shop, $settings, $ready);
        }

        return $ready;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDecision(Shop $shop, RollInput $input, bool $risk): array
    {
        $settings = $shop->settingsOrCreate();
        $roll = $this->findOwnedRoll($shop, $settings, $input);
        if (! $roll) {
            throw new RollRejected(RollRejected::CLOSED, 'There is no prize waiting for you.');
        }

        if ($roll->decision !== Roll::DECISION_PENDING) {
            return $this->present($shop, $settings, $this->ensureCode($shop, $settings, $roll), repeat: true);
        }

        $roll = $this->finish($shop, $settings, $roll, $risk);

        return $this->present($shop, $settings, $roll, repeat: false);
    }

    private function finish(Shop $shop, ShopSettings $settings, Roll $roll, bool $risk): Roll
    {
        $notify = false;

        $roll = DB::transaction(function () use ($shop, $settings, $roll, $risk, &$notify) {
            /** @var Roll $locked */
            $locked = Roll::whereKey($roll->id)->lockForUpdate()->firstOrFail();
            if ($locked->decision !== Roll::DECISION_PENDING) {
                return $locked;
            }

            $expired = $locked->decision_expires_at !== null && $locked->decision_expires_at->isPast();
            if (! $risk || $expired) {
                $locked->forceFill(['decision' => Roll::DECISION_TAKEN])->save();
                $notify = true;

                return $locked;
            }

            $higher = $this->higherPrize($settings, $locked);
            if ($higher <= (int) $locked->payout) {
                throw new RollRejected(RollRejected::SOLD_OUT, 'That higher prize is no longer available. Take the one you have.');
            }

            if ($this->draw->doubles()) {
                $locked->forceFill([
                    'decision' => Roll::DECISION_DOUBLED,
                    'payout' => $higher,
                    'code_status' => $locked->channel === Roll::CHANNEL_POS ? Roll::CODE_NONE : Roll::CODE_PENDING,
                ])->save();
            } else {
                $locked->forceFill([
                    'decision' => Roll::DECISION_BUSTED,
                    'won' => false,
                    'payout' => 0,
                    'code_status' => Roll::CODE_NONE,
                ])->save();
            }
            $notify = true;

            return $locked;
        });

        if ($roll->won && $roll->decision !== Roll::DECISION_PENDING) {
            $roll = $this->ensureCode($shop, $settings, $roll);
        }
        if ($notify) {
            $this->notifier->rollLanded($shop, $settings, $roll);
        }

        return $roll;
    }

    private function higherPrize(ShopSettings $settings, Roll $roll): int
    {
        $room = null;
        if ($settings->daily_budget) {
            $date = $roll->promo_date?->toDateString() ?? (string) $roll->promo_date;
            $remaining = DayStats::for($roll->shop_id, $date)->budgetRemaining($settings);
            $room = (int) $roll->payout + (int) $remaining;
        }

        return PayoutCalculator::calculate(
            (int) $roll->eligible_subtotal,
            (int) $settings->max_payout,
            $settings->doublePayoutPercent(),
            $room,
        );
    }

    private function findOwnedRoll(Shop $shop, ShopSettings $settings, RollInput $input): ?Roll
    {
        if (PreviewLinks::isValid($settings, $input->previewToken)) {
            return Roll::where('shop_id', $shop->id)
                ->where('is_test', true)
                ->latest('id')
                ->first();
        }

        $window = new PromoWindow($settings, $shop->timezone);
        $emailHash = $input->email !== null && $input->email !== '' ? IdentityHasher::hashEmail($input->email) : null;
        $phoneHash = $input->phone !== null && $input->phone !== '' ? IdentityHasher::hashPhone($input->phone) : null;
        $customerHash = $input->shopifyCustomerId !== null && $input->shopifyCustomerId !== ''
            ? IdentityHasher::hashShopifyCustomerId($input->shopifyCustomerId)
            : null;

        return $this->existingRoll($shop, $emailHash, $phoneHash, $customerHash, $window->promoDate(), $input->ip);
    }

    private function ensureCode(Shop $shop, ShopSettings $settings, Roll $roll): Roll
    {
        if (! $roll->won || $roll->code_status === Roll::CODE_CREATED || $roll->channel === Roll::CHANNEL_POS) {
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
        $isPos = $roll->channel === Roll::CHANNEL_POS;
        $pending = $roll->decision === Roll::DECISION_PENDING;
        $codeReady = ! $pending && (! $roll->won || $roll->code_status === Roll::CODE_CREATED || $isPos);
        $symbol = Money::symbolFor($shop->currency);
        $doublePayout = $pending ? $this->higherPrize($settings, $roll) : null;
        $offerDouble = $pending && $doublePayout !== null && $doublePayout > (int) $roll->payout;

        // What the customer would have won if this roll had gone the other way,
        // so the widget can tease them to come back. Based on their actual cart
        // and the shop's current caps.
        $potentialPayout = null;
        if (! $roll->won) {
            $potentialPayout = PayoutCalculator::calculate(
                (int) $roll->eligible_subtotal,
                (int) $settings->max_payout,
                $settings->activePayoutPercent(),
            );
        }

        return [
            'repeat' => $repeat,
            'test' => $roll->is_test,
            'won' => $roll->won,
            'displayed_number' => $roll->displayed_number,
            'picked_number' => $roll->picked_number,
            'payout' => $roll->payout,
            'payout_formatted' => Money::format($roll->payout, $symbol),
            'potential_payout' => $potentialPayout,
            'potential_payout_formatted' => $potentialPayout !== null && $potentialPayout > 0
                ? Money::format($potentialPayout, $symbol)
                : null,
            'code' => $roll->won && $codeReady && ! $isPos ? $roll->discount_code : null,
            'code_ready' => $codeReady,
            'ends_at' => ! $isPos ? $roll->discount_ends_at?->toIso8601String() : null,
            'message' => match (true) {
                $pending => WidgetCopy::offerMessage($shop, $settings, $roll, (int) $doublePayout, $offerDouble),
                $codeReady => WidgetCopy::resultMessage($shop, $settings, $roll),
                default => 'You won! We could not create your code just now. Roll again in a moment to collect it.',
            },
            'decision' => $roll->decision,
            'offer_double' => $offerDouble,
            'base_percent' => (int) ($settings->base_percent ?: 5),
            'double_percent' => (int) ($settings->double_percent ?: 10),
            'double_payout' => $doublePayout,
            'double_payout_formatted' => $doublePayout !== null ? Money::format($doublePayout, $symbol) : null,
            'roll_id' => $roll->id,
            'channel' => $roll->channel,
        ];
    }

    private function existingRoll(
        Shop $shop,
        ?string $emailHash,
        ?string $phoneHash,
        ?string $customerHash,
        string $promoDate,
        ?string $ip = null,
    ): ?Roll {
        $hasNamedIdentity = $emailHash || $phoneHash || $customerHash;
        if (! $hasNamedIdentity && ! $ip) {
            return null;
        }

        return Roll::where('shop_id', $shop->id)
            ->whereDate('promo_date', $promoDate)
            ->where('is_test', false)
            ->where(function ($q) use ($emailHash, $phoneHash, $customerHash, $ip, $hasNamedIdentity) {
                if ($emailHash) {
                    $q->orWhere('email_hash', $emailHash);
                }
                if ($phoneHash) {
                    $q->orWhere('phone_hash', $phoneHash);
                }
                if ($customerHash) {
                    $q->orWhere('shopify_customer_hash', $customerHash);
                }
                // Guests are keyed by IP for the day (one roll per connection).
                // Only match other guest rows so a shared café IP cannot surface someone else's code.
                if (! $hasNamedIdentity && $ip) {
                    $q->orWhere(function ($guest) use ($ip) {
                        $guest->where('ip', $ip)
                            ->whereNull('email_hash')
                            ->whereNull('phone_hash')
                            ->whereNull('shopify_customer_hash');
                    });
                }
            })
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

    public static function losingFace(int $picked, int $min = 1, int $max = 10): int
    {
        do {
            $face = random_int($min, $max);
        } while ($face === $picked);

        return $face;
    }
}
