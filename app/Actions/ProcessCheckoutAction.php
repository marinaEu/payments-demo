<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\CartDTO;
use App\DTOs\CheckoutResultDTO;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\PaymentFailed;
use App\Exceptions\InvalidCouponException;
use App\Exceptions\OutOfStockException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\SkuNotFoundException;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates the complete checkout process using a two-phase decoupled transaction pattern.
 *
 * Database locks and transactions are strictly separated from external HTTP network I/O
 * to prevent connection pool starvation. Preserves strict financial audit trails without hard deletions.
 */
final class ProcessCheckoutAction
{
    public function __construct(
        private readonly CheckStockAction $checkStockAction,
        private readonly ValidateCouponAction $validateCouponAction,
        private readonly CalculateCartTotalAction $calculateCartTotalAction,
        private readonly PaymentGatewayInterface $paymentGateway
    ) {
    }

    /**
     * Executes the checkout pipeline with atomic DB reservation, bulk insertion, and decoupled payment.
     *
     * @throws SkuNotFoundException
     * @throws OutOfStockException
     * @throws InvalidCouponException
     * @throws PaymentGatewayException
     */
    public function execute(CartDTO $cart, ?string $returnUrl = null, ?string $cancelUrl = null): CheckoutResultDTO
    {
        // Phase 1: Local atomic DB transaction (Ultra-fast, < 2ms).
        // 1a. Resolves & locks inventory items deterministically (CheckStockAction)
        // 1b. Resolves & validates coupon model from database (ValidateCouponAction)
        // 1c. Pure in-memory financial calculation without hidden I/O (CalculateCartTotalAction)
        // 1d. Persists pending order and bulk-inserts order items in a single query
        [$order, $calcResult] = DB::transaction(function () use ($cart): array {
            $resolvedSkus = $this->checkStockAction->execute($cart->items, lock: true);
            $coupon = $this->validateCouponAction->execute($cart->couponCode);
            $calcResult = $this->calculateCartTotalAction->execute($cart, $resolvedSkus, $coupon);

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

            // Single Bulk Insert reducing database round-trips from O(N) to O(1)
            $orderItemsData = [];
            $now = now();

            foreach ($calcResult->items as $item) {
                $item->sku->decrement('stock', $item->quantity);

                $orderItemsData[] = [
                    'order_id' => $order->id,
                    'sku_id' => $item->sku->id,
                    'sku_code' => $item->sku->code,
                    'sku_name' => $item->sku->name,
                    'unit_price' => $item->unitPrice,
                    'quantity' => $item->quantity,
                    'total_price' => $item->totalPrice,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            OrderItem::insert($orderItemsData);

            return [$order, $calcResult];
        });

        // Phase 2: External HTTP network call performed OUTSIDE of any database transaction.
        // No DB row locks or connections are held during this round-trip.
        try {
            $paymentResponse = $this->paymentGateway->createOrder(
                order: $order,
                returnUrl: $returnUrl,
                cancelUrl: $cancelUrl
            );
        } catch (Throwable $e) {
            // Phase 3A: Compensating Transaction if payment gateway fails.
            // Preserves financial audit trail: marks order as FAILED with failure reason, and atomically restores stock
            DB::transaction(function () use ($order, $calcResult, $e): void {
                $order->update([
                    'status' => OrderStatus::FAILED,
                    'failure_reason' => $e->getMessage(),
                ]);

                foreach ($calcResult->items as $item) {
                    $item->sku->increment('stock', $item->quantity);
                }
            });

            // Dispatch domain event for asynchronous alerting / monitoring
            PaymentFailed::dispatch($order, $e->getMessage());

            throw $e;
        }

        // Phase 3B: Finalize order with gateway provider order ID in a fast single-row update.
        $order->update([
            'provider_order_id' => $paymentResponse->providerOrderId,
        ]);

        // Emit domain event only after gateway approval succeeds (prevents premature processing)
        OrderCreated::dispatch($order);

        return new CheckoutResultDTO(
            orderId: $order->id,
            status: $order->status->value,
            providerOrderId: $paymentResponse->providerOrderId,
            redirectUrl: $paymentResponse->redirectUrl,
            totalAmount: $calcResult->totalAmount,
            currency: $order->currency,
            itemsCount: count($calcResult->items)
        );
    }
}
