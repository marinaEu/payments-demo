<?php

declare(strict_types=1);

use App\DTOs\CartDTO;
use App\DTOs\CartItemDTO;

it('creates cart item dto from array', function () {
    $dto = CartItemDTO::fromArray([
        'sku_code' => 'TSHIRT-RED-M',
        'quantity' => 2,
    ]);

    expect($dto->skuCode)->toBe('TSHIRT-RED-M')
        ->and($dto->quantity)->toBe(2);
});

it('creates cart dto from array with items and coupon', function () {
    $dto = CartDTO::fromArray([
        'checkout_token' => 'tok_123456',
        'items' => [
            ['sku_code' => 'TSHIRT-RED-M', 'quantity' => 1],
            ['sku_code' => 'TSHIRT-BLUE-L', 'quantity' => 3],
        ],
        'coupon_code' => 'DISCOUNT10',
        'customer_email' => 'mario.rossi@example.com',
        'currency' => 'EUR',
    ]);

    expect($dto->checkoutToken)->toBe('tok_123456')
        ->and($dto->items)->toHaveCount(2)
        ->and($dto->items[0]->skuCode)->toBe('TSHIRT-RED-M')
        ->and($dto->couponCode)->toBe('DISCOUNT10')
        ->and($dto->customerEmail)->toBe('mario.rossi@example.com')
        ->and($dto->currency)->toBe('EUR');
});
