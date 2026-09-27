<?php

namespace Database\Factories;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->groupOwner(),
            'action' => 'hotel.updated',
            'auditable_type' => null,
            'auditable_id' => null,
            'hotel_id' => Hotel::factory(),
            'before' => null,
            'after' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'created_at' => now(),
        ];
    }
}
