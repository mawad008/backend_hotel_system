<?php

namespace Database\Seeders;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\HotelGroup\Models\HotelMedia;
use App\Domain\HotelGroup\Support\HotelMediaStore;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Support\RoomMediaStore;
use App\Domain\Location\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The three hotels from the Guest App Figma file (`mobile/Design/
 * hotel_guest_app.fig` — HOME_Default, SEARCH_Results_Default,
 * HOTEL_Detail_Premium, ROOM_Detail_Premium, Available Room Card) with their
 * full guest-facing content, room types, physical rooms and the Figma's own
 * images (copied to `database/seeders/assets/figma-hotels/`).
 *
 * The Figma only details one hotel page (فندق الواحة — جدة); its detail
 * content (check-in/out, "why choose" cards, facilities, room types) is the
 * template reused for the other two, whose name / city / cover come from the
 * home + search screens. Prices, star ratings, room counts for المرسى /
 * النخيل, coordinates and English copy are not in the Figma and are
 * deterministic demo values.
 *
 * DEVELOPMENT/DEMO DATA ONLY — never runs in production. Idempotent and
 * non-destructive: a hotel that already exists (by slug) is left as is, and
 * its highlights / nearby places / images / rooms are only added when it has
 * none, so edits made in the dashboard survive a re-seed.
 */
class FigmaHotelsDemoSeeder extends Seeder
{
    private const ASSETS = __DIR__.'/assets/figma-hotels';

    /** Facilities shown on the Figma hotel page that the base catalog lacks. */
    private const EXTRA_FACILITIES = [
        'reception_24h' => ['en' => '24-hour reception', 'ar' => 'استقبال 24 ساعة'],
        'daily_housekeeping' => ['en' => 'Daily housekeeping', 'ar' => 'تنظيف يومي'],
        'elevator' => ['en' => 'Elevator', 'ar' => 'مصعد'],
        'in_room_safe' => ['en' => 'In-room safe', 'ar' => 'خزنة داخل الغرفة'],
    ];

    /** HOTEL_Detail_Premium › facilities-section + why-section. */
    private const FACILITY_KEYS = [
        'free_wifi', 'pool', 'restaurant', 'parking', 'gym', 'room_service',
        'air_conditioning', 'business_center', 'airport_shuttle',
        'reception_24h', 'daily_housekeeping', 'elevator', 'in_room_safe',
    ];

    /** HOTEL_Detail_Premium › why-section › feature-card × 6. */
    private const HIGHLIGHTS = [
        ['waves', 'مسبح خارجي', 'Outdoor pool', 'إطلالة ومرافق متكاملة', 'Views and complete facilities'],
        ['utensils', 'مطعم فاخر', 'Fine dining restaurant', 'أطباق عالمية طوال اليوم', 'International dishes all day'],
        ['wifi', 'واي فاي مجاني', 'Free Wi-Fi', 'إنترنت عالي السرعة', 'High-speed internet'],
        ['car-front', 'مواقف سيارات', 'Parking', 'مواقف مجانية للنزلاء', 'Free parking for guests'],
        ['dumbbell', 'نادي رياضي', 'Fitness club', 'معدات حديثة', 'Modern equipment'],
        ['utensils-crossed', 'خدمة الغرف', 'Room service', 'متاحة 24 ساعة', 'Available 24 hours'],
    ];

