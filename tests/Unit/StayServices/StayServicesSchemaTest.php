<?php

namespace Tests\Unit\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Models\ServiceOrder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StayServicesSchemaTest extends TestCase
{
    public function test_all_four_tables_exist(): void
    {
        foreach (['service_categories', 'hotel_services', 'service_orders', 'folio_charges'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table {$table}");
        }
    }

    public function test_category_name_is_unique_per_hotel_but_not_across_hotels(): void
    {
        $a = Hotel::factory()->create();
        $b = Hotel::factory()->create();

        ServiceCategory::factory()->create(['hotel_id' => $a->id, 'name' => 'Spa']);
        ServiceCategory::factory()->create(['hotel_id' => $b->id, 'name' => 'Spa']); // ok — different hotel

        $this->expectException(UniqueConstraintViolationException::class);
        ServiceCategory::factory()->create(['hotel_id' => $a->id, 'name' => 'Spa']);
    }

    public function test_service_name_is_unique_per_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        HotelService::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Laundry']);

        $this->expectException(UniqueConstraintViolationException::class);
        HotelService::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Laundry']);
    }

    public function test_one_folio_charge_per_source(): void
    {
        $order = ServiceOrder::factory()->create();

        FolioCharge::factory()->create([
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'source_id' => $order->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        FolioCharge::factory()->create([
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'source_id' => $order->id,
        ]);
    }

    public function test_money_columns_keep_two_decimal_places_exactly(): void
    {
        $order = ServiceOrder::factory()->create([
            'unit_price_snapshot' => '123.45',
            'total_amount' => '246.90',
        ]);

        $this->assertSame('123.45', $order->fresh()->unit_price_snapshot);
        $this->assertSame('246.90', $order->fresh()->total_amount);

        $charge = FolioCharge::factory()->create(['unit_amount' => '0.05', 'total_amount' => '0.15', 'quantity' => 3]);
        $this->assertSame('0.05', $charge->fresh()->unit_amount);
        $this->assertSame('0.15', $charge->fresh()->total_amount);
    }

    public function test_deleting_a_hotel_with_a_service_is_blocked(): void
    {
        $service = HotelService::factory()->create();

        $this->expectException(QueryException::class);
        Hotel::query()->whereKey($service->hotel_id)->delete();
    }

    public function test_deleting_a_reservation_with_a_service_order_is_blocked(): void
    {
        $order = ServiceOrder::factory()->create();

        $this->expectException(QueryException::class);
        Reservation::query()->whereKey($order->reservation_id)->delete();
    }

    public function test_deleting_a_reservation_with_a_folio_charge_is_blocked(): void
    {
        $charge = FolioCharge::factory()->create();

        $this->expectException(QueryException::class);
        Reservation::query()->whereKey($charge->reservation_id)->delete();
    }

    public function test_deleting_a_category_nulls_its_services_link(): void
    {
        $category = ServiceCategory::factory()->create();
        $service = HotelService::factory()->create([
            'hotel_id' => $category->hotel_id, 'service_category_id' => $category->id,
        ]);

        $category->delete();

        $this->assertNull($service->fresh()->service_category_id);
    }
}
