<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a payment gateway operation fails or times out.
 */
class PaymentGatewayException extends Exception
{
    public function __construct(string $message, int $code = 502)
    {
        parent::__construct($message, $code);
    }
}
