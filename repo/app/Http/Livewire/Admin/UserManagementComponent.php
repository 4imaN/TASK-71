<?php

namespace App\Http\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\StepUpService;
use App\Services\Admin\UserGovernanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class UserManagementComponent extends Component
{
    use WithPagination;

    // ── Filters ───────────────────────────────────────────────────────────────
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $roleFilter = '';

    // ── Step-up ───────────────────────────────────────────────────────────────
    public bool   $showStepUp     = false;
    public string $stepUpPassword = '';
    public string $stepUpError    = '';

    /** Action queued behind step-up: [method, ...args] */
    private array $pendingAction  = [];

    // ── Detail panel ──────────────────────────────────────────────────────────
    public ?int   $detailUserId   = null;

    // ── Delete confirmation ───────────────────────────────────────────────────
    public bool   $showDeleteConfirm   = false;
    public int    $deleteConfirmUserId = 0;
    public string $deleteConfirmName   = '';

    // ── Set password form ─────────────────────────────────────────────────────
    public bool   $showSetPasswordForm     = false;
    public int    $setPasswordUserId       = 0;
    public string $setPasswordValue        = '';
    public string $setPasswordConfirmation = '';
    public string $setPasswordError        = '';

    // ── Role assignment form ──────────────────────────────────────────────────
    public bool   $showRoleForm   = false;
    public int    $roleFormUserId = 0;
    public string $roleFormAction = 'assign'; // 'assign' | 'revoke'
    public string $roleFormRole   = '';

    // ── Flash ─────────────────────────────────────────────────────────────────
    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function updatedSearch(): void      { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedRoleFilter(): void  { $this->resetPage(); }

    public function render(UserGovernanceService $service): \Illuminate\View\View
    {
        $filters = array_filter([
            'search' => $this->search,
            'status' => $this->statusFilter,
            'role'   => $this->roleFilter,
        ]);

        return view('livewire.admin.user-management', [
            'users'        => $service->list($filters, perPage: 20),
            'detailUser'   => $this->detailUserId
                ? $service->show($this->detailUserId)
                : null,
            'stepUpOk'     => app(StepUpService::class)->isGranted(),
            'allRoles'     => ['learner', 'content_editor', 'administrator'],
        ]);
    }

    // ── Detail panel ──────────────────────────────────────────────────────────

    public function openDetail(int $userId): void
    {
        $this->detailUserId = $userId;
    }

    public function closeDetail(): void
    {
        $this->detailUserId = null;
    }

    // ── Step-up plumbing ──────────────────────────────────────────────────────

    private function requireStepUp(string $method, mixed ...$args): bool
    {
        if (app(StepUpService::class)->isGranted()) {
            return false;
        }

        $this->pendingAction  = [$method, ...$args];
        $this->showStepUp     = true;
        $this->stepUpPassword = '';
        $this->stepUpError    = '';

        return true; // caller should return early
    }

    public function verifyStepUp(): void
    {
        $ok = app(StepUpService::class)->verify(Auth::user(), $this->stepUpPassword);

        if (!$ok) {
            $this->stepUpError    = 'Incorrect password.';
            $this->stepUpPassword = '';
            return;
        }

        $this->showStepUp     = false;
        $this->stepUpPassword = '';
        $this->stepUpError    = '';

        if (!empty($this->pendingAction)) {
            $method = array_shift($this->pendingAction);
            $this->$method(...$this->pendingAction);
            $this->pendingAction = [];
        }
    }

    public function cancelStepUp(): void
    {
        $this->showStepUp    = false;
        $this->pendingAction = [];
        $this->stepUpError   = '';
    }

    // ── Governance actions (all require step-up) ──────────────────────────────

    public function lockUser(int $userId): void
    {
        if ($this->requireStepUp('lockUser', $userId)) {
            return;
        }

        $this->withUser($userId, function (User $target, UserGovernanceService $svc) {
            $svc->lockAccount($target, Auth::user());
            $this->flash("{$target->username} locked for 24 hours.", 'success');
        });
    }

    public function unlockUser(int $userId): void
    {
        if ($this->requireStepUp('unlockUser', $userId)) {
            return;
        }

        $this->withUser($userId, function (User $target, UserGovernanceService $svc) {
            $svc->unlockAccount($target, Auth::user());
            $this->flash("{$target->username} unlocked.", 'success');
        });
    }

    public function suspendUser(int $userId): void
    {
        if ($this->requireStepUp('suspendUser', $userId)) {
            return;
        }

        $this->withUser($userId, function (User $target, UserGovernanceService $svc) {
            $svc->suspendAccount($target, Auth::user());
            $this->flash("{$target->username} suspended.", 'success');
        });
    }

    public function reactivateUser(int $userId): void
    {
        if ($this->requireStepUp('reactivateUser', $userId)) {
            return;
        }

        $this->withUser($userId, function (User $target, UserGovernanceService $svc) {
            $svc->reactivateAccount($target, Auth::user());
            $this->flash("{$target->username} reactivated.", 'success');
        });
    }

    public function forcePasswordReset(int $userId): void
    {
        if ($this->requireStepUp('forcePasswordReset', $userId)) {
            return;
        }

        $this->withUser($userId, function (User $target, UserGovernanceService $svc) {
            $svc->forcePasswordReset($target, Auth::user());
            $this->flash("{$target->username} will be required to change password on next login.", 'success');
        });
    }

    public function revokeSessions(int $userId): void
    {
        if ($this->requireStepUp('revokeSessions', $userId)) {
            return;
        }

        $this->withUser($userId, function (User $target, UserGovernanceService $svc) {
            $count = $svc->revokeSessions($target, Auth::user());
            $this->flash("Revoked {$count} session(s) for {$target->username}.", 'success');
        });
    }

    // ── Account deletion ──────────────────────────────────────────────────────

    /**
     * Open the deletion confirmation modal for a specific user.
     * Step-up is not checked yet; it fires when the admin actually confirms.
     */
    public function openDeleteConfirm(int $userId): void
    {
        $user = User::withTrashed()->findOrFail($userId);

        $this->deleteConfirmUserId = $userId;
        $this->deleteConfirmName   = $user->username;
        $this->showDeleteConfirm   = true;
    }

    public function closeDeleteConfirm(): void
    {
        $this->showDeleteConfirm   = false;
        $this->deleteConfirmUserId = 0;
        $this->deleteConfirmName   = '';
    }

    /**
     * Admin confirmed the deletion modal — now enforce step-up before proceeding.
     * If step-up fires the pending action queue will resume with deleteUserConfirmed().
     */
    public function confirmDelete(): void
    {
        $userId = $this->deleteConfirmUserId;
        $this->showDeleteConfirm = false;

        if ($this->requireStepUp('deleteUserConfirmed', $userId)) {
            return;
        }

        $this->deleteUserConfirmed($userId);
    }

    public function deleteUserConfirmed(int $userId): void
    {
        $this->withUser($userId, function (User $target, UserGovernanceService $svc) {
            try {
                $svc->deleteAccount($target, Auth::user());
                $this->flash("{$target->username} has been deleted.", 'success');
                $this->detailUserId = null;
            } catch (\RuntimeException $e) {
                $this->flash($e->getMessage(), 'error');
            }
        });
    }

    // ── Set initial password ─────────────────────────────────────────────────

    public function openSetPasswordForm(int $userId): void
    {
        $this->setPasswordUserId       = $userId;
        $this->setPasswordValue        = '';
        $this->setPasswordConfirmation = '';
        $this->setPasswordError        = '';
        $this->showSetPasswordForm     = true;
    }

    public function closeSetPasswordForm(): void
    {
        $this->showSetPasswordForm = false;
    }

    public function submitSetPassword(): void
    {
        if ($this->setPasswordValue !== $this->setPasswordConfirmation) {
            $this->setPasswordError = 'Passwords do not match.';
            return;
        }

        if ($this->requireStepUp('submitSetPasswordConfirmed')) {
            return;
        }

        $this->submitSetPasswordConfirmed();
    }

    public function submitSetPasswordConfirmed(): void
    {
        $this->showSetPasswordForm = false;

        $this->withUser($this->setPasswordUserId, function (User $target, UserGovernanceService $svc) {
            $result = $svc->setInitialPassword($target, $this->setPasswordValue, Auth::user());

            if (!$result['ok']) {
                $this->flash(implode(' ', $result['errors']), 'error');
                return;
            }

            $this->flash("Initial password set for {$target->username}. User must change it on next login.", 'success');
        });

        $this->setPasswordValue        = '';
        $this->setPasswordConfirmation = '';
    }

    // ── Role form ─────────────────────────────────────────────────────────────

    public function openRoleForm(int $userId, string $action): void
    {
        $this->roleFormUserId = $userId;
        $this->roleFormAction = $action;
        $this->roleFormRole   = '';
        $this->showRoleForm   = true;
    }

    public function closeRoleForm(): void
    {
        $this->showRoleForm = false;
    }

    public function submitRoleForm(): void
    {
        if (empty($this->roleFormRole)) {
            $this->flash('Please select a role.', 'error');
            return;
        }

        if ($this->requireStepUp('submitRoleFormConfirmed')) {
            return;
        }

        $this->submitRoleFormConfirmed();
    }

    public function submitRoleFormConfirmed(): void
    {
        $this->showRoleForm = false;

        $this->withUser($this->roleFormUserId, function (User $target, UserGovernanceService $svc) {
            try {
                if ($this->roleFormAction === 'assign') {
                    $svc->assignRole($target, $this->roleFormRole, Auth::user());
                    $this->flash("Role '{$this->roleFormRole}' assigned to {$target->username}.", 'success');
                } else {
                    $svc->revokeRole($target, $this->roleFormRole, Auth::user());
                    $this->flash("Role '{$this->roleFormRole}' revoked from {$target->username}.", 'success');
                }
            } catch (\RuntimeException $e) {
                $this->flash($e->getMessage(), 'error');
            }
        });
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function withUser(int $userId, callable $fn): void
    {
        try {
            $target  = User::withTrashed()->findOrFail($userId);
            $service = app(UserGovernanceService::class);
            $fn($target, $service);
            $this->resetPage();
        } catch (\Throwable $e) {
            $this->flash('Error: ' . $e->getMessage(), 'error');
        }
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType    = $type;
    }
}
