<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\CartDTO;
use App\DTOs\CheckoutResultDTO;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidCouponException;
use App\Exceptions\OutOfStockException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\SkuNotFoundException;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the complete atomic checkout process under database transaction and pessimistic locking.
 */
final class ProcessCheckoutAction
{
    public function __construct(
        private readonly CalculateCartTotalAction $calculateCartTotalAction,
        private readonly PaymentGatewayInterface $paymentGateway
    ) {
    }

    /**
     * Executes the checkout pipeline atomically.
     *
     * @throws SkuNotFoundException
     * @throws OutOfStockException
     * @throws InvalidCouponException
     * @throws PaymentGatewayException
     */
    public function execute(CartDTO $cart, ?string $returnUrl = null, ?string $cancelUrl = null): CheckoutResultDTO
    {
        return DB::transaction(function () use ($cart, $returnUrl, $cancelUrl): CheckoutResultDTO {
            // 1. Calculate totals while acquiring pessimistic lock on inventory items
            $calcResult = $this->calculateCartTotalAction->execute($cart, lockStock: true);

            // 2. Persist order in 'pending' status; unique constraint on checkout_token blocks race condition duplicates
            $order = Order::create([
                'checkout_token' => $cart->checkoutToken,
                'status' => OrderStatus::PENDING,
                'subtotal_amount' => $calcResult->subtotal,
                'discount_amount' => $calcResult->discountAmount,
                'tax_amount' => $calcResult->taxAmount,
                'total_amount' => $calcResult->totalAmount,
                'currency' => $cart->currency,
                'coupon_id' => $calcResult->appliedCoupon?->id,
                'customer_email' => $cart->customerEmail,
            ]);

            // 3. Persist order line items snapshot
            foreach ($calcResult->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'sku_id' => $item->sku->id,
                    'sku_code' => $item->sku->code,
                    'sku_name' => $item->sku->name,
                    'unit_price' => $item->unitPrice,
                    'quantity' => $item->quantity,
                    'total_price' => $item->totalPrice,
                ]);
            }

            // 4. Initiate payment order with PayPal gateway (revertable if call fails)
            $paymentResponse = $this->paymentGateway->createOrder(
                order: $order,
                returnUrl: $returnUrl,
                cancelUrl: $cancelUrl
            );

            // 5. Update order with PayPal Order ID
            $order->update([
                'provider_order_id' => $paymentResponse->providerOrderId,
            ]);

            return new CheckoutResultDTO(
                orderId: $order->id,
                status: $order->status->value,
                providerOrderId: $paymentResponse->providerOrderId,
                redirectUrl: $paymentResponse->redirectUrl,
                totalAmount: (float) $order->total_amount,
                currency: $order->currency,
                itemsCount: count($calcResult->items)
            );
        });
    }
}
