<?php

namespace Tests\Feature\RoomType;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomTypeMediaTest extends TestCase
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

    private function roomType(): RoomType
    {
        $hotel = Hotel::factory()->create();

        return RoomType::factory()->create(['hotel_id' => $hotel->id]);
    }

    public function test_staff_uploads_a_gallery_image_and_it_is_served_by_url(): void
    {
        $roomType = $this->roomType();

        $res = $this->actingAs($this->owner(), 'sanctum')->postJson(
            "/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media",
            ['collection' => 'gallery', 'image' => UploadedFile::fake()->image('type.png', 800, 600)],
        );

        $res->assertCreated()
            ->assertJsonPath('data.collection', 'gallery')
            ->assertJsonStructure(['data' => ['id', 'url', 'sort_order']])
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $media = RoomMedia::firstOrFail();
        Storage::disk('public')->assertExists($media->path);
        $this->assertStringContainsString("room-types/{$roomType->id}/gallery/", $media->path);
        $this->assertSame(RoomType::class, $media->mediable_type);
        $this->assertSame($roomType->id, $media->mediable_id);
    }

    public function test_gallery_appends_and_reorders(): void
    {
        $roomType = $this->roomType();
        $owner = $this->owner();

        foreach (['g1.png', 'g2.png', 'g3.png'] as $name) {
            $this->actingAs($owner, 'sanctum')->postJson(
                "/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media",
                ['collection' => 'gallery', 'image' => UploadedFile::fake()->image($name)],
            )->assertCreated();
        }

        $ids = $roomType->galleryMedia()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media/reorder", ['ids' => $reversed])
            ->assertOk()
            ->assertJsonPath('data.0.id', $reversed[0]);

        $this->assertSame($reversed, $roomType->galleryMedia()->pluck('id')->all());
    }

    public function test_gallery_is_capped_at_the_configured_max(): void
    {
        config(['room_media.collections.gallery.max' => 2]);
        $roomType = $this->roomType();
        $owner = $this->owner();

        RoomMedia::factory()->forRoomType($roomType)->count(2)->create();

        $this->actingAs($owner, 'sanctum')->postJson(
            "/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media",
            ['collection' => 'gallery', 'image' => UploadedFile::fake()->image('over.png')],
        )->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_deleting_media_removes_the_row_and_the_file(): void
    {
        $roomType = $this->roomType();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson(
            "/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media",
            ['collection' => 'gallery', 'image' => UploadedFile::fake()->image('c.png')],
        )->assertCreated();
        $media = RoomMedia::firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media/{$media->id}")
            ->assertOk();

        $this->assertDatabaseMissing('room_media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing($media->path);
    }

    public function test_media_of_another_room_type_is_a_404(): void
    {
        $a = $this->roomType();
        $b = $this->roomType();
        $mediaB = RoomMedia::factory()->forRoomType($b)->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/hotels/{$a->hotel_id}/room-types/{$a->id}/media/{$mediaB->id}")
            ->assertStatus(404);
    }

    public function test_a_role_without_inventory_manage_is_forbidden(): void
    {
        $roomType = $this->roomType();
        $reception = User::factory()->reception()->create();

        $this->actingAs($reception, 'sanctum')->postJson(
            "/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media",
            ['collection' => 'gallery', 'image' => UploadedFile::fake()->image('x.png')],
        )->assertStatus(403);
    }

    public function test_the_guest_app_never_reaches_the_media_endpoint(): void
    {
        $roomType = $this->roomType();
        $guest = Guest::factory()->create();

        $this->withToken($guest->createToken('guest-api')->plainTextToken)
            ->postJson(
                "/api/v1/hotels/{$roomType->hotel_id}/room-types/{$roomType->id}/media",
                ['collection' => 'gallery', 'image' => UploadedFile::fake()->image('x.png')],
            )
            ->assertStatus(401);
    }
}