    /**
     * ROOM_Detail_Premium, HOTEL_Detail_Premium › room-card and the
     * Available Room Card component. `prices` are per hotel slug.
     */
    private const ROOM_TYPES = [
        [
            'name' => 'غرفة ديلوكس',
            'description' => 'غرفة واسعة بإطلالة على المدينة وشرفة خاصة.',
            'capacity' => 2,
            'bed' => ['en' => 'King bed', 'ar' => 'سرير كينج'],
            'view' => ['en' => 'City view', 'ar' => 'إطلالة على المدينة'],
            'area' => null,
            'breakfast' => true,
            'refundable' => true,
            'amenities' => ['free_wifi', 'breakfast'],
            'images' => ['room-deluxe-1.jpg', 'room-deluxe-2.jpg'],
            'share' => 0.45,
            'prices' => ['al-waha-hotel' => '450.00', 'al-marsa-hotel' => '380.00', 'al-nakheel-hotel' => '480.00'],
        ],
        [
            'name' => 'غرفة مزدوجة ديلوكس',
            'description' => 'غرفة واسعة بتصميم عصري توفر إقامة مريحة مع جميع التجهيزات الأساسية، مناسبة للأزواج ورجال الأعمال.',
            'capacity' => 2,
            'bed' => ['en' => 'Large double bed', 'ar' => 'سرير مزدوج كبير'],
            'view' => ['en' => 'City view', 'ar' => 'إطلالة على المدينة'],
            'area' => 32,
            'breakfast' => true,
            'refundable' => true,
            'amenities' => ['free_wifi', 'breakfast', 'pool', 'air_conditioning', 'room_service'],
            'images' => ['room-deluxe-double-1.jpg', 'room-deluxe-double-2.jpg', 'room-deluxe-double-3.jpg'],
            'share' => 0.33,
            'prices' => ['al-waha-hotel' => '520.00', 'al-marsa-hotel' => '440.00', 'al-nakheel-hotel' => '560.00'],
        ],
        [
            'name' => 'غرفة ديلوكس كينج',
            'description' => 'غرفة ديلوكس بسرير كينج وإطلالة على المدينة، بمساحة 40 م² تتسع لشخصين.',
            'capacity' => 2,
            'bed' => ['en' => 'King bed', 'ar' => 'سرير كينج'],
            'view' => ['en' => 'City view', 'ar' => 'إطلالة المدينة'],
            'area' => 40,
            'breakfast' => false,
            'refundable' => true,
            'amenities' => ['free_wifi', 'air_conditioning'],
            'images' => ['room-deluxe-king-1.jpg'],
            'share' => null, // the remainder
            'prices' => ['al-waha-hotel' => '610.00', 'al-marsa-hotel' => '520.00', 'al-nakheel-hotel' => '650.00'],
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('FigmaHotelsDemoSeeder: skipped — refusing to seed demo data in production.');

            return;
        }

        $this->call(LocationSeeder::class);

        $group = HotelGroup::query()->firstOrCreate(
            ['slug' => 'demo-hotel-group'],
            ['name' => 'Demo Hotel Group', 'is_active' => true],
        );

        $facilityIds = $this->facilityIds();

        foreach ($this->hotels() as $spec) {
            DB::transaction(fn () => $this->seedHotel($group, $spec, $facilityIds));
        }

        $this->command?->info('Figma demo hotels seeded/verified: فندق الواحة, فندق المرسى, فندق النخيل.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hotels(): array
    {
        $shared = [
            'star_rating' => 5,
            'check_in_time' => '15:00',
            'check_out_time' => '12:00',
            'suitable_for_i18n' => ['ar' => 'العائلات / رجال الأعمال', 'en' => 'Families / Business travellers'],
            'tagline_i18n' => [
                'ar' => 'تجربة إقامة فاخرة ومتوازنة بين الراحة والخدمة المميزة',
                'en' => 'A luxurious stay balancing comfort and distinguished service',
            ],
        ];

        return [
            $shared + [
                'slug' => 'al-waha-hotel',
                'name' => ['en' => 'Al Waha Hotel', 'ar' => 'فندق الواحة'],
                'city' => 'Jeddah',
                'description_i18n' => [
                    'ar' => 'فندق فاخر في جدة على بعد دقائق من الكورنيش، يجمع بين غرف عصرية ومسبح خارجي ومطعم يقدّم أطباقًا عالمية طوال اليوم، مع خدمات مصممة لراحة النزلاء على مدار الساعة.',
                    'en' => 'A luxury hotel in Jeddah minutes from the Corniche, pairing modern rooms with an outdoor pool and an all-day international restaurant, with services designed for guests\' comfort around the clock.',
                ],
                'location_note_i18n' => ['ar' => 'يبعد 10 دقائق عن الكورنيش', 'en' => '10 minutes from the Corniche'],
                'latitude' => 21.5600,
                'longitude' => 39.1500,
                'nearby' => [
                    ['plane', 'airport', 'مطار جدة', 'Jeddah Airport', 25],
                    ['map-pin', 'landmark', 'وسط المدينة', 'City centre', 15],
                ],
                'rooms' => 180,
                'cover' => 'al-waha-cover.jpg',
                'gallery' => ['al-waha-gallery-1.jpg', 'al-waha-gallery-2.jpg', 'al-waha-gallery-3.jpg', 'al-waha-gallery-4.jpg'],
            ],
            ['star_rating' => 4] + $shared + [
                'slug' => 'al-marsa-hotel',
                'name' => ['en' => 'Al Marsa Hotel', 'ar' => 'فندق المرسى'],
                'city' => 'Dammam',
                'description_i18n' => [
                    'ar' => 'منتجع هادئ على ساحل الدمام بين النخيل والحدائق، بمسبح خارجي وغرف مريحة وخدمات تناسب العائلات ورحلات العمل.',
                    'en' => 'A calm resort on the Dammam coast among palms and gardens, with an outdoor pool, comfortable rooms and services suited to families and business trips.',
                ],
                'location_note_i18n' => ['ar' => 'يبعد 5 دقائق عن كورنيش الدمام', 'en' => '5 minutes from the Dammam Corniche'],
                'latitude' => 26.4430,
                'longitude' => 50.1150,
                'nearby' => [
                    ['plane', 'airport', 'مطار الملك فهد الدولي', 'King Fahd International Airport', 35],
                    ['beach', 'beach', 'كورنيش الدمام', 'Dammam Corniche', 5],
                ],
                'rooms' => 120,
                'cover' => 'al-marsa-cover.jpg',
                'gallery' => ['al-marsa-gallery-1.jpg'],
            ],
            $shared + [
                'slug' => 'al-nakheel-hotel',
                'name' => ['en' => 'Al Nakheel Hotel', 'ar' => 'فندق النخيل'],
                'city' => 'Riyadh',
                'description_i18n' => [
                    'ar' => 'فندق عصري في قلب الرياض قريب من أبرز معالمها التجارية، بغرف أنيقة ونادٍ رياضي وقاعة اجتماعات لرجال الأعمال.',
                    'en' => 'A modern hotel in the heart of Riyadh close to its main business landmarks, with elegant rooms, a fitness club and a meeting hall for business travellers.',
                ],
                'location_note_i18n' => ['ar' => 'يبعد 5 دقائق عن برج المملكة', 'en' => '5 minutes from Kingdom Centre'],
                'latitude' => 24.6995,
                'longitude' => 46.6850,
                'nearby' => [
                    ['plane', 'airport', 'مطار الملك خالد الدولي', 'King Khalid International Airport', 30],
                    ['landmark', 'landmark', 'برج المملكة', 'Kingdom Centre', 5],
                ],
                'rooms' => 150,
                'cover' => 'al-nakheel-cover.jpg',
                'gallery' => ['al-nakheel-gallery-1.png'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<int>  $facilityIds
     */
    private function seedHotel(HotelGroup $group, array $spec, array $facilityIds): void
    {
        $city = City::query()->where('name_en', $spec['city'])->firstOrFail();

        $hotel = Hotel::query()->firstOrCreate(
            ['slug' => $spec['slug']],
            [
                'hotel_group_id' => $group->id,
                'name' => $spec['name']['en'],
                'name_i18n' => $spec['name'],
                'tagline_i18n' => $spec['tagline_i18n'],
                'description_i18n' => $spec['description_i18n'],
                'star_rating' => $spec['star_rating'],
                // The pre-per-hotel app-wide default the deposit migration
                // backfilled (2026_09_24_150000); operators edit it on the
                // dashboard hotel form.
                'deposit_percentage' => 20,
                'country_id' => $city->country_id,
                'city_id' => $city->id,
                'country' => 'Saudi Arabia',
                'city' => $spec['city'],
                'timezone' => 'Asia/Riyadh',
                'is_active' => true,
                'seo_indexable' => true,
                'check_in_time' => $spec['check_in_time'],
                'check_out_time' => $spec['check_out_time'],
                'suitable_for_i18n' => $spec['suitable_for_i18n'],
                'location_note_i18n' => $spec['location_note_i18n'],
                'latitude' => $spec['latitude'],
                'longitude' => $spec['longitude'],
            ],
        );

        if (! $hotel->facilities()->exists()) {
            $hotel->facilities()->sync($facilityIds);
        }

        if (! $hotel->highlights()->exists()) {
            foreach (self::HIGHLIGHTS as $order => [$icon, $titleAr, $titleEn, $subAr, $subEn]) {
                $hotel->highlights()->create([
                    'icon' => $icon,
                    'title_i18n' => ['ar' => $titleAr, 'en' => $titleEn],
                    'subtitle_i18n' => ['ar' => $subAr, 'en' => $subEn],
                    'is_active' => true,
                    'sort_order' => $order,
                ]);
            }
        }

        if (! $hotel->nearbyPlaces()->exists()) {
            foreach ($spec['nearby'] as $order => [$icon, $category, $nameAr, $nameEn, $minutes]) {
                $hotel->nearbyPlaces()->create([
                    'icon' => $icon,
                    'category' => $category,
                    'name_i18n' => ['ar' => $nameAr, 'en' => $nameEn],
                    'travel_minutes' => $minutes,
                    'is_active' => true,
                    'sort_order' => $order,
                ]);
            }
        }

        if (! $hotel->media()->exists()) {
            $this->attachHotelImage($hotel, HotelMedia::COLLECTION_COVER, $spec['cover'], 0);
            foreach ($spec['gallery'] as $order => $file) {
                $this->attachHotelImage($hotel, HotelMedia::COLLECTION_GALLERY, $file, $order);
            }
        }

        $this->seedInventory($hotel, $spec['slug'], $spec['rooms']);
    }

    private function seedInventory(Hotel $hotel, string $slug, int $totalRooms): void
    {
        if ($hotel->roomTypes()->exists()) {
            return;
        }

        $number = 0;
        $remaining = $totalRooms;

        foreach (self::ROOM_TYPES as $spec) {
            $roomType = RoomType::query()->create([
                'hotel_id' => $hotel->id,
                'name' => $spec['name'],
                'description' => $spec['description'],
                'base_price' => $spec['prices'][$slug],
                'capacity' => $spec['capacity'],
                'amenities' => $spec['amenities'],
                'bed_type_i18n' => $spec['bed'],
                'view_i18n' => $spec['view'],
                'area_sqm' => $spec['area'],
                'breakfast_included' => $spec['breakfast'],
                'refundable' => $spec['refundable'],
                'is_active' => true,
            ]);

            foreach ($spec['images'] as $order => $file) {
                $this->attachRoomTypeImage($roomType, $file, $order);
            }

            $count = $spec['share'] === null ? $remaining : (int) round($totalRooms * $spec['share']);
            $remaining -= $count;

            // Floor-based numbers (101…120, 201…), 20 rooms per floor — the
            // Figma's own examples are rooms 112 and 412.
            for ($i = 0; $i < $count; $i++, $number++) {
                Room::query()->create([
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'room_number' => (string) ((intdiv($number, 20) + 1) * 100 + $number % 20 + 1),
                    'status' => 'available',
                ]);
            }
        }
    }

    /** @return list<int> */
    private function facilityIds(): array
    {
        $sort = (int) Facility::query()->max('sort_order');

        foreach (self::EXTRA_FACILITIES as $key => $name) {
            Facility::query()->firstOrCreate(
                ['key' => $key],
                ['name_i18n' => $name, 'is_active' => true, 'sort_order' => ++$sort],
            );
        }

        return Facility::query()->whereIn('key', self::FACILITY_KEYS)->pluck('id')->all();
    }

    private function attachHotelImage(Hotel $hotel, string $collection, string $file, int $order): void
    {
        $store = app(HotelMediaStore::class);
        $upload = $this->upload($file);

        $hotel->media()->create([
            'collection' => $collection,
            'disk' => $store->disk(),
            'path' => $store->store($hotel->id, $collection, $upload),
            'original_filename' => $file,
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
            'sort_order' => $order,
        ]);
    }

    private function attachRoomTypeImage(RoomType $roomType, string $file, int $order): void
    {
        $store = app(RoomMediaStore::class);
        $upload = $this->upload($file);

        $roomType->media()->create([
            'collection' => RoomMedia::COLLECTION_GALLERY,
            'disk' => $store->disk(),
            'path' => $store->store($roomType, RoomMedia::COLLECTION_GALLERY, $upload),
            'original_filename' => $file,
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
            'sort_order' => $order,
        ]);
    }

    private function upload(string $file): UploadedFile
    {
        $path = self::ASSETS.'/'.$file;

        return new UploadedFile($path, $file, mime_content_type($path) ?: null, null, true);
    }
}
