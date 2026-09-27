<?php

namespace Database\Factories;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyAccount>
 */
class LoyaltyAccountFactory extends Factory
{
    protected $model = LoyaltyAccount::class;

    public function definition(): array
    {
        return [
            'guest_id' => Guest::factory(),
            'points_balance' => 0,
            'is_active' => true,
        ];
    }

    public function withBalance(int $points): static
    {
        return $this->state(fn () => ['points_balance' => $points]);
    }
}
