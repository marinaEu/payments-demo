<?php

declare(strict_types=1);

use App\Actions\ValidateCouponAction;
use App\Enums\CouponType;
use App\Exceptions\InvalidCouponException;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->action = new ValidateCouponAction();
});

it('returns zero discount when no coupon code is provided', function () {
    $result = $this->action->execute(null, 100.00);

    expect($result->coupon)->toBeNull()
        ->and($result->discountAmount)->toBe(0.0);

    $emptyResult = $this->action->execute('   ', 100.00);
    expect($emptyResult->coupon)->toBeNull()
        ->and($emptyResult->discountAmount)->toBe(0.0);
});

it('calculates correct percentage discount', function () {
    Coupon::create([
        'code' => 'SAVE10',
        'type' => CouponType::PERCENTAGE,
        'value' => 10.00,
        'is_active' => true,
    ]);

    $result = $this->action->execute('save10', 100.00);

    expect($result->coupon)->not->toBeNull()
        ->and($result->coupon->code)->toBe('SAVE10')
        ->and($result->discountAmount)->toBe(10.00);
});

it('calculates correct fixed value discount', function () {
    Coupon::create([
        'code' => 'FIXED15',
        'type' => CouponType::FIXED,
        'value' => 15.00,
        'is_active' => true,
    ]);

    $result = $this->action->execute('FIXED15', 80.00);

    expect($result->discountAmount)->toBe(15.00);
});

it('caps fixed discount to subtotal if coupon value exceeds cart subtotal', function () {
    Coupon::create([
        'code' => 'BIG50',
        'type' => CouponType::FIXED,
        'value' => 50.00,
        'is_active' => true,
    ]);

    $result = $this->action->execute('BIG50', 30.00);

    expect($result->discountAmount)->toBe(30.00);
});

it('throws exception if coupon does not exist', function () {
    expect(fn () => $this->action->execute('NON_EXISTENT', 50.00))
        ->toThrow(InvalidCouponException::class, "Il codice coupon 'NON_EXISTENT' non esiste.");
});

it('throws exception if coupon is inactive', function () {
    Coupon::create([
        'code' => 'OFFLINE',
        'type' => CouponType::PERCENTAGE,
        'value' => 20.00,
        'is_active' => false,
    ]);

    expect(fn () => $this->action->execute('OFFLINE', 50.00))
        ->toThrow(InvalidCouponException::class, "Il codice coupon 'OFFLINE' è attualmente disattivato.");
});

it('throws exception if coupon is expired', function () {
    Coupon::create([
        'code' => 'EXPIRED',
        'type' => CouponType::PERCENTAGE,
        'value' => 20.00,
        'is_active' => true,
        'expires_at' => now()->subDay(),
    ]);

    expect(fn () => $this->action->execute('EXPIRED', 50.00))
        ->toThrow(InvalidCouponException::class, "Il codice coupon 'EXPIRED' è scaduto.");
});
