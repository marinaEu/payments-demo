<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentRedirectResponseDTO;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Structured mock implementation of PayPal REST v2 Orders API in redirect mode.
 */
final class PayPalRedirectMockService implements PaymentGatewayInterface
{
    /**
     * Creates a mock PayPal order and returns the approval redirect URL.
     *
     * @throws PaymentGatewayException
     */
    public function createOrder(Order $order, ?string $returnUrl = null, ?string $cancelUrl = null): PaymentRedirectResponseDTO
    {
        $totalAmount = (float) $order->total_amount;

        if ($totalAmount <= 0.0) {
            throw new PaymentGatewayException("L'importo dell'ordine deve essere maggiore di zero per procedere al pagamento.", 422);
        }

        // Simulates provider failure for resilience/testing when specific test email is used
        if ($order->customer_email === 'fail_paypal@test.com') {
            throw new PaymentGatewayException("Simulazione errore gateway PayPal: Timeout o servizio temporaneamente non disponibile.", 502);
        }

        // Generates realistic PayPal Order ID format (e.g., PAYPAL_MOCK_5O190127TN364715T)
        $randomToken = strtoupper(Str::random(17));
        $providerOrderId = "PAYPAL_MOCK_{$randomToken}";

        // Standard PayPal sandbox approval URL
        $redirectUrl = "https://www.sandbox.paypal.com/checkoutnow?token={$providerOrderId}";

        return new PaymentRedirectResponseDTO(
            providerOrderId: $providerOrderId,
            redirectUrl: $redirectUrl,
            status: 'CREATED'
        );
    }
}
