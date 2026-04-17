<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Admin\StepUpService;
use App\Services\Admin\UserGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator',  'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'learner',        'guard_name' => 'web']);

        $this->admin  = User::factory()->create(['password' => Hash::make('Admin1!')]);
        $this->admin->assignRole('administrator');

        $this->target = User::factory()->create(['status' => 'active']);
    }

    private function svc(): UserGovernanceService
    {
        return app(UserGovernanceService::class);
    }

    // ── lockAccount ───────────────────────────────────────────────────────────

    public function test_lock_account_sets_status_and_locked_until(): void
    {
        $until = now()->addHours(24);
        $updated = $this->svc()->lockAccount($this->target, $this->admin, $until);

        $this->assertEquals('locked', $updated->status);
        $this->assertNotNull($updated->locked_until);
    }

    public function test_lock_account_defaults_to_24_hours(): void
    {
        $updated = $this->svc()->lockAccount($this->target, $this->admin);

        $this->assertEquals('locked', $updated->status);
        $this->assertTrue($updated->locked_until->isFuture());
    }

    public function test_lock_account_writes_audit_log(): void
    {
        $this->svc()->lockAccount($this->target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'user.account_locked',
            'actor_id'   => $this->admin->id,
            'entity_id'  => $this->target->id,
        ]);
    }

    // ── unlockAccount ─────────────────────────────────────────────────────────

    public function test_unlock_account_restores_active_status(): void
    {
        $this->target->update(['status' => 'locked', 'locked_until' => now()->addHours(1)]);
        $updated = $this->svc()->unlockAccount($this->target, $this->admin);

        $this->assertEquals('active', $updated->status);
        $this->assertNull($updated->locked_until);
    }

    public function test_unlock_account_writes_audit_log(): void
    {
        $this->svc()->unlockAccount($this->target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.account_unlocked',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    // ── suspendAccount ────────────────────────────────────────────────────────

    public function test_suspend_sets_suspended_status(): void
    {
        $updated = $this->svc()->suspendAccount($this->target, $this->admin, 'policy violation');

        $this->assertEquals('suspended', $updated->status);
    }

    public function test_suspend_writes_audit_log(): void
    {
        $this->svc()->suspendAccount($this->target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.account_suspended',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    // ── reactivateAccount ─────────────────────────────────────────────────────

    public function test_reactivate_restores_active_from_suspended(): void
    {
        $this->target->update(['status' => 'suspended']);
        $updated = $this->svc()->reactivateAccount($this->target, $this->admin);

        $this->assertEquals('active', $updated->status);
    }

    public function test_reactivate_writes_audit_log(): void
    {
        $this->svc()->reactivateAccount($this->target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.account_reactivated',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    // ── forcePasswordReset ────────────────────────────────────────────────────

    public function test_force_password_reset_sets_flag(): void
    {
        $updated = $this->svc()->forcePasswordReset($this->target, $this->admin);

        $this->assertTrue((bool) $updated->must_change_password);
    }

    public function test_force_password_reset_writes_audit_log(): void
    {
        $this->svc()->forcePasswordReset($this->target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.force_password_reset',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    // ── revokeSessions ────────────────────────────────────────────────────────

    public function test_revoke_sessions_writes_governance_audit_log(): void
    {
        $this->svc()->revokeSessions($this->target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.sessions_revoked_by_admin',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    // ── assignRole ────────────────────────────────────────────────────────────

    public function test_assign_role_gives_user_the_role(): void
    {
        $this->svc()->assignRole($this->target, 'content_editor', $this->admin);

        $this->assertTrue($this->target->fresh()->hasRole('content_editor'));
    }

    public function test_assign_role_writes_audit_log(): void
    {
        $this->svc()->assignRole($this->target, 'content_editor', $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.role_assigned',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    public function test_assign_role_throws_for_nonexistent_role(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->assignRole($this->target, 'nonexistent_role', $this->admin);
    }

    // ── revokeRole ────────────────────────────────────────────────────────────

    public function test_revoke_role_removes_the_role(): void
    {
        $this->target->assignRole('content_editor');
        $this->svc()->revokeRole($this->target, 'content_editor', $this->admin);

        $this->assertFalse($this->target->fresh()->hasRole('content_editor'));
    }

    public function test_revoke_role_writes_audit_log(): void
    {
        $this->target->assignRole('content_editor');
        $this->svc()->revokeRole($this->target, 'content_editor', $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.role_revoked',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    public function test_revoke_administrator_role_blocked_for_last_admin(): void
    {
        // Only one active admin exists (this->admin)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/last active administrator/');

        $this->svc()->revokeRole($this->admin, 'administrator', $this->admin);
    }

    public function test_revoke_administrator_role_allowed_when_second_admin_exists(): void
    {
        $secondAdmin = User::factory()->create(['status' => 'active']);
        $secondAdmin->assignRole('administrator');

        // Should not throw
        $this->svc()->revokeRole($this->target->assignRole('administrator') ? $this->target : $this->target, 'administrator', $this->admin);

        // Verify — target never had admin role in first place; test that second admin exists and revocation works
        $this->assertFalse($this->target->fresh()->hasRole('administrator'));
    }

    // ── API: step-up required for write operations ────────────────────────────

    public function test_api_lock_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/lock")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Step-up verification required.']);
    }

    public function test_api_lock_succeeds_with_stepup(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/lock")
            ->assertOk()
            ->assertJsonPath('user.status', 'locked');
    }

    public function test_api_unlock_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/unlock")
            ->assertStatus(403);
    }

    public function test_api_unlock_succeeds_with_stepup(): void
    {
        $this->target->update(['status' => 'locked']);
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/unlock")
            ->assertOk()
            ->assertJsonPath('user.status', 'active');

        $this->assertDatabaseHas('users', ['id' => $this->target->id, 'status' => 'active']);
    }

    public function test_api_suspend_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/suspend")
            ->assertStatus(403);
    }

    public function test_api_suspend_succeeds_with_stepup(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/suspend")
            ->assertOk()
            ->assertJsonPath('user.status', 'suspended');

        $this->assertDatabaseHas('users', ['id' => $this->target->id, 'status' => 'suspended']);
    }

    public function test_api_force_password_reset_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/force-password-reset")
            ->assertStatus(403);
    }

    public function test_api_force_password_reset_succeeds_with_stepup(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/force-password-reset")
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id'                   => $this->target->id,
            'must_change_password' => true,
        ]);
    }

    public function test_api_revoke_role_blocks_last_admin(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/users/{$this->admin->id}/roles/administrator")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot revoke the administrator role from the last active administrator. Assign it to another account first.']);
    }

    // ── deleteAccount ─────────────────────────────────────────────────────────

    public function test_delete_account_soft_deletes_user(): void
    {
        $this->svc()->deleteAccount($this->target, $this->admin);

        $this->assertSoftDeleted('users', ['id' => $this->target->id]);
    }

    public function test_delete_account_writes_audit_log(): void
    {
        $this->svc()->deleteAccount($this->target, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.account_deleted',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    public function test_delete_account_accepts_optional_reason(): void
    {
        $this->svc()->deleteAccount($this->target, $this->admin, 'account closure request');

        $this->assertSoftDeleted('users', ['id' => $this->target->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.account_deleted',
            'entity_id' => $this->target->id,
        ]);
    }

    public function test_delete_account_blocks_last_active_administrator(): void
    {
        // Only one active admin ($this->admin). Deleting it must throw.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/last active administrator/');

        $this->svc()->deleteAccount($this->admin, $this->admin);
    }

    public function test_delete_account_allowed_when_second_admin_exists(): void
    {
        $secondAdmin = User::factory()->create(['status' => 'active']);
        $secondAdmin->assignRole('administrator');

        // target is a non-admin user; deletion should succeed
        $this->svc()->deleteAccount($this->target, $this->admin);

        $this->assertSoftDeleted('users', ['id' => $this->target->id]);
    }

    public function test_delete_admin_allowed_when_second_admin_exists(): void
    {
        // Promote target to admin, add a second admin, then delete target
        $this->target->assignRole('administrator');
        $secondAdmin = User::factory()->create(['status' => 'active']);
        $secondAdmin->assignRole('administrator');

        $this->svc()->deleteAccount($this->target, $this->admin);

        $this->assertSoftDeleted('users', ['id' => $this->target->id]);
    }

    // ── API: DELETE /api/v1/admin/users/{id} ──────────────────────────────────

    public function test_api_delete_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/users/{$this->target->id}")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Step-up verification required.']);
    }

    public function test_api_delete_succeeds_with_stepup(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/users/{$this->target->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $this->target->id]);
    }

    public function test_api_delete_blocks_last_admin(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/users/{$this->admin->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete the last active administrator. Assign the administrator role to another account first.']);
    }

    public function test_api_delete_requires_administrator_role(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->deleteJson("/api/v1/admin/users/{$this->target->id}")
            ->assertForbidden();
    }

    // ── setInitialPassword ──────────────────────────────────────────────────

    public function test_set_initial_password_updates_password_and_sets_must_change(): void
    {
        $result = $this->svc()->setInitialPassword($this->target, 'ValidNewPass1!', $this->admin);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);

        $fresh = $this->target->fresh();
        $this->assertTrue(Hash::check('ValidNewPass1!', $fresh->password));
        $this->assertTrue((bool) $fresh->must_change_password);
    }

    public function test_set_initial_password_rejects_weak_password(): void
    {
        $result = $this->svc()->setInitialPassword($this->target, 'short', $this->admin);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_set_initial_password_writes_audit_log(): void
    {
        $this->svc()->setInitialPassword($this->target, 'ValidNewPass1!', $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'user.initial_password_set',
            'actor_id'  => $this->admin->id,
            'entity_id' => $this->target->id,
        ]);
    }

    public function test_api_set_password_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/set-password", [
                'password'              => 'ValidNewPass1!',
                'password_confirmation' => 'ValidNewPass1!',
            ])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Step-up verification required.']);
    }

    public function test_api_set_password_succeeds_with_stepup(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/set-password", [
                'password'              => 'ValidNewPass1!',
                'password_confirmation' => 'ValidNewPass1!',
            ])
            ->assertOk()
            ->assertJsonPath('must_change_password', true);

        $this->assertTrue(Hash::check('ValidNewPass1!', $this->target->fresh()->password));
    }

    public function test_api_set_password_rejects_mismatched_confirmation(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/set-password", [
                'password'              => 'ValidNewPass1!',
                'password_confirmation' => 'Different1!zzz',
            ])
            ->assertStatus(422);
    }

    // ── API: list / show ──────────────────────────────────────────────────────

    public function test_api_list_users_returns_paginated_results(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page']);
    }

    public function test_api_list_users_requires_administrator_role(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner)
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    public function test_api_show_user_returns_user_with_roles(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/users/{$this->target->id}")
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'username', 'roles']]);
    }

    // ── API: POST /admin/users/{id}/reactivate (item 11) ─────────────────────

    public function test_api_reactivate_requires_stepup(): void
    {
        $this->target->update(['status' => 'suspended']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/reactivate")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Step-up verification required.']);
    }

    public function test_api_reactivate_succeeds_with_stepup(): void
    {
        $this->target->update(['status' => 'suspended']);
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('user.status', 'active');

        $this->assertDatabaseHas('users', ['id' => $this->target->id, 'status' => 'active']);
    }

    public function test_api_reactivate_forbidden_for_non_admin(): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson("/api/v1/admin/users/{$this->target->id}/reactivate")
            ->assertForbidden();
    }

    // ── API: POST /admin/users/{id}/revoke-sessions (item 12) ────────────────

    public function test_api_revoke_sessions_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/revoke-sessions")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Step-up verification required.']);
    }

    public function test_api_revoke_sessions_succeeds_with_stepup(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/revoke-sessions")
            ->assertOk()
            ->assertJsonStructure(['sessions_revoked']);
    }

    public function test_api_revoke_sessions_forbidden_for_non_admin(): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson("/api/v1/admin/users/{$this->target->id}/revoke-sessions")
            ->assertForbidden();
    }

    // ── API: POST /admin/users/{id}/roles (item 13) ──────────────────────────

    public function test_api_assign_role_requires_stepup(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/roles", ['role' => 'learner'])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Step-up verification required.']);
    }

    public function test_api_assign_role_succeeds_with_stepup(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/roles", ['role' => 'content_editor'])
            ->assertOk()
            ->assertJsonPath('user.id', $this->target->id);

        $this->assertTrue($this->target->fresh()->hasRole('content_editor'));
    }

    public function test_api_assign_invalid_role_returns_422(): void
    {
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->target->id}/roles", ['role' => 'nonexistent_role'])
            ->assertStatus(422);
    }

    public function test_api_assign_role_forbidden_for_non_admin(): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson("/api/v1/admin/users/{$this->target->id}/roles", ['role' => 'learner'])
            ->assertForbidden();
    }
}
