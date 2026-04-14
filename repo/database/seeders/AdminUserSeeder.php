<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Auth\PasswordValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the initial administrator account.
 *
 * Credentials are printed to stdout once on first seed.
 * The administrator MUST change this password immediately after first login.
 * This seeder will not overwrite an existing admin account.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::where('username', 'admin')->first();

        if ($existing) {
            $this->command->info('Admin user already exists — skipping.');
            return;
        }

        $password = 'Admin@' . Str::random(8) . '1!';

        $admin = User::create([
            'uuid'                 => (string) Str::uuid(),
            'username'             => 'admin',
            'display_name'         => 'System Administrator',
            'password'             => Hash::make($password),
            'password_changed_at'  => now(),
            'audience_type'        => 'staff',
            'status'               => 'active',
            'must_change_password' => true, // force change on first login
        ]);

        $admin->assignRole('administrator');

        // Record in password history
        app(PasswordValidator::class)->recordInHistory($admin, Hash::make($password));

        $this->command->warn('');
        $this->command->warn('════════════════════════════════════════════════════');
        $this->command->warn('  INITIAL ADMIN CREDENTIALS (change immediately)');
        $this->command->warn('  Username: admin');
        $this->command->warn("  Password: {$password}");
        $this->command->warn('════════════════════════════════════════════════════');
        $this->command->warn('');
    }
}
