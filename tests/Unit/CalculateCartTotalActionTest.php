<?php

declare(strict_types=1);

use App\Actions\CalculateCartTotalAction;
use App\Actions\CheckStockAction;
use App\Actions\ValidateCouponAction;
use App\DTOs\CartDTO;
use App\DTOs\CartItemDTO;
use App\Enums\CouponType;
use App\Exceptions\InvalidCouponException;
use App\Exceptions\OutOfStockException;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->action = new CalculateCartTotalAction(
        new CheckStockAction(),
        new ValidateCouponAction()
    );

    $this->product = Product::create(['name' => 'Catalogo Moda']);
    $this->skuA = $this->product->skus()->create([
        'code' => 'JEANS-32',
        'name' => 'Jeans Slim 32',
        'price_amount' => 50.00,
        'stock' => 10,
        'is_active' => true,
    ]);

    $this->skuB = $this->product->skus()->create([
        'code' => 'BELT-100',
        'name' => 'Cintura Pelle 100cm',
        'price_amount' => 25.00,
        'stock' => 5,
        'is_active' => true,
    ]);
});

it('calculates exact subtotal, 22% tax, and total without coupon', function () {
    $cart = new CartDTO(
        checkoutToken: 'tok_test_1',
        items: [
            new CartItemDTO(skuCode: 'JEANS-32', quantity: 2), // 100.00
            new CartItemDTO(skuCode: 'BELT-100', quantity: 1), // 25.00
        ],
        couponCode: null
    );

    $result = $this->action->execute($cart);

    expect($result->subtotal)->toBe(125.00)
        ->and($result->discountAmount)->toBe(0.0)
        ->and($result->taxableAmount)->toBe(125.00)
        ->and($result->taxRate)->toBe(0.22)
        ->and($result->taxAmount)->toBe(27.50) // 125 * 0.22 = 27.50
        ->and($result->totalAmount)->toBe(152.50) // 125 + 27.50 = 152.50
        ->and($result->items)->toHaveCount(2)
        ->and($result->appliedCoupon)->toBeNull();
});

it('calculates subtotal, percentage discount, and VAT correctly', function () {
    Coupon::create([
        'code' => 'PROMO10',
        'type' => CouponType::PERCENTAGE,
        'value' => 10.00,
        'is_active' => true,
    ]);

    $cart = new CartDTO(
        checkoutToken: 'tok_test_2',
        items: [
            new CartItemDTO(skuCode: 'JEANS-32', quantity: 2), // 100.00
        ],
        couponCode: 'PROMO10'
    );

    $result = $this->action->execute($cart);

    expect($result->subtotal)->toBe(100.00)
        ->and($result->discountAmount)->toBe(10.00) // 10% di 100
        ->and($result->taxableAmount)->toBe(90.00) // 100 - 10
        ->and($result->taxAmount)->toBe(19.80) // 90 * 0.22 = 19.80
        ->and($result->totalAmount)->toBe(109.80) // 90 + 19.80 = 109.80
        ->and($result->appliedCoupon?->code)->toBe('PROMO10');
});

it('calculates subtotal, fixed value discount, and VAT correctly', function () {
    Coupon::create([
        'code' => 'FLAT15',
        'type' => CouponType::FIXED,
        'value' => 15.00,
        'is_active' => true,
    ]);

    $cart = new CartDTO(
        checkoutToken: 'tok_test_3',
        items: [
            new CartItemDTO(skuCode: 'JEANS-32', quantity: 1), // 50.00
            new CartItemDTO(skuCode: 'BELT-100', quantity: 1), // 25.00
        ],
        couponCode: 'FLAT15'
    );

    $result = $this->action->execute($cart);

    expect($result->subtotal)->toBe(75.00)
        ->and($result->discountAmount)->toBe(15.00)
        ->and($result->taxableAmount)->toBe(60.00) // 75 - 15
        ->and($result->taxAmount)->toBe(13.20) // 60 * 0.22 = 13.20
        ->and($result->totalAmount)->toBe(73.20) // 60 + 13.20 = 73.20
        ->and($result->appliedCoupon?->code)->toBe('FLAT15');
});

it('throws out of stock exception and stops calculation when quantity exceeds inventory', function () {
    $cart = new CartDTO(
        checkoutToken: 'tok_test_4',
        items: [
            new CartItemDTO(skuCode: 'BELT-100', quantity: 99), // Solo 5 disponibili
        ]
    );

    expect(fn () => $this->action->execute($cart))
        ->toThrow(OutOfStockException::class);
});

it('throws invalid coupon exception and stops calculation when coupon is invalid', function () {
    $cart = new CartDTO(
        checkoutToken: 'tok_test_5',
        items: [
            new CartItemDTO(skuCode: 'BELT-100', quantity: 1),
        ],
        couponCode: 'FAKE_CODE'
    );

    expect(fn () => $this->action->execute($cart))
        ->toThrow(InvalidCouponException::class);
});
