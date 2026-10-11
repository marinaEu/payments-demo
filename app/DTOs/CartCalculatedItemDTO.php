<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Sku;

/**
 * Encapsulates calculation details for a single cart line item using exact decimal string representation.
 */
final readonly class CartCalculatedItemDTO
{
    public function __construct(
        public Sku $sku,
        public int $quantity,
        public string $unitPrice,
        public string $totalPrice
    ) {
    }
}
