<?php

use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\BackupController as AdminBackupController;
use App\Http\Controllers\Api\V1\Admin\ConfigController as AdminConfigController;
use App\Http\Controllers\Api\V1\Admin\DictionaryController as AdminDictionaryController;
use App\Http\Controllers\Api\V1\Admin\ExportController as AdminExportController;
use App\Http\Controllers\Api\V1\Admin\FormRuleController as AdminFormRuleController;
use App\Http\Controllers\Api\V1\Admin\ImportController as AdminImportController;
use App\Http\Controllers\Api\V1\Admin\RelationshipController as AdminRelationshipController;
use App\Http\Controllers\Api\V1\Admin\StepUpController as AdminStepUpController;
use App\Http\Controllers\Api\V1\Auth\PasswordChangeController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\Editor\ReservationController as EditorReservationController;
use App\Http\Controllers\Api\V1\Editor\ServiceController as EditorServiceController;
use App\Http\Controllers\Api\V1\Editor\SlotController as EditorSlotController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnforcePasswordChange;
use App\Http\Middleware\ValidateAppSession;
use Illuminate\Support\Facades\Route;

/*
 * REST-style API endpoints — parallel surface to Livewire components.
 * All business logic lives in domain services shared by both surfaces.
 *
 * Authentication: session-based (same session as Livewire).
 * All authenticated endpoints require 'auth' + ValidateAppSession +
 * EnforcePasswordChange — the same controls applied on the web surface.
 * Both middleware return JSON 401/403 responses for API callers.
 */

// ── Health (public) ───────────────────────────────────────────────────────────
Route::get('/health', HealthController::class)->name('api.health');

