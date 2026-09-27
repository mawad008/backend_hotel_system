<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelMedia;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HotelMediaTest extends TestCase
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

    public function test_staff_uploads_a_logo_and_it_is_served_by_url(): void
    {
        $hotel = Hotel::factory()->create();

        $res = $this->actingAs($this->owner(), 'sanctum')->postJson(
            "/api/v1/hotels/{$hotel->id}/media",
            ['collection' => 'logo', 'image' => UploadedFile::fake()->image('logo.png', 200, 200)],
        );

        $res->assertCreated()
            ->assertJsonPath('data.collection', 'logo')
            ->assertJsonStructure(['data' => ['id', 'url', 'sort_order']])
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $media = HotelMedia::firstOrFail();
        Storage::disk('public')->assertExists($media->path);
        $this->assertStringContainsString("hotels/{$hotel->id}/logo/", $media->path);
    }

    public function test_uploading_a_new_logo_replaces_and_deletes_the_previous_one(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
            'collection' => 'logo', 'image' => UploadedFile::fake()->image('a.png'),
        ])->assertCreated();
        $first = HotelMedia::firstOrFail();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
            'collection' => 'logo', 'image' => UploadedFile::fake()->image('b.png'),
        ])->assertCreated();

        $this->assertDatabaseMissing('hotel_media', ['id' => $first->id]);
        $this->assertSame(1, $hotel->media()->where('collection', 'logo')->count());
        Storage::disk('public')->assertMissing($first->path);
    }

    public function test_gallery_appends_and_reorders(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = $this->owner();

        foreach (['g1.png', 'g2.png', 'g3.png'] as $name) {
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
                'collection' => 'gallery', 'image' => UploadedFile::fake()->image($name),
            ])->assertCreated();
        }

        $ids = $hotel->galleryMedia()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/media/reorder", ['ids' => $reversed])
            ->assertOk()
            ->assertJsonPath('data.0.id', $reversed[0]);

        $this->assertSame($reversed, $hotel->galleryMedia()->pluck('id')->all());
    }

    public function test_gallery_is_capped_at_the_configured_max(): void
    {
        config(['hotel_media.collections.gallery.max' => 2]);
        $hotel = Hotel::factory()->create();
        $owner = $this->owner();

        HotelMedia::factory()->collection('gallery')->count(2)->create(['hotel_id' => $hotel->id]);

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
            'collection' => 'gallery', 'image' => UploadedFile::fake()->image('over.png'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_rejects_a_non_image_and_an_oversized_file(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
            'collection' => 'logo', 'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        $tooBig = (int) config('hotel_media.max_file_kb') + 1024;
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
            'collection' => 'cover', 'image' => UploadedFile::fake()->image('huge.jpg')->size($tooBig),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_deleting_media_removes_the_row_and_the_file(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
            'collection' => 'cover', 'image' => UploadedFile::fake()->image('c.png'),
        ])->assertCreated();
        $media = HotelMedia::firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/hotels/{$hotel->id}/media/{$media->id}")
            ->assertOk();

        $this->assertDatabaseMissing('hotel_media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing($media->path);
    }

    public function test_media_of_another_hotel_is_a_404(): void
    {
        $a = Hotel::factory()->create();
        $b = Hotel::factory()->create();
        $mediaB = HotelMedia::factory()->collection('logo')->create(['hotel_id' => $b->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->deleteJson("/api/v1/hotels/{$a->id}/media/{$mediaB->id}")
            ->assertStatus(404);
    }

    public function test_a_role_without_hotels_manage_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create();
        $reception = User::factory()->reception()->create();

        $this->actingAs($reception, 'sanctum')->postJson("/api/v1/hotels/{$hotel->id}/media", [
            'collection' => 'logo', 'image' => UploadedFile::fake()->image('x.png'),
        ])->assertStatus(403);
    }

    public function test_the_guest_app_never_reaches_the_media_endpoint(): void
    {
        $hotel = Hotel::factory()->create();
        $guest = Guest::factory()->create();

        $this->withToken($guest->createToken('guest-api')->plainTextToken)
            ->postJson("/api/v1/hotels/{$hotel->id}/media", [
                'collection' => 'logo', 'image' => UploadedFile::fake()->image('x.png'),
            ])
            ->assertStatus(401);
    }
}
