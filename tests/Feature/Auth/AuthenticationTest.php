<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret123';

    public function test_customer_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => '  Jane@Example.com ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'device_name' => 'iphone',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.user.role', UserRole::Customer->value)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'role', 'created_at'], 'access_token', 'expires_at']])
            ->assertJsonMissingPath('data.user.password');

        $user = User::query()->where('email', 'jane@example.com')->sole();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame('iphone', $user->tokens()->sole()->name);
    }

    public function test_registration_cannot_escalate_role(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => UserRole::Admin->value,
        ])->assertCreated()->assertJsonPath('data.user.role', UserRole::Customer->value);

        $this->assertFalse(User::query()->where('email', 'mallory@example.com')->sole()->isAdmin());
    }

    public function test_registration_rejects_duplicate_email_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Copycat',
            'email' => 'TAKEN@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_a_strong_confirmed_password(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'password',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'john@example.com', 'password' => self::PASSWORD]);

        $this->postJson('/api/auth/login', [
            'email' => 'JOHN@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_at']]);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_fails_with_wrong_password_or_unknown_email(): void
    {
        User::factory()->create(['email' => 'john@example.com', 'password' => self::PASSWORD]);

        foreach (['john@example.com' => 'WrongPass1', 'nobody@example.com' => self::PASSWORD] as $email => $password) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
                ->assertUnauthorized()
                ->assertJsonPath('code', 'INVALID_CREDENTIALS');
        }
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'john@example.com', 'password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'john@example.com', 'password' => 'WrongPass1'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => 'john@example.com', 'password' => self::PASSWORD])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertHeader('Retry-After');
    }

    public function test_me_requires_a_valid_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->withToken('invalid-token')->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', UserRole::Customer->value);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', expiresAt: now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('phone')->plainTextToken;
        $user->createToken('laptop');

        $this->withToken($current)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(['laptop'], $user->tokens()->pluck('name')->all());

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('phone')->plainTextToken;
        $user->createToken('laptop');

        $this->withToken($current)->postJson('/api/auth/logout-all')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
