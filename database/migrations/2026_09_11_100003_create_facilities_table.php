<?php

use App\Domain\HotelGroup\Enums\HotelAmenity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the fixed `HotelAmenity` enum + `hotels.amenities` JSON column
 * with a real Facility catalog + `facility_hotel` pivot, so hotel facilities
 * are managed as first-class entities instead of duplicated per-hotel
 * strings (Hotel module facilities hardening).
 *
 * The 12 existing enum values seed the catalog 1:1, using the exact EN/AR
 * labels already approved and shipped in the dashboard i18n files
 * (`hotels.amenity.*`) — not invented text. Every hotel's existing
 * `amenities` JSON array is migrated into `facility_hotel` before the
 * column is dropped, so no hotel loses its current facility selection.
 *
 * `RoomType.amenities` is a separate, untouched JSON column — Room Type /
 * Room ↔ Facilities is a deliberate deferred decision (see the Hotel
 * module plan), not built here.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{en: string, ar: string}>
     */
    private array $seedLabels = [
        'free_wifi' => ['en' => 'Free Wi-Fi', 'ar' => 'واي فاي مجاني'],
        'breakfast' => ['en' => 'Breakfast', 'ar' => 'إفطار'],
        'parking' => ['en' => 'Parking', 'ar' => 'موقف سيارات'],
        'pool' => ['en' => 'Pool', 'ar' => 'مسبح'],
        'gym' => ['en' => 'Gym', 'ar' => 'نادٍ رياضي'],
        'family_rooms' => ['en' => 'Family rooms', 'ar' => 'غرف عائلية'],
        'airport_shuttle' => ['en' => 'Airport shuttle', 'ar' => 'نقل المطار'],
        'room_service' => ['en' => 'Room service', 'ar' => 'خدمة الغرف'],
        'air_conditioning' => ['en' => 'Air conditioning', 'ar' => 'تكييف'],
        'restaurant' => ['en' => 'Restaurant', 'ar' => 'مطعم'],
        'spa' => ['en' => 'Spa', 'ar' => 'سبا'],
        'business_center' => ['en' => 'Business center', 'ar' => 'مركز أعمال'],
    ];

    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('name_i18n');
            $table->string('icon', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('facility_hotel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['facility_id', 'hotel_id']);
        });

        $now = now();
        $facilityIds = [];
        foreach (HotelAmenity::values() as $order => $key) {
            $label = $this->seedLabels[$key] ?? ['en' => $key, 'ar' => $key];

            $facilityIds[$key] = DB::table('facilities')->insertGetId([
                'key' => $key,
                'name_i18n' => json_encode($label),
                'is_active' => true,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Migrate every hotel's existing `amenities` JSON array into the
        // pivot before the column is dropped below.
        DB::table('hotels')->select('id', 'amenities')->get()->each(function ($hotel) use ($facilityIds, $now) {
            $amenities = json_decode((string) $hotel->amenities, true) ?: [];

            $rows = [];
            foreach (array_unique($amenities) as $key) {
                if (isset($facilityIds[$key])) {
                    $rows[] = [
                        'facility_id' => $facilityIds[$key],
                        'hotel_id' => $hotel->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('facility_hotel')->insert($rows);
            }
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('amenities');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->json('amenities')->nullable();
        });

        // Best-effort restore of the legacy column from the pivot.
        DB::table('hotels')->select('id')->get()->each(function ($hotel) {
            $keys = DB::table('facility_hotel')
                ->join('facilities', 'facilities.id', '=', 'facility_hotel.facility_id')
                ->where('facility_hotel.hotel_id', $hotel->id)
                ->pluck('facilities.key')
                ->all();

            DB::table('hotels')->where('id', $hotel->id)->update(['amenities' => json_encode($keys)]);
        });

        Schema::dropIfExists('facility_hotel');
        Schema::dropIfExists('facilities');
    }
};
