<?php
/**
 * Bug Regression Tests
 * Each test documents and prevents a specific bug from re-appearing.
 *
 * ┌────────┬───────────────────────────────────────────────────────────────┐
 * │ Bug #  │ Description                                                 │
 * ├────────┼───────────────────────────────────────────────────────────────┤
 * │ BUG-01 │ CSRF field name mismatch: JS sent 'csrf_token' but PHP      │
 * │        │ expected '_csrf_token'. All AJAX in bots.php & bot-edit.php  │
 * │        │ failed with "CSRF failed" 403.                              │
 * ├────────┼───────────────────────────────────────────────────────────────┤
 * │ BUG-02 │ Variable collision: header.php sidebar loop used $bot as    │
 * │        │ loop variable, overwriting $bot set by bot-edit.php.        │
 * │        │ Result: edit form showed data from the last bot in sidebar. │
 * ├────────┼───────────────────────────────────────────────────────────────┤
 * │ BUG-03 │ missed.php "Mark all reviewed" button stayed visible after  │
 * │        │ all items were individually removed via "อ่านแล้ว".          │
 * │        │ Root cause: server-rendered PHP, no reactive JS state.      │
 * ├────────┼───────────────────────────────────────────────────────────────┤
 * │ BUG-04 │ Sidebar accordion not working: expanding bot 2 didn't      │
 * │        │ collapse bot 1. openBots array kept growing.                │
 * ├────────┼───────────────────────────────────────────────────────────────┤
 * │ BUG-05 │ Nav item 'unanswered' page mismatch with filename          │
 * │        │ 'missed.php'. $currentPage = 'missed' but nav item had     │
 * │        │ page = 'unanswered', so active state never highlighted.    │
 * └────────┴───────────────────────────────────────────────────────────────┘
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\Auth;

class BugRegressionTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    // BUG-01: CSRF field name mismatch
    // JS sent 'csrf_token', PHP reads '_csrf_token'
    // ────────────────────────────────────────────────────────────────

    public function test_bug01_csrf_field_name_must_have_underscore_prefix(): void
    {
        $token = $this->setupCsrf();

        // The CORRECT field name (with underscore)
        $_POST['_csrf_token'] = $token;
        $this->assertTrue(Auth::validateCsrf(), 'Should accept _csrf_token');

        // The WRONG field name (without underscore) — was the bug
        unset($_POST['_csrf_token']);
        $_POST['csrf_token'] = $token;
        $this->assertFalse(Auth::validateCsrf(), 'Should reject csrf_token (no underscore)');
    }

    /**
     * Verify all admin pages send CSRF with correct field name.
     * Scans JS files for the old buggy pattern.
     */
    public function test_bug01_no_js_files_send_wrong_csrf_field(): void
    {
        $adminDir = dirname(__DIR__, 2) . '/public/admin';
        $files = glob($adminDir . '/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            // Check that no JS code uses fd.append('csrf_token', ...)
            // The correct pattern is fd.append('_csrf_token', ...)
            if (preg_match_all("/fd\.append\(['\"]csrf_token['\"]/", $content, $matches)) {
                $this->fail(
                    "BUG-01 REGRESSION: {$basename} still uses 'csrf_token' without underscore prefix. "
                    . 'Should be \'_csrf_token\'. Found: ' . implode(', ', $matches[0])
                );
            }
        }

        // If we get here, no files have the bug
        $this->assertTrue(true);
    }

    // ────────────────────────────────────────────────────────────────
    // BUG-02: Variable collision in header.php sidebar loop
    // foreach ($userBots as $bot) overwrites $bot from bot-edit.php
    // ────────────────────────────────────────────────────────────────

    public function test_bug02_header_sidebar_does_not_use_bot_variable(): void
    {
        $headerFile = dirname(__DIR__, 2) . '/public/admin/layouts/header.php';
        $this->assertFileExists($headerFile);

        $content = file_get_contents($headerFile);

        // The sidebar loop should NOT use $bot as the loop variable
        $hasBotLoopVar = preg_match('/foreach\s*\(\s*\$userBots\s+as\s+.*\$bot\s*\)/', $content);
        $this->assertSame(
            0,
            $hasBotLoopVar,
            'BUG-02 REGRESSION: header.php sidebar loop uses $bot as loop variable. '
            . 'This overwrites $bot from pages like bot-edit.php. Use $_sidebarBot instead.'
        );
    }

    /**
     * Verify header.php uses $_sidebarBot (or similar non-colliding name).
     */
    public function test_bug02_header_uses_safe_loop_variable(): void
    {
        $headerFile = dirname(__DIR__, 2) . '/public/admin/layouts/header.php';
        $content = file_get_contents($headerFile);

        // Should use $_sidebarBot
        $this->assertStringContainsString(
            '$_sidebarBot',
            $content,
            'header.php should use $_sidebarBot as loop variable'
        );
    }

    // ────────────────────────────────────────────────────────────────
    // BUG-03: missed.php stale "mark all" button
    // Button and count were server-rendered PHP, not reactive
    // ────────────────────────────────────────────────────────────────

    public function test_bug03_missed_page_uses_reactive_remaining_count(): void
    {
        $missedFile = dirname(__DIR__, 2) . '/public/admin/missed.php';
        $this->assertFileExists($missedFile);

        $content = file_get_contents($missedFile);

        // Should use Alpine.js x-show with remaining count, not PHP if-else
        $this->assertStringContainsString(
            'x-show="remaining > 0"',
            $content,
            'BUG-03: "Mark all" button should use reactive x-show="remaining > 0"'
        );

        // Empty state should also be reactive
        $this->assertStringContainsString(
            'x-show="remaining === 0"',
            $content,
            'BUG-03: Empty state should use reactive x-show="remaining === 0"'
        );

        // Should have reactive counter in button text
        $this->assertStringContainsString(
            'x-text="remaining"',
            $content,
            'BUG-03: Button count should use x-text="remaining"'
        );
    }

    public function test_bug03_missed_page_tracks_reviewed_items(): void
    {
        $missedFile = dirname(__DIR__, 2) . '/public/admin/missed.php';
        $content = file_get_contents($missedFile);

        // Should track reviewed IDs in Alpine state
        $this->assertStringContainsString('reviewed:', $content);
        $this->assertStringContainsString('this.reviewed.push(id)', $content);
    }

    // ────────────────────────────────────────────────────────────────
    // BUG-04: Sidebar bot toggle
    // History: originally fixed as an accordion (open one bot at a time),
    // later deliberately changed to allow MULTIPLE bots open at once
    // ("keep others open for easy comparison") with state persisted in
    // localStorage. This test pins the current intended behavior: clicking
    // an open bot collapses it, clicking a closed bot expands it without
    // collapsing the others, and the choice is persisted.
    // ────────────────────────────────────────────────────────────────

    public function test_bug04_sidebar_toggle_expands_and_collapses_bots(): void
    {
        $footerFile = dirname(__DIR__, 2) . '/public/admin/layouts/footer.php';
        $this->assertFileExists($footerFile);

        $content = file_get_contents($footerFile);

        // Expand adds the bot without collapsing others (multi-open by design)
        $this->assertStringContainsString(
            'this.openBots.push(botId)',
            $content,
            'BUG-04: toggleBot should expand a bot by adding it to openBots'
        );

        // Collapse removes only the clicked bot
        $this->assertStringContainsString(
            'this.openBots.filter(id => id !== botId)',
            $content,
            'BUG-04: toggleBot should collapse the clicked bot only'
        );

        // Open/closed state survives page loads
        $this->assertStringContainsString(
            'localStorage.setItem(storageKey',
            $content,
            'BUG-04: sidebar open-state should persist to localStorage'
        );
    }

    // ────────────────────────────────────────────────────────────────
    // BUG-05: Unanswered nav page name mismatch
    // Nav item page='unanswered' but filename='missed.php' → currentPage='missed'
    // ────────────────────────────────────────────────────────────────

    public function test_bug05_nav_items_page_matches_filename(): void
    {
        $headerFile = dirname(__DIR__, 2) . '/public/admin/layouts/header.php';
        $content = file_get_contents($headerFile);

        // The nav item for missed.php should use page='missed', not 'unanswered'
        // Check that navigation highlight works correctly
        $this->assertMatchesRegularExpression(
            "/\['page'\s*=>\s*'missed'\s*,\s*'href'\s*=>\s*'missed\.php'/",
            $content,
            'BUG-05: Nav item for missed.php should have page=\'missed\' to match $currentPage'
        );

        // Should NOT have page='unanswered' for missed.php
        $hasOldBug = preg_match(
            "/\['page'\s*=>\s*'unanswered'\s*,\s*'href'\s*=>\s*'missed\.php'/",
            $content
        );
        $this->assertSame(
            0,
            $hasOldBug,
            'BUG-05 REGRESSION: Nav item still uses page=\'unanswered\' for missed.php'
        );
    }
}
