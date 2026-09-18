CREATE TABLE IF NOT EXISTS `jai_provider_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `provider_url` VARCHAR(2048) NOT NULL,
  `model` VARCHAR(191) NOT NULL,
  `api_key_encrypted` TEXT NOT NULL,
  `api_key_hint` VARCHAR(8) NOT NULL DEFAULT '',
  `timeout_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `max_input_bytes` INT UNSIGNED NOT NULL DEFAULT 200000,
  `max_output_tokens` INT UNSIGNED NOT NULL DEFAULT 4000,
  `requests_per_minute` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `updated_by` INT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
