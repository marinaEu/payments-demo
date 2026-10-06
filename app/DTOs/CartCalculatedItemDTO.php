<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Sku;

/**
 * Encapsulates calculation details for a single cart line item.
 */
final readonly class CartCalculatedItemDTO
{
    public function __construct(
        public Sku $sku,
        public int $quantity,
        public float $unitPrice,
        public float $totalPrice
    ) {
    }
}
