<?php

namespace Tests\Feature\ProblemReport;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Support\Models\ProblemReport;
use Tests\TestCase;

class ProblemReportManagementTest extends TestCase
{
    private function hotelManagerFor(Hotel $hotel): User
    {
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);

        return $manager;
    }

    public function test_staff_lists_every_status_for_their_hotel(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        ProblemReport::factory()->create(['hotel_id' => $hotel->id]);
        ProblemReport::factory()->inProgress()->create(['hotel_id' => $hotel->id]);
        ProblemReport::factory()->resolved()->create(['hotel_id' => $hotel->id]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/problems")
            ->assertOk();

        $response->assertJsonCount(3, 'data');
        $this->assertNotNull($response->json('data.0.hotel_id'));
    }

    public function test_staff_can_filter_by_status(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        ProblemReport::factory()->create(['hotel_id' => $hotel->id]);
        ProblemReport::factory()->resolved()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotel->id}/problems?status=".ProblemReport::STATUS_RESOLVED)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_reception_can_transition_a_report(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $report = ProblemReport::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($reception, 'sanctum')
            ->patchJson("/api/v1/problems/{$report->id}/status", ['status' => ProblemReport::STATUS_IN_PROGRESS])
            ->assertOk()
            ->assertJsonPath('data.status', ProblemReport::STATUS_IN_PROGRESS);

        $this->assertSame(ProblemReport::STATUS_IN_PROGRESS, $report->fresh()->status);
    }

    public function test_transitioning_to_resolved_records_who_resolved_it(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $report = ProblemReport::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/problems/{$report->id}/status", ['status' => ProblemReport::STATUS_RESOLVED])
            ->assertOk()
            ->assertJsonPath('data.status', ProblemReport::STATUS_RESOLVED);

        $this->assertSame($manager->id, $report->fresh()->resolved_by_user_id);
        $this->assertNotNull($report->fresh()->resolved_at);
    }

    public function test_cannot_transition_backwards_from_resolved(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotel);
        $report = ProblemReport::factory()->resolved()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/problems/{$report->id}/status", ['status' => ProblemReport::STATUS_OPEN])
            ->assertStatus(422);
    }

    public function test_staff_from_another_hotel_cannot_view_or_manage(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = $this->hotelManagerFor($hotelA);
        $report = ProblemReport::factory()->create(['hotel_id' => $hotelB->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/hotels/{$hotelB->id}/problems")
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/problems/{$report->id}/status", ['status' => ProblemReport::STATUS_IN_PROGRESS])
            ->assertStatus(403);
    }

    public function test_guest_cannot_access_staff_problem_report_endpoints(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $report = ProblemReport::factory()->create(['hotel_id' => $hotel->id]);

        $this->getJson("/api/v1/hotels/{$hotel->id}/problems")->assertStatus(401);
        $this->patchJson("/api/v1/problems/{$report->id}/status", ['status' => ProblemReport::STATUS_IN_PROGRESS])
            ->assertStatus(401);
    }
}
