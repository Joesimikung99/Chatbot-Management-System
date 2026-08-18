<?php
/**
 * WikiPrompts — prompt template tests (WP-1)
 *
 * These prompts are the main hallucination guard for the wiki layer: the LLM
 * rewrites documents into wiki pages, and nothing but the prompt stops it from
 * inventing opening hours. The tests below pin the two things that must never
 * silently disappear from a prompt: the "don't make things up" instruction and
 * the JSON output contract the parser depends on.
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\WikiPrompts;

class WikiPromptsTest extends TestCase
{
    private const DOC_TITLE = 'ระเบียบห้องสมุด 2568';
    private const DOC_TEXT  = "เวลาทำการ จันทร์-ศุกร์ 08:30-16:30\n\nยืมได้ครั้งละ 5 เล่ม";

    // ────────────────────────────────────────────────────────────────
    // Shape: every prompt is a ready-to-send messages array
    // ────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, array{role: string, content: string}>>
     */
    private function allPrompts(): array
    {
        return [
            'buildPages'     => WikiPrompts::buildPagesPrompt(self::DOC_TITLE, self::DOC_TEXT),
            'buildQuestions' => WikiPrompts::buildQuestionsPrompt('เวลาทำการ', 'จันทร์-ศุกร์ 08:30-16:30'),
            'mergePages'     => WikiPrompts::mergePagesPrompt('library-hours', 'เวลาทำการ', 'เดิม', 'เวลาทำการ', 'ใหม่'),
            'gapAnalysis'    => WikiPrompts::gapAnalysisPrompt(['ห้องสมุดเปิดกี่โมง']),
        ];
    }

    public function test_every_prompt_is_a_valid_messages_array(): void
    {
        foreach ($this->allPrompts() as $name => $messages) {
            $this->assertCount(2, $messages, $name);
            $this->assertSame('system', $messages[0]['role'], $name);
            $this->assertSame('user',   $messages[1]['role'], $name);

            foreach ($messages as $m) {
                $this->assertArrayHasKey('content', $m, $name);
                $this->assertNotSame('', trim($m['content']), $name);
            }
        }
    }

    /**
     * Every prompt states the JSON contract that WikiService::decodeJson parses.
     */
    public function test_every_prompt_specifies_json_output(): void
    {
        foreach ($this->allPrompts() as $name => $messages) {
            $this->assertStringContainsString('JSON', $messages[0]['content'], $name);
            $this->assertStringContainsString('ห้ามครอบด้วย code fence', $messages[0]['content'], $name);
        }
    }

    // ────────────────────────────────────────────────────────────────
    // The hallucination guard
    // ────────────────────────────────────────────────────────────────

    public function test_pages_prompt_forbids_inventing_information(): void
    {
        $system = WikiPrompts::buildPagesPrompt(self::DOC_TITLE, self::DOC_TEXT)[0]['content'];

        $this->assertStringContainsString('ห้ามแต่งข้อมูลที่ไม่มีในเอกสาร', $system);
        $this->assertStringContainsString('ห้ามเดา', $system);
    }

    public function test_merge_prompt_forbids_inventing_and_dropping_information(): void
    {
        $system = WikiPrompts::mergePagesPrompt('library-hours', 'เวลาทำการ', 'เดิม', 'เวลาทำการ', 'ใหม่')[0]['content'];

        $this->assertStringContainsString('ห้ามแต่งข้อมูล', $system);
        $this->assertStringContainsString('ห้ามทิ้งข้อมูลเดิม', $system);
    }

    public function test_questions_prompt_only_asks_what_the_page_answers(): void
    {
        $system = WikiPrompts::buildQuestionsPrompt('เวลาทำการ', 'จันทร์-ศุกร์ 08:30-16:30')[0]['content'];

        $this->assertStringContainsString('ห้ามตั้งคำถามเรื่องที่ไม่มีในเนื้อหา', $system);
    }

    /**
     * The gap prompt sees only the fallback log — there is no source document
     * behind those questions, so the LLM must never write page content from it.
     */
    public function test_gap_prompt_forbids_writing_content(): void
    {
        $system = WikiPrompts::gapAnalysisPrompt(['ห้องสมุดเปิดกี่โมง'])[0]['content'];

        $this->assertStringContainsString('ห้ามแต่งคำตอบหรือเนื้อหาของหัวข้อ', $system);
    }

    // ────────────────────────────────────────────────────────────────
    // Content passthrough
    // ────────────────────────────────────────────────────────────────

    public function test_pages_prompt_carries_the_document_and_the_size_limit(): void
    {
        $messages = WikiPrompts::buildPagesPrompt(self::DOC_TITLE, self::DOC_TEXT);

        $this->assertStringContainsString(self::DOC_TITLE, $messages[1]['content']);
        $this->assertStringContainsString(self::DOC_TEXT,  $messages[1]['content']);
        $this->assertStringContainsString((string)WikiPrompts::MAX_PAGE_CHARS, $messages[0]['content']);
    }

    public function test_merge_prompt_carries_both_versions(): void
    {
        $user = WikiPrompts::mergePagesPrompt(
            'library-hours',
            'เวลาทำการ', 'เปิด 08:30',
            'เวลาทำการห้องสมุด', 'ปิด 16:30',
            ['library-location']
        )[1]['content'];

        $this->assertStringContainsString('เปิด 08:30', $user);
        $this->assertStringContainsString('ปิด 16:30',  $user);
        $this->assertStringContainsString('library-location', $user);
        $this->assertStringContainsString('library-hours', $user);
    }

    public function test_gap_prompt_lists_questions_and_existing_titles(): void
    {
        $user = WikiPrompts::gapAnalysisPrompt(
            ['จอดรถที่ไหน', 'มีที่จอดรถมั้ย'],
            ['เวลาทำการ']
        )[1]['content'];

        $this->assertStringContainsString('1. จอดรถที่ไหน', $user);
        $this->assertStringContainsString('2. มีที่จอดรถมั้ย', $user);
        $this->assertStringContainsString('- เวลาทำการ', $user);
    }

    public function test_gap_prompt_handles_an_empty_wiki(): void
    {
        $user = WikiPrompts::gapAnalysisPrompt(['จอดรถที่ไหน'], [])[1]['content'];

        $this->assertStringContainsString('ยังไม่มีหน้า wiki เลย', $user);
    }

    public function test_json_options_request_a_json_object(): void
    {
        $this->assertSame(
            ['response_format' => ['type' => 'json_object']],
            WikiPrompts::jsonOptions()
        );
    }
}
