<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\InvalidCouponException;
use App\Models\Coupon;
use Carbon\CarbonInterface;

/**
 * Resolves and validates a promotional coupon code against the database.
 */
final class ValidateCouponAction
{
    /**
     * Validates the given coupon code and returns the active Coupon model, or null if empty.
     *
     * @throws InvalidCouponException
     */
    public function execute(?string $couponCode): ?Coupon
    {
        if ($couponCode === null || trim($couponCode) === '') {
            return null;
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

        return $coupon;
    }
}
