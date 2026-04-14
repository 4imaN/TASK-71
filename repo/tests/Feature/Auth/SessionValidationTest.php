<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\ValidateAppSession;
use App\Models\User;
use App\Services\Auth\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integration tests for session-boundary enforcement.
 *
 * Covers:
 *   - SessionManager::isSessionValid()  — missing, revoked, idle-timeout, and valid paths
 *   - SessionManager::recordSession()   — upsert behavior (regression for Issue 1: must
 *                                         insert when the post-regenerate session ID has
 *                                         no row yet rather than silently dropping the write)
 *   - ValidateAppSession middleware     — web redirect (302) and API 401 JSON for invalid sessions
 *   - Login flow regression             — after a successful Livewire login, the sessions table
 *                                         must contain a row for the authenticated user that has
 *                                         device_fingerprint set (proves regenerate → recordSession
 *                                         ordering is correct and upsert fires)
 */
class SessionValidationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'uuid'                => (string) Str::uuid(),
            'username'            => 'sessionuser_' . uniqid(),
            'display_name'        => 'Session Test User',
            'password'            => Hash::make('TestPassword1!'),
            'password_changed_at' => now(),
            'audience_type'       => 'staff',
            'status'              => 'active',
            'failed_attempts'     => 0,
        ], $overrides));
    }

    /**
     * Insert a minimal session row for direct unit tests.
     */
    private function insertSession(string $sessionId, int $userId, array $overrides = []): void
    {
        DB::table('sessions')->insert(array_merge([
            'id'                 => $sessionId,
            'user_id'            => $userId,
            'ip_address'         => '127.0.0.1',
            'user_agent'         => 'TestAgent/1.0',
            'payload'            => base64_encode(serialize([])),
            'last_activity'      => time(),
            'device_fingerprint' => 'test-fingerprint-abc123',
            'last_active_at'     => now(),
            'revoked_at'         => null,
        ], $overrides));
    }

    // ── isSessionValid() unit tests ───────────────────────────────────────────

    /** No DB row → invalid (new session that was never recorded, or cleaned up). */
    public function test_missing_session_row_is_invalid(): void
    {
        $sessionManager = app(SessionManager::class);

        $this->assertFalse(
            $sessionManager->isSessionValid('totally-nonexistent-session-id'),
            'A session with no matching row must be rejected',
        );
    }

    /** revoked_at set → invalid regardless of last_active_at freshness. */
    public function test_revoked_session_is_invalid(): void
    {
        $user      = $this->makeUser();
        $sessionId = Str::random(40);

        $this->insertSession($sessionId, $user->id, ['revoked_at' => now()]);

        $sessionManager = app(SessionManager::class);

        $this->assertFalse(
            $sessionManager->isSessionValid($sessionId),
            'A session with revoked_at set must be rejected',
        );
    }

    /** last_active_at beyond idle timeout → invalid. */
    public function test_idle_session_exceeding_timeout_is_invalid(): void
    {
        $user      = $this->makeUser();
        $sessionId = Str::random(40);

        // 60 minutes stale — far beyond the default 20-minute idle window.
        $this->insertSession($sessionId, $user->id, [
            'last_active_at' => now()->subMinutes(60),
        ]);

        $sessionManager = app(SessionManager::class);

        $this->assertFalse(
            $sessionManager->isSessionValid($sessionId),
            'A session idle for longer than the timeout must be rejected',
        );
    }

    /** Fresh, non-revoked session → valid. */
    public function test_active_session_is_valid(): void
    {
        $user      = $this->makeUser();
        $sessionId = Str::random(40);

        $this->insertSession($sessionId, $user->id);

        $sessionManager = app(SessionManager::class);

        $this->assertTrue(
            $sessionManager->isSessionValid($sessionId),
            'A fresh, non-revoked session must be accepted',
        );
    }

    // ── recordSession() upsert tests ──────────────────────────────────────────

    /**
     * After session()->regenerate() the new session ID may not yet have a DB row.
     * recordSession() MUST insert one so that ValidateAppSession can find it.
     */
    public function test_record_session_inserts_new_row_when_none_exists(): void
    {
        $user      = $this->makeUser();
        $sessionId = Str::random(40);

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);

        $request = Request::create('/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT'      => 'Mozilla/5.0 TestBrowser',
            'REMOTE_ADDR'          => '10.0.0.1',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
        ]);

        $sessionManager = app(SessionManager::class);
        $sessionManager->recordSession($user, $request, $sessionId);

        $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'user_id' => $user->id]);

        $row = DB::table('sessions')->where('id', $sessionId)->first();

        $this->assertNotNull(
            $row->device_fingerprint,
            'device_fingerprint must be written even when the session row did not previously exist',
        );
        $this->assertNotNull(
            $row->last_active_at,
            'last_active_at must be set by recordSession()',
        );
    }

    /**
     * When a row already exists (e.g. pre-regenerate write) it should be updated.
     */
    public function test_record_session_updates_existing_row(): void
    {
        $user      = $this->makeUser();
        $sessionId = Str::random(40);

        // Pre-insert a row with a placeholder fingerprint.
        $this->insertSession($sessionId, $user->id, ['device_fingerprint' => 'old-fingerprint']);

        $request = Request::create('/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT'      => 'NewBrowser/3.0',
            'REMOTE_ADDR'          => '10.0.0.99',
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE',
        ]);

        $sessionManager = app(SessionManager::class);
        $sessionManager->recordSession($user, $request, $sessionId);

        $row = DB::table('sessions')->where('id', $sessionId)->first();

        $this->assertEquals($user->id, $row->user_id);
        // The fingerprint is recomputed from the new request attributes.
        $this->assertNotEquals(
            'old-fingerprint',
            $row->device_fingerprint,
            'recordSession() must overwrite the fingerprint on update',
        );
    }

    // ── ValidateAppSession middleware unit tests ──────────────────────────────

    /**
     * Web request authenticated but session row is missing → 302 to /login.
     */
    public function test_middleware_rejects_web_request_with_missing_session(): void
    {
        $user      = $this->makeUser();
        $sessionId = Str::random(40);
        // No row inserted — missing session scenario.

        // ValidateAppSession only bypasses enforcement for the 'array' driver
        // (in-memory test sessions with no DB rows).  All other drivers —
        // including 'database' and 'redis' — are fully enforced.
        // phpunit.xml sets SESSION_DRIVER=array, so we switch to 'database' here
        // to exercise the enforcement path.
        //
        // IMPORTANT: restore in finally so this mutation does NOT leak into
        // subsequent tests within the same process.
        $originalDriver = config('session.driver');
        config(['session.driver' => 'database']);

        try {
            // Set user on the guard directly (bypasses session-based auth lookup).
            $this->app['auth']->guard()->setUser($user);

            // Force the container's session store to use our controlled session ID
            // so that session()->getId() inside the middleware returns $sessionId.
            $this->app['session']->driver()->setId($sessionId);
            $this->app['session']->driver()->start();

            $middleware = app(ValidateAppSession::class);
            $response   = $middleware->handle(
                Request::create('/dashboard', 'GET'),
                fn ($req) => response('OK', 200),
            );

            $this->assertEquals(302, $response->getStatusCode());
            $this->assertStringContainsString('login', $response->getTargetUrl());
        } finally {
            config(['session.driver' => $originalDriver]);
        }
    }

    /**
     * API request (Accept: application/json) with a revoked session → 401 JSON.
     */
    public function test_middleware_returns_401_json_for_api_request_with_revoked_session(): void
    {
        $user      = $this->makeUser();
        $sessionId = Str::random(40);

        $this->insertSession($sessionId, $user->id, ['revoked_at' => now()]);

        // Same as above: switch away from 'array' so enforcement is active.
        // Restore in finally to prevent config leaking into subsequent tests.
        $originalDriver = config('session.driver');
        config(['session.driver' => 'database']);

        try {
            $this->app['auth']->guard()->setUser($user);
            $this->app['session']->driver()->setId($sessionId);
            $this->app['session']->driver()->start();

            $request = Request::create('/api/v1/user/dashboard', 'GET', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);

            $middleware = app(ValidateAppSession::class);
            $response   = $middleware->handle(
                $request,
                fn ($req) => response('OK', 200),
            );

            $this->assertEquals(401, $response->getStatusCode());

            $body = json_decode($response->getContent(), true);
            $this->assertIsArray($body);
            $this->assertArrayHasKey('message', $body);
        } finally {
            config(['session.driver' => $originalDriver]);
        }
    }

    // ── Login flow regression ─────────────────────────────────────────────────

    /**
     * After a successful Livewire login, the sessions table must contain a row
     * for the authenticated user with device_fingerprint populated.
     *
     * This is the end-to-end regression for Round 2 Issue 1:
     *   - session()->regenerate() must happen BEFORE recordSession()
     *   - recordSession() must use updateOrInsert() so the post-regenerate ID is
     *     written even if no row yet exists for it
     */
    public function test_login_records_session_row_with_device_fingerprint(): void
    {
        $user = $this->makeUser();

        // No session rows before login.
        $this->assertEquals(
            0,
            DB::table('sessions')->where('user_id', $user->id)->count(),
            'No session row should exist before login',
        );

        \Livewire\Livewire::test(\App\Http\Livewire\Auth\LoginComponent::class)
            ->set('username', $user->username)
            ->set('password', 'TestPassword1!')
            ->call('authenticate');

        $this->assertTrue(auth()->check(), 'User must be authenticated after login');

        // recordSession() (via updateOrInsert) must have written a row with the fingerprint.
        $count = DB::table('sessions')
            ->where('user_id', $user->id)
            ->whereNotNull('device_fingerprint')
            ->count();

        $this->assertGreaterThan(
            0,
            $count,
            'A session row with device_fingerprint must exist in the sessions table after login ' .
            '(regression guard: session()->regenerate() → recordSession() with updateOrInsert)',
        );
    }
}
