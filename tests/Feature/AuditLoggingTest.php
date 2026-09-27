<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    public function test_login_writes_an_audit_log_entry(): void
    {
        $user = User::factory()->groupOwner()->create(['password' => 'password']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'auth.login',
        ]);
    }

    public function test_creating_a_hotel_group_writes_an_audit_log_entry_with_actor_and_after_state(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/hotel-groups', [
            'name' => 'Acme Hotels',
            'slug' => 'acme-hotels',
        ]);

        $groupId = $response->json('data.id');

        $log = AuditLog::where('action', 'hotel-group.created')->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->actor_id);
        $this->assertSame($groupId, $log->auditable_id);
        $this->assertSame('acme-hotels', $log->after['slug']);
    }

    public function test_updating_a_hotel_group_records_before_and_after_state(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $group = HotelGroup::factory()->create(['name' => 'Old Name']);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotel-groups/{$group->id}", ['name' => 'New Name'])
            ->assertOk();

        $log = AuditLog::where('action', 'hotel-group.updated')->first();

        $this->assertNotNull($log);
        $this->assertSame('Old Name', $log->before['name']);
        $this->assertSame('New Name', $log->after['name']);
    }

    public function test_deleting_a_user_writes_an_audit_log_entry(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $target = User::factory()->reception()->create();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/users/{$target->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $owner->id,
            'action' => 'user.deleted',
            'auditable_id' => $target->id,
        ]);
    }
}
