<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Module 1 - authentication (interim test cases T1-T9). */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private array $valid = [
        'name' => 'Muwafaq',
        'email' => 'coord@example.test',
        'phone' => '0771234567',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    public function test_t1_register_with_valid_details_returns_token(): void
    {
        $this->postJson('/api/auth/register', $this->valid)
            ->assertCreated()
            ->assertJsonStructure(['success', 'user' => ['id', 'email', 'role'], 'token'])
            ->assertJsonPath('user.role', 'coordinator');

        $this->assertDatabaseHas('users', ['email' => 'coord@example.test']);
    }

    public function test_t2_register_with_duplicate_email_is_rejected(): void
    {
        $this->makeUser(['email' => 'coord@example.test']);

        $this->postJson('/api/auth/register', $this->valid)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_t3_register_with_weak_password_is_rejected(): void
    {
        $this->postJson('/api/auth/register', array_merge($this->valid, ['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_t4_login_with_valid_credentials(): void
    {
        $this->makeUser(['email' => 'coord@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'coord@example.test', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name']]);
    }

    public function test_t5_login_with_wrong_password_returns_401_and_no_token(): void
    {
        $this->makeUser(['email' => 'coord@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'coord@example.test', 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonMissingPath('token');
    }

    public function test_t6_protected_route_without_token_returns_401(): void
    {
        $this->getJson('/api/auth/user')->assertStatus(401);
        // Also without an Accept header (previously crashed with a 500).
        $this->get('/api/events')->assertStatus(401);
    }

    public function test_t7_protected_route_with_valid_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_t8_refresh_token_issues_new_token_and_revokes_old(): void
    {
        $user = $this->makeUser();
        $old = $user->createToken('t')->plainTextToken;

        $new = $this->withToken($old)->postJson('/api/auth/refresh-token')->assertOk()->json('token');
        $this->assertNotEquals($old, $new);

        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/auth/user')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($new)->getJson('/api/auth/user')->assertOk();
    }

    public function test_t9_logout_revokes_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/user')->assertStatus(401);
    }
}
