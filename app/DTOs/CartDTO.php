<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Data Transfer Object representing the checkout request and cart items.
 */
final readonly class CartDTO
{
    /**
     * @param array<int, CartItemDTO> $items
     */
    public function __construct(
        public string $checkoutToken,
        public array $items,
        public ?string $couponCode = null,
        public ?string $customerEmail = null,
        public string $currency = 'EUR',
    ) {
    }

    /**
     * Creates an instance from validated array payload.
     *
     * @param array{
     *     checkout_token: string,
     *     items: array<int, array{sku_code: string, quantity: int|numeric-string}>,
     *     coupon_code?: string|null,
     *     customer_email?: string|null,
     *     currency?: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $items = array_map(
            fn (array $itemData): CartItemDTO => CartItemDTO::fromArray($itemData),
            $data['items']
        );

        return new self(
            checkoutToken: $data['checkout_token'],
            items: $items,
            couponCode: $data['coupon_code'] ?? null,
            customerEmail: $data['customer_email'] ?? null,
            currency: $data['currency'] ?? 'EUR',
        );
    }
}
