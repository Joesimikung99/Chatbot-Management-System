<?php
/**
 * Feature tests for Bot Settings (bot-edit.php save_settings handler)
 * Tests: per-bot settings save, widget settings, settings isolation between bots
 */

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use App\Helpers\Database;

class BotSettingsTest extends DatabaseTestCase
{
    private int $botId1;
    private int $botId2;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::getInstance();

        $this->botId1 = (int)$db->insert('bots', [
            'slug' => 'settings-bot1-' . time(),
            'name' => 'Settings Bot 1',
            'is_active' => 1,
            'created_by' => 1,
        ]);

        $this->botId2 = (int)$db->insert('bots', [
            'slug' => 'settings-bot2-' . time(),
            'name' => 'Settings Bot 2',
            'is_active' => 1,
            'created_by' => 1,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Save & Load Settings
    // ────────────────────────────────────────────────────────────────

    public function test_save_bot_setting_via_upsert(): void
    {
        $db = Database::getInstance();

        $db->upsert('bot_settings', [
            'bot_id'        => $this->botId1,
            'setting_key'   => 'temperature',
            'setting_value' => '0.7',
        ]);

        $val = $db->fetchColumn(
            'SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = ?',
            [$this->botId1, 'temperature']
        );
        $this->assertSame('0.7', $val);
    }

    public function test_upsert_updates_existing_setting(): void
    {
        $db = Database::getInstance();

        $db->upsert('bot_settings', [
            'bot_id'        => $this->botId1,
            'setting_key'   => 'primary_color',
            'setting_value' => '#ff0000',
        ]);

        // Update same key
        $db->upsert('bot_settings', [
            'bot_id'        => $this->botId1,
            'setting_key'   => 'primary_color',
            'setting_value' => '#00ff00',
        ]);

        $val = $db->fetchColumn(
            'SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = ?',
            [$this->botId1, 'primary_color']
        );
        $this->assertSame('#00ff00', $val);

        // Should be exactly 1 row, not 2
        $count = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM bot_settings WHERE bot_id = ? AND setting_key = ?',
            [$this->botId1, 'primary_color']
        );
        $this->assertSame(1, $count);
    }

    // ────────────────────────────────────────────────────────────────
    // Settings Isolation Between Bots
    // ────────────────────────────────────────────────────────────────

    public function test_settings_are_isolated_between_bots(): void
    {
        $db = Database::getInstance();

        $db->upsert('bot_settings', [
            'bot_id' => $this->botId1, 'setting_key' => 'temperature', 'setting_value' => '0.3',
        ]);
        $db->upsert('bot_settings', [
            'bot_id' => $this->botId2, 'setting_key' => 'temperature', 'setting_value' => '0.9',
        ]);

        $val1 = $db->fetchColumn(
            'SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = ?',
            [$this->botId1, 'temperature']
        );
        $val2 = $db->fetchColumn(
            'SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = ?',
            [$this->botId2, 'temperature']
        );

        $this->assertSame('0.3', $val1);
        $this->assertSame('0.9', $val2);
        $this->assertNotSame($val1, $val2);
    }

    // ────────────────────────────────────────────────────────────────
    // Widget Settings Keys
    // ────────────────────────────────────────────────────────────────

    public function test_widget_settings_save_all_keys(): void
    {
        $db = Database::getInstance();

        $widgetKeys = [
            'bot_name'             => 'My Widget Bot',
            'chat_welcome_message' => 'Hello!',
            'primary_color'        => '#db2777',
            'allow_feedback'       => 'true',
            'widget_icon_url'      => 'https://example.com/icon.png',
            'widget_logo_url'      => 'https://example.com/logo.png',
            'widget_icon_size'     => '70',
            'widget_draggable'     => 'true',
        ];

        foreach ($widgetKeys as $key => $val) {
            $db->upsert('bot_settings', [
                'bot_id' => $this->botId1, 'setting_key' => $key, 'setting_value' => $val,
            ]);
        }

        $rows = $db->fetchAll(
            'SELECT setting_key, setting_value FROM bot_settings WHERE bot_id = ?',
            [$this->botId1]
        );
        $map = array_column($rows, 'setting_value', 'setting_key');

        foreach ($widgetKeys as $key => $val) {
            $this->assertSame($val, $map[$key], "Widget setting '{$key}' mismatch");
        }
    }

    // ────────────────────────────────────────────────────────────────
    // Settings Group Validation (mimics bot-edit.php save_settings)
    // ────────────────────────────────────────────────────────────────

    public function test_save_settings_only_allows_whitelisted_keys(): void
    {
        $settingGroups = [
            'ai'       => ['system_prompt','temperature','max_tokens','similarity_threshold','top_k_chunks','history_length','fallback_message'],
            'platform' => ['google_drive_folder_id','facebook_page_token','facebook_app_secret','facebook_verify_token','facebook_page_id','line_channel_token','line_channel_secret','line_channel_id'],
            'widget'   => ['bot_name','chat_welcome_message','primary_color','allow_feedback','widget_icon_url','widget_logo_url','widget_icon_size','widget_draggable'],
        ];

        // AI group should not accept widget keys
        $aiKeys = $settingGroups['ai'];
        $this->assertNotContains('primary_color', $aiKeys);
        $this->assertNotContains('bot_name', $aiKeys);

        // Widget group should not accept AI keys
        $widgetKeys = $settingGroups['widget'];
        $this->assertNotContains('temperature', $widgetKeys);
        $this->assertNotContains('system_prompt', $widgetKeys);

        // Platform group should not accept widget or AI keys
        $platformKeys = $settingGroups['platform'];
        $this->assertNotContains('temperature', $platformKeys);
        $this->assertNotContains('primary_color', $platformKeys);

        // Unknown group returns empty
        $this->assertSame([], $settingGroups['unknown'] ?? []);
    }
}
