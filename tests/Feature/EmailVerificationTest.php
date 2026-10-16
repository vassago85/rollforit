<?php

/**
 * Mailgun-backed email confirmation for the storefront widget. A guest shopper
 * must enter their email, receive a 6-digit code, and type it back before the
 * roll endpoint will accept their roll. This locks in the whole round trip.
 */

use App\Mail\StorefrontVerifyCode;
use App\Models\Block;
use App\Models\EmailVerifyAttempt;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\EmailVerification;
use App\Promotion\Exceptions\VerificationRejected;
use App\Promotion\IdentityHasher;
use App\Promotion\PreviewLinks;
use App\Promotion\PromoWindow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    RateLimiter::clear('verify:send:ip:');
});

function sendCode(Shop $shop, string $email, array $headers = []): TestResponse
{
    return test()->withHeaders($headers + ['Accept' => 'application/json'])
        ->postJson(
            '/api/proxy/email/send-code?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]),
            ['email' => $email],
        );
}

function verifyCode(Shop $shop, string $email, string $code, array $headers = []): TestResponse
{
    return test()->withHeaders($headers + ['Accept' => 'application/json'])
        ->postJson(
            '/api/proxy/email/verify-code?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]),
            ['email' => $email, 'code' => $code],
        );
}

function latestCodeFor(Shop $shop, string $email): string
{
    $hash = IdentityHasher::hashEmail($email);
    $entry = Cache::get("verify:code:{$shop->id}:{$hash}");

    // We only know the bcrypt'd hash; brute-force the 6-digit space in-test to
    // recover the plaintext code. Fast enough and keeps the test agnostic to
    // the code-generation implementation.
    foreach (range(0, 999999) as $candidate) {
        $padded = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);
        if (password_verify($padded, (string) ($entry['hash'] ?? ''))) {
            return $padded;
        }
    }

    throw new RuntimeException('Could not recover the 6-digit code from cache.');
}

/**
 * Sends the mailable, grabs the code off the queued Mailable itself. Faster
 * and deterministic (no 1M-iteration brute-force).
 */
