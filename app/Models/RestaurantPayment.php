<?php

namespace App\Models;

use App\Enums\BillingPaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Obuna hisob-kitobi bo'yicha qilingan to'lov (tiyinda). Bekor qilingan to'lov o'chirilmaydi:
 * cancelled_at / cancelled_by / cancel_reason bilan belgilanadi va hisobga kirmaydi.
 */
class RestaurantPayment extends Model
{
    protected $fillable = ['invoice_id', 'restaurant_id', 'amount', 'paid_at', 'method', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'date',
            'method' => BillingPaymentMethod::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** Bekor qilinmagan (hisobga kiradigan) to'lovlar. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** @return BelongsTo<Staff, RestaurantPayment> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'cancelled_by');
    }

    /** @return BelongsTo<RestaurantInvoice, RestaurantPayment> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RestaurantInvoice::class, 'invoice_id');
    }

    /** @return BelongsTo<Restaurant, RestaurantPayment> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<Staff, RestaurantPayment> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
