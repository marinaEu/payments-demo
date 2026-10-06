<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CouponValidationResultDTO;
use App\Enums\CouponType;
use App\Exceptions\InvalidCouponException;
use App\Models\Coupon;
use Carbon\CarbonInterface;

/**
 * Validates promotional coupons and computes the corresponding discount amount.
 */
final class ValidateCouponAction
{
    /**
     * Validates the given coupon code against subtotal and returns the discount amount.
     *
     * @throws InvalidCouponException
     */
    public function execute(?string $couponCode, float $subtotal): CouponValidationResultDTO
    {
        if ($couponCode === null || trim($couponCode) === '') {
            return new CouponValidationResultDTO(null, 0.0);
        }

        $normalizedCode = strtoupper(trim($couponCode));
        $coupon = Coupon::where('code', $normalizedCode)->first();

        if ($coupon === null) {
            throw InvalidCouponException::notFound($normalizedCode);
        }

        if (! $coupon->is_active) {
            throw InvalidCouponException::inactive($normalizedCode);
        }

        if ($coupon->expires_at instanceof CarbonInterface && $coupon->expires_at->isPast()) {
            throw InvalidCouponException::expired($normalizedCode);
        }

        $discount = match ($coupon->type) {
            CouponType::PERCENTAGE => round(($subtotal * (float) $coupon->value) / 100, 2),
            CouponType::FIXED => (float) $coupon->value,
        };

        // Guarantee that the discount never exceeds the available subtotal or goes negative
        $finalDiscount = max(0.0, min($discount, $subtotal));

        return new CouponValidationResultDTO($coupon, $finalDiscount);
    }
}
