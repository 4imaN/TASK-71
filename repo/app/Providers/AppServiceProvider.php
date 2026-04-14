<?php

namespace App\Providers;

use App\Services\Admin\DynamicValidationResolver;
use App\Services\Admin\SystemConfigService;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\SensitiveDataRedactor;
use App\Services\Auth\CaptchaService;
use App\Services\Auth\PasswordValidator;
use App\Services\Auth\SessionManager;
use App\Services\Reservation\ReservationService;
use App\Services\Reservation\SlotAvailabilityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Audit layer
        $this->app->singleton(SensitiveDataRedactor::class);
        $this->app->singleton(AuditLogger::class);

        // Admin config
        $this->app->singleton(SystemConfigService::class);
        $this->app->singleton(DynamicValidationResolver::class);

        // Auth services — pick up config values from SystemConfigService at resolve time
        $this->app->singleton(CaptchaService::class);

        $this->app->singleton(PasswordValidator::class, function ($app) {
            $cfg = $app->make(SystemConfigService::class);
            return new PasswordValidator(
                minLength:    $cfg->passwordMinLength(),
                historyCount: $cfg->passwordHistoryCount(),
            );
        });

        $this->app->singleton(SessionManager::class, function ($app) {
            $cfg = $app->make(SystemConfigService::class);
            return new SessionManager(
                auditLogger:        $app->make(AuditLogger::class),
                config:             $cfg,
                idleTimeoutMinutes: $cfg->idleTimeoutMinutes(),
            );
        });

        // Reservation layer
        $this->app->singleton(SlotAvailabilityService::class);
        $this->app->singleton(ReservationService::class);
    }

    public function boot(): void
    {
        // Prevent lazy loading in production to surface N+1 issues early
        Model::preventLazyLoading(! app()->isProduction());
    }
}
