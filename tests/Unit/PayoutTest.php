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
    expect(PayoutCalculator::calculate(80000, 100000, null, 20000))->toBe(20000)
        ->and(PayoutCalculator::calculate(80000, 100000, null, 0))->toBe(0);
});

it('applies the percent cap so a small cart cannot win 100%', function () {
    // R500 cart, 40% cap → R200 even though max_payout would allow R500.
    expect(PayoutCalculator::calculate(50000, 100000, 40))->toBe(20000);
});

it('uses the percent cap when it is tighter than max_payout', function () {
    // R2,000 cart with max R1,000 and a 20% cap → percent (R400) wins.
    expect(PayoutCalculator::calculate(200000, 100000, 20))->toBe(40000);
});

it('uses max_payout when it is tighter than the percent cap', function () {
    // R10,000 cart with max R500 and a 50% cap would allow R5,000; max_payout wins at R500.
    expect(PayoutCalculator::calculate(1000000, 50000, 50))->toBe(50000);
});

it('combines the percent cap with the daily budget', function () {
    // Percent cap says R400, but only R150 of budget left.
    expect(PayoutCalculator::calculate(200000, 100000, 20, 15000))->toBe(15000);
});

it('ignores the percent cap when it is null or zero', function () {
    expect(PayoutCalculator::calculate(50000, 100000, null))->toBe(50000)
        ->and(PayoutCalculator::calculate(50000, 100000, 0))->toBe(50000);
});

it('exposes the raw percent cap for diagnostics', function () {
    expect(PayoutCalculator::percentCap(50000, 40))->toBe(20000)
        ->and(PayoutCalculator::percentCap(50000, null))->toBe(0)
        ->and(PayoutCalculator::percentCap(50000, 0))->toBe(0)
        ->and(PayoutCalculator::percentCap(0, 40))->toBe(0);
});

it('never pays more than max_payout across 10,000 simulated rolls with random carts', function () {
    $maxPayout = 100000;

    for ($i = 0; $i < 10_000; $i++) {
        $eligible = random_int(1, 5_000_000);
        $budget = random_int(0, 3) === 0 ? null : random_int(0, 500_000);
        $payout = PayoutCalculator::calculate($eligible, $maxPayout, null, $budget);

        expect($payout)->toBeLessThanOrEqual($maxPayout)
            ->toBeLessThanOrEqual($eligible)
            ->toBeGreaterThanOrEqual(0);
        if ($budget !== null) {
            expect($payout)->toBeLessThanOrEqual($budget);
        }
        if ($payout > 0) {
            PayoutGuard::assert($payout, $maxPayout, $eligible);
        }
    }
});

it('never pays more than the percent cap across 10,000 simulated rolls', function () {
    $maxPayout = 1_000_000;

    for ($i = 0; $i < 10_000; $i++) {
        $eligible = random_int(1, 5_000_000);
        $percent = random_int(1, 100);
        $percentCap = intdiv($eligible * $percent, 100);
        $payout = PayoutCalculator::calculate($eligible, $maxPayout, $percent);

        expect($payout)->toBeLessThanOrEqual($percentCap)
            ->toBeLessThanOrEqual($eligible)
            ->toBeGreaterThanOrEqual(0);
        if ($payout > 0) {
            PayoutGuard::assert($payout, $maxPayout, $eligible, $percent);
        }
    }
});

it('refuses a payout above max_payout', function () {
    PayoutGuard::assert(100001, 100000, 500000);
})->throws(PayoutGuardViolation::class);

it('refuses a payout when max_payout is not set', function () {
    PayoutGuard::assert(5000, null, 500000);
})->throws(PayoutGuardViolation::class);

it('refuses a zero payout', function () {
    PayoutGuard::assert(0, 100000, 500000);
})->throws(PayoutGuardViolation::class);

it('refuses a payout that breaks the percent cap even if max_payout would allow it', function () {
    // R500 cart with 40% cap → cap is R200, so R201 must be refused.
    PayoutGuard::assert(20100, 100000, 50000, 40);
})->throws(PayoutGuardViolation::class);

it('accepts a payout that sits exactly on the percent cap', function () {
    PayoutGuard::assert(20000, 100000, 50000, 40);
})->throwsNoExceptions();

it('converts and formats rand amounts as cents', function () {
    expect(Money::toCents('499.995'))->toBe(50000)
        ->and(Money::toCents('1500'))->toBe(150000)
        ->and(Money::toCents('0.01'))->toBe(1)
        ->and(Money::format(150000))->toBe('R1,500')
        ->and(Money::format(49950))->toBe('R499.50')
        ->and(Money::toDecimal(100000))->toBe('1000.00');
});
