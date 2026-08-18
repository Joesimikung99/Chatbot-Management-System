<?php
/**
 * Wiki Prompt Templates
 * AI Chatbot System — CBMS
 *
 * Pure prompt builders for the LLM wiki knowledge layer. No DB, no API calls —
 * every method returns a messages array ready for OpenRouterService::chat().
 *
 * The three prompts:
 *   1. buildPagesPrompt()     — เอกสารดิบ  → หน้า wiki หลายหน้า
 *   2. buildQuestionsPrompt() — หน้า wiki  → คำถามตัวอย่าง (doc2query)
 *   3. mergePagesPrompt()     — หน้าเดิม + เอกสารใหม่ที่พูดเรื่องเดียวกัน → หน้าเดียว
 *
 * ทุก prompt เน้นคำสั่ง "ห้ามแต่งข้อมูลที่ไม่มีในเอกสาร" — นี่คือ guard หลัก
 * กัน hallucination ในขั้นสรุป (อีกชั้นคือ D5: ทุกหน้าเป็น draft จนกว่าคนจะ approve)
 */

namespace App\Services;

class WikiPrompts
{
    /** เป้าความยาวเนื้อหาต่อหน้า (ตัวอักษรไทย) — D4 */
    public const MAX_PAGE_CHARS = 2000;

    /** จำนวนคำถามตัวอย่างต่อหน้า */
    public const MIN_QUESTIONS = 5;
    public const MAX_QUESTIONS = 10;

    /**
     * Options to pass to OpenRouterService::chat() so the model replies with a
     * JSON object. Models that ignore response_format still produce parseable
     * JSON because every prompt below states the schema explicitly (and
     * WikiService::decodeJson strips code fences defensively).
     *
     * @return array<string, mixed>
     */
    public static function jsonOptions(): array
    {
        return ['response_format' => ['type' => 'json_object']];
    }

    // ----------------------------------------------------------------
    // 1. Document → wiki pages
    // ----------------------------------------------------------------

