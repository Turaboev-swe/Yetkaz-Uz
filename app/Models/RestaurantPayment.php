<?php

namespace App\Models;

use App\Enums\BillingPaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Obuna hisob-kitobi bo'yicha qilingan to'lov (tiyinda). */
class RestaurantPayment extends Model
{
    protected $fillable = ['invoice_id', 'restaurant_id', 'amount', 'paid_at', 'method', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'date',
            'method' => BillingPaymentMethod::class,
        ];
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
