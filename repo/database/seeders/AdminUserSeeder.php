<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Auth\PasswordValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the initial demo accounts for all three roles.
 *
 * Credentials are deterministic so they can be documented in the README.
 * Every account has must_change_password=true — the user is forced to set
 * a new password on first login.
 *
 * NEVER deploy these credentials to a publicly accessible environment
 * without immediately changing them.
 */
class AdminUserSeeder extends Seeder
{
    /** Deterministic demo accounts — must match README.md */
    public const ACCOUNTS = [
        [
            'username'      => 'admin',
            'display_name'  => 'System Administrator',
            'password'      => 'AdminDemo1!',
            'audience_type' => 'staff',
            'role'          => 'administrator',
        ],
        [
            'username'      => 'editor',
            'display_name'  => 'Demo Editor',
            'password'      => 'EditorDemo1!',
            'audience_type' => 'staff',
            'role'          => 'content_editor',
        ],
        [
            'username'      => 'learner',
            'display_name'  => 'Demo Learner',
            'password'      => 'LearnerDemo1!',
            'audience_type' => 'graduate',
            'role'          => 'learner',
        ],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $acct) {
            $existing = User::where('username', $acct['username'])->first();

            if ($existing) {
                $this->command->info("User '{$acct['username']}' already exists — skipping.");
                continue;
            }

            $user = User::create([
                'uuid'                 => (string) Str::uuid(),
                'username'             => $acct['username'],
                'display_name'         => $acct['display_name'],
                'password'             => Hash::make($acct['password']),
                'password_changed_at'  => now(),
                'audience_type'        => $acct['audience_type'],
                'status'               => 'active',
                'must_change_password' => true,
            ]);

            $user->assignRole($acct['role']);
            app(PasswordValidator::class)->recordInHistory($user, Hash::make($acct['password']));

            $this->command->info("  Created {$acct['role']}: {$acct['username']} / {$acct['password']}");
        }

        $this->command->warn('');
        $this->command->warn('════════════════════════════════════════════════════════');
        $this->command->warn('  DEMO CREDENTIALS — change immediately after login');
        foreach (self::ACCOUNTS as $acct) {
            $this->command->warn("    {$acct['role']}: {$acct['username']} / {$acct['password']}");
        }
        $this->command->warn('════════════════════════════════════════════════════════');
        $this->command->warn('');
    }
}
