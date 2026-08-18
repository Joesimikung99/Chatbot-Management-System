<?php
/**
 * WikiService — LLM response handling (WP-2 / WP-3)
 *
 * Everything here runs on an instance built without the constructor, so no DB
 * and no OpenRouter connection is involved: these are the pure functions that
 * stand between a model's reply and our tables. They must never throw — a
 * mangled response from one document may not take the whole batch down.
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\WikiService;
use ReflectionClass;

class WikiServiceParsingTest extends TestCase
{
    private WikiService $wiki;
    private ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ref  = new ReflectionClass(WikiService::class);
        $this->wiki = $this->ref->newInstanceWithoutConstructor();
    }

    /**
     * Call a private method on the service.
     */
    private function call(string $method, mixed ...$args): mixed
    {
        return $this->ref->getMethod($method)->invokeArgs($this->wiki, $args);
    }

    // ────────────────────────────────────────────────────────────────
    // decodeJson — the defensive parser
    // ────────────────────────────────────────────────────────────────

    public function test_decodes_plain_json(): void
    {
        $this->assertSame(
            ['pages' => [['slug' => 'library-hours']]],
            $this->call('decodeJson', '{"pages":[{"slug":"library-hours"}]}')
        );
    }

    public function test_decodes_json_wrapped_in_a_code_fence(): void
    {
        $raw = "```json\n{\"questions\":[\"เปิดกี่โมง\"]}\n```";

        $this->assertSame(['questions' => ['เปิดกี่โมง']], $this->call('decodeJson', $raw));
    }

    public function test_decodes_json_surrounded_by_chatter(): void
    {
        // Some models prepend "นี่คือผลลัพธ์ครับ:" no matter what the prompt says.
        $raw = 'นี่คือผลลัพธ์ครับ: {"questions":["เปิดกี่โมง"]} หวังว่าจะช่วยได้นะครับ';

        $this->assertSame(['questions' => ['เปิดกี่โมง']], $this->call('decodeJson', $raw));
    }

    public function test_returns_null_for_broken_json(): void
    {
        $this->assertNull($this->call('decodeJson', '{"pages": [{"slug": '));
        $this->assertNull($this->call('decodeJson', 'ขออภัย ไม่สามารถทำรายการนี้ได้'));
        $this->assertNull($this->call('decodeJson', ''));
    }

    // ────────────────────────────────────────────────────────────────
    // extractList — models disagree on wrapping
    // ────────────────────────────────────────────────────────────────

    public function test_extracts_a_wrapped_list(): void
    {
        $this->assertSame(['a', 'b'], $this->call('extractList', ['questions' => ['a', 'b']], 'questions'));
    }

    public function test_extracts_a_bare_list(): void
    {
        // Models that ignore response_format often answer with the array itself.
        $this->assertSame(['a', 'b'], $this->call('extractList', ['a', 'b'], 'questions'));
    }

    public function test_extracts_nothing_from_an_unrelated_object(): void
    {
        $this->assertSame([], $this->call('extractList', ['error' => 'nope'], 'questions'));
    }

    // ────────────────────────────────────────────────────────────────
    // normalizeSlug — slugs end up in a URL-ish natural key
    // ────────────────────────────────────────────────────────────────

    public function test_keeps_a_well_formed_slug(): void
    {
        $this->assertSame('library-hours', $this->call('normalizeSlug', 'library-hours', 'เวลาทำการ'));
    }

    public function test_forces_slugs_into_kebab_case_ascii(): void
    {
        $this->assertSame('library-hours', $this->call('normalizeSlug', 'Library Hours!', ''));
        $this->assertSame('library-hours', $this->call('normalizeSlug', '--library__hours--', ''));
    }

    /**
     * A Thai slug normalizes to an empty string — falling back to a hash of the
     * title keeps the page instead of silently dropping it.
     */
    public function test_falls_back_to_a_title_hash_when_the_slug_is_unusable(): void
    {
        $slug = $this->call('normalizeSlug', 'เวลาทำการ', 'เวลาทำการ');

        $this->assertMatchesRegularExpression('/^page-[0-9a-f]{10}$/', $slug);
        $this->assertSame($slug, $this->call('normalizeSlug', 'เวลาทำการ', 'เวลาทำการ'), 'must be stable');
    }

    public function test_gives_up_when_there_is_no_slug_and_no_title(): void
    {
        $this->assertSame('', $this->call('normalizeSlug', '', ''));
    }

    public function test_normalizes_linked_slugs_and_drops_junk(): void
    {
        $this->assertSame(
            ['library-hours', 'library-location'],
            $this->call('normalizeLinkedSlugs', ['Library Hours', 'library-location', 'เวลา', 42, null])
        );
    }

    public function test_linked_slugs_tolerate_a_non_array(): void
    {
        $this->assertSame([], $this->call('normalizeLinkedSlugs', 'library-hours'));
    }

    // ────────────────────────────────────────────────────────────────
    // clampContent — D4: a page must stay 1–2 chunks
    // ────────────────────────────────────────────────────────────────

    public function test_short_content_is_left_alone(): void
    {
        $content = "## เวลาทำการ\n- จันทร์-ศุกร์ 08:30-16:30";

        $this->assertSame($content, $this->call('clampContent', $content, 'library-hours'));
    }

    public function test_overlong_content_is_truncated_at_a_line_break(): void
    {
        $line    = str_repeat('ก', 99) . "\n";
        $content = str_repeat($line, 40);   // 4,000 chars over 40 lines

        $clamped = $this->call('clampContent', $content, 'library-hours');

        $this->assertLessThanOrEqual(2500, mb_strlen($clamped, 'UTF-8'));
        // Cut on the line boundary before 2,500, so the last line stays whole.
        $this->assertSame(2499, mb_strlen($clamped, 'UTF-8'));
        $this->assertStringEndsWith(str_repeat('ก', 99), $clamped);
    }

    /**
     * One giant paragraph has no line break to cut at — we would rather cut
     * mid-sentence than hand the prompt a page that blows the size budget.
     */
    public function test_overlong_single_paragraph_is_still_truncated(): void
    {
        $clamped = $this->call('clampContent', str_repeat('ก', 4000), 'library-hours');

        $this->assertSame(2500, mb_strlen($clamped, 'UTF-8'));
    }

    // ────────────────────────────────────────────────────────────────
    // overlapLength — undo the chunker's overlap when rebuilding a document
    // ────────────────────────────────────────────────────────────────

    public function test_finds_the_overlap_between_consecutive_chunks(): void
    {
        $tail  = str_repeat('ข', 200);
        $prev  = str_repeat('ก', 500) . $tail;
        $next  = $tail . str_repeat('ค', 500);

        $this->assertSame(200, $this->call('overlapLength', $prev, $next));
    }

    public function test_reports_no_overlap_between_unrelated_chunks(): void
    {
        $this->assertSame(0, $this->call('overlapLength', str_repeat('ก', 500), str_repeat('ค', 500)));
    }

    /**
     * A handful of identical characters where two chunks meet is a coincidence
     * in Thai text, not the chunker's overlap — treating it as overlap would
     * eat real content.
     */
    public function test_ignores_a_coincidental_short_match(): void
    {
        $this->assertSame(0, $this->call('overlapLength', 'ห้องสมุดเปิดทำการ', 'การยืมหนังสือ'));
    }

    // ────────────────────────────────────────────────────────────────
    // decodeIdList / decodeStringList — JSON columns
    // ────────────────────────────────────────────────────────────────

    public function test_decodes_a_source_id_list(): void
    {
        $this->assertSame([3, 5], $this->call('decodeIdList', '[3, 5, 3]'));
        $this->assertSame([],     $this->call('decodeIdList', null));
        $this->assertSame([],     $this->call('decodeIdList', 'not json'));
    }

    public function test_decodes_a_linked_slug_list(): void
    {
        $this->assertSame(['library-hours'], $this->call('decodeStringList', '["library-hours", 7]'));
        $this->assertSame([],                $this->call('decodeStringList', null));
    }
}
