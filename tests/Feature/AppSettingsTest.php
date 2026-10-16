<?php

/**
 * Operator-managed app settings (currently Mailgun). Covers:
 *   - The AppSetting model: get/put/forget with encryption + caching.
 *   - MailConfigurator: overlays DB values on top of config() at runtime.
 *   - Operator UI: GET /op/settings/mail, POST updates, POST test email.
 */

use App\Mail\StorefrontVerifyCode;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\MailConfigurator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function opUser(bool $isOperator = true): User
{
    return User::factory()->create([
        'email' => 'ops-'.bin2hex(random_bytes(3)).'@example.com',
        'password' => Hash::make('x'),
        'is_operator' => $isOperator,
    ]);
}

beforeEach(function () {
    Cache::flush();
});

/* -----------------------------------------------------------------------
 * AppSetting model
 * ----------------------------------------------------------------------- */

it('stores and reads back a plain setting', function () {
    AppSetting::put('mail.from.address', 'noreply@roll4it.app');

    expect(AppSetting::get('mail.from.address'))->toBe('noreply@roll4it.app');
});

it('encrypts a secret at rest and decrypts on read', function () {
    // Test fixture only: this value is intentionally formatted so GitHub's
    // secret scanner does not mistake it for a real Mailgun API key.
    $fixture = 'TEST_FIXTURE_mg_plaintext_7';
    AppSetting::put('mail.mailgun.secret', $fixture, encrypted: true);

    $row = AppSetting::query()->where('key', 'mail.mailgun.secret')->first();
    expect($row->encrypted)->toBeTrue()
        ->and($row->value)->not->toBe($fixture)                     // ciphertext, not plaintext
        ->and(Crypt::decryptString($row->value))->toBe($fixture)
        ->and(AppSetting::get('mail.mailgun.secret'))->toBe($fixture);
});

it('returns the default when nothing is stored', function () {
    expect(AppSetting::get('mail.from.address', 'fallback@example.com'))->toBe('fallback@example.com');
});

it('forgets a setting and clears the cached read', function () {
    AppSetting::put('mail.from.name', 'Roll4It');
    expect(AppSetting::get('mail.from.name'))->toBe('Roll4It');

    AppSetting::forget('mail.from.name');

    expect(AppSetting::get('mail.from.name'))->toBeNull();
});

/* -----------------------------------------------------------------------
 * MailConfigurator
 * ----------------------------------------------------------------------- */

it('overlays stored Mailgun credentials on top of the .env config', function () {
    config([
        'mail.default' => 'log',
        'services.mailgun.domain' => 'env-domain.example',
        'services.mailgun.secret' => 'env-secret',
    ]);
    AppSetting::put('mail.mailer', 'mailgun');
    AppSetting::put('mail.mailgun.domain', 'db-domain.example');
    AppSetting::put('mail.mailgun.secret', 'db-secret-super-safe', encrypted: true);

    app(MailConfigurator::class)->apply();

    expect(config('mail.default'))->toBe('mailgun')
        ->and(config('services.mailgun.domain'))->toBe('db-domain.example')
        ->and(config('services.mailgun.secret'))->toBe('db-secret-super-safe');
});

it('leaves .env values untouched when nothing is stored in app_settings', function () {
    config([
        'mail.default' => 'log',
        'services.mailgun.domain' => 'env-only.example',
    ]);

    app(MailConfigurator::class)->apply();

    expect(config('mail.default'))->toBe('log')
        ->and(config('services.mailgun.domain'))->toBe('env-only.example');
});

it('masks the Mailgun secret in the summary payload', function () {
    // Test fixture only — shaped like a Mailgun key but with filler characters
    // that keep GitHub's secret scanner from flagging the push.
    $fixture = 'MGTF-1234567890abcdef1234567890abcdef';
    config(['services.mailgun.secret' => $fixture]);

    $summary = app(MailConfigurator::class)->summary();

    expect($summary['mailgun_secret_set'])->toBeTrue()
        ->and($summary['mailgun_secret_masked'])->toStartWith('MGTF')
        ->and($summary['mailgun_secret_masked'])->toEndWith('cdef')
        ->and($summary['mailgun_secret_masked'])->toContain('•')
        ->and($summary['mailgun_secret_masked'])->not->toContain('1234567890');
});

/* -----------------------------------------------------------------------
 * Operator UI
 * ----------------------------------------------------------------------- */

it('refuses the mail settings page to a signed-out visitor', function () {
    $this->get('/op/settings/mail')->assertRedirect('/op/login');
});

it('refuses the mail settings page to a non-operator user', function () {
    $this->actingAs(opUser(isOperator: false))
        ->get('/op/settings/mail')
        ->assertRedirect('/op/login');
});

it('shows the mail settings form to an operator', function () {
    $this->actingAs(opUser())
        ->get('/op/settings/mail')
        ->assertOk()
        ->assertSee('Mail (Mailgun)')
        ->assertSee('Mailgun domain')
        ->assertSee('Send a test email');
});

