<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123';

    private const RANDOM_CUSTOMERS = 5;

    public function run(): void
    {
        $this->upsertUser('Admin', 'admin@example.com', UserRole::Admin);
        $this->upsertUser('Demo Customer', 'customer@example.com', UserRole::Customer);

        $missing = self::RANDOM_CUSTOMERS - User::query()->whereNotIn('email', ['admin@example.com', 'customer@example.com'])->count();

        if ($missing > 0) {
            User::factory()->count($missing)->create();
        }
    }

    private function upsertUser(string $name, string $email, UserRole $role): void
    {
        User::query()->firstOrNew(['email' => $email])
            ->forceFill([
                'name' => $name,
                'password' => self::DEFAULT_PASSWORD,
                'role' => $role,
                'email_verified_at' => now(),
            ])
            ->save();
    }
}
