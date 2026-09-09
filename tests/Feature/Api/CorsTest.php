<?php

namespace Tests\Feature\Api;

class CorsTest extends ApiTestCase
{
    private const ORIGIN = 'https://cars-images.netlify.app';

    private function preflight(string $origin)
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/v1/auth/me');
    }

    public function test_nothing_is_allowed_until_an_origin_is_configured(): void
    {
        config(['cors.allowed_origins' => []]);

        $this->preflight(self::ORIGIN)->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_the_configured_origin_gets_cors_headers(): void
    {
        config(['cors.allowed_origins' => [self::ORIGIN]]);

        $this->preflight(self::ORIGIN)
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
    }

    public function test_any_other_origin_is_never_echoed_back(): void
    {
        config(['cors.allowed_origins' => [self::ORIGIN]]);

        $header = $this->preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin');

        // With one allowed origin, HandleCors answers every request with that
        // origin and lets the browser refuse any other page. Absent or equal to
        // the configured origin are both refusals; echoing the caller's origin
        // would be the leak.
        $this->assertContains($header, [null, self::ORIGIN]);
    }
}
