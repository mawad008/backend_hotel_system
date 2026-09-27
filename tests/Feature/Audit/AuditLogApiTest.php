<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    public function test_hotel_manager_lists_the_hotel_audit_log(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $actor = User::factory()->groupOwner()->create();
        AuditLog::factory()->create(['hotel_id' => $hotel->id, 'action' => 'payment.hold_requested']);
        // Created last — highest id, so it sorts first under latest('id').
        AuditLog::factory()->create(['hotel_id' => $hotel->id, 'actor_id' => $actor->id, 'action' => 'hotel.updated']);
        // A different hotel's event must never appear.
        AuditLog::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/audit-log")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame($actor->id, $response->json('data.0.actor.id'));
    }

    public function test_action_filter_is_a_prefix_match(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        AuditLog::factory()->create(['hotel_id' => $hotel->id, 'action' => 'payment.hold_requested']);
        AuditLog::factory()->create(['hotel_id' => $hotel->id, 'action' => 'payment.hold_succeeded']);
        AuditLog::factory()->create(['hotel_id' => $hotel->id, 'action' => 'checkout.completed']);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/audit-log?action=payment.")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_date_range_filter(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        AuditLog::factory()->create(['hotel_id' => $hotel->id, 'created_at' => '2026-01-05 10:00:00']);
        AuditLog::factory()->create(['hotel_id' => $hotel->id, 'created_at' => '2026-02-15 10:00:00']);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/audit-log?from=2026-01-01&to=2026-01-31")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_reception_cannot_view_the_audit_log(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/audit-log")
            ->assertStatus(403);
    }

    public function test_staff_from_another_hotel_cannot_view_the_audit_log(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/audit-log")
            ->assertStatus(403);
    }

    public function test_group_owner_lists_the_global_feed_across_hotels(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        AuditLog::factory()->create(['hotel_id' => $hotelA->id]);
        AuditLog::factory()->create(['hotel_id' => $hotelB->id]);
        AuditLog::factory()->create(['hotel_id' => null, 'action' => 'user.created']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/audit-log')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_hotel_manager_cannot_view_the_global_feed(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/audit-log')
            ->assertStatus(403);
    }

    public function test_audit_log_requires_authentication(): void
    {
        $this->getJson('/api/v1/audit-log')->assertStatus(401);
    }
}
