<?php

use App\Mail\MerchantBudgetDepleted;
use App\Mail\MerchantRollWon;
use App\Models\Block;
use App\Models\Shop;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Mail::fake();
});

function notifierRoll(Shop $shop, array $payload = [], array $headers = []): TestResponse
{
    // Pre-verify whatever email this roll uses so the Mailgun gate never gets
    // in the way of merchant-notification assertions.
    $email = $payload['email'] ?? 'customer@example.com';
    if ($email !== null && $email !== '') {
        preVerifyEmail($shop, $email);
    }

    return test()->withHeaders($headers + ['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload($payload));
}

function forceWin(Shop $shop): Block
{
    return Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1]);
}

it('emails the shop owner when a real customer wins', function () {
    $shop = makeShop();
    forceWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);

    notifierRoll($shop, ['email' => 'c@example.com'])->assertOk();

    Mail::assertQueued(MerchantRollWon::class, fn ($mail) => $mail->hasTo('owner@example.com')
        && $mail->roll->won === true
        && $mail->roll->is_test === false
    );
});

it('does not email on a losing roll', function () {
    $shop = makeShop();
    Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 10, 'next_position' => 1]);
    fakeShopify([1 => ['price' => '600.00']]);

    notifierRoll($shop, ['email' => 'c@example.com', 'picked_number' => 1])->assertOk();

    Mail::assertNothingQueued();
});

it('does not email on a test roll even when the preview forces a win', function () {
    $shop = makeShop();
    $issued = \App\Promotion\PreviewLinks::issue($shop, $shop->settings, 'win');
    $token = is_array($issued) ? ($issued['token'] ?? $issued['url'] ?? null) : $issued;
    fakeShopify([1 => ['price' => '600.00']]);

    notifierRoll($shop, ['email' => 'c@example.com', 'preview' => $token])->assertOk();

    Mail::assertNotQueued(MerchantRollWon::class);
    Mail::assertNotQueued(MerchantBudgetDepleted::class);
});

it('emails the shop owner the first time the daily budget is used up', function () {
    // Budget == max_payout, so one win uses the whole budget.
    $shop = makeShop(['daily_budget' => 100000, 'max_payout' => 100000]);
    forceWin($shop);
    fakeShopify([1 => ['price' => '2000.00']]);

    notifierRoll($shop, ['email' => 'first@example.com'])->assertOk();

    Mail::assertQueued(MerchantBudgetDepleted::class, fn ($mail) => $mail->hasTo('owner@example.com')
        && $mail->totalPaidOut === 100000
        && $mail->winners === 1
    );
});

it('includes the actual next promo date in the budget-depleted mail', function () {
    // Promo days = Friday only, so from any day the next opening is a Friday.
    $shop = makeShop([
        'daily_budget' => 100000,
        'max_payout' => 100000,
        'promo_days' => [5],
        'start_time' => '08:00',
        'end_time' => '17:00',
    ]);
    forceWin($shop);
    fakeShopify([1 => ['price' => '2000.00']]);

    notifierRoll($shop, ['email' => 'first@example.com'])->assertOk();

    Mail::assertQueued(MerchantBudgetDepleted::class, function ($mail) use ($shop) {
        return $mail->nextOpening !== null
            && $mail->nextOpening->timezone->getName() === $shop->timezone
            && $mail->nextOpening->dayOfWeekIso === 5 // Friday
            && $mail->nextOpening->format('H:i') === '08:00'
            && $mail->nextOpening->greaterThan(now($shop->timezone));
    });
});

it('only emails the budget notice once per promo day no matter how many rolls follow', function () {
    $shop = makeShop(['daily_budget' => 100000, 'max_payout' => 100000]);
    forceWin($shop);
    fakeShopify([1 => ['price' => '2000.00']]);

    notifierRoll($shop, ['email' => 'first@example.com'])->assertOk();
    // A follow-up roll from a different person hits the sold-out guard, but we still
    // confirm the cache lock holds: the notifier only sent the budget mail once.
    notifierRoll($shop, ['email' => 'second@example.com'])->assertStatus(422)->assertJson(['error' => 'sold_out']);

    Mail::assertQueued(MerchantBudgetDepleted::class, 1);
});

it('skips the win mail silently when the shop has no email on file', function () {
    $shop = makeShop(shop: ['email' => '']);
    forceWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);

    notifierRoll($shop, ['email' => 'c@example.com'])->assertOk();

    Mail::assertNothingQueued();
});
