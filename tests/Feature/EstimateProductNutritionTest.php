<?php

namespace Tests\Feature;

use App\Enums\NutritionStatus;
use App\Jobs\EstimateProductNutrition;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AI kaloriya taxmini: job (Http::fake — haqiqiy API so'rovi yo'q),
 * observer (qachon ishga tushadi, nom/tavsif o'zgarsa pending ga qaytish)
 * va artisan buyrug'i.
 */
class EstimateProductNutritionTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.anthropic.com/v1/messages';

    private function enableAi(): void
    {
        config(['services.anthropic.key' => 'test-key']);
    }

    /** Kalitsiz yaratiladi — observer job qo'ymaydi, job'ni test o'zi ishga tushiradi. */
    private function product(array $attrs = []): Product
    {
        $category = Category::factory()->create(['name' => 'Milliy taomlar']);

        return Product::factory()->for($category)->create(array_merge([
            'name' => 'Toshkent oshi',
            'description' => 'Guruch, qo\'y go\'shti, sabzi, zig\'ir yog\'ida',
        ], $attrs));
    }

    private function runJob(Product $product): void
    {
        (new EstimateProductNutrition($product->id, $product->name, $product->description))->handle();
    }

    private function claudeReplies(string $text): void
    {
        Http::fake([self::API => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
        ])]);
    }

    public function test_valid_json_is_saved_as_pending(): void
    {
        $product = $this->product();
        $this->enableAi();
        $this->claudeReplies('{"calories": 650, "is_light": false}');

        $this->runJob($product);

        $product->refresh();
        $this->assertSame(650, $product->calories_estimate);
        $this->assertFalse($product->is_light);
        $this->assertSame(NutritionStatus::Pending, $product->nutrition_status);
        $this->assertNotNull($product->nutrition_generated_at);
    }

    public function test_request_sends_name_description_and_category_to_the_configured_model(): void
    {
        $product = $this->product();
        $this->enableAi();
        $this->claudeReplies('{"calories": 650, "is_light": false}');

        $this->runJob($product);

        Http::assertSent(function (Request $request) {
            $prompt = $request['messages'][0]['content'];

            return $request->url() === self::API
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-haiku-4-5-20251001'
                && str_contains($prompt, 'Toshkent oshi')
                && str_contains($prompt, 'zig\'ir yog\'ida')
                && str_contains($prompt, 'Milliy taomlar')
                && str_contains($request['system'], 'Uzbekistan');
        });
    }

    public function test_json_wrapped_in_a_markdown_code_fence_is_parsed(): void
    {
        $product = $this->product();
        $this->enableAi();
        $this->claudeReplies("```json\n{\"calories\": 180, \"is_light\": true}\n```");

        $this->runJob($product);

        $product->refresh();
        $this->assertSame(180, $product->calories_estimate);
        $this->assertTrue($product->is_light);
    }

    /** @return array<string, array{string}> */
    public static function invalidReplies(): array
    {
        return [
            'matn, JSON yo\'q' => ['Taxminan 500 kkal atrofida'],
            'buzuq JSON' => ['{"calories": 500, "is_light": '],
            'kaloriya satr' => ['{"calories": "500", "is_light": false}'],
            'is_light yo\'q' => ['{"calories": 500}'],
            'nol kaloriya' => ['{"calories": 0, "is_light": true}'],
            'haddan katta' => ['{"calories": 99999, "is_light": false}'],
            'manfiy' => ['{"calories": -50, "is_light": false}'],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function test_invalid_reply_saves_nothing_and_does_not_throw(string $reply): void
    {
        $product = $this->product();
        $this->enableAi();
        $this->claudeReplies($reply);

        $this->runJob($product);

        $product->refresh();
        $this->assertNull($product->calories_estimate);
        $this->assertNull($product->is_light);
        $this->assertNull($product->nutrition_status);
    }

    public function test_api_error_saves_nothing_and_does_not_throw(): void
    {
        $product = $this->product();
        $this->enableAi();
        Http::fake([self::API => Http::response([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ], 529)]);

        $this->runJob($product);

        $this->assertNull($product->fresh()->nutrition_status);
    }

    public function test_network_failure_saves_nothing_and_does_not_throw(): void
    {
        $product = $this->product();
        $this->enableAi();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->runJob($product);

        $this->assertNull($product->fresh()->nutrition_status);
    }

    public function test_without_api_key_no_request_is_made(): void
    {
        Http::fake();
        $product = $this->product();

        $this->runJob($product);

        Http::assertNothingSent();
        $this->assertNull($product->fresh()->nutrition_status);
    }

    public function test_owner_decision_made_meanwhile_is_not_overwritten(): void
    {
        $product = $this->product();
        $product->update(['calories_estimate' => 700, 'is_light' => false, 'nutrition_status' => NutritionStatus::Approved]);
        $this->enableAi();
        $this->claudeReplies('{"calories": 400, "is_light": true}');

        $this->runJob($product);

        $product->refresh();
        $this->assertSame(700, $product->calories_estimate);
        $this->assertSame(NutritionStatus::Approved, $product->nutrition_status);
        Http::assertNothingSent();
    }

    public function test_stale_result_for_a_renamed_product_is_discarded(): void
    {
        $product = $this->product();
        $staleJob = new EstimateProductNutrition($product->id, 'Eski nom', $product->description);
        $this->enableAi();
        $this->claudeReplies('{"calories": 400, "is_light": true}');

        $staleJob->handle();

        $this->assertNull($product->fresh()->calories_estimate);
    }

    // --- Qachon ishga tushadi (observer) ---

    public function test_creating_a_product_queues_an_estimate(): void
    {
        Queue::fake();
        $this->enableAi();

        $product = $this->product();

        Queue::assertPushed(EstimateProductNutrition::class, fn ($job) => $job->productId === $product->id
            && $job->name === 'Toshkent oshi');
    }

    public function test_nothing_is_queued_without_an_api_key(): void
    {
        Queue::fake();

        $this->product();

        Queue::assertNotPushed(EstimateProductNutrition::class);
    }

    public function test_renaming_an_approved_product_resets_it_to_pending_and_re_estimates(): void
    {
        $product = $this->product();
        $product->update(['calories_estimate' => 650, 'is_light' => false, 'nutrition_status' => NutritionStatus::Approved]);
        Queue::fake();
        $this->enableAi();

        $product->update(['name' => 'To\'y oshi']);

        $this->assertSame(NutritionStatus::Pending, $product->fresh()->nutrition_status);
        Queue::assertPushed(EstimateProductNutrition::class, fn ($job) => $job->name === 'To\'y oshi');
    }

    public function test_changing_the_description_resets_a_hidden_estimate_to_pending(): void
    {
        $product = $this->product();
        $product->update(['calories_estimate' => 650, 'nutrition_status' => NutritionStatus::Hidden]);
        Queue::fake();
        $this->enableAi();

        $product->update(['description' => 'Endi kurka go\'shti bilan']);

        $this->assertSame(NutritionStatus::Pending, $product->fresh()->nutrition_status);
        Queue::assertPushed(EstimateProductNutrition::class);
    }

    public function test_price_or_availability_change_keeps_the_approval(): void
    {
        $product = $this->product();
        $product->update(['calories_estimate' => 650, 'nutrition_status' => NutritionStatus::Approved]);
        Queue::fake();
        $this->enableAi();

        $product->update(['price' => 4_000_000, 'is_available' => false]);

        $this->assertSame(NutritionStatus::Approved, $product->fresh()->nutrition_status);
        Queue::assertNotPushed(EstimateProductNutrition::class);
    }

    // --- Artisan buyrug'i ---

    public function test_command_queues_only_products_never_estimated(): void
    {
        $fresh = $this->product(['name' => 'Yangi']);
        $this->product(['name' => 'Kutmoqda'])->update(['nutrition_status' => NutritionStatus::Pending]);
        $this->product(['name' => 'Tasdiqlangan'])->update(['nutrition_status' => NutritionStatus::Approved]);
        Queue::fake();
        $this->enableAi();

        $this->artisan('products:estimate-nutrition')->assertSuccessful();

        Queue::assertPushed(EstimateProductNutrition::class, 1);
        Queue::assertPushed(EstimateProductNutrition::class, fn ($job) => $job->productId === $fresh->id);
    }

    public function test_command_fails_clearly_without_an_api_key(): void
    {
        $this->artisan('products:estimate-nutrition')->assertFailed();
    }
}
