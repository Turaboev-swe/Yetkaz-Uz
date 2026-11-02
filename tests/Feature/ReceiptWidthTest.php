<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Enums\PosType;
use App\Events\PrintJobRequested;
use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Dispatch\ReceiptFormatter;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Chek kengligi: 58 mm (32), 80 mm (42) va 80 mm (48 belgi, Xprinter XP-80T Font A). Mavjud qiymatlar o'zgarmaydi. */
class ReceiptWidthTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(array $extra = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'Guzar Kafe', 'pos_type' => PosType::EscPos, 'print_agent_token' => 'tok-'.uniqid(),
        ], $extra));
    }

    private function longOrder(Restaurant $r): Order
    {
        $long = "Juda uzun nomli taom: Lag'mon ustiga qo'shimcha go'sht, sabzavot va achchiq sous (katta porsiya)";

        return Order::factory()->for($r)->for(User::factory(['full_name' => 'Familiyasi juda uzun mijoz Abdurahmonovich', 'phone' => '+998900000000']))->create([
            'delivery_type' => DeliveryType::Delivery,
            'items' => [['product_id' => 1, 'name' => $long, 'price' => 1, 'qty' => 12, 'prep' => 1, 'note' => 'izoh: '.$long]],
            'note' => $long.' '.$long,
            'contact_phone' => '+998935556677',
            'payment_method' => PaymentMethod::Cash,
            'address_snapshot' => ['address_text' => $long, 'district' => 'Chilonzor', 'entrance' => '2', 'floor' => '5', 'apartment' => '45'],
            'total' => 6_000_000,
        ]);
    }

    private function asAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
    }

    // --- Kenglik hisobi ---------------------------------------------------------------------------

    /** @return array<string, array{0: ?int, 1: ?int, 2: int}> */
    public static function widths(): array
    {
        return [
            '58 mm (bo‘sh -> 32)' => [58, null, 32],
            '80 mm (bo‘sh -> 42, standart)' => [80, null, 42],
            '80 mm, 48 belgi' => [80, 48, 48],
            '80 mm, 42 belgi aniq' => [80, 42, 42],
            '58 mm, 32 belgi aniq' => [58, 32, 32],
            'noto‘g‘ri qiymat e‘tiborga olinmaydi' => [80, 40, 42],
        ];
    }

    #[DataProvider('widths')]
    public function test_columns_come_from_the_explicit_setting_or_the_paper_width(int $paper, ?int $columns, int $expected): void
    {
        $r = new Restaurant(['receipt_paper_mm' => $paper, 'receipt_columns' => $columns]);

        $this->assertSame($expected, $r->receiptColumns());
    }

    public function test_a_restaurant_without_any_receipt_setting_uses_the_80mm_default(): void
    {
        $this->assertSame(42, (new Restaurant)->receiptColumns());
    }

    // --- 48 belgili chek -------------------------------------------------------------------------

    public function test_48_column_receipt_fills_the_full_line_and_never_exceeds_it(): void
    {
        $r = $this->restaurant(['receipt_paper_mm' => 80, 'receipt_columns' => 48]);
        $order = $this->longOrder($r);

        $text = app(ReceiptFormatter::class)->format($order->fresh(['user', 'restaurant']))['text'];

        $this->assertStringContainsString(str_repeat('=', 48), $text);
        $this->assertStringNotContainsString(str_repeat('=', 49), $text);
        $this->assertStringContainsString(str_repeat('-', 48), $text);
        foreach (explode("\n", rtrim($text)) as $line) {
            $this->assertLessThanOrEqual(48, mb_strlen($line), "qator 48 dan uzun: {$line}");
        }
        // 42 belgidan uzun qator ham bor — kenglik haqiqatan kengaygan.
        $this->assertTrue(collect(explode("\n", $text))->contains(fn ($l) => mb_strlen($l) > 42));
    }

    public function test_48_and_42_receipts_wrap_differently_but_carry_the_same_content(): void
    {
        $wide = $this->restaurant(['receipt_columns' => 48]);
        $normal = $this->restaurant(['receipt_columns' => null]);
        $format = fn (Restaurant $r) => preg_replace('/\s+/', ' ', app(ReceiptFormatter::class)->format($this->longOrder($r)->fresh(['user', 'restaurant']))['text']);

        $a = $format($wide);
        $b = $format($normal);

        foreach (['Juda uzun nomli taom', 'sabzavot va achchiq sous', 'MIJOZDAN OLINADI', 'Tel: +998935556677'] as $needle) {
            $this->assertStringContainsString($needle, $a);
            $this->assertStringContainsString($needle, $b);
        }
    }

    public function test_the_default_80mm_receipt_stays_42_columns(): void
    {
        $r = $this->restaurant(['receipt_paper_mm' => 80]); // receipt_columns berilmagan — avvalgidek
        $text = app(ReceiptFormatter::class)->format($this->longOrder($r)->fresh(['user', 'restaurant']))['text'];

        $this->assertStringContainsString(str_repeat('=', 42), $text);
        $this->assertStringNotContainsString(str_repeat('=', 43), $text);
    }

    public function test_test_receipt_command_uses_48_columns(): void
    {
        Event::fake([PrintJobRequested::class]);
        $r = $this->restaurant(['receipt_columns' => 48]);

        $this->artisan('print-agent:test-receipt', ['restaurant' => (string) $r->id])
            ->expectsOutputToContain('80 mm, 48 belgi')
            ->assertSuccessful();

        Event::assertDispatched(PrintJobRequested::class, function (PrintJobRequested $e) {
            $this->assertStringContainsString(substr(str_repeat('1234567890', 5), 0, 48), $e->text, 'chizg‘ich 48 belgi');
            foreach (explode("\n", rtrim($e->text)) as $line) {
                $this->assertLessThanOrEqual(48, mb_strlen($line));
            }

            return true;
        });
    }

    // --- Saqlash va migratsiya ------------------------------------------------------------------------

    public function test_existing_restaurants_keep_their_values_the_new_column_is_empty(): void
    {
        $r = $this->restaurant(['receipt_paper_mm' => 58]);
        $row = DB::table('restaurants')->find($r->id);

        $this->assertNull($row->receipt_columns);
        $this->assertSame(58, (int) $row->receipt_paper_mm);
        $this->assertSame(32, $r->fresh()->receiptColumns());
    }

    public function test_database_accepts_only_32_42_or_48(): void
    {
        $r = $this->restaurant();

        foreach ([32, 42, 48] as $ok) {
            DB::table('restaurants')->where('id', $r->id)->update(['receipt_columns' => $ok]);
            $this->assertSame($ok, (int) DB::table('restaurants')->find($r->id)->receipt_columns);
        }

        foreach ([40, 50, 0] as $bad) {
            try {
                DB::transaction(fn () => DB::table('restaurants')->where('id', $r->id)->update(['receipt_columns' => $bad]));
                $this->fail("receipt_columns={$bad} rad etilishi kerak edi");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    // --- Admin formasi: 58 mm (32), 80 mm (42), 80 mm (48) -------------------------------------------------

    public function test_admin_form_offers_the_three_options_and_maps_them_to_paper_and_columns(): void
    {
        $this->asAdmin();
        $r = $this->restaurant();

        foreach ([[32, 58], [42, 80], [48, 80]] as [$columns, $paper]) {
            Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
                ->set('data.receipt_columns', $columns)
                ->call('save')
                ->assertHasNoFormErrors();

            $fresh = $r->fresh();
            $this->assertSame([$paper, $columns, $columns], [$fresh->receipt_paper_mm, $fresh->receipt_columns, $fresh->receiptColumns()], "{$columns} belgi");
        }
    }

    public function test_admin_form_shows_the_current_width_for_old_restaurants_and_saving_other_fields_keeps_it(): void
    {
        $this->asAdmin();
        $old58 = $this->restaurant(['receipt_paper_mm' => 58]);          // receipt_columns bo'sh
        $old80 = $this->restaurant(['receipt_paper_mm' => 80]);

        Livewire::test(EditRestaurant::class, ['record' => $old58->getRouteKey()])
            ->assertFormSet(['receipt_columns' => 32, 'receipt_paper_mm' => 58])
            ->fillForm(['name' => 'Yangi nom'])
            ->call('save')
            ->assertHasNoFormErrors();
        Livewire::test(EditRestaurant::class, ['record' => $old80->getRouteKey()])
            ->assertFormSet(['receipt_columns' => 42, 'receipt_paper_mm' => 80])
            ->call('save');

        $this->assertSame([58, 32], [$old58->fresh()->receipt_paper_mm, $old58->fresh()->receiptColumns()]);
        $this->assertSame([80, 42], [$old80->fresh()->receipt_paper_mm, $old80->fresh()->receiptColumns()]);
    }

    public function test_admin_form_lists_exactly_the_three_width_options(): void
    {
        $this->asAdmin();
        $r = $this->restaurant();

        $component = Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()]);
        $field = $component->instance()->form->getComponent('data.receipt_columns');

        $this->assertSame([32, 42, 48], array_keys($field->getOptions()));
        $this->assertStringContainsString('58 mm (32', $field->getOptions()[32]);
        $this->assertStringContainsString('80 mm (42', $field->getOptions()[42]);
        $this->assertStringContainsString('80 mm (48', $field->getOptions()[48]);
    }
}
