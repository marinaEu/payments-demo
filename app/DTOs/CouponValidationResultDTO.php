<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Coupon;

/**
 * Encapsulates the output of a coupon validation and discount computation.
 */
final readonly class CouponValidationResultDTO
{
    public function __construct(
        public ?Coupon $coupon,
        public string $discountAmount
    ) {
    }
}
