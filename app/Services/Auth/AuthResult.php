<?php

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

final readonly class AuthResult
{
    public function __construct(
        public User $user,
        public NewAccessToken $token,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function tokenPayload(): array
    {
        return [
            'access_token' => $this->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->token->accessToken->expires_at?->toIso8601String(),
        ];
    }
}
