-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 20, 2026 at 09:21 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `remote_bridge`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('network_access_code', '0ac2386d8137e8cb', '2026-08-20 07:19:51');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `remote_id` varchar(64) DEFAULT NULL,
  `session_id` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `remote_id` varchar(64) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `mac_fingerprint` char(64) DEFAULT NULL,
  `agent_token_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `is_online` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `devices`
--

INSERT INTO `devices` (`id`, `user_id`, `remote_id`, `device_name`, `mac_fingerprint`, `agent_token_hash`, `ip_address`, `last_seen_at`, `is_online`, `created_at`, `updated_at`) VALUES
(1, 1, '959717370', 'Win32', NULL, NULL, '::1', '2026-08-20 15:21:35', 1, '2026-08-20 06:45:51', '2026-08-20 07:21:35'),
(48, 2, '335763731', 'Win32', NULL, NULL, '192.168.0.84', NULL, 0, '2026-08-20 07:07:42', '2026-08-20 07:07:42');

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `version` varchar(100) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `version`, `applied_at`) VALUES
(1, '001_initial', '2026-08-20 06:40:42');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` char(64) NOT NULL,
  `initiator_remote_id` varchar(64) NOT NULL,
  `target_remote_id` varchar(64) NOT NULL,
  `status` enum('pending','connected','closed','expired') NOT NULL DEFAULT 'pending',
  `initiator_ip` varchar(45) DEFAULT NULL,
  `target_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `connected_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `signals`
--

CREATE TABLE `signals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` char(64) NOT NULL,
  `sender_remote_id` varchar(64) NOT NULL,
  `message_type` enum('offer','answer','candidate','hello','close') NOT NULL,
  `payload` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfer_history`
--

CREATE TABLE `transfer_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` char(64) DEFAULT NULL,
  `sender_remote_id` varchar(64) DEFAULT NULL,
  `receiver_remote_id` varchar(64) DEFAULT NULL,
  `file_name` varchar(512) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `transfer_type` enum('file','clipboard') NOT NULL DEFAULT 'file',
  `bytes_transferred` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `sha256` char(64) DEFAULT NULL,
  `status` enum('started','completed','failed','cancelled') NOT NULL DEFAULT 'started',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(80) NOT NULL,
  `display_name` varchar(160) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `display_name`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`, `last_login_at`) VALUES
(1, 'sadmin', 'Aron Niegos', '$2y$10$GfsCI3d4wh46JCZ7XfstEONlcWOQ.m1hoLh/rwbFHZtgfdjCmNmvq', 'admin', 1, '2026-08-20 06:44:06', '2026-08-20 07:15:09', '2026-08-20 15:15:09'),
(2, 'sadmin1', 'Harold Niegos', '$2y$10$vbf.WLGPQGvAslmUV.j.6.6P43rLKSD80HN6TniCiUwfzHaKO4SNa', 'user', 1, '2026-08-20 07:08:11', '2026-08-20 07:16:18', '2026-08-20 15:16:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_event` (`event_type`),
  ADD KEY `idx_audit_remote` (`remote_id`),
  ADD KEY `idx_audit_session` (`session_id`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_devices_remote_id` (`remote_id`),
  ADD KEY `idx_devices_last_seen` (`last_seen_at`),
  ADD KEY `idx_devices_user` (`user_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_migrations_version` (`version`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sessions_session_id` (`session_id`),
  ADD KEY `idx_sessions_target` (`target_remote_id`),
  ADD KEY `idx_sessions_status` (`status`),
  ADD KEY `idx_sessions_created` (`created_at`);

--
-- Indexes for table `signals`
--
ALTER TABLE `signals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_signals_session_id` (`session_id`),
  ADD KEY `idx_signals_created` (`created_at`);

--
-- Indexes for table `transfer_history`
--
ALTER TABLE `transfer_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transfer_session` (`session_id`),
  ADD KEY `idx_transfer_sender` (`sender_remote_id`),
  ADD KEY `idx_transfer_receiver` (`receiver_remote_id`),
  ADD KEY `idx_transfer_started` (`started_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `signals`
--
ALTER TABLE `signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfer_history`
--
ALTER TABLE `transfer_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `signals`
--
ALTER TABLE `signals`
  ADD CONSTRAINT `fk_signals_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
