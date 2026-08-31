<?php

namespace Tests\Feature;

use App\Exceptions\WikimediaBlockedException;
use App\Services\Images\WikimediaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikimediaBlockHandlingTest extends TestCase
{
    public function test_throws_wikimedia_blocked_exception_on_429(): void
    {
        Http::fake([
            '*' => Http::response('Rate limit exceeded', 429, ['Retry-After' => '60']),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        try {
            $client->filesInCategory('Toyota RAV4');
            $this->fail('Expected WikimediaBlockedException');
        } catch (WikimediaBlockedException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertSame(60, $e->retryAfterSeconds);
            $this->assertStringContainsString('Rate limit', $e->responseExcerpt);
        }
    }

    public function test_throws_wikimedia_blocked_exception_on_403(): void
    {
        Http::fake([
            '*' => Http::response('Forbidden', 403),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        $this->expectException(WikimediaBlockedException::class);

        $client->filesInCategory('Toyota RAV4');
    }

    public function test_user_agent_includes_contact_info(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['search' => []]], 200),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        $client->filesInCategory('Toyota RAV4');

        Http::assertSent(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';

            return str_contains($ua, 'CarsImagesApi/1.0')
                && (str_contains($ua, 'http') || str_contains($ua, '@'));
        });
    }

    public function test_request_includes_maxlag_parameter(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['search' => []]], 200),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        $client->filesInCategory('Toyota RAV4');

        Http::assertSent(function ($request) {
            return ($request->data()['maxlag'] ?? null) == 5
                || str_contains($request->url(), 'maxlag=5');
        });
    }
}
