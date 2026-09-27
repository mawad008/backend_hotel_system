<?php

namespace Tests\Unit\StayServices;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Services\ServiceCatalogService;

class ServiceCatalogServiceTest extends StayServicesTestCase
{
    private function catalog(): ServiceCatalogService
    {
        return app(ServiceCatalogService::class);
    }

    public function test_create_category_derives_hotel_and_audits(): void
    {
        $hotel = Hotel::factory()->create();
        $actor = User::factory()->groupOwner()->create();

        $category = $this->catalog()->createCategory($hotel, [
            'name' => 'Spa', 'hotel_id' => Hotel::factory()->create()->id, 'is_active' => false,
        ], $actor);

        $this->assertSame($hotel->id, $category->hotel_id);
        $this->assertTrue($category->is_active, 'is_active from the client is ignored; column default wins');
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_category.created', 'actor_id' => $actor->id]);
    }

    public function test_create_service_snapshots_nothing_and_rejects_cross_hotel_category(): void
    {
        $hotel = Hotel::factory()->create();
        $foreignCategory = ServiceCategory::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $service = $this->catalog()->createService($hotel, [
            'name' => 'Airport pickup',
            'price' => '40.00',
            'currency' => 'USD',
            'service_category_id' => $foreignCategory->id,
        ], null);

        $this->assertSame($hotel->id, $service->hotel_id);
        $this->assertNull($service->service_category_id, 'a cross-hotel category id is never attached');
        $this->assertSame('40.00', $service->price);
    }

    public function test_same_hotel_category_is_attached(): void
    {
        $hotel = Hotel::factory()->create();
        $category = ServiceCategory::factory()->create(['hotel_id' => $hotel->id]);

        $service = $this->catalog()->createService($hotel, [
            'name' => 'Breakfast', 'price' => '15.00', 'service_category_id' => $category->id,
        ], null);

        $this->assertSame($category->id, $service->service_category_id);
    }

    public function test_update_service_cannot_change_hotel_or_activation(): void
    {
        $service = HotelService::factory()->create(['is_active' => true, 'price' => '10.00']);
        $originalHotel = $service->hotel_id;

        $updated = $this->catalog()->updateService($service, [
            'hotel_id' => Hotel::factory()->create()->id,
            'is_active' => false,
            'price' => '12.50',
        ], null);

        $this->assertSame($originalHotel, $updated->hotel_id);
        $this->assertTrue($updated->is_active);
        $this->assertSame('12.50', $updated->price);
    }

    public function test_activate_and_deactivate_service_are_audited(): void
    {
        $service = HotelService::factory()->create(['is_active' => true]);

        $this->catalog()->deactivateService($service, null);
        $this->assertFalse($service->fresh()->is_active);

        $this->catalog()->activateService($service->fresh(), null);
        $this->assertTrue($service->fresh()->is_active);

        $this->assertSame(1, AuditLog::where('action', 'service.deactivated')->count());
        $this->assertSame(1, AuditLog::where('action', 'service.activated')->count());
    }

    public function test_there_is_no_delete_path(): void
    {
        $this->assertFalse(method_exists(ServiceCatalogService::class, 'deleteService'));
        $this->assertFalse(method_exists(ServiceCatalogService::class, 'deleteCategory'));
    }
}