// ── API v1 ────────────────────────────────────────────────────────────────────
Route::prefix('v1')->name('api.v1.')->group(function () {

    // Catalog (public browsing — eligibility enforced at booking)
    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('/services',       [CatalogController::class, 'index'])->name('services.index');
        Route::get('/services/{slug}', [CatalogController::class, 'show'])->name('services.show');
    });

    // Authenticated endpoints
    // ValidateAppSession: enforces revocation and idle-timeout (returns 401 JSON on failure).
    // EnforcePasswordChange: blocks access when must_change_password=true or rotation expired
    //   (returns 403 JSON); the password-change route is excluded from this group so the
    //   API client can POST to it regardless of password-change state.
    Route::middleware(['auth', ValidateAppSession::class])->group(function () {

        // Auth — password change (voluntary, forced, rotation-expired).
        // Intentionally OUTSIDE EnforcePasswordChange so callers whose passwords
        // have expired can still reach this endpoint.
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('/password/change', [PasswordChangeController::class, 'change'])->name('password.change');
        });

        // All remaining authenticated endpoints also enforce password-change policy.
        Route::middleware(EnforcePasswordChange::class)->group(function () {

        // Reservations — learner lifecycle (create, list, detail, cancel, reschedule)
        Route::prefix('reservations')->name('reservations.')->group(function () {
            Route::get('/',                   [ReservationController::class, 'index'])->name('index');
            Route::post('/',                  [ReservationController::class, 'store'])->name('store');
            Route::get('/{uuid}',             [ReservationController::class, 'show'])->name('show');
            Route::post('/{uuid}/cancel',     [ReservationController::class, 'cancel'])->name('cancel');
            Route::post('/{uuid}/reschedule', [ReservationController::class, 'reschedule'])->name('reschedule');
            Route::post('/{uuid}/check-in',   [ReservationController::class, 'checkIn'])->name('check-in');
            Route::post('/{uuid}/check-out',  [ReservationController::class, 'checkOut'])->name('check-out');
        });

        // User dashboard, favorites, recent views
        Route::prefix('user')->name('user.')->group(function () {
            Route::get('/dashboard',                         [UserController::class, 'dashboard'])->name('dashboard');
            Route::get('/favorites',                         [UserController::class, 'favorites'])->name('favorites');
            Route::post('/favorites/{service_id}',           [UserController::class, 'addFavorite'])->name('favorites.store');
            Route::delete('/favorites/{service_id}',         [UserController::class, 'removeFavorite'])->name('favorites.destroy');
            Route::get('/recent-views',                      [UserController::class, 'recentViews'])->name('recent-views');
        });

        // Admin endpoints
        Route::middleware('role:administrator')->prefix('admin')->name('admin.')->group(function () {
            // Step-up verification
            Route::post('/step-up', [AdminStepUpController::class, 'verify'])->name('step-up');
            // System config
            Route::get('/system-config',       [AdminConfigController::class, 'index'])->name('system-config.index');
            Route::put('/system-config/{key}', [AdminConfigController::class, 'update'])->name('system-config.update');
            // Data dictionary
            Route::get('/data-dictionary',                     [AdminDictionaryController::class, 'index'])->name('data-dictionary.index');
            Route::post('/data-dictionary/{typeCode}/values',  [AdminDictionaryController::class, 'storeValue'])->name('data-dictionary.values.store');
            Route::put('/data-dictionary/values/{id}',         [AdminDictionaryController::class, 'updateValue'])->name('data-dictionary.values.update');
            // Form rules
            Route::get('/form-rules',          [AdminFormRuleController::class, 'index'])->name('form-rules.index');
            Route::post('/form-rules',         [AdminFormRuleController::class, 'store'])->name('form-rules.store');
            Route::put('/form-rules/{id}',     [AdminFormRuleController::class, 'update'])->name('form-rules.update');
            Route::delete('/form-rules/{id}',  [AdminFormRuleController::class, 'destroy'])->name('form-rules.destroy');
            // Audit logs
            Route::get('/audit-logs',                           [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('/audit-logs/{id}',                      [AdminAuditLogController::class, 'show'])->name('audit-logs.show');
            Route::get('/audit-logs/correlation/{correlationId}', [AdminAuditLogController::class, 'byCorrelation'])->name('audit-logs.correlation');
            // User governance
            Route::get('/users',                                [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/{id}',                           [AdminUserController::class, 'show'])->name('users.show');
            Route::post('/users/{id}/lock',                     [AdminUserController::class, 'lock'])->name('users.lock');
            Route::post('/users/{id}/unlock',                   [AdminUserController::class, 'unlock'])->name('users.unlock');
            Route::post('/users/{id}/suspend',                  [AdminUserController::class, 'suspend'])->name('users.suspend');
            Route::post('/users/{id}/reactivate',               [AdminUserController::class, 'reactivate'])->name('users.reactivate');
            Route::post('/users/{id}/force-password-reset',     [AdminUserController::class, 'forcePasswordReset'])->name('users.force-password-reset');
            Route::post('/users/{id}/set-password',              [AdminUserController::class, 'setPassword'])->name('users.set-password');
            Route::post('/users/{id}/revoke-sessions',          [AdminUserController::class, 'revokeSessions'])->name('users.revoke-sessions');
            Route::delete('/users/{id}',                        [AdminUserController::class, 'destroy'])->name('users.destroy');
            Route::post('/users/{id}/roles',                    [AdminUserController::class, 'assignRole'])->name('users.roles.assign');
            Route::delete('/users/{id}/roles/{role}',           [AdminUserController::class, 'revokeRole'])->name('users.roles.revoke');
            // Backups
            Route::get('/backups',                              [AdminBackupController::class, 'index'])->name('backups.index');
            Route::post('/backups',                             [AdminBackupController::class, 'store'])->name('backups.store');
            Route::get('/backups/{id}',                         [AdminBackupController::class, 'show'])->name('backups.show');
            Route::post('/backups/{id}/restore-tests',          [AdminBackupController::class, 'storeRestoreTest'])->name('backups.restore-tests.store');

            // Import routes
            Route::get('/import/templates',         [AdminImportController::class, 'listTemplates'])->name('import.templates.index');
            Route::post('/import/templates',         [AdminImportController::class, 'createTemplate'])->name('import.templates.store');
            Route::get('/import',                    [AdminImportController::class, 'index'])->name('import.index');
            Route::post('/import',                   [AdminImportController::class, 'store'])->name('import.store');
            Route::get('/import/{id}',               [AdminImportController::class, 'show'])->name('import.show');
            Route::post('/import/{id}/resolve',      [AdminImportController::class, 'resolveConflict'])->name('import.resolve');
            Route::post('/import/{id}/reprocess',   [AdminImportController::class, 'reprocess'])->name('import.reprocess');

            // Export route (step-up required inside controller)
            Route::post('/export', [AdminExportController::class, 'generate'])->name('export');

            // Relationship definitions + instances
            Route::prefix('relationship-definitions')->name('relationship-definitions.')->group(function () {
                Route::get('/',                           [AdminRelationshipController::class, 'index'])->name('index');
                Route::post('/',                          [AdminRelationshipController::class, 'store'])->name('store');
                Route::delete('/{id}',                    [AdminRelationshipController::class, 'destroy'])->name('destroy');
                Route::get('/{id}/instances',             [AdminRelationshipController::class, 'listInstances'])->name('instances.index');
                Route::post('/{id}/instances',            [AdminRelationshipController::class, 'storeInstance'])->name('instances.store');
                Route::delete('/{id}/instances/{iid}',    [AdminRelationshipController::class, 'destroyInstance'])->name('instances.destroy');
            });
        });

        // Editor endpoints
        Route::middleware('role:content_editor|administrator')->prefix('editor')->name('editor.')->group(function () {
            Route::get('/services',                        [EditorServiceController::class, 'index'])->name('services.index');
            Route::post('/services',                       [EditorServiceController::class, 'store'])->name('services.store');
            Route::get('/services/{id}',                   [EditorServiceController::class, 'show'])->name('services.show');
            Route::put('/services/{id}',                   [EditorServiceController::class, 'update'])->name('services.update');
            Route::post('/services/{id}/publish',          [EditorServiceController::class, 'publish'])->name('services.publish');
            Route::post('/services/{id}/archive',          [EditorServiceController::class, 'archive'])->name('services.archive');
            // Service ↔ research-project relationship management
            Route::get('/services/{id}/research-projects',              [EditorServiceController::class, 'listResearchProjects'])->name('services.research-projects.index');
            Route::post('/services/{id}/research-projects',             [EditorServiceController::class, 'attachResearchProjects'])->name('services.research-projects.attach');
            Route::delete('/services/{id}/research-projects/{projectId}', [EditorServiceController::class, 'detachResearchProject'])->name('services.research-projects.detach');
            Route::get('/services/{serviceId}/slots',      [EditorSlotController::class, 'index'])->name('slots.index');
            Route::post('/services/{serviceId}/slots',     [EditorSlotController::class, 'store'])->name('slots.store');
            Route::put('/services/{serviceId}/slots/{slotId}',    [EditorSlotController::class, 'update'])->name('slots.update');
            Route::post('/services/{serviceId}/slots/{slotId}/cancel', [EditorSlotController::class, 'cancel'])->name('slots.cancel');
            // Operator reservation queue — confirm / reject pending manual-review reservations.
            // Mirrors the Livewire PendingConfirmationsComponent on the REST surface.
            Route::get('/reservations',                    [EditorReservationController::class, 'index'])->name('reservations.index');
            Route::post('/reservations/{id}/confirm',      [EditorReservationController::class, 'confirm'])->name('reservations.confirm');
            Route::post('/reservations/{id}/reject',       [EditorReservationController::class, 'reject'])->name('reservations.reject');
        });

        }); // end EnforcePasswordChange group
    }); // end auth + ValidateAppSession group
});
