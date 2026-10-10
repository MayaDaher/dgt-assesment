<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private const DEFAULT_DEVICE_NAME = 'api';

    /**
     * Self-registration always creates a customer; admins are promoted via `php artisan user:role`.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data, ?string $deviceName = null): AuthResult
    {
        return DB::transaction(function () use ($data, $deviceName) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            return new AuthResult($user, $this->issueToken($user, $deviceName));
        });
    }

    /**
     * @throws InvalidCredentialsException
     */
    public function login(string $email, string $password, ?string $deviceName = null): AuthResult
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return new AuthResult($user, $this->issueToken($user, $deviceName));
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function logoutEverywhere(User $user): void
    {
        $user->tokens()->delete();
    }

    private function issueToken(User $user, ?string $deviceName): NewAccessToken
    {
        $expiration = config('sanctum.expiration');

        return $user->createToken(
            name: $deviceName ?: self::DEFAULT_DEVICE_NAME,
            expiresAt: $expiration ? now()->addMinutes((int) $expiration) : null,
        );
    }
}
