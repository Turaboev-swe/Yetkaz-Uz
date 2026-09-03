<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\OrderNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrderNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_yt_prefix_with_six_digits(): void
    {
        $number = app(OrderNumberGenerator::class)->generate();

        $this->assertMatchesRegularExpression('/^YT-\d{6}$/', $number);
    }

    public function test_two_consecutive_numbers_differ(): void
    {
        $gen = app(OrderNumberGenerator::class);

        $this->assertNotSame($gen->generate(), $gen->generate());
    }

    public function test_retries_on_collision_until_a_free_number_is_found(): void
    {
        $this->orderWithNumber('YT-000001');

        $gen = new class extends OrderNumberGenerator
        {
            /** @var list<string> */
            public array $sequence = ['000001', '000001', '000042'];

            protected function sixDigits(): string
            {
                return array_shift($this->sequence) ?? parent::sixDigits();
            }
        };

        $this->assertSame('YT-000042', $gen->generate());
        $this->assertSame([], $gen->sequence); // uchala qadam ishlatildi
    }

    public function test_throws_after_max_attempts_all_taken(): void
    {
        $this->orderWithNumber('YT-000007');

        $gen = new class extends OrderNumberGenerator
        {
            protected function sixDigits(): string
            {
                return '000007'; // doim band
            }
        };

        $this->expectException(RuntimeException::class);
        $gen->generate();
    }

    private function orderWithNumber(string $number): Order
    {
        return Order::factory()
            ->for(Restaurant::factory())
            ->for(User::factory())
            ->create(['order_number' => $number]);
    }
}
