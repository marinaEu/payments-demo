<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Defines the possible states of an order.
 */
enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
