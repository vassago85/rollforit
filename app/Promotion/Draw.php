<?php

namespace App\Promotion;

/**
 * The two random draws the double-or-nothing game owns.
 * Bound in the container so tests can fix the outcome.
 */
class Draw
{
    public function winningNumber(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    /** A fair coin. True means the shopper keeps the higher prize. */
    public function doubles(): bool
    {
        return random_int(0, 1) === 1;
    }
}