    /**
     * สั่งให้ LLM อ่านเอกสารหนึ่งฉบับแล้วแตกเป็นหน้า wiki ต่อหัวข้อ
     *
     * Expected model output:
     * {
     *   "pages": [
     *     {
     *       "slug": "library-hours",
     *       "title": "เวลาทำการของห้องสมุด",
     *       "summary": "เวลาเปิด-ปิดวันธรรมดา เสาร์-อาทิตย์ และช่วงปิดเทอม",
     *       "content": "## เวลาทำการ\n- จันทร์-ศุกร์ 08:30-16:30\n...",
     *       "linked_slugs": ["library-location"]
     *     }
     *   ]
     * }
     *
     * @param  string $docTitle ชื่อไฟล์/เอกสารต้นทาง
     * @param  string $docText  เนื้อหาเต็มของเอกสาร
     * @return array<int, array{role: string, content: string}>
     */
    public static function buildPagesPrompt(string $docTitle, string $docText): array
    {
        $max = self::MAX_PAGE_CHARS;

        $system = <<<SYS
        คุณคือบรรณาธิการฐานความรู้ (knowledge base editor) ของหน่วยงานไทย
        หน้าที่ของคุณคือเรียบเรียงเอกสารที่ได้รับ ให้กลายเป็น "หน้า wiki" ที่ผู้ใช้อ่านแล้วได้คำตอบครบจบในหน้าเดียว

        ## กฎเหล็ก (สำคัญที่สุด — ห้ามฝ่าฝืนเด็ดขาด)

        - **ห้ามแต่งข้อมูลที่ไม่มีในเอกสารโดยเด็ดขาด** ทุกตัวเลข วันเวลา ชื่อ สถานที่ เบอร์โทร เงื่อนไข ต้องมาจากเอกสารที่ให้มาเท่านั้น
        - ห้ามเดา ห้ามเติมข้อมูลจากความรู้ทั่วไปของคุณ ห้ามคาดคะเนสิ่งที่เอกสารไม่ได้บอก
        - ถ้าเอกสารกำกวมหรือไม่ครบ ให้เขียนเท่าที่เอกสารบอกจริง — ขาดดีกว่าเกิน
        - ห้ามสรุปจนความหมายเพี้ยน ถ้าเป็นข้อมูลสำคัญ (ตัวเลข/เงื่อนไข) ให้คงถ้อยคำเดิมไว้

        ## วิธีแบ่งหน้า

        - หนึ่งหน้า = หนึ่งหัวข้อที่ผู้ใช้น่าจะถาม (เช่น "เวลาทำการ", "การยืม-คืนหนังสือ", "การสมัครสมาชิก")
        - เนื้อหาต่อหน้า **ไม่เกิน {$max} ตัวอักษร** ถ้าหัวข้อใหญ่เกินให้แตกเป็นหลายหน้าแล้วอ้างถึงกันผ่าน linked_slugs
        - หน้าต้อง "ครบจบในตัว" — ผู้อ่านที่เห็นแค่หน้านี้หน้าเดียวต้องตอบคำถามในหัวข้อนี้ได้
        - เอกสารหนึ่งฉบับอาจได้ 1 หน้าหรือหลายหน้าก็ได้ ตามจำนวนหัวข้อที่มีจริง
        - ถ้าเอกสารไม่มีเนื้อหาที่เป็นประโยชน์เลย ให้คืน pages เป็น array ว่าง

        ## รูปแบบของแต่ละฟิลด์

        - `slug`: ตัวพิมพ์เล็กภาษาอังกฤษ คั่นด้วยขีด (kebab-case ASCII) แปลหัวข้อไทยเป็นอังกฤษสั้นๆ เช่น "library-hours", "book-borrowing"
        - `title`: ชื่อหัวข้อภาษาไทย สั้น ชัด ตรงกับสิ่งที่ผู้ใช้จะถาม
        - `summary`: 1-2 ประโยคภาษาไทย บอกว่าหน้านี้ตอบเรื่องอะไรได้บ้าง (ใช้ทำสารบัญ)
        - `content`: เนื้อหาภาษาไทยแบบ markdown ใช้หัวข้อย่อย/bullet ให้อ่านง่าย
        - `linked_slugs`: array ของ slug หน้าอื่นในชุดนี้ที่เกี่ยวข้อง (ถ้าไม่มีให้ใส่ [])

        ## รูปแบบคำตอบ

        ตอบเป็น JSON object เท่านั้น ห้ามมีข้อความอื่นนอก JSON ห้ามครอบด้วย code fence:

        {"pages":[{"slug":"...","title":"...","summary":"...","content":"...","linked_slugs":["..."]}]}
        SYS;

        $user = <<<USR
        ชื่อเอกสาร: {$docTitle}

        --- เริ่มเนื้อหาเอกสาร ---
        {$docText}
        --- จบเนื้อหาเอกสาร ---

        เรียบเรียงเอกสารข้างต้นเป็นหน้า wiki ตามกฎที่กำหนด แล้วตอบเป็น JSON object ตามรูปแบบที่ระบุ
        USR;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    // ----------------------------------------------------------------
    // 2. Wiki page → example questions (doc2query)
    // ----------------------------------------------------------------

    /**
     * สร้างคำถามตัวอย่างที่ผู้ใช้จริงน่าจะพิมพ์ถามหน้านี้
     *
     * คำถามเหล่านี้จะถูก embed แล้วใช้เป็น retrieval tier 1 — คำถามของผู้ใช้
     * เทียบกับ "คำถาม" ด้วยกันได้คะแนน cosine สูงกว่าเทียบกับ "เนื้อหา" มาก
     * จึงต้องเขียนให้เหมือนภาษาที่คนพิมพ์จริง ไม่ใช่ภาษาทางการ
     *
     * Expected model output:
     * {"questions": ["ห้องสมุดเปิดกี่โมง", "วันเสาร์เปิดมั้ย", ...]}
     *
     * @return array<int, array{role: string, content: string}>
     */
    public static function buildQuestionsPrompt(string $title, string $content): array
    {
        $min = self::MIN_QUESTIONS;
        $max = self::MAX_QUESTIONS;

        $system = <<<SYS
        คุณคือผู้ช่วยที่เข้าใจว่า "คนไทยทั่วไปพิมพ์ถามแชทบอทยังไง"
        หน้าที่ของคุณคืออ่านหน้าความรู้ที่ได้รับ แล้วเดาคำถามที่ผู้ใช้จริงน่าจะพิมพ์เข้ามาเพื่อหาข้อมูลในหน้านี้

        ## กฎ

        - **สร้างเฉพาะคำถามที่หน้านี้ตอบได้จริง** ห้ามตั้งคำถามเรื่องที่ไม่มีในเนื้อหา
        - ตั้งคำถาม {$min}-{$max} ข้อ ครอบคลุมประเด็นต่างๆ ในหน้า ไม่ถามซ้ำประเด็นเดิม
        - เขียนแบบภาษาพูดที่คนพิมพ์จริง ไม่ต้องเป็นทางการ ไม่ต้องมีเครื่องหมายคำถามก็ได้
        - ผสมทั้งคำถามสั้น (3-6 คำ เช่น "เปิดกี่โมง") และคำถามยาวแบบเต็มประโยค
        - สะกดแบบที่คนพิมพ์จริง รวมคำย่อ/คำแสลงที่ใช้กันทั่วไป (เช่น "มั้ย", "ยังไง", "กี่โมง")
        - ใช้คำเรียกสิ่งเดียวกันให้หลากหลายเท่าที่เนื้อหาสื่อถึง (เช่น ห้องสมุด / หอสมุด)
        - ภาษาไทยเป็นหลัก เพิ่มภาษาอังกฤษได้ 1-2 ข้อถ้าหัวข้อนั้นคนมักถามเป็นอังกฤษ

        ## รูปแบบคำตอบ

        ตอบเป็น JSON object เท่านั้น ห้ามมีข้อความอื่นนอก JSON ห้ามครอบด้วย code fence:

        {"questions":["คำถามข้อ 1","คำถามข้อ 2"]}
        SYS;

        $user = <<<USR
        หัวข้อ: {$title}

        --- เริ่มเนื้อหาหน้า wiki ---
        {$content}
        --- จบเนื้อหาหน้า wiki ---

        สร้างคำถามตัวอย่างที่ผู้ใช้น่าจะถามหน้านี้ แล้วตอบเป็น JSON object ตามรูปแบบที่ระบุ
        USR;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    // ----------------------------------------------------------------
    // 3. Merge: existing page + new document about the same topic
    // ----------------------------------------------------------------

    /**
     * รวมหน้า wiki ที่มีอยู่แล้ว เข้ากับเนื้อหาจากเอกสารอีกฉบับที่พูดเรื่องเดียวกัน
     * (เกิดเมื่อ slug ที่ LLM เสนอชนกับหน้าเดิม)
     *
     * ห้ามทิ้งข้อมูลเดิม — หน้าเดิมอาจมาจากเอกสารที่ยังไม่ได้ส่งมารอบนี้
     *
     * Expected model output:
     * {"page": {"title":"...","summary":"...","content":"...","linked_slugs":[...]}}
     *
     * @param  string   $slug            slug ของหน้าที่ชนกัน
     * @param  string   $existingTitle   title ปัจจุบันของหน้า
     * @param  string   $existingContent เนื้อหาปัจจุบันของหน้า
     * @param  string   $newTitle        title ที่ LLM เสนอจากเอกสารใหม่
     * @param  string   $newContent      เนื้อหาที่ LLM เสนอจากเอกสารใหม่
     * @param  string[] $linkedSlugs     slug ที่เกี่ยวข้อง (รวมของเดิม + ใหม่)
     * @return array<int, array{role: string, content: string}>
     */
    public static function mergePagesPrompt(
        string $slug,
        string $existingTitle,
        string $existingContent,
        string $newTitle,
        string $newContent,
        array  $linkedSlugs = []
    ): array {
        $max    = self::MAX_PAGE_CHARS;
        $linked = $linkedSlugs === [] ? '(ไม่มี)' : implode(', ', $linkedSlugs);

        $system = <<<SYS
        คุณคือบรรณาธิการฐานความรู้ (knowledge base editor) ของหน่วยงานไทย
        หน้าที่ของคุณคือรวมหน้า wiki ที่มีอยู่แล้ว เข้ากับเนื้อหาใหม่ที่พูดถึงหัวข้อเดียวกัน ให้เหลือหน้าเดียวที่สมบูรณ์

        ## กฎเหล็ก (สำคัญที่สุด — ห้ามฝ่าฝืนเด็ดขาด)

        - **ห้ามแต่งข้อมูลที่ไม่มีในสองเวอร์ชันที่ให้มา** ห้ามเติมจากความรู้ทั่วไปของคุณ
        - **ห้ามทิ้งข้อมูลเดิม** ข้อมูลที่มีเฉพาะในเวอร์ชันเดิมต้องยังอยู่ในผลลัพธ์
        - ข้อมูลที่ซ้ำกันให้รวมเป็นชุดเดียว ไม่ต้องเขียนซ้ำสองรอบ
        - ถ้าสองเวอร์ชัน **ขัดแย้งกัน** (เช่น เวลาเปิดไม่ตรงกัน) ห้ามเลือกข้างเอง ให้เขียนไว้ทั้งสองแบบพร้อมระบุเงื่อนไขที่ต่างกันเท่าที่เนื้อหาบอก
        - เนื้อหารวมแล้ว **ไม่เกิน {$max} ตัวอักษร** — ถ้าจะเกิน ให้กระชับสำนวน ไม่ใช่ตัดข้อมูลทิ้ง

        ## รูปแบบคำตอบ

        ตอบเป็น JSON object เท่านั้น ห้ามมีข้อความอื่นนอก JSON ห้ามครอบด้วย code fence:

        {"page":{"title":"...","summary":"...","content":"...","linked_slugs":["..."]}}

        - `title`: ชื่อหัวข้อภาษาไทยที่ครอบคลุมเนื้อหารวม
        - `summary`: 1-2 ประโยค บอกว่าหน้านี้ตอบเรื่องอะไรได้บ้าง
        - `content`: เนื้อหารวมภาษาไทยแบบ markdown
        - `linked_slugs`: array ของ slug หน้าอื่นที่เกี่ยวข้อง
        SYS;

        $user = <<<USR
        slug ของหน้า: {$slug}
        slug ที่เกี่ยวข้อง: {$linked}

        --- เวอร์ชันเดิมที่มีอยู่ในระบบ ---
        หัวข้อ: {$existingTitle}

        {$existingContent}
        --- จบเวอร์ชันเดิม ---

        --- เนื้อหาใหม่จากเอกสารอีกฉบับ ---
        หัวข้อ: {$newTitle}

        {$newContent}
        --- จบเนื้อหาใหม่ ---

        รวมสองเวอร์ชันข้างต้นเป็นหน้าเดียวตามกฎที่กำหนด แล้วตอบเป็น JSON object ตามรูปแบบที่ระบุ
        USR;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    // ----------------------------------------------------------------
    // 4. Gap analysis: fallback questions → missing topics
    // ----------------------------------------------------------------

    /**
     * จัดกลุ่มคำถามที่บอทตอบไม่ได้ (messages.is_fallback = 1) เป็นหัวข้อ
     * เพื่อเสนอว่าควรเพิ่มหน้า wiki เรื่องอะไร
     *
     * **สังเกต:** prompt นี้ห้าม LLM แต่ง "เนื้อหา" — สั่งให้จัดกลุ่มคำถามเท่านั้น
     * เพราะ fallback log ไม่มีแหล่งข้อมูลรองรับ เนื้อหาต้องให้คนเขียนเอง (WP-7)
     *
     * Expected model output:
     * {"topics": [{"topic":"...","frequency":3,"sample_questions":["..."],"suggested_slug":"..."}]}
     *
     * @param  string[] $questions คำถามที่ตอบไม่ได้
     * @param  string[] $existingTitles หัวข้อ wiki ที่มีอยู่แล้ว (ไว้ให้ LLM เลี่ยงเสนอซ้ำ)
     * @return array<int, array{role: string, content: string}>
     */
    public static function gapAnalysisPrompt(array $questions, array $existingTitles = []): array
    {
        $questionList = '';
        foreach ($questions as $i => $q) {
            $questionList .= ($i + 1) . '. ' . $q . "\n";
        }
        $existing = $existingTitles === [] ? '(ยังไม่มีหน้า wiki เลย)' : '- ' . implode("\n- ", $existingTitles);

        $system = <<<SYS
        คุณคือนักวิเคราะห์ที่ช่วยหาช่องว่างของฐานความรู้แชทบอท
        คุณจะได้รับรายการคำถามที่บอท "ตอบไม่ได้" (ไม่มีข้อมูลในระบบ) ให้จัดกลุ่มเป็นหัวข้อ

        ## กฎ

        - **ห้ามแต่งคำตอบหรือเนื้อหาของหัวข้อ** หน้าที่ของคุณคือจัดกลุ่มและตั้งชื่อหัวข้อเท่านั้น
        - จัดคำถามที่ถามเรื่องเดียวกัน (แม้สำนวนต่างกัน) ให้อยู่กลุ่มเดียวกัน
        - `frequency` = จำนวนคำถามในรายการที่เข้ากลุ่มนั้น (นับจากรายการที่ให้มาเท่านั้น ห้ามเดา)
        - `sample_questions` = คำถามตัวอย่างจากรายการ ไม่เกิน 3 ข้อ ต้องคัดลอกจากรายการจริง ห้ามแต่งใหม่
        - `suggested_slug` = kebab-case ASCII สำหรับหน้า wiki ที่ควรสร้าง
        - เรียงกลุ่มจาก frequency มากไปน้อย
        - **ข้ามคำถามที่เป็นการทักทาย คำถามส่วนตัว หรือข้อความไร้สาระ** ไม่ต้องจัดกลุ่ม
        - ถ้าหัวข้อนั้นตรงกับหน้า wiki ที่มีอยู่แล้ว ไม่ต้องเสนอ (แปลว่าปัญหาอยู่ที่ retrieval ไม่ใช่ข้อมูลขาด)

        ## รูปแบบคำตอบ

        ตอบเป็น JSON object เท่านั้น ห้ามมีข้อความอื่นนอก JSON ห้ามครอบด้วย code fence:

        {"topics":[{"topic":"...","frequency":3,"sample_questions":["..."],"suggested_slug":"..."}]}
        SYS;

        $user = <<<USR
        ## หน้า wiki ที่มีอยู่แล้วในระบบ

        {$existing}

        ## คำถามที่บอทตอบไม่ได้

        {$questionList}
        จัดกลุ่มคำถามข้างต้นเป็นหัวข้อ แล้วตอบเป็น JSON object ตามรูปแบบที่ระบุ
        USR;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }
}
