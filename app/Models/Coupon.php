<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Represents a discount coupon.
 *
 * @property int $id
 * @property string $code
 * @property CouponType $type
 * @property numeric-string|float $value
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'is_active',
        'expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'decimal:2',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Determines whether the coupon is currently valid and usable.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at instanceof CarbonInterface && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get all orders that applied this coupon.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
