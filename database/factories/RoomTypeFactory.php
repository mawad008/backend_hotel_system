<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    /**
     * Bed type by occupancy, bilingual — a real (if coarse) mapping rather
     * than a random pick, so `capacity` and `bed_type` never contradict each
     * other on generated demo data.
     *
     * @var array<int, array{en: string, ar: string}>
     */
    private const BED_TYPES_BY_CAPACITY = [
        1 => ['en' => 'Single bed', 'ar' => 'سرير مفرد'],
        2 => ['en' => 'Double bed', 'ar' => 'سرير مزدوج'],
        3 => ['en' => 'Twin beds', 'ar' => 'سريران منفصلان'],
        4 => ['en' => 'Twin beds', 'ar' => 'سريران منفصلان'],
    ];

    private const BED_TYPE_SUITE = ['en' => 'King bed', 'ar' => 'سرير كينج'];

    public function definition(): array
    {
        $qualifiers = ['Standard', 'Superior', 'Deluxe', 'Executive', 'Premium', 'Classic'];
        $categories = ['Single', 'Double', 'Twin', 'Suite', 'Room'];

        // The qualifier/category pair alone only has 30 combinations and
        // room_types has a UNIQUE(hotel_id, name) constraint, so two
        // random picks can collide when several Room Types are created
        // for the same hotel. Appending a number that Faker guarantees
        // is unique for the life of the test process — not merely
        // unlikely to repeat — makes every generated name unique
        // outright, while staying a realistic, readable room type label
        // (e.g. "Deluxe Double 4821").
        $name = fake()->randomElement($qualifiers).' '.fake()->randomElement($categories).' '.fake()->unique()->numberBetween(1000, 9999);
        $capacity = fake()->numberBetween(1, 6);
        $bedType = $capacity >= 5 ? self::BED_TYPE_SUITE : self::BED_TYPES_BY_CAPACITY[$capacity];

        return [
            'hotel_id' => Hotel::factory(),
            'name' => $name,
            'base_price' => fake()->randomFloat(2, 50, 500),
            'capacity' => $capacity,
            'amenities' => fake()->randomElements(
                ['wifi', 'air_conditioning', 'tv', 'minibar', 'balcony', 'sea_view', 'safe'],
                fake()->numberBetween(2, 5)
            ),
            'description' => fake()->sentence(),
            'is_active' => true,
            'bed_type_i18n' => $bedType,
            // Roughly 16-22 m² per guest, floored to a plausible whole
            // number — never a fixed constant regardless of capacity.
            'area_sqm' => $capacity * fake()->numberBetween(16, 22),
            'breakfast_included' => fake()->boolean(60),
            'refundable' => fake()->boolean(50),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
