<?php

namespace App\Models;

use App\Enums\BillingPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restoran tarif tarixi yozuvi: `effective_from` (oyning 1-kuni) dan boshlab amalda.
 * Davr uchun tarif = effective_from <= davr bo'lgan eng oxirgi yozuv. `plan = null` — tarifsiz davr.
 */
class RestaurantBillingPlan extends Model
{
    protected $fillable = ['restaurant_id', 'plan', 'rate_bp', 'monthly_fee', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return [
            'plan' => BillingPlan::class,
            'rate_bp' => 'integer',
            'monthly_fee' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<Restaurant, RestaurantBillingPlan> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<Staff, RestaurantBillingPlan> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
