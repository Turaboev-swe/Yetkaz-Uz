<?php

namespace App\Models;

use App\Enums\BillingPlan;
use App\Enums\InvoiceStatus;
use App\Models\Scopes\ExcludeVoidInvoices;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Restoranning oylik obuna hisob-kitobi. Pullar tiyinda. plan / rate_bp / fixed_fee —
 * hisob yaratilgan paytdagi tarif SNAPSHOT'i (billing_started_at ham: fixed tarif proratsiyasi
 * va buyurtmalar oynasi shunga bog'liq). Snapshot faqat "Tarifni o'zgartirish" amali bilan o'zgaradi.
 */
class RestaurantInvoice extends Model
{
    protected $fillable = [
        'restaurant_id', 'period', 'plan', 'rate_bp', 'fixed_fee', 'orders_count', 'orders_total',
        'amount_due', 'amount_paid', 'status', 'due_date', 'notes', 'billing_started_at',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ExcludeVoidInvoices);
    }

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'due_date' => 'date',
            'billing_started_at' => 'date',
            'plan' => BillingPlan::class,
            'status' => InvoiceStatus::class,
            'rate_bp' => 'integer',
            'fixed_fee' => 'integer',
            'orders_count' => 'integer',
            'orders_total' => 'integer',
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
        ];
    }

    /** @return BelongsTo<Restaurant, RestaurantInvoice> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return HasMany<RestaurantPayment> */
    public function payments(): HasMany
    {
        return $this->hasMany(RestaurantPayment::class, 'invoice_id')->orderByDesc('paid_at')->orderByDesc('id');
    }

    /** Qarz: kechirilgan va bekor qilingan hisobda 0, aks holda to'lanadigan − to'langan (manfiy emas). */
    public function debt(): int
    {
        if (in_array($this->status, [InvoiceStatus::Waived, InvoiceStatus::Void], true)) {
            return 0;
        }

        return max(0, (int) $this->amount_due - (int) $this->amount_paid);
    }

    /** Yopiq (paid/waived/void) hisob qayta hisoblanmaydi va unga amal bajarilmaydi. */
    public function isSettled(): bool
    {
        return in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Waived, InvoiceStatus::Void], true);
    }
}
