-- ============================================================================
-- EQ Finance — MySQL / MariaDB installation script
-- Requires MySQL 5.7+ or MariaDB 10.3+. Run against an EMPTY database.
-- All timestamps are stored in UTC.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ----------------------------------------------------------------------------
-- Administrators
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  `last_login`    DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`),
  UNIQUE KEY `uq_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Admin login attempts (brute-force protection, keyed by hashed client IP)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`      CHAR(64)     NOT NULL,
  `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt` DATETIME     NOT NULL,
  `locked_until` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_attempts_ip` (`ip_hash`),
  KEY `idx_login_attempts_last` (`last_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- API keys. Only a SHA-256 hash of the key is stored; `key_prefix` holds the
-- first characters for display (e.g. eqf_live_a1b2c3).
-- status: active | disabled | revoked
-- Limits: NULL = unlimited.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(100) NOT NULL,
  `key_prefix`          VARCHAR(20)  NOT NULL,
  `key_hash`            CHAR(64)     NOT NULL,
  `status`              ENUM('active','disabled','revoked') NOT NULL DEFAULT 'active',
  `requests_per_minute` INT UNSIGNED NULL DEFAULT NULL,
  `requests_per_day`    INT UNSIGNED NULL DEFAULT NULL,
  `monthly_limit`       INT UNSIGNED NULL DEFAULT NULL,
  `expires_at`          DATETIME     NULL DEFAULT NULL,
  `replaced_by_id`      INT UNSIGNED NULL DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL,
  `updated_at`          DATETIME     NOT NULL,
  `last_used_at`        DATETIME     NULL DEFAULT NULL,
  `revoked_at`          DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_keys_hash` (`key_hash`),
  KEY `idx_api_keys_status` (`status`),
  KEY `idx_api_keys_expires` (`expires_at`),
  CONSTRAINT `fk_api_keys_replaced_by` FOREIGN KEY (`replaced_by_id`)
    REFERENCES `api_keys` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- API usage log (one row per request). Never stores keys or auth headers.
-- api_key_id is NULL for unauthenticated / rejected requests.
-- cache_status: hit | miss | stale | none
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_usage` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key_id`       INT UNSIGNED    NULL DEFAULT NULL,
  `endpoint`         VARCHAR(40)     NOT NULL,
  `requested_symbol` VARCHAR(255)    NULL DEFAULT NULL,
  `response_status`  SMALLINT UNSIGNED NOT NULL,
  `response_time_ms` INT UNSIGNED    NOT NULL DEFAULT 0,
  `cache_status`     ENUM('hit','miss','stale','none') NOT NULL DEFAULT 'none',
  `error_code`       VARCHAR(40)     NULL DEFAULT NULL,
  `ip_hash`          CHAR(64)        NULL DEFAULT NULL,
  `created_at`       DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_api_usage_key_created` (`api_key_id`, `created_at`),
  KEY `idx_api_usage_created` (`created_at`),
  KEY `idx_api_usage_endpoint` (`endpoint`),
  KEY `idx_api_usage_status` (`response_status`),
  KEY `idx_api_usage_symbol` (`requested_symbol`(64)),
  CONSTRAINT `fk_api_usage_key` FOREIGN KEY (`api_key_id`)
    REFERENCES `api_keys` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Quote cache. One row per symbol. response_json is NULL only for lock rows
-- (a fetch is in progress and no valid data has ever been stored).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_cache` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol`        VARCHAR(64)  NOT NULL,
  `response_json` MEDIUMTEXT   NULL DEFAULT NULL,
  `source_url`    VARCHAR(255) NULL DEFAULT NULL,
  `fetched_at`    DATETIME     NULL DEFAULT NULL,
  `expires_at`    DATETIME     NULL DEFAULT NULL,
  `lock_until`    DATETIME     NULL DEFAULT NULL,
  `hit_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_cache_symbol` (`symbol`),
  KEY `idx_stock_cache_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Settings (key/value, editable from the admin panel)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(64)  NOT NULL,
  `setting_value` TEXT         NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Rate-limit counters. request_window examples:
--   m:202611041956  (minute)   d:20261104 (day)   mo:202611 (month)
-- Incremented atomically with INSERT ... ON DUPLICATE KEY UPDATE.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key_id`     INT UNSIGNED    NOT NULL,
  `request_window` VARCHAR(24)     NOT NULL,
  `request_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `updated_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_limits_key_window` (`api_key_id`, `request_window`),
  KEY `idx_rate_limits_updated` (`updated_at`),
  CONSTRAINT `fk_rate_limits_key` FOREIGN KEY (`api_key_id`)
    REFERENCES `api_keys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Default settings
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
  ('api_name',                    'EQ Finance',                                   UTC_TIMESTAMP()),
  ('api_description',             'Lightweight Google Finance scraping API.',     UTC_TIMESTAMP()),
  ('api_version',                 '1.0.0',                                        UTC_TIMESTAMP()),
  ('api_enabled',                 '1',                                            UTC_TIMESTAMP()),
  ('maintenance_mode',            '0',                                            UTC_TIMESTAMP()),
  ('cache_ttl_seconds',           '60',                                           UTC_TIMESTAMP()),
  ('cache_serve_stale',           '0',                                            UTC_TIMESTAMP()),
  ('cache_max_stale_seconds',     '900',                                          UTC_TIMESTAMP()),
  ('cache_retention_seconds',     '86400',                                        UTC_TIMESTAMP()),
  ('request_timeout_seconds',     '12',                                           UTC_TIMESTAMP()),
  ('connect_timeout_seconds',     '5',                                            UTC_TIMESTAMP()),
  ('max_batch_size',              '10',                                           UTC_TIMESTAMP()),
  ('default_requests_per_minute', '30',                                           UTC_TIMESTAMP()),
  ('default_requests_per_day',    '1000',                                         UTC_TIMESTAMP()),
  ('default_monthly_limit',       '0',                                            UTC_TIMESTAMP()),
  ('user_agent',                  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', UTC_TIMESTAMP()),
  ('accept_language',             'en-US,en;q=0.9',                               UTC_TIMESTAMP()),
  ('log_retention_days',          '90',                                           UTC_TIMESTAMP());
