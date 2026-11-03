<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PosType;
use App\Events\PrintJobRequested;
use App\Filament\Admin\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Jobs\DispatchOrderJob;
use App\Models\District;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Staff;
use App\Models\User;
use App\Services\Dispatch\EscPos;
use App\Services\Dispatch\OrderDispatcher;
use App\Services\Dispatch\PrintMonitor;
use App\Services\Dispatch\ReceiptFormatter;
use App\Support\QrCode;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Brauzer cheki (pos_type = browser): "Qabul qilish"da oshxona paneli chek sahifasini yashirin iframe'da
 * ochib chop etadi. Server tomoni: chek sahifasi (HTML/CSS, ReceiptFormatter::data bilan bir manba), ruxsat,
 * "chop etildi" belgisi, QR (serverda SVG), print agent monitoringi BU rejimda ishlamasligi.
 */
class BrowserPrintTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->restaurant = $this->restaurant();
        $this->owner = Staff::factory()->owner($this->restaurant)->create();
    }

    private function restaurant(array $extra = []): Restaurant
    {
        return Restaurant::factory()->for(District::factory())->create(array_replace([
            'name' => 'Guzar Kafe', 'pos_type' => PosType::Browser, 'receipt_paper_mm' => 80,
        ], $extra));
    }

    private function order(?Restaurant $r = null, array $extra = [], array $user = []): Order
    {
        return Order::factory()->for($r ?? $this->restaurant)
            ->for(User::factory(array_replace(['full_name' => 'Ali Valiyev', 'phone' => '+998900000000'], $user)))
            ->create(array_replace([
                'status' => OrderStatus::New,
                'delivery_type' => DeliveryType::Delivery,
                'items' => [['product_id' => 1, 'name' => "Lag'mon", 'price' => 2_500_000, 'qty' => 2, 'prep' => 20, 'note' => null]],
                'note' => null,
                'contact_phone' => '+998900000000',
                'payment_method' => PaymentMethod::Cash,
                'address_snapshot' => ['address_text' => 'Chilonzor, 5-uy', 'district' => 'Chilonzor'],
                'total' => 6_000_000,
            ], $extra));
    }

    private function asAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs(Staff::factory()->platformAdmin()->create(), 'admin');
    }

    // --- pos_type: yangi qiymat ------------------------------------------------------------------

    public function test_browser_is_a_pos_type_with_a_label_and_is_not_a_print_agent_mode(): void
    {
        $this->assertSame('browser', PosType::Browser->value);
        $this->assertSame('Brauzer orqali chop etish', PosType::Browser->label());
        $this->assertTrue(PosType::Browser->usesBrowserPrint());
        $this->assertFalse(PosType::Browser->usesPrintAgent());
        $this->assertFalse(PosType::Browser->usesExternalApi());
        $this->assertFalse(PosType::EscPos->usesBrowserPrint());
    }

    public function test_restaurant_in_browser_mode_never_counts_as_having_a_print_agent_even_with_a_token(): void
    {
        $r = $this->restaurant(['print_agent_token' => 'eski-token']);

        $this->assertTrue($r->usesBrowserPrint());
        $this->assertFalse($r->usesPrintAgent());
        $this->assertSame(0, Restaurant::query()->withPrintAgent()->where('restaurants.id', $r->id)->count());
    }

    public function test_database_accepts_browser_and_still_rejects_unknown_pos_types(): void
    {
        $r = $this->restaurant();
        $this->assertSame(PosType::Browser, $r->fresh()->pos_type);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('restaurants')->where('id', $r->id)->update(['pos_type' => 'telepatiya']));
    }

    // --- Monitoring BU rejimda ishlamaydi -----------------------------------------------------------

    public function test_dispatch_in_browser_mode_sends_nothing_to_an_agent(): void
    {
        Event::fake([PrintJobRequested::class]);
        $order = $this->order();

        $result = app(OrderDispatcher::class)->dispatch($order->load('restaurant'));

        $this->assertTrue($result->ok);
        $this->assertSame('browser', $result->driver);
        Event::assertNotDispatched(PrintJobRequested::class);
    }

    public function test_unprinted_browser_orders_are_never_flagged_and_show_no_agent_state(): void
    {
        $r = $this->restaurant(['print_agent_token' => 'eski-token']);
        $order = $this->order($r, ['status' => OrderStatus::Preparing, 'created_at' => now()->subMinutes(30)]);

        $this->assertSame(0, app(PrintMonitor::class)->flagUnprinted());
        $this->assertNull($order->fresh()->dispatch_failed_at);

        $status = app(PrintMonitor::class)->statusFor([$r->id]);
        $this->assertFalse($status['restaurants'][0]['uses_printer']);
        $this->assertSame([], $status['failed_order_ids']);
    }

    public function test_failed_dispatch_job_does_not_raise_the_check_failed_flag_in_browser_mode(): void
    {
        $order = $this->order();

        (new DispatchOrderJob($order->id))->failed(new RuntimeException('boom'));

        $this->assertNull($order->fresh()->dispatch_failed_at);
        $this->assertFalse($order->fresh(['restaurant'])->printFailed());
    }

    public function test_old_leftover_failed_flag_is_not_shown_for_browser_restaurants(): void
    {
        $order = $this->order(null, ['dispatch_failed_at' => now()]);

        $this->assertFalse($order->fresh(['restaurant'])->printFailed());
        $this->actingAs($this->owner, 'staff')->getJson('/kitchen/orders')
            ->assertOk()->assertJsonPath('data.0.dispatch_failed', false);
    }

    public function test_agent_heartbeat_and_pending_are_inert_in_browser_mode_but_work_for_escpos(): void
    {
        $browser = $this->restaurant(['print_agent_token' => 'tok-browser']);
        $escpos = $this->restaurant(['name' => 'Printerli', 'pos_type' => PosType::EscPos, 'print_agent_token' => 'tok-escpos']);
        $this->order($browser);
        $pendingEscpos = $this->order($escpos);

        $this->postJson('/api/agent/heartbeat', [], ['Authorization' => 'Bearer tok-browser'])->assertOk();
        $this->assertNull($browser->fresh()->print_agent_last_seen_at, 'brauzer rejimida heartbeat yozilmaydi');
        $this->getJson('/api/agent/orders/pending', ['Authorization' => 'Bearer tok-browser'])
            ->assertOk()->assertExactJson(['data' => []]);

        $this->postJson('/api/agent/heartbeat', [], ['Authorization' => 'Bearer tok-escpos'])->assertOk();
        $this->assertNotNull($escpos->fresh()->print_agent_last_seen_at, 'escpos rejimi o‘zgarmaydi');
        $this->getJson('/api/agent/orders/pending', ['Authorization' => 'Bearer tok-escpos'])
            ->assertOk()->assertJsonPath('data.0.order_id', $pendingEscpos->id);
    }

    // --- Oshxona kartasi: browser_print belgisi ----------------------------------------------------------

    public function test_kitchen_orders_carry_the_browser_print_flag_per_restaurant(): void
    {
        $other = $this->restaurant(['name' => 'Printersiz', 'pos_type' => PosType::Manual]);
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->alsoAssignedTo($other)->create();
        $mine = $this->order();
        $theirs = $this->order($other);

        $rows = collect($this->actingAs($staff, 'staff')->getJson('/kitchen/orders')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$mine->id]['restaurant']['browser_print']);
        $this->assertFalse($rows[$theirs->id]['restaurant']['browser_print']);
    }

    public function test_an_owner_sees_the_flag_for_a_second_assigned_restaurant_too(): void
    {
        // RestaurantScope egasiga faqat asosiy restoranni ko'rsatadi — boshqa biriktirilgan restoran kartasi ham to'liq bo'lishi kerak.
        $second = $this->restaurant(['name' => 'Ikkinchi', 'pos_type' => PosType::Browser]);
        $owner = Staff::factory()->owner($this->restaurant)->alsoAssignedTo($second)->create();
        $order = $this->order($second);

        $this->actingAs($owner, 'staff')->getJson('/kitchen/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.restaurant.name', 'Ikkinchi')
            ->assertJsonPath('data.0.restaurant.browser_print', true);
    }

    public function test_accepting_an_order_returns_the_flag_so_the_panel_can_auto_print(): void
    {
        Http::fake();
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')
            ->patchJson("/kitchen/orders/{$order->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.restaurant.browser_print', true);
    }

    // --- Chek sahifasi (oldindan ko'rish) -------------------------------------------------------------

    public function test_receipt_page_has_the_brand_logo_restaurant_order_and_the_amount_due(): void
    {
        $order = $this->order(null, [
            'order_number' => 'YT-000123',
            'items' => [
                ['product_id' => 1, 'name' => "Lag'mon", 'price' => 2_500_000, 'qty' => 2, 'prep' => 20, 'note' => 'achchiq emas'],
                ['product_id' => 2, 'name' => 'Плов самаркандский', 'price' => 3_000_000, 'qty' => 1, 'prep' => 15, 'note' => null],
            ],
            'note' => 'Eshik oldiga qo‘ng‘iroq qiling',
            'total' => 24_500_000,
        ]);

        $html = $this->actingAs($this->owner, 'staff')
            ->get("/kitchen/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('YETKAZ UZ')
            ->assertSee('Guzar Kafe')
            ->assertSee('YT-000123')
            ->assertSee('YETKAZISH')
            ->assertSee('2x', false)
            ->assertSee("Lag'mon")
            ->assertSee('achchiq emas')
            ->assertSee('Плов самаркандский')   // HTML'da kirill lotinga o'girilmaydi
            ->assertSee('IZOH')
            ->assertSee('Ali Valiyev')
            ->assertSee('+998900000000')
            ->assertSee('Chilonzor, 5-uy')
            ->assertSee('MIJOZDAN OLINADI')
            ->assertSee('245 000 so‘m')
            ->assertSee('naqd')
            ->assertSee('Yetkaz Uz orqali buyurtma')
            ->assertSee('@Yetkaz_uzbot', false)
            ->getContent();

        // Kenglik/qog'oz va logo: @page, kontrast filtri, 40 mm, ildiz-nisbiy yo'l.
        $this->assertStringContainsString('@page { size: 80mm auto; margin: 0; }', $html);
        $this->assertStringContainsString('<img src="/images/yetkaz-logo.png"', $html);
        $this->assertStringContainsString('filter: grayscale(100%) contrast(180%)', $html);
        $this->assertStringContainsString('width: 40mm', $html);
        $this->assertStringContainsString('width: 72mm', $html);
        $this->assertStringContainsString('color: #000', $html);
    }

    public function test_receipt_page_embeds_the_bot_qr_as_an_inline_svg_with_no_external_service(): void
    {
        $order = $this->order();

        $html = $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();

        $this->assertStringContainsString(QrCode::svg('https://t.me/Yetkaz_uzbot'), $html);
        $this->assertStringContainsString('<svg', $html);
        // Hech qanday tashqi rasm/skript/uslub: faqat o'z domenidagi logo va inline SVG.
        $this->assertDoesNotMatchRegularExpression('#(src|href)=["\']https?://#i', $html);
        $this->assertStringNotContainsString('qrserver', $html);
        $this->assertStringNotContainsString('googleapis', $html);
        $this->assertStringNotContainsString('<script src', $html);
    }

    public function test_receipt_width_follows_the_restaurants_paper_setting(): void
    {
        $r58 = $this->restaurant(['name' => 'Kichik', 'receipt_paper_mm' => 58]);
        $order = $this->order($r58);
        $staff = Staff::factory()->owner($r58)->create();

        $html = $this->actingAs($staff, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();

        $this->assertStringContainsString('@page { size: 58mm auto; margin: 0; }', $html);
        $this->assertStringContainsString('width: 48mm', $html);
        $this->assertStringNotContainsString('size: 80mm', $html);
    }

    public function test_receipt_page_pickup_has_no_address_and_a_discounted_total_with_both_phones(): void
    {
        $order = $this->order(null, [
            'delivery_type' => DeliveryType::Pickup,
            'address_snapshot' => null,
            'contact_phone' => '+998935556677',
            'subtotal' => 6_900_000,
            'total' => 5_900_000,
        ]);
        $order->forceFill(['discount_amount' => 1_000_000, 'discount_restaurant_amount' => 0, 'discount_platform_amount' => 1_000_000])->save();

        $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertSee('OLIB KETISH')
            ->assertDontSee('MANZIL')
            ->assertSee('Chegirma')
            ->assertSee('-10 000 so‘m')
            ->assertSee('59 000 so‘m')
            ->assertSee('+998935556677')
            ->assertSee('Tel (Telegram)')
            ->assertSee('+998900000000');
    }

    public function test_receipt_page_hides_rows_that_do_not_apply(): void
    {
        $order = $this->order(null, ['note' => null, 'eta_minutes' => null, 'contact_phone' => '+998900000000']);

        $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertDontSee('IZOH')
            ->assertDontSee('Chegirma')
            ->assertDontSee('Tel (Telegram)')
            ->assertDontSee('Tayyor:');
    }

    public function test_receipt_page_escapes_customer_supplied_text(): void
    {
        $order = $this->order(null, ['note' => '<script>alert(1)</script>'], ['full_name' => '<b>Hack</b>']);

        $html = $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Hack</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Hack&lt;/b&gt;', $html);
    }

    public function test_receipt_page_drops_emoji_but_keeps_cyrillic_and_apostrophes(): void
    {
        $order = $this->order(null, [
            'items' => [['product_id' => 1, 'name' => "Шашлык 🍢 o'zbekcha", 'price' => 1, 'qty' => 1, 'prep' => 1, 'note' => 'без лука 🙏']],
            'note' => 'Rahmat 🙂👍',
        ], ['full_name' => 'Алишер 🙂 Каримов']);

        $html = $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();

        $this->assertStringContainsString('Шашлык', $html);
        $this->assertStringContainsString('без лука', $html);
        $this->assertStringContainsString('Алишер Каримов', $html);
        $this->assertStringContainsString('Rahmat', $html);
        foreach (['🍢', '🙏', '🙂', '👍'] as $emoji) {
            $this->assertStringNotContainsString($emoji, $html);
        }
    }

    public function test_strip_pictographs_only_removes_symbols(): void
    {
        $this->assertSame("O'zbek oshi", EscPos::stripPictographs("O'zbek  oshi 🍚"));
        $this->assertSame('Плов (Қўй)', EscPos::stripPictographs('Плов 🌶 (Қўй)'));
        $this->assertSame('Aka-uka', EscPos::stripPictographs("Aka-uka\u{FE0F}\u{200D}"));
    }

    public function test_opening_the_receipt_page_writes_nothing(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk();

        $this->assertNull($order->fresh()->printed_at);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_html_and_escpos_receipts_are_built_from_the_same_data(): void
    {
        $order = $this->order(null, [
            'items' => [['product_id' => 1, 'name' => "Lag'mon", 'price' => 2_500_000, 'qty' => 3, 'prep' => 20, 'note' => 'achchiq emas']],
            'contact_phone' => '+998935556677',
        ]);
        $order = $order->fresh(['user', 'restaurant']);
        $formatter = app(ReceiptFormatter::class);

        $data = $formatter->data($order);

        $this->assertSame([['qty' => 3, 'name' => "Lag'mon", 'note' => 'achchiq emas']], $data->items);
        $this->assertSame('+998935556677', $data->phone);
        $this->assertSame('+998900000000', $data->telegramPhone);
        $this->assertSame('Chilonzor, 5-uy Chilonzor', $data->addressText);
        $this->assertSame($order->total, $data->total);

        // Bir xil ma'lumot ESC/POS matnida ham bor (mantiq takrorlanmaydi — ikkalasi data() dan).
        $text = $formatter->format($order)['text'];
        $this->assertStringContainsString('3x  Lag\'mon', $text);
        $this->assertStringContainsString('Tel: +998935556677', $text);
        $this->assertStringContainsString('Tel (Telegram): +998900000000', $text);
        $this->assertStringContainsString('Chilonzor, 5-uy Chilonzor', $text);
        $this->assertStringContainsString('60 000 som', $text);
    }

    // --- Ruxsat -----------------------------------------------------------------------------------

    public function test_guest_cannot_open_or_confirm_a_receipt(): void
    {
        $order = $this->order();

        $this->get("/kitchen/orders/{$order->id}/receipt")->assertRedirect('/kitchen/login');
        $this->postJson("/kitchen/orders/{$order->id}/printed")->assertUnauthorized();
        $this->assertNull($order->fresh()->printed_at);
    }

    public function test_kitchen_staff_of_the_same_restaurant_can_open_the_receipt(): void
    {
        $staff = Staff::factory()->kitchenStaff($this->restaurant)->create();
        $order = $this->order();

        $this->actingAs($staff, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->assertSee('YETKAZ UZ');
    }

    public function test_another_restaurants_staff_cannot_see_or_confirm_the_receipt(): void
    {
        $foreign = $this->restaurant(['name' => 'Begona']);
        $foreignOwner = Staff::factory()->owner($foreign)->create();
        $foreignKitchen = Staff::factory()->kitchenStaff($foreign)->create();
        $order = $this->order();

        // Egasi — 404 (RestaurantScope uni ko'rmaydi), oshxona xodimi — 403.
        $this->actingAs($foreignOwner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertNotFound();
        $this->actingAs($foreignKitchen, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertForbidden();
        $this->actingAs($foreignOwner, 'staff')->postJson("/kitchen/orders/{$order->id}/printed")->assertNotFound();
        $this->actingAs($foreignKitchen, 'staff')->postJson("/kitchen/orders/{$order->id}/printed")->assertForbidden();

        $this->assertNull($order->fresh()->printed_at);
    }

    public function test_receipt_of_a_missing_order_is_404(): void
    {
        $this->actingAs($this->owner, 'staff')->get('/kitchen/orders/999999/receipt')->assertNotFound();
        $this->actingAs($this->owner, 'staff')->get('/kitchen/orders/abc/receipt')->assertNotFound();
    }

    // --- "Chop etildi" belgisi ---------------------------------------------------------------------

    public function test_printed_marker_fills_printed_at_and_clears_the_failed_flag(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $order = $this->order(null, ['dispatch_failed_at' => now()->subMinutes(5)]);

        $this->actingAs($this->owner, 'staff')
            ->postJson("/kitchen/orders/{$order->id}/printed")
            ->assertOk()
            ->assertJsonPath('data.printed', true);

        $fresh = $order->fresh();
        $this->assertTrue($fresh->printed_at->equalTo(now()));
        $this->assertNull($fresh->dispatch_failed_at);
    }

    public function test_reprinting_keeps_the_first_printed_at(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $order = $this->order();
        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/printed")->assertOk();

        Carbon::setTestNow('2026-11-03 10:20:00');
        $this->actingAs($this->owner, 'staff')->postJson("/kitchen/orders/{$order->id}/printed")->assertOk();

        $this->assertTrue($order->fresh()->printed_at->equalTo(Carbon::parse('2026-11-03 10:00:00')));
    }

    public function test_printed_marker_is_refused_for_restaurants_not_in_browser_mode(): void
    {
        $escpos = $this->restaurant(['name' => 'Agentli', 'pos_type' => PosType::EscPos, 'print_agent_token' => 'tok']);
        $staff = Staff::factory()->owner($escpos)->create();
        $order = $this->order($escpos);

        $this->actingAs($staff, 'staff')->postJson("/kitchen/orders/{$order->id}/printed")->assertStatus(409);

        $this->assertNull($order->fresh()->printed_at, 'agent restoranida printed_at ni faqat agent tasdig‘i yozadi');
    }

    // --- Admin: POS bo'limi -------------------------------------------------------------------------------

    public function test_admin_pos_select_offers_browser_printing_and_it_shows_only_the_paper_width(): void
    {
        $this->asAdmin();
        $r = $this->restaurant(['pos_type' => PosType::Manual]);

        $component = Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()]);
        $options = $component->instance()->form->getComponent('data.pos_type')->getOptions();
        $this->assertSame('Brauzer orqali chop etish', $options['browser']);

        $component->set('data.pos_type', 'browser')
            ->assertFormFieldIsVisible('receipt_columns')
            ->assertSee('--kiosk-printing')
            ->assertFormFieldIsHidden('printer_host')
            ->assertFormFieldIsHidden('print_agent_token')
            ->assertFormFieldIsHidden('receipt_codepage');

        $field = $component->instance()->form->getComponent('data.receipt_columns');
        $this->assertSame([32, 42], array_keys($field->getOptions()));
        $this->assertSame('58 mm', $field->getOptions()[32]);
        $this->assertSame('80 mm', $field->getOptions()[42]);
    }

    public function test_admin_can_switch_a_restaurant_to_browser_printing_and_pick_the_paper(): void
    {
        $this->asAdmin();
        $r = $this->restaurant(['pos_type' => PosType::Manual, 'receipt_paper_mm' => 80]);

        Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->set('data.pos_type', 'browser')
            ->set('data.receipt_columns', 32)
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $r->fresh();
        $this->assertSame(PosType::Browser, $fresh->pos_type);
        $this->assertSame(58, $fresh->receipt_paper_mm);
        $this->assertSame(48, $fresh->receiptPrintableMm());
    }

    public function test_escpos_form_is_unchanged_with_three_options_and_agent_fields(): void
    {
        $this->asAdmin();
        $r = $this->restaurant(['pos_type' => PosType::EscPos, 'print_agent_token' => 'tok']);

        $component = Livewire::test(EditRestaurant::class, ['record' => $r->getRouteKey()])
            ->assertFormFieldIsVisible('printer_host')
            ->assertFormFieldIsVisible('print_agent_token')
            ->assertDontSee('--kiosk-printing');

        $this->assertSame([32, 42, 48], array_keys($component->instance()->form->getComponent('data.receipt_columns')->getOptions()));
    }

    public function test_printable_width_comes_from_config_per_paper_size(): void
    {
        $this->assertSame(72, $this->restaurant(['receipt_paper_mm' => 80])->receiptPrintableMm());
        $this->assertSame(48, $this->restaurant(['receipt_paper_mm' => 58])->receiptPrintableMm());
    }

    // --- QR ----------------------------------------------------------------------------------------------

    public function test_qr_is_an_inline_svg_sized_by_css_and_deterministic(): void
    {
        $svg = QrCode::svg('https://t.me/Yetkaz_uzbot');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertMatchesRegularExpression('/viewBox="0 0 256 256"/', $svg);
        $this->assertDoesNotMatchRegularExpression('/<svg[^>]*\swidth="/', $svg, 'o‘lcham CSS bilan beriladi');
        $this->assertSame($svg, QrCode::svg('https://t.me/Yetkaz_uzbot'));
        $this->assertNotSame($svg, QrCode::svg('https://t.me/boshqa_bot'));
    }

    public function test_qr_refuses_empty_content(): void
    {
        $this->expectException(\Throwable::class);

        QrCode::svg('');
    }

    public function test_bot_username_comes_from_config_and_loses_a_leading_at_sign(): void
    {
        config(['print.browser.bot_username' => '@Boshqa_bot']);
        $order = $this->order();

        $html = $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")->assertOk()->getContent();

        $this->assertStringContainsString('@Boshqa_bot', $html);
        $this->assertStringNotContainsString('@@Boshqa_bot', $html);
        $this->assertStringContainsString(QrCode::svg('https://t.me/Boshqa_bot'), $html);
    }

    public function test_logo_path_is_configurable_so_the_file_can_be_swapped(): void
    {
        config(['print.browser.logo' => 'images/receipt-logo.png']);
        $order = $this->order();

        $this->actingAs($this->owner, 'staff')->get("/kitchen/orders/{$order->id}/receipt")
            ->assertOk()->assertSee('<img src="/images/receipt-logo.png"', false);
    }
}
