<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordHistory;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\Admin\SystemConfigService;
use App\Services\Auth\PasswordChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers:
 *   - PasswordChangeService: change(), isRotationExpired(), mustChange()
 *   - EnforcePasswordChange middleware behaviour
 *   - POST /api/v1/auth/password/change REST endpoint
 *   - PasswordChangeComponent (Livewire)
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'uuid'                 => (string) Str::uuid(),
            'username'             => 'testuser_' . uniqid(),
            'display_name'         => 'Test User',
            'password'             => Hash::make('OldPassword1!'),
            'password_changed_at'  => now(),
            'audience_type'        => 'staff',
            'status'               => 'active',
            'must_change_password' => false,
            'failed_attempts'      => 0,
        ], $overrides));
    }

    private function svc(): PasswordChangeService
    {
        return app(PasswordChangeService::class);
    }

    /** Helper: set a system config value AND flush its cache. */
    private function setConfig(string $key, string $value): void
    {
        app(SystemConfigService::class)->set($key, $value);
    }

    // ── PasswordChangeService: change() ───────────────────────────────────────

    public function test_change_fails_when_current_password_is_wrong(): void
    {
        $user   = $this->makeUser();
        $result = $this->svc()->change($user, 'WrongPassword1!', 'NewPassword1!');

        $this->assertFalse($result['ok']);
        $this->assertContains('Current password is incorrect.', $result['errors']);
    }

    public function test_change_fails_with_weak_new_password(): void
    {
        $user   = $this->makeUser();
        $result = $this->svc()->change($user, 'OldPassword1!', 'weak');

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_change_succeeds_and_updates_password(): void
    {
        $user   = $this->makeUser();
        $result = $this->svc()->change($user, 'OldPassword1!', 'NewPassword1!');

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
        $this->assertTrue(Hash::check('NewPassword1!', $user->fresh()->password));
    }

    public function test_change_clears_must_change_password_flag(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        $this->svc()->change($user, 'OldPassword1!', 'NewPassword1!');

        $this->assertFalse((bool) $user->fresh()->must_change_password);
    }

    public function test_change_stamps_password_changed_at(): void
    {
        $user = $this->makeUser(['password_changed_at' => now()->subYear()]);

        $this->svc()->change($user, 'OldPassword1!', 'NewPassword1!');

        $this->assertTrue($user->fresh()->password_changed_at->isToday());
    }

    public function test_change_records_new_hash_in_history(): void
    {
        $user = $this->makeUser();

        $this->svc()->change($user, 'OldPassword1!', 'NewPassword1!');

        $entry = PasswordHistory::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($entry);
        $this->assertTrue(Hash::check('NewPassword1!', $entry->password_hash));
    }

    public function test_change_writes_audit_log(): void
    {
        $user = $this->makeUser();

        $this->svc()->change($user, 'OldPassword1!', 'NewPassword1!');

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'auth.password_changed',
            'actor_id'  => $user->id,
            'entity_id' => $user->id,
        ]);
    }

    public function test_change_fails_when_new_password_is_in_history(): void
    {
        $user = $this->makeUser();

        // Seed the old password into history to simulate it being a past password
        PasswordHistory::create([
            'user_id'       => $user->id,
            'password_hash' => $user->password, // OldPassword1! hash
            'created_at'    => now(),
        ]);

        // Trying to reuse OldPassword1! as the new password should fail (history match)
        $result = $this->svc()->change($user, 'OldPassword1!', 'OldPassword1!');

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    // ── PasswordChangeService: isRotationExpired() ────────────────────────────

    public function test_rotation_not_expired_when_disabled(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['password_changed_at' => now()->subDays(999)]);

        $this->assertFalse($this->svc()->isRotationExpired($user));
    }

    public function test_rotation_expired_when_password_changed_at_is_null(): void
    {
        $this->setConfig('password_rotation_days', '90');
        $user = $this->makeUser(['password_changed_at' => null]);

        $this->assertTrue($this->svc()->isRotationExpired($user));
    }

    public function test_rotation_expired_when_past_window(): void
    {
        $this->setConfig('password_rotation_days', '90');
        $user = $this->makeUser(['password_changed_at' => now()->subDays(91)]);

        $this->assertTrue($this->svc()->isRotationExpired($user));
    }

    public function test_rotation_not_expired_when_within_window(): void
    {
        $this->setConfig('password_rotation_days', '90');
        $user = $this->makeUser(['password_changed_at' => now()->subDays(45)]);

        $this->assertFalse($this->svc()->isRotationExpired($user));
    }

    // ── PasswordChangeService: mustChange() ───────────────────────────────────

    public function test_must_change_returns_true_for_flag(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => true]);

        $this->assertTrue($this->svc()->mustChange($user));
    }

    public function test_must_change_returns_true_for_rotation_expiry(): void
    {
        $this->setConfig('password_rotation_days', '90');
        $user = $this->makeUser(['password_changed_at' => now()->subDays(91)]);

        $this->assertTrue($this->svc()->mustChange($user));
    }

    public function test_must_change_returns_false_for_normal_user(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => false]);

        $this->assertFalse($this->svc()->mustChange($user));
    }

    // ── EnforcePasswordChange middleware ──────────────────────────────────────
    //
    // ValidateAppSession requires an app_sessions row that isn't present in
    // simple actingAs() calls; we bypass it so each test isolates only the
    // EnforcePasswordChange behaviour.

    public function test_middleware_redirects_to_password_change_when_flag_set(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => true]);

        $this->withoutMiddleware(\App\Http\Middleware\ValidateAppSession::class)
            ->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.change'));
    }

    public function test_middleware_allows_access_when_no_change_required(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => false]);

        $this->withoutMiddleware(\App\Http\Middleware\ValidateAppSession::class)
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_middleware_does_not_block_password_change_route_itself(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => true]);

        // /password/change is outside the EnforcePasswordChange middleware group
        // so it must always be reachable when must_change_password = true.
        $this->actingAs($user)
            ->get('/password/change')
            ->assertOk();
    }

    public function test_middleware_returns_403_json_when_json_request_and_flag_set(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => true]);

        // Hit a web route with an Accept: application/json header to exercise
        // the JSON branch of the middleware (EnforcePasswordChange returns 403).
        $this->withoutMiddleware(\App\Http\Middleware\ValidateAppSession::class)
            ->actingAs($user)
            ->getJson('/dashboard')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Password change required before proceeding.']);
    }

    // ── POST /api/v1/auth/password/change ─────────────────────────────────────

    public function test_api_change_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/password/change', [
            'current_password'          => 'OldPassword1!',
            'new_password'              => 'NewPassword1!',
            'new_password_confirmation' => 'NewPassword1!',
        ])->assertUnauthorized();
    }

    public function test_api_change_succeeds_with_valid_payload(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/password/change', [
                'current_password'          => 'OldPassword1!',
                'new_password'              => 'NewPassword1!',
                'new_password_confirmation' => 'NewPassword1!',
            ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Password changed successfully.']);

        $this->assertTrue(Hash::check('NewPassword1!', $user->fresh()->password));
    }

    public function test_api_change_returns_422_for_wrong_current_password(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/password/change', [
                'current_password'          => 'WrongPassword1!',
                'new_password'              => 'NewPassword1!',
                'new_password_confirmation' => 'NewPassword1!',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Password change failed.']);
    }

    public function test_api_change_returns_422_for_mismatched_confirmation(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/password/change', [
                'current_password'          => 'OldPassword1!',
                'new_password'              => 'NewPassword1!',
                'new_password_confirmation' => 'DifferentPassword1!',
            ])
            ->assertStatus(422);
    }

    public function test_api_change_returns_422_for_weak_new_password(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/password/change', [
                'current_password'          => 'OldPassword1!',
                'new_password'              => 'weak',
                'new_password_confirmation' => 'weak',
            ])
            ->assertStatus(422);
    }

    // ── Livewire PasswordChangeComponent ─────────────────────────────────────

    public function test_livewire_component_renders_on_password_change_route(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => false]);

        $this->actingAs($user)
            ->get('/password/change')
            ->assertOk()
            ->assertSeeLivewire(\App\Http\Livewire\Auth\PasswordChangeComponent::class);
    }

    public function test_livewire_change_succeeds_and_redirects(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Http\Livewire\Auth\PasswordChangeComponent::class)
            ->set('currentPassword', 'OldPassword1!')
            ->set('newPassword', 'NewPassword1!')
            ->set('newPasswordConfirmation', 'NewPassword1!')
            ->call('changePassword')
            ->assertRedirect(route('dashboard'));
    }

    public function test_livewire_change_shows_errors_on_wrong_current_password(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Http\Livewire\Auth\PasswordChangeComponent::class)
            ->set('currentPassword', 'WrongPassword1!')
            ->set('newPassword', 'NewPassword1!')
            ->set('newPasswordConfirmation', 'NewPassword1!')
            ->call('changePassword')
            ->assertSet('changeErrors', fn ($errors) => count($errors) > 0)
            ->assertNoRedirect();
    }

    public function test_livewire_change_shows_error_when_passwords_do_not_match(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Http\Livewire\Auth\PasswordChangeComponent::class)
            ->set('currentPassword', 'OldPassword1!')
            ->set('newPassword', 'NewPassword1!')
            ->set('newPasswordConfirmation', 'DifferentPassword1!')
            ->call('changePassword')
            ->assertHasErrors(['newPasswordConfirmation'])
            ->assertNoRedirect();
    }

    public function test_livewire_shows_forced_change_banner_when_flag_is_set(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => true]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Http\Livewire\Auth\PasswordChangeComponent::class)
            ->assertSee('Password change required');
    }

    public function test_livewire_does_not_show_forced_banner_for_voluntary_change(): void
    {
        $this->setConfig('password_rotation_days', '0');
        $user = $this->makeUser(['must_change_password' => false]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Http\Livewire\Auth\PasswordChangeComponent::class)
            ->assertDontSee('Password change required');
    }
}
