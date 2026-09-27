<?php

namespace Tests\Unit\Models;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\HotelCancellationPolicy;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class HotelCancellationPolicyTest extends TestCase
{
    public function test_factory_creates_a_valid_cancellation_policy(): void
    {
        $policy = HotelCancellationPolicy::factory()->create();

        $this->assertDatabaseHas('hotel_cancellation_policies', ['id' => $policy->id]);
    }

    public function test_hotel_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        HotelCancellationPolicy::factory()->create(['hotel_id' => null]);
    }

    public function test_notice_period_hours_persists_as_an_integer(): void
    {
        $policy = HotelCancellationPolicy::factory()->create(['notice_period_hours' => 48]);

        $this->assertSame(48, $policy->fresh()->notice_period_hours);
    }

    public function test_penalty_type_only_accepts_the_three_approved_values(): void
    {
        foreach (['percentage', 'flat', 'none'] as $type) {
            $policy = HotelCancellationPolicy::factory()->create(['penalty_type' => $type]);
            $this->assertSame($type, $policy->fresh()->penalty_type);
        }
    }

    public function test_penalty_type_rejects_an_unapproved_value_at_the_database_level(): void
    {
        $this->expectException(QueryException::class);

        HotelCancellationPolicy::factory()->create(['penalty_type' => 'double_charge']);
    }

    public function test_penalty_value_persists_as_a_decimal(): void
    {
        $policy = HotelCancellationPolicy::factory()->create(['penalty_value' => 25.5]);

        $this->assertSame('25.50', $policy->fresh()->penalty_value);
    }

    public function test_belongs_to_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $policy = HotelCancellationPolicy::factory()->create(['hotel_id' => $hotel->id]);

        $this->assertTrue($policy->hotel->is($hotel));
    }

    public function test_hotel_has_many_cancellation_policies(): void
    {
        $hotel = Hotel::factory()->create();
        HotelCancellationPolicy::factory()->count(2)->create(['hotel_id' => $hotel->id]);

        $this->assertCount(2, $hotel->cancellationPolicies);
    }

    /**
     * Phase 0 §12 does not require a hotel to hold at most one policy row,
     * and explicitly anticipates future tiered policies — this proves the
     * schema does not silently invent a one-per-hotel restriction.
     */
    public function test_a_hotel_may_have_more_than_one_cancellation_policy(): void
    {
        $hotel = Hotel::factory()->create();

        HotelCancellationPolicy::factory()->create(['hotel_id' => $hotel->id, 'notice_period_hours' => 24]);
        $second = HotelCancellationPolicy::factory()->create(['hotel_id' => $hotel->id, 'notice_period_hours' => 72]);

        $this->assertDatabaseHas('hotel_cancellation_policies', ['id' => $second->id, 'hotel_id' => $hotel->id]);
        $this->assertSame(2, HotelCancellationPolicy::where('hotel_id', $hotel->id)->count());
    }

    public function test_deleting_a_hotel_cascades_to_its_cancellation_policies(): void
    {
        $hotel = Hotel::factory()->create();
        $policy = HotelCancellationPolicy::factory()->create(['hotel_id' => $hotel->id]);

        $hotel->delete();

        $this->assertDatabaseMissing('hotel_cancellation_policies', ['id' => $policy->id]);
    }
}
