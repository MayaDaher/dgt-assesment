<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'role:admin'])
            ->get('/api/testing/admin-only', fn () => ['ok' => true]);
    }

    public function test_guest_is_rejected_with_401(): void
    {
        $this->getJson('/api/testing/admin-only')->assertUnauthorized();
    }

    public function test_customer_is_rejected_with_403(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/testing/admin-only')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_is_allowed(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/testing/admin-only')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_role_can_be_changed_from_the_console(): void
    {
        $user = User::factory()->create(['email' => 'promote@example.com']);

        $this->artisan('user:role', ['email' => 'PROMOTE@example.com', 'role' => 'admin'])->assertSuccessful();
        $this->assertSame(UserRole::Admin, $user->fresh()->role);

        $this->artisan('user:role', ['email' => 'promote@example.com', 'role' => 'superuser'])->assertFailed();
    }
}
