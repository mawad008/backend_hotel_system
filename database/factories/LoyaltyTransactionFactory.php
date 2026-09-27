<?php

namespace Database\Factories;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyTransaction>
 */
class LoyaltyTransactionFactory extends Factory
{
    protected $model = LoyaltyTransaction::class;

    public function definition(): array
    {
        return [
            'loyalty_account_id' => LoyaltyAccount::factory(),
            'type' => LoyaltyTransaction::TYPE_EARN,
            'points' => 100,
            'source_type' => LoyaltyTransaction::SOURCE_RESERVATION,
            'source_id' => fn () => fake()->unique()->numberBetween(1, 1_000_000),
            'reverses_transaction_id' => null,
            'description' => 'Points earned for a completed stay',
            'created_by_user_id' => null,
            'metadata' => null,
        ];
    }

    public function earn(int $points): static
    {
        return $this->state(fn () => ['type' => LoyaltyTransaction::TYPE_EARN, 'points' => abs($points)]);
    }

    public function redeem(int $points): static
    {
        return $this->state(fn () => ['type' => LoyaltyTransaction::TYPE_REDEEM, 'points' => -abs($points)]);
    }

    public function forReservation(int $reservationId): static
    {
        return $this->state(fn () => [
            'source_type' => LoyaltyTransaction::SOURCE_RESERVATION,
            'source_id' => $reservationId,
        ]);
    }
}
