<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Data Transfer Object representing an individual line item within the cart.
 */
final readonly class CartItemDTO
{
    public function __construct(
        public string $skuCode,
        public int $quantity,
    ) {
    }

    /**
     * Creates an instance from raw payload array.
     *
     * @param array{sku_code: string, quantity: int|numeric-string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            skuCode: $data['sku_code'],
            quantity: (int) $data['quantity'],
        );
    }
}
