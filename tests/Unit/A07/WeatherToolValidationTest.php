<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Domain\Ai\AiToolRejected;
use App\Services\Ai\Tools\WeatherTool;
use Tests\TestCase;

/**
 * VERIFY: the weather tool accepts the argument shapes models actually send.
 *
 * In the live acceptance run a weak model sent `days` as the string "2", the
 * tool refused it, and the model reported the refusal to the person instead of
 * retrying. A numeric string is the integer the model meant, so it is accepted;
 * anything genuinely out of range is still refused.
 */
final class WeatherToolValidationTest extends TestCase
{
    private function tool(): WeatherTool
    {
        return new WeatherTool;
    }

    public function test_a_numeric_string_days_is_accepted(): void
    {
        $validated = $this->tool()->validate(['location' => 'Girón, Santander', 'days' => '2']);

        $this->assertSame(2, $validated['days']);
        $this->assertSame('Girón, Santander', $validated['location']);
    }

    public function test_days_defaults_to_two_when_omitted(): void
    {
        $this->assertSame(2, $this->tool()->validate(['location' => 'Girón'])['days']);
    }

    public function test_an_integer_days_is_kept(): void
    {
        $this->assertSame(3, $this->tool()->validate(['location' => 'Girón', 'days' => 3])['days']);
    }

    public function test_an_out_of_range_days_is_refused(): void
    {
        $this->expectException(AiToolRejected::class);

        $this->tool()->validate(['location' => 'Girón', 'days' => '9']);
    }

    public function test_a_non_numeric_days_is_refused(): void
    {
        $this->expectException(AiToolRejected::class);

        $this->tool()->validate(['location' => 'Girón', 'days' => 'muchos']);
    }
}
