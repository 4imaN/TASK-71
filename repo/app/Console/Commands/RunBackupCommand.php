<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\BackupService;
use Illuminate\Console\Command;

/**
 * Artisan command to execute a scheduled or ad-hoc backup.
 *
 * The command must be invoked with an admin user ID so the backup is
 * attributed to a real actor in the audit log.  For scheduled daily
 * runs, pass the system admin user's ID (seeded by AdminUserSeeder).
 *
 * Usage:
 *   php artisan backups:run --type=daily  --actor=1
 *   php artisan backups:run               --actor=1    (defaults to manual)
 */
class RunBackupCommand extends Command
{
    protected $signature = 'backups:run
                            {--type=daily  : Backup type: daily or manual}
                            {--actor=      : User ID to attribute this run to in the audit log}';

    protected $description = 'Execute a database backup snapshot and apply 30-day retention';

    public function handle(BackupService $service): int
    {
        $type    = $this->option('type') === 'manual' ? 'manual' : 'daily';
        $actorId = (int) $this->option('actor');

        // Resolve actor — fall back to the first administrator if none given
        $actor = $actorId
            ? User::find($actorId)
            : User::role('administrator')->orderBy('id')->first();

        if (!$actor) {
            $this->error(
                'No actor resolved.  Pass --actor=<user_id> or ensure at least one ' .
                'administrator account exists in the database.'
            );
            return self::FAILURE;
        }

        $this->line("Running {$type} backup as user #{$actor->id} ({$actor->username})…");

        $log = $service->run($actor, $type);

        if ($log->status === 'success') {
            $humanSize = $this->humanBytes($log->file_size_bytes);
            $this->info("Backup completed: {$log->snapshot_filename} ({$humanSize})");
            return self::SUCCESS;
        }

        $this->error("Backup failed: {$log->error_message}");
        return self::FAILURE;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 1) . ' ' . ($units[$i] ?? 'B');
    }
}
