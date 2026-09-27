<?php

namespace Database\Factories;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HotelMedia>
 */
class HotelMediaFactory extends Factory
{
    protected $model = HotelMedia::class;

    public function definition(): array
    {
        $collection = fake()->randomElement([
            HotelMedia::COLLECTION_LOGO,
            HotelMedia::COLLECTION_COVER,
            HotelMedia::COLLECTION_GALLERY,
        ]);

        return [
            'hotel_id' => Hotel::factory(),
            'collection' => $collection,
            'disk' => config('hotel_media.disk', 'public'),
            'path' => "hotels/demo/{$collection}/".Str::random(40).'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(20_000, 900_000),
            'sort_order' => 0,
        ];
    }

    public function collection(string $collection): static
    {
        return $this->state(fn () => ['collection' => $collection]);
    }
}
