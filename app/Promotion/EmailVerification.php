<?php

namespace App\Promotion;

use App\Mail\StorefrontVerifyCode;
use App\Models\EmailVerifyAttempt;
use App\Models\Shop;
use App\Promotion\Exceptions\VerificationRejected;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One-time email confirmation on the storefront widget. We send a 6-digit code
 * via Mailgun, hold it in cache for 10 minutes, and remember a "this email was
 * verified today" flag for 24 hours so a shopper never confirms twice in the
 * same promo day.
 *
 * The flag is server-side truth: the widget never stores anything cryptographic.
 * On every roll we re-check the flag by shop + hashed email + promo_date.
 */
class EmailVerification
{
    public const CODE_TTL_MINUTES = 10;

    public const VERIFIED_TTL_HOURS = 24;

    public const MAX_ATTEMPTS = 5;

    public const DAILY_SENDS_PER_IP = 5;

    public const DAILY_SENDS_PER_EMAIL = 5;

    public const MIN_SECONDS_BETWEEN_SENDS = 30;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Issue a fresh 6-digit code and queue the Mailgun email.
     *
     * @return array{expires_at: string, cooldown_seconds: int}
     *
     * @throws VerificationRejected
     */
    public function sendCode(Shop $shop, string $email, ?string $ip): array
    {
        $email = IdentityHasher::normaliseEmail($email);
        $context = ['shop_id' => $shop->id, 'email' => $email, 'ip' => $ip];

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log->info('EmailVerification.sendCode rejected: invalid_email', $context);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_SEND, 'invalid_email');
            throw new VerificationRejected('invalid_email', 'That email does not look right. Please check and try again.');
        }

        $emailHash = IdentityHasher::hashEmail($email);
        $burstKey = "verify:send:ip:{$shop->id}:".($ip ?? 'none');
        $emailKey = "verify:send:email:{$shop->id}:{$emailHash}";

        if ($ip && RateLimiter::tooManyAttempts($burstKey, self::DAILY_SENDS_PER_IP)) {
            $this->log->info('EmailVerification.sendCode rejected: rate_limited (ip)', $context);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_SEND, 'rate_limited_ip');
            throw new VerificationRejected('rate_limited', 'Too many confirmation codes from this connection. Please try again later.', 429);
        }
        if (RateLimiter::tooManyAttempts($emailKey, self::DAILY_SENDS_PER_EMAIL)) {
            $this->log->info('EmailVerification.sendCode rejected: rate_limited (email)', $context);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_SEND, 'rate_limited_email');
            throw new VerificationRejected('rate_limited', 'Too many confirmation codes for this email. Please try again later.', 429);
        }

        $cooldownKey = $this->cooldownKey($shop, $emailHash);
        $remaining = $this->cooldownSeconds($cooldownKey);
        if ($remaining > 0) {
            $this->log->info('EmailVerification.sendCode rejected: cooldown', $context + ['cooldown_seconds' => $remaining]);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_SEND, 'cooldown');
            throw new VerificationRejected('cooldown', "Please wait {$remaining} seconds before requesting another code.", 429);
        }

        $code = $this->newCode();
        $expiresAt = CarbonImmutable::now()->addMinutes(self::CODE_TTL_MINUTES);

        $this->cache->put($this->codeKey($shop, $emailHash), [
            'hash' => password_hash($code, PASSWORD_BCRYPT),
            'email' => $email,
            'attempts' => 0,
        ], self::CODE_TTL_MINUTES * 60);

        $this->cache->put($cooldownKey, 1, self::MIN_SECONDS_BETWEEN_SENDS);

        if ($ip) {
            RateLimiter::hit($burstKey, 86400);
        }
        RateLimiter::hit($emailKey, 86400);

        try {
            $this->mailer->send(new StorefrontVerifyCode($shop, $email, $code, $expiresAt));
        } catch (Throwable $e) {
            // Mail transport problems should surface to the shopper (they can't verify
            // without the code) rather than being swallowed like merchant notifications.
            $this->log->warning('EmailVerification.sendCode mail transport failed', $context + ['error' => $e->getMessage()]);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_SEND, 'mail_failed');
            throw new VerificationRejected('mail_failed', 'We could not send a confirmation code just now. Please try again.', 502);
        }

        // Code value itself is never logged — that's a shared secret with the shopper.
        $this->log->info('EmailVerification.sendCode queued', $context + ['expires_at' => $expiresAt->toIso8601String()]);
        $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_SEND, 'queued');

        return [
            'expires_at' => $expiresAt->toIso8601String(),
            'cooldown_seconds' => self::MIN_SECONDS_BETWEEN_SENDS,
        ];
    }

    /**
     * Confirm a 6-digit code. On success the shopper is "verified" for this
     * shop + email + promo_date combination for the next 24 hours.
     *
     * @throws VerificationRejected
     */
    public function verifyCode(Shop $shop, string $email, string $code, string $promoDate, ?string $ip = null): void
    {
        $email = IdentityHasher::normaliseEmail($email);
        $context = ['shop_id' => $shop->id, 'email' => $email, 'promo_date' => $promoDate];

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log->info('EmailVerification.verifyCode rejected: invalid_email', $context);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_VERIFY, 'invalid_email');
            throw new VerificationRejected('invalid_email', 'That email does not look right. Please check and try again.');
        }

        $emailHash = IdentityHasher::hashEmail($email);
        $codeKey = $this->codeKey($shop, $emailHash);
        $entry = $this->cache->get($codeKey);

        if (! is_array($entry)) {
            $this->log->info('EmailVerification.verifyCode rejected: code_expired', $context);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_VERIFY, 'code_expired');
            throw new VerificationRejected('code_expired', 'That confirmation code has expired. Please request a new one.');
        }

        $attempts = (int) ($entry['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->cache->forget($codeKey);
            $this->log->info('EmailVerification.verifyCode rejected: too_many_attempts', $context + ['attempts' => $attempts]);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_VERIFY, 'too_many_attempts', $attempts);
            throw new VerificationRejected('too_many_attempts', 'Too many wrong codes. Please request a new one.');
        }

        $hash = (string) ($entry['hash'] ?? '');
        if ($hash === '' || ! password_verify(trim($code), $hash)) {
            $entry['attempts'] = $attempts + 1;
            $this->cache->put($codeKey, $entry, self::CODE_TTL_MINUTES * 60);
            $this->log->info('EmailVerification.verifyCode rejected: wrong_code', $context + ['attempt' => $entry['attempts']]);
            $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_VERIFY, 'wrong_code', $entry['attempts']);
            throw new VerificationRejected('wrong_code', 'That code is not right. Please check your inbox and try again.');
        }

        $this->cache->forget($codeKey);
        $this->cache->put(
            $this->verifiedKey($shop, $emailHash, $promoDate),
            ['email' => $email, 'verified_at' => CarbonImmutable::now()->toIso8601String()],
            self::VERIFIED_TTL_HOURS * 3600,
        );

        $this->log->info('EmailVerification.verifyCode confirmed', $context);
        $this->recordAttempt($shop, $email, $ip, EmailVerifyAttempt::ACTION_VERIFY, 'confirmed');
    }

    /**
     * Append an attempt row for the reporting UI. We never want this to break
     * the shopper's verify flow, so a DB hiccup is logged and swallowed —
     * the Mailgun send already happened, so the shopper still gets their code.
     */
    private function recordAttempt(Shop $shop, ?string $email, ?string $ip, string $action, string $outcome, ?int $attemptNumber = null): void
    {
        try {
            EmailVerifyAttempt::create([
                'shop_id' => $shop->id,
                'email' => $email !== '' ? $email : null,
                'ip' => $ip,
                'action' => $action,
                'outcome' => $outcome,
                'attempt_number' => $attemptNumber,
            ]);
        } catch (Throwable $e) {
            $this->log->warning('EmailVerification could not persist attempt row', [
                'shop_id' => $shop->id,
                'email' => $email,
                'action' => $action,
                'outcome' => $outcome,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * True when this shopper has already confirmed this email for this promo day.
     */
    public function isVerified(Shop $shop, string $email, string $promoDate): bool
    {
        $email = IdentityHasher::normaliseEmail($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $this->cache->has($this->verifiedKey($shop, IdentityHasher::hashEmail($email), $promoDate));
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function codeKey(Shop $shop, string $emailHash): string
    {
        return "verify:code:{$shop->id}:{$emailHash}";
    }

    private function cooldownKey(Shop $shop, string $emailHash): string
    {
        return "verify:cooldown:{$shop->id}:{$emailHash}";
    }

    private function verifiedKey(Shop $shop, string $emailHash, string $promoDate): string
    {
        return "verify:ok:{$shop->id}:{$emailHash}:{$promoDate}";
    }

    private function cooldownSeconds(string $key): int
    {
        if (! $this->cache->has($key)) {
            return 0;
        }

        // We don't rely on TTL introspection (not portable across stores); the
        // cooldown value is the source of truth.
        return self::MIN_SECONDS_BETWEEN_SENDS;
    }
}
