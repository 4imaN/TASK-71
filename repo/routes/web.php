<?php

use App\Http\Livewire\Admin\AuditLogComponent;
use App\Http\Livewire\Admin\BackupComponent;
use App\Http\Livewire\Admin\DataDictionaryComponent;
use App\Http\Livewire\Admin\FormRulesComponent;
use App\Http\Livewire\Admin\ImportJobDetailComponent;
use App\Http\Livewire\Admin\ImportJobListComponent;
use App\Http\Livewire\Admin\PolicyConfigComponent;
use App\Http\Livewire\Admin\RelationshipManagerComponent;
use App\Http\Livewire\Admin\UserManagementComponent;
use App\Http\Livewire\Auth\LoginComponent;
use App\Http\Livewire\Auth\PasswordChangeComponent;
use App\Http\Livewire\Catalog\BrowseComponent as CatalogBrowseComponent;
use App\Http\Livewire\Catalog\ServiceDetailComponent;
use App\Http\Livewire\Dashboard\LearnerDashboardComponent;
use App\Http\Livewire\Editor\PendingConfirmationsComponent as EditorPendingConfirmationsComponent;
use App\Http\Livewire\Editor\ServiceFormComponent as EditorServiceFormComponent;
use App\Http\Livewire\Editor\ServiceListComponent as EditorServiceListComponent;
use App\Http\Livewire\Editor\SlotListComponent as EditorSlotListComponent;
use App\Http\Livewire\Reservation\ReservationDetailComponent;
use App\Http\Livewire\Reservation\ReservationListComponent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ── Auth routes ───────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', LoginComponent::class)->name('login');
});

Route::post('/logout', function () {
    /** @var \App\Services\Auth\SessionManager $sessionManager */
    $sessionManager = app(\App\Services\Auth\SessionManager::class);
    $user = Auth::user();

    if ($user) {
        $sessionManager->revokeAllSessions($user, reason: 'logout');
    }

    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Password change — handles voluntary, forced (must_change_password), and rotation-expired flows.
// Intentionally placed OUTSIDE the EnforcePasswordChange middleware group so this route is always
// reachable — the middleware redirects TO here but must never block access to it.
Route::get('/password/change', PasswordChangeComponent::class)
    ->middleware('auth')
    ->name('password.change');

// ── Authenticated application routes ─────────────────────────────────────────
// EnforcePasswordChange runs on every request in this group and redirects to
// /password/change when must_change_password=true or rotation has expired.
Route::middleware(['auth', \App\Http\Middleware\ValidateAppSession::class, \App\Http\Middleware\EnforcePasswordChange::class])->group(function () {

    Route::get('/', function () {
        return redirect()->route('dashboard');
    });

    Route::get('/dashboard', LearnerDashboardComponent::class)->name('dashboard');

    // Catalog
    Route::get('/catalog', CatalogBrowseComponent::class)->name('catalog.index');
    Route::get('/catalog/{slug}', ServiceDetailComponent::class)->name('catalog.show');

    // Reservations — learner self-management
    Route::get('/reservations',         ReservationListComponent::class)->name('reservations.index');
    Route::get('/reservations/{uuid}',  ReservationDetailComponent::class)->name('reservations.show');

    // Editor routes
    Route::middleware('role:content_editor|administrator')->prefix('editor')->name('editor.')->group(function () {
        Route::get('/services',             EditorServiceListComponent::class)->name('services.index');
        Route::get('/services/create',      EditorServiceFormComponent::class)->name('services.create');
        Route::get('/services/{serviceId}', EditorServiceFormComponent::class)->name('services.edit');
        Route::get('/services/{serviceId}/slots', EditorSlotListComponent::class)->name('services.slots');
        // Operator confirmation queue for manual-confirm reservations
        Route::get('/pending', EditorPendingConfirmationsComponent::class)->name('pending.index');
    });

    // Admin routes
    Route::middleware('role:administrator')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users',           UserManagementComponent::class)->name('users.index');
        Route::get('/policies',        PolicyConfigComponent::class)->name('policies.index');
        Route::get('/data-dictionary', DataDictionaryComponent::class)->name('data-dictionary.index');
        Route::get('/form-rules',      FormRulesComponent::class)->name('form-rules.index');
        Route::get('/audit-logs',      AuditLogComponent::class)->name('audit-logs.index');
        Route::get('/import-export',         ImportJobListComponent::class)->name('import-export.index');
        Route::get('/import-export/{jobId}', ImportJobDetailComponent::class)->name('import-export.show');
        Route::get('/backups',         BackupComponent::class)->name('backups.index');
        Route::get('/relationships',   RelationshipManagerComponent::class)->name('relationships.index');
    });
});
