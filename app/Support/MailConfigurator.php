<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Overlays operator-managed Mailgun credentials from the `app_settings` table
 * on top of the `.env`-driven defaults in `config/services.php` and
 * `config/mail.php`. Any value the operator has set wins; anything left blank
 * in the UI falls back to .env.
 *
 * Called from AppServiceProvider::boot() so every request gets the live config.
 */
final class MailConfigurator
{
    public const KEYS = [
        'mail.mailer',          // "mailgun" | "smtp" | "log" | ...
        'mail.from.address',
        'mail.from.name',
        'mail.mailgun.domain',
        'mail.mailgun.secret',
        'mail.mailgun.endpoint',
    ];

    public function __construct(private readonly ConfigRepository $config) {}

    public function apply(): void
    {
        // The settings table may not exist yet when `artisan migrate` is building
        // the schema; swallow and move on so first-boot does not crash.
        try {
            $mailer = AppSetting::get('mail.mailer');
            if (is_string($mailer) && $mailer !== '') {
                $this->config->set('mail.default', $mailer);
            }

            $fromAddress = AppSetting::get('mail.from.address');
            if (is_string($fromAddress) && $fromAddress !== '') {
                $this->config->set('mail.from.address', $fromAddress);
            }

            $fromName = AppSetting::get('mail.from.name');
            if (is_string($fromName) && $fromName !== '') {
                $this->config->set('mail.from.name', $fromName);
            }

            $domain = AppSetting::get('mail.mailgun.domain');
            if (is_string($domain) && $domain !== '') {
                $this->config->set('services.mailgun.domain', $domain);
            }

            $secret = AppSetting::get('mail.mailgun.secret');
            if (is_string($secret) && $secret !== '') {
                $this->config->set('services.mailgun.secret', $secret);
            }

            $endpoint = AppSetting::get('mail.mailgun.endpoint');
            if (is_string($endpoint) && $endpoint !== '') {
                $this->config->set('services.mailgun.endpoint', $endpoint);
            }
        } catch (Throwable) {
            // Table missing or DB down — fall back silently to .env defaults.
        }
    }

    /**
     * Summary for the admin UI: what the running app is actually using right now,
     * with the API secret masked. Combines DB overrides and .env fallbacks.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'mailer' => (string) $this->config->get('mail.default', 'log'),
            'from_address' => (string) $this->config->get('mail.from.address', ''),
            'from_name' => (string) $this->config->get('mail.from.name', ''),
            'mailgun_domain' => (string) $this->config->get('services.mailgun.domain', ''),
            'mailgun_endpoint' => (string) $this->config->get('services.mailgun.endpoint', 'api.mailgun.net'),
            'mailgun_secret_masked' => self::mask((string) $this->config->get('services.mailgun.secret', '')),
            'mailgun_secret_set' => $this->config->get('services.mailgun.secret') !== null
                && $this->config->get('services.mailgun.secret') !== '',
            'overridden_in_db' => $this->overriddenKeys(),
        ];
    }

    /**
     * @return list<string>
     */
    private function overriddenKeys(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $value = AppSetting::get($key);
            if (is_string($value) && $value !== '') {
                $out[] = $key;
            }
        }

        return $out;
    }

    private static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        if (strlen($secret) <= 8) {
            return str_repeat('•', strlen($secret));
        }

        return substr($secret, 0, 4).str_repeat('•', max(4, strlen($secret) - 8)).substr($secret, -4);
    }
}
