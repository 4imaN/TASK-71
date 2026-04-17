<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Session-realistic API tests (item 6).
 *
 * Exercises real API endpoints through ValidateAppSession with actual
 * session rows — validates that protected surfaces are enforced end-to-end,
 * not just at the middleware unit level.
 *
 * Uses SESSION_DRIVER=database to activate session validation logic.
 */
class SessionRealisticApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $originalDriver;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);

        $this->admin = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->admin->assignRole('administrator');

        $this->originalDriver = config('session.driver');
        config(['session.driver' => 'database']);
    }

    protected function tearDown(): void
    {
        config(['session.driver' => $this->originalDriver]);
        parent::tearDown();
    }

    private function insertValidSession(string $sessionId, int $userId): void
    {
        DB::table('sessions')->insert([
            'id'                 => $sessionId,
            'user_id'            => $userId,
            'ip_address'         => '127.0.0.1',
            'user_agent'         => 'TestAgent/1.0',
            'payload'            => base64_encode(serialize([])),
            'last_activity'      => time(),
            'device_fingerprint' => hash('sha256', 'test-fingerprint'),
            'last_active_at'     => now(),
            'revoked_at'         => null,
        ]);
    }

    // ── Valid session passes through to the endpoint ─────────────────────────

    public function test_admin_config_accessible_with_valid_session(): void
    {
        $sessionId = Str::random(40);
        $this->insertValidSession($sessionId, $this->admin->id);

        $this->app['auth']->guard()->setUser($this->admin);
        $this->app['session']->driver()->setId($sessionId);
        $this->app['session']->driver()->start();

        $response = $this->getJson('/api/v1/admin/system-config');

        // If session is valid, the request should reach the controller
        // and return the config groups
        $response->assertOk();
        $response->assertJsonStructure(['groups']);
    }

    // ── Revoked session blocks the endpoint ──────────────────────────────────

    public function test_admin_config_blocked_with_revoked_session(): void
    {
        $sessionId = Str::random(40);
        $this->insertValidSession($sessionId, $this->admin->id);

        // Revoke the session
        DB::table('sessions')->where('id', $sessionId)->update([
            'revoked_at' => now(),
        ]);

        $this->app['auth']->guard()->setUser($this->admin);
        $this->app['session']->driver()->setId($sessionId);
        $this->app['session']->driver()->start();

        $response = $this->getJson('/api/v1/admin/system-config');

        $response->assertStatus(401);
        $response->assertJsonFragment(['message' => 'Session expired or revoked.']);
    }

    // ── Idle-expired session blocks the endpoint ─────────────────────────────

    public function test_admin_config_blocked_with_idle_expired_session(): void
    {
        $sessionId = Str::random(40);
        $this->insertValidSession($sessionId, $this->admin->id);

        // Make the session idle (60 minutes ago, well past the 20-minute default)
        DB::table('sessions')->where('id', $sessionId)->update([
            'last_active_at' => now()->subMinutes(60),
        ]);

        $this->app['auth']->guard()->setUser($this->admin);
        $this->app['session']->driver()->setId($sessionId);
        $this->app['session']->driver()->start();

        $response = $this->getJson('/api/v1/admin/system-config');

        $response->assertStatus(401);
    }

    // ── Catalog (public) accessible without session ──────────────────────────

    public function test_public_catalog_accessible_without_session(): void
    {
        $response = $this->getJson('/api/v1/catalog/services');

        $response->assertOk();
    }
}
