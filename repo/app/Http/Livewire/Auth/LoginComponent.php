<?php

namespace App\Http\Livewire\Auth;

use App\Models\User;
use App\Services\Admin\SystemConfigService;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\CaptchaService;
use App\Services\Auth\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Component;

/**
 * Offline username+password login with brute-force protection and local CAPTCHA.
 *
 * CAPTCHA is a server-side math challenge (CaptchaService).
 * The challenge question is stored in component state; the expected answer
 * is stored in the cache under the token key. CAPTCHA_ENABLED=false in
 * test environments disables the challenge without altering auth logic.
 */
#[Layout('layouts.auth')]
class LoginComponent extends Component
{
    #[Rule('required|string|max:100')]
    public string $username = '';

    #[Rule('required|string')]
    public string $password = '';

    public bool   $showCaptcha     = false;
    public string $captchaInput    = '';
    public string $captchaToken    = '';
    public string $captchaQuestion = '';
    public string $error           = '';

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirect(route('dashboard'), navigate: true);
        }
    }

    /**
     * Generate (or regenerate) a CAPTCHA challenge.
     * Safe to call when $showCaptcha transitions to true and after each wrong answer.
     */
    public function refreshCaptcha(): void
    {
        /** @var CaptchaService $captcha */
        $captcha = app(CaptchaService::class);

        // Consume any live token before generating a new one
        if ($this->captchaToken !== '') {
            $captcha->consume($this->captchaToken);
        }

        $challenge = $captcha->generate();

        $this->captchaToken    = $challenge['token'];
        $this->captchaQuestion = $challenge['question'];
        $this->captchaInput    = '';
    }

    public function authenticate(): void
    {
        $this->validate();

        /** @var SystemConfigService $config */
        $config = app(SystemConfigService::class);
        /** @var AuditLogger $audit */
        $audit = app(AuditLogger::class);

        $user = User::where('username', strtolower(trim($this->username)))->first();

        // Unknown user — generic error to prevent username enumeration
        if (!$user) {
            $this->addError('username', 'Invalid credentials.');
            return;
        }

        // Locked account — report remaining wait time
        if ($user->isAccountLocked()) {
            $minutes = (int) now()->diffInMinutes($user->locked_until, absolute: true);
            $this->error = "Account locked. Try again in {$minutes} minute(s).";
            return;
        }

        // CAPTCHA gate — only active when enabled and already shown
        $captchaEnabled = config('app.captcha_enabled', true);

        if ($captchaEnabled && $this->showCaptcha) {
            /** @var CaptchaService $captcha */
            $captcha = app(CaptchaService::class);

            if (!$captcha->verify($this->captchaToken, $this->captchaInput)) {
                $this->refreshCaptcha();
                $this->addError('captchaInput', 'Incorrect answer. Please try again.');
                return;
            }

            // Correct answer — consume so the token cannot be reused
            $captcha->consume($this->captchaToken);
            $this->captchaToken = '';
        }

        // Password check
        if (!Hash::check($this->password, $user->password)) {
            $user->increment('failed_attempts');

            $maxAttempts    = $config->bruteForceMaxAttempts();
            $lockoutMinutes = $config->bruteForceLockoutMinutes();

            if ($user->failed_attempts >= $maxAttempts) {
                $user->update([
                    'status'       => 'locked',
                    'locked_until' => now()->addMinutes($lockoutMinutes),
                ]);
                $audit->log('auth.login.lockout', $user->id, entityType: 'user', entityId: $user->id,
                    metadata: ['failed_attempts' => $user->failed_attempts]);

                $this->showCaptcha = false;
                $this->error = "Too many failed attempts. Account locked for {$lockoutMinutes} minutes.";
            } else {
                $this->addError('password', 'Invalid credentials.');

                // Show CAPTCHA once threshold is reached; refresh after each bad attempt
                if ($captchaEnabled && $user->failed_attempts >= $config->captchaShowAfterAttempts()) {
                    if (!$this->showCaptcha) {
                        $this->showCaptcha = true;
                    }
                    $this->refreshCaptcha();
                }
            }

            $audit->log('auth.login.failed', $user->id, entityType: 'user', entityId: $user->id,
                metadata: ['failed_attempts' => $user->failed_attempts]);
            return;
        }

        // Successful authentication
        $user->update(['failed_attempts' => 0, 'locked_until' => null, 'status' => 'active']);

        Auth::login($user);

        // Regenerate the session ID immediately after authentication so that the
        // new ID is what gets recorded and validated on subsequent requests.
        // Must happen BEFORE detectAndAuditLoginAnomalies / recordSession so both
        // operate on the stable post-regeneration session ID.
        session()->regenerate();

        /** @var SessionManager $sessionManager */
        $sessionManager = app(SessionManager::class);

        // Detect anomalies BEFORE recording session so the current session is
        // not yet fingerprinted — gives a clean "first time this fingerprint" signal.
        $sessionManager->detectAndAuditLoginAnomalies($user, request());

        $sessionManager->recordSession($user, request(), session()->getId());

        $audit->log('auth.login.success', $user->id, entityType: 'user', entityId: $user->id);

        // Force password change if required
        if ($user->must_change_password) {
            $this->redirect(route('password.change'), navigate: true);
            return;
        }

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
