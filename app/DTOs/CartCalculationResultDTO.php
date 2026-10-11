<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Coupon;

/**
 * Encapsulates the complete financial computation result for a cart with exact precision strings.
 */
final readonly class CartCalculationResultDTO
{
    /**
     * @param array<int, CartCalculatedItemDTO> $items
     */
    public function __construct(
        public string $subtotal,
        public string $discountAmount,
        public string $taxableAmount,
        public string $taxRate,
        public string $taxAmount,
        public string $totalAmount,
        public array $items,
        public ?Coupon $appliedCoupon = null
    ) {
    }
}
