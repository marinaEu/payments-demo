<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Encapsulates the response from a payment gateway when initiating an order in redirect mode.
 */
final readonly class PaymentRedirectResponseDTO
{
    public function __construct(
        public string $providerOrderId,
        public string $redirectUrl,
        public string $status = 'CREATED'
    ) {
    }
}
