<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\User;

class AuthTest extends ApiTestCase
{
    private const LOGIN = ['email' => 'allan@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'];

    public function test_login_returns_a_scoped_bearer_token(): void
    {
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $response = $this->postJson('/api/v1/auth/login', self::LOGIN);

        $response->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('abilities', TokenAbilities::all())
            ->assertJsonPath('user.email', 'allan@example.com');
        $this->assertNotEmpty($response->json('token'));

        $token = $user->tokens()->sole();
        $this->assertSame('Pixel 8', $token->name);
        $this->assertSame(TokenAbilities::all(), $token->abilities);
    }

    public function test_login_rejects_a_wrong_password_without_issuing_a_token(): void
    {
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', ['password' => 'wrong'] + self::LOGIN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_requires_a_device_name(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.c', 'password' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'allan@example.com']);
        $bad = ['password' => 'wrong'] + self::LOGIN;

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $bad)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $bad)->assertStatus(429);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_request_without_a_json_accept_header_still_gets_a_json_401(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_me_returns_the_user_behind_a_real_bearer_token(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('Pixel 8', TokenAbilities::all())->plainTextToken;

        $this->asToken($plain)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone', TokenAbilities::all())->plainTextToken;
        $laptop = $user->createToken('laptop', TokenAbilities::all())->plainTextToken;

        $this->asToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->asToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($laptop)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }
}
