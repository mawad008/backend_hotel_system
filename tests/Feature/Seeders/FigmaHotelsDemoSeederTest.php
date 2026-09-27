<?php

namespace Tests\Feature\Seeders;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelMedia;
use App\Domain\Inventory\Models\RoomMedia;
use Database\Seeders\FigmaHotelsDemoSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FigmaHotelsDemoSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_seeds_the_three_figma_hotels_with_content_rooms_and_images(): void
    {
        $this->seed(FigmaHotelsDemoSeeder::class);

        $waha = Hotel::query()->where('slug', 'al-waha-hotel')->firstOrFail();

        $this->assertSame(['al-marsa-hotel', 'al-nakheel-hotel', 'al-waha-hotel'],
            Hotel::query()->whereIn('slug', ['al-waha-hotel', 'al-marsa-hotel', 'al-nakheel-hotel'])->orderBy('slug')->pluck('slug')->all());
        $this->assertSame(180, $waha->rooms()->count());
        $this->assertSame(3, $waha->roomTypes()->count());
        $this->assertSame(6, $waha->highlights()->count());
        $this->assertSame(13, $waha->facilities()->count());
        $this->assertSame(1, $waha->media()->where('collection', HotelMedia::COLLECTION_COVER)->count());
        $this->assertSame(4, $waha->media()->where('collection', HotelMedia::COLLECTION_GALLERY)->count());
        $this->assertSame(18, RoomMedia::query()->count());

        foreach (HotelMedia::query()->get() as $media) {
            Storage::disk('public')->assertExists($media->path);
        }

        $this->getJson("/api/v1/guest/hotels/{$waha->id}", ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.name', 'فندق الواحة')
            ->assertJsonPath('data.rooms_count', 180)
            ->assertJsonPath('data.location.note', 'يبعد 10 دقائق عن الكورنيش')
            ->assertJsonPath('data.highlights.0.title', 'مسبح خارجي');
    }

    public function test_it_is_idempotent_and_keeps_existing_edits(): void
    {
        $this->seed(FigmaHotelsDemoSeeder::class);

        $waha = Hotel::query()->where('slug', 'al-waha-hotel')->firstOrFail();
        $waha->update(['tagline_i18n' => ['ar' => 'معدّل', 'en' => 'Edited']]);
        $counts = [Hotel::query()->count(), HotelMedia::query()->count(), RoomMedia::query()->count(), $waha->rooms()->count()];

        $this->seed(FigmaHotelsDemoSeeder::class);

        $this->assertSame($counts, [Hotel::query()->count(), HotelMedia::query()->count(), RoomMedia::query()->count(), $waha->rooms()->count()]);
        $this->assertSame('Edited', $waha->fresh()->tagline_i18n['en']);
    }
}
