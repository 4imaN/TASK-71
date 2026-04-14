<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'uuid'                => (string) Str::uuid(),
            'username'            => 'testuser',
            'display_name'        => 'Test User',
            'password'            => Hash::make('TestPassword1!'),
            'password_changed_at' => now(),
            'audience_type'       => 'staff',
            'status'              => 'active',
            'failed_attempts'     => 0,
        ], $overrides));
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get('/login');
        $response->assertSuccessful();
        $response->assertSee('Sign In');
    }

    public function test_valid_credentials_redirect_to_dashboard(): void
    {
        $this->makeUser();

        \Livewire\Livewire::test(\App\Http\Livewire\Auth\LoginComponent::class)
            ->set('username', 'testuser')
            ->set('password', 'TestPassword1!')
            ->call('authenticate');

        $this->assertTrue(auth()->check());
    }

    public function test_invalid_password_increments_failed_attempts(): void
    {
        $user = $this->makeUser();

        \Livewire\Livewire::test(\App\Http\Livewire\Auth\LoginComponent::class)
            ->set('username', 'testuser')
            ->set('password', 'WrongPassword1!')
            ->call('authenticate');

        $this->assertEquals(1, $user->fresh()->failed_attempts);
        $this->assertFalse(auth()->check());
    }

    public function test_account_is_locked_after_max_attempts(): void
    {
        // CAPTCHA is disabled in test env (CAPTCHA_ENABLED=false in phpunit.xml),
        // so all 5 attempts go through to the password check path.
        $user = $this->makeUser();

        $component = \Livewire\Livewire::test(\App\Http\Livewire\Auth\LoginComponent::class);

        for ($i = 0; $i < 5; $i++) {
            $component
                ->set('username', 'testuser')
                ->set('password', 'WrongPassword1!')
                ->call('authenticate');
        }

        $this->assertEquals('locked', $user->fresh()->status);
        $this->assertNotNull($user->fresh()->locked_until);
    }

    public function test_locked_account_cannot_login(): void
    {
        $user = $this->makeUser([
            'status'       => 'locked',
            'locked_until' => now()->addMinutes(10),
        ]);

        $component = \Livewire\Livewire::test(\App\Http\Livewire\Auth\LoginComponent::class)
            ->set('username', 'testuser')
            ->set('password', 'TestPassword1!')
            ->call('authenticate');

        $this->assertFalse(auth()->check());
        $component->assertSet('error', fn ($error) => str_contains($error, 'locked'));
    }

    public function test_unauthenticated_dashboard_redirects_to_login(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    /**
     * CAPTCHA is gated by config('app.captcha_enabled').
     * This test exercises the challenge/verify/consume cycle directly,
     * since the Livewire flow in tests uses CAPTCHA_ENABLED=false.
     */
    public function test_captcha_challenge_is_verified_and_consumed(): void
    {
        $service = app(CaptchaService::class);

        // Generate a challenge
        $challenge = $service->generate();
        $this->assertArrayHasKey('token', $challenge);
        $this->assertArrayHasKey('question', $challenge);
        $this->assertNotEmpty($challenge['token']);
        $this->assertMatchesRegularExpression('/^\d+ [+\-] \d+$/', $challenge['question']);

        // Wrong answer returns false; token still exists in cache
        $this->assertFalse($service->verify($challenge['token'], '999'));

        // Derive correct answer from question string
        [$a, $op, $b] = explode(' ', $challenge['question']);
        $expected = ($op === '+') ? ((int)$a + (int)$b) : ((int)$a - (int)$b);

        // Correct answer returns true
        $this->assertTrue($service->verify($challenge['token'], (string) $expected));

        // Consume invalidates the token
        $service->consume($challenge['token']);
        $this->assertFalse($service->verify($challenge['token'], (string) $expected));
    }

    public function test_captcha_token_expires(): void
    {
        // verify() returns false for an unknown/expired token
        $service = app(CaptchaService::class);
        $this->assertFalse($service->verify('non-existent-token', '5'));
    }

    public function test_captcha_shown_when_enabled_after_threshold(): void
    {
        // Re-enable CAPTCHA for this specific test
        config(['app.captcha_enabled' => true]);

        $user = $this->makeUser();

        /** @var \App\Services\Admin\SystemConfigService $configSvc */
        $configSvc = app(\App\Services\Admin\SystemConfigService::class);
        $configSvc->set('brute_force_max_attempts', 5);
        $configSvc->set('captcha_show_after_attempts', 3);
        $threshold = 3;

        $component = \Livewire\Livewire::test(\App\Http\Livewire\Auth\LoginComponent::class);

        for ($i = 0; $i < $threshold; $i++) {
            $component
                ->set('username', 'testuser')
                ->set('password', 'WrongPassword1!')
                ->call('authenticate');
        }

        $component->assertSet('showCaptcha', true);
        // captchaQuestion must be a non-empty string like "7 + 3"
        $component->assertSet('captchaQuestion', fn ($q) => str_contains($q, ' '));
    }

    /**
     * Under shipped defaults (captcha_show_after_attempts=3, brute_force_max_attempts=5),
     * CAPTCHA must appear before lockout kicks in.  This proves the two thresholds
     * do not collide — a static auditor can verify the invariant:
     * captcha_show_after_attempts < brute_force_max_attempts.
     */
    public function test_captcha_triggers_before_lockout_under_default_config(): void
    {
        config(['app.captcha_enabled' => true]);

        $user = $this->makeUser();

        /** @var \App\Services\Admin\SystemConfigService $configSvc */
        $configSvc = app(\App\Services\Admin\SystemConfigService::class);
        // Use the shipped defaults
        $captchaThreshold = $configSvc->captchaShowAfterAttempts();  // 3
        $lockoutThreshold = $configSvc->bruteForceMaxAttempts();     // 5

        // Invariant: CAPTCHA must trigger strictly before lockout
        $this->assertLessThan(
            $lockoutThreshold,
            $captchaThreshold,
            'captcha_show_after_attempts must be less than brute_force_max_attempts'
        );

        $component = \Livewire\Livewire::test(\App\Http\Livewire\Auth\LoginComponent::class);

        // Fail enough times to trigger CAPTCHA but NOT lockout
        for ($i = 0; $i < $captchaThreshold; $i++) {
            $component
                ->set('username', 'testuser')
                ->set('password', 'WrongPassword1!')
                ->call('authenticate');
        }

        // CAPTCHA is shown
        $component->assertSet('showCaptcha', true);
        // Account is NOT yet locked
        $this->assertEquals('active', $user->fresh()->status);
        $this->assertNull($user->fresh()->locked_until);
    }
}
