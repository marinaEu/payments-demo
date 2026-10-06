<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Represents a customer purchase order.
 *
 * @property int $id
 * @property string $checkout_token
 * @property string|null $provider_order_id
 * @property OrderStatus $status
 * @property numeric-string|float $subtotal_amount
 * @property numeric-string|float $tax_amount
 * @property numeric-string|float $discount_amount
 * @property numeric-string|float $total_amount
 * @property string $currency
 * @property int|null $coupon_id
 * @property string|null $customer_email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Collection<int, OrderItem> $items
 * @property Coupon|null $coupon
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_token',
        'provider_order_id',
        'status',
        'subtotal_amount',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'currency',
        'coupon_id',
        'customer_email',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the items belonging to this order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the optional coupon applied to this order.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
