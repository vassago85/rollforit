<?php

namespace App\Promotion\Exceptions;

use RuntimeException;

/**
 * A roll that was refused before any outcome was allocated.
 */
class RollRejected extends RuntimeException
{
    public const CLOSED = 'closed';

    public const SOLD_OUT = 'sold_out';

    public const BELOW_MIN = 'below_min';

    public const NO_ELIGIBLE_ITEMS = 'no_eligible_items';

    public const RATE_LIMITED = 'rate_limited';

    public const CURRENCY = 'currency';

    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
