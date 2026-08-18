<?php

namespace App\Helpers;

trait BotSettingsTrait
{
    abstract protected function getBotId(): ?int;
    abstract protected function getDb(): Database;

    private function getDbSetting(string $key): ?string
    {
        $db    = $this->getDb();
        $botId = $this->getBotId();

        if ($botId) {
            $val = $db->fetchColumn(
                'SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = ?',
                [$botId, $key]
            );
            if ($val) return $val;
        }
        return $db->fetchColumn(
            'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1',
            [$key]
        );
    }
}
