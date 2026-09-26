<?php

namespace App\Observers;

use App\Enums\NutritionStatus;
use App\Jobs\EstimateProductNutrition;
use App\Models\Product;
use App\Models\ProductPriceHistory;

class ProductObserver
{
    /** Yangi taom — kaloriyani AI bilan taxminlash (egasi o'zi kiritib tasdiqlagan bo'lmasa). */
    public function created(Product $product): void
    {
        if ($product->nutrition_status !== NutritionStatus::Approved) {
            EstimateProductNutrition::dispatchFor($product);
        }
    }

    /**
     * Nom yoki tavsif o'zgarsa — bu amalda boshqa taom: avvalgi tasdiq
     * (yoki yashirish) bekor bo'ladi, holat pending ga qaytadi va qayta
     * taxminlanadi. Eski tasdiqlangan raqam mijozga yangi taom uchun
     * noto'g'ri chiqib qolmasligi kerak.
     *
     * Istisno: egasi xuddi shu saqlashda kaloriyani o'zi tahrirlab
     * tasdiqlagan bo'lsa (panel nutrition_status=approved qo'yadi) — uning
     * qarori ustun.
     */
    public function updating(Product $product): void
    {
        if (! $product->isDirty(['name', 'description'])) {
            return;
        }

        $ownerApprovedNow = $product->isDirty('nutrition_status')
            && $product->nutrition_status === NutritionStatus::Approved;

        if (! $ownerApprovedNow) {
            $product->nutrition_status = NutritionStatus::Pending;
        }
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged(['name', 'description']) && $product->nutrition_status === NutritionStatus::Pending) {
            EstimateProductNutrition::dispatchFor($product);
        }

        $this->recordPriceChange($product);
    }

    /**
     * Narx o'zgarганда product_price_history ga yozadi (narxlar tiyinda).
     * Kim o'zgartirgani `staff` guard'idan olinadi (panel konteksti).
     */
    private function recordPriceChange(Product $product): void
    {
        if (! $product->wasChanged('price')) {
            return;
        }

        ProductPriceHistory::create([
            'product_id' => $product->id,
            'staff_id' => auth('staff')->id(),
            'old_price' => (int) $product->getOriginal('price'),
            'new_price' => (int) $product->price,
            'changed_at' => now(),
        ]);
    }
}
