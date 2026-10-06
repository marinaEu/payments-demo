<?php

declare(strict_types=1);

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sku;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates product and related skus with correct casts', function () {
    $product = Product::create([
        'name' => 'T-Shirt Classic',
        'description' => '100% cotone',
        'is_active' => true,
    ]);

    $sku = $product->skus()->create([
        'code' => 'TSHIRT-BLK-S',
        'name' => 'Nero / S',
        'price_amount' => 19.99,
        'stock' => 50,
        'is_active' => true,
    ]);

    expect($product->skus)->toHaveCount(1)
        ->and($sku->product->id)->toBe($product->id)
        ->and($sku->stock)->toBe(50)
        ->and($sku->is_active)->toBeTrue()
        ->and((float) $sku->price_amount)->toBe(19.99);
});

it('creates order with items, coupon and correct enums', function () {
    $coupon = Coupon::create([
        'code' => 'WELCOME10',
        'type' => CouponType::PERCENTAGE,
        'value' => 10.00,
        'is_active' => true,
        'expires_at' => now()->addDays(7),
    ]);

    expect($coupon->isValid())->toBeTrue()
        ->and($coupon->type)->toBe(CouponType::PERCENTAGE);

    $product = Product::create(['name' => 'Scarpe']);
    $sku = $product->skus()->create([
        'code' => 'SHOES-42',
        'name' => 'Taglia 42',
        'price_amount' => 80.00,
        'stock' => 10,
    ]);

    $order = Order::create([
        'checkout_token' => 'chk_unique_123',
        'provider_order_id' => 'PAYPAL-MOCK-999',
        'status' => OrderStatus::PENDING,
        'subtotal_amount' => 80.00,
        'tax_amount' => 17.60,
        'discount_amount' => 8.00,
        'total_amount' => 89.60,
        'currency' => 'EUR',
        'coupon_id' => $coupon->id,
        'customer_email' => 'cliente@test.com',
    ]);

    $item = $order->items()->create([
        'sku_id' => $sku->id,
        'sku_code' => $sku->code,
        'sku_name' => $sku->name,
        'unit_price' => 80.00,
        'quantity' => 1,
        'total_price' => 80.00,
    ]);

    expect($order->status)->toBe(OrderStatus::PENDING)
        ->and($order->coupon->code)->toBe('WELCOME10')
        ->and($order->items)->toHaveCount(1)
        ->and($item->sku->code)->toBe('SHOES-42');
});

it('enforces unique checkout token constraint to prevent duplicate orders', function () {
    Order::create([
        'checkout_token' => 'duplicate_token_test',
        'status' => OrderStatus::PENDING,
        'subtotal_amount' => 50.00,
        'tax_amount' => 11.00,
        'discount_amount' => 0.00,
        'total_amount' => 61.00,
    ]);

    expect(fn () => Order::create([
        'checkout_token' => 'duplicate_token_test',
        'status' => OrderStatus::PENDING,
        'subtotal_amount' => 50.00,
        'tax_amount' => 11.00,
        'discount_amount' => 0.00,
        'total_amount' => 61.00,
    ]))->toThrow(QueryException::class);
});