function peekQueuedCode(string $email): string
{
    $code = null;
    Mail::assertQueued(StorefrontVerifyCode::class, function ($mail) use ($email, &$code) {
        if (strcasecmp($mail->email, $email) !== 0) {
            return false;
        }
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

/* -----------------------------------------------------------------------
 * The service: send / verify round trip
 * ----------------------------------------------------------------------- */

it('queues a 6-digit code via Mailgun when the shopper requests one', function () {
    $shop = makeShop();

    sendCode($shop, 'shopper@example.com')->assertOk()->assertJsonStructure(['sent', 'expires_at', 'cooldown_seconds']);

    Mail::assertQueued(StorefrontVerifyCode::class, function (StorefrontVerifyCode $mail) {
        return $mail->email === 'shopper@example.com'
            && preg_match('/^\d{6}$/', $mail->code) === 1;
    });
});

it('sends the confirmation email branded as the shop with the shop email as reply-to', function () {
    // The shopper should see the SHOP's name in their inbox, not "Roll4It".
    // We can't send literally AS the shop (DKIM/SPF would fail on our Mailgun
    // domain), so the display name is the shop and Reply-To routes replies to
    // the shop's own mailbox.
    config([
        'mail.from.address' => 'noreply@roll4it.app',
        'mail.from.name' => 'Roll4It',
    ]);
    $shop = makeShop();

    sendCode($shop, 'shopper@example.com')->assertOk();

    Mail::assertQueued(StorefrontVerifyCode::class, function (StorefrontVerifyCode $mail) use ($shop) {
        $mail->assertFrom('noreply@roll4it.app', $shop->name);
        $mail->assertHasReplyTo($shop->email, $shop->name);
        $mail->assertHasSubject("{$shop->name}: your confirmation code {$mail->code}");

        return true;
    });
});

it('verifies the correct code and remembers the shopper for the current promo day', function () {
    $shop = makeShop();
    sendCode($shop, 'shopper@example.com')->assertOk();
    $code = peekQueuedCode('shopper@example.com');

    verifyCode($shop, 'shopper@example.com', $code)
        ->assertOk()
        ->assertJson(['verified' => true, 'email' => 'shopper@example.com']);

    expect(app(EmailVerification::class)->isVerified(
        $shop,
        'shopper@example.com',
        (new PromoWindow($shop->settings, $shop->timezone))->promoDate(),
    ))->toBeTrue();
});

it('rejects a wrong code and never flips the shopper to verified', function () {
    $shop = makeShop();
    sendCode($shop, 'shopper@example.com')->assertOk();

    verifyCode($shop, 'shopper@example.com', '000000')
        ->assertStatus(422)
        ->assertJson(['error' => 'wrong_code']);

    expect(app(EmailVerification::class)->isVerified(
        $shop,
        'shopper@example.com',
        (new PromoWindow($shop->settings, $shop->timezone))->promoDate(),
    ))->toBeFalse();
});

it('locks the code after 5 wrong attempts and asks the shopper to request a new one', function () {
    $shop = makeShop();
    sendCode($shop, 'shopper@example.com')->assertOk();
    $code = peekQueuedCode('shopper@example.com');

    for ($i = 0; $i < EmailVerification::MAX_ATTEMPTS; $i++) {
        verifyCode($shop, 'shopper@example.com', '000000')->assertStatus(422)->assertJson(['error' => 'wrong_code']);
    }

    // Even the real code is useless now — the next call hits the attempt cap and
    // the cache row is purged in the same breath.
    verifyCode($shop, 'shopper@example.com', $code)
        ->assertStatus(422)
        ->assertJson(['error' => 'too_many_attempts']);

    // A second attempt after the purge sees nothing at all: code_expired.
    verifyCode($shop, 'shopper@example.com', $code)
        ->assertStatus(422)
        ->assertJson(['error' => 'code_expired']);
});

it('refuses to send a second code before the per-email cooldown elapses', function () {
    $shop = makeShop();
    sendCode($shop, 'shopper@example.com')->assertOk();

    sendCode($shop, 'shopper@example.com')
        ->assertStatus(429)
        ->assertJson(['error' => 'cooldown']);
});

/* -----------------------------------------------------------------------
 * The gate: /roll refuses an unverified email
 * ----------------------------------------------------------------------- */

it('refuses a roll when the shopper has not confirmed their email yet', function () {
    $shop = makeShop();
    Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1]);
    fakeShopify([1 => ['price' => '600.00']]);

    test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => 'guest@example.com']))
        ->assertStatus(403)
        ->assertJson(['error' => 'email_not_verified']);

    expect(Roll::count())->toBe(0);
});

it('refuses a roll with no email at all even when an IP is present', function () {
    $shop = makeShop();
    Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1]);
    fakeShopify([1 => ['price' => '600.00']]);

    test()->withHeaders(['Accept' => 'application/json', 'X-Forwarded-For' => '198.51.100.9'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertStatus(422)
        ->assertJson(['error' => 'email_required']);

    expect(Roll::count())->toBe(0);
});

it('lets the shopper roll once they have confirmed their email', function () {
    $shop = makeShop();
    Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1]);
    fakeShopify([1 => ['price' => '600.00']]);

    sendCode($shop, 'guest@example.com')->assertOk();
    $code = peekQueuedCode('guest@example.com');
    verifyCode($shop, 'guest@example.com', $code)->assertOk();

    test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => 'guest@example.com']))
        ->assertOk()
        ->assertJson(['won' => true]);

    expect(Roll::count())->toBe(1);
});

