-- PingGlass Database Structure
-- Import this file to set up the database schema
-- Usage: mysql -u root -p pingglass < database/pingglass_sample.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: users
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `password` VARCHAR(255) NOT NULL,
    `remember_token` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user (password: "password")
INSERT INTO `users` (`id`, `name`, `email`, `password`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'admin@pingglass.local', '$2y$12$QFWJZ1qM8yYH1R9vKZ1qMOeGqXkZ6l5R5pQXO7ZlVbS9YbCqPv6G', NOW(), NOW());

-- -------------------------------------------
-- Table: password_reset_tokens
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: sessions
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(255) NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `payload` LONGTEXT NOT NULL,
    `last_activity` INT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `sessions_user_id_index` (`user_id`),
    KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: jobs
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL,
    `reserved_at` INT UNSIGNED DEFAULT NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: job_batches
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `job_batches` (
    `id` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `total_jobs` INT NOT NULL,
    `pending_jobs` INT NOT NULL,
    `failed_jobs` INT NOT NULL,
    `failed_job_ids` TEXT DEFAULT NULL,
    `options` MEDIUMTEXT DEFAULT NULL,
    `cancelled_at` INT DEFAULT NULL,
    `created_at` INT NOT NULL,
    `finished_at` INT DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: failed_jobs
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(255) NOT NULL,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: categories
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: targets
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `targets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `host` VARCHAR(255) NOT NULL,
    `show_host_publicly` TINYINT(1) NOT NULL DEFAULT 0,
    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `icmp_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `tcp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `tcp_port` SMALLINT UNSIGNED DEFAULT NULL,
    `loss_threshold_percent` FLOAT(5,2) DEFAULT NULL,
    `latency_threshold_ms` FLOAT(10,2) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `targets_slug_unique` (`slug`),
    KEY `targets_category_id_is_enabled_index` (`category_id`, `is_enabled`),
    KEY `targets_is_public_is_enabled_index` (`is_public`, `is_enabled`),
    CONSTRAINT `targets_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: target_states
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `target_states` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_id` BIGINT UNSIGNED NOT NULL,
    `overall_status` VARCHAR(20) NOT NULL DEFAULT 'unknown',
    `icmp_status` VARCHAR(20) NOT NULL DEFAULT 'unknown',
    `icmp_latency_ms` FLOAT(10,2) DEFAULT NULL,
    `icmp_loss_percent` FLOAT(5,2) DEFAULT NULL,
    `tcp_status` VARCHAR(20) NOT NULL DEFAULT 'unknown',
    `tcp_latency_ms` FLOAT(10,2) DEFAULT NULL,
    `tcp_loss_percent` FLOAT(5,2) DEFAULT NULL,
    `last_measured_at` TIMESTAMP NULL DEFAULT NULL,
    `last_status_change_at` TIMESTAMP NULL DEFAULT NULL,
    `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
    `consecutive_recoveries` INT UNSIGNED NOT NULL DEFAULT 0,
    `first_failure_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `target_states_target_id_unique` (`target_id`),
    CONSTRAINT `target_states_target_id_foreign` FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: monitor_settings
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `monitor_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(255) NOT NULL,
    `value` TEXT DEFAULT NULL,
    `type` VARCHAR(255) NOT NULL DEFAULT 'string',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `monitor_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default monitoring settings
INSERT INTO `monitor_settings` (`key`, `value`, `type`, `created_at`, `updated_at`) VALUES
('probe_interval', '60', 'int', NOW(), NOW()),
('icmp_samples', '10', 'int', NOW(), NOW()),
('tcp_samples', '10', 'int', NOW(), NOW()),
('icmp_timeout', '2000', 'int', NOW(), NOW()),
('tcp_timeout', '2000', 'int', NOW(), NOW()),
('raw_retention_days', '30', 'int', NOW(), NOW()),
('rollup5m_retention_days', '180', 'int', NOW(), NOW()),
('down_confirmation_cycles', '3', 'int', NOW(), NOW()),
('recovery_confirmation_cycles', '2', 'int', NOW(), NOW());

-- -------------------------------------------
-- Table: probe_cycles
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `probe_cycles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `started_at` TIMESTAMP NOT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `target_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `expected_probe_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `successful_probe_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_probe_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'running',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `probe_cycles_status_index` (`status`),
    KEY `probe_cycles_started_at_index` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: measurements
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `measurements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `probe_cycle_id` BIGINT UNSIGNED NOT NULL,
    `target_id` BIGINT UNSIGNED NOT NULL,
    `protocol` VARCHAR(10) NOT NULL,
    `measured_at` TIMESTAMP NOT NULL,
    `sent` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `received` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `loss_percent` FLOAT(5,2) NOT NULL DEFAULT 0,
    `min_ms` FLOAT(10,2) DEFAULT NULL,
    `max_ms` FLOAT(10,2) DEFAULT NULL,
    `avg_ms` FLOAT(10,2) DEFAULT NULL,
    `median_ms` FLOAT(10,2) DEFAULT NULL,
    `p10_ms` FLOAT(10,2) DEFAULT NULL,
    `p25_ms` FLOAT(10,2) DEFAULT NULL,
    `p75_ms` FLOAT(10,2) DEFAULT NULL,
    `p90_ms` FLOAT(10,2) DEFAULT NULL,
    `p95_ms` FLOAT(10,2) DEFAULT NULL,
    `stddev_ms` FLOAT(10,2) DEFAULT NULL,
    `samples` JSON DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'success',
    `error` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `measurements_target_protocol_cycle_unique` (`target_id`, `protocol`, `probe_cycle_id`),
    KEY `measurements_target_protocol_time_index` (`target_id`, `protocol`, `measured_at`),
    KEY `measurements_measured_at_index` (`measured_at`),
    CONSTRAINT `measurements_probe_cycle_id_foreign` FOREIGN KEY (`probe_cycle_id`) REFERENCES `probe_cycles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `measurements_target_id_foreign` FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: measurement_rollups
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `measurement_rollups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_id` BIGINT UNSIGNED NOT NULL,
    `protocol` VARCHAR(10) NOT NULL,
    `granularity` VARCHAR(5) NOT NULL,
    `period_start` TIMESTAMP NOT NULL,
    `sent` INT UNSIGNED NOT NULL DEFAULT 0,
    `received` INT UNSIGNED NOT NULL DEFAULT 0,
    `loss_percent` FLOAT(5,2) NOT NULL DEFAULT 0,
    `min_ms` FLOAT(10,2) DEFAULT NULL,
    `max_ms` FLOAT(10,2) DEFAULT NULL,
    `avg_ms` FLOAT(10,2) DEFAULT NULL,
    `median_ms` FLOAT(10,2) DEFAULT NULL,
    `p10_ms` FLOAT(10,2) DEFAULT NULL,
    `p25_ms` FLOAT(10,2) DEFAULT NULL,
    `p75_ms` FLOAT(10,2) DEFAULT NULL,
    `p90_ms` FLOAT(10,2) DEFAULT NULL,
    `p95_ms` FLOAT(10,2) DEFAULT NULL,
    `stddev_ms` FLOAT(10,2) DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `measurement_rollups_unique` (`target_id`, `protocol`, `granularity`, `period_start`),
    CONSTRAINT `measurement_rollups_target_id_foreign` FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: incidents
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `incidents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_id` BIGINT UNSIGNED NOT NULL,
    `type` VARCHAR(30) NOT NULL,
    `protocol` VARCHAR(10) DEFAULT NULL,
    `started_at` TIMESTAMP NOT NULL,
    `ended_at` TIMESTAMP NULL DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `initial_reason` TEXT DEFAULT NULL,
    `last_reason` TEXT DEFAULT NULL,
    `duration_seconds` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `incidents_target_status_started_index` (`target_id`, `status`, `started_at`),
    KEY `incidents_status_index` (`status`),
    CONSTRAINT `incidents_target_id_foreign` FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: audit_logs
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(255) NOT NULL,
    `auditable_type` VARCHAR(255) DEFAULT NULL,
    `auditable_id` BIGINT UNSIGNED DEFAULT NULL,
    `changes` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `audit_logs_auditable_type_id_index` (`auditable_type`, `auditable_id`),
    CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: migrations
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('0001_01_01_000000_create_users_table', 1),
('0001_01_01_000001_create_jobs_table', 1),
('2024_01_01_000001_create_categories_table', 1),
('2024_01_01_000002_create_targets_table', 1),
('2024_01_01_000003_create_target_states_table', 1),
('2024_01_01_000004_create_monitor_settings_table', 1),
('2024_01_01_000005_create_probe_cycles_table', 1),
('2024_01_01_000006_create_measurements_table', 1),
('2024_01_01_000007_create_measurement_rollups_table', 1),
('2024_01_01_000008_create_incidents_table', 1),
('2024_01_01_000009_create_audit_logs_table', 1),
('2024_01_01_000010_add_confirmation_counters_to_target_states', 1),
('2024_01_01_000011_add_per_target_thresholds_to_targets', 1);

-- -------------------------------------------
-- Sample categories and targets
-- -------------------------------------------
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `is_public`, `is_enabled`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, '上海', 'shanghai', 'Shanghai network targets', 1, 1, 10, NOW(), NOW()),
(2, '北京', 'beijing', 'Beijing network targets', 1, 1, 20, NOW(), NOW()),
(3, '广东', 'guangdong', 'Guangdong network targets', 1, 1, 30, NOW(), NOW());

INSERT INTO `targets` (`id`, `category_id`, `name`, `slug`, `host`, `show_host_publicly`, `is_public`, `is_enabled`, `icmp_enabled`, `tcp_enabled`, `tcp_port`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, '上海电信', 'shanghai-telecom', '124.74.52.254', 0, 1, 1, 1, 1, 65499, 10, NOW(), NOW()),
(2, 1, '上海联通', 'shanghai-unicom', '112.65.18.154', 0, 1, 1, 1, 1, 65499, 20, NOW(), NOW()),
(3, 1, '上海移动', 'shanghai-mobile', '117.131.0.1', 0, 1, 1, 1, 0, NULL, 30, NOW(), NOW()),
(4, 2, '北京电信', 'beijing-telecom', '223.72.1.1', 0, 1, 1, 1, 1, 443, 10, NOW(), NOW()),
(5, 2, '北京联通', 'beijing-unicom', '123.125.81.6', 0, 1, 1, 1, 1, 443, 20, NOW(), NOW()),
(6, 2, '北京移动', 'beijing-mobile', '221.130.33.1', 0, 1, 1, 1, 0, NULL, 30, NOW(), NOW()),
(7, 3, '广州电信', 'guangzhou-telecom', '14.215.116.1', 0, 1, 1, 1, 1, 80, 10, NOW(), NOW()),
(8, 3, '广州联通', 'guangzhou-unicom', '221.5.88.1', 0, 1, 1, 1, 1, 80, 20, NOW(), NOW()),
(9, 3, '广州移动', 'guangzhou-mobile', '211.136.192.1', 0, 1, 1, 1, 0, NULL, 30, NOW(), NOW());

SET FOREIGN_KEY_CHECKS = 1;
