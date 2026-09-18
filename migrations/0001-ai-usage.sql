CREATE TABLE IF NOT EXISTS `jai_rate_limits` (
  `user_id` INT NOT NULL,
  `window_started_at` DATETIME(6) NOT NULL,
  `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jai_usage_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `resource_type` VARCHAR(20) NOT NULL,
  `resource_id` INT NULL,
  `operation` VARCHAR(32) NOT NULL,
  `model` VARCHAR(191) NOT NULL,
  `input_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `output_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `input_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `output_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('success','failed') NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jai_usage_created` (`created_at`),
  KEY `idx_jai_usage_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
