<?php

namespace App\Promotion\Exceptions;

use RuntimeException;

/**
 * Thrown by EmailVerification when a send-code or verify-code request cannot
 * complete. $reason is the short machine code the widget switches on.
 */
class VerificationRejected extends RuntimeException
{
    public const INVALID_EMAIL = 'invalid_email';

    public const RATE_LIMITED = 'rate_limited';

    public const COOLDOWN = 'cooldown';

    public const CODE_EXPIRED = 'code_expired';

    public const WRONG_CODE = 'wrong_code';

    public const TOO_MANY_ATTEMPTS = 'too_many_attempts';

    public const MAIL_FAILED = 'mail_failed';

    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
