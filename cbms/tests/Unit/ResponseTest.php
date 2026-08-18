<?php
/**
 * Tests for Response helper — Input validation & utility methods
 * (Cannot test json/error/success directly because they call exit)
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\Response;

class ResponseTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    // validateRequired()
    // ────────────────────────────────────────────────────────────────

    public function test_validateRequired_returns_empty_when_all_present(): void
    {
        $data = ['name' => 'Bot A', 'slug' => 'bot-a', 'description' => 'Test'];
        $required = ['name', 'slug'];

        $missing = Response::validateRequired($data, $required);
        $this->assertSame([], $missing);
    }

    public function test_validateRequired_returns_missing_fields(): void
    {
        $data = ['name' => 'Bot A'];
        $required = ['name', 'slug', 'platform'];

        $missing = Response::validateRequired($data, $required);
        $this->assertSame(['slug', 'platform'], $missing);
    }

    public function test_validateRequired_treats_empty_string_as_missing(): void
    {
        $data = ['name' => '', 'slug' => '  '];
        $required = ['name', 'slug'];

        $missing = Response::validateRequired($data, $required);
        $this->assertSame(['name', 'slug'], $missing);
    }

    public function test_validateRequired_treats_zero_as_present(): void
    {
        $data = ['count' => 0, 'name' => '0'];
        $required = ['count', 'name'];

        $missing = Response::validateRequired($data, $required);
        // '0' is not empty, 0 (int) isset returns true
        $this->assertSame([], $missing);
    }
}
