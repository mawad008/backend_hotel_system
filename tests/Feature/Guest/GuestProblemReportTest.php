<?php

namespace Tests\Feature\Guest;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Support\Models\ProblemReport;
use Tests\TestCase;

class GuestProblemReportTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    public function test_guest_submits_a_problem_report(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);

        $response = $this->postJson("/api/v1/guest/reservations/{$reservation->id}/problems", [
            'category' => ProblemReport::CATEGORY_AC_HEATING,
            'urgency' => ProblemReport::URGENCY_IMPORTANT,
            'notes' => 'AC has not been cooling since yesterday.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category', ProblemReport::CATEGORY_AC_HEATING)
            ->assertJsonPath('data.urgency', ProblemReport::URGENCY_IMPORTANT)
            ->assertJsonPath('data.status', ProblemReport::STATUS_OPEN)
            ->assertJsonMissingPath('data.hotel_id')
            ->assertJsonMissingPath('data.guest_id');

        $this->assertDatabaseHas('problem_reports', [
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'hotel_id' => $reservation->hotel_id,
            'category' => ProblemReport::CATEGORY_AC_HEATING,
        ]);
    }

    public function test_submission_requires_a_valid_category_and_urgency(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/problems", [
            'category' => 'not_a_real_category',
            'urgency' => ProblemReport::URGENCY_NORMAL,
        ])->assertStatus(422);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/problems", [
            'category' => ProblemReport::CATEGORY_NOISE_DISTURBANCE,
        ])->assertStatus(422);
    }

    public function test_submission_is_ownership_scoped(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();

        $this->postJson("/api/v1/guest/reservations/{$other->id}/problems", [
            'category' => ProblemReport::CATEGORY_NOISE_DISTURBANCE,
            'urgency' => ProblemReport::URGENCY_NORMAL,
        ])->assertStatus(404);
    }

    public function test_guest_lists_and_shows_their_own_reports(): void
    {
        $guest = $this->actingGuest();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);
        $report = ProblemReport::factory()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'hotel_id' => $reservation->hotel_id,
        ]);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/problems")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}/problems/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $report->id);
    }

    public function test_reading_is_ownership_scoped(): void
    {
        $this->actingGuest();
        $other = Reservation::factory()->create();
        $report = ProblemReport::factory()->create([
            'reservation_id' => $other->id,
            'guest_id' => $other->guest_id,
            'hotel_id' => $other->hotel_id,
        ]);

        $this->getJson("/api/v1/guest/reservations/{$other->id}/problems")->assertStatus(404);
        $this->getJson("/api/v1/guest/reservations/{$other->id}/problems/{$report->id}")->assertStatus(404);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $reservation = Reservation::factory()->create();

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/problems", [
            'category' => ProblemReport::CATEGORY_NOISE_DISTURBANCE,
            'urgency' => ProblemReport::URGENCY_NORMAL,
        ])->assertStatus(401);
    }
}
