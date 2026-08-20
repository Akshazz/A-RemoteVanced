-- RemoteBridge Native PHP / MySQL-MariaDB Database Schema
-- Compatible with MySQL 8+ and MariaDB 10.6+

CREATE DATABASE IF NOT EXISTS `remote_bridge`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `remote_bridge`;

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` VARCHAR(100) NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migrations_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `remote_id` VARCHAR(64) NOT NULL,
  `device_name` VARCHAR(255) NULL,
  `mac_fingerprint` CHAR(64) NULL,
  `agent_token_hash` CHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `last_seen_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_remote_id` (`remote_id`),
  KEY `idx_devices_last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` CHAR(64) NOT NULL,
  `initiator_remote_id` VARCHAR(64) NOT NULL,
  `target_remote_id` VARCHAR(64) NOT NULL,
  `status` ENUM('pending','connected','closed','expired') NOT NULL DEFAULT 'pending',
  `initiator_ip` VARCHAR(45) NULL,
  `target_ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `connected_at` DATETIME NULL,
  `closed_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_session_id` (`session_id`),
  KEY `idx_sessions_target` (`target_remote_id`),
  KEY `idx_sessions_status` (`status`),
  KEY `idx_sessions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `signals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` CHAR(64) NOT NULL,
  `sender_remote_id` VARCHAR(64) NOT NULL,
  `message_type` ENUM('offer','answer','candidate','hello','close') NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signals_session_id` (`session_id`),
  KEY `idx_signals_created` (`created_at`),
  CONSTRAINT `fk_signals_session`
    FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transfer_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` CHAR(64) NULL,
  `sender_remote_id` VARCHAR(64) NULL,
  `receiver_remote_id` VARCHAR(64) NULL,
  `file_name` VARCHAR(512) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NULL,
  `status` ENUM('started','completed','failed','cancelled') NOT NULL DEFAULT 'started',
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transfer_session` (`session_id`),
  KEY `idx_transfer_sender` (`sender_remote_id`),
  KEY `idx_transfer_receiver` (`receiver_remote_id`),
  KEY `idx_transfer_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(100) NOT NULL,
  `remote_id` VARCHAR(64) NULL,
  `session_id` CHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `details` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_event` (`event_type`),
  KEY `idx_audit_remote` (`remote_id`),
  KEY `idx_audit_session` (`session_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional baseline migration marker. Remove this INSERT if importing into
-- a database where the migration table is managed independently.
INSERT IGNORE INTO `migrations` (`version`) VALUES ('001_initial');
