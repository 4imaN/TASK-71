<?php

namespace App\Http\Livewire\Auth;

use App\Services\Auth\PasswordChangeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Handles all three password-change entry points:
 *   - Voluntary change (authenticated user wants to rotate their password)
 *   - Forced change (must_change_password = true, set by admin or import)
 *   - Rotation expiry (password_changed_at + rotation_days is in the past)
 *
 * The forced/rotation-expired context is surfaced as a banner so the user
 * understands why the change is required, but the underlying form and
 * validation path are identical for all three cases.
 *
 * On success the user is redirected to the dashboard.  If must_change_password
 * was set (or rotation was expired), the service layer clears that flag and
 * stamps password_changed_at — the EnforcePasswordChange middleware will no
 * longer block subsequent requests.
 */
#[Layout('layouts.auth')]
class PasswordChangeComponent extends Component
{
    public string $currentPassword       = '';
    public string $newPassword           = '';
    public string $newPasswordConfirmation = '';

    /** Populated by the service if validation fails */
    public array  $changeErrors   = [];
    public string $generalError   = '';
    public bool   $success        = false;

    public function mount(): void
    {
        if (!Auth::check()) {
            $this->redirect(route('login'), navigate: true);
        }
    }

    /**
     * Whether the user is on this page because a change is required (not voluntary).
     */
    public function isForced(): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return app(PasswordChangeService::class)->mustChange($user);
    }

    public function changePassword(PasswordChangeService $service): void
    {
        $this->changeErrors = [];
        $this->generalError = '';

        // Basic presence validation
        if ($this->currentPassword === '') {
            $this->addError('currentPassword', 'Current password is required.');
            return;
        }
        if ($this->newPassword === '') {
            $this->addError('newPassword', 'New password is required.');
            return;
        }
        if ($this->newPasswordConfirmation === '') {
            $this->addError('newPasswordConfirmation', 'Please confirm the new password.');
            return;
        }
        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->addError('newPasswordConfirmation', 'Passwords do not match.');
            return;
        }

        /** @var \App\Models\User $user */
        $user   = Auth::user();
        $result = $service->change($user, $this->currentPassword, $this->newPassword);

        if (!$result['ok']) {
            $this->changeErrors    = $result['errors'];
            $this->currentPassword = '';
            return;
        }

        // Clear sensitive field values before redirect
        $this->currentPassword         = '';
        $this->newPassword             = '';
        $this->newPasswordConfirmation = '';
        $this->success                 = true;

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.password-change', [
            'forced' => $this->isForced(),
        ]);
    }
}
