<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthResult;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register(
            $request->safe()->only(['name', 'email', 'password']),
            $request->validated('device_name'),
        );

        return ApiResponse::created($this->authPayload($result), 'Registered successfully.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
        );

        return ApiResponse::success($this->authPayload($result), 'Logged in successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::success(message: 'Logged out successfully.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->auth->logoutEverywhere($request->user());

        return ApiResponse::success(message: 'Logged out from all devices.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    private function authPayload(AuthResult $result): array
    {
        return [
            'user' => new UserResource($result->user),
            ...$result->tokenPayload(),
        ];
    }
}
