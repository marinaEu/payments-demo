<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a payment gateway operation fails or times out.
 */
class PaymentGatewayException extends Exception
{
    public function __construct(string $message, int $code = 502)
    {
        parent::__construct($message, $code);
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        $statusCode = ($this->getCode() >= 400 && $this->getCode() < 600) ? $this->getCode() : 502;

        return response()->json([
            'error' => 'payment_gateway_error',
            'message' => $this->getMessage(),
        ], $statusCode);
    }
}
