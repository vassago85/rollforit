<?php

use App\Promotion\Exceptions\PayoutGuardViolation;
use App\Promotion\PayoutCalculator;
use App\Promotion\PayoutGuard;
use App\Support\Money;

it('pays the full eligible subtotal when it is under max_payout', function () {
    expect(PayoutCalculator::calculate(80000, 100000))->toBe(80000);
});

it('caps a cart above max_payout so the customer pays the remainder', function () {
    $cart = 150000;
    $payout = PayoutCalculator::calculate($cart, 100000);

    expect($payout)->toBe(100000)
        ->and($cart - $payout)->toBe(50000);
});

it('reduces a win to the remaining daily budget', function () {
    expect(PayoutCalculator::calculate(80000, 100000, 20000))->toBe(20000)
        ->and(PayoutCalculator::calculate(80000, 100000, 0))->toBe(0);
});

it('never pays more than max_payout across 10,000 simulated rolls with random carts', function () {
    $maxPayout = 100000;

    for ($i = 0; $i < 10_000; $i++) {
        $eligible = random_int(1, 5_000_000);
        $budget = random_int(0, 3) === 0 ? null : random_int(0, 500_000);
        $payout = PayoutCalculator::calculate($eligible, $maxPayout, $budget);

        expect($payout)->toBeLessThanOrEqual($maxPayout)
            ->toBeLessThanOrEqual($eligible)
            ->toBeGreaterThanOrEqual(0);
        if ($budget !== null) {
            expect($payout)->toBeLessThanOrEqual($budget);
        }
        if ($payout > 0) {
            PayoutGuard::assert($payout, $maxPayout);
        }
    }
});

it('refuses a payout above max_payout', function () {
    PayoutGuard::assert(100001, 100000);
})->throws(PayoutGuardViolation::class);

it('refuses a payout when max_payout is not set', function () {
    PayoutGuard::assert(5000, null);
})->throws(PayoutGuardViolation::class);

it('refuses a zero payout', function () {
    PayoutGuard::assert(0, 100000);
})->throws(PayoutGuardViolation::class);

it('converts and formats rand amounts as cents', function () {
    expect(Money::toCents('499.995'))->toBe(50000)
        ->and(Money::toCents('1500'))->toBe(150000)
        ->and(Money::toCents('0.01'))->toBe(1)
        ->and(Money::format(150000))->toBe('R1,500')
        ->and(Money::format(49950))->toBe('R499.50')
        ->and(Money::toDecimal(100000))->toBe('1000.00');
});
