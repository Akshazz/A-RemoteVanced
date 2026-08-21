<?php
declare(strict_types=1);
return [
'version' => '001_initial',
'up' => [
"CREATE TABLE IF NOT EXISTS devices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 remote_id VARCHAR(64) NOT NULL UNIQUE,
 device_name VARCHAR(255) NULL,
 mac_fingerprint CHAR(64) NULL,
 agent_token_hash CHAR(64) NULL,
 ip_address VARCHAR(45) NULL,
 last_seen_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_devices_last_seen(last_seen_at)
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_id CHAR(64) NOT NULL UNIQUE,
 initiator_remote_id VARCHAR(64) NOT NULL,
 target_remote_id VARCHAR(64) NOT NULL,
 status ENUM('pending','connected','closed','expired') NOT NULL DEFAULT 'pending',
 initiator_ip VARCHAR(45) NULL,
 target_ip VARCHAR(45) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 connected_at DATETIME NULL,
 closed_at DATETIME NULL,
 expires_at DATETIME NULL,
 INDEX idx_sessions_target(target_remote_id),
 INDEX idx_sessions_status(status)
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS signals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_id CHAR(64) NOT NULL,
 sender_remote_id VARCHAR(64) NOT NULL,
 message_type ENUM('offer','answer','candidate','hello','close') NOT NULL,
 payload LONGTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_signals_session(session_id),
 CONSTRAINT fk_signals_session FOREIGN KEY(session_id) REFERENCES sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS transfer_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_id CHAR(64) NULL,
 sender_remote_id VARCHAR(64) NULL,
 receiver_remote_id VARCHAR(64) NULL,
 file_name VARCHAR(512) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 sha256 CHAR(64) NULL,
 status ENUM('started','completed','failed','cancelled') NOT NULL DEFAULT 'started',
 started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 INDEX idx_transfer_session(session_id),
 INDEX idx_transfer_started(started_at)
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_type VARCHAR(100) NOT NULL,
 remote_id VARCHAR(64) NULL,
 session_id CHAR(64) NULL,
 ip_address VARCHAR(45) NULL,
 details JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_event(event_type),
 INDEX idx_audit_remote(remote_id),
 INDEX idx_audit_created(created_at)
) ENGINE=InnoDB"
]
];
