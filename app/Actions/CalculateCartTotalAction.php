<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CartCalculatedItemDTO;
use App\DTOs\CartCalculationResultDTO;
use App\DTOs\CartDTO;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Sku;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * 100% Pure domain action that computes cart financial totals in-memory using Brick\Math\BigDecimal.
 *
 * Adheres strictly to the Single Responsibility Principle (SRP):
 * contains ZERO database queries, zero side effects, and zero external I/O.
 */
final class CalculateCartTotalAction
{
    private const INTERNAL_SCALE = 12;

    /**
     * Executes the pure in-memory calculation pipeline for the provided cart DTO, SKU map, and optional coupon.
     *
     * @param array<string, Sku> $resolvedSkus
     */
    public function execute(
        CartDTO $cart,
        array $resolvedSkus,
        ?Coupon $coupon = null,
        string $taxRate = '0.22'
    ): CartCalculationResultDTO {
        $subtotal = BigDecimal::zero();
        /** @var array<int, CartCalculatedItemDTO> $calculatedItems */
        $calculatedItems = [];

        foreach ($cart->items as $item) {
            $sku = $resolvedSkus[$item->skuCode];
            $unitPrice = BigDecimal::of((string) $sku->price_amount);
            $quantity = $item->quantity;
            $lineTotal = $unitPrice->multipliedBy($quantity);

            $subtotal = $subtotal->plus($lineTotal);

            $calculatedItems[] = new CartCalculatedItemDTO(
                sku: $sku,
                quantity: $quantity,
                unitPrice: (string) $unitPrice->toScale(2, RoundingMode::HalfUp),
                totalPrice: (string) $lineTotal->toScale(2, RoundingMode::HalfUp)
            );
        }

        $subtotalRounded = $subtotal->toScale(2, RoundingMode::HalfUp);

        // Compute discount purely in-memory if a coupon was resolved
        $discountAmount = BigDecimal::zero();
        if ($coupon !== null) {
            $couponValue = BigDecimal::of((string) $coupon->value);

            $discount = match ($coupon->type) {
                CouponType::PERCENTAGE => $subtotalRounded
                    ->multipliedBy($couponValue)
                    ->dividedBy('100', self::INTERNAL_SCALE, RoundingMode::HalfUp),
                CouponType::FIXED => $couponValue,
            };

            // Capping: discount cannot exceed subtotal or be negative
            if ($discount->isGreaterThan($subtotalRounded)) {
                $discount = $subtotalRounded;
            }

            if ($discount->isNegative()) {
                $discount = BigDecimal::zero();
            }

            $discountAmount = $discount;
        }

        // Imponibile netto (Subtotale - Sconto)
        $taxable = $subtotal->minus($discountAmount);
        if ($taxable->isNegative()) {
            $taxable = BigDecimal::zero();
        }

        // Calcolo imposta IVA con moltiplicazione decimale
        $taxRateDecimal = BigDecimal::of($taxRate);
        $taxAmount = $taxable->multipliedBy($taxRateDecimal);

        // Totale lordo finale (Imponibile + IVA)
        $total = $taxable->plus($taxAmount);

        return new CartCalculationResultDTO(
            subtotal: (string) $subtotalRounded,
            discountAmount: (string) $discountAmount->toScale(2, RoundingMode::HalfUp),
            taxableAmount: (string) $taxable->toScale(2, RoundingMode::HalfUp),
            taxRate: $taxRate,
            taxAmount: (string) $taxAmount->toScale(2, RoundingMode::HalfUp),
            totalAmount: (string) $total->toScale(2, RoundingMode::HalfUp),
            items: $calculatedItems,
            appliedCoupon: $coupon
        );
    }
}
