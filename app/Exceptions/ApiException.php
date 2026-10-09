<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Base class for expected business-rule failures (insufficient stock, invalid coupon, ...).
 * These are rendered as JSON errors and are not reported to the logs.
 */
abstract class ApiException extends Exception
{
    protected int $status = 400;

    protected string $errorCode = 'BAD_REQUEST';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = '', protected array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            message: $this->getMessage(),
            code: $this->errorCode(),
            status: $this->status(),
            details: $this->details(),
        );
    }
}
