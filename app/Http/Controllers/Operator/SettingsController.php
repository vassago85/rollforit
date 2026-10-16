<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Mail\StorefrontVerifyCode;
use App\Models\AppSetting;
use App\Models\Shop;
use App\Support\MailConfigurator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * Operator-only app settings: Mailgun credentials and the From address Roll4It
 * uses for every email it sends. These values override whatever is in `.env`.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly MailConfigurator $mail) {}

    public function mail(): View
    {
        return view('op.settings.mail', [
            'summary' => $this->mail->summary(),
            'env_fallbacks' => [
                'mail.mailer' => env('MAIL_MAILER'),
                'mail.from.address' => env('MAIL_FROM_ADDRESS'),
                'mail.from.name' => env('MAIL_FROM_NAME'),
                'mail.mailgun.domain' => env('MAILGUN_DOMAIN'),
                'mail.mailgun.endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
                'mail.mailgun.secret_set' => (bool) env('MAILGUN_SECRET'),
            ],
        ]);
    }

    public function updateMail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mail_mailer' => ['nullable', 'in:log,array,smtp,mailgun,ses'],
            'mail_from_address' => ['nullable', 'email:rfc', 'max:254'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'mailgun_domain' => ['nullable', 'string', 'max:253'],
            'mailgun_endpoint' => ['nullable', 'string', 'max:253'],
            'mailgun_secret' => ['nullable', 'string', 'max:512'],
            'clear_secret' => ['sometimes', 'boolean'],
        ]);

        // Scalar config: an empty string from the form means "delete this
        // override and go back to .env", so we treat null and '' the same way.
        $putOrClear = function (string $key, ?string $value): void {
            if ($value === null || $value === '') {
                AppSetting::forget($key);

                return;
            }
            AppSetting::put($key, $value, false);
        };

        $putOrClear('mail.mailer', $data['mail_mailer'] ?? null);
        $putOrClear('mail.from.address', $data['mail_from_address'] ?? null);
        $putOrClear('mail.from.name', $data['mail_from_name'] ?? null);
        $putOrClear('mail.mailgun.domain', $data['mailgun_domain'] ?? null);
        $putOrClear('mail.mailgun.endpoint', $data['mailgun_endpoint'] ?? null);

        // The Mailgun secret has special handling:
        //  - empty input + no "clear" checkbox => leave the stored secret alone
        //    (so re-opening the form and clicking Save doesn't wipe the key),
        //  - "clear" checkbox => delete the stored secret and fall back to .env,
        //  - non-empty input => replace the stored secret (encrypted at rest).
        if (! empty($data['clear_secret'])) {
            AppSetting::forget('mail.mailgun.secret');
        } elseif (! empty($data['mailgun_secret'])) {
            AppSetting::put('mail.mailgun.secret', $data['mailgun_secret'], true);
        }

        return redirect()
            ->route('op.settings.mail')
            ->with('status', 'Mail settings saved. They apply to every email Roll4It sends from now on.');
    }

    /**
     * Fires a real StorefrontVerifyCode mail to the operator-supplied address
     * so they can confirm their Mailgun creds work end-to-end. Uses a dummy
     * code; nothing in the DB changes.
     */
    public function testMail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email:rfc', 'max:254'],
        ]);

        // Re-apply in case the operator just saved on a different browser tab.
        $this->mail->apply();

        $shop = Shop::query()->whereNotNull('access_token')->orderByDesc('installed_at')->first()
            ?? new Shop(['name' => 'Roll4It', 'email' => (string) config('mail.from.address')]);

        $mailable = new StorefrontVerifyCode(
            shop: $shop,
            email: $data['to'],
            code: str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            expiresAt: CarbonImmutable::now()->addMinutes(10),
        );

        try {
            Mail::send($mailable);
        } catch (Throwable $e) {
            return redirect()
                ->route('op.settings.mail')
                ->with('error', 'Test email failed: '.$e->getMessage());
        }

        return redirect()
            ->route('op.settings.mail')
            ->with('status', "Test email sent to {$data['to']} via ".config('mail.default').'. Check the inbox.');
    }
}
