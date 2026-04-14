<?php

namespace App\Services\Admin;

use App\Models\BackupLog;
use App\Models\RestoreTestLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Operator-facing backup service.
 *
 * Execution reality:
 *   - PostgreSQL (production/Docker): runs pg_dump --format=custom piped
 *     through gzip.  Requires pg_dump on $PATH and PGPASSWORD or .pgpass.
 *   - SQLite file (local dev): copies the .sqlite file.
 *   - SQLite :memory: (test suite): writes a zero-byte placeholder so the
 *     workflow is fully exercisable without a real dump command.
 *
 * All outcomes (success and failed) are recorded in backup_logs and
 * written to the audit log.  30-day snapshot retention is applied
 * automatically after every successful run.
 */
class BackupService
{
    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Execute a backup synchronously and return the BackupLog record.
     *
     * @param User   $admin  Acting administrator (null actor for scheduled runs
     *                       is handled by passing a system-flag user; callers
     *                       that have no interactive user should pass the
     *                       system admin account).
     * @param string $type   'manual' | 'daily'
     */
    public function run(User $admin, string $type = 'manual'): BackupLog
    {
        $backupDir = $this->backupDirectory();
        $this->ensureDirectory($backupDir);

        $filename = 'backup_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));

        $driver    = config('database.default');
        $dbConfig  = config("database.connections.{$driver}", []);

        $path      = '';
        $sizeBytes = 0;
        $status    = 'success';
        $error     = null;

        try {
            [$path, $sizeBytes] = $this->executeDump($filename, $backupDir, $driver, $dbConfig);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error  = $e->getMessage();
            // Remove any partial file left by a failed dump
            if ($path && file_exists($path)) {
                @unlink($path);
                $path = '';
            }
        }

        $log = BackupLog::create([
            'snapshot_filename' => $path ? basename($path) : "{$filename}.failed",
            'snapshot_path'     => $path,
            'file_size_bytes'   => $sizeBytes,
            'type'              => $type,
            'status'            => $status,
            'error_message'     => $error,
        ]);

        $this->auditLogger->log(
            action:     $status === 'success' ? 'backup.completed' : 'backup.failed',
            actorId:    $admin->id,
            entityType: 'backup_log',
            entityId:   $log->id,
            afterState: $log->toArray(),
        );

        if ($status === 'success') {
            $this->applyRetention();
        }

        return $log;
    }

    /**
     * Delete backup_log records (and their files) older than RETENTION_DAYS.
     * Failed backup records are also pruned to avoid clutter.
     *
     * Returns the number of snapshots pruned.
     */
    public function applyRetention(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $old = BackupLog::where('created_at', '<', $cutoff)->get();

        $count = 0;
        foreach ($old as $log) {
            if ($log->snapshot_path && file_exists($log->snapshot_path)) {
                @unlink($log->snapshot_path);
            }
            $log->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Paginated list of backup logs, newest first, with restore-test counts.
     */
    public function list(int $perPage = 20): LengthAwarePaginator
    {
        return BackupLog::withCount('restoreTests')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Record a restore-test drill result against a specific backup snapshot.
     *
     * @param string $result  'success' | 'partial' | 'failed'
     */
    public function recordRestoreTest(
        BackupLog $backup,
        User      $tester,
        string    $result,
        ?string   $notes = null
    ): RestoreTestLog {
        $restoreTest = RestoreTestLog::create([
            'backup_log_id' => $backup->id,
            'tested_by'     => $tester->id,
            'test_result'   => $result,
            'notes'         => $notes,
            'tested_at'     => now(),
        ]);

        $this->auditLogger->log(
            action:     'backup.restore_test_recorded',
            actorId:    $tester->id,
            entityType: 'restore_test_log',
            entityId:   $restoreTest->id,
            afterState: [
                'backup_log_id' => $backup->id,
                'test_result'   => $result,
                'tested_at'     => $restoreTest->tested_at->toIso8601String(),
            ],
        );

        return $restoreTest;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Perform the actual dump for the current database driver.
     *
     * @return array{string, int}  [absolute path to file, byte size]
     */
    protected function executeDump(
        string $filename,
        string $backupDir,
        string $driver,
        array  $dbConfig
    ): array {
        if ($driver === 'pgsql') {
            return $this->dumpPostgres($filename, $backupDir, $dbConfig);
        }

        if ($driver === 'sqlite') {
            return $this->dumpSqlite($filename, $backupDir, $dbConfig);
        }

        throw new \RuntimeException(
            "Backup execution is not implemented for database driver '{$driver}'."
        );
    }

    /**
     * pg_dump --format=custom | gzip → backup_YYYYMMDD_HHmmss_xxxxxx.dump.gz
     */
    private function dumpPostgres(string $filename, string $backupDir, array $dbConfig): array
    {
        $path = $backupDir . '/' . $filename . '.dump.gz';

        $host     = escapeshellarg($dbConfig['host'] ?? 'localhost');
        $port     = (int) ($dbConfig['port'] ?? 5432);
        $database = escapeshellarg($dbConfig['database']);
        $username = escapeshellarg($dbConfig['username']);
        $password = $dbConfig['password'] ?? '';

        $envPrefix = $password !== '' ? 'PGPASSWORD=' . escapeshellarg($password) . ' ' : '';

        $cmd = "{$envPrefix}pg_dump -h {$host} -p {$port} -U {$username} --format=custom {$database}"
             . " | gzip > " . escapeshellarg($path);

        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'pg_dump exited with code ' . $exitCode . ': ' . implode(' ', $output)
            );
        }

        $sizeBytes = file_exists($path) ? filesize($path) : 0;

        return [$path, $sizeBytes];
    }

    /**
     * SQLite: copy the database file, or write a zero-byte placeholder for
     * in-memory databases used in the test suite.
     *
     * The placeholder approach is documented explicitly so operators are not
     * misled: in-memory test databases have no persistent state to back up.
     */
    private function dumpSqlite(string $filename, string $backupDir, array $dbConfig): array
    {
        $database = $dbConfig['database'] ?? '';

        if ($database === ':memory:') {
            // In-memory test database — no file to copy.
            // Write a zero-byte placeholder so the backup workflow
            // (log creation, retention, audit) is fully testable.
            $path = $backupDir . '/' . $filename . '.sqlite-placeholder';
            file_put_contents($path, '');
            return [$path, 0];
        }

        if (!file_exists($database)) {
            throw new \RuntimeException("SQLite database file not found: {$database}");
        }

        $path = $backupDir . '/' . $filename . '.sqlite';
        if (!copy($database, $path)) {
            throw new \RuntimeException("Failed to copy SQLite database to {$path}");
        }

        return [$path, filesize($path)];
    }

    private function backupDirectory(): string
    {
        return storage_path('app/backups');
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
