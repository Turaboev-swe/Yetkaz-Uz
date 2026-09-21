<?php

namespace Tests\Feature;

use App\Enums\BroadcastAudience;
use App\Jobs\SendBroadcastMessage;
use App\Models\Address;
use App\Models\District;
use App\Models\User;
use App\Services\Broadcast\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * BroadcastService — auditoriyani aniqlash ("barcha" / "tuman") va har
 * foydalanuvchi uchun alohida SendBroadcastMessage job navbatga qo'yish.
 */
class BroadcastServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(): BroadcastService
    {
        return app(BroadcastService::class);
    }

    /**
     * 2026-09-22: profile_completed shart olib tashlandi — "Barcha
     * foydalanuvchilar" endi botga /start bosgan (telegram_id bor) HAMMASINI
     * qamrab oladi, ro'yxatdan to'liq o'tmaganlar ham.
     */
    public function test_all_audience_targets_every_user_with_a_telegram_id_regardless_of_registration(): void
    {
        $a = User::factory()->create(['telegram_id' => 111, 'profile_completed' => true]);
        $b = User::factory()->create(['telegram_id' => 222, 'profile_completed' => true]);
        $incomplete = User::factory()->create(['telegram_id' => 333, 'profile_completed' => false]);

        $result = $this->service()->send('Salom!', null, BroadcastAudience::All, [], null);

        $this->assertSame(3, $result['target_count']);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $a->id);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $b->id);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $incomplete->id);
    }

    public function test_district_audience_only_targets_users_with_an_address_there(): void
    {
        $districtA = District::factory()->create(['name' => 'A tumani']);
        $districtB = District::factory()->create(['name' => 'B tumani']);

        $inA = User::factory()->create(['telegram_id' => 111]);
        Address::factory()->for($inA)->create(['district_id' => $districtA->id]);

        $inB = User::factory()->create(['telegram_id' => 222]);
        Address::factory()->for($inB)->create(['district_id' => $districtB->id]);

        $noAddress = User::factory()->create(['telegram_id' => 333]);

        $result = $this->service()->send('Aksiya!', null, BroadcastAudience::District, [$districtA->id], null);

        $this->assertSame(1, $result['target_count']);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $inA->id);
        Queue::assertNotPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $inB->id);
        Queue::assertNotPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $noAddress->id);
    }

    public function test_district_audience_with_several_districts_selected(): void
    {
        $districtA = District::factory()->create();
        $districtB = District::factory()->create();
        $districtC = District::factory()->create();

        $inA = User::factory()->create();
        Address::factory()->for($inA)->create(['district_id' => $districtA->id]);
        $inC = User::factory()->create();
        Address::factory()->for($inC)->create(['district_id' => $districtC->id]);
        $inB = User::factory()->create();
        Address::factory()->for($inB)->create(['district_id' => $districtB->id]);

        $result = $this->service()->send('Xabar', null, BroadcastAudience::District, [$districtA->id, $districtC->id], null);

        $this->assertSame(2, $result['target_count']);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $inA->id);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $inC->id);
        Queue::assertNotPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $inB->id);
    }

    /** District auditoriyasi o'zgarmadi — profile_completed'ga bog'liq emas, faqat manzil bor-yo'qligiga. */
    public function test_district_audience_is_unaffected_by_profile_completed(): void
    {
        $district = District::factory()->create();

        $incomplete = User::factory()->create(['profile_completed' => false]);
        Address::factory()->for($incomplete)->create(['district_id' => $district->id]);

        $result = $this->service()->send('Xabar', null, BroadcastAudience::District, [$district->id], null);

        $this->assertSame(1, $result['target_count']);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->userId === $incomplete->id);
    }

    public function test_a_user_with_several_addresses_in_the_target_district_is_targeted_only_once(): void
    {
        $district = District::factory()->create();
        $user = User::factory()->create();
        Address::factory()->for($user)->count(3)->create(['district_id' => $district->id]);

        $result = $this->service()->send('Xabar', null, BroadcastAudience::District, [$district->id], null);

        $this->assertSame(1, $result['target_count']);
        Queue::assertPushed(SendBroadcastMessage::class, 1);
    }

    public function test_broadcast_record_is_created_with_the_given_data(): void
    {
        $district = District::factory()->create();

        $result = $this->service()->send('Yangi taomlar!', 'broadcasts/x.jpg', BroadcastAudience::District, [$district->id], null);

        $broadcast = $result['broadcast'];
        $this->assertSame('Yangi taomlar!', $broadcast->message);
        $this->assertSame('broadcasts/x.jpg', $broadcast->image_path);
        $this->assertSame(BroadcastAudience::District, $broadcast->audience_type);
        $this->assertSame([$district->id], $broadcast->district_ids);
        $this->assertSame(0, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->failed_count);
    }

    public function test_all_audience_does_not_store_district_ids(): void
    {
        $result = $this->service()->send('Salom', null, BroadcastAudience::All, [], null);

        $this->assertNull($result['broadcast']->district_ids);
    }
}
