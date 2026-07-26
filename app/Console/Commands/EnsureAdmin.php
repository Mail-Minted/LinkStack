<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Deploy-time replacement for the installer's AdminSeeder, which hardcodes
 * admin@admin.com / 12345678. Registration is sealed in this fork, so
 * without this there is no way to mint the operator's admin login on a
 * fresh production database. Idempotent — safe to run on every boot.
 */
class EnsureAdmin extends Command
{
    protected $signature = 'mm:ensure-admin';

    protected $description = 'Create the admin user from LINKSTACK_ADMIN_EMAIL / LINKSTACK_ADMIN_PASSWORD if it does not exist.';

    public function handle(): int
    {
        $email = env('LINKSTACK_ADMIN_EMAIL');
        $password = env('LINKSTACK_ADMIN_PASSWORD');

        if (!$email || !$password) {
            $this->info('LINKSTACK_ADMIN_EMAIL / LINKSTACK_ADMIN_PASSWORD not set — skipping admin bootstrap.');
            return 0;
        }

        if (User::where('email', $email)->exists()) {
            $this->info("Admin {$email} already exists.");
            return 0;
        }

        User::insert([
            'name' => 'admin',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'role' => 'admin',
            'littlelink_name' => 'admin',
            'littlelink_description' => 'admin page',
        ]);

        $this->info("Admin {$email} created.");
        return 0;
    }
}
