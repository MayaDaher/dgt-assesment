<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Builds the JSON envelope shared by every API response.
 *
 * Success: { "success": true, "message": ?string, "data": mixed }
 * Error:   { "success": false, "message": string, "code": string, "errors"?: object, "details"?: object }
 */
final class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        if ($data instanceof JsonResource) {
            return $data
                ->additional(['success' => true, 'message' => $message])
                ->response()
                ->setStatusCode($status);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * @param  array<string, mixed>  $errors  Field-level validation errors.
     * @param  array<string, mixed>  $details  Extra machine-readable context about the failure.
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message,
        string $code,
        int $status,
        array $errors = [],
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return new JsonResponse($payload, $status, $headers);
    }
}
