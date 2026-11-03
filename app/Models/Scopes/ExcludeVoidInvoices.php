<?php

namespace App\Models\Scopes;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Bekor qilingan (`void`) hisob-kitoblar hech bir so'rovga kirmaydi: qarz, statistika, generatsiya,
 * "To'lov holati". Faqat `withoutGlobalScope(ExcludeVoidInvoices::class)` bilan ko'rinadi
 * (hisob-kitoblar ro'yxatidagi «Bekor qilinganlar» filtri, tuzatish amali).
 */
class ExcludeVoidInvoices implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('status'), '<>', InvoiceStatus::Void->value);
    }
}
