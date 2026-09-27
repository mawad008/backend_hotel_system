<?php

namespace Tests\Feature\Room;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function room(): Room
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
    }

    public function test_staff_uploads_a_gallery_image_and_it_is_served_by_url(): void
    {
        $room = $this->room();

        $res = $this->actingAs($this->owner(), 'sanctum')->postJson(
            "/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media",
            ['collection' => 'gallery', 'image' => UploadedFile::fake()->image('room.png', 800, 600)],
        );

        $res->assertCreated()
            ->assertJsonPath('data.collection', 'gallery')
            ->assertJsonStructure(['data' => ['id', 'url', 'sort_order']])
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $media = RoomMedia::firstOrFail();
        Storage::disk('public')->assertExists($media->path);
        $this->assertStringContainsString("rooms/{$room->id}/gallery/", $media->path);
        $this->assertSame(Room::class, $media->mediable_type);
        $this->assertSame($room->id, $media->mediable_id);
    }

    public function test_gallery_appends_and_reorders(): void
    {
        $room = $this->room();
        $owner = $this->owner();

        foreach (['g1.png', 'g2.png', 'g3.png'] as $name) {
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media", [
                'collection' => 'gallery', 'image' => UploadedFile::fake()->image($name),
            ])->assertCreated();
        }

        $ids = $room->galleryMedia()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media/reorder", ['ids' => $reversed])
            ->assertOk()
            ->assertJsonPath('data.0.id', $reversed[0]);

        $this->assertSame($reversed, $room->galleryMedia()->pluck('id')->all());
    }

    public function test_gallery_is_capped_at_the_configured_max(): void
    {
        config(['room_media.collections.gallery.max' => 2]);
        $room = $this->room();
        $owner = $this->owner();

        RoomMedia::factory()->forRoom($room)->count(2)->create();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media", [
            'collection' => 'gallery', 'image' => UploadedFile::fake()->image('over.png'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_rejects_a_non_image_and_an_oversized_file(): void
    {
        $room = $this->room();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media", [
            'collection' => 'gallery', 'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        $tooBig = (int) config('room_media.max_file_kb') + 1024;
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media", [
            'collection' => 'gallery', 'image' => UploadedFile::fake()->image('huge.jpg')->size($tooBig),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_deleting_media_removes_the_row_and_the_file(): void
    {
        $room = $this->room();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media", [
            'collection' => 'gallery', 'image' => UploadedFile::fake()->image('c.png'),
        ])->assertCreated();
        $media = RoomMedia::firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media/{$media->id}")
            ->assertOk();

        $this->assertDatabaseMissing('room_media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing($media->path);
    }

    public function test_media_of_another_room_is_a_404(): void
    {
        $a = $this->room();
        $b = $this->room();
        $mediaB = RoomMedia::factory()->forRoom($b)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/hotels/{$a->hotel_id}/rooms/{$a->id}/media/{$mediaB->id}")
            ->assertStatus(404);
    }

    public function test_a_room_types_media_is_not_reachable_through_the_room_endpoint(): void
    {
        $room = $this->room();
        $roomTypeMedia = RoomMedia::factory()->forRoomType($room->roomType)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media/{$roomTypeMedia->id}")
            ->assertStatus(404);
    }

    public function test_a_role_without_inventory_manage_is_forbidden(): void
    {
        $room = $this->room();
        $reception = User::factory()->reception()->create();

        $this->actingAs($reception, 'sanctum')->postJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media", [
            'collection' => 'gallery', 'image' => UploadedFile::fake()->image('x.png'),
        ])->assertStatus(403);
    }

    public function test_the_guest_app_never_reaches_the_media_endpoint(): void
    {
        $room = $this->room();
        $guest = Guest::factory()->create();

        $this->withToken($guest->createToken('guest-api')->plainTextToken)
            ->postJson("/api/v1/hotels/{$room->hotel_id}/rooms/{$room->id}/media", [
                'collection' => 'gallery', 'image' => UploadedFile::fake()->image('x.png'),
            ])
            ->assertStatus(401);
    }
}
