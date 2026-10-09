<?php

namespace App\Shopify\Exceptions;

use RuntimeException;

class ShopifyApiException extends RuntimeException
{
    /**
     * @param  array<int, mixed>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [], public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
