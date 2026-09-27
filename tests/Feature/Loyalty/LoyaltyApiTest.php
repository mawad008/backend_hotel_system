<?php

namespace Tests\Feature\Loyalty;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoyaltyApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function reservation(
        string $status = Reservation::STATUS_INVOICED,
        string $price = '200.00',
        bool $activeRule = true,
        ?HotelGroup $group = null,
    ): Reservation {
        $group ??= HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        if ($activeRule) {
            LoyaltyRule::factory()->active()->create(['hotel_group_id' => $group->id]);
        }

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => $status, 'price_snapshot' => $price,
        ]);
    }

    private function hit(User $actor, string $method, Reservation $reservation, string $suffix = '', array $body = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->json($method, "/api/v1/reservations/{$reservation->id}/loyalty{$suffix}", $body);
    }

    // ── Auth ────────────────────────────────────────────────────────

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson("/api/v1/reservations/{$this->reservation()->id}/loyalty")->assertStatus(401);
    }

    public function test_guest_role_gets_a_scope_404(): void
    {
        $this->hit(User::factory()->guest()->create(), 'GET', $this->reservation())->assertStatus(404);
    }

    public function test_manager_in_an_unassigned_hotel_gets_a_404(): void
    {
        $assigned = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $this->hit($manager, 'GET', $this->reservation())->assertStatus(404);
    }

    // ── Read ────────────────────────────────────────────────────────

    public function test_show_returns_the_account_created_on_first_access(): void
    {
        $reservation = $this->reservation();

        $this->hit($this->owner(), 'GET', $reservation)
            ->assertOk()
            ->assertJsonPath('data.guest_id', $reservation->guest_id)
            ->assertJsonPath('data.points_balance', 0)
            ->assertJsonStructure(['data' => ['id', 'guest_id', 'points_balance', 'is_active']]);
    }

    public function test_transactions_lists_the_ledger_newest_first(): void
    {
        $reservation = $this->reservation();
        $this->hit($this->owner(), 'POST', $reservation, '/earn')->assertStatus(201);

        $this->hit($this->owner(), 'GET', $reservation->fresh(), '/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'earn')
            ->assertJsonPath('data.0.points', 200);
    }

    // ── Earn ────────────────────────────────────────────────────────

    public function test_earn_accrues_points_for_a_completed_booking(): void
    {
        $reservation = $this->reservation(price: '350.00');

        $this->hit($this->owner(), 'POST', $reservation, '/earn')
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'earn')
            ->assertJsonPath('data.points', 350)
            ->assertJsonPath('data.source_id', $reservation->id);
    }

    public function test_earn_is_idempotent_over_http(): void
    {
        $reservation = $this->reservation();

        $first = $this->hit($this->owner(), 'POST', $reservation, '/earn')->assertStatus(201);
        $this->hit($this->owner(), 'POST', $reservation->fresh(), '/earn')
            ->assertStatus(201)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, LoyaltyTransaction::count());
    }

    public function test_earn_on_a_non_completed_booking_is_a_422(): void
    {
        $this->hit($this->owner(), 'POST', $this->reservation(status: Reservation::STATUS_IN_STAY), '/earn')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_earn_when_the_program_is_off_is_a_422(): void
    {
        $this->hit($this->owner(), 'POST', $this->reservation(activeRule: false), '/earn')->assertStatus(422);
    }

    // ── Redeem ──────────────────────────────────────────────────────

    public function test_redeem_debits_the_balance(): void
    {
        $group = HotelGroup::factory()->create();
        LoyaltyRule::factory()->active('1.0000', '0.0100')->create(['hotel_group_id' => $group->id]);
        $earn = $this->reservation(status: Reservation::STATUS_INVOICED, price: '1000.00', activeRule: false, group: $group);
        $this->hit($this->owner(), 'POST', $earn, '/earn')->assertStatus(201);

        $redeemRes = $this->reservation(status: Reservation::STATUS_IN_STAY, activeRule: false, group: $group);
        $redeemRes->update(['guest_id' => $earn->guest_id]);

        $this->hit($this->owner(), 'POST', $redeemRes->fresh(), '/redeem', ['points' => 400])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'redeem')
            ->assertJsonPath('data.points', -400);

        $this->hit($this->owner(), 'GET', $earn->fresh())
            ->assertJsonPath('data.points_balance', 600);
    }

    public function test_redeem_validates_points(): void
    {
        $reservation = $this->reservation(status: Reservation::STATUS_IN_STAY);

        $this->hit($this->owner(), 'POST', $reservation, '/redeem', ['points' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['points']);
        $this->hit($this->owner(), 'POST', $reservation, '/redeem', ['points' => -5])
            ->assertStatus(422)->assertJsonValidationErrors(['points']);
        $this->hit($this->owner(), 'POST', $reservation, '/redeem', [])
            ->assertStatus(422)->assertJsonValidationErrors(['points']);
    }

    public function test_redeem_with_insufficient_balance_is_a_422(): void
    {
        $this->hit($this->owner(), 'POST', $this->reservation(status: Reservation::STATUS_IN_STAY), '/redeem', ['points' => 10])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_redeem_against_a_completed_booking_is_a_422(): void
    {
        $this->hit($this->owner(), 'POST', $this->reservation(status: Reservation::STATUS_INVOICED), '/redeem', ['points' => 10])
            ->assertStatus(422);
    }

    // ── Security ───────────────────────────────────────────────────

    public function test_client_cannot_set_the_balance_or_the_ledger_delta(): void
    {
        $reservation = $this->reservation(price: '100.00');

        $this->hit($this->owner(), 'POST', $reservation, '/earn', [
            'points' => 999999, 'points_balance' => 999999, 'type' => 'adjust',
        ])->assertStatus(201)->assertJsonPath('data.points', 100);
    }

    public function test_response_never_leaks_internal_detail(): void
    {
        $reservation = $this->reservation();
        $body = $this->hit($this->owner(), 'POST', $reservation, '/earn')->getContent();

        foreach (['SQLSTATE', '.php:', 'Eloquent'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }
}
