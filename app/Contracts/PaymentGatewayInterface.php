<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\PaymentRedirectResponseDTO;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;

/**
 * Contract for payment gateway providers operating in redirect mode.
 */
interface PaymentGatewayInterface
{
    /**
     * Initiates a payment order with the external gateway and returns the approval redirect URL.
     *
     * @throws PaymentGatewayException
     */
    public function createOrder(Order $order, ?string $returnUrl = null, ?string $cancelUrl = null): PaymentRedirectResponseDTO;
}
