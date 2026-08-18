<?php
/**
 * Tests for Auth helper — CSRF Token methods
 * Covers: csrfToken, validateCsrf, csrfField
 * Bug regression: JS sending 'csrf_token' vs PHP expecting '_csrf_token'
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\Auth;

class AuthCsrfTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    // csrfToken()
    // ────────────────────────────────────────────────────────────────

    public function test_csrfToken_generates_token_on_first_call(): void
    {
        $this->startSession();
        unset($_SESSION['csrf_token']);

        $token = Auth::csrfToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes → 64 hex chars
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function test_csrfToken_returns_same_token_on_subsequent_calls(): void
    {
        $this->startSession();
        $first  = Auth::csrfToken();
        $second = Auth::csrfToken();

        $this->assertSame($first, $second);
    }

    // ────────────────────────────────────────────────────────────────
    // validateCsrf()
    // ────────────────────────────────────────────────────────────────

    public function test_validateCsrf_accepts_correct_token_from_POST(): void
    {
        $token = $this->setupCsrf();
        $_POST['_csrf_token'] = $token;

        $this->assertTrue(Auth::validateCsrf());
    }

    public function test_validateCsrf_rejects_wrong_token(): void
    {
        $this->setupCsrf();
        $_POST['_csrf_token'] = 'wrong-token-value';

        $this->assertFalse(Auth::validateCsrf());
    }

    public function test_validateCsrf_rejects_empty_token(): void
    {
        $this->setupCsrf();
        $_POST['_csrf_token'] = '';

        $this->assertFalse(Auth::validateCsrf());
    }

    public function test_validateCsrf_rejects_missing_token(): void
    {
        $this->setupCsrf();
        unset($_POST['_csrf_token']);

        $this->assertFalse(Auth::validateCsrf());
    }

    /**
     * REGRESSION: JS was sending 'csrf_token' (no underscore prefix)
     * but Auth::validateCsrf() reads from '_csrf_token'.
     * This test ensures the correct field name is required.
     */
    public function test_validateCsrf_rejects_wrong_field_name_csrf_token(): void
    {
        $token = $this->setupCsrf();

        // Wrong field name (the old bug)
        $_POST['csrf_token'] = $token;
        unset($_POST['_csrf_token']);

        $this->assertFalse(Auth::validateCsrf());
    }

    public function test_validateCsrf_accepts_X_CSRF_TOKEN_header(): void
    {
        $token = $this->setupCsrf();
        unset($_POST['_csrf_token']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $this->assertTrue(Auth::validateCsrf());

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function test_validateCsrf_accepts_explicit_token_argument(): void
    {
        $token = $this->setupCsrf();

        $this->assertTrue(Auth::validateCsrf($token));
    }

    public function test_validateCsrf_fails_when_no_session_token_set(): void
    {
        $this->startSession();
        unset($_SESSION['csrf_token']);
        $_POST['_csrf_token'] = 'anything';

        $this->assertFalse(Auth::validateCsrf());
    }

    // ────────────────────────────────────────────────────────────────
    // csrfField()
    // ────────────────────────────────────────────────────────────────

    public function test_csrfField_returns_hidden_input_with_underscore_prefix(): void
    {
        $this->startSession();
        $html = Auth::csrfField();

        $this->assertStringContainsString('name="_csrf_token"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('value="', $html);
    }
}
