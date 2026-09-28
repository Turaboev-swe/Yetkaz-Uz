<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\Ordering\PromoCodeService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * applyForOrder() tranzaksiyasiz chaqirilsa — xato. Tranzaksiyasiz
 * SELECT ... FOR UPDATE darhol qo'yib yuboriladi (auto-commit) va poyga
 * holatidan himoya jimgina yo'qoladi.
 *
 * ATAYLAB RefreshDatabase'siz: u testni tranzaksiyaga o'raydi (level >= 1),
 * bu yerda esa level 0 kerak. Himoya bazaga murojaatdan OLDIN ishlaydi —
 * modellar saqlanmaydi.
 */
class PromoCodeServiceTransactionGuardTest extends TestCase
{
    public function test_apply_for_order_outside_a_transaction_throws(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);

        app(PromoCodeService::class)->applyForOrder('ANY', new Restaurant, 10_000_00, new User);
    }

    public function test_no_code_outside_a_transaction_is_fine(): void
    {
        $result = app(PromoCodeService::class)->applyForOrder(null, new Restaurant, 10_000_00, new User);

        $this->assertNull($result->promoCodeId);
    }
}
