<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RoomMedia>
 */
class RoomMediaFactory extends Factory
{
    protected $model = RoomMedia::class;

    public function definition(): array
    {
        $collection = RoomMedia::COLLECTION_GALLERY;

        return [
            'mediable_id' => Room::factory(),
            'mediable_type' => Room::class,
            'collection' => $collection,
            'disk' => config('room_media.disk', 'public'),
            'path' => "rooms/demo/{$collection}/".Str::random(40).'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(20_000, 900_000),
            'sort_order' => 0,
        ];
    }

    public function forRoom(Room $room): static
    {
        return $this->state(fn () => ['mediable_id' => $room->id, 'mediable_type' => Room::class]);
    }

    public function forRoomType(RoomType $roomType): static
    {
        return $this->state(fn () => [
            'mediable_id' => $roomType->id,
            'mediable_type' => RoomType::class,
        ]);
    }
}
