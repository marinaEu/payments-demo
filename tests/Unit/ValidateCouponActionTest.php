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

it('returns null when no coupon code is provided', function () {
    expect($this->action->execute(null))->toBeNull()
        ->and($this->action->execute('   '))->toBeNull();
});

it('resolves active coupon successfully regardless of case', function () {
    Coupon::create([
        'code' => 'SAVE10',
        'type' => CouponType::PERCENTAGE,
        'value' => 10.00,
        'is_active' => true,
    ]);

    $coupon = $this->action->execute('save10');

    expect($coupon)->not->toBeNull()
        ->and($coupon->code)->toBe('SAVE10');
});

it('throws exception if coupon does not exist', function () {
    expect(fn () => $this->action->execute('NON_EXISTENT'))
        ->toThrow(InvalidCouponException::class, "Il codice coupon 'NON_EXISTENT' non esiste.");
});

it('throws exception if coupon is inactive', function () {
    Coupon::create([
        'code' => 'OFFLINE',
        'type' => CouponType::PERCENTAGE,
        'value' => 20.00,
        'is_active' => false,
    ]);

    expect(fn () => $this->action->execute('OFFLINE'))
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

    expect(fn () => $this->action->execute('EXPIRED'))
        ->toThrow(InvalidCouponException::class, "Il codice coupon 'EXPIRED' è scaduto.");
});
