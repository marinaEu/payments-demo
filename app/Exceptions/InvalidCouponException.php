<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a coupon code is not found, expired, or inactive.
 */
class InvalidCouponException extends Exception
{
    /**
     * Creates an exception for non-existing coupon code.
     */
    public static function notFound(string $code): self
    {
        return new self("Il codice coupon '{$code}' non esiste.", 422);
    }

    /**
     * Creates an exception for an inactive coupon.
     */
    public static function inactive(string $code): self
    {
        return new self("Il codice coupon '{$code}' è attualmente disattivato.", 422);
    }

    /**
     * Creates an exception for an expired coupon.
     */
    public static function expired(string $code): self
    {
        return new self("Il codice coupon '{$code}' è scaduto.", 422);
    }
}
