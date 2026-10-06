<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CartCalculatedItemDTO;
use App\DTOs\CartCalculationResultDTO;
use App\DTOs\CartDTO;
use App\Exceptions\InvalidCouponException;
use App\Exceptions\OutOfStockException;
use App\Exceptions\SkuNotFoundException;

/**
 * Orchestrates complete cart calculation: validates inventory, applies coupons, and calculates tax and totals.
 */
final class CalculateCartTotalAction
{
    public function __construct(
        private readonly CheckStockAction $checkStockAction,
        private readonly ValidateCouponAction $validateCouponAction
    ) {
    }

    /**
     * Executes the calculation pipeline for the provided cart DTO.
     *
     * @throws SkuNotFoundException
     * @throws OutOfStockException
     * @throws InvalidCouponException
     */
    public function execute(CartDTO $cart, float $taxRate = 0.22, bool $lockStock = false): CartCalculationResultDTO
    {
        $resolvedSkus = $this->checkStockAction->execute($cart->items, $lockStock);

        $subtotal = 0.0;
        /** @var array<int, CartCalculatedItemDTO> $calculatedItems */
        $calculatedItems = [];

        foreach ($cart->items as $item) {
            $sku = $resolvedSkus[$item->skuCode];
            $unitPrice = (float) $sku->price_amount;
            $lineTotal = round($unitPrice * $item->quantity, 2);

            $subtotal += $lineTotal;

            $calculatedItems[] = new CartCalculatedItemDTO(
                sku: $sku,
                quantity: $item->quantity,
                unitPrice: $unitPrice,
                totalPrice: $lineTotal
            );
        }

        $subtotal = round($subtotal, 2);

        $couponResult = $this->validateCouponAction->execute($cart->couponCode, $subtotal);
        $discountAmount = $couponResult->discountAmount;

        $taxableAmount = round(max(0.0, $subtotal - $discountAmount), 2);
        $taxAmount = round($taxableAmount * $taxRate, 2);
        $totalAmount = round($taxableAmount + $taxAmount, 2);

        return new CartCalculationResultDTO(
            subtotal: $subtotal,
            discountAmount: $discountAmount,
            taxableAmount: $taxableAmount,
            taxRate: $taxRate,
            taxAmount: $taxAmount,
            totalAmount: $totalAmount,
            items: $calculatedItems,
            appliedCoupon: $couponResult->coupon
        );
    }
}
