<?php

namespace App\Http\Livewire\Admin;

use App\Services\Admin\StepUpService;
use App\Services\Api\BackupApiGateway;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin backup management page.
 *
 * Delegates to BackupApiGateway — the same API contract consumed by
 * GET /api/v1/admin/backups, POST /api/v1/admin/backups, and
 * POST /api/v1/admin/backups/{id}/restore-tests — so this Livewire
 * component is a client of the REST API layer rather than calling
 * domain services directly.
 *
 * Step-up verification is handled at the component level (same as the
 * REST controller checks step-up before invoking the gateway).
 */
#[Layout('layouts.app')]
class BackupComponent extends Component
{
    use WithPagination;

    // ── Step-up ───────────────────────────────────────────────────────────────
    public bool   $showStepUp      = false;
    public string $stepUpPassword  = '';
    public string $stepUpError     = '';

    // ── Restore-test modal ────────────────────────────────────────────────────
    public bool   $showRestoreForm  = false;
    public ?int   $restoreBackupId  = null;
    public string $restoreResult    = 'success';
    public string $restoreNotes     = '';

    // ── Flash ─────────────────────────────────────────────────────────────────
    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function render(BackupApiGateway $gateway): \Illuminate\View\View
    {
        return view('livewire.admin.backup', [
            'backups'    => $gateway->list(perPage: 15),
            'stepUpOk'   => app(StepUpService::class)->isGranted(),
            'retention'  => $gateway->retentionDays(),
        ]);
    }

    // ── Trigger backup ────────────────────────────────────────────────────────

    /**
     * First click: open step-up prompt if grant has expired.
     * Subsequent clicks (grant still valid): execute immediately.
     */
    public function initiateTrigger(): void
    {
        if (app(StepUpService::class)->isGranted()) {
            $this->executeBackup();
        } else {
            $this->showStepUp     = true;
            $this->stepUpError    = '';
            $this->stepUpPassword = '';
        }
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
        $this->executeBackup();
    }

    public function cancelStepUp(): void
    {
        $this->showStepUp     = false;
        $this->stepUpPassword = '';
        $this->stepUpError    = '';
    }

    /**
     * Execute backup via BackupApiGateway — the same API contract
     * consumed by POST /api/v1/admin/backups.
     */
    private function executeBackup(): void
    {
        $result = app(BackupApiGateway::class)->run(Auth::user(), 'manual');

        if ($result->success) {
            $this->flash('Backup completed: ' . $result->data->snapshot_filename, 'success');
        } else {
            $this->flash($result->error, 'error');
        }

        $this->resetPage();
    }

    // ── Restore-test recording ────────────────────────────────────────────────

    public function openRestoreTestForm(int $backupLogId): void
    {
        $this->restoreBackupId = $backupLogId;
        $this->restoreResult   = 'success';
        $this->restoreNotes    = '';
        $this->showRestoreForm = true;
    }

    public function cancelRestoreTestForm(): void
    {
        $this->showRestoreForm = false;
        $this->restoreBackupId = null;
    }

    /**
     * Submit restore test via BackupApiGateway — the same API contract
     * consumed by POST /api/v1/admin/backups/{id}/restore-tests.
     */
    public function submitRestoreTest(BackupApiGateway $gateway): void
    {
        $this->validate([
            'restoreResult' => ['required', 'in:success,partial,failed'],
            'restoreNotes'  => ['nullable', 'string', 'max:2000'],
        ]);

        if (!$this->restoreBackupId) {
            $this->flash('No backup selected.', 'error');
            return;
        }

        $result = $gateway->recordRestoreTest(
            backupLogId: $this->restoreBackupId,
            tester:      Auth::user(),
            result:      $this->restoreResult,
            notes:       $this->restoreNotes ?: null,
        );

        if (!$result->success) {
            $this->flash($result->error, 'error');
            $this->showRestoreForm = false;
            return;
        }

        $this->showRestoreForm = false;
        $this->restoreBackupId = null;
        $this->flash('Restore test recorded.', 'success');
        $this->resetPage();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType    = $type;
    }
}
