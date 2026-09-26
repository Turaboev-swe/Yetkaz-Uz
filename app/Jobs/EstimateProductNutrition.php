<?php

namespace App\Jobs;

use App\Enums\NutritionStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taomning bir porsiyasi uchun taxminiy kaloriya va "yengil taom" belgisini
 * Claude (Anthropic Messages API) orqali taxminlaydi.
 *
 * Natija `nutrition_status = pending` bilan saqlanadi — mijozga KO'RINMAYDI,
 * restoran egasi /restaurant panelida tasdiqlagachgina chiqadi.
 *
 * Best-effort: API xatosi yoki noto'g'ri javob bo'lsa faqat log yoziladi,
 * hech narsa saqlanmaydi, xato tashlanmaydi. Kalit (ANTHROPIC_API_KEY)
 * bo'lmasa funksiya butunlay o'chiq — job navbatga ham qo'yilmaydi.
 */
class EstimateProductNutrition implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const MAX_CALORIES = 5000;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You estimate nutrition for dishes on a food-delivery menu in Uzbekistan.
        Menu text may be in Uzbek (Latin or Cyrillic) or Russian.

        Assume one standard restaurant portion as typically served in Uzbekistan, unless the name or description states a size, weight or piece count. Typical portions: osh/plov ~350-400 g, lag'mon ~450 g bowl, shurva ~400 ml, one lavash ~350 g, one somsa ~120 g, manti ~5 pieces, one shashlik skewer ~100 g, burger ~250 g, salad ~200 g.

        is_light is true only for dishes suitable as a light or diet meal: low in fat and roughly under 400 kcal per portion (e.g. vegetable salads without mayonnaise, grilled lean meat or fish, clear soups). Fried, fatty, doughy or mayonnaise-heavy dishes are not light.

        Respond with only a JSON object and no other text:
        {"calories": <integer kcal per portion>, "is_light": <true or false>}
        PROMPT;

    /**
     * Nom va tavsif dispatch paytidagi holatda saqlanadi: javob kelguncha
     * taom o'zgargan bo'lsa (yangi job allaqachon navbatda), eski natija
     * yozilmaydi.
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $name,
        public readonly ?string $description,
    ) {}

    public static function enabled(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /** Kalit bo'lsa navbatga qo'yadi — tranzaksiya commit bo'lgach. */
    public static function dispatchFor(Product $product, int $delaySeconds = 0): void
    {
        if (! self::enabled()) {
            return;
        }

        self::dispatch($product->id, $product->name, $product->description)
            ->delay($delaySeconds > 0 ? now()->addSeconds($delaySeconds) : null)
            ->afterCommit();
    }

    public function handle(): void
    {
        if (! self::enabled()) {
            return;
        }

        $product = Product::withoutGlobalScopes()->find($this->productId);

        if ($product === null || ! $this->stillAwaitingEstimate($product)) {
            return;
        }

        $category = Category::withoutGlobalScopes()->find($product->category_id)?->name;

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(30)
                ->post(self::API_URL, [
                    'model' => config('services.anthropic.nutrition_model'),
                    'max_tokens' => 100,
                    'temperature' => 0,
                    'system' => self::SYSTEM_PROMPT,
                    'messages' => [[
                        'role' => 'user',
                        'content' => $this->dishText($category),
                    ]],
                ]);
        } catch (Throwable $e) {
            $this->skip('API so\'rovi amalga oshmadi', ['error' => $e->getMessage()]);

            return;
        }

        if ($response->failed()) {
            $this->skip('API xato javob qaytardi', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return;
        }

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        $result = self::parseEstimate($text);

        if ($result === null) {
            $this->skip('javobni tushunib bo\'lmadi', ['response' => mb_substr($text, 0, 200)]);

            return;
        }

        // Atomik: taom shu orada o'zgarmagan va egasi hali qaror qilmagan bo'lsagina yoziladi.
        Product::withoutGlobalScopes()
            ->whereKey($product->id)
            ->where('name', $this->name)
            ->when(
                $this->description === null,
                fn ($q) => $q->whereNull('description'),
                fn ($q) => $q->where('description', $this->description),
            )
            ->where(fn ($q) => $q->whereNull('nutrition_status')
                ->orWhere('nutrition_status', NutritionStatus::Pending->value))
            ->update([
                'calories_estimate' => $result['calories'],
                'is_light' => $result['is_light'],
                'nutrition_status' => NutritionStatus::Pending->value,
                'nutrition_generated_at' => now(),
            ]);
    }

    /**
     * Model javobidan {"calories": int, "is_light": bool} ni xavfsiz ajratadi.
     * ```json qobig'i va atrofdagi matnga chidamli; noto'g'ri bo'lsa null.
     *
     * @return array{calories: int, is_light: bool}|null
     */
    public static function parseEstimate(string $text): ?array
    {
        $text = preg_replace('/^```(?:json)?|```$/mi', '', trim($text));

        if (! preg_match('/\{.*\}/s', (string) $text, $m)) {
            return null;
        }

        $data = json_decode($m[0], true);

        if (! is_array($data)) {
            return null;
        }

        $calories = $data['calories'] ?? null;
        $isLight = $data['is_light'] ?? null;

        // 450 yoki 450.0 qabul; "450" (satr), 0, manfiy, haddan katta — yo'q.
        if (! (is_int($calories) || (is_float($calories) && floor($calories) === $calories))) {
            return null;
        }

        $calories = (int) $calories;

        if ($calories < 1 || $calories > self::MAX_CALORIES || ! is_bool($isLight)) {
            return null;
        }

        return ['calories' => $calories, 'is_light' => $isLight];
    }

    private function stillAwaitingEstimate(Product $product): bool
    {
        return $product->name === $this->name
            && $product->description === $this->description
            && in_array($product->nutrition_status, [null, NutritionStatus::Pending], true);
    }

    private function dishText(?string $category): string
    {
        return implode("\n", [
            'Dish: '.$this->name,
            'Category: '.($category ?: '(unknown)'),
            'Description: '.(filled($this->description) ? $this->description : '(none)'),
        ]);
    }

    /** @param  array<string, mixed>  $context */
    private function skip(string $reason, array $context = []): void
    {
        Log::warning("[nutrition] taxmin saqlanmadi: {$reason}", ['product_id' => $this->productId] + $context);
    }
}
