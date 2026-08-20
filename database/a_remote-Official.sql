-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 20, 2026 at 04:55 AM
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
-- Database: `a_remote`
--

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
  `remote_id` varchar(64) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `mac_fingerprint` char(64) DEFAULT NULL,
  `agent_token_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `devices`
--

INSERT INTO `devices` (`id`, `remote_id`, `device_name`, `mac_fingerprint`, `agent_token_hash`, `ip_address`, `last_seen_at`, `created_at`, `updated_at`) VALUES
(1, '626624796', 'Win32', NULL, NULL, '::1', '2026-08-20 10:51:14', '2026-08-20 01:57:22', '2026-08-20 02:51:14'),
(2, '335763731', 'Win32', NULL, NULL, '192.168.0.84', '2026-08-20 10:40:52', '2026-08-20 01:58:27', '2026-08-20 02:40:52');

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
(1, '001_initial', '2026-08-20 01:42:44');

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
(1, 'd537080bed7327bc483db8bc554771b83b7b24ce800d570347a644acedb91531', '335763731', '626624796', 'closed', '192.168.0.84', '::1', '2026-08-20 01:58:44', '2026-08-20 09:58:55', '2026-08-20 10:00:16', '2026-08-20 10:03:44'),
(2, 'b7cb31c7001bb3749f6cbd2f33dcdb2f3b8cb148749aebdf10b2a71fe9cbd81e', '626624796', '335763731', 'closed', '::1', NULL, '2026-08-20 02:01:05', NULL, '2026-08-20 10:01:33', '2026-08-20 10:06:05'),
(3, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '335763731', '626624796', 'closed', '192.168.0.84', '::1', '2026-08-20 02:41:13', '2026-08-20 10:41:22', '2026-08-20 10:49:21', '2026-08-20 10:46:13');

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
(1, 'd537080bed7327bc483db8bc554771b83b7b24ce800d570347a644acedb91531', '335763731', 'offer', 'v=0\r\no=- 1805412261367614346 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0\r\na=extmap-allow-mixed\r\na=msid-semantic: WMS\r\nm=video 9 UDP/TLS/RTP/SAVPF 96 97 98 99 100 101 35 36 37 38 102 103 104 107 108 109 114 115 116 117 39 40 41 42 43 44 45 46 47 48 118 119 120 121 122 123 124 49\r\nc=IN IP4 0.0.0.0\r\na=rtcp:9 IN IP4 0.0.0.0\r\na=ice-ufrag:5Ln2\r\na=ice-pwd:Et+9Txw5OBwUVbsyTUwRG95T\r\na=ice-options:trickle\r\na=fingerprint:sha-256 8B:F2:B6:A0:BB:6F:AD:1F:ED:95:CF:5D:3B:B9:7B:28:AC:36:A0:61:74:74:F6:C4:3E:05:81:10:6F:38:04:83\r\na=setup:actpass\r\na=mid:0\r\na=extmap:1 urn:ietf:params:rtp-hdrext:toffset\r\na=extmap:2 http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time\r\na=extmap:3 urn:3gpp:video-orientation\r\na=extmap:4 http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01\r\na=extmap:5 http://www.webrtc.org/experiments/rtp-hdrext/playout-delay\r\na=extmap:6 http://www.webrtc.org/experiments/rtp-hdrext/video-content-type\r\na=extmap:7 http://www.webrtc.org/experiments/rtp-hdrext/video-timing\r\na=extmap:8 http://www.webrtc.org/experiments/rtp-hdrext/color-space\r\na=extmap:9 urn:ietf:params:rtp-hdrext:sdes:mid\r\na=extmap:10 urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id\r\na=extmap:11 urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id\r\na=recvonly\r\na=rtcp-mux\r\na=rtcp-rsize\r\na=rtcp-xr:rcvr-rtt=all\r\na=rtcp-fb:96 rrtr\r\na=rtcp-fb:97 rrtr\r\na=rtcp-fb:98 rrtr\r\na=rtcp-fb:99 rrtr\r\na=rtcp-fb:100 rrtr\r\na=rtcp-fb:101 rrtr\r\na=rtcp-fb:35 rrtr\r\na=rtcp-fb:36 rrtr\r\na=rtcp-fb:37 rrtr\r\na=rtcp-fb:38 rrtr\r\na=rtcp-fb:102 rrtr\r\na=rtcp-fb:103 rrtr\r\na=rtcp-fb:104 rrtr\r\na=rtcp-fb:107 rrtr\r\na=rtcp-fb:108 rrtr\r\na=rtcp-fb:109 rrtr\r\na=rtcp-fb:114 rrtr\r\na=rtcp-fb:115 rrtr\r\na=rtcp-fb:116 rrtr\r\na=rtcp-fb:117 rrtr\r\na=rtcp-fb:39 rrtr\r\na=rtcp-fb:40 rrtr\r\na=rtcp-fb:41 rrtr\r\na=rtcp-fb:42 rrtr\r\na=rtcp-fb:43 rrtr\r\na=rtcp-fb:44 rrtr\r\na=rtcp-fb:45 rrtr\r\na=rtcp-fb:46 rrtr\r\na=rtcp-fb:47 rrtr\r\na=rtcp-fb:48 rrtr\r\na=rtcp-fb:118 rrtr\r\na=rtcp-fb:119 rrtr\r\na=rtcp-fb:120 rrtr\r\na=rtcp-fb:121 rrtr\r\na=rtcp-fb:122 rrtr\r\na=rtcp-fb:123 rrtr\r\na=rtcp-fb:124 rrtr\r\na=rtcp-fb:49 rrtr\r\na=rtpmap:96 VP8/90000\r\na=rtcp-fb:96 goog-remb\r\na=rtcp-fb:96 transport-cc\r\na=rtcp-fb:96 ccm fir\r\na=rtcp-fb:96 nack\r\na=rtcp-fb:96 nack pli\r\na=rtpmap:97 rtx/90000\r\na=fmtp:97 apt=96\r\na=rtpmap:98 VP9/90000\r\na=rtcp-fb:98 goog-remb\r\na=rtcp-fb:98 transport-cc\r\na=rtcp-fb:98 ccm fir\r\na=rtcp-fb:98 nack\r\na=rtcp-fb:98 nack pli\r\na=fmtp:98 profile-id=0\r\na=rtpmap:99 rtx/90000\r\na=fmtp:99 apt=98\r\na=rtpmap:100 VP9/90000\r\na=rtcp-fb:100 goog-remb\r\na=rtcp-fb:100 transport-cc\r\na=rtcp-fb:100 ccm fir\r\na=rtcp-fb:100 nack\r\na=rtcp-fb:100 nack pli\r\na=fmtp:100 profile-id=2\r\na=rtpmap:101 rtx/90000\r\na=fmtp:101 apt=100\r\na=rtpmap:35 VP9/90000\r\na=rtcp-fb:35 goog-remb\r\na=rtcp-fb:35 transport-cc\r\na=rtcp-fb:35 ccm fir\r\na=rtcp-fb:35 nack\r\na=rtcp-fb:35 nack pli\r\na=fmtp:35 profile-id=1\r\na=rtpmap:36 rtx/90000\r\na=fmtp:36 apt=35\r\na=rtpmap:37 VP9/90000\r\na=rtcp-fb:37 goog-remb\r\na=rtcp-fb:37 transport-cc\r\na=rtcp-fb:37 ccm fir\r\na=rtcp-fb:37 nack\r\na=rtcp-fb:37 nack pli\r\na=fmtp:37 profile-id=3\r\na=rtpmap:38 rtx/90000\r\na=fmtp:38 apt=37\r\na=rtpmap:102 H264/90000\r\na=rtcp-fb:102 goog-remb\r\na=rtcp-fb:102 transport-cc\r\na=rtcp-fb:102 ccm fir\r\na=rtcp-fb:102 nack\r\na=rtcp-fb:102 nack pli\r\na=fmtp:102 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42001f\r\na=rtpmap:103 rtx/90000\r\na=fmtp:103 apt=102\r\na=rtpmap:104 H264/90000\r\na=rtcp-fb:104 goog-remb\r\na=rtcp-fb:104 transport-cc\r\na=rtcp-fb:104 ccm fir\r\na=rtcp-fb:104 nack\r\na=rtcp-fb:104 nack pli\r\na=fmtp:104 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42001f\r\na=rtpmap:107 rtx/90000\r\na=fmtp:107 apt=104\r\na=rtpmap:108 H264/90000\r\na=rtcp-fb:108 goog-remb\r\na=rtcp-fb:108 transport-cc\r\na=rtcp-fb:108 ccm fir\r\na=rtcp-fb:108 nack\r\na=rtcp-fb:108 nack pli\r\na=fmtp:108 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f\r\na=rtpmap:109 rtx/90000\r\na=fmtp:109 apt=108\r\na=rtpmap:114 H264/90000\r\na=rtcp-fb:114 goog-remb\r\na=rtcp-fb:114 transport-cc\r\na=rtcp-fb:114 ccm fir\r\na=rtcp-fb:114 nack\r\na=rtcp-fb:114 nack pli\r\na=fmtp:114 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42e01f\r\na=rtpmap:115 rtx/90000\r\na=fmtp:115 apt=114\r\na=rtpmap:116 H264/90000\r\na=rtcp-fb:116 goog-remb\r\na=rtcp-fb:116 transport-cc\r\na=rtcp-fb:116 ccm fir\r\na=rtcp-fb:116 nack\r\na=rtcp-fb:116 nack pli\r\na=fmtp:116 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=4d001f\r\na=rtpmap:117 rtx/90000\r\na=fmtp:117 apt=116\r\na=rtpmap:39 H264/90000\r\na=rtcp-fb:39 goog-remb\r\na=rtcp-fb:39 transport-cc\r\na=rtcp-fb:39 ccm fir\r\na=rtcp-fb:39 nack\r\na=rtcp-fb:39 nack pli\r\na=fmtp:39 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=4d001f\r\na=rtpmap:40 rtx/90000\r\na=fmtp:40 apt=39\r\na=rtpmap:41 H264/90000\r\na=rtcp-fb:41 goog-remb\r\na=rtcp-fb:41 transport-cc\r\na=rtcp-fb:41 ccm fir\r\na=rtcp-fb:41 nack\r\na=rtcp-fb:41 nack pli\r\na=fmtp:41 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=f4001f\r\na=rtpmap:42 rtx/90000\r\na=fmtp:42 apt=41\r\na=rtpmap:43 H264/90000\r\na=rtcp-fb:43 goog-remb\r\na=rtcp-fb:43 transport-cc\r\na=rtcp-fb:43 ccm fir\r\na=rtcp-fb:43 nack\r\na=rtcp-fb:43 nack pli\r\na=fmtp:43 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=f4001f\r\na=rtpmap:44 rtx/90000\r\na=fmtp:44 apt=43\r\na=rtpmap:45 AV1/90000\r\na=rtcp-fb:45 goog-remb\r\na=rtcp-fb:45 transport-cc\r\na=rtcp-fb:45 ccm fir\r\na=rtcp-fb:45 nack\r\na=rtcp-fb:45 nack pli\r\na=fmtp:45 level-idx=5;profile=0;tier=0\r\na=rtpmap:46 rtx/90000\r\na=fmtp:46 apt=45\r\na=rtpmap:47 AV1/90000\r\na=rtcp-fb:47 goog-remb\r\na=rtcp-fb:47 transport-cc\r\na=rtcp-fb:47 ccm fir\r\na=rtcp-fb:47 nack\r\na=rtcp-fb:47 nack pli\r\na=fmtp:47 level-idx=5;profile=1;tier=0\r\na=rtpmap:48 rtx/90000\r\na=fmtp:48 apt=47\r\na=rtpmap:118 H264/90000\r\na=rtcp-fb:118 goog-remb\r\na=rtcp-fb:118 transport-cc\r\na=rtcp-fb:118 ccm fir\r\na=rtcp-fb:118 nack\r\na=rtcp-fb:118 nack pli\r\na=fmtp:118 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=64001f\r\na=rtpmap:119 rtx/90000\r\na=fmtp:119 apt=118\r\na=rtpmap:120 H264/90000\r\na=rtcp-fb:120 goog-remb\r\na=rtcp-fb:120 transport-cc\r\na=rtcp-fb:120 ccm fir\r\na=rtcp-fb:120 nack\r\na=rtcp-fb:120 nack pli\r\na=fmtp:120 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=64001f\r\na=rtpmap:121 rtx/90000\r\na=fmtp:121 apt=120\r\na=rtpmap:122 red/90000\r\na=rtpmap:123 rtx/90000\r\na=fmtp:123 apt=122\r\na=rtpmap:124 ulpfec/90000\r\na=rtpmap:49 flexfec-03/90000\r\na=rtcp-fb:49 goog-remb\r\na=rtcp-fb:49 transport-cc\r\na=fmtp:49 repair-window=10000000\r\n', '2026-08-20 01:58:55'),
(2, 'd537080bed7327bc483db8bc554771b83b7b24ce800d570347a644acedb91531', '335763731', 'candidate', '{\"candidate\":\"candidate:3664660241 1 udp 2113937151 f7d98e6b-c745-4a1d-88ef-557e32ed4d64.local 53341 typ host generation 0 ufrag 5Ln2 network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"5Ln2\"}', '2026-08-20 01:58:55'),
(3, 'd537080bed7327bc483db8bc554771b83b7b24ce800d570347a644acedb91531', '335763731', 'candidate', '{\"candidate\":\"candidate:1108195821 1 udp 1677729535 180.190.171.208 60082 typ srflx raddr 0.0.0.0 rport 0 generation 0 ufrag 5Ln2 network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"5Ln2\"}', '2026-08-20 01:58:55'),
(4, 'd537080bed7327bc483db8bc554771b83b7b24ce800d570347a644acedb91531', '626624796', 'answer', 'v=0\r\no=- 8329123663524736248 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0\r\na=extmap-allow-mixed\r\na=msid-semantic: WMS f7c68973-dc32-48ad-9b6e-3705fab5608a\r\nm=video 9 UDP/TLS/RTP/SAVPF 96 97 98 99 100 101 102 103 104 107 108 109 114 115 116 117 39 40 45 46 118 119 122 123 124\r\nc=IN IP4 0.0.0.0\r\na=rtcp:9 IN IP4 0.0.0.0\r\na=ice-ufrag:e2J0\r\na=ice-pwd:wI0ZRzMr6bFvZocC0DghutfE\r\na=ice-options:trickle\r\na=fingerprint:sha-256 3E:C9:04:E3:5F:1A:C9:E5:BF:C1:93:01:8E:BB:E4:4C:A4:47:4C:0B:A0:7C:07:6E:78:26:27:8A:70:7D:E8:02\r\na=setup:active\r\na=mid:0\r\na=extmap:1 urn:ietf:params:rtp-hdrext:toffset\r\na=extmap:2 http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time\r\na=extmap:3 urn:3gpp:video-orientation\r\na=extmap:4 http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01\r\na=extmap:5 http://www.webrtc.org/experiments/rtp-hdrext/playout-delay\r\na=extmap:6 http://www.webrtc.org/experiments/rtp-hdrext/video-content-type\r\na=extmap:7 http://www.webrtc.org/experiments/rtp-hdrext/video-timing\r\na=extmap:8 http://www.webrtc.org/experiments/rtp-hdrext/color-space\r\na=extmap:9 urn:ietf:params:rtp-hdrext:sdes:mid\r\na=extmap:10 urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id\r\na=extmap:11 urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id\r\na=sendonly\r\na=msid:f7c68973-dc32-48ad-9b6e-3705fab5608a 5c6ddeb6-ad86-4da2-bc64-130f841d99c3\r\na=rtcp-mux\r\na=rtcp-rsize\r\na=rtcp-xr:rcvr-rtt=all\r\na=rtcp-fb:96 rrtr\r\na=rtcp-fb:97 rrtr\r\na=rtcp-fb:98 rrtr\r\na=rtcp-fb:99 rrtr\r\na=rtcp-fb:100 rrtr\r\na=rtcp-fb:101 rrtr\r\na=rtcp-fb:102 rrtr\r\na=rtcp-fb:103 rrtr\r\na=rtcp-fb:104 rrtr\r\na=rtcp-fb:107 rrtr\r\na=rtcp-fb:108 rrtr\r\na=rtcp-fb:109 rrtr\r\na=rtcp-fb:114 rrtr\r\na=rtcp-fb:115 rrtr\r\na=rtcp-fb:116 rrtr\r\na=rtcp-fb:117 rrtr\r\na=rtcp-fb:39 rrtr\r\na=rtcp-fb:40 rrtr\r\na=rtcp-fb:45 rrtr\r\na=rtcp-fb:46 rrtr\r\na=rtcp-fb:118 rrtr\r\na=rtcp-fb:119 rrtr\r\na=rtcp-fb:122 rrtr\r\na=rtcp-fb:123 rrtr\r\na=rtcp-fb:124 rrtr\r\na=rtpmap:96 VP8/90000\r\na=rtcp-fb:96 goog-remb\r\na=rtcp-fb:96 transport-cc\r\na=rtcp-fb:96 ccm fir\r\na=rtcp-fb:96 nack\r\na=rtcp-fb:96 nack pli\r\na=rtpmap:97 rtx/90000\r\na=fmtp:97 apt=96\r\na=rtpmap:98 VP9/90000\r\na=rtcp-fb:98 goog-remb\r\na=rtcp-fb:98 transport-cc\r\na=rtcp-fb:98 ccm fir\r\na=rtcp-fb:98 nack\r\na=rtcp-fb:98 nack pli\r\na=fmtp:98 profile-id=0\r\na=rtpmap:99 rtx/90000\r\na=fmtp:99 apt=98\r\na=rtpmap:100 VP9/90000\r\na=rtcp-fb:100 goog-remb\r\na=rtcp-fb:100 transport-cc\r\na=rtcp-fb:100 ccm fir\r\na=rtcp-fb:100 nack\r\na=rtcp-fb:100 nack pli\r\na=fmtp:100 profile-id=2\r\na=rtpmap:101 rtx/90000\r\na=fmtp:101 apt=100\r\na=rtpmap:102 H264/90000\r\na=rtcp-fb:102 goog-remb\r\na=rtcp-fb:102 transport-cc\r\na=rtcp-fb:102 ccm fir\r\na=rtcp-fb:102 nack\r\na=rtcp-fb:102 nack pli\r\na=fmtp:102 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42001f\r\na=rtpmap:103 rtx/90000\r\na=fmtp:103 apt=102\r\na=rtpmap:104 H264/90000\r\na=rtcp-fb:104 goog-remb\r\na=rtcp-fb:104 transport-cc\r\na=rtcp-fb:104 ccm fir\r\na=rtcp-fb:104 nack\r\na=rtcp-fb:104 nack pli\r\na=fmtp:104 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42001f\r\na=rtpmap:107 rtx/90000\r\na=fmtp:107 apt=104\r\na=rtpmap:108 H264/90000\r\na=rtcp-fb:108 goog-remb\r\na=rtcp-fb:108 transport-cc\r\na=rtcp-fb:108 ccm fir\r\na=rtcp-fb:108 nack\r\na=rtcp-fb:108 nack pli\r\na=fmtp:108 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f\r\na=rtpmap:109 rtx/90000\r\na=fmtp:109 apt=108\r\na=rtpmap:114 H264/90000\r\na=rtcp-fb:114 goog-remb\r\na=rtcp-fb:114 transport-cc\r\na=rtcp-fb:114 ccm fir\r\na=rtcp-fb:114 nack\r\na=rtcp-fb:114 nack pli\r\na=fmtp:114 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42e01f\r\na=rtpmap:115 rtx/90000\r\na=fmtp:115 apt=114\r\na=rtpmap:116 H264/90000\r\na=rtcp-fb:116 goog-remb\r\na=rtcp-fb:116 transport-cc\r\na=rtcp-fb:116 ccm fir\r\na=rtcp-fb:116 nack\r\na=rtcp-fb:116 nack pli\r\na=fmtp:116 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=4d001f\r\na=rtpmap:117 rtx/90000\r\na=fmtp:117 apt=116\r\na=rtpmap:39 H264/90000\r\na=rtcp-fb:39 goog-remb\r\na=rtcp-fb:39 transport-cc\r\na=rtcp-fb:39 ccm fir\r\na=rtcp-fb:39 nack\r\na=rtcp-fb:39 nack pli\r\na=fmtp:39 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=4d001f\r\na=rtpmap:40 rtx/90000\r\na=fmtp:40 apt=39\r\na=rtpmap:45 AV1/90000\r\na=rtcp-fb:45 goog-remb\r\na=rtcp-fb:45 transport-cc\r\na=rtcp-fb:45 ccm fir\r\na=rtcp-fb:45 nack\r\na=rtcp-fb:45 nack pli\r\na=fmtp:45 level-idx=5;profile=0;tier=0\r\na=rtpmap:46 rtx/90000\r\na=fmtp:46 apt=45\r\na=rtpmap:118 H264/90000\r\na=rtcp-fb:118 goog-remb\r\na=rtcp-fb:118 transport-cc\r\na=rtcp-fb:118 ccm fir\r\na=rtcp-fb:118 nack\r\na=rtcp-fb:118 nack pli\r\na=fmtp:118 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=640032\r\na=rtpmap:119 rtx/90000\r\na=fmtp:119 apt=118\r\na=rtpmap:122 red/90000\r\na=rtpmap:123 rtx/90000\r\na=fmtp:123 apt=122\r\na=rtpmap:124 ulpfec/90000\r\na=ssrc-group:FID 3350265504 1160756705\r\na=ssrc:3350265504 cname:xhqI2jlI7syfPxEM\r\na=ssrc:1160756705 cname:xhqI2jlI7syfPxEM\r\n', '2026-08-20 01:58:57'),
(5, 'd537080bed7327bc483db8bc554771b83b7b24ce800d570347a644acedb91531', '626624796', 'candidate', '{\"candidate\":\"candidate:3046607724 1 udp 2113937151 e6f3438e-0149-406b-a14e-3a627f4f5ab0.local 51363 typ host generation 0 ufrag e2J0 network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"e2J0\"}', '2026-08-20 01:58:57'),
(6, 'd537080bed7327bc483db8bc554771b83b7b24ce800d570347a644acedb91531', '626624796', 'candidate', '{\"candidate\":\"candidate:3200707859 1 udp 1677729535 180.190.171.208 37947 typ srflx raddr 0.0.0.0 rport 0 generation 0 ufrag e2J0 network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"e2J0\"}', '2026-08-20 01:58:57'),
(7, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '335763731', 'offer', 'v=0\r\no=- 6477625010066541725 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0 1\r\na=extmap-allow-mixed\r\na=msid-semantic: WMS\r\nm=video 9 UDP/TLS/RTP/SAVPF 96 97 98 99 100 101 35 36 37 38 102 103 104 107 108 109 114 115 116 117 39 40 41 42 43 44 45 46 47 48 118 119 120 121 122 123 124 49\r\nc=IN IP4 0.0.0.0\r\na=rtcp:9 IN IP4 0.0.0.0\r\na=ice-ufrag:wYz0\r\na=ice-pwd:rXQaRe1nxHbPN1ef0fTCI8W2\r\na=ice-options:trickle\r\na=fingerprint:sha-256 89:47:9B:89:28:96:81:30:7D:7B:60:CE:7F:95:04:D2:16:F0:DD:EB:7D:04:85:7A:A3:86:8F:1B:A8:49:57:7C\r\na=setup:actpass\r\na=mid:0\r\na=extmap:1 urn:ietf:params:rtp-hdrext:toffset\r\na=extmap:2 http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time\r\na=extmap:3 urn:3gpp:video-orientation\r\na=extmap:4 http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01\r\na=extmap:5 http://www.webrtc.org/experiments/rtp-hdrext/playout-delay\r\na=extmap:6 http://www.webrtc.org/experiments/rtp-hdrext/video-content-type\r\na=extmap:7 http://www.webrtc.org/experiments/rtp-hdrext/video-timing\r\na=extmap:8 http://www.webrtc.org/experiments/rtp-hdrext/color-space\r\na=extmap:9 urn:ietf:params:rtp-hdrext:sdes:mid\r\na=extmap:10 urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id\r\na=extmap:11 urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id\r\na=recvonly\r\na=rtcp-mux\r\na=rtcp-rsize\r\na=rtcp-xr:rcvr-rtt=all\r\na=rtcp-fb:96 rrtr\r\na=rtcp-fb:97 rrtr\r\na=rtcp-fb:98 rrtr\r\na=rtcp-fb:99 rrtr\r\na=rtcp-fb:100 rrtr\r\na=rtcp-fb:101 rrtr\r\na=rtcp-fb:35 rrtr\r\na=rtcp-fb:36 rrtr\r\na=rtcp-fb:37 rrtr\r\na=rtcp-fb:38 rrtr\r\na=rtcp-fb:102 rrtr\r\na=rtcp-fb:103 rrtr\r\na=rtcp-fb:104 rrtr\r\na=rtcp-fb:107 rrtr\r\na=rtcp-fb:108 rrtr\r\na=rtcp-fb:109 rrtr\r\na=rtcp-fb:114 rrtr\r\na=rtcp-fb:115 rrtr\r\na=rtcp-fb:116 rrtr\r\na=rtcp-fb:117 rrtr\r\na=rtcp-fb:39 rrtr\r\na=rtcp-fb:40 rrtr\r\na=rtcp-fb:41 rrtr\r\na=rtcp-fb:42 rrtr\r\na=rtcp-fb:43 rrtr\r\na=rtcp-fb:44 rrtr\r\na=rtcp-fb:45 rrtr\r\na=rtcp-fb:46 rrtr\r\na=rtcp-fb:47 rrtr\r\na=rtcp-fb:48 rrtr\r\na=rtcp-fb:118 rrtr\r\na=rtcp-fb:119 rrtr\r\na=rtcp-fb:120 rrtr\r\na=rtcp-fb:121 rrtr\r\na=rtcp-fb:122 rrtr\r\na=rtcp-fb:123 rrtr\r\na=rtcp-fb:124 rrtr\r\na=rtcp-fb:49 rrtr\r\na=rtpmap:96 VP8/90000\r\na=rtcp-fb:96 goog-remb\r\na=rtcp-fb:96 transport-cc\r\na=rtcp-fb:96 ccm fir\r\na=rtcp-fb:96 nack\r\na=rtcp-fb:96 nack pli\r\na=rtpmap:97 rtx/90000\r\na=fmtp:97 apt=96\r\na=rtpmap:98 VP9/90000\r\na=rtcp-fb:98 goog-remb\r\na=rtcp-fb:98 transport-cc\r\na=rtcp-fb:98 ccm fir\r\na=rtcp-fb:98 nack\r\na=rtcp-fb:98 nack pli\r\na=fmtp:98 profile-id=0\r\na=rtpmap:99 rtx/90000\r\na=fmtp:99 apt=98\r\na=rtpmap:100 VP9/90000\r\na=rtcp-fb:100 goog-remb\r\na=rtcp-fb:100 transport-cc\r\na=rtcp-fb:100 ccm fir\r\na=rtcp-fb:100 nack\r\na=rtcp-fb:100 nack pli\r\na=fmtp:100 profile-id=2\r\na=rtpmap:101 rtx/90000\r\na=fmtp:101 apt=100\r\na=rtpmap:35 VP9/90000\r\na=rtcp-fb:35 goog-remb\r\na=rtcp-fb:35 transport-cc\r\na=rtcp-fb:35 ccm fir\r\na=rtcp-fb:35 nack\r\na=rtcp-fb:35 nack pli\r\na=fmtp:35 profile-id=1\r\na=rtpmap:36 rtx/90000\r\na=fmtp:36 apt=35\r\na=rtpmap:37 VP9/90000\r\na=rtcp-fb:37 goog-remb\r\na=rtcp-fb:37 transport-cc\r\na=rtcp-fb:37 ccm fir\r\na=rtcp-fb:37 nack\r\na=rtcp-fb:37 nack pli\r\na=fmtp:37 profile-id=3\r\na=rtpmap:38 rtx/90000\r\na=fmtp:38 apt=37\r\na=rtpmap:102 H264/90000\r\na=rtcp-fb:102 goog-remb\r\na=rtcp-fb:102 transport-cc\r\na=rtcp-fb:102 ccm fir\r\na=rtcp-fb:102 nack\r\na=rtcp-fb:102 nack pli\r\na=fmtp:102 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42001f\r\na=rtpmap:103 rtx/90000\r\na=fmtp:103 apt=102\r\na=rtpmap:104 H264/90000\r\na=rtcp-fb:104 goog-remb\r\na=rtcp-fb:104 transport-cc\r\na=rtcp-fb:104 ccm fir\r\na=rtcp-fb:104 nack\r\na=rtcp-fb:104 nack pli\r\na=fmtp:104 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42001f\r\na=rtpmap:107 rtx/90000\r\na=fmtp:107 apt=104\r\na=rtpmap:108 H264/90000\r\na=rtcp-fb:108 goog-remb\r\na=rtcp-fb:108 transport-cc\r\na=rtcp-fb:108 ccm fir\r\na=rtcp-fb:108 nack\r\na=rtcp-fb:108 nack pli\r\na=fmtp:108 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f\r\na=rtpmap:109 rtx/90000\r\na=fmtp:109 apt=108\r\na=rtpmap:114 H264/90000\r\na=rtcp-fb:114 goog-remb\r\na=rtcp-fb:114 transport-cc\r\na=rtcp-fb:114 ccm fir\r\na=rtcp-fb:114 nack\r\na=rtcp-fb:114 nack pli\r\na=fmtp:114 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42e01f\r\na=rtpmap:115 rtx/90000\r\na=fmtp:115 apt=114\r\na=rtpmap:116 H264/90000\r\na=rtcp-fb:116 goog-remb\r\na=rtcp-fb:116 transport-cc\r\na=rtcp-fb:116 ccm fir\r\na=rtcp-fb:116 nack\r\na=rtcp-fb:116 nack pli\r\na=fmtp:116 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=4d001f\r\na=rtpmap:117 rtx/90000\r\na=fmtp:117 apt=116\r\na=rtpmap:39 H264/90000\r\na=rtcp-fb:39 goog-remb\r\na=rtcp-fb:39 transport-cc\r\na=rtcp-fb:39 ccm fir\r\na=rtcp-fb:39 nack\r\na=rtcp-fb:39 nack pli\r\na=fmtp:39 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=4d001f\r\na=rtpmap:40 rtx/90000\r\na=fmtp:40 apt=39\r\na=rtpmap:41 H264/90000\r\na=rtcp-fb:41 goog-remb\r\na=rtcp-fb:41 transport-cc\r\na=rtcp-fb:41 ccm fir\r\na=rtcp-fb:41 nack\r\na=rtcp-fb:41 nack pli\r\na=fmtp:41 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=f4001f\r\na=rtpmap:42 rtx/90000\r\na=fmtp:42 apt=41\r\na=rtpmap:43 H264/90000\r\na=rtcp-fb:43 goog-remb\r\na=rtcp-fb:43 transport-cc\r\na=rtcp-fb:43 ccm fir\r\na=rtcp-fb:43 nack\r\na=rtcp-fb:43 nack pli\r\na=fmtp:43 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=f4001f\r\na=rtpmap:44 rtx/90000\r\na=fmtp:44 apt=43\r\na=rtpmap:45 AV1/90000\r\na=rtcp-fb:45 goog-remb\r\na=rtcp-fb:45 transport-cc\r\na=rtcp-fb:45 ccm fir\r\na=rtcp-fb:45 nack\r\na=rtcp-fb:45 nack pli\r\na=fmtp:45 level-idx=5;profile=0;tier=0\r\na=rtpmap:46 rtx/90000\r\na=fmtp:46 apt=45\r\na=rtpmap:47 AV1/90000\r\na=rtcp-fb:47 goog-remb\r\na=rtcp-fb:47 transport-cc\r\na=rtcp-fb:47 ccm fir\r\na=rtcp-fb:47 nack\r\na=rtcp-fb:47 nack pli\r\na=fmtp:47 level-idx=5;profile=1;tier=0\r\na=rtpmap:48 rtx/90000\r\na=fmtp:48 apt=47\r\na=rtpmap:118 H264/90000\r\na=rtcp-fb:118 goog-remb\r\na=rtcp-fb:118 transport-cc\r\na=rtcp-fb:118 ccm fir\r\na=rtcp-fb:118 nack\r\na=rtcp-fb:118 nack pli\r\na=fmtp:118 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=64001f\r\na=rtpmap:119 rtx/90000\r\na=fmtp:119 apt=118\r\na=rtpmap:120 H264/90000\r\na=rtcp-fb:120 goog-remb\r\na=rtcp-fb:120 transport-cc\r\na=rtcp-fb:120 ccm fir\r\na=rtcp-fb:120 nack\r\na=rtcp-fb:120 nack pli\r\na=fmtp:120 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=64001f\r\na=rtpmap:121 rtx/90000\r\na=fmtp:121 apt=120\r\na=rtpmap:122 red/90000\r\na=rtpmap:123 rtx/90000\r\na=fmtp:123 apt=122\r\na=rtpmap:124 ulpfec/90000\r\na=rtpmap:49 flexfec-03/90000\r\na=rtcp-fb:49 goog-remb\r\na=rtcp-fb:49 transport-cc\r\na=fmtp:49 repair-window=10000000\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\nc=IN IP4 0.0.0.0\r\na=ice-ufrag:wYz0\r\na=ice-pwd:rXQaRe1nxHbPN1ef0fTCI8W2\r\na=ice-options:trickle\r\na=fingerprint:sha-256 89:47:9B:89:28:96:81:30:7D:7B:60:CE:7F:95:04:D2:16:F0:DD:EB:7D:04:85:7A:A3:86:8F:1B:A8:49:57:7C\r\na=setup:actpass\r\na=mid:1\r\na=sctp-port:5000\r\na=max-message-size:262144\r\n', '2026-08-20 02:41:23'),
(8, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '335763731', 'candidate', '{\"candidate\":\"candidate:1690014225 1 udp 2113937151 12e11ae4-80c5-48ce-a2b7-429e9e27b4fd.local 55644 typ host generation 0 ufrag wYz0 network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"wYz0\"}', '2026-08-20 02:41:23'),
(9, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '335763731', 'candidate', '{\"candidate\":\"candidate:1690014225 1 udp 2113937151 12e11ae4-80c5-48ce-a2b7-429e9e27b4fd.local 55646 typ host generation 0 ufrag wYz0 network-cost 999\",\"sdpMid\":\"1\",\"sdpMLineIndex\":1,\"usernameFragment\":\"wYz0\"}', '2026-08-20 02:41:23'),
(10, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '335763731', 'candidate', '{\"candidate\":\"candidate:3580219987 1 udp 1677729535 180.190.171.208 59918 typ srflx raddr 0.0.0.0 rport 0 generation 0 ufrag wYz0 network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"wYz0\"}', '2026-08-20 02:41:23'),
(11, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '335763731', 'candidate', '{\"candidate\":\"candidate:3580219987 1 udp 1677729535 180.190.171.208 59970 typ srflx raddr 0.0.0.0 rport 0 generation 0 ufrag wYz0 network-cost 999\",\"sdpMid\":\"1\",\"sdpMLineIndex\":1,\"usernameFragment\":\"wYz0\"}', '2026-08-20 02:41:23'),
(12, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '626624796', 'answer', 'v=0\r\no=- 8376204769529620625 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0 1\r\na=extmap-allow-mixed\r\na=msid-semantic: WMS 4cfcd4fc-5574-44cb-a0d0-ef55584ebb58\r\nm=video 9 UDP/TLS/RTP/SAVPF 96 97 98 99 100 101 102 103 104 107 108 109 114 115 116 117 39 40 45 46 118 119 122 123 124\r\nc=IN IP4 0.0.0.0\r\na=rtcp:9 IN IP4 0.0.0.0\r\na=ice-ufrag:6Q8a\r\na=ice-pwd:IDw52q43/ZWADtyLQftunJ1i\r\na=ice-options:trickle\r\na=fingerprint:sha-256 6A:7C:60:C9:4B:17:19:9B:AB:10:99:8C:18:05:CF:0B:F5:66:D3:24:40:33:ED:68:82:9D:EF:97:23:F6:B1:EC\r\na=setup:active\r\na=mid:0\r\na=extmap:1 urn:ietf:params:rtp-hdrext:toffset\r\na=extmap:2 http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time\r\na=extmap:3 urn:3gpp:video-orientation\r\na=extmap:4 http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01\r\na=extmap:5 http://www.webrtc.org/experiments/rtp-hdrext/playout-delay\r\na=extmap:6 http://www.webrtc.org/experiments/rtp-hdrext/video-content-type\r\na=extmap:7 http://www.webrtc.org/experiments/rtp-hdrext/video-timing\r\na=extmap:8 http://www.webrtc.org/experiments/rtp-hdrext/color-space\r\na=extmap:9 urn:ietf:params:rtp-hdrext:sdes:mid\r\na=extmap:10 urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id\r\na=extmap:11 urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id\r\na=sendonly\r\na=msid:4cfcd4fc-5574-44cb-a0d0-ef55584ebb58 92f2abed-a2ed-4be5-b421-63dae046384a\r\na=rtcp-mux\r\na=rtcp-rsize\r\na=rtcp-xr:rcvr-rtt=all\r\na=rtcp-fb:96 rrtr\r\na=rtcp-fb:97 rrtr\r\na=rtcp-fb:98 rrtr\r\na=rtcp-fb:99 rrtr\r\na=rtcp-fb:100 rrtr\r\na=rtcp-fb:101 rrtr\r\na=rtcp-fb:102 rrtr\r\na=rtcp-fb:103 rrtr\r\na=rtcp-fb:104 rrtr\r\na=rtcp-fb:107 rrtr\r\na=rtcp-fb:108 rrtr\r\na=rtcp-fb:109 rrtr\r\na=rtcp-fb:114 rrtr\r\na=rtcp-fb:115 rrtr\r\na=rtcp-fb:116 rrtr\r\na=rtcp-fb:117 rrtr\r\na=rtcp-fb:39 rrtr\r\na=rtcp-fb:40 rrtr\r\na=rtcp-fb:45 rrtr\r\na=rtcp-fb:46 rrtr\r\na=rtcp-fb:118 rrtr\r\na=rtcp-fb:119 rrtr\r\na=rtcp-fb:122 rrtr\r\na=rtcp-fb:123 rrtr\r\na=rtcp-fb:124 rrtr\r\na=rtpmap:96 VP8/90000\r\na=rtcp-fb:96 goog-remb\r\na=rtcp-fb:96 transport-cc\r\na=rtcp-fb:96 ccm fir\r\na=rtcp-fb:96 nack\r\na=rtcp-fb:96 nack pli\r\na=rtpmap:97 rtx/90000\r\na=fmtp:97 apt=96\r\na=rtpmap:98 VP9/90000\r\na=rtcp-fb:98 goog-remb\r\na=rtcp-fb:98 transport-cc\r\na=rtcp-fb:98 ccm fir\r\na=rtcp-fb:98 nack\r\na=rtcp-fb:98 nack pli\r\na=fmtp:98 profile-id=0\r\na=rtpmap:99 rtx/90000\r\na=fmtp:99 apt=98\r\na=rtpmap:100 VP9/90000\r\na=rtcp-fb:100 goog-remb\r\na=rtcp-fb:100 transport-cc\r\na=rtcp-fb:100 ccm fir\r\na=rtcp-fb:100 nack\r\na=rtcp-fb:100 nack pli\r\na=fmtp:100 profile-id=2\r\na=rtpmap:101 rtx/90000\r\na=fmtp:101 apt=100\r\na=rtpmap:102 H264/90000\r\na=rtcp-fb:102 goog-remb\r\na=rtcp-fb:102 transport-cc\r\na=rtcp-fb:102 ccm fir\r\na=rtcp-fb:102 nack\r\na=rtcp-fb:102 nack pli\r\na=fmtp:102 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42001f\r\na=rtpmap:103 rtx/90000\r\na=fmtp:103 apt=102\r\na=rtpmap:104 H264/90000\r\na=rtcp-fb:104 goog-remb\r\na=rtcp-fb:104 transport-cc\r\na=rtcp-fb:104 ccm fir\r\na=rtcp-fb:104 nack\r\na=rtcp-fb:104 nack pli\r\na=fmtp:104 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42001f\r\na=rtpmap:107 rtx/90000\r\na=fmtp:107 apt=104\r\na=rtpmap:108 H264/90000\r\na=rtcp-fb:108 goog-remb\r\na=rtcp-fb:108 transport-cc\r\na=rtcp-fb:108 ccm fir\r\na=rtcp-fb:108 nack\r\na=rtcp-fb:108 nack pli\r\na=fmtp:108 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f\r\na=rtpmap:109 rtx/90000\r\na=fmtp:109 apt=108\r\na=rtpmap:114 H264/90000\r\na=rtcp-fb:114 goog-remb\r\na=rtcp-fb:114 transport-cc\r\na=rtcp-fb:114 ccm fir\r\na=rtcp-fb:114 nack\r\na=rtcp-fb:114 nack pli\r\na=fmtp:114 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42e01f\r\na=rtpmap:115 rtx/90000\r\na=fmtp:115 apt=114\r\na=rtpmap:116 H264/90000\r\na=rtcp-fb:116 goog-remb\r\na=rtcp-fb:116 transport-cc\r\na=rtcp-fb:116 ccm fir\r\na=rtcp-fb:116 nack\r\na=rtcp-fb:116 nack pli\r\na=fmtp:116 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=4d001f\r\na=rtpmap:117 rtx/90000\r\na=fmtp:117 apt=116\r\na=rtpmap:39 H264/90000\r\na=rtcp-fb:39 goog-remb\r\na=rtcp-fb:39 transport-cc\r\na=rtcp-fb:39 ccm fir\r\na=rtcp-fb:39 nack\r\na=rtcp-fb:39 nack pli\r\na=fmtp:39 level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=4d001f\r\na=rtpmap:40 rtx/90000\r\na=fmtp:40 apt=39\r\na=rtpmap:45 AV1/90000\r\na=rtcp-fb:45 goog-remb\r\na=rtcp-fb:45 transport-cc\r\na=rtcp-fb:45 ccm fir\r\na=rtcp-fb:45 nack\r\na=rtcp-fb:45 nack pli\r\na=fmtp:45 level-idx=5;profile=0;tier=0\r\na=rtpmap:46 rtx/90000\r\na=fmtp:46 apt=45\r\na=rtpmap:118 H264/90000\r\na=rtcp-fb:118 goog-remb\r\na=rtcp-fb:118 transport-cc\r\na=rtcp-fb:118 ccm fir\r\na=rtcp-fb:118 nack\r\na=rtcp-fb:118 nack pli\r\na=fmtp:118 level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=640032\r\na=rtpmap:119 rtx/90000\r\na=fmtp:119 apt=118\r\na=rtpmap:122 red/90000\r\na=rtpmap:123 rtx/90000\r\na=fmtp:123 apt=122\r\na=rtpmap:124 ulpfec/90000\r\na=ssrc-group:FID 3243997138 3621637442\r\na=ssrc:3243997138 cname:zkUkbD1PyfvR5/VF\r\na=ssrc:3621637442 cname:zkUkbD1PyfvR5/VF\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\nc=IN IP4 0.0.0.0\r\na=ice-ufrag:6Q8a\r\na=ice-pwd:IDw52q43/ZWADtyLQftunJ1i\r\na=ice-options:trickle\r\na=fingerprint:sha-256 6A:7C:60:C9:4B:17:19:9B:AB:10:99:8C:18:05:CF:0B:F5:66:D3:24:40:33:ED:68:82:9D:EF:97:23:F6:B1:EC\r\na=setup:active\r\na=mid:1\r\na=sctp-port:5000\r\na=max-message-size:262144\r\n', '2026-08-20 02:41:24'),
(13, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '626624796', 'candidate', '{\"candidate\":\"candidate:2282319441 1 udp 2113937151 e8493c22-e1dd-49e7-9230-6327cb8a7b99.local 62473 typ host generation 0 ufrag 6Q8a network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"6Q8a\"}', '2026-08-20 02:41:24'),
(14, '09f76d3e864ef4d3f8e828de9837e7b5db0ce86dd75cb3a02085b9cfb212c33c', '626624796', 'candidate', '{\"candidate\":\"candidate:2203584558 1 udp 1677729535 180.190.171.208 60147 typ srflx raddr 0.0.0.0 rport 0 generation 0 ufrag 6Q8a network-cost 999\",\"sdpMid\":\"0\",\"sdpMLineIndex\":0,\"usernameFragment\":\"6Q8a\"}', '2026-08-20 02:41:25');

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
  `sha256` char(64) DEFAULT NULL,
  `status` enum('started','completed','failed','cancelled') NOT NULL DEFAULT 'started',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

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
  ADD KEY `idx_devices_last_seen` (`last_seen_at`);

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `signals`
--
ALTER TABLE `signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `transfer_history`
--
ALTER TABLE `transfer_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
