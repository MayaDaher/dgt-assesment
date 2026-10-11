<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Converts framework and unexpected exceptions into the API error envelope.
 * ApiException subclasses render themselves and never reach this class.
 */
final class ApiExceptionRenderer
{
    public static function shouldRenderJson(Request $request): bool
    {
        return $request->is('api', 'api/*') || $request->expectsJson();
    }

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldRenderJson($request) || $e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(
                message: $e->getMessage(),
                code: 'VALIDATION_FAILED',
                status: $e->status,
                errors: $e->errors(),
            ),
            $e instanceof AuthenticationException => ApiResponse::error(
                message: 'Unauthenticated.',
                code: 'UNAUTHENTICATED',
                status: Response::HTTP_UNAUTHORIZED,
            ),
            $e instanceof NotFoundHttpException => $this->notFound($e),
            $e instanceof AccessDeniedHttpException => ApiResponse::error(
                message: $e->getMessage() ?: 'This action is unauthorized.',
                code: 'FORBIDDEN',
                status: Response::HTTP_FORBIDDEN,
            ),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                message: 'The HTTP method is not supported for this endpoint.',
                code: 'METHOD_NOT_ALLOWED',
                status: Response::HTTP_METHOD_NOT_ALLOWED,
                headers: $e->getHeaders(),
            ),
            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                message: 'Too many requests. Please slow down.',
                code: 'TOO_MANY_REQUESTS',
                status: Response::HTTP_TOO_MANY_REQUESTS,
                headers: $e->getHeaders(),
            ),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                message: $e->getMessage() ?: $this->statusText($e->getStatusCode()),
                code: $this->codeFromStatus($e->getStatusCode()),
                status: $e->getStatusCode(),
                headers: $e->getHeaders(),
            ),
            default => $this->serverError($e),
        };
    }

    private function notFound(NotFoundHttpException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof ModelNotFoundException) {
            return ApiResponse::error(
                message: ucfirst(strtolower(Str::headline(class_basename($previous->getModel())))).' not found.',
                code: 'RESOURCE_NOT_FOUND',
                status: Response::HTTP_NOT_FOUND,
            );
        }

        return ApiResponse::error(
            message: $e->getMessage() ?: 'Not found.',
            code: 'NOT_FOUND',
            status: Response::HTTP_NOT_FOUND,
        );
    }

    private function serverError(Throwable $e): JsonResponse
    {
        $details = config('app.debug') ? [
            'exception' => $e::class,
            'exception_message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ] : [];

        return ApiResponse::error(
            message: 'Something went wrong. Please try again later.',
            code: 'SERVER_ERROR',
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
            details: $details,
        );
    }

    private function statusText(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }

    private function codeFromStatus(int $status): string
    {
        return trim(strtoupper((string) preg_replace('/[^A-Za-z]+/', '_', $this->statusText($status))), '_');
    }
}
