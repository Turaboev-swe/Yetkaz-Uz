<?php

namespace App\Observers;

use App\Enums\NutritionStatus;
use App\Jobs\EstimateProductNutrition;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\Stock\StockService;

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

    /**
     * Kunlik tiklash sozlamasi saqlandi — «oxirgi tiklanish» shu lahza deb belgilanadi: bugungi vaqt allaqachon o'tgan
     * bo'lsa ham egasi hozir kiritgan qoldiq darhol qayta yozilib ketmaydi (keyingi tiklash — ertaga yoki hali kelmagan
     * bugungi vaqtda). Faqat sozlama o'zgarganda; qoldiqning o'zi o'zgarsa tegilmaydi.
     */
    public function saving(Product $product): void
    {
        if ($product->daily_stock === null) {
            return;
        }

        // Forma '06:00' yuboradi, baza '06:00:00' qaytaradi — soat:daqiqa bo'yicha solishtiramiz, aks holda mahsulotning
        // har qanday tahriri «sozlama o'zgardi» deb hisoblanardi.
        $timeChanged = substr((string) $product->getOriginal('daily_reset_time'), 0, 5) !== substr((string) $product->daily_reset_time, 0, 5);

        if ($product->isDirty('daily_stock') || $timeChanged) {
            $product->stock_reset_at = now();
        }
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged(['name', 'description']) && $product->nutrition_status === NutritionStatus::Pending) {
            EstimateProductNutrition::dispatchFor($product);
        }

        $this->recordPriceChange($product);

        // Restoran qoldiqni qo'lda o'zgartirdi (forma yoki jadvalda inline) — Mini App/tinglovchilar bilsin.
        if ($product->wasChanged('stock_quantity')) {
            app(StockService::class)->announce([$product->id]);
        }
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
