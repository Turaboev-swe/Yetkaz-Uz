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
use App\Services\Dispatch\EscPos;
use App\Services\Dispatch\ReceiptFormatter;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/** Chek formati: kodlash/kenglik sozlamalari, transliteratsiya, telefon/mijoz/to'lov, test-chek buyrug'i. */
class ReceiptFormatTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(array $extra = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'Chek Resto', 'pos_type' => PosType::EscPos, 'print_agent_token' => 'tok-1',
        ], $extra));
    }

    private function order(Restaurant $r, array $extra = [], array $user = []): Order
    {
        return Order::factory()->for($r)->for(User::factory(array_replace(['full_name' => 'Ali Valiyev', 'phone' => '+998900000000'], $user)))->create(array_replace([
            'delivery_type' => DeliveryType::Delivery,
            'items' => [['product_id' => 1, 'name' => "Lag'mon", 'price' => 2_500_000, 'qty' => 2, 'prep' => 20, 'note' => null]],
            'note' => null,
            'contact_phone' => '+998900000000',
            'payment_method' => PaymentMethod::Cash,
            'address_snapshot' => ['address_text' => "Chilonzor, 5-uy", 'district' => 'Chilonzor'],
            'total' => 6_000_000,
        ], $extra));
    }

    private function text(Order $o): string
    {
        return app(ReceiptFormatter::class)->format($o->fresh(['user', 'restaurant']))['text'];
    }

    // --- Transliteratsiya va ASCII ---------------------------------------------------------------

    public function test_cyrillic_is_transliterated_to_latin_not_dropped(): void
    {
        $this->assertSame('Plov samarkandskiy s govyadinoy', EscPos::ascii('Плов самаркандский с говядиной'));
        $this->assertSame("Kam yog', o'tkir emas", EscPos::ascii('Кам ёғ, ўткир эмас'));
        $this->assertSame('Pojaluysta, bez luka', EscPos::ascii('Пожалуйста, без лука'));
        $this->assertSame('Shashlik iz baranini', EscPos::ascii('Шашлык из баранины'));
        $this->assertSame('Choy i xleb', EscPos::ascii('Чой и хлеб'));
    }

    public function test_uppercase_cyrillic_keeps_its_case_without_ugly_mixed_case(): void
    {
        $this->assertSame('JARENIY PLOV', EscPos::ascii('ЖАРЕНЫЙ ПЛОВ'));
        $this->assertSame('Jareniy', EscPos::ascii('Жареный'));
        $this->assertSame('Yoldosh', EscPos::ascii('Ёлдош'));
        $this->assertSame('SHASHLIK', EscPos::ascii('ШАШЛЫК'));
    }

    public function test_emoji_and_other_non_ascii_characters_are_removed(): void
    {
        $this->assertSame('Salom  dunyo', EscPos::ascii('Salom 🍕 dunyo'));
        $this->assertSame('Salom dunyo', EscPos::clean('Salom 🍕 dunyo'));
        $this->assertSame('Spasibo', EscPos::clean('Спасибо 🙏👍🏽'));
        $this->assertSame('', EscPos::clean('🙂🙂🙂'));
        $this->assertSame('xyz', EscPos::ascii('x漢y字z'));
        $this->assertDoesNotMatchRegularExpression('/[^\x20-\x7E\n]/', EscPos::ascii("Test ❤️ \u{200D} \u{FE0F} ñ ü"));
    }

    public function test_apostrophes_and_typographic_characters_become_plain_ascii(): void
    {
        $this->assertSame("O'zbek go'sht", EscPos::ascii('Oʻzbek goʻsht'));
        $this->assertSame("It's a 'test'", EscPos::ascii('It’s a ‘test’'));
        $this->assertSame("a - b - c", EscPos::ascii('a – b — c'));
        $this->assertSame('"Plov"', EscPos::ascii('«Plov»'));
    }

    public function test_latin_diacritics_fall_back_to_the_base_letter(): void
    {
        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('intl yo‘q');
        }

        $this->assertSame('Cafe Muller', EscPos::ascii('Café Müller'));
    }

    public function test_full_receipt_with_cyrillic_and_emoji_is_pure_ascii_in_both_outputs(): void
    {
        $r = $this->restaurant(['name' => 'Ресторан «Плов» 🍚']);
        $o = $this->order($r, [
            'items' => [['product_id' => 1, 'name' => 'Шашлык 🍢', 'price' => 1, 'qty' => 1, 'prep' => 1, 'note' => 'без лука 🙏']],
            'note' => 'Позвоните 📞 пожалуйста',
        ], ['full_name' => 'Алишер 🙂']);

        $receipt = app(ReceiptFormatter::class)->format($o->fresh(['user', 'restaurant']));

        foreach (['text', 'escpos'] as $key) {
            // ESC/POS buyruq baytlari (<0x20 va ESC/GS ketma-ketliklari) bundan mustasno: 0x80+ bayt bo'lmasligi shart.
            $this->assertDoesNotMatchRegularExpression('/[\x80-\xFF]/', $receipt[$key], "{$key} da ASCII bo‘lmagan bayt");
        }
        $this->assertStringContainsString('Restoran "Plov"', $receipt['text']);
        $this->assertStringContainsString('Shashlik', $receipt['text']);
        $this->assertStringContainsString('bez luka', $receipt['text']);
        $this->assertStringContainsString('Pozvonite pojaluysta', str_replace(['Pozvonite', 'Позвоните'], 'Pozvonite', 'Pozvonite pojaluysta'));
        $this->assertStringContainsString('Pozvonite pojaluysta', $receipt['text']);
        $this->assertStringContainsString('Mijoz: Alisher', $receipt['text']);
    }

    // --- Kodlash jadvali va kenglik --------------------------------------------------------------

    public function test_default_codepage_is_16_and_the_old_mismatched_6_is_gone(): void
    {
        $r = $this->restaurant();
        $raw = app(ReceiptFormatter::class)->format($this->order($r)->fresh(['user', 'restaurant']))['escpos'];

        $this->assertStringStartsWith("\x1b@\x1bt\x10", $raw); // ESC @ ; ESC t 16
        $this->assertStringNotContainsString("\x1bt\x06", $raw);
    }

    public function test_codepage_is_a_per_restaurant_setting(): void
    {
        $r = $this->restaurant(['receipt_codepage' => 19]); // PC858
        $raw = app(ReceiptFormatter::class)->format($this->order($r)->fresh(['user', 'restaurant']))['escpos'];

        $this->assertStringStartsWith("\x1b@\x1bt\x13", $raw);
        $this->assertSame(19, $r->fresh()->receiptCodepage());
    }

    public function test_80mm_receipt_uses_42_columns_and_58mm_uses_32(): void
    {
        $long = "Juda uzun nomli taom: Lag'mon ustiga qo'shimcha go'sht, sabzavot va achchiq sous (katta porsiya)";
        foreach ([80 => 42, 58 => 32] as $mm => $columns) {
            $r = $this->restaurant(['receipt_paper_mm' => $mm, 'name' => 'Juda uzun nomli restoran bu yerda', 'print_agent_token' => "tok-{$mm}"]);
            $o = $this->order($r, [
                'items' => [['product_id' => 1, 'name' => $long, 'price' => 1, 'qty' => 12, 'prep' => 1, 'note' => 'juda uzun izoh: '.$long]],
                'note' => $long.' '.$long,
                'address_snapshot' => ['address_text' => $long, 'district' => 'Chilonzor', 'entrance' => '2', 'floor' => '5', 'apartment' => '45'],
            ], ['full_name' => 'Familiyasi juda uzun mijoz Abdurahmonovich Ibrohimjonov']);

            $text = $this->text($o);

            $this->assertSame($columns, $r->fresh()->receiptColumns());
            $this->assertStringContainsString(str_repeat('=', $columns), $text);
            $this->assertStringNotContainsString(str_repeat('=', $columns + 1), $text);
            foreach (explode("\n", rtrim($text)) as $line) {
                $this->assertLessThanOrEqual($columns, mb_strlen($line), "{$mm} mm: qator {$columns} dan uzun: {$line}");
            }
        }
    }

    public function test_long_item_names_wrap_with_a_hanging_indent(): void
    {
        $r = $this->restaurant();
        $o = $this->order($r, ['items' => [['product_id' => 1, 'name' => "Juda uzun nomli taom: Lag'mon ustiga qo'shimcha go'sht, sabzavot va achchiq sous", 'price' => 1, 'qty' => 2, 'prep' => 1, 'note' => null]]]);

        $lines = explode("\n", $this->text($o));
        // Birinchi qator: "2x  " prefiksi, nom bo'lagi va o'ngda qator jami; nom narxga yetmaydi.
        $start = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '2x  Juda uzun nomli taom: Lag\'mon')) {
                $start = $i;
            }
        }

        $this->assertNotNull($start, 'birinchi qator "2x  " prefiksi bilan');
        $this->assertStringStartsWith('    qo\'shimcha go\'sht', $lines[$start + 1]); // keyingi qator 4 bo'shliq chekinish bilan
    }

    public function test_a_word_longer_than_the_line_is_split_instead_of_overflowing(): void
    {
        $p = new EscPos(32, 16);

        $lines = $p->wrap(str_repeat('x', 80));

        $this->assertSame([32, 32, 16], array_map('mb_strlen', $lines));
    }

    public function test_columns_never_exceed_the_width(): void
    {
        $p = new EscPos(32, 16);
        $p->columns(str_repeat('L', 60), '123 456 som');
        $p->columns('Chap', str_repeat('R', 60));

        foreach (explode("\n", rtrim($p->plain())) as $line) {
            $this->assertLessThanOrEqual(32, mb_strlen($line));
        }
    }

    // --- Mijoz, telefon, to'lov --------------------------------------------------------------------

    public function test_pickup_receipt_shows_the_phone_and_customer_name_too(): void
    {
        $r = $this->restaurant();
        $text = $this->text($this->order($r, ['delivery_type' => DeliveryType::Pickup, 'address_snapshot' => null, 'contact_phone' => '+998901234567'], ['full_name' => 'Sardor Aliyev']));

        $this->assertStringContainsString('OLIB KETISH', $text);
        $this->assertStringContainsString('Mijoz: Sardor Aliyev', $text);
        $this->assertStringContainsString('Tel: +998901234567', $text);
        $this->assertStringNotContainsString('MANZIL', $text);
    }

    public function test_delivery_receipt_shows_both_phones_when_they_differ_and_the_name(): void
    {
        $r = $this->restaurant();
        $text = $this->text($this->order($r, ['contact_phone' => '+998935556677'], ['phone' => '+998901112233', 'full_name' => 'Ali Valiyev']));

        $this->assertStringContainsString('Mijoz: Ali Valiyev', $text);
        $this->assertStringContainsString('Tel: +998935556677', $text);
        $this->assertStringContainsString('Tel (Telegram): +998901112233', $text);
        $this->assertStringContainsString('MANZIL', $text);
    }

    public function test_same_phone_is_printed_once(): void
    {
        $r = $this->restaurant();
        $text = $this->text($this->order($r, ['contact_phone' => '+998900000000'], ['phone' => '+998900000000']));

        $this->assertSame(1, substr_count($text, '+998900000000'));
        $this->assertStringNotContainsString('Telegram', $text);
    }

    public function test_payment_line_follows_the_payment_method(): void
    {
        $r = $this->restaurant();

        $this->assertStringContainsString('Tolov: naqd', $this->text($this->order($r, ['payment_method' => PaymentMethod::Cash])));
        $this->assertStringContainsString('Tolov: Payme (onlayn)', $this->text($this->order($r, ['payment_method' => PaymentMethod::Payme])));
        $this->assertStringContainsString('Tolov: Click (onlayn)', $this->text($this->order($r, ['payment_method' => PaymentMethod::Click])));
    }

    public function test_a_customer_without_a_name_is_just_skipped(): void
    {
        $r = $this->restaurant();
        $text = $this->text($this->order($r, [], ['full_name' => null]));

        $this->assertStringNotContainsString('Mijoz:', $text);
        $this->assertStringContainsString('Tel:', $text);
    }

    // --- Sozlamalar (DB va forma) ------------------------------------------------------------------

    public function test_new_restaurants_default_to_80mm_and_codepage_16(): void
    {
        $r = Restaurant::factory()->for(District::factory())->create(); // receipt_* berilmagan — DB standarti
        $row = DB::table('restaurants')->find($r->id);

        $this->assertSame([80, 16], [(int) $row->receipt_paper_mm, (int) $row->receipt_codepage]);
    }

    public function test_database_rejects_unsupported_paper_widths_and_codepages(): void
    {
        $r = $this->restaurant();

        foreach ([['receipt_paper_mm', 70], ['receipt_codepage', 300]] as [$column, $value]) {
            try {
                DB::transaction(fn () => DB::table('restaurants')->where('id', $r->id)->update([$column => $value]));
                $this->fail("{$column}={$value} rad etilishi kerak edi");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_admin_form_saves_paper_width_and_codepage(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
        $r = $this->restaurant();

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->set('data.receipt_columns', 32) // 58 mm tanlandi -> qog'oz kengligi ham 58 bo'ladi
            ->fillForm(['receipt_codepage' => 19])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([58, 19, 32], [$r->fresh()->receipt_paper_mm, $r->fresh()->receipt_codepage, $r->fresh()->receiptColumns()]);
    }

    // --- print-agent:test-receipt ---------------------------------------------------------------------

    public function test_test_receipt_command_sends_a_sample_receipt_to_the_restaurant_channel(): void
    {
        Event::fake([PrintJobRequested::class]);
        $r = $this->restaurant(['receipt_paper_mm' => 58, 'receipt_codepage' => 19]);

        $this->artisan('print-agent:test-receipt', ['restaurant' => (string) $r->id])
            ->expectsOutputToContain('58 mm, 32 belgi')
            ->expectsOutputToContain('ESC t 19')
            ->assertSuccessful();

        Event::assertDispatched(PrintJobRequested::class, function (PrintJobRequested $e) use ($r) {
            $raw = base64_decode($e->escposBase64);

            $this->assertSame("private-restaurant.{$r->id}.print", $e->broadcastOn()[0]->name);
            $this->assertLessThan(0, $e->orderId, 'sinov cheki salbiy id bilan — haqiqiy buyurtma emas');
            $this->assertStringStartsWith("\x1b@\x1bt\x13", $raw);
            $this->assertStringContainsString('*** TEST CHEK ***', $e->text);
            $this->assertStringContainsString('Plov samarkandskiy s govyadinoy', preg_replace('/\s+/', ' ', $e->text)); // kirill -> lotin (58 mm da qatorga bo'linadi)
            $this->assertStringContainsString("go'sht", $e->text);                          // ' bilan
            $this->assertStringContainsString('Juda uzun nomli taom', $e->text);            // uzun nom
            $this->assertDoesNotMatchRegularExpression('/[\x80-\xFF]/', $raw);            // emoji yo'q
            foreach (explode("\n", rtrim($e->text)) as $line) {
                $this->assertLessThanOrEqual(32, mb_strlen($line), "qator 32 dan uzun: {$line}");
            }

            return true;
        });
    }

    public function test_test_receipt_can_append_the_codepage_table(): void
    {
        Event::fake([PrintJobRequested::class]);
        $r = $this->restaurant();

        $this->artisan('print-agent:test-receipt', ['restaurant' => $r->name, '--table' => true])->assertSuccessful();

        Event::assertDispatched(PrintJobRequested::class, function (PrintJobRequested $e) {
            $raw = base64_decode($e->escposBase64);

            return str_contains($e->text, 'Kodlash jadvali 16')
                && str_contains($raw, "8x: \x80\x81\x82")
                && str_contains($raw, 'Fx: '."\xF0\xF1");
        });
    }

    public function test_test_receipt_dry_run_prints_the_text_without_sending(): void
    {
        Event::fake([PrintJobRequested::class]);
        $r = $this->restaurant();

        $this->artisan('print-agent:test-receipt', ['restaurant' => (string) $r->id, '--dry' => true])
            ->expectsOutputToContain('*** TEST CHEK ***')
            ->assertSuccessful();

        Event::assertNotDispatched(PrintJobRequested::class);
    }

    public function test_test_receipt_refuses_restaurants_without_a_printer_and_unknown_ones(): void
    {
        Event::fake([PrintJobRequested::class]);
        $manual = $this->restaurant(['pos_type' => PosType::Manual, 'name' => 'Qo‘lda']);

        $this->artisan('print-agent:test-receipt', ['restaurant' => (string) $manual->id])->assertFailed();
        $this->artisan('print-agent:test-receipt', ['restaurant' => '999999'])->assertFailed();

        Event::assertNotDispatched(PrintJobRequested::class);
    }

    public function test_test_receipt_warns_when_the_agent_is_offline(): void
    {
        Event::fake([PrintJobRequested::class]);
        $r = $this->restaurant(['print_agent_last_seen_at' => null]);

        $this->artisan('print-agent:test-receipt', ['restaurant' => (string) $r->id])
            ->expectsOutputToContain('Printer agenti hozir o\'chiq')
            ->assertSuccessful();
    }
}
