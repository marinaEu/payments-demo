<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Coupon;

/**
 * Encapsulates the complete financial computation result for a cart.
 */
final readonly class CartCalculationResultDTO
{
    /**
     * @param array<int, CartCalculatedItemDTO> $items
     */
    public function __construct(
        public float $subtotal,
        public float $discountAmount,
        public float $taxableAmount,
        public float $taxRate,
        public float $taxAmount,
        public float $totalAmount,
        public array $items,
        public ?Coupon $appliedCoupon = null
    ) {
    }
}
