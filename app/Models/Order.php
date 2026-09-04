<?php

namespace App\Models;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\ScopedToRestaurant;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use ScopedToRestaurant;

    protected $fillable = [
        'order_number',
        'user_id',
        'restaurant_id',
        'address_id',
        'delivery_type',
        'items',
        'address_snapshot',
        'note',
        'courier_name',
        'courier_phone',
        'subtotal',
        'delivery_fee',
        'total',
        'payment_method',
        'payment_status',
        'status',
        'eta_minutes',
        'distance_km',
        'dispatched_at',
        'printed_at',
        'dispatch_failed_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'address_snapshot' => 'array',
            'delivery_type' => DeliveryType::class,
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'total' => 'integer',
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'eta_minutes' => 'integer',
            'distance_km' => 'float',
            'dispatched_at' => 'datetime',
            'printed_at' => 'datetime',
            'dispatch_failed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, Order> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Restaurant, Order> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<Address, Order> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /** @return HasMany<OrderStatusHistory> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('changed_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', OrderStatus::activeValues());
    }

    /** RestaurantScope tomonidan chaqiriladi (restaurant_owner uchun). */
    public function scopeForRestaurant(Builder $query, int $restaurantId): Builder
    {
        return $query->where($this->qualifyColumn('restaurant_id'), $restaurantId);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
