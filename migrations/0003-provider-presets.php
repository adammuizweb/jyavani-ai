<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $hasColumn = static function (string $column) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['jai_provider_settings', $column]);
        return (int)$stmt->fetchColumn() > 0;
    };

    if (!$hasColumn('active_provider')) {
        $pdo->exec("ALTER TABLE `jai_provider_settings` ADD COLUMN `active_provider` VARCHAR(16) NOT NULL DEFAULT 'openai' AFTER `id`");
    }
    if (!$hasColumn('openai_api_key_encrypted')) {
        $pdo->exec('ALTER TABLE `jai_provider_settings` ADD COLUMN `openai_api_key_encrypted` TEXT NULL AFTER `active_provider`');
    }
    if (!$hasColumn('gemini_api_key_encrypted')) {
        $pdo->exec('ALTER TABLE `jai_provider_settings` ADD COLUMN `gemini_api_key_encrypted` TEXT NULL AFTER `openai_api_key_encrypted`');
    }

    $pdo->exec("UPDATE `jai_provider_settings`
        SET `active_provider` = CASE
            WHEN `provider_url` LIKE '%generativelanguage.googleapis.com%' THEN 'gemini'
            ELSE 'openai'
        END");
    $pdo->exec("UPDATE `jai_provider_settings`
        SET `openai_api_key_encrypted` = `api_key_encrypted`
        WHERE (`openai_api_key_encrypted` IS NULL OR `openai_api_key_encrypted` = '')
          AND `provider_url` NOT LIKE '%generativelanguage.googleapis.com%'");
    $pdo->exec("UPDATE `jai_provider_settings`
        SET `gemini_api_key_encrypted` = `api_key_encrypted`
        WHERE (`gemini_api_key_encrypted` IS NULL OR `gemini_api_key_encrypted` = '')
          AND `provider_url` LIKE '%generativelanguage.googleapis.com%'");
};
