<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/testing')->group(function () {
            Route::post('/validation', fn (Request $request) => $request->validate([
                'name' => ['required', 'string'],
            ]));
            Route::get('/model-not-found', fn () => throw (new ModelNotFoundException)->setModel(User::class, [99]));
            Route::get('/unauthenticated', fn () => throw new AuthenticationException);
            Route::get('/forbidden', fn () => throw new AuthorizationException);
            Route::get('/domain', fn () => throw new class('Not enough stock.', ['available' => 1]) extends ApiException
            {
                protected int $status = 409;

                protected string $errorCode = 'INSUFFICIENT_STOCK';
            });
            Route::get('/crash', fn () => throw new RuntimeException('Secret internal failure'));
        });
    }

    public function test_unknown_endpoint_returns_json_404(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertJson(['success' => false, 'code' => 'NOT_FOUND']);
    }

    public function test_unsupported_method_returns_json_405(): void
    {
        $this->post('/api/products')
            ->assertMethodNotAllowed()
            ->assertJson(['success' => false, 'code' => 'METHOD_NOT_ALLOWED']);
    }

    public function test_validation_errors_return_422_with_field_errors(): void
    {
        $this->post('/api/testing/validation')
            ->assertUnprocessable()
            ->assertJson(['success' => false, 'code' => 'VALIDATION_FAILED'])
            ->assertJsonValidationErrors(['name']);
    }

    public function test_missing_model_returns_readable_404(): void
    {
        $this->get('/api/testing/model-not-found')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'User not found.',
                'code' => 'RESOURCE_NOT_FOUND',
            ]);
    }

    public function test_unauthenticated_request_returns_401_without_redirect(): void
    {
        $this->get('/api/testing/unauthenticated')
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'code' => 'UNAUTHENTICATED']);
    }

    public function test_unauthorized_action_returns_403(): void
    {
        $this->get('/api/testing/forbidden')
            ->assertForbidden()
            ->assertJson(['success' => false, 'code' => 'FORBIDDEN']);
    }

    public function test_domain_exception_renders_its_own_status_code_and_details(): void
    {
        $this->get('/api/testing/domain')
            ->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => 'Not enough stock.',
                'code' => 'INSUFFICIENT_STOCK',
                'details' => ['available' => 1],
            ]);
    }

    public function test_unexpected_errors_hide_internal_message_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $this->get('/api/testing/crash')
            ->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
                'code' => 'SERVER_ERROR',
            ]);
    }
}
