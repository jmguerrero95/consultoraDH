<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }
}
