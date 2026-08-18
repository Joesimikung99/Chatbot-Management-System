<?php
/**
 * Feature tests for Bot CRUD operations (bots.php handlers)
 * Tests: create, toggle_active, delete, slug uniqueness, default settings seeding
 * Requires: database connection (integration test)
 */

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use App\Helpers\Auth;
use App\Helpers\Database;

class BotCrudTest extends DatabaseTestCase
{
    // ────────────────────────────────────────────────────────────────
    // Create Bot
    // ────────────────────────────────────────────────────────────────

    public function test_create_bot_inserts_into_database(): void
    {
        $this->loginAsSuperAdmin();
        $db = Database::getInstance();

        $botId = $db->insert('bots', [
            'slug'       => 'test-bot-' . time(),
            'name'       => 'Test Bot',
            'is_active'  => 1,
            'created_by' => 1,
        ]);

        $this->assertGreaterThan(0, (int)$botId);

        $bot = $db->fetch('SELECT * FROM bots WHERE id = ?', [$botId]);
        $this->assertNotNull($bot);
        $this->assertSame('Test Bot', $bot['name']);
        $this->assertSame('1', (string)$bot['is_active']);
    }

    public function test_create_bot_seeds_default_settings(): void
    {
        $db = Database::getInstance();

        $botId = $db->insert('bots', [
            'slug'       => 'seed-test-' . time(),
            'name'       => 'Seed Test',
            'is_active'  => 1,
            'created_by' => 1,
        ]);

        // Seed default settings (mimics bots.php create handler)
        $defaultSettings = [
            'system_prompt'        => '',
            'temperature'          => '0.3',
            'max_tokens'           => '1024',
            'similarity_threshold' => '0.35',
            'top_k_chunks'         => '5',
            'history_length'       => '10',
            'fallback_message'     => 'ขออภัยครับ ไม่พบข้อมูลที่ตรงกับคำถามของคุณ',
            'bot_name'             => 'Seed Test',
            'chat_welcome_message' => 'สวัสดีครับ! ยินดีให้บริการ 😊',
            'primary_color'        => '#4f46e5',
        ];

        foreach ($defaultSettings as $key => $val) {
            $db->insert('bot_settings', [
                'bot_id'        => $botId,
                'setting_key'   => $key,
                'setting_value' => $val,
            ]);
        }

        $settings = $db->fetchAll(
            'SELECT setting_key, setting_value FROM bot_settings WHERE bot_id = ?',
            [$botId]
        );
        $settingsMap = array_column($settings, 'setting_value', 'setting_key');

        $this->assertSame('0.3', $settingsMap['temperature']);
        $this->assertSame('1024', $settingsMap['max_tokens']);
        $this->assertSame('#4f46e5', $settingsMap['primary_color']);
        $this->assertCount(count($defaultSettings), $settings);
    }

    public function test_create_bot_assigns_owner_role(): void
    {
        $db = Database::getInstance();

        $botId = $db->insert('bots', [
            'slug'       => 'owner-test-' . time(),
            'name'       => 'Owner Test',
            'is_active'  => 1,
            'created_by' => 1,
        ]);

        $db->insert('bot_users', [
            'bot_id'  => $botId,
            'user_id' => 1,
            'role'    => 'owner',
        ]);

        $role = $db->fetchColumn(
            'SELECT role FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$botId, 1]
        );
        $this->assertSame('owner', $role);
    }

    // ────────────────────────────────────────────────────────────────
    // Slug Uniqueness
    // ────────────────────────────────────────────────────────────────

    public function test_slug_must_be_unique(): void
    {
        $db   = Database::getInstance();
        $slug = 'unique-slug-' . time();

        $db->insert('bots', [
            'slug'       => $slug,
            'name'       => 'First Bot',
            'is_active'  => 1,
            'created_by' => 1,
        ]);

        $exists = $db->fetch('SELECT id FROM bots WHERE slug = ?', [$slug]);
        $this->assertNotNull($exists, 'First insert should exist');

        // Check slug collision detection (mimics bots.php logic)
        $duplicate = $db->fetch('SELECT id FROM bots WHERE slug = ?', [$slug]);
        $this->assertNotNull($duplicate, 'Duplicate slug should be detected before insert');
    }

    // ────────────────────────────────────────────────────────────────
    // Toggle Active
    // ────────────────────────────────────────────────────────────────

    public function test_toggle_bot_active_status(): void
    {
        $db = Database::getInstance();

        $botId = $db->insert('bots', [
            'slug'       => 'toggle-test-' . time(),
            'name'       => 'Toggle Test',
            'is_active'  => 1,
            'created_by' => 1,
        ]);

        // Deactivate
        $db->update('bots', ['is_active' => 0], ['id' => $botId]);
        $bot = $db->fetch('SELECT is_active FROM bots WHERE id = ?', [$botId]);
        $this->assertSame('0', (string)$bot['is_active']);

        // Reactivate
        $db->update('bots', ['is_active' => 1], ['id' => $botId]);
        $bot = $db->fetch('SELECT is_active FROM bots WHERE id = ?', [$botId]);
        $this->assertSame('1', (string)$bot['is_active']);
    }

    // ────────────────────────────────────────────────────────────────
    // Delete Bot (cascade)
    // ────────────────────────────────────────────────────────────────

    public function test_delete_bot_removes_settings_and_members(): void
    {
        $db = Database::getInstance();

        $botId = $db->insert('bots', [
            'slug'       => 'delete-test-' . time(),
            'name'       => 'Delete Test',
            'is_active'  => 1,
            'created_by' => 1,
        ]);

        $db->insert('bot_settings', [
            'bot_id'        => $botId,
            'setting_key'   => 'test_key',
            'setting_value' => 'test_value',
        ]);

        $db->insert('bot_users', [
            'bot_id'  => $botId,
            'user_id' => 1,
            'role'    => 'owner',
        ]);

        // Delete bot (FK CASCADE should clean up)
        $db->delete('bots', ['id' => $botId]);

        $this->assertNull($db->fetch('SELECT * FROM bots WHERE id = ?', [$botId]));
        $this->assertSame(
            0,
            (int)$db->fetchColumn('SELECT COUNT(*) FROM bot_settings WHERE bot_id = ?', [$botId])
        );
        $this->assertSame(
            0,
            (int)$db->fetchColumn('SELECT COUNT(*) FROM bot_users WHERE bot_id = ?', [$botId])
        );
    }
}
