<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the network. Every outbound call in this app goes
        // to Wikimedia; a test that forgets to fake one would otherwise make a
        // real request — slow, flaky, and rude to a free API. Any unfaked
        // request now fails loudly instead.
        Http::preventStrayRequests();
    }
}