it('saves the mail settings posted from the form and applies them on the next request', function () {
    // Fixture is deliberately not shaped like a real Mailgun API key so the
    // GitHub secret scanner leaves it alone.
    $fixture = 'MGTF_posted_from_form_abc123';
    $this->actingAs(opUser())
        ->post('/op/settings/mail', [
            'mail_mailer' => 'mailgun',
            'mail_from_address' => 'noreply@roll4it.app',
            'mail_from_name' => 'Roll4It',
            'mailgun_domain' => 'mg.roll4it.app',
            'mailgun_endpoint' => 'api.mailgun.net',
            'mailgun_secret' => $fixture,
        ])
        ->assertRedirect(route('op.settings.mail'));

    expect(AppSetting::get('mail.mailer'))->toBe('mailgun')
        ->and(AppSetting::get('mail.from.address'))->toBe('noreply@roll4it.app')
        ->and(AppSetting::get('mail.mailgun.domain'))->toBe('mg.roll4it.app')
        ->and(AppSetting::get('mail.mailgun.secret'))->toBe($fixture);

    // The secret must be stored encrypted on disk, not as plaintext.
    $row = AppSetting::query()->where('key', 'mail.mailgun.secret')->first();
    expect($row->value)->not->toBe($fixture)
        ->and($row->encrypted)->toBeTrue();
});

it('keeps the saved Mailgun secret when the form is re-submitted with the field blank', function () {
    $fixture = 'MGTF_keep_me_set';
    AppSetting::put('mail.mailgun.secret', $fixture, encrypted: true);

    $this->actingAs(opUser())
        ->post('/op/settings/mail', [
            'mail_mailer' => 'mailgun',
            'mail_from_address' => 'noreply@roll4it.app',
            'mailgun_domain' => 'mg.roll4it.app',
            'mailgun_secret' => '', // blank: should NOT wipe the stored secret
        ])
        ->assertRedirect(route('op.settings.mail'));

    expect(AppSetting::get('mail.mailgun.secret'))->toBe($fixture);
});

it('clears the Mailgun secret when the operator ticks the clear checkbox', function () {
    AppSetting::put('mail.mailgun.secret', 'MGTF_clear_me', encrypted: true);

    $this->actingAs(opUser())
        ->post('/op/settings/mail', [
            'clear_secret' => '1',
        ])
        ->assertRedirect(route('op.settings.mail'));

    expect(AppSetting::get('mail.mailgun.secret'))->toBeNull();
});

it('clears a scalar override when the operator submits an empty value', function () {
    AppSetting::put('mail.from.address', 'legacy@roll4it.app');

    $this->actingAs(opUser())
        ->post('/op/settings/mail', [
            'mail_from_address' => '',
        ])
        ->assertRedirect(route('op.settings.mail'));

    expect(AppSetting::get('mail.from.address'))->toBeNull();
});

it('sends a real test email through the configured mailer', function () {
    Mail::fake();

    $this->actingAs(opUser())
        ->post('/op/settings/mail/test', ['to' => 'ops@example.com'])
        ->assertRedirect(route('op.settings.mail'));

    // StorefrontVerifyCode implements ShouldQueue so assertQueued is the right assertion.
    Mail::assertQueued(StorefrontVerifyCode::class, function (StorefrontVerifyCode $mail) {
        return $mail->hasTo('ops@example.com')
            && preg_match('/^\d{6}$/', $mail->code) === 1;
    });
});

it('refuses to send a test email to an invalid address', function () {
    Mail::fake();

    $this->actingAs(opUser())
        ->post('/op/settings/mail/test', ['to' => 'not-an-email'])
        ->assertSessionHasErrors('to');

    Mail::assertNothingQueued();
});

/* -----------------------------------------------------------------------
 * Composer / transport wiring
 *
 * Regression guard: Laravel's MailManager will throw "Mailer [mailgun] is not
 * defined" if either (a) config/mail.php has no `mailgun` entry under
 * `mailers`, or (b) symfony/mailgun-mailer + symfony/http-client are not in
 * composer.json. We've been bitten by both; this test keeps the wiring honest.
 * ----------------------------------------------------------------------- */

it('resolves the Mailgun mailer out of the manager without missing-driver errors', function () {
    AppSetting::put('mail.mailgun.domain', 'mg.example.test');
    AppSetting::put('mail.mailgun.secret', 'TEST_FIXTURE_abcdef0123456789', encrypted: true);
    app(MailConfigurator::class)->apply();

    // Resolving the mailer out of the manager is what blew up in prod — if the
    // Symfony Mailgun transport isn't registered, this call throws
    // InvalidArgumentException("Mailer [mailgun] is not defined.") before any
    // actual send is attempted.
    $mailer = app('mail.manager')->mailer('mailgun');

    expect($mailer)->not->toBeNull();
});
