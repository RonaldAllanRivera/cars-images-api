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
            ->assertJsonPath('abilities', TokenAbilities::defaultScope())
            ->assertJsonPath('user.email', 'allan@example.com');
        $this->assertNotEmpty($response->json('token'));

        $token = $user->tokens()->sole();
        $this->assertSame('Pixel 8', $token->name);
        $this->assertSame(TokenAbilities::defaultScope(), $token->abilities);
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

    public function test_the_default_scope_is_a_strict_subset_of_every_ability(): void
    {
        // The two lists do different jobs: all() is what a request is
        // validated against, defaultScope() is what an unscoped request is
        // issued. Collapsing them back into one method is the regression this
        // guards - it would widen what every deployed client receives.
        $this->assertNotSame(TokenAbilities::all(), TokenAbilities::defaultScope());
        $this->assertEmpty(array_diff(TokenAbilities::defaultScope(), TokenAbilities::all()));
    }

    public function test_the_privileged_abilities_are_not_issued_by_default(): void
    {
        $privileged = [
            TokenAbilities::IMPORTS_READ,
            TokenAbilities::IMPORTS_WRITE,
            TokenAbilities::SEARCH_RUN,
            TokenAbilities::EXPORTS_READ,
        ];

        foreach ($privileged as $ability) {
            $this->assertContains($ability, TokenAbilities::all());
            $this->assertNotContains($ability, TokenAbilities::defaultScope());
        }
    }

    public function test_login_without_abilities_issues_only_the_default_scope(): void
    {
        // The deployed-client guard. mobile/src/api/schemas.ts parses
        // `abilities` through a z.enum of exactly these four, so issuing a
        // wider set to a client that asked for nothing throws on parse and
        // breaks sign-in on the live web build.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', TokenAbilities::defaultScope());

        $this->assertSame(TokenAbilities::defaultScope(), $user->tokens()->sole()->abilities);
    }

    public function test_login_issues_exactly_the_requested_subset(): void
    {
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'abilities' => [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE],
        ] + self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE]);

        $this->assertSame(
            [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE],
            $user->tokens()->sole()->abilities,
        );
    }

    public function test_login_grants_a_privileged_ability_when_it_is_asked_for(): void
    {
        // P3's native build depends on this: the privileged abilities are
        // withheld by default, not withheld outright.
        User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'abilities' => [TokenAbilities::SEARCH_READ, TokenAbilities::SEARCH_RUN],
        ] + self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', [TokenAbilities::SEARCH_READ, TokenAbilities::SEARCH_RUN]);
    }

    public function test_login_canonicalises_the_order_and_collapses_duplicates(): void
    {
        // The issued list is intersected in all()'s order, not the request's,
        // so the response is byte-stable whatever order the client asked in -
        // which is what keeps the committed contract fixture stable.
        User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'abilities' => [
                TokenAbilities::REVIEW_WRITE,
                TokenAbilities::SEARCH_READ,
                TokenAbilities::REVIEW_WRITE,
            ],
        ] + self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE]);
    }

    public function test_login_rejects_an_unknown_ability(): void
    {
        // A typo must be an error, not a silently narrower token.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', ['abilities' => ['search:reed']] + self::LOGIN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abilities.0']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_rejects_an_empty_abilities_array(): void
    {
        // A zero-ability token 403s on every route it touches, which reads as
        // a server fault from the client side. Reject at the door instead.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', ['abilities' => []] + self::LOGIN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abilities']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_reported_abilities_match_the_stored_token(): void
    {
        // These are written in two places - the response body and the token
        // row - and a client that trusts the body while the row says
        // something narrower gets 403s it cannot explain.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'abilities' => [TokenAbilities::ERRORS_READ, TokenAbilities::EXPORTS_READ],
        ] + self::LOGIN)->assertCreated();

        $this->assertSame($response->json('abilities'), $user->tokens()->sole()->abilities);
    }
}
