<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         | Suggestion reasoning calls a provider. The suite must never do that.
         |
         | This is not a precaution. `AI_SUGGESTION_REASONING` is set in the local
         | `.env` so the running application really uses it, and a test that
         | inherited that would quietly reach the network - spending a free
         | tier's quota, taking thirty seconds on a timeout, and producing an
         | explanation whose text depends on what a model happened to say that
         | day. That last one is the real damage: the assertion was written
         | against a generated sentence.
         |
         | Forced here, once, for every test. The ones that exercise reasoning
         | turn it back on in their own `setUp` and fake the provider.
         */
        config(['ai.suggestions.reasoning.enabled' => false]);

        /*
         | BUG-021. No test may reach a real provider by accident.
         |
         | `phpunit.xml` already blanks every AI credential, so the pool is empty
         | unless a test builds a candidate. This is the second, independent
         | guard: the HTTP client itself refuses any request that was not matched
         | by a registered fake, so a test that forgets to fake — or that fails
         | over to a candidate nobody meant to call — fails loudly here instead of
         | spending a free tier's quota against a real endpoint. Tests that need a
         | provider fake it; tests that need the network for their own reasons use
         | their own client, which this does not touch.
         */
        Http::preventStrayRequests();
    }
}