it('skips the challenge entirely for a signed-in Shopify customer', function () {
    $shop = makeShop();
    Block::create(['shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1]);
    fakeShopify([1 => ['price' => '600.00']]);

    // logged_in_customer_id travels on the signed proxy query, not the body.
    test()->withHeaders(['Accept' => 'application/json'])
        ->postJson(
            '/api/proxy/roll?'.proxyQuery($shop, [
                'nonce' => bin2hex(random_bytes(4)),
                'logged_in_customer_id' => '7788',
            ]),
            rollPayload(['email' => null]),
        )
        ->assertOk()
        ->assertJson(['won' => true]);

    expect(Roll::count())->toBe(1);
    // The merchant may receive a win notification — we only care that the
    // SHOPPER never received a confirmation code.
    Mail::assertNotQueued(StorefrontVerifyCode::class);
});

it('skips the challenge in merchant preview mode (test rolls bypass Mailgun entirely)', function () {
    $shop = makeShop();
    $preview = PreviewLinks::issue($shop, $shop->settings, 'win');
    fakeShopify([1 => ['price' => '600.00']]);

    test()->withHeaders(['Accept' => 'application/json'])
        ->postJson(
            '/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]),
            rollPayload(['email' => null, 'preview' => $preview['token']]),
        )
        ->assertOk()
        ->assertJson(['won' => true, 'test' => true]);

    Mail::assertNotQueued(StorefrontVerifyCode::class);
});

/* -----------------------------------------------------------------------
 * Status payload tells the widget which path to take
 * ----------------------------------------------------------------------- */

it('reports requires_email_verification=true to a guest widget', function () {
    $shop = makeShop();
    fakeShopify();

    $status = test()->getJson('/api/proxy/status?'.proxyQuery($shop))->assertOk()->json();

    expect($status)->toHaveKey('requires_email_verification')
        ->and($status['requires_email_verification'])->toBeTrue();
});

it('reports requires_email_verification=false to a signed-in Shopify customer', function () {
    $shop = makeShop();
    fakeShopify();

    $status = test()->getJson('/api/proxy/status?'.proxyQuery($shop, ['logged_in_customer_id' => '7788']))
        ->assertOk()->json();

    expect($status['requires_email_verification'])->toBeFalse();
});

/* -----------------------------------------------------------------------
 * Attempt log — every send and verify (success or failure) is persisted so
 * the merchant can see "who tried, which email, which failure" in reporting.
 * ----------------------------------------------------------------------- */

it('persists an attempt row for a successful send and for a successful verify', function () {
    $shop = makeShop();

    sendCode($shop, 'shopper@example.com')->assertOk();
    $code = peekQueuedCode('shopper@example.com');
    verifyCode($shop, 'shopper@example.com', $code)->assertOk();

    $rows = EmailVerifyAttempt::where('shop_id', $shop->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->action)->toBe('send')
        ->and($rows[0]->outcome)->toBe('queued')
        ->and($rows[0]->email)->toBe('shopper@example.com')
        ->and($rows[1]->action)->toBe('verify')
        ->and($rows[1]->outcome)->toBe('confirmed')
        ->and($rows[1]->email)->toBe('shopper@example.com');
});

it('persists failure attempts with the right outcome code (cooldown and wrong_code via HTTP)', function () {
    $shop = makeShop();

    sendCode($shop, 'shopper@example.com')->assertOk();
    sendCode($shop, 'shopper@example.com')->assertStatus(429)->assertJson(['error' => 'cooldown']);
    verifyCode($shop, 'shopper@example.com', '000000')->assertStatus(422);

    $rows = EmailVerifyAttempt::where('shop_id', $shop->id)->orderBy('id')->get();
    expect($rows->pluck('action')->all())->toBe(['send', 'send', 'verify'])
        ->and($rows->pluck('outcome')->all())->toBe(['queued', 'cooldown', 'wrong_code'])
        ->and($rows[2]->attempt_number)->toBe(1);
});

it('persists an invalid_email attempt when the service is called with a bad email directly', function () {
    // The HTTP controller rejects malformed emails with its own validator before
    // the service ever sees them, so this edge is exercised at the service seam
    // where the invalid_email outcome actually gets written.
    $shop = makeShop();
    $service = app(EmailVerification::class);

    try {
        $service->sendCode($shop, 'not-an-email', '198.51.100.1');
    } catch (VerificationRejected $e) {
        expect($e->reason)->toBe('invalid_email');
    }

    $row = EmailVerifyAttempt::where('shop_id', $shop->id)->first();
    expect($row->action)->toBe('send')
        ->and($row->outcome)->toBe('invalid_email')
        ->and($row->email)->toBe('not-an-email')
        ->and($row->ip)->toBe('198.51.100.1');
});

it('records attempt_number for repeated wrong-code tries', function () {
    $shop = makeShop();
    sendCode($shop, 'shopper@example.com')->assertOk();

    verifyCode($shop, 'shopper@example.com', '000000')->assertStatus(422);
    verifyCode($shop, 'shopper@example.com', '000000')->assertStatus(422);
    verifyCode($shop, 'shopper@example.com', '000000')->assertStatus(422);

    $wrong = EmailVerifyAttempt::where('shop_id', $shop->id)
        ->where('action', 'verify')
        ->where('outcome', 'wrong_code')
        ->orderBy('id')
        ->pluck('attempt_number')
        ->all();

    expect($wrong)->toBe([1, 2, 3]);
});
