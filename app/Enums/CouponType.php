<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Defines the discount calculation type for a coupon.
 */
enum CouponType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED = 'fixed';
}
