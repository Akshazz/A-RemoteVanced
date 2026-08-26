-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 26, 2026 at 06:22 PM
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
('login_attempts:b3512c54e5bb073b6643ed3de18252aeb301ff15435b67d249abb96ad67bddc6', '{\"attempts\":[1787477665,1787477666,1787477673,1787477674,1787477698,1787477699,1787477718,1787477719]}', '2026-08-23 09:35:19'),
('login_attempts:b588633f18148e387c847a75f208cae6b470a9999d5e6e0816e9c7ffb96a7403', '{\"attempts\":[1787475358,1787475396,1787475401,1787475435,1787475436,1787475445,1787475448]}', '2026-08-23 08:57:28');

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

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `event_type`, `remote_id`, `session_id`, `ip_address`, `details`, `created_at`) VALUES
(1, 'login_failed', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:55:58'),
(2, 'login_failed', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:56:36'),
(3, 'login_failed', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:56:41'),
(4, 'login_failed', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:57:15'),
(5, 'login_failed', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:57:16'),
(6, 'login_failed', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:57:25'),
(7, 'login_failed', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:57:28'),
(8, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:59:08'),
(9, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 08:59:10'),
(10, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 08:59:28'),
(11, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-23 08:59:30'),
(12, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 08:59:37'),
(13, 'logout', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:59:41'),
(14, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 08:59:44'),
(15, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 08:59:44'),
(16, 'login_success', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:01:16'),
(17, 'device_registered', '573603396', NULL, '192.168.189.119', '{\"user_id\":2}', '2026-08-23 09:01:17'),
(18, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:05:31'),
(19, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-23 09:05:41'),
(20, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:06:18'),
(21, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:06:44'),
(22, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-23 09:06:48'),
(23, 'device_registered', '573603396', NULL, '192.168.189.119', '{\"user_id\":2}', '2026-08-23 09:08:56'),
(24, 'device_registered', '573603396', NULL, '192.168.189.119', '{\"user_id\":2}', '2026-08-23 09:08:59'),
(25, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:11:49'),
(26, 'logout', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:11:49'),
(27, 'logout', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:11:54'),
(28, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:18:38'),
(29, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:18:40'),
(30, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:19:12'),
(31, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:19:15'),
(32, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:22:29'),
(33, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-23 09:22:41'),
(34, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-23 09:22:47'),
(35, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:23:33'),
(36, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:23:35'),
(37, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:23:37'),
(38, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:23:56'),
(39, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:23:58'),
(40, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:23:58'),
(41, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:23:58'),
(42, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"role\"]}', '2026-08-23 09:23:59'),
(43, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"role\"]}', '2026-08-23 09:24:01'),
(44, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:24:05'),
(45, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:24:09'),
(46, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:30:55'),
(47, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-23 09:31:04'),
(48, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:31:12'),
(49, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:33:40'),
(50, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:33:52'),
(51, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:33:57'),
(52, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:34:25'),
(53, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:34:26'),
(54, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:34:33'),
(55, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:34:34'),
(56, 'logout', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:34:41'),
(57, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:34:43'),
(58, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:34:44'),
(59, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:34:51'),
(60, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:34:54'),
(61, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:34:58'),
(62, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:34:59'),
(63, 'password_reset', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\"}', '2026-08-23 09:35:07'),
(64, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:35:18'),
(65, 'login_failed', NULL, NULL, '192.168.189.119', '{\"username\":\"sadmin1\"}', '2026-08-23 09:35:19'),
(66, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:40:26'),
(67, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:40:47'),
(68, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:40:49'),
(69, 'logout', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:40:53'),
(70, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:41:06'),
(71, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:41:06'),
(72, 'user_updated', NULL, NULL, '::1', '{\"by\":\"sadmin\",\"target\":\"sadmin1\",\"changes\":[\"id\",\"is_active\"]}', '2026-08-23 09:41:11'),
(73, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:41:55'),
(74, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:41:57'),
(75, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:45:32'),
(76, 'logout', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:45:48'),
(77, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-23 09:45:50'),
(78, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 09:45:51'),
(79, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-23 10:04:45'),
(80, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-24 12:58:04'),
(81, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-24 12:58:04'),
(82, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-24 12:58:39'),
(83, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-24 12:59:49'),
(84, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-24 13:00:54'),
(85, 'network_scan', NULL, NULL, '::1', '{\"admin\":\"sadmin\"}', '2026-08-24 13:01:34'),
(86, 'login_success', NULL, NULL, '::1', '{\"username\":\"sadmin\"}', '2026-08-26 15:55:54'),
(87, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-26 15:55:54'),
(88, 'security_scope_created', NULL, NULL, '::1', '{\"name\":\"Linux armv81\",\"target\":\"192.168.189.119\",\"authorized\":1}', '2026-08-26 15:56:30'),
(89, 'security_scan_completed', NULL, NULL, '::1', '{\"scan_id\":1,\"scope\":\"192.168.189.119\",\"summary\":\"Checked 17 TCP services on 192.168.189.119 (192.168.189.119); 0 port(s) accepted connections.\"}', '2026-08-26 15:56:49'),
(90, 'security_scan_completed', NULL, NULL, '::1', '{\"scan_id\":2,\"scope\":\"192.168.189.119\",\"summary\":\"Checked 17 TCP services on 192.168.189.119 (192.168.189.119); 0 port(s) accepted connections.\"}', '2026-08-26 15:57:17'),
(91, 'security_scan_completed', NULL, NULL, '::1', '{\"scan_id\":3,\"scope\":\"192.168.189.119\",\"summary\":\"Checked 17 TCP services on 192.168.189.119 (192.168.189.119); 0 port(s) accepted connections.\"}', '2026-08-26 15:58:28'),
(92, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-26 16:10:10'),
(93, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-26 16:10:17'),
(94, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-26 16:12:06'),
(95, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-26 16:12:13'),
(96, 'device_registered', '216843757', NULL, '::1', '{\"user_id\":1}', '2026-08-26 16:17:08');

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
(73, 1, '216843757', 'Win32', NULL, NULL, '::1', '2026-08-27 00:17:12', 1, '2026-08-23 08:59:10', '2026-08-26 16:17:12'),
(77, 2, '573603396', 'Linux armv81', NULL, NULL, '192.168.189.119', '2026-08-23 17:11:56', 1, '2026-08-23 09:01:17', '2026-08-23 09:11:56');

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `version` varchar(100) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_findings`
--

CREATE TABLE `security_findings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scan_id` bigint(20) UNSIGNED NOT NULL,
  `severity` enum('Critical','High','Medium','Low','Info') NOT NULL DEFAULT 'Info',
  `title` varchar(255) NOT NULL,
  `asset` varchar(255) NOT NULL,
  `evidence` text DEFAULT NULL,
  `remediation` text DEFAULT NULL,
  `status` enum('Open','Resolved','Accepted') NOT NULL DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_lab_runs`
--

CREATE TABLE `security_lab_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tool` varchar(40) NOT NULL,
  `profile` varchar(60) NOT NULL,
  `command_text` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `exit_code` int(11) DEFAULT NULL,
  `output` mediumtext DEFAULT NULL,
  `summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`summary_json`)),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_scans`
--

CREATE TABLE `security_scans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope_id` bigint(20) UNSIGNED NOT NULL,
  `scan_type` varchar(60) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `summary` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_scans`
--

INSERT INTO `security_scans` (`id`, `scope_id`, `scan_type`, `status`, `started_at`, `finished_at`, `summary`) VALUES
(1, 1, 'defensive-network-and-web', 'completed', '2026-08-26 15:56:35', '2026-08-26 23:56:49', 'Checked 17 TCP services on 192.168.189.119 (192.168.189.119); 0 port(s) accepted connections.'),
(2, 1, 'defensive-network-and-web', 'completed', '2026-08-26 15:57:03', '2026-08-26 23:57:17', 'Checked 17 TCP services on 192.168.189.119 (192.168.189.119); 0 port(s) accepted connections.'),
(3, 1, 'defensive-network-and-web', 'completed', '2026-08-26 15:58:14', '2026-08-26 23:58:28', 'Checked 17 TCP services on 192.168.189.119 (192.168.189.119); 0 port(s) accepted connections.');

-- --------------------------------------------------------

--
-- Table structure for table `security_scan_ports`
--

CREATE TABLE `security_scan_ports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scan_id` bigint(20) UNSIGNED NOT NULL,
  `port` int(10) UNSIGNED NOT NULL,
  `service` varchar(80) NOT NULL,
  `state` varchar(20) NOT NULL,
  `evidence` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_scopes`
--

CREATE TABLE `security_scopes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(160) NOT NULL,
  `target` varchar(255) NOT NULL,
  `target_type` enum('ip','hostname') NOT NULL DEFAULT 'ip',
  `authorized` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_scopes`
--

INSERT INTO `security_scopes` (`id`, `name`, `target`, `target_type`, `authorized`, `notes`, `created_by`, `created_at`) VALUES
(1, 'Linux armv81', '192.168.189.119', 'ip', 1, 'Device Droid', 1, '2026-08-26 15:56:30');

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

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `session_id`, `initiator_remote_id`, `target_remote_id`, `status`, `initiator_ip`, `target_ip`, `created_at`, `connected_at`, `closed_at`, `expires_at`) VALUES
(1, '25b27d010f9c2a48a37901c4f805842b82c4cf424113cf1464a936c5274020b8', '216843757', '573603396', 'pending', '::1', NULL, '2026-08-23 09:01:36', NULL, NULL, '2026-08-23 17:06:36'),
(2, '46f56802555b11f06bae08dbb65ba93de04c138a5737a50953353867701cfc42', '216843757', '573603396', 'closed', '::1', NULL, '2026-08-23 09:09:21', NULL, '2026-08-23 17:09:34', '2026-08-23 17:14:21'),
(3, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '573603396', '216843757', 'closed', '192.168.189.119', '::1', '2026-08-23 09:09:52', '2026-08-23 17:10:02', '2026-08-23 17:11:21', '2026-08-23 17:14:52');

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

--
-- Dumping data for table `signals`
--

INSERT INTO `signals` (`id`, `session_id`, `sender_remote_id`, `message_type`, `payload`, `created_at`) VALUES
(1, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '573603396', 'offer', 'v=0\r\no=- 151195200044471982 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0 1\r\na=extmap-allow-mixed\r\na=msid-semantic: WMS\r\nm=video 9 UDP/TLS/RTP/SAVPF 96 97 98 99 35 36 37 38 100 101 102 103 104 107 108 109 114 115 39 40 41 42 43 44 45 46 47 48 116 117 118 119 120 121 49 50 51 52 122 123 124 53\r\nc=IN IP4 0.0.0.0\r\na=rtcp:9 IN IP4 0.0.0.0\r\na=ice-ufrag:R55U\r\na=ice-pwd:sGDZ0Dj6LwIYBmJk2p3gs/cq\r\na=ice-options:trickle\r\na=fingerprint:sha-256 0E:79:C0:8D:E3:2F:BB:B6:80:3B:07:16:1E:E4:EB:07:F3:C2:C8:9C:87:0C:38:4C:E9:C8:98:77:42:9D:18:64\r\na=setup:actpass\r\na=mid:0\r\na=extmap:1 urn:ietf:params:rtp-hdrext:toffset\r\na=extmap:2 http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time\r\na=extmap:3 urn:3gpp:video-orientation\r\na=extmap:4 http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01\r\na=extmap:5 http://www.webrtc.org/experiments/rtp-hdrext/playout-delay\r\na=extmap:6 http://www.webrtc.org/experiments/rtp-hdrext/video-content-type\r\na=extmap:7 http://www.webrtc.org/experiments/rtp-hdrext/video-timing\r\na=extmap:8 http://www.webrtc.org/experiments/rtp-hdrext/color-space\r\na=extmap:9 urn:ietf:params:rtp-hdrext:sdes:mid\r\na=extmap:10 urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id\r\na=extmap:11 urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id\r\na=recvonly\r\na=rtcp-mux\r\na=rtcp-rsize\r\na=rtcp-xr:rcvr-rtt=all\r\na=rtcp-fb:96 rrtr\r\na=rtcp-fb:97 rrtr\r\na=rtcp-fb:98 rrtr\r\na=rtcp-fb:99 rrtr\r\na=rtcp-fb:35 rrtr\r\na=rtcp-fb:36 rrtr\r\na=rtcp-fb:37 rrtr\r\na=rtcp-fb:38 rrtr\r\na=rtcp-fb:100 rrtr\r\na=rtcp-fb:101 rrtr\r\na=rtcp-fb:102 rrtr\r\na=rtcp-fb:103 rrtr\r\na=rtcp-fb:104 rrtr\r\na=rtcp-fb:107 rrtr\r\na=rtcp-fb:108 rrtr\r\na=rtcp-fb:109 rrtr\r\na=rtcp-fb:114 rrtr\r\na=rtcp-fb:115 rrtr\r\na=rtcp-fb:39 rrtr\r\na=rtcp-fb:40 rrtr\r\na=rtcp-fb:41 rrtr\r\na=rtcp-fb:42 rrtr\r\na=rtcp-fb:43 rrtr\r\na=rtcp-fb:44 rrtr\r\na=rtcp-fb:45 rrtr\r\na=rtcp-fb:46 rrtr\r\na=rtcp-fb:47 rrtr\r\na=rtcp-fb:48 rrtr\r\na=rtcp-fb:116 rrtr\r\na=rtcp-fb:117 rrtr\r\na=rtcp-fb:118 rrtr\r\na=rtcp-fb:119 rrtr\r\na=rtcp-fb:120 rrtr\r\na=rtcp-fb:121 rrtr\r\na=rtcp-fb:49 rrtr\r\na=rtcp-fb:50 rrtr\r\na=rtcp-fb:51 rrtr\r\na=rtcp-fb:52 rrtr\r\na=rtcp-fb:122 rrtr\r\na=rtcp-fb:123 rrtr\r\na=rtcp-fb:124 rrtr\r\na=rtcp-fb:53 rrtr\r\na=rtpmap:96 VP8/90000\r\na=rtcp-fb:96 goog-remb\r\na=rtcp-fb:96 transport-cc\r\na=rtcp-fb:96 ccm fir\r\na=rtcp-fb:96 nack\r\na=rtcp-fb:96 nack pli\r\na=rtpmap:97 rtx/90000\r\na=fmtp:97 apt=96\r\na=rtpmap:98 VP9/90000\r\na=rtcp-fb:98 goog-remb\r\na=rtcp-fb:98 transport-cc\r\na=rtcp-fb:98 ccm fir\r\na=rtcp-fb:98 nack\r\na=rtcp-fb:98 nack pli\r\na=fmtp:98 profile-id=0\r\na=rtpmap:99 rtx/90000\r\na=fmtp:99 apt=98\r\na=rtpmap:35 VP9/90000\r\na=rtcp-fb:35 goog-remb\r\na=rtcp-fb:35 transport-cc\r\na=rtcp-fb:35 ccm fir\r\na=rtcp-fb:35 nack\r\na=rtcp-fb:35 nack pli\r\na=fmtp:35 profile-id=1\r\na=rtpmap:36 rtx/90000\r\na=fmtp:36 apt=35\r\na=rtpmap:37 VP9/90000\r\na=rtcp-fb:37 goog-remb\r\na=rtcp-fb:37 transport-cc\r\na=rtcp-fb:37 ccm fir\r\na=rtcp-fb:37 nack\r\na=rtcp-fb:37 nack pli\r\na=fmtp:37 profile-id=3\r\na=rtpmap:38 rtx/90000\r\na=fmtp:38 apt=37\r\na=rtpmap:100 H264/90000\r\na=rtcp-fb:100 goog-remb\r\na=rtcp-fb:100 transport-cc\r\na=rtcp-fb:100 ccm fir\r\na=rtcp-fb:100 nack\r\na=rtcp-fb:100 nack pli\r\na=fmtp:100 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42001f\r\na=rtpmap:101 rtx/90000\r\na=fmtp:101 apt=100\r\na=rtpmap:102 H264/90000\r\na=rtcp-fb:102 goog-remb\r\na=rtcp-fb:102 transport-cc\r\na=rtcp-fb:102 ccm fir\r\na=rtcp-fb:102 nack\r\na=rtcp-fb:102 nack pli\r\na=fmtp:102 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42001f\r\na=rtpmap:103 rtx/90000\r\na=fmtp:103 apt=102\r\na=rtpmap:104 H264/90000\r\na=rtcp-fb:104 goog-remb\r\na=rtcp-fb:104 transport-cc\r\na=rtcp-fb:104 ccm fir\r\na=rtcp-fb:104 nack\r\na=rtcp-fb:104 nack pli\r\na=fmtp:104 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f\r\na=rtpmap:107 rtx/90000\r\na=fmtp:107 apt=104\r\na=rtpmap:108 H264/90000\r\na=rtcp-fb:108 goog-remb\r\na=rtcp-fb:108 transport-cc\r\na=rtcp-fb:108 ccm fir\r\na=rtcp-fb:108 nack\r\na=rtcp-fb:108 nack pli\r\na=fmtp:108 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42e01f\r\na=rtpmap:109 rtx/90000\r\na=fmtp:109 apt=108\r\na=rtpmap:114 H264/90000\r\na=rtcp-fb:114 goog-remb\r\na=rtcp-fb:114 transport-cc\r\na=rtcp-fb:114 ccm fir\r\na=rtcp-fb:114 nack\r\na=rtcp-fb:114 nack pli\r\na=fmtp:114 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=4d001f\r\na=rtpmap:115 rtx/90000\r\na=fmtp:115 apt=114\r\na=rtpmap:39 H264/90000\r\na=rtcp-fb:39 goog-remb\r\na=rtcp-fb:39 transport-cc\r\na=rtcp-fb:39 ccm fir\r\na=rtcp-fb:39 nack\r\na=rtcp-fb:39 nack pli\r\na=fmtp:39 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=4d001f\r\na=rtpmap:40 rtx/90000\r\na=fmtp:40 apt=39\r\na=rtpmap:41 H264/90000\r\na=rtcp-fb:41 goog-remb\r\na=rtcp-fb:41 transport-cc\r\na=rtcp-fb:41 ccm fir\r\na=rtcp-fb:41 nack\r\na=rtcp-fb:41 nack pli\r\na=fmtp:41 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=f4001f\r\na=rtpmap:42 rtx/90000\r\na=fmtp:42 apt=41\r\na=rtpmap:43 H264/90000\r\na=rtcp-fb:43 goog-remb\r\na=rtcp-fb:43 transport-cc\r\na=rtcp-fb:43 ccm fir\r\na=rtcp-fb:43 nack\r\na=rtcp-fb:43 nack pli\r\na=fmtp:43 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=f4001f\r\na=rtpmap:44 rtx/90000\r\na=fmtp:44 apt=43\r\na=rtpmap:45 AV1/90000\r\na=rtcp-fb:45 goog-remb\r\na=rtcp-fb:45 transport-cc\r\na=rtcp-fb:45 ccm fir\r\na=rtcp-fb:45 nack\r\na=rtcp-fb:45 nack pli\r\na=fmtp:45 level-idx=5;profile=0;tier=0\r\na=rtpmap:46 rtx/90000\r\na=fmtp:46 apt=45\r\na=rtpmap:47 AV1/90000\r\na=rtcp-fb:47 goog-remb\r\na=rtcp-fb:47 transport-cc\r\na=rtcp-fb:47 ccm fir\r\na=rtcp-fb:47 nack\r\na=rtcp-fb:47 nack pli\r\na=fmtp:47 level-idx=5;profile=1;tier=0\r\na=rtpmap:48 rtx/90000\r\na=fmtp:48 apt=47\r\na=rtpmap:116 VP9/90000\r\na=rtcp-fb:116 goog-remb\r\na=rtcp-fb:116 transport-cc\r\na=rtcp-fb:116 ccm fir\r\na=rtcp-fb:116 nack\r\na=rtcp-fb:116 nack pli\r\na=fmtp:116 profile-id=2\r\na=rtpmap:117 rtx/90000\r\na=fmtp:117 apt=116\r\na=rtpmap:118 H264/90000\r\na=rtcp-fb:118 goog-remb\r\na=rtcp-fb:118 transport-cc\r\na=rtcp-fb:118 ccm fir\r\na=rtcp-fb:118 nack\r\na=rtcp-fb:118 nack pli\r\na=fmtp:118 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=64001f\r\na=rtpmap:119 rtx/90000\r\na=fmtp:119 apt=118\r\na=rtpmap:120 H264/90000\r\na=rtcp-fb:120 goog-remb\r\na=rtcp-fb:120 transport-cc\r\na=rtcp-fb:120 ccm fir\r\na=rtcp-fb:120 nack\r\na=rtcp-fb:120 nack pli\r\na=fmtp:120 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=64001f\r\na=rtpmap:121 rtx/90000\r\na=fmtp:121 apt=120\r\na=rtpmap:49 H265/90000\r\na=rtcp-fb:49 goog-remb\r\na=rtcp-fb:49 transport-cc\r\na=rtcp-fb:49 ccm fir\r\na=rtcp-fb:49 nack\r\na=rtcp-fb:49 nack pli\r\na=fmtp:49 level-id=93;profile-id=1;tier-flag=0;tx-mode=SRST\r\na=rtpmap:50 rtx/90000\r\na=fmtp:50 apt=49\r\na=rtpmap:51 H265/90000\r\na=rtcp-fb:51 goog-remb\r\na=rtcp-fb:51 transport-cc\r\na=rtcp-fb:51 ccm fir\r\na=rtcp-fb:51 nack\r\na=rtcp-fb:51 nack pli\r\na=fmtp:51 level-id=150;profile-id=2;tier-flag=0;tx-mode=SRST\r\na=rtpmap:52 rtx/90000\r\na=fmtp:52 apt=51\r\na=rtpmap:122 red/90000\r\na=rtpmap:123 rtx/90000\r\na=fmtp:123 apt=122\r\na=rtpmap:124 ulpfec/90000\r\na=rtpmap:53 flexfec-03/90000\r\na=rtcp-fb:53 goog-remb\r\na=rtcp-fb:53 transport-cc\r\na=fmtp:53 repair-window=10000000\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\nc=IN IP4 0.0.0.0\r\na=ice-ufrag:R55U\r\na=ice-pwd:sGDZ0Dj6LwIYBmJk2p3gs/cq\r\na=ice-options:trickle\r\na=fingerprint:sha-256 0E:79:C0:8D:E3:2F:BB:B6:80:3B:07:16:1E:E4:EB:07:F3:C2:C8:9C:87:0C:38:4C:E9:C8:98:77:42:9D:18:64\r\na=setup:actpass\r\na=mid:1\r\na=sctp-port:5000\r\na=max-message-size:262144\r\n', '2026-08-23 09:10:04'),
(2, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '573603396', 'candidate', '{\"candidate\":\"candidate:2363750795 1 udp 2113937151 714a9100-0189-4ca4-a643-716ca768dd7a.local 53096 typ host generation 0 ufrag R55U network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"R55U\"}', '2026-08-23 09:10:04'),
(3, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '573603396', 'candidate', '{\"candidate\":\"candidate:2363750795 1 udp 2113937151 714a9100-0189-4ca4-a643-716ca768dd7a.local 54978 typ host generation 0 ufrag R55U network-cost 999\",\"sdpMid\":\"1\",\"sdpMLineIndex\":1,\"usernameFragment\":\"R55U\"}', '2026-08-23 09:10:04'),
(4, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '573603396', 'candidate', '{\"candidate\":\"candidate:266229334 1 udp 1677729535 157.20.143.181 53096 typ srflx raddr 0.0.0.0 rport 0 generation 0 ufrag R55U network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"R55U\"}', '2026-08-23 09:10:04'),
(5, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '573603396', 'candidate', '{\"candidate\":\"candidate:266229334 1 udp 1677729535 157.20.143.181 54978 typ srflx raddr 0.0.0.0 rport 0 generation 0 ufrag R55U network-cost 999\",\"sdpMid\":\"1\",\"sdpMLineIndex\":1,\"usernameFragment\":\"R55U\"}', '2026-08-23 09:10:04'),
(6, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '216843757', 'answer', 'v=0\r\no=- 3512643674809808821 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0 1\r\na=extmap-allow-mixed\r\na=msid-semantic: WMS 57e37f06-a4ab-4c6f-8f76-baee326f6532\r\nm=video 9 UDP/TLS/RTP/SAVPF 96 97 98 99 100 101 102 103 104 107 108 109 114 115 39 40 45 46 116 117 118 119 49 50 122 123 124\r\nc=IN IP4 0.0.0.0\r\na=rtcp:9 IN IP4 0.0.0.0\r\na=ice-ufrag:K1Dt\r\na=ice-pwd:LpCPQgjV+UuN+5Xp24opsPIM\r\na=ice-options:trickle\r\na=fingerprint:sha-256 4B:D6:40:A8:D4:1F:D8:35:84:42:42:35:FA:65:19:C2:3E:71:A6:28:CD:29:9A:87:D2:FF:5F:A8:F9:5D:5F:47\r\na=setup:active\r\na=mid:0\r\na=extmap:1 urn:ietf:params:rtp-hdrext:toffset\r\na=extmap:2 http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time\r\na=extmap:3 urn:3gpp:video-orientation\r\na=extmap:4 http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01\r\na=extmap:5 http://www.webrtc.org/experiments/rtp-hdrext/playout-delay\r\na=extmap:6 http://www.webrtc.org/experiments/rtp-hdrext/video-content-type\r\na=extmap:7 http://www.webrtc.org/experiments/rtp-hdrext/video-timing\r\na=extmap:8 http://www.webrtc.org/experiments/rtp-hdrext/color-space\r\na=extmap:9 urn:ietf:params:rtp-hdrext:sdes:mid\r\na=extmap:10 urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id\r\na=extmap:11 urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id\r\na=sendonly\r\na=msid:57e37f06-a4ab-4c6f-8f76-baee326f6532 d67e78e7-0f1a-4226-bbf9-af6a2dca14ec\r\na=rtcp-mux\r\na=rtcp-rsize\r\na=rtcp-xr:rcvr-rtt=all\r\na=rtcp-fb:96 rrtr\r\na=rtcp-fb:97 rrtr\r\na=rtcp-fb:98 rrtr\r\na=rtcp-fb:99 rrtr\r\na=rtcp-fb:100 rrtr\r\na=rtcp-fb:101 rrtr\r\na=rtcp-fb:102 rrtr\r\na=rtcp-fb:103 rrtr\r\na=rtcp-fb:104 rrtr\r\na=rtcp-fb:107 rrtr\r\na=rtcp-fb:108 rrtr\r\na=rtcp-fb:109 rrtr\r\na=rtcp-fb:114 rrtr\r\na=rtcp-fb:115 rrtr\r\na=rtcp-fb:39 rrtr\r\na=rtcp-fb:40 rrtr\r\na=rtcp-fb:45 rrtr\r\na=rtcp-fb:46 rrtr\r\na=rtcp-fb:116 rrtr\r\na=rtcp-fb:117 rrtr\r\na=rtcp-fb:118 rrtr\r\na=rtcp-fb:119 rrtr\r\na=rtcp-fb:49 rrtr\r\na=rtcp-fb:50 rrtr\r\na=rtcp-fb:122 rrtr\r\na=rtcp-fb:123 rrtr\r\na=rtcp-fb:124 rrtr\r\na=rtpmap:96 VP8/90000\r\na=rtcp-fb:96 goog-remb\r\na=rtcp-fb:96 transport-cc\r\na=rtcp-fb:96 ccm fir\r\na=rtcp-fb:96 nack\r\na=rtcp-fb:96 nack pli\r\na=rtpmap:97 rtx/90000\r\na=fmtp:97 apt=96\r\na=rtpmap:98 VP9/90000\r\na=rtcp-fb:98 goog-remb\r\na=rtcp-fb:98 transport-cc\r\na=rtcp-fb:98 ccm fir\r\na=rtcp-fb:98 nack\r\na=rtcp-fb:98 nack pli\r\na=fmtp:98 profile-id=0\r\na=rtpmap:99 rtx/90000\r\na=fmtp:99 apt=98\r\na=rtpmap:100 H264/90000\r\na=rtcp-fb:100 goog-remb\r\na=rtcp-fb:100 transport-cc\r\na=rtcp-fb:100 ccm fir\r\na=rtcp-fb:100 nack\r\na=rtcp-fb:100 nack pli\r\na=fmtp:100 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42001f\r\na=rtpmap:101 rtx/90000\r\na=fmtp:101 apt=100\r\na=rtpmap:102 H264/90000\r\na=rtcp-fb:102 goog-remb\r\na=rtcp-fb:102 transport-cc\r\na=rtcp-fb:102 ccm fir\r\na=rtcp-fb:102 nack\r\na=rtcp-fb:102 nack pli\r\na=fmtp:102 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42001f\r\na=rtpmap:103 rtx/90000\r\na=fmtp:103 apt=102\r\na=rtpmap:104 H264/90000\r\na=rtcp-fb:104 goog-remb\r\na=rtcp-fb:104 transport-cc\r\na=rtcp-fb:104 ccm fir\r\na=rtcp-fb:104 nack\r\na=rtcp-fb:104 nack pli\r\na=fmtp:104 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f\r\na=rtpmap:107 rtx/90000\r\na=fmtp:107 apt=104\r\na=rtpmap:108 H264/90000\r\na=rtcp-fb:108 goog-remb\r\na=rtcp-fb:108 transport-cc\r\na=rtcp-fb:108 ccm fir\r\na=rtcp-fb:108 nack\r\na=rtcp-fb:108 nack pli\r\na=fmtp:108 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42e01f\r\na=rtpmap:109 rtx/90000\r\na=fmtp:109 apt=108\r\na=rtpmap:114 H264/90000\r\na=rtcp-fb:114 goog-remb\r\na=rtcp-fb:114 transport-cc\r\na=rtcp-fb:114 ccm fir\r\na=rtcp-fb:114 nack\r\na=rtcp-fb:114 nack pli\r\na=fmtp:114 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=4d001f\r\na=rtpmap:115 rtx/90000\r\na=fmtp:115 apt=114\r\na=rtpmap:39 H264/90000\r\na=rtcp-fb:39 goog-remb\r\na=rtcp-fb:39 transport-cc\r\na=rtcp-fb:39 ccm fir\r\na=rtcp-fb:39 nack\r\na=rtcp-fb:39 nack pli\r\na=fmtp:39 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=4d001f\r\na=rtpmap:40 rtx/90000\r\na=fmtp:40 apt=39\r\na=rtpmap:45 AV1/90000\r\na=rtcp-fb:45 goog-remb\r\na=rtcp-fb:45 transport-cc\r\na=rtcp-fb:45 ccm fir\r\na=rtcp-fb:45 nack\r\na=rtcp-fb:45 nack pli\r\na=fmtp:45 level-idx=5;profile=0;tier=0\r\na=rtpmap:46 rtx/90000\r\na=fmtp:46 apt=45\r\na=rtpmap:116 VP9/90000\r\na=rtcp-fb:116 goog-remb\r\na=rtcp-fb:116 transport-cc\r\na=rtcp-fb:116 ccm fir\r\na=rtcp-fb:116 nack\r\na=rtcp-fb:116 nack pli\r\na=fmtp:116 profile-id=2\r\na=rtpmap:117 rtx/90000\r\na=fmtp:117 apt=116\r\na=rtpmap:118 H264/90000\r\na=rtcp-fb:118 goog-remb\r\na=rtcp-fb:118 transport-cc\r\na=rtcp-fb:118 ccm fir\r\na=rtcp-fb:118 nack\r\na=rtcp-fb:118 nack pli\r\na=fmtp:118 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=640020\r\na=rtpmap:119 rtx/90000\r\na=fmtp:119 apt=118\r\na=rtpmap:49 H265/90000\r\na=rtcp-fb:49 goog-remb\r\na=rtcp-fb:49 transport-cc\r\na=rtcp-fb:49 ccm fir\r\na=rtcp-fb:49 nack\r\na=rtcp-fb:49 nack pli\r\na=fmtp:49 level-id=93;profile-id=1;tier-flag=0;tx-mode=SRST\r\na=rtpmap:50 rtx/90000\r\na=fmtp:50 apt=49\r\na=rtpmap:122 red/90000\r\na=rtpmap:123 rtx/90000\r\na=fmtp:123 apt=122\r\na=rtpmap:124 ulpfec/90000\r\na=ssrc-group:FID 2955568175 52500598\r\na=ssrc:2955568175 cname:EN6diYCog0q9HPHI\r\na=ssrc:52500598 cname:EN6diYCog0q9HPHI\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\nc=IN IP4 0.0.0.0\r\na=ice-ufrag:K1Dt\r\na=ice-pwd:LpCPQgjV+UuN+5Xp24opsPIM\r\na=ice-options:trickle\r\na=fingerprint:sha-256 4B:D6:40:A8:D4:1F:D8:35:84:42:42:35:FA:65:19:C2:3E:71:A6:28:CD:29:9A:87:D2:FF:5F:A8:F9:5D:5F:47\r\na=setup:active\r\na=mid:1\r\na=sctp-port:5000\r\na=max-message-size:262144\r\n', '2026-08-23 09:10:07'),
(7, '1fb8f9f0439cd588fcbeb23f2756150314568f41bcef2192bd2a4d145915cb05', '216843757', 'candidate', '{\"candidate\":\"candidate:3691610039 1 udp 2113937151 4fc3d3b5-92e3-40be-aa9b-c2b3fe3ce9a5.local 61272 typ host generation 0 ufrag K1Dt network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"K1Dt\"}', '2026-08-23 09:10:07');

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
(1, 'sadmin', 'Aron Niegos', '$2y$10$GfsCI3d4wh46JCZ7XfstEONlcWOQ.m1hoLh/rwbFHZtgfdjCmNmvq', 'admin', 1, '2026-08-20 06:44:06', '2026-08-26 15:55:54', '2026-08-26 23:55:54');

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
-- Indexes for table `security_findings`
--
ALTER TABLE `security_findings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_finding_scan` (`scan_id`),
  ADD KEY `idx_security_finding_status` (`status`),
  ADD KEY `idx_security_finding_severity` (`severity`);

--
-- Indexes for table `security_lab_runs`
--
ALTER TABLE `security_lab_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `scope_id` (`scope_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `security_scans`
--
ALTER TABLE `security_scans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_scan_scope` (`scope_id`);

--
-- Indexes for table `security_scan_ports`
--
ALTER TABLE `security_scan_ports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_port_scan` (`scan_id`);

--
-- Indexes for table `security_scopes`
--
ALTER TABLE `security_scopes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_security_scope_target` (`target`);

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `security_findings`
--
ALTER TABLE `security_findings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_lab_runs`
--
ALTER TABLE `security_lab_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_scans`
--
ALTER TABLE `security_scans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `security_scan_ports`
--
ALTER TABLE `security_scan_ports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_scopes`
--
ALTER TABLE `security_scopes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `signals`
--
ALTER TABLE `signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
