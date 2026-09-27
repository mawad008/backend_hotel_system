<?php

namespace Database\Seeders;

use App\Domain\HotelGroup\Enums\HotelAmenity;
use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Location\Models\City;
use Illuminate\Database\Seeder;

/**
 * Phase 1 development/demo dataset for manually exercising the Phase 1
 * API (e.g. via the Postman collection under postman/) against a local
 * `php artisan serve`.
 *
 * DEVELOPMENT/DEMO DATA ONLY:
 * - Refuses to run when APP_ENV=production.
 * - All demo accounts use the reserved `.test` email TLD (RFC 2606) and
 *   the single documented development password below — never real
 *   credentials. See README.md "Phase 1 demo data".
 * - Hotel Group/Hotel records use a `demo-` slug prefix so they are easy
 *   to identify and never collide with real data entered later.
 *
 * Idempotent: every record is looked up by a stable natural key (slug for
 * Hotel Groups/Hotels, email for Users) via firstOrCreate/updateOrCreate,
 * and hotel access is granted with syncWithoutDetaching. Re-running this
 * seeder any number of times never creates duplicates and never deletes
 * or detaches unrelated existing data.
 */
class Phase1DemoSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'Password123!';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Phase1DemoSeeder: skipped — refusing to seed demo data in production.');

            return;
        }

        // Demo users reference roles/permissions seeded here; safe to call
        // repeatedly since RolePermissionSeeder is itself idempotent.
        $this->call(RolePermissionSeeder::class);
        // Country/City reference data the demo hotels below point at.
        $this->call(LocationSeeder::class);

        $group = HotelGroup::query()->firstOrCreate(
            ['slug' => 'demo-hotel-group'],
            [
                'name' => 'Demo Hotel Group',
                'is_active' => true,
            ]
        );

        $cairo = $this->demoHotel($group->id, 'demo-cairo-hotel', 'Cairo Hotel', 'Cairo', 'فندق القاهرة');
        $hurghada = $this->demoHotel($group->id, 'demo-hurghada-hotel', 'Hurghada Hotel', 'Hurghada', 'فندق الغردقة');
        $luxor = $this->demoHotel($group->id, 'demo-luxor-hotel', 'Luxor Hotel', 'Luxor', 'فندق الأقصر');

        // Room types + physical rooms so the guest discovery/availability
        // and booking funnel can be walked end-to-end with demo data.
        foreach ([$cairo, $hurghada, $luxor] as $hotel) {
            $this->demoInventory($hotel);
        }

        $roleIdBySlug = Role::query()->pluck('id', 'slug');

        $owner = $this->demoUser('owner@hotel.test', 'Demo Group Owner', $roleIdBySlug[Role::GROUP_OWNER]);
        $manager = $this->demoUser('manager@hotel.test', 'Demo Hotel Manager', $roleIdBySlug[Role::HOTEL_MANAGER]);
        $receptionCairo = $this->demoUser('reception.cairo@hotel.test', 'Demo Reception (Cairo)', $roleIdBySlug[Role::RECEPTION]);
        $receptionHurghada = $this->demoUser('reception.hurghada@hotel.test', 'Demo Reception (Hurghada)', $roleIdBySlug[Role::RECEPTION]);
        $this->demoUser('guest@hotel.test', 'Demo Guest', $roleIdBySlug[Role::GUEST]);

        // Group Owner needs no explicit hotel_access row — all-hotels
        // access is an explicit policy bypass (see HotelAccessService),
        // not a pivot assignment.
        $manager->hotels()->syncWithoutDetaching([$cairo->id, $hurghada->id]);
        $receptionCairo->hotels()->syncWithoutDetaching([$cairo->id]);
        $receptionHurghada->hotels()->syncWithoutDetaching([$hurghada->id]);

        $this->command?->info('Phase 1 demo data seeded/verified — see README.md "Phase 1 demo data" for accounts.');
        $this->command?->line("  {$owner->email} — Group Owner (all hotels)");
        $this->command?->line("  {$manager->email} — Hotel Manager (Cairo, Hurghada)");
        $this->command?->line("  {$receptionCairo->email} — Reception (Cairo)");
        $this->command?->line("  {$receptionHurghada->email} — Reception (Hurghada)");
        $this->command?->line('  guest@hotel.test — Guest (no staff access)');
        $this->command?->line('  Password for all demo accounts: '.self::DEMO_PASSWORD);
    }

    /**
     * Idempotent demo hotel wired to the normalized Country/City reference
     * data (all demo hotels are Egyptian). Backfills the FK columns on an
     * already-existing row too.
     */
    private function demoHotel(int $groupId, string $slug, string $name, string $cityName, string $nameAr): Hotel
    {
        $city = City::query()->where('name_en', $cityName)->firstOrFail();

        $hotel = Hotel::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'hotel_group_id' => $groupId,
                'name' => $name,
                // Deterministic bilingual demo content — real (non-duplicated)
                // ar/en strings, not fabricated translations of each other.
                'name_i18n' => ['en' => $name, 'ar' => $nameAr],
                'tagline_i18n' => [
                    'en' => 'A calm stay in the heart of '.$cityName,
                    'ar' => 'إقامة هادئة في قلب '.$cityName,
                ],
                'description_i18n' => [
                    'en' => 'Contemporary rooms, attentive service and an easy walk to everything that matters.',
                    'ar' => 'غرف عصرية وخدمة مهتمة وقربٌ سهل من كل ما يهم.',
                ],
                'star_rating' => 4,
                // The pre-per-hotel app-wide default the deposit migration
                // backfilled (2026_09_24_150000); operators edit it on the
                // dashboard hotel form.
                'deposit_percentage' => 20,
                'country_id' => $city->country_id,
                'city_id' => $city->id,
                'country' => 'Egypt',
                'city' => $cityName,
                'timezone' => 'Africa/Cairo',
                'is_active' => true,
            ]
        );

        $hotel->facilities()->sync(
            Facility::query()->whereIn('key', [
                HotelAmenity::FreeWifi->value,
                HotelAmenity::Breakfast->value,
                HotelAmenity::Pool->value,
                HotelAmenity::Parking->value,
                HotelAmenity::AirConditioning->value,
            ])->pluck('id')
        );

        return $hotel;
        // NOTE: no images are seeded — hotel media is real uploaded content
        // managed by staff through the dashboard, never a seeded placeholder.
    }

    /**
     * Idempotent demo room types (Standard / Deluxe) + physical rooms for a
     * hotel. Priced/sized deterministically so availability + the stay total
     * are predictable when walking the booking funnel.
     */
    private function demoInventory(Hotel $hotel): void
    {
        $types = [
            [
                'name' => 'Standard Room',
                'base_price' => '480.00',
                'capacity' => 2,
                'rooms' => 6,
                'bed_type_i18n' => ['en' => 'Double bed', 'ar' => 'سرير مزدوج'],
                'area_sqm' => 28,
                'breakfast_included' => true,
                'refundable' => true,
            ],
            [
                'name' => 'Deluxe Suite',
                'base_price' => '920.00',
                'capacity' => 4,
                'rooms' => 3,
                'bed_type_i18n' => ['en' => 'King bed', 'ar' => 'سرير كينج'],
                'area_sqm' => 45,
                'breakfast_included' => true,
                'refundable' => false,
            ],
        ];

        foreach ($types as $spec) {
            $roomType = RoomType::query()->updateOrCreate(
                ['hotel_id' => $hotel->id, 'name' => $spec['name']],
                [
                    'base_price' => $spec['base_price'],
                    'capacity' => $spec['capacity'],
                    'amenities' => [HotelAmenity::FreeWifi->value, HotelAmenity::AirConditioning->value],
                    'description' => 'Demo room type for exercising the guest booking funnel.',
                    'is_active' => true,
                    'bed_type_i18n' => $spec['bed_type_i18n'],
                    'area_sqm' => $spec['area_sqm'],
                    'breakfast_included' => $spec['breakfast_included'],
                    'refundable' => $spec['refundable'],
                ]
            );

            for ($i = 1; $i <= $spec['rooms']; $i++) {
                $number = ($spec['name'] === 'Deluxe Suite' ? 'S' : 'R').str_pad((string) $i, 2, '0', STR_PAD_LEFT);

                Room::query()->updateOrCreate(
                    ['hotel_id' => $hotel->id, 'room_number' => $number],
                    ['room_type_id' => $roomType->id, 'status' => 'available']
                );
            }
        }
    }

    private function demoUser(string $email, string $name, int $roleId): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role_id' => $roleId,
                'password' => self::DEMO_PASSWORD,
                'is_active' => true,
            ]
        );
    }
}
