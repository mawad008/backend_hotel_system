<?php

namespace Tests\Unit\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Repositories\EloquentFolioChargeRepository;
use App\Domain\StayServices\Repositories\EloquentHotelServiceRepository;
use App\Domain\StayServices\Repositories\EloquentServiceCategoryRepository;
use App\Domain\StayServices\Repositories\EloquentServiceOrderRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StayServicesRepositoryTest extends TestCase
{
    public function test_category_repo_scopes_to_the_users_hotels(): void
    {
        $mine = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        ServiceCategory::factory()->create(['hotel_id' => $mine->id]);
        ServiceCategory::factory()->create(['hotel_id' => $other->id]);

        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($mine);

        $page = (new EloquentServiceCategoryRepository)->paginateForHotel($manager, $mine, 15);
        $this->assertCount(1, $page->items());

        $owner = User::factory()->groupOwner()->create();
        $this->assertCount(1, (new EloquentServiceCategoryRepository)->paginateForHotel($owner, $mine, 15)->items());
    }

    public function test_service_repo_active_filter(): void
    {
        $hotel = Hotel::factory()->create();
        HotelService::factory()->count(2)->create(['hotel_id' => $hotel->id, 'is_active' => true]);
        HotelService::factory()->create(['hotel_id' => $hotel->id, 'is_active' => false]);
        $owner = User::factory()->groupOwner()->create();

        $repo = new EloquentHotelServiceRepository;
        $this->assertCount(3, $repo->paginateForHotel($owner, $hotel, null)->items());
        $this->assertCount(2, $repo->paginateForHotel($owner, $hotel, true)->items());
        $this->assertCount(1, $repo->paginateForHotel($owner, $hotel, false)->items());
    }

    public function test_service_lock_helper_runs_in_a_transaction(): void
    {
        $service = HotelService::factory()->create();

        DB::transaction(function () use ($service) {
            $this->assertSame($service->id, (new EloquentHotelServiceRepository)->findForUpdate($service->id)?->id);
        });
    }

    public function test_service_order_repo_only_returns_a_reservations_own_orders(): void
    {
        $order = ServiceOrder::factory()->create();
        ServiceOrder::factory()->create();

        $repo = new EloquentServiceOrderRepository;
        $this->assertCount(1, $repo->paginateForReservation($order->reservation_id)->items());
        $this->assertCount(1, $repo->allForReservation($order->reservation_id));
    }

    public function test_folio_charge_repo_find_by_source_and_sum(): void
    {
        $repo = new EloquentFolioChargeRepository;
        $charge = FolioCharge::factory()->amount('10.00', 2)->create(['source_id' => 5001]);

        $this->assertSame($charge->id, $repo->findBySource(FolioCharge::SOURCE_SERVICE_ORDER, 5001)?->id);
        $this->assertNull($repo->findBySource(FolioCharge::SOURCE_SERVICE_ORDER, 9999));

        FolioCharge::factory()->amount('5.55', 1)->create([
            'reservation_id' => $charge->reservation_id, 'hotel_id' => $charge->hotel_id, 'source_id' => 5002,
        ]);
        FolioCharge::factory()->amount('100.00', 1)->cancelled()->create([
            'reservation_id' => $charge->reservation_id, 'hotel_id' => $charge->hotel_id, 'source_id' => 5003,
        ]);

        $this->assertSame(
            '25.55',
            $repo->sumTotalForReservation($charge->reservation_id, [FolioCharge::STATUS_POSTED]),
        );
        $this->assertSame('0.00', $repo->sumTotalForReservation($charge->reservation_id, []));
        $this->assertSame('0.00', $repo->sumTotalForReservation(999999, [FolioCharge::STATUS_POSTED]));
    }
}
