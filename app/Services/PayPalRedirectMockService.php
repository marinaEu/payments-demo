<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentRedirectResponseDTO;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Structured mock implementation of PayPal REST v2 Orders API in redirect mode with outbound audit trail.
 */
final class PayPalRedirectMockService implements PaymentGatewayInterface
{
    /**
     * Creates a mock PayPal order, logs the outbound interaction, and returns the approval redirect URL.
     *
     * @throws PaymentGatewayException
     */
    public function createOrder(Order $order, ?string $returnUrl = null, ?string $cancelUrl = null): PaymentRedirectResponseDTO
    {
        $sanitizedEmail = $order->customer_email !== null
            ? preg_replace('/(?<=.).(?=.*@)/u', '*', $order->customer_email)
            : null;

        // 1. Audit Log: Outbound Request to PayPal Orders v2 API (Sanitized PII)
        Log::channel('paypal_audit')->info("POST /v2/checkout/orders [INIT_TRANSACTION]", [
            'internal_order_id' => $order->id,
            'checkout_token' => $order->checkout_token,
            'amount' => (float) $order->total_amount,
            'currency' => $order->currency,
            'customer_email_sanitized' => $sanitizedEmail,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);

        $totalAmount = (float) $order->total_amount;

        if ($totalAmount <= 0.0) {
            $errorMsg = "L'importo dell'ordine deve essere maggiore di zero per procedere al pagamento.";
            Log::channel('paypal_audit')->warning("POST /v2/checkout/orders [INVALID_AMOUNT]", [
                'internal_order_id' => $order->id,
                'amount' => $totalAmount,
            ]);

            throw new PaymentGatewayException($errorMsg, 422);
        }

        // Simulates provider failure for resilience/testing when specific test email is used
        if ($order->customer_email === 'fail_paypal@test.com') {
            $errorMsg = "Simulazione errore gateway PayPal: Timeout o servizio temporaneamente non disponibile.";
            Log::channel('paypal_audit')->error("POST /v2/checkout/orders [PROVIDER_TIMEOUT_SIMULATED]", [
                'internal_order_id' => $order->id,
                'status_code' => 502,
                'error' => $errorMsg,
            ]);

            throw new PaymentGatewayException($errorMsg, 502);
        }

        // Generates realistic PayPal Order ID format (e.g., PAYPAL_MOCK_5O190127TN364715T)
        $randomToken = strtoupper(Str::random(17));
        $providerOrderId = "PAYPAL_MOCK_{$randomToken}";

        // Standard PayPal sandbox approval URL
        $redirectUrl = "https://www.sandbox.paypal.com/checkoutnow?token={$providerOrderId}";

        // 2. Audit Log: Inbound Response received from PayPal API
        Log::channel('paypal_audit')->info("POST /v2/checkout/orders [ORDER_CREATED_201]", [
            'internal_order_id' => $order->id,
            'paypal_order_id' => $providerOrderId,
            'paypal_status' => 'CREATED',
            'redirect_url' => $redirectUrl,
        ]);

        return new PaymentRedirectResponseDTO(
            providerOrderId: $providerOrderId,
            redirectUrl: $redirectUrl,
            status: 'CREATED'
        );
    }
}
