<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('user:role {email} {role=admin}', function (string $email, string $role) {
    $newRole = UserRole::tryFrom($role);

    if ($newRole === null) {
        $this->error("Invalid role [{$role}]. Allowed: ".implode(', ', UserRole::values()));

        return 1;
    }

    $user = User::query()->where('email', mb_strtolower($email))->first();

    if ($user === null) {
        $this->error("No user found with email [{$email}].");

        return 1;
    }

    $user->forceFill(['role' => $newRole])->save();

    $this->info("{$user->email} is now [{$newRole->value}].");

    return 0;
})->purpose('Change the role of a user (customer or admin)');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('cart:expire-reservations')->everyMinute();
Schedule::command('orders:expire-pending')->everyMinute();
