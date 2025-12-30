-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 17, 2025 at 04:21 AM
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
-- Database: `geobagg`
--

-- --------------------------------------------------------

--
-- Table structure for table `active_sessions`
--

CREATE TABLE `active_sessions` (
  `id` int(11) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `active_sessions`
--

INSERT INTO `active_sessions` (`id`, `session_id`, `user_id`, `username`, `ip_address`, `user_agent`, `login_time`, `last_activity`, `expires_at`, `is_active`, `created_at`) VALUES
(50, 'dk9oe9bv1hlvfi56hutci4qqv2', 10, 'admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-17 08:55:01', '2025-12-17 08:55:01', NULL, 1, '2025-12-17 02:55:01');

-- --------------------------------------------------------

--
-- Table structure for table `api_access_log`
--

CREATE TABLE `api_access_log` (
  `id` int(11) NOT NULL,
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(10) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `request_data` text DEFAULT NULL,
  `response_code` int(11) DEFAULT NULL,
  `response_time` decimal(10,3) DEFAULT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint(20) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE','APPROVE','REJECT','LOGIN','LOGOUT') NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `changed_by` varchar(100) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `changed_by`, `user_id`, `ip_address`, `user_agent`, `changed_at`) VALUES
(90, 'auth', NULL, 'LOGIN', NULL, '{\"event\":6,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:01:07'),
(91, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"checker_test\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:01:07'),
(92, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"agm_test\",\"role\":\"agm ops\",\"status\":\"success\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:01:25'),
(93, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":7,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:07:44'),
(94, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"agm_test\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:07:44'),
(95, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"tester_test\",\"role\":\"tester\",\"status\":\"success\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:09:07'),
(96, 'auth', NULL, 'REJECT', NULL, '{\"event\":5,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:10:35'),
(97, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"tester_test\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:10:35'),
(98, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"checker_test\",\"role\":\"checker\",\"status\":\"success\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:10:52'),
(99, 'auth', NULL, 'LOGIN', NULL, '{\"event\":6,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:11:21'),
(100, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"checker_test\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:11:21'),
(101, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"agm_test\",\"role\":\"agm ops\",\"status\":\"success\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:11:40'),
(102, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":7,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:27:34'),
(103, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"agm_test\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:27:34'),
(104, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"prod_test\",\"role\":\"production_user\",\"status\":\"success\"}', 'prod_test', 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:27:56'),
(105, 'auth', NULL, 'DELETE', NULL, '{\"event\":3,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:40:51'),
(106, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"prod_test\"}', 'prod_test', 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:40:51'),
(107, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"tester_test\",\"role\":\"tester\",\"status\":\"success\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 07:42:48'),
(108, 'auth', NULL, 'REJECT', NULL, '{\"event\":5,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:03:21'),
(109, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"tester_test\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:03:21'),
(110, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"checker_test\",\"role\":\"checker\",\"status\":\"success\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:03:40'),
(111, 'auth', NULL, 'LOGIN', NULL, '{\"event\":6,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:04:09'),
(112, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"checker_test\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:04:09'),
(113, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"agm_test\",\"role\":\"agm ops\",\"status\":\"success\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:04:24'),
(114, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":7,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:07:07'),
(115, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"agm_test\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:07:07'),
(116, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"prod_test\",\"role\":\"production_user\",\"status\":\"success\"}', 'prod_test', 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 08:07:36'),
(117, 'auth', NULL, 'DELETE', NULL, '{\"event\":3,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:07:54'),
(118, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"prod_test\"}', 'prod_test', 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:07:54'),
(119, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"qc_test\",\"role\":\"qc_inspector\",\"status\":\"success\"}', 'qc_test', 4, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:08:09'),
(120, 'auth', NULL, 'APPROVE', NULL, '{\"event\":4,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:08:37'),
(121, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"qc_test\"}', 'qc_test', 4, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:08:37'),
(122, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"finance_test\",\"role\":\"finance_user\",\"status\":\"success\"}', 'finance_test', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:09:01'),
(123, 'auth', NULL, '', NULL, '{\"event\":8,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:25:45'),
(124, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"finance_test\"}', 'finance_test', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 09:25:45'),
(125, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"admin\",\"role\":\"admin\",\"status\":\"success\"}', 'admin', 10, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 12:08:09'),
(126, 'auth', NULL, '', NULL, '{\"event\":10,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:03:42'),
(127, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"admin\"}', 'admin', 10, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:03:43'),
(128, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"mgmt_test\",\"role\":\"management\",\"status\":\"success\"}', 'mgmt_test', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:04:10'),
(129, 'auth', NULL, '', NULL, '{\"event\":9,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:05:03'),
(130, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"mgmt_test\"}', 'mgmt_test', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:05:03'),
(131, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"agm_test\",\"role\":\"agm ops\",\"status\":\"success\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:07:19'),
(132, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":7,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:08:16'),
(133, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"agm_test\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:08:16'),
(134, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"tester_test\",\"role\":\"tester\",\"status\":\"success\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:08:36'),
(135, 'auth', NULL, 'REJECT', NULL, '{\"event\":5,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:09:25'),
(136, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"tester_test\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:09:25'),
(137, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"checker_test\",\"role\":\"checker\",\"status\":\"success\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:10:18'),
(138, 'auth', NULL, 'LOGIN', NULL, '{\"event\":6,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:10:51'),
(139, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"checker_test\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:10:51'),
(140, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"agm_test\",\"role\":\"agm ops\",\"status\":\"success\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:11:10'),
(141, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":7,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:13:58'),
(142, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"agm_test\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:13:58'),
(143, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"tester_test\",\"role\":\"tester\",\"status\":\"success\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:14:23'),
(144, 'auth', NULL, 'REJECT', NULL, '{\"event\":5,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:19:01'),
(145, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"tester_test\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:19:01'),
(146, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"checker_test\",\"role\":\"checker\",\"status\":\"success\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:19:32'),
(147, 'auth', NULL, 'LOGIN', NULL, '{\"event\":6,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:26:32'),
(148, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"checker_test\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:26:32'),
(149, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"tester_test\",\"role\":\"tester\",\"status\":\"success\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:27:03'),
(150, 'auth', NULL, 'REJECT', NULL, '{\"event\":5,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:27:25'),
(151, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"tester_test\"}', 'tester_test', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:27:25'),
(152, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"checker_test\",\"role\":\"checker\",\"status\":\"success\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:27:44'),
(153, 'auth', NULL, 'LOGIN', NULL, '{\"event\":6,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:28:26'),
(154, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"checker_test\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:28:26'),
(155, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"agm_test\",\"role\":\"agm ops\",\"status\":\"success\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:28:48'),
(156, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":7,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:35:13'),
(157, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"agm_test\"}', 'agm_test', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:35:13'),
(158, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"checker_test\",\"role\":\"checker\",\"status\":\"success\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:35:40'),
(159, 'auth', NULL, 'LOGIN', NULL, '{\"event\":6,\"details\":\"::1\"}', 'logout', 0, 'User logged out', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:35:48'),
(160, 'auth', NULL, 'LOGOUT', NULL, '{\"event\":\"logout\",\"details\":\"User logged out\",\"user\":\"checker_test\"}', 'checker_test', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-16 13:35:48'),
(161, 'auth', NULL, 'LOGIN', NULL, '{\"event\":\"login\",\"username\":\"admin\",\"role\":\"admin\",\"status\":\"success\"}', 'admin', 10, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', '2025-12-17 02:55:01');

-- --------------------------------------------------------

--
-- Table structure for table `backup_log`
--

CREATE TABLE `backup_log` (
  `id` int(11) NOT NULL,
  `backup_type` enum('full','incremental','differential') NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `status` enum('started','completed','failed') NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bag_size_master`
--

CREATE TABLE `bag_size_master` (
  `id` int(11) NOT NULL,
  `sl_no` int(11) NOT NULL,
  `bag_size` varchar(50) NOT NULL,
  `thickness` decimal(5,2) NOT NULL,
  `gsm` int(11) NOT NULL,
  `bag_capacity` varchar(20) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bag_size_master`
--

INSERT INTO `bag_size_master` (`id`, `sl_no`, `bag_size`, `thickness`, `gsm`, `bag_capacity`, `unit_price`, `created_at`) VALUES
(1, 1, '2000mmx1500mm', 4.50, 600, '800 kg', 0.00, '2025-12-15 11:07:57'),
(2, 2, '1200mmx950mm', 3.30, 450, '250 kg', 0.00, '2025-12-15 11:07:57'),
(3, 3, '1250mmx1000mm', 3.30, 450, '250 kg', 0.00, '2025-12-15 11:07:57'),
(4, 4, '1250mmx1000mm', 3.00, 400, '250 kg', 0.00, '2025-12-15 11:07:57'),
(5, 5, '1225mmx1000mm', 3.00, 400, '250 kg', 0.00, '2025-12-15 11:07:57'),
(6, 6, '1200mmx950mm', 3.00, 400, '250 kg', 0.00, '2025-12-15 11:07:57'),
(7, 7, '1200mmx950mm', 2.50, 350, '250 kg', 0.00, '2025-12-15 11:07:57'),
(8, 8, '1300mmx1050mm', 2.50, 350, '250 kg', 0.00, '2025-12-15 11:07:57'),
(9, 9, '1600mmx850mm', 3.30, 450, '250 kg', 0.00, '2025-12-15 11:07:57'),
(10, 10, '1100mmx850mm', 3.30, 450, '250 kg', 0.00, '2025-12-15 11:07:57'),
(11, 11, '1100mmx850mm', 3.00, 400, '200 kg', 0.00, '2025-12-15 11:07:57'),
(12, 12, '1200mmx600mm', 3.00, 400, '200 kg', 0.00, '2025-12-15 11:07:57'),
(13, 13, '1100mmx800mm', 3.00, 400, '200 kg', 0.00, '2025-12-15 11:07:57'),
(14, 14, '1125mmx900mm', 3.30, 450, '175 kg', 0.00, '2025-12-15 11:07:57'),
(15, 15, '1125mmx900mm', 3.00, 400, '175 kg', 0.00, '2025-12-15 11:07:57'),
(16, 16, '1125mmx900mm', 2.30, 300, '175 kg', 0.00, '2025-12-15 11:07:57'),
(17, 17, '1150mmx800mm', 3.30, 450, '200 kg', 0.00, '2025-12-15 11:07:57'),
(18, 18, '1125mmx900mm', 2.50, 350, '200 kg', 0.00, '2025-12-15 11:07:57'),
(19, 19, '1150mmx850mm', 3.00, 400, '200 kg', 0.00, '2025-12-15 11:07:57'),
(20, 20, '1150mmx900mm', 3.30, 450, '175 kg', 0.00, '2025-12-15 11:07:57'),
(21, 21, '1300mmx1050mm', 3.30, 450, '200 kg', 0.00, '2025-12-15 11:07:57'),
(22, 22, '1700mmx1250mm', 4.50, 600, '500 kg', 0.00, '2025-12-15 11:07:57'),
(23, 23, '1050mmx800mm', 3.30, 450, '175 kg', 0.00, '2025-12-15 11:07:57'),
(24, 24, '1050mmx800mm', 3.00, 400, '175 kg', 0.00, '2025-12-15 11:07:57'),
(25, 25, '1050mmx800mm', 2.50, 350, '175 kg', 0.00, '2025-12-15 11:07:57'),
(26, 26, '1075mmx850mm', 3.30, 450, '175 kg', 0.00, '2025-12-15 11:07:57'),
(27, 27, '1075mmx850mm', 3.00, 400, '175 kg', 0.00, '2025-12-15 11:07:57'),
(28, 28, '1075mmx850mm', 2.50, 350, '175 kg', 0.00, '2025-12-15 11:07:57'),
(29, 29, '1030mmx700mm', 3.00, 400, '126 kg', 0.00, '2025-12-15 11:07:57'),
(30, 30, '1030mmx700mm', 2.50, 350, '126 kg', 0.00, '2025-12-15 11:07:57'),
(31, 31, '1030mmx700mm', 3.30, 450, '126 kg', 0.00, '2025-12-15 11:07:57'),
(32, 32, '1000mmx800mm', 3.30, 450, '125 kg', 0.00, '2025-12-15 11:07:57'),
(33, 33, '950mmx750mm', 3.30, 450, '125 kg', 0.00, '2025-12-15 11:07:57'),
(34, 34, '950mmx750mm', 3.00, 400, '125 kg', 0.00, '2025-12-15 11:07:57'),
(35, 35, '950mmx500mm', 3.30, 450, '125 kg', 0.00, '2025-12-15 11:07:57'),
(36, 36, '830mmx600mm', 2.50, 350, '100 kg', 0.00, '2025-12-15 11:07:57'),
(37, 37, '830mmx600mm', 3.00, 400, '78 kg', 0.00, '2025-12-15 11:07:57'),
(38, 38, '1030mmx700mm', 2.30, 300, '126 kg', 0.00, '2025-12-15 11:07:57'),
(39, 39, '1000mmx800mm', 3.00, 400, '125 kg', 0.00, '2025-12-15 11:07:57'),
(40, 40, '300mmx299mm', 5.00, 570, '35 kg', 0.00, '2025-12-15 11:07:57'),
(41, 41, '500mmx499mm', 5.00, 570, '170 kg', 0.00, '2025-12-15 11:07:57'),
(42, 42, '700mmx700mm', 5.00, 570, '450 kg', 0.00, '2025-12-15 11:07:57'),
(43, 44, '1000mmx800mm', 3.00, 400, '125 kg', 0.00, '2025-12-15 11:07:57'),
(44, 45, '1000mmx800mm', 3.00, 300, '120 kg', 0.00, '2025-12-15 11:07:57'),
(45, 46, '850mmx700mm', 3.00, 400, '75 kg', 0.00, '2025-12-15 11:07:57'),
(46, 47, '1030mmx750mm', 3.00, 400, '126 kg', 0.00, '2025-12-15 11:07:57'),
(47, 48, '1000mmx700mm', 3.00, 400, '125 kg', 0.00, '2025-12-15 11:07:57');

-- --------------------------------------------------------

--
-- Table structure for table `bom`
--

CREATE TABLE `bom` (
  `id` int(11) NOT NULL,
  `product_type` varchar(20) DEFAULT 'bag',
  `product_id` int(11) DEFAULT NULL,
  `bag_size` varchar(100) DEFAULT NULL,
  `unit_price` decimal(12,2) DEFAULT 0.00,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `weight` decimal(10,2) DEFAULT 0.00,
  `gsm` decimal(10,2) DEFAULT NULL,
  `thickness_mm` decimal(10,2) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branding_entries`
--

CREATE TABLE `branding_entries` (
  `id` int(11) NOT NULL,
  `branding_id` varchar(50) DEFAULT NULL,
  `date_time` datetime NOT NULL,
  `shift_incharge` varchar(100) DEFAULT NULL,
  `reporter_id` int(11) DEFAULT NULL,
  `reporter_name` varchar(100) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `bag_size` varchar(100) DEFAULT NULL,
  `print_qty` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reference_number` varchar(100) DEFAULT NULL,
  `cnc_cutting_batch` varchar(100) DEFAULT NULL,
  `print_machine` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bundle_test_completion`
--

CREATE TABLE `bundle_test_completion` (
  `id` int(11) NOT NULL,
  `bundle_reference` varchar(100) NOT NULL,
  `test_type` varchar(50) NOT NULL,
  `status` enum('pending','completed','passed') DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `characteristics_readings`
--

CREATE TABLE `characteristics_readings` (
  `id` int(11) NOT NULL,
  `char_test_id` int(11) NOT NULL,
  `sieve_size` decimal(10,3) DEFAULT NULL,
  `retained` decimal(10,3) DEFAULT NULL,
  `cumulative` decimal(10,3) DEFAULT NULL,
  `cumulative_passing` decimal(10,3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `characteristics_tests`
--

CREATE TABLE `characteristics_tests` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `lab_test_number` varchar(50) NOT NULL,
  `test_standard` varchar(100) DEFAULT NULL,
  `test_materials` varchar(255) NOT NULL,
  `reference_number` varchar(200) DEFAULT NULL,
  `bundle_reference` varchar(200) DEFAULT NULL,
  `gsm` decimal(10,4) DEFAULT NULL,
  `roll_number` varchar(100) DEFAULT NULL,
  `sample_id` varchar(100) DEFAULT NULL,
  `specimen_size` decimal(10,4) NOT NULL,
  `specimen_size_unit` varchar(10) NOT NULL,
  `sand_type` varchar(100) NOT NULL,
  `sand_weight` decimal(10,4) NOT NULL,
  `sand_weight_unit` varchar(10) NOT NULL,
  `sieving_time` decimal(10,4) NOT NULL,
  `sieving_time_unit` varchar(10) NOT NULL,
  `sample_received` date NOT NULL,
  `sample_tested` datetime NOT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `test_performed_by` varchar(255) NOT NULL,
  `checker_name` varchar(255) DEFAULT NULL,
  `approver_name` varchar(255) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','checked','approved','rejected','resubmitted') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `client_name` varchar(200) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `client_name`, `contact_person`, `phone`, `email`, `address`, `is_active`, `created_at`) VALUES
(1, 'Confidence Infrastructure Ltd.(ZDL)', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(2, 'M/S Saleh Ahmed', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(3, 'Sigma engineers Ltd', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(4, 'Confidence Infrastructure Ltd(EPC-1)', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(5, 'M/S Amir Engineering Corporation', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(6, 'TEESTA SOLAR LIMITED', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(7, 'MD. FRIDUL ISLAM', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(8, 'M/S LUCKY CONSTRUCTION', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(9, 'M/S GOLDER TRADING CORP.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(10, 'Grameen Trade International', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(11, 'M/S. Masuma Begum', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(12, 'M/S.M.R. Construction', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(13, 'Sulekhasons Limited', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(14, 'M/S. Shaju Hardware', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(15, 'S.S. Rahman International Ltd', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(16, 'Bengal Structure Development Ltd - Future Infrastructure Development Ltd. (Joint venture)', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(17, 'OTJ Joint Venture', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(18, 'NATIONAL DEVELOPMENT ENGINEERS LTD.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(19, 'Azmirr Builders Limited', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(20, 'Bengal Structure Development Ltd- Future Insfrastrcture Development Ltd.(Joint venture)', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(21, 'M/S SK Emdadul Haque Al Mamun', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(22, 'M/S. Zinnat Ali Zinnah Limited', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(23, 'MIR AKHTER - WMCG JV', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(24, 'GOLAM RABBANI CONSTRUCTION LTD.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(25, 'ZINNAT ALI ZINNAH LIMITED', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(26, 'SK.Emdadul Haque Al-Mamun', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(27, 'Confidence Infrastructure Limited (EPC-3)', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(28, 'HUDA TRADERS', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(29, 'M/S. MOSTAFA & SONS', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(30, 'ATAUR RAHMAN KHAN LTD.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(31, 'Samuda Construction Ltd.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(32, 'BISWAS TRADING & CONSTRUCTION', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(33, 'Confidence Infrastructure Ltd.(CIL-Dredging Unit)', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(34, 'Orient Trading & Builders Ltd.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(35, 'Tajwar Trade System Ltd', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(36, 'M/S AMIR HOSSEN TRADERS', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(37, 'CREATIVE ENGINEERS LTD.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(38, 'BRAC', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(39, 'HUDA HOLDINGS LIMITED', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(40, 'Nahee Geotextile Ltd.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(41, 'Orchid Geotextile Ltd', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(42, 'Hassan & Brothers', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(43, 'DIRD Felt Limited', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(44, 'M/s. Ahad Builders', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(45, 'M/S.SHUKTARA CONSTRUCTION', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(46, 'Project Director, PMO-FRERMIP, BWDB, Dhaka', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(47, 'ARC Construction Company', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(48, 'BALY SHRIMP HATCHERY', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(49, 'Excel Construction', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(50, 'M/S .khandaker shahin ahmed', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(51, 'S.S Engineering & Construction Ltd.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(52, 'M/S. Sokina & Sons', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(53, 'M/S.AR-R\'AD Corporation', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(54, 'Eagle Ridge Engineering & Construction (BD) Ltd.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(55, 'SWAN INTERNATIONAL (PVT.) LIMITED', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(56, 'M/S Sarika Traders', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(57, 'Nation Tech Communication Limited', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(58, 'Bengal Group of Industries', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(59, 'MASTERMIND ENGINEERING LTD.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(60, 'Alam & Brothers', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(61, 'M.M. Builders & Engineers LTD', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(62, 'Property Development Ltd', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(63, 'KCEL-BECL JV', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(64, 'CASTLE CONSTRUCTION CO. LIMITED', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(65, 'Standard Engineers LTD', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(66, 'M/S Rasa Enterprise', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(67, 'First S.S Enterprise (Pvt.) Ltd.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(68, 'CROSSIFIC CONSTRUCTION', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(69, 'BAY DREDGERS LIMITED', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(70, 'Future Infrastructure Development Ltd.', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(71, 'Sazzad Hossain', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(72, 'BIFEX ENGINEERING', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(73, 'Md. Ataur Rahman', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(74, 'Energypac Infrastructure & Development Ltd', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(75, 'Bismillahi Engineering Corporation Limited', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(76, 'Md. Afaz Uddin', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(77, 'M/S Masud Trading Corporation', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(78, 'Shahin Sharif', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(79, 'IRA Enterprise', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(80, 'Al-Mostafa Group', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(81, 'Confidence Cement Dhaka Ltd', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26'),
(82, 'TEKKEN CORPORATION client list', NULL, NULL, NULL, NULL, 1, '2025-12-15 11:02:26');

-- --------------------------------------------------------

--
-- Table structure for table `cnc_entries`
--

CREATE TABLE `cnc_entries` (
  `id` int(11) NOT NULL,
  `date_time` datetime NOT NULL,
  `shift` enum('Day','Night') NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `bag_size` varchar(100) NOT NULL,
  `cnc_id` varchar(50) DEFAULT NULL,
  `cnc_machine_id` varchar(50) DEFAULT NULL,
  `cutting_roll_quantity` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `cnc_cutting_batch` varchar(150) DEFAULT NULL,
  `actual_weight` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cut_length_fiber_reports`
--

CREATE TABLE `cut_length_fiber_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `store_entry_reference` varchar(100) DEFAULT NULL,
  `sample_description` text DEFAULT NULL,
  `lc_no` varchar(100) DEFAULT NULL,
  `sample_received_from` varchar(255) DEFAULT NULL,
  `sample_collected_from` varchar(255) DEFAULT NULL,
  `manufacturer_name` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `received_date` date DEFAULT NULL,
  `test_start_date` date DEFAULT NULL,
  `test_end_date` date DEFAULT NULL,
  `others_information` text DEFAULT NULL,
  `test_temperature` decimal(10,2) DEFAULT 0.00,
  `rh_percent` decimal(5,2) DEFAULT 0.00,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `rejected_by` varchar(100) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_gsm_checks`
--

CREATE TABLE `daily_gsm_checks` (
  `id` int(11) NOT NULL,
  `entry_id` varchar(50) DEFAULT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `roll_no` varchar(50) DEFAULT NULL,
  `date_time` datetime DEFAULT NULL,
  `shift` varchar(50) DEFAULT NULL,
  `line_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `inspector` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `size_type` varchar(50) DEFAULT NULL,
  `size_value` varchar(50) DEFAULT NULL,
  `weight_left` decimal(10,2) DEFAULT NULL,
  `weight_left_middle` decimal(10,2) DEFAULT NULL,
  `weight_right_middle` decimal(10,2) DEFAULT NULL,
  `weight_right` decimal(10,2) DEFAULT NULL,
  `gsm_left` decimal(6,2) DEFAULT NULL,
  `gsm_left_middle` decimal(6,2) DEFAULT NULL,
  `gsm_right_middle` decimal(6,2) DEFAULT NULL,
  `right` decimal(6,2) DEFAULT NULL,
  `avg_gsm` decimal(6,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `gsm_right` decimal(6,2) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `error_log`
--

CREATE TABLE `error_log` (
  `id` int(11) NOT NULL,
  `error_type` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `line_number` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_url` text DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_after_production_counters`
--

CREATE TABLE `fabric_after_production_counters` (
  `date_key` varchar(8) NOT NULL,
  `counter` int(11) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_after_production_tests`
--

CREATE TABLE `fabric_after_production_tests` (
  `id` int(11) NOT NULL,
  `report_number` varchar(50) DEFAULT NULL,
  `gsm` varchar(50) DEFAULT NULL,
  `line_number` int(11) DEFAULT NULL,
  `roll_number` varchar(100) DEFAULT NULL,
  `sample_id` varchar(100) DEFAULT NULL,
  `batch_information` varchar(100) DEFAULT NULL,
  `received_from` varchar(200) DEFAULT NULL,
  `sample_received_date` datetime DEFAULT NULL,
  `sample_tested_date` date DEFAULT NULL,
  `test_performed_by` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `qc_entry_id` int(11) DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_pre_production_counters`
--

CREATE TABLE `fabric_pre_production_counters` (
  `date_key` varchar(8) NOT NULL,
  `counter` int(11) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_pre_production_tests`
--

CREATE TABLE `fabric_pre_production_tests` (
  `id` int(11) NOT NULL,
  `report_number` varchar(50) DEFAULT NULL,
  `sample_details` varchar(255) DEFAULT NULL,
  `sample_collected_from` varchar(255) DEFAULT NULL,
  `batch_information` varchar(100) DEFAULT NULL,
  `gsm` int(11) DEFAULT NULL,
  `line_no` int(11) DEFAULT NULL,
  `roll_number` varchar(100) DEFAULT NULL,
  `product_reference` varchar(255) DEFAULT NULL,
  `customer_reference` varchar(255) DEFAULT NULL,
  `sample_received_date` datetime DEFAULT NULL,
  `sample_production_date` date DEFAULT NULL,
  `test_period_from` date DEFAULT NULL,
  `test_period_to` date DEFAULT NULL,
  `sample_received_from` varchar(255) DEFAULT NULL,
  `lighthouse_reference` varchar(255) DEFAULT NULL,
  `test_performed_by` varchar(100) DEFAULT NULL,
  `temperature` decimal(5,2) DEFAULT NULL,
  `rh_percentage` decimal(5,2) DEFAULT NULL,
  `others_information` text DEFAULT NULL,
  `checked_by` varchar(100) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `checker_remarks` text DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `qc_entry_id` int(11) DEFAULT NULL,
  `test_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending_checker','pending_approval','approved','rejected_by_checker','rejected_by_approver') DEFAULT 'pending_checker',
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_reports`
--

CREATE TABLE `fabric_reports` (
  `id` int(11) NOT NULL,
  `report_type` enum('pre_production','after_production','summary') NOT NULL,
  `sample_reference_id` varchar(100) NOT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `report_no` varchar(50) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `project_name` varchar(255) DEFAULT NULL,
  `inspector_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_testing`
--

CREATE TABLE `fabric_testing` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `batch_info` varchar(255) DEFAULT NULL,
  `product_reference` varchar(255) DEFAULT NULL,
  `sample_received_at` datetime DEFAULT NULL,
  `sample_production_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_testing_readings`
--

CREATE TABLE `fabric_testing_readings` (
  `id` int(11) NOT NULL,
  `fabric_test_id` int(11) NOT NULL,
  `test_standard_id` int(11) DEFAULT NULL,
  `test_type` varchar(255) DEFAULT NULL,
  `direction` enum('MD','CD') DEFAULT NULL,
  `value` decimal(10,2) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fg`
--

CREATE TABLE `fg` (
  `id` int(11) NOT NULL,
  `product_name` varchar(100) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `prod_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `received_qty` decimal(10,2) DEFAULT NULL,
  `delivery_qty` decimal(10,2) DEFAULT NULL,
  `client` varchar(100) DEFAULT NULL,
  `batch_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fg_deliveries`
--

CREATE TABLE `fg_deliveries` (
  `id` int(11) NOT NULL,
  `delivery_id` varchar(50) DEFAULT NULL,
  `fg_entry_id` int(11) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `cnc_cutting_batch` varchar(100) DEFAULT NULL,
  `delivery_date` date NOT NULL,
  `shift` varchar(20) DEFAULT NULL,
  `challan_no` varchar(50) DEFAULT NULL,
  `truck_no` varchar(50) DEFAULT NULL,
  `destination` varchar(200) DEFAULT NULL,
  `delivery_quantity` decimal(10,2) NOT NULL,
  `delivery_product_type` varchar(20) DEFAULT 'bag',
  `delivery_roll_entry_type` varchar(20) DEFAULT '',
  `client_id` int(11) DEFAULT NULL,
  `client_name` varchar(200) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `delivered_by` varchar(100) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `bag_size` varchar(50) DEFAULT NULL,
  `packaging_type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fg_delivery`
--

CREATE TABLE `fg_delivery` (
  `id` int(11) NOT NULL,
  `fg_id` int(11) NOT NULL,
  `delivery_qty` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `date_time` datetime NOT NULL,
  `delivery_id` varchar(50) DEFAULT NULL,
  `truck_no` varchar(50) DEFAULT NULL,
  `destination` varchar(255) DEFAULT NULL,
  `challan_no` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `reporter_name` varchar(100) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fg_entry`
--

CREATE TABLE `fg_entry` (
  `id` int(11) NOT NULL,
  `fg_id` varchar(50) DEFAULT NULL,
  `date_time` datetime NOT NULL,
  `shift` enum('Day','Night') NOT NULL,
  `product_type` varchar(20) DEFAULT NULL,
  `roll_entry_type` varchar(20) DEFAULT NULL,
  `shift_in_charge` varchar(100) DEFAULT 'User',
  `project_id` int(11) NOT NULL,
  `bag_size` varchar(50) NOT NULL,
  `recommended_weight` decimal(10,2) DEFAULT NULL,
  `actual_weight` decimal(10,2) NOT NULL,
  `measurement_type` varchar(20) DEFAULT NULL,
  `total_area` decimal(10,2) DEFAULT NULL,
  `quality_checked` int(11) NOT NULL,
  `passed_qty` int(11) NOT NULL,
  `rejected_qty` int(11) NOT NULL,
  `packaging_type` varchar(100) DEFAULT NULL,
  `batch_number` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `cnc_cutting_batch` varchar(100) DEFAULT NULL,
  `delivered_quantity` decimal(10,2) DEFAULT 0.00,
  `status` varchar(20) DEFAULT 'Available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fg_received`
--

CREATE TABLE `fg_received` (
  `id` int(11) NOT NULL,
  `fg_id` varchar(50) NOT NULL,
  `date_time` datetime NOT NULL,
  `shift` varchar(20) NOT NULL,
  `qc_inspector` varchar(100) NOT NULL,
  `project_id` int(11) NOT NULL,
  `bag_size` varchar(100) NOT NULL,
  `recommended_weight` int(11) NOT NULL,
  `actual_weight` int(11) NOT NULL,
  `quality_checked` int(11) NOT NULL,
  `passed_qty` int(11) NOT NULL,
  `rejected_qty` int(11) NOT NULL,
  `packaging_type` varchar(100) NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_entries`
--

CREATE TABLE `fiber_entries` (
  `id` int(11) NOT NULL,
  `entry_code` varchar(50) NOT NULL,
  `date_time` datetime NOT NULL,
  `shift` varchar(50) DEFAULT '',
  `shift_incharge` varchar(100) NOT NULL,
  `reference` varchar(100) DEFAULT '',
  `project_id` int(11) NOT NULL,
  `amount_kg` decimal(10,2) DEFAULT 0.00,
  `recycled_type` varchar(50) DEFAULT 'none',
  `recycled_amount_kg` decimal(10,2) DEFAULT 0.00,
  `total_amount_kg` decimal(10,2) DEFAULT 0.00,
  `recycled_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `amount` decimal(10,2) DEFAULT NULL,
  `origin` varchar(255) NOT NULL,
  `material_type` varchar(100) DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `belt_weight` int(11) DEFAULT 0,
  `belt_number` varchar(100) DEFAULT '',
  `summary` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_pretesting`
--

CREATE TABLE `fiber_pretesting` (
  `id` int(11) NOT NULL,
  `sample_ref` varchar(50) NOT NULL,
  `batch_no` varchar(50) DEFAULT NULL,
  `supplier_name` varchar(100) DEFAULT NULL,
  `sample_from` varchar(100) DEFAULT NULL,
  `test_date` date NOT NULL,
  `test_time` time NOT NULL,
  `gsm_avg` decimal(6,2) DEFAULT NULL,
  `gsm_min` decimal(6,2) DEFAULT NULL,
  `gsm_max` decimal(6,2) DEFAULT NULL,
  `thickness_avg` decimal(5,3) DEFAULT NULL,
  `thickness_direction` enum('MD','CD','Both') DEFAULT NULL,
  `tensile_md_strength` decimal(8,2) DEFAULT NULL,
  `tensile_md_elongation` decimal(5,2) DEFAULT NULL,
  `tensile_cd_strength` decimal(8,2) DEFAULT NULL,
  `tensile_cd_elongation` decimal(5,2) DEFAULT NULL,
  `cbr_force` decimal(8,2) DEFAULT NULL,
  `cbr_displacement` decimal(6,2) DEFAULT NULL,
  `grab_force` decimal(8,2) DEFAULT NULL,
  `grab_elongation` decimal(5,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `result` enum('Pass','Fail') DEFAULT 'Pass',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `date_time` datetime DEFAULT NULL,
  `shift` varchar(20) DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `lot_no` varchar(100) DEFAULT NULL,
  `fiber_type` varchar(100) DEFAULT NULL,
  `gsm` int(11) DEFAULT NULL,
  `line_no` int(11) DEFAULT NULL,
  `roll_no` int(11) DEFAULT NULL,
  `auto_batch` varchar(150) DEFAULT NULL,
  `thickness_avg_mm` int(11) DEFAULT NULL,
  `direction` varchar(10) DEFAULT NULL,
  `md_strength_knpm` int(11) DEFAULT NULL,
  `md_elongation_pct` int(11) DEFAULT NULL,
  `cd_strength_knpm` int(11) DEFAULT NULL,
  `cd_elongation_pct` int(11) DEFAULT NULL,
  `cbr_force_n` int(11) DEFAULT NULL,
  `cbr_displacement_mm` int(11) DEFAULT NULL,
  `grab_force_n` int(11) DEFAULT NULL,
  `grab_elongation_pct` int(11) DEFAULT NULL,
  `sample_collected_from` varchar(150) DEFAULT NULL,
  `sample_production_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_pretesting_readings`
--

CREATE TABLE `fiber_pretesting_readings` (
  `id` int(11) NOT NULL,
  `fiber_pretest_id` int(11) NOT NULL,
  `test_type` enum('GSM','Thickness','Tensile_MD','Tensile_CD','CBR','Grab') NOT NULL,
  `strength_value` decimal(10,2) DEFAULT NULL,
  `elongation_value` decimal(10,2) DEFAULT NULL,
  `force_value` decimal(10,2) DEFAULT NULL,
  `displacement_value` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_testing`
--

CREATE TABLE `fiber_testing` (
  `id` int(11) NOT NULL,
  `report_no` varchar(50) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `tested_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_testing_readings`
--

CREATE TABLE `fiber_testing_readings` (
  `id` int(11) NOT NULL,
  `fiber_test_id` int(11) NOT NULL,
  `test_type` enum('gsm','thickness','tensile','cbr','grab') DEFAULT NULL,
  `reading_no` int(11) DEFAULT NULL,
  `value` decimal(10,3) DEFAULT NULL,
  `direction` enum('MD','CD') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_testing_results`
--

CREATE TABLE `fiber_testing_results` (
  `id` int(11) NOT NULL,
  `fiber_test_id` int(11) NOT NULL,
  `property_name` varchar(100) DEFAULT NULL,
  `value` decimal(10,2) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_test_counters`
--

CREATE TABLE `fiber_test_counters` (
  `date_key` varchar(8) NOT NULL,
  `counter` int(11) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_test_reports`
--

CREATE TABLE `fiber_test_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `sample_name` varchar(255) DEFAULT NULL,
  `lc_no` varchar(100) DEFAULT NULL,
  `sample_received_date` date DEFAULT NULL,
  `manufacturer_name` varchar(100) DEFAULT NULL,
  `store_entry_reference` varchar(100) DEFAULT NULL,
  `sample_id` varchar(100) NOT NULL,
  `sample_tested_date` date NOT NULL,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `comments` text DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiber_to_roll_entry`
--

CREATE TABLE `fiber_to_roll_entry` (
  `id` int(11) NOT NULL,
  `date_time` datetime DEFAULT NULL,
  `operator_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `bale_opener_number` varchar(100) DEFAULT NULL,
  `bale_number` varchar(100) DEFAULT NULL,
  `bale_weight` int(11) DEFAULT NULL,
  `gsm` decimal(10,2) DEFAULT NULL,
  `line_no` varchar(50) DEFAULT NULL,
  `material_type` varchar(100) DEFAULT NULL,
  `origin` varchar(100) DEFAULT NULL,
  `roll_no` int(11) DEFAULT NULL,
  `batch_info` varchar(100) DEFAULT NULL,
  `reference_number` varchar(200) DEFAULT NULL,
  `total_weight` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fineness_fiber_reports`
--

CREATE TABLE `fineness_fiber_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `store_entry_reference` varchar(100) DEFAULT NULL,
  `sample_description` text DEFAULT NULL,
  `lc_no` varchar(100) DEFAULT NULL,
  `sample_received_from` varchar(255) DEFAULT NULL,
  `sample_collected_from` varchar(255) DEFAULT NULL,
  `manufacturer_name` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `received_date` date DEFAULT NULL,
  `test_start_date` date DEFAULT NULL,
  `test_end_date` date DEFAULT NULL,
  `others_information` text DEFAULT NULL,
  `test_temperature` decimal(10,2) DEFAULT 0.00,
  `rh_percent` decimal(5,2) DEFAULT 0.00,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `rejected_by` varchar(100) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `length_calibrations`
--

CREATE TABLE `length_calibrations` (
  `id` int(11) NOT NULL,
  `entry_id` varchar(50) DEFAULT NULL,
  `reference_number` varchar(200) DEFAULT NULL,
  `date_time` datetime NOT NULL,
  `shift` varchar(20) NOT NULL,
  `line_number` varchar(20) NOT NULL,
  `roll_no` varchar(50) NOT NULL,
  `reference_length` decimal(10,2) NOT NULL,
  `set_in_machine` decimal(10,2) NOT NULL,
  `actual_length` decimal(10,2) NOT NULL,
  `difference` decimal(10,2) NOT NULL,
  `calibration_length` decimal(10,2) NOT NULL,
  `inspector` varchar(100) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `attempt_time` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `success` tinyint(1) DEFAULT 0,
  `failure_reason` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `username`, `attempt_time`, `ip_address`, `user_agent`, `success`, `failure_reason`) VALUES
(71, 'admin', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `machines`
--

CREATE TABLE `machines` (
  `id` int(11) NOT NULL,
  `machine_name` varchar(100) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` int(11) NOT NULL,
  `material_name` varchar(100) NOT NULL,
  `price_per_unit` decimal(10,2) DEFAULT 50.00,
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `materials`
--

INSERT INTO `materials` (`id`, `material_name`, `price_per_unit`, `is_deleted`, `who_did`, `deleted_at`) VALUES
(1, 'PP Stable Fiber', 50.00, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `material_consumption`
--

CREATE TABLE `material_consumption` (
  `id` int(11) NOT NULL,
  `consumption_date` datetime NOT NULL,
  `shift` varchar(20) DEFAULT NULL,
  `material_id` int(11) NOT NULL,
  `material_name` varchar(100) DEFAULT NULL,
  `manufacturer_name` varchar(255) DEFAULT NULL,
  `consumption_type` varchar(100) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `operator` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` int(11) NOT NULL,
  `module_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `new_user`
--

CREATE TABLE `new_user` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `role` varchar(50) DEFAULT 'user',
  `designation` varchar(100) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `wrong_attempts` int(11) DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `new_user`
--

INSERT INTO `new_user` (`id`, `username`, `full_name`, `password`, `email`, `first_name`, `last_name`, `role`, `designation`, `employee_id`, `phone`, `is_active`, `wrong_attempts`, `lock_until`, `last_login`, `last_activity`, `created_at`, `updated_at`, `is_deleted`, `who_did`, `deleted_at`, `status`) VALUES
(1, 'planning_test', 'Planning Test User', 'Test@123', 'planning@test.com', 'Planning Test User', '', 'planning_user', NULL, NULL, NULL, 1, 0, NULL, NULL, '2025-12-16 19:38:14', '2025-12-15 11:04:16', '2025-12-16 13:42:38', 0, NULL, NULL, 'active'),
(2, 'prod_test', 'Production Test User', '$2y$10$44NMyG4wwUO1NT4dGq0yDeUQbDjcphY3isEUAC29ZNNbBqztKoQM6', 'prod@test.com', 'Production Test User', '', 'production_user', NULL, NULL, NULL, 1, 0, NULL, '2025-12-16 14:07:36', '2025-12-16 14:07:36', '2025-12-15 11:04:16', '2025-12-16 08:07:36', 0, NULL, NULL, 'active'),
(3, 'qc_test', 'QC Inspector Test', '$2y$10$Ov43whIANHQKUZP6bz/vCuUGSgARjTjf63k/WNrizrcbASuNbiJYa', 'qc@test.com', 'QC Inspector Test', '', 'qc_inspector', NULL, NULL, NULL, 1, 0, NULL, '2025-12-16 15:08:09', '2025-12-16 15:08:09', '2025-12-15 11:04:16', '2025-12-16 09:08:09', 0, NULL, NULL, 'active'),
(4, 'tester_test', 'Lab Tester Test', '$2y$10$xFdiZL.TTY4J8O9vDA2Kx.aRMimQc.fdzYYRwbdTlBQFRFDXmc0Zq', 'tester@test.com', 'Lab Tester Test', '', 'tester', NULL, NULL, NULL, 1, 0, NULL, '2025-12-16 19:27:03', '2025-12-16 19:27:03', '2025-12-15 11:04:16', '2025-12-16 13:27:03', 0, NULL, NULL, 'active'),
(5, 'checker_test', 'Checker Test User', '$2y$10$Yy9MUExve3.iccy1bSPcy.mcF/xGOTbNjsHge9J22kbMsY.8DcMd2', 'checker@test.com', 'Checker Test', '', 'checker', NULL, NULL, NULL, 1, 0, NULL, '2025-12-16 19:35:40', '2025-12-16 19:35:40', '2025-12-15 11:04:16', '2025-12-16 13:35:40', 0, NULL, NULL, 'active'),
(6, 'agm_test', 'AGM Operations Test', '$2y$10$T6K9uI06uxyD3U8Qyh9C1e/1IW7VEHQTmyADLZbFJJxcEafWNbh8e', 'agm@test.com', 'AGM Operations Test', '', 'agm ops', NULL, NULL, NULL, 1, 0, NULL, '2025-12-16 19:35:40', '2025-12-16 19:35:40', '2025-12-15 11:04:16', '2025-12-16 13:35:40', 0, NULL, NULL, 'active'),
(7, 'finance_test', 'Finance Test User', '$2y$10$ukohd0hz7gzyOePlSZUKMuNb0NNoOj9jtxF0hfzle6Awhvm9BRjg6', 'finance@test.com', 'Finance Test User', '', 'finance_user', NULL, NULL, NULL, 1, 0, NULL, '2025-12-16 19:28:48', '2025-12-16 19:28:48', '2025-12-15 11:04:16', '2025-12-16 13:28:48', 0, NULL, NULL, 'active'),
(8, 'mgmt_test', 'Management Test User', '$2y$10$j77FFlF9eXG74juud1RJd.VAk5VYaqmgIFdp/8oPpmFnX5JOSCEu.', 'mgmt@test.com', 'Management Test', '', 'management', NULL, NULL, NULL, 1, 0, NULL, '2025-12-16 19:04:10', '2025-12-16 19:04:10', '2025-12-15 11:04:16', '2025-12-16 13:04:10', 0, NULL, NULL, 'active'),
(10, 'admin', 'admin', '$2y$10$lVEVsnevO02XYMNYKMtF4eiUosdlhrEs2YbWUBD5dSrCc3Nc.0p..', 'admin@geotex.local', 'System Admin', '', 'admin', NULL, '45690', '01890473834', 1, 0, NULL, '2025-12-17 08:55:01', '2025-12-17 08:55:01', '2025-12-15 12:46:09', '2025-12-17 02:55:01', 0, NULL, NULL, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `production`
--

CREATE TABLE `production` (
  `id` int(11) NOT NULL,
  `roll_id` int(11) NOT NULL,
  `cnc_data` text DEFAULT NULL,
  `prod_line` varchar(50) DEFAULT NULL,
  `branding` varchar(50) DEFAULT NULL,
  `output_qty` decimal(10,2) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_cost_settings`
--

CREATE TABLE `production_cost_settings` (
  `id` int(11) NOT NULL,
  `cost_type` varchar(50) NOT NULL,
  `cost_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_entry`
--

CREATE TABLE `production_entry` (
  `id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `gsm` decimal(10,2) NOT NULL,
  `line_no` varchar(50) NOT NULL,
  `fiber_type` varchar(50) NOT NULL,
  `roll_id` int(11) NOT NULL,
  `total_weight` decimal(10,2) NOT NULL,
  `date_time` datetime NOT NULL DEFAULT current_timestamp(),
  `shift` enum('Day','Night') NOT NULL,
  `batch_number` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_targets`
--

CREATE TABLE `production_targets` (
  `target_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `target_qty` int(11) NOT NULL,
  `target_period` varchar(50) NOT NULL,
  `production_qty` int(11) NOT NULL,
  `target_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `project_name` varchar(100) NOT NULL,
  `belt_weight` decimal(10,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive','completed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `project_name`, `belt_weight`, `description`, `status`, `created_at`, `updated_at`, `is_deleted`, `who_did`, `deleted_at`) VALUES
(1, 'OPERATION/CIL/GEO/2024-25/FEB-JUN/03', 0.00, NULL, 'active', '2025-12-16 05:36:26', '2025-12-16 05:36:26', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `qc`
--

CREATE TABLE `qc` (
  `id` int(11) NOT NULL,
  `stage` varchar(50) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qc_type` varchar(50) DEFAULT NULL,
  `result` enum('pass','fail') DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `reporter_id` int(11) DEFAULT NULL,
  `roll_entry_id` int(11) DEFAULT NULL,
  `project_roll` int(11) DEFAULT NULL,
  `bag_size_roll` varchar(100) DEFAULT NULL,
  `rec_wt_roll` int(11) DEFAULT NULL,
  `act_wt_roll` int(11) DEFAULT NULL,
  `project_cnc` int(11) DEFAULT NULL,
  `bag_size_cnc` varchar(100) DEFAULT NULL,
  `rec_wt_cnc` int(11) DEFAULT NULL,
  `act_wt_cnc` int(11) DEFAULT NULL,
  `project_prod` int(11) DEFAULT NULL,
  `gsm_prod` int(11) DEFAULT NULL,
  `line_no_prod` int(11) DEFAULT NULL,
  `fiber_type_prod` varchar(100) DEFAULT NULL,
  `roll_number_prod` varchar(100) DEFAULT NULL,
  `total_wt_prod` int(11) DEFAULT NULL,
  `batch_number_prod` varchar(100) DEFAULT NULL,
  `project_fg` int(11) DEFAULT NULL,
  `bag_size_fg` varchar(100) DEFAULT NULL,
  `rec_wt_fg` int(11) DEFAULT NULL,
  `act_wt_fg` int(11) DEFAULT NULL,
  `quality_checked_fg` varchar(100) DEFAULT NULL,
  `passed_qty_fg` int(11) DEFAULT NULL,
  `rejected_qty_fg` int(11) DEFAULT NULL,
  `packaging_type_fg` varchar(100) DEFAULT NULL,
  `qc_id` varchar(50) DEFAULT NULL,
  `date_time` datetime DEFAULT NULL,
  `shift` varchar(20) DEFAULT NULL,
  `qc_stage` varchar(50) DEFAULT NULL,
  `qc_result` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_checks`
--

CREATE TABLE `qc_checks` (
  `id` int(11) NOT NULL,
  `qc_id` int(11) NOT NULL,
  `parameter` varchar(100) NOT NULL,
  `target_value` varchar(50) DEFAULT NULL,
  `measured_value` varchar(50) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `pass_fail` enum('pass','fail') NOT NULL,
  `defect_code` varchar(50) DEFAULT NULL,
  `severity` enum('minor','major','critical') DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_entries`
--

CREATE TABLE `qc_entries` (
  `id` int(11) NOT NULL,
  `qc_id` varchar(50) DEFAULT NULL,
  `date_time` datetime DEFAULT NULL,
  `shift` varchar(20) DEFAULT NULL,
  `qc_stage` varchar(50) DEFAULT NULL,
  `qc_type` varchar(100) DEFAULT NULL,
  `qc_result` varchar(20) DEFAULT NULL,
  `inspector_name` varchar(255) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reporter_id` int(11) DEFAULT NULL,
  `roll_entry_id` int(11) DEFAULT NULL,
  `project_roll` int(11) DEFAULT NULL,
  `bag_size_roll` varchar(100) DEFAULT NULL,
  `rec_wt_roll` int(11) DEFAULT NULL,
  `act_wt_roll` int(11) DEFAULT NULL,
  `project_cnc` int(11) DEFAULT NULL,
  `bag_size_cnc` varchar(100) DEFAULT NULL,
  `rec_wt_cnc` int(11) DEFAULT NULL,
  `act_wt_cnc` int(11) DEFAULT NULL,
  `project_prod` int(11) DEFAULT NULL,
  `gsm_prod` int(11) DEFAULT NULL,
  `line_no_prod` int(11) DEFAULT NULL,
  `fiber_type_prod` varchar(100) DEFAULT NULL,
  `roll_number_prod` varchar(100) DEFAULT NULL,
  `total_wt_prod` int(11) DEFAULT NULL,
  `batch_number_prod` varchar(100) DEFAULT NULL,
  `project_fg` int(11) DEFAULT NULL,
  `bag_size_fg` varchar(100) DEFAULT NULL,
  `rec_wt_fg` int(11) DEFAULT NULL,
  `act_wt_fg` int(11) DEFAULT NULL,
  `quality_checked_fg` varchar(100) DEFAULT NULL,
  `passed_qty_fg` int(11) DEFAULT NULL,
  `rejected_qty_fg` int(11) DEFAULT NULL,
  `packaging_type_fg` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fg_reference_number` varchar(100) DEFAULT NULL,
  `fg_amount` decimal(10,2) DEFAULT NULL,
  `bag_no_fg` varchar(100) DEFAULT NULL,
  `weight_fg` decimal(10,2) DEFAULT NULL,
  `stitch_fg` varchar(100) DEFAULT NULL,
  `actual_length_fg` decimal(10,2) DEFAULT NULL,
  `width_fg` decimal(10,2) DEFAULT NULL,
  `margin_left_fg` decimal(10,2) DEFAULT NULL,
  `margin_right_fg` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_test_orders`
--

CREATE TABLE `qc_test_orders` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `checked_by` varchar(100) DEFAULT NULL,
  `sample_reference_id` varchar(100) NOT NULL,
  `inspector_name` varchar(255) DEFAULT NULL,
  `test_standard_id` int(11) NOT NULL,
  `chosen_method` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `test_data` text DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `checker_remarks` text DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `roll_destination` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `qc_test_orders`
--

INSERT INTO `qc_test_orders` (`id`, `report_number`, `status`, `checked_by`, `sample_reference_id`, `inspector_name`, `test_standard_id`, `chosen_method`, `created_at`, `updated_at`, `test_data`, `inspector_id`, `checked_at`, `checker_remarks`, `approved_by`, `approved_at`, `admin_remarks`, `roll_destination`) VALUES
(1, 'RPT-20251216-001', 'approved', 'Checker Test', '4.0L125DEC16-R02-GT0.9.H0.1', 'Lab Tester Test', 1, 'ASTM D5199', '2025-12-16 06:52:31', '2025-12-16 07:27:28', '{\"sample_details\":\"Sample from line-1\",\"batch_information\":\"GT9.H1\",\"sample_collected_from\":\"prod-line1\",\"sample_received_datetime\":\"2025-12-16T12:42\",\"sample_production_date\":\"2025-12-16\",\"temperature\":\"34\",\"rh_percentage\":\"43\",\"test_period_from\":\"2025-12-16\",\"test_period_to\":\"2025-12-16\",\"customer_reference\":\"GEOCIL\",\"sample_received_from\":\"GT New Material Technology\",\"is_external_product\":false,\"product_reference\":\"4.0L125DEC16-R02-GT0.9.H0.1\",\"positions\":[{\"position\":\"Left-1\",\"value\":338,\"thickness\":338},{\"position\":\"Middle Left-1\",\"value\":354,\"thickness\":354},{\"position\":\"Middle Right-1\",\"value\":434,\"thickness\":434},{\"position\":\"Right-1\",\"value\":534,\"thickness\":534}],\"average\":415,\"sd\":89.76264999059093,\"cv\":21.629554214600226,\"max\":534,\"min\":338}', 5, NULL, NULL, 'AGM Operations Test', '2025-12-16 13:01:41', 'Routed to Bag Production.', 'bag_production'),
(2, 'RPT-20251216-002', 'approved', 'Checker Test', '4.0L125DEC16-R02-GT0.9.H0.1', 'Lab Tester Test', 2, 'ASTM D5261', '2025-12-16 06:54:15', '2025-12-16 07:27:28', '{\"sample_details\":\"Sample from line-1\",\"batch_information\":\"GT9.H1\",\"sample_collected_from\":\"prod-line1\",\"sample_received_datetime\":\"2025-12-16T12:42\",\"sample_production_date\":\"2025-12-16\",\"temperature\":\"34\",\"rh_percentage\":\"43\",\"test_period_from\":\"2025-12-16\",\"test_period_to\":\"2025-12-16\",\"customer_reference\":\"GEOCIL\",\"sample_received_from\":\"GT New Material Technology\",\"is_external_product\":false,\"product_reference\":\"4.0L125DEC16-R02-GT0.9.H0.1\",\"positions\":[{\"position\":\"Left-1\",\"weight\":434,\"gsm\":343},{\"position\":\"Middle Left-1\",\"weight\":343,\"gsm\":434},{\"position\":\"Middle Right-1\",\"weight\":434,\"gsm\":434},{\"position\":\"Right-1\",\"weight\":343,\"gsm\":434},{\"position\":\"Left-2\",\"weight\":343,\"gsm\":342.99},{\"position\":\"Middle Left-2\",\"weight\":343,\"gsm\":34},{\"position\":\"Right-3\",\"weight\":343,\"gsm\":434.02}],\"average\":350.85857142857145,\"sd\":146.15992410467965,\"cv\":41.657789208218105,\"max\":434.02,\"min\":34}', 5, NULL, NULL, 'AGM Operations Test', '2025-12-16 13:05:16', 'Routed to Bag Production.', 'bag_production'),
(3, 'RPT-20251216-003', 'approved', 'Checker Test', '4.0L125DEC16-R02-GT0.9.H0.1', 'Lab Tester Test', 3, 'ASTM D4632', '2025-12-16 07:10:31', '2025-12-16 07:27:28', '{\"sample_details\":\"Sample from line-1\",\"batch_information\":\"GT9.H1\",\"sample_collected_from\":\"prod-line1\",\"sample_received_datetime\":\"2025-12-16T12:42\",\"sample_production_date\":\"2025-12-16\",\"temperature\":\"34\",\"rh_percentage\":\"43\",\"test_period_from\":\"2025-12-16\",\"test_period_to\":\"2025-12-16\",\"customer_reference\":\"GEOCIL\",\"sample_received_from\":\"GT New Material Technology\",\"is_external_product\":false,\"product_reference\":\"4.0L125DEC16-R02-GT0.9.H0.1\",\"grab_data\":[{\"position\":\"Left-1\",\"direction\":\"MD\",\"breaking_force\":434,\"elongation\":343},{\"position\":\"Middle Left-1\",\"direction\":\"MD\",\"breaking_force\":434,\"elongation\":434},{\"position\":\"Middle Right-1\",\"direction\":\"MD\",\"breaking_force\":434,\"elongation\":434},{\"position\":\"Right-1\",\"direction\":\"MD\",\"breaking_force\":434,\"elongation\":4343}],\"summary\":{\"md\":{\"force_avg\":434,\"force_sd\":0,\"force_cv\":0,\"force_max\":434,\"force_min\":434,\"elongation_avg\":1388.5,\"elongation_sd\":1970.133751804684,\"elongation_cv\":141.8893591504994,\"elongation_max\":4343,\"elongation_min\":343},\"cd\":{\"force_avg\":0,\"force_sd\":0,\"force_cv\":0,\"force_max\":0,\"force_min\":0,\"elongation_avg\":0,\"elongation_sd\":0,\"elongation_cv\":0,\"elongation_max\":0,\"elongation_min\":0}}}', 5, NULL, NULL, 'AGM Operations Test', '2025-12-16 13:27:23', 'Routed to Bag Production.', 'bag_production'),
(4, 'RPT-20251216-004', 'approved', 'Checker Test', 'TOKEN-1', 'Lab Tester Test', 1, 'ASTM D5199', '2025-12-16 13:08:11', '2025-12-16 13:11:55', '{\"sample_details\":\"Sample from line-1\",\"sample_collected_from\":\"prod-line1\",\"sample_received_datetime\":\"2025-12-16T19:07\",\"sample_production_date\":\"2025-12-16\",\"temperature\":\"34\",\"rh_percentage\":\"55\",\"test_period_from\":\"2025-12-16\",\"test_period_to\":\"2025-12-16\",\"customer_reference\":\"GEOCIL\",\"sample_received_from\":\"GT New Material Technology\",\"is_external_product\":true,\"external_reference\":\"TOKEN-1\",\"positions\":[{\"position\":\"Left-1\",\"value\":43,\"thickness\":43},{\"position\":\"Middle Left-1\",\"value\":545,\"thickness\":545},{\"position\":\"Middle Right-1\",\"value\":535,\"thickness\":535},{\"position\":\"Right-1\",\"value\":534.9998,\"thickness\":534.9998}],\"average\":414.49995,\"sd\":247.71149334123223,\"cv\":59.76152550590952,\"max\":545,\"min\":43}', 5, NULL, NULL, 'AGM Operations Test', '2025-12-16 19:11:55', NULL, NULL),
(5, 'RPT-20251216-005', 'approved', 'Checker Test', 'TOKEN-1', 'Lab Tester Test', 2, 'ASTM D5261', '2025-12-16 13:08:11', '2025-12-16 13:12:07', '{\"sample_details\":\"Sample from line-1\",\"sample_collected_from\":\"prod-line1\",\"sample_received_datetime\":\"2025-12-16T19:07\",\"sample_production_date\":\"2025-12-16\",\"temperature\":\"34\",\"rh_percentage\":\"55\",\"test_period_from\":\"2025-12-16\",\"test_period_to\":\"2025-12-16\",\"customer_reference\":\"GEOCIL\",\"sample_received_from\":\"GT New Material Technology\",\"is_external_product\":true,\"external_reference\":\"TOKEN-1\",\"positions\":[{\"position\":\"Left-1\",\"weight\":535,\"gsm\":353},{\"position\":\"Middle Left-1\",\"weight\":554,\"gsm\":54},{\"position\":\"Middle Right-1\",\"weight\":545,\"gsm\":566},{\"position\":\"Right-1\",\"weight\":566,\"gsm\":64.99}],\"average\":259.4975,\"sd\":246.81227014001283,\"cv\":95.11161769959743,\"max\":566,\"min\":54}', 5, NULL, NULL, 'agm_test', '2025-12-16 19:12:07', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `qc_test_readings`
--

CREATE TABLE `qc_test_readings` (
  `reading_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `test_standard_id` int(11) DEFAULT NULL,
  `parameter_name` varchar(100) DEFAULT NULL,
  `value` decimal(10,4) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `position_sample_no` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recycle`
--

CREATE TABLE `recycle` (
  `id` int(11) NOT NULL,
  `scrap_id` int(11) NOT NULL,
  `recycled_qty` decimal(10,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_tests`
--

CREATE TABLE `report_tests` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `test_standard_id` int(11) NOT NULL,
  `test_order` smallint(6) DEFAULT NULL,
  `test_direction` enum('MD','CD','Both') DEFAULT NULL,
  `test_unit` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `permissions` text DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roll_entry`
--

CREATE TABLE `roll_entry` (
  `id` int(11) NOT NULL,
  `date_time` datetime NOT NULL,
  `operator_id` int(11) NOT NULL,
  `reference_number` varchar(200) DEFAULT NULL,
  `project_id` int(11) NOT NULL,
  `material_type` varchar(100) DEFAULT NULL,
  `roll_size` varchar(50) DEFAULT NULL,
  `gsm` int(11) NOT NULL,
  `line_no` varchar(50) NOT NULL,
  `fiber_type` varchar(100) NOT NULL,
  `roll_number` int(11) NOT NULL,
  `total_weight` decimal(10,2) NOT NULL,
  `total_area` decimal(10,2) DEFAULT NULL,
  `actual_gsm` decimal(10,2) DEFAULT NULL,
  `batch_number` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `entry_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roll_production`
--

CREATE TABLE `roll_production` (
  `id` int(11) NOT NULL,
  `date` datetime NOT NULL DEFAULT curtime(),
  `palk_id` varchar(50) NOT NULL,
  `qty` decimal(10,2) NOT NULL,
  `next_stage` varchar(50) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roll_qc_reports`
--

CREATE TABLE `roll_qc_reports` (
  `id` int(11) NOT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `roll_no` varchar(50) DEFAULT NULL,
  `line_number` varchar(50) NOT NULL,
  `line_no` varchar(50) DEFAULT NULL,
  `product_amount` decimal(12,2) DEFAULT 0.00,
  `gsm_check_status` varchar(20) NOT NULL,
  `length_calibration_status` varchar(20) NOT NULL,
  `overall_status` varchar(20) NOT NULL,
  `inspector` varchar(100) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roll_received`
--

CREATE TABLE `roll_received` (
  `id` int(11) NOT NULL,
  `reporting_time` datetime NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `receiver_name` varchar(100) NOT NULL,
  `project_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roll_transfer`
--

CREATE TABLE `roll_transfer` (
  `id` int(10) UNSIGNED NOT NULL,
  `transfer_id` int(10) UNSIGNED NOT NULL,
  `date_time` datetime NOT NULL,
  `operator_id` int(10) UNSIGNED NOT NULL,
  `reference_number` varchar(200) DEFAULT NULL,
  `operator_name` varchar(100) NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `driver_id` int(10) UNSIGNED DEFAULT NULL,
  `driver_name` varchar(120) DEFAULT NULL,
  `amount_kg` decimal(10,2) NOT NULL DEFAULT 0.00,
  `from_location` varchar(150) NOT NULL,
  `to_location` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sender_name` varchar(100) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scrap`
--

CREATE TABLE `scrap` (
  `id` int(11) NOT NULL,
  `prod_id` int(11) DEFAULT NULL,
  `scrap_type` varchar(50) DEFAULT NULL,
  `qty` decimal(10,2) NOT NULL,
  `remarks` text DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `scrap_id` varchar(50) DEFAULT NULL,
  `date_time` datetime DEFAULT NULL,
  `scrap_product` varchar(100) DEFAULT NULL,
  `scrap_category` varchar(100) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `cutting_batch` varchar(100) DEFAULT NULL,
  `shift` varchar(20) DEFAULT NULL,
  `reporter_id` int(11) DEFAULT NULL,
  `reporter_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scrap_recycle`
--

CREATE TABLE `scrap_recycle` (
  `id` int(11) NOT NULL,
  `recycle_id` varchar(20) DEFAULT NULL,
  `scrap_id` int(11) NOT NULL,
  `scrap_type` varchar(20) DEFAULT 'scrap',
  `recycled_qty` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `recycled_at` datetime NOT NULL,
  `remarks` text DEFAULT NULL,
  `machine_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scrap_type_costs`
--

CREATE TABLE `scrap_type_costs` (
  `id` int(11) NOT NULL,
  `scrap_type` varchar(100) NOT NULL,
  `scrap_product` varchar(100) DEFAULT NULL,
  `cost_per_kg` decimal(10,2) NOT NULL DEFAULT 50.00,
  `salvage_percentage` decimal(5,2) NOT NULL DEFAULT 30.00,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_thread_approval_comments`
--

CREATE TABLE `sewing_thread_approval_comments` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `action` varchar(20) NOT NULL,
  `comments` text DEFAULT NULL,
  `approver_id` int(11) NOT NULL,
  `approver_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_thread_backups`
--

CREATE TABLE `sewing_thread_backups` (
  `id` int(11) NOT NULL,
  `backup_name` varchar(255) NOT NULL,
  `backup_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_size` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_thread_counters`
--

CREATE TABLE `sewing_thread_counters` (
  `id` int(11) NOT NULL,
  `day_key` varchar(8) DEFAULT NULL,
  `counter` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_thread_logs`
--

CREATE TABLE `sewing_thread_logs` (
  `id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `report_number` varchar(100) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_thread_reports`
--

CREATE TABLE `sewing_thread_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `store_entry_reference` varchar(100) DEFAULT NULL,
  `sample_description` varchar(255) NOT NULL,
  `sample_received_from` varchar(255) NOT NULL,
  `sample_collected_from` varchar(255) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `received_date` datetime NOT NULL,
  `test_start_date` date NOT NULL,
  `test_end_date` date NOT NULL,
  `others_information` text DEFAULT NULL,
  `test_temperature` decimal(10,2) NOT NULL,
  `rh_percent` decimal(5,2) NOT NULL,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_thread_templates`
--

CREATE TABLE `sewing_thread_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `template_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_by` varchar(255) NOT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_thread_user_preferences`
--

CREATE TABLE `sewing_thread_user_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `preference_key` varchar(100) NOT NULL,
  `preference_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `side_cut_scrap`
--

CREATE TABLE `side_cut_scrap` (
  `id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `shift` enum('Day','Night') NOT NULL,
  `category` enum('Sheet Production','Swing Production') NOT NULL,
  `cutting_batch_no` varchar(50) DEFAULT NULL,
  `quantity_kg` decimal(10,2) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(100) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `entry_id` varchar(50) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `slow_query_log`
--

CREATE TABLE `slow_query_log` (
  `id` int(11) NOT NULL,
  `query_hash` varchar(64) DEFAULT NULL,
  `query_text` text DEFAULT NULL,
  `execution_time` decimal(10,3) DEFAULT NULL,
  `rows_examined` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `executed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `store_received_counters`
--

CREATE TABLE `store_received_counters` (
  `date_key` varchar(20) NOT NULL,
  `counter` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `store_received_entries`
--

CREATE TABLE `store_received_entries` (
  `id` int(11) NOT NULL,
  `entry_number` varchar(100) NOT NULL,
  `date_time` datetime NOT NULL,
  `shift` varchar(50) NOT NULL,
  `manufacturer_name` varchar(255) NOT NULL,
  `material_type` varchar(255) NOT NULL,
  `amount_kg` decimal(10,2) NOT NULL,
  `original_amount_kg` decimal(10,2) NOT NULL,
  `reported_by` varchar(255) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `amount` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sun_tests`
--

CREATE TABLE `sun_tests` (
  `id` int(11) NOT NULL,
  `report_no` varchar(50) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `sample_description` text DEFAULT NULL,
  `test_start_date` date DEFAULT NULL,
  `test_end_date` date DEFAULT NULL,
  `temperature` decimal(5,2) DEFAULT NULL,
  `humidity` decimal(5,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sun_test_counters`
--

CREATE TABLE `sun_test_counters` (
  `id` int(11) NOT NULL,
  `day_key` varchar(8) NOT NULL,
  `counter` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sun_test_logs`
--

CREATE TABLE `sun_test_logs` (
  `id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sun_test_readings`
--

CREATE TABLE `sun_test_readings` (
  `id` int(11) NOT NULL,
  `sun_test_id` int(11) NOT NULL,
  `specimen_no` int(11) DEFAULT NULL,
  `direction` enum('MD','CD') DEFAULT NULL,
  `force_before` decimal(10,2) DEFAULT NULL,
  `force_after` decimal(10,2) DEFAULT NULL,
  `elongation_before` decimal(10,2) DEFAULT NULL,
  `elongation_after` decimal(10,2) DEFAULT NULL,
  `retain_percent` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sun_test_reports`
--

CREATE TABLE `sun_test_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `reference_number` varchar(200) DEFAULT NULL,
  `bundle_reference` varchar(200) DEFAULT NULL,
  `reference_name` varchar(255) DEFAULT NULL,
  `lab_test_number` varchar(50) NOT NULL,
  `sample_collected_from` varchar(255) NOT NULL,
  `sample_production_date` date NOT NULL,
  `reference` varchar(255) NOT NULL,
  `sample_description` text NOT NULL,
  `sample_received_from` varchar(255) NOT NULL,
  `recipe` varchar(255) NOT NULL,
  `received_date` datetime NOT NULL,
  `test_start_date` date NOT NULL,
  `test_end_date` date NOT NULL,
  `testing_method` varchar(255) NOT NULL,
  `test_name` varchar(255) NOT NULL,
  `test_speed` varchar(255) NOT NULL,
  `gauge_length` varchar(255) NOT NULL,
  `specimen_size` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `temperature` decimal(10,2) NOT NULL,
  `rh_percent` decimal(5,2) NOT NULL,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `tested_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `swing_machine_entry`
--

CREATE TABLE `swing_machine_entry` (
  `id` int(11) NOT NULL,
  `swing_id` varchar(50) NOT NULL,
  `date_time` datetime NOT NULL,
  `shift` varchar(10) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `helper_id` int(11) NOT NULL,
  `line_no` varchar(50) NOT NULL,
  `sewing_qty` int(11) NOT NULL,
  `ncp_piece` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cnc_cutting_batch` varchar(150) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_health_checks`
--

CREATE TABLE `system_health_checks` (
  `id` int(11) NOT NULL,
  `check_type` varchar(50) NOT NULL,
  `status` enum('ok','warning','error') NOT NULL,
  `message` text DEFAULT NULL,
  `checked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_performance`
--

CREATE TABLE `system_performance` (
  `id` int(11) NOT NULL,
  `metric_name` varchar(100) NOT NULL,
  `metric_value` decimal(10,2) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenacity_fiber_reports`
--

CREATE TABLE `tenacity_fiber_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `store_entry_reference` varchar(100) DEFAULT NULL,
  `sample_description` text NOT NULL,
  `lc_no` varchar(100) DEFAULT NULL,
  `sample_received_from` varchar(255) NOT NULL,
  `sample_collected_from` varchar(255) NOT NULL,
  `manufacturer_name` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `received_date` datetime NOT NULL,
  `test_start_date` date NOT NULL,
  `test_end_date` date NOT NULL,
  `others_information` text DEFAULT NULL,
  `test_temperature` decimal(10,2) NOT NULL,
  `rh_percent` decimal(5,2) NOT NULL,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenacity_yarn_reports`
--

CREATE TABLE `tenacity_yarn_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `store_entry_reference` varchar(100) DEFAULT NULL,
  `sample_description` text NOT NULL,
  `sample_received_from` varchar(255) NOT NULL,
  `sample_collected_from` varchar(255) NOT NULL,
  `manufacturer_name` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `received_date` datetime NOT NULL,
  `test_start_date` date NOT NULL,
  `test_end_date` date NOT NULL,
  `others_information` text DEFAULT NULL,
  `test_temperature` decimal(10,2) NOT NULL,
  `rh_percent` decimal(5,2) NOT NULL,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenacity_yarn_report_logs`
--

CREATE TABLE `tenacity_yarn_report_logs` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `test_readings`
--

CREATE TABLE `test_readings` (
  `id` int(11) NOT NULL,
  `report_test_id` int(11) NOT NULL,
  `specimen_no` smallint(6) NOT NULL,
  `parameter` varchar(255) NOT NULL,
  `value` decimal(12,6) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `measured_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `test_reports`
--

CREATE TABLE `test_reports` (
  `id` int(11) NOT NULL,
  `report_type` enum('fiber_preproduction','fabric_preproduction','fiber_test','geotextile_summary','uv_test','sun_test','yarn_test','water_perm','characteristics') NOT NULL,
  `report_no` varchar(100) DEFAULT NULL,
  `sample_id` varchar(100) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `received_date` datetime DEFAULT NULL,
  `tested_date` datetime DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `test_standards`
--

CREATE TABLE `test_standards` (
  `id` int(11) NOT NULL,
  `product` varchar(100) NOT NULL,
  `test_name` varchar(255) NOT NULL,
  `standard_code` varchar(50) NOT NULL,
  `detection_min` decimal(10,3) DEFAULT NULL,
  `detection_max` decimal(10,3) DEFAULT NULL,
  `detection_unit` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `iso_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `test_standards`
--

INSERT INTO `test_standards` (`id`, `product`, `test_name`, `standard_code`, `detection_min`, `detection_max`, `detection_unit`, `created_at`, `iso_code`, `description`) VALUES
(1, '', 'Thickness (Under 2kPa Pressure)', 'ASTM D5199', NULL, NULL, NULL, '2025-12-16 06:52:31', NULL, NULL),
(2, '', 'Mass Per Unit Area (GSM)', 'ASTM D5261', NULL, NULL, NULL, '2025-12-16 06:54:15', NULL, NULL),
(3, '', 'Grab Tensile Test', 'ASTM D4632', NULL, NULL, NULL, '2025-12-16 07:10:31', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `test_summary`
--

CREATE TABLE `test_summary` (
  `id` int(11) NOT NULL,
  `report_test_id` int(11) NOT NULL,
  `parameter` varchar(255) NOT NULL,
  `avg_val` decimal(12,6) DEFAULT NULL,
  `min_val` decimal(12,6) DEFAULT NULL,
  `max_val` decimal(12,6) DEFAULT NULL,
  `sd_val` decimal(12,6) DEFAULT NULL,
  `cv_val` decimal(12,6) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `thickness_test_counters`
--

CREATE TABLE `thickness_test_counters` (
  `id` int(11) NOT NULL,
  `date_key` varchar(10) NOT NULL,
  `counter` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `thickness_test_reports`
--

CREATE TABLE `thickness_test_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(50) NOT NULL,
  `sample_description` varchar(255) DEFAULT NULL,
  `sample_received_from` varchar(255) DEFAULT NULL,
  `test_date` date DEFAULT NULL,
  `tested_by` varchar(100) DEFAULT NULL,
  `lab_test_number` varchar(20) DEFAULT NULL,
  `testing_method` varchar(255) DEFAULT NULL,
  `test_standard` varchar(100) DEFAULT NULL,
  `specimen_1` decimal(10,2) DEFAULT NULL,
  `specimen_2` decimal(10,2) DEFAULT NULL,
  `specimen_3` decimal(10,2) DEFAULT NULL,
  `specimen_4` decimal(10,2) DEFAULT NULL,
  `specimen_5` decimal(10,2) DEFAULT NULL,
  `specimen_6` decimal(10,2) DEFAULT NULL,
  `specimen_7` decimal(10,2) DEFAULT NULL,
  `specimen_8` decimal(10,2) DEFAULT NULL,
  `specimen_9` decimal(10,2) DEFAULT NULL,
  `specimen_10` decimal(10,2) DEFAULT NULL,
  `average` decimal(10,2) DEFAULT NULL,
  `sd` decimal(10,3) DEFAULT NULL,
  `cv_percent` decimal(10,2) DEFAULT NULL,
  `test_results` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','locked') NOT NULL DEFAULT 'active',
  `is_deleted` tinyint(1) DEFAULT 0,
  `who_did` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `full_name`, `password`, `role`, `designation`, `employee_id`, `email`, `phone_number`, `status`, `is_deleted`, `who_did`, `deleted_at`) VALUES
(1, 'planning_test', 'Planning Test User', '$2y$10$qgcE4yHk8suSYWiQHVAJneSf5RL1Q3lCz/AaBn/YZVCg6lWkRpev2', 'planning_user', NULL, NULL, 'planning@test.com', NULL, 'active', 0, NULL, NULL),
(3, 'prod_test', 'Production Test User', '$2y$10$44NMyG4wwUO1NT4dGq0yDeUQbDjcphY3isEUAC29ZNNbBqztKoQM6', 'production_user', NULL, NULL, 'prod@test.com', NULL, 'active', 0, NULL, NULL),
(4, 'qc_test', 'QC Inspector Test', '$2y$10$Ov43whIANHQKUZP6bz/vCuUGSgARjTjf63k/WNrizrcbASuNbiJYa', 'qc_inspector', NULL, NULL, 'qc@test.com', NULL, 'active', 0, NULL, NULL),
(5, 'tester_test', 'Lab Tester Test', '$2y$10$xFdiZL.TTY4J8O9vDA2Kx.aRMimQc.fdzYYRwbdTlBQFRFDXmc0Zq', 'tester', NULL, NULL, 'tester@test.com', NULL, 'active', 0, NULL, NULL),
(6, 'checker_test', 'Checker Test', '$2y$10$Yy9MUExve3.iccy1bSPcy.mcF/xGOTbNjsHge9J22kbMsY.8DcMd2', 'checker', NULL, NULL, 'checker@test.com', NULL, 'active', 0, NULL, NULL),
(7, 'agm_test', 'AGM Operations Test', '$2y$10$T6K9uI06uxyD3U8Qyh9C1e/1IW7VEHQTmyADLZbFJJxcEafWNbh8e', 'agm ops', NULL, NULL, 'agm@test.com', NULL, 'active', 0, NULL, NULL),
(8, 'finance_test', 'Finance Test User', '$2y$10$ukohd0hz7gzyOePlSZUKMuNb0NNoOj9jtxF0hfzle6Awhvm9BRjg6', 'finance_user', NULL, NULL, 'finance@test.com', NULL, 'active', 0, NULL, NULL),
(9, 'mgmt_test', 'Management Test', '$2y$10$j77FFlF9eXG74juud1RJd.VAk5VYaqmgIFdp/8oPpmFnX5JOSCEu.', 'management', NULL, NULL, 'mgmt@test.com', NULL, 'active', 0, NULL, NULL),
(10, 'admin', 'System Admin', '$2y$10$lVEVsnevO02XYMNYKMtF4eiUosdlhrEs2YbWUBD5dSrCc3Nc.0p..', 'admin', NULL, NULL, 'admin@geotex.local', NULL, 'active', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_qc_preferences`
--

CREATE TABLE `user_qc_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `field_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `uv_tests`
--

CREATE TABLE `uv_tests` (
  `id` int(11) NOT NULL,
  `report_no` varchar(50) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `sample_description` text DEFAULT NULL,
  `test_start_date` date DEFAULT NULL,
  `test_end_date` date DEFAULT NULL,
  `temperature` decimal(5,2) DEFAULT NULL,
  `humidity` decimal(5,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `uv_test_readings`
--

CREATE TABLE `uv_test_readings` (
  `id` int(11) NOT NULL,
  `uv_test_id` int(11) NOT NULL,
  `specimen_no` int(11) DEFAULT NULL,
  `direction` enum('MD','CD') DEFAULT NULL,
  `force_before` decimal(10,2) DEFAULT NULL,
  `force_after` decimal(10,2) DEFAULT NULL,
  `elongation_before` decimal(10,2) DEFAULT NULL,
  `elongation_after` decimal(10,2) DEFAULT NULL,
  `retain_percent` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_permeability_readings`
--

CREATE TABLE `water_permeability_readings` (
  `id` int(11) NOT NULL,
  `water_test_id` int(11) NOT NULL,
  `specimen_no` int(11) DEFAULT NULL,
  `head_diff` decimal(10,3) DEFAULT NULL,
  `time_sec` decimal(10,3) DEFAULT NULL,
  `velocity` decimal(10,4) DEFAULT NULL,
  `permeability` decimal(12,6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_permeability_tests`
--

CREATE TABLE `water_permeability_tests` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `sample_id` varchar(100) DEFAULT NULL,
  `lab_test_number` varchar(50) NOT NULL,
  `gsm` int(11) NOT NULL,
  `roll_number` varchar(100) NOT NULL,
  `test_date` date NOT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `test_performed_by` varchar(255) NOT NULL,
  `checked_by` varchar(255) DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','checked','approved','rejected','resubmitted') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reference_number` varchar(100) DEFAULT NULL,
  `bundle_reference` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `weathering_exposure_counters`
--

CREATE TABLE `weathering_exposure_counters` (
  `id` int(11) NOT NULL,
  `day_key` varchar(8) DEFAULT NULL,
  `counter` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `weathering_exposure_logs`
--

CREATE TABLE `weathering_exposure_logs` (
  `id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `weathering_exposure_reports`
--

CREATE TABLE `weathering_exposure_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(100) NOT NULL,
  `sample_received_from` varchar(255) NOT NULL,
  `sample_collected_from` varchar(255) NOT NULL,
  `reference` varchar(255) NOT NULL,
  `bundle_reference` varchar(255) DEFAULT NULL,
  `sample_description` text NOT NULL,
  `recipe` varchar(255) NOT NULL,
  `received_date` datetime NOT NULL,
  `test_start_date` date NOT NULL,
  `test_end_date` date NOT NULL,
  `testing_method` varchar(255) NOT NULL,
  `test_name` varchar(255) NOT NULL,
  `test_speed` varchar(255) NOT NULL,
  `gauge_length` varchar(255) NOT NULL,
  `specimen_size` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `temperature` decimal(10,2) NOT NULL,
  `rh_percent` decimal(5,2) NOT NULL,
  `test_performed_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `tested_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `test_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `yarn_tests`
--

CREATE TABLE `yarn_tests` (
  `id` int(11) NOT NULL,
  `report_no` varchar(50) DEFAULT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `sample_description` text DEFAULT NULL,
  `test_start_date` date DEFAULT NULL,
  `test_end_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `yarn_test_readings`
--

CREATE TABLE `yarn_test_readings` (
  `id` int(11) NOT NULL,
  `yarn_test_id` int(11) NOT NULL,
  `parameter` enum('Finishes','Tenacity','CV%','Elongation') DEFAULT NULL,
  `value` decimal(10,2) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `active_sessions`
--
ALTER TABLE `active_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_id` (`session_id`),
  ADD KEY `idx_active` (`is_active`,`last_activity`),
  ADD KEY `idx_user` (`username`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `api_access_log`
--
ALTER TABLE `api_access_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_api_endpoint` (`endpoint`,`accessed_at`),
  ADD KEY `idx_api_user` (`user_id`,`accessed_at`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `backup_log`
--
ALTER TABLE `backup_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_backup_status` (`status`,`started_at`);

--
-- Indexes for table `bag_size_master`
--
ALTER TABLE `bag_size_master`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bom`
--
ALTER TABLE `bom`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bag_size` (`bag_size`);

--
-- Indexes for table `branding_entries`
--
ALTER TABLE `branding_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `branding_id` (`branding_id`);

--
-- Indexes for table `bundle_test_completion`
--
ALTER TABLE `bundle_test_completion`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `characteristics_readings`
--
ALTER TABLE `characteristics_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `char_test_id` (`char_test_id`);

--
-- Indexes for table `characteristics_tests`
--
ALTER TABLE `characteristics_tests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_client_name` (`client_name`);

--
-- Indexes for table `cnc_entries`
--
ALTER TABLE `cnc_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cnc_id` (`cnc_id`),
  ADD KEY `reporter_id` (`reporter_id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `cut_length_fiber_reports`
--
ALTER TABLE `cut_length_fiber_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`);

--
-- Indexes for table `daily_gsm_checks`
--
ALTER TABLE `daily_gsm_checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reference_number` (`reference_number`);

--
-- Indexes for table `error_log`
--
ALTER TABLE `error_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_error_type_time` (`error_type`,`occurred_at`),
  ADD KEY `idx_error_user` (`user_id`,`occurred_at`);

--
-- Indexes for table `fabric_after_production_counters`
--
ALTER TABLE `fabric_after_production_counters`
  ADD PRIMARY KEY (`date_key`);

--
-- Indexes for table `fabric_after_production_tests`
--
ALTER TABLE `fabric_after_production_tests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `qc_entry_id` (`qc_entry_id`);

--
-- Indexes for table `fabric_pre_production_counters`
--
ALTER TABLE `fabric_pre_production_counters`
  ADD PRIMARY KEY (`date_key`);

--
-- Indexes for table `fabric_pre_production_tests`
--
ALTER TABLE `fabric_pre_production_tests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `qc_entry_id` (`qc_entry_id`);

--
-- Indexes for table `fabric_reports`
--
ALTER TABLE `fabric_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_no` (`report_no`),
  ADD KEY `inspector_id` (`inspector_id`);

--
-- Indexes for table `fabric_testing`
--
ALTER TABLE `fabric_testing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `inspector_id` (`inspector_id`);

--
-- Indexes for table `fabric_testing_readings`
--
ALTER TABLE `fabric_testing_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fabric_test_id` (`fabric_test_id`),
  ADD KEY `test_standard_id` (`test_standard_id`);

--
-- Indexes for table `fg`
--
ALTER TABLE `fg`
  ADD PRIMARY KEY (`id`),
  ADD KEY `prod_id` (`prod_id`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- Indexes for table `fg_deliveries`
--
ALTER TABLE `fg_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `delivery_id` (`delivery_id`),
  ADD KEY `fg_entry_id` (`fg_entry_id`);

--
-- Indexes for table `fg_delivery`
--
ALTER TABLE `fg_delivery`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `delivery_id` (`delivery_id`),
  ADD KEY `fg_id` (`fg_id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `fg_entry`
--
ALTER TABLE `fg_entry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fg_id` (`fg_id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_batch_number` (`batch_number`);

--
-- Indexes for table `fg_received`
--
ALTER TABLE `fg_received`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fiber_entries`
--
ALTER TABLE `fiber_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- Indexes for table `fiber_pretesting`
--
ALTER TABLE `fiber_pretesting`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fiber_pretesting_readings`
--
ALTER TABLE `fiber_pretesting_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fiber_pretest_id` (`fiber_pretest_id`);

--
-- Indexes for table `fiber_testing`
--
ALTER TABLE `fiber_testing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_no` (`report_no`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `inspector_id` (`inspector_id`);

--
-- Indexes for table `fiber_testing_readings`
--
ALTER TABLE `fiber_testing_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fiber_test_id` (`fiber_test_id`);

--
-- Indexes for table `fiber_testing_results`
--
ALTER TABLE `fiber_testing_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fiber_test_id` (`fiber_test_id`);

--
-- Indexes for table `fiber_test_counters`
--
ALTER TABLE `fiber_test_counters`
  ADD PRIMARY KEY (`date_key`);

--
-- Indexes for table `fiber_test_reports`
--
ALTER TABLE `fiber_test_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `idx_report_number` (`report_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sample_id` (`sample_id`);

--
-- Indexes for table `fiber_to_roll_entry`
--
ALTER TABLE `fiber_to_roll_entry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reference` (`reference_number`);

--
-- Indexes for table `fineness_fiber_reports`
--
ALTER TABLE `fineness_fiber_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`);

--
-- Indexes for table `length_calibrations`
--
ALTER TABLE `length_calibrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_time` (`attempt_time`);

--
-- Indexes for table `machines`
--
ALTER TABLE `machines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `material_consumption`
--
ALTER TABLE `material_consumption`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_material` (`material_id`),
  ADD KEY `idx_date` (`consumption_date`),
  ADD KEY `idx_type` (`consumption_type`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `new_user`
--
ALTER TABLE `new_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_new_user_last_login` (`last_login`),
  ADD KEY `idx_new_user_last_activity` (`last_activity`),
  ADD KEY `idx_new_user_lock_until` (`lock_until`),
  ADD KEY `idx_new_user_is_active` (`is_active`);

--
-- Indexes for table `production`
--
ALTER TABLE `production`
  ADD PRIMARY KEY (`id`),
  ADD KEY `roll_id` (`roll_id`);

--
-- Indexes for table `production_cost_settings`
--
ALTER TABLE `production_cost_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cost_type` (`cost_type`),
  ADD KEY `idx_cost_type` (`cost_type`);

--
-- Indexes for table `production_entry`
--
ALTER TABLE `production_entry`
  ADD PRIMARY KEY (`id`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `roll_id` (`roll_id`);

--
-- Indexes for table `production_targets`
--
ALTER TABLE `production_targets`
  ADD PRIMARY KEY (`target_id`),
  ADD KEY `module_id` (`module_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qc`
--
ALTER TABLE `qc`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qc_checks`
--
ALTER TABLE `qc_checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `qc_id` (`qc_id`);

--
-- Indexes for table `qc_entries`
--
ALTER TABLE `qc_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qc_id` (`qc_id`);

--
-- Indexes for table `qc_test_orders`
--
ALTER TABLE `qc_test_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `test_standard_id` (`test_standard_id`);

--
-- Indexes for table `qc_test_readings`
--
ALTER TABLE `qc_test_readings`
  ADD PRIMARY KEY (`reading_id`),
  ADD KEY `test_standard_id` (`test_standard_id`);

--
-- Indexes for table `recycle`
--
ALTER TABLE `recycle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `scrap_id` (`scrap_id`);

--
-- Indexes for table `report_tests`
--
ALTER TABLE `report_tests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rt_report` (`report_id`),
  ADD KEY `fk_rt_standard` (`test_standard_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `roll_entry`
--
ALTER TABLE `roll_entry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_id` (`entry_id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_roll_number` (`roll_number`),
  ADD KEY `idx_batch_number` (`batch_number`);

--
-- Indexes for table `roll_production`
--
ALTER TABLE `roll_production`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roll_qc_reports`
--
ALTER TABLE `roll_qc_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reference_number` (`reference_number`);

--
-- Indexes for table `roll_received`
--
ALTER TABLE `roll_received`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roll_transfer`
--
ALTER TABLE `roll_transfer`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_transfer_id` (`transfer_id`);

--
-- Indexes for table `scrap`
--
ALTER TABLE `scrap`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `scrap_id` (`scrap_id`),
  ADD KEY `prod_id` (`prod_id`);

--
-- Indexes for table `scrap_recycle`
--
ALTER TABLE `scrap_recycle`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_recycle_id` (`recycle_id`),
  ADD KEY `scrap_id` (`scrap_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `scrap_type_costs`
--
ALTER TABLE `scrap_type_costs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `scrap_type` (`scrap_type`);

--
-- Indexes for table `sewing_thread_approval_comments`
--
ALTER TABLE `sewing_thread_approval_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report_id` (`report_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_approver` (`approver_id`);

--
-- Indexes for table `sewing_thread_backups`
--
ALTER TABLE `sewing_thread_backups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `sewing_thread_counters`
--
ALTER TABLE `sewing_thread_counters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `day_key` (`day_key`);

--
-- Indexes for table `sewing_thread_logs`
--
ALTER TABLE `sewing_thread_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `sewing_thread_reports`
--
ALTER TABLE `sewing_thread_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `idx_report_number` (`report_number`),
  ADD KEY `idx_received_date` (`received_date`),
  ADD KEY `idx_reporter` (`reporter_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `sewing_thread_templates`
--
ALTER TABLE `sewing_thread_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_template_name` (`template_name`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_is_public` (`is_public`);

--
-- Indexes for table `sewing_thread_user_preferences`
--
ALTER TABLE `sewing_thread_user_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_preference` (`user_id`,`preference_key`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `side_cut_scrap`
--
ALTER TABLE `side_cut_scrap`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_id` (`entry_id`);

--
-- Indexes for table `slow_query_log`
--
ALTER TABLE `slow_query_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_query_time` (`execution_time`,`executed_at`);

--
-- Indexes for table `store_received_counters`
--
ALTER TABLE `store_received_counters`
  ADD PRIMARY KEY (`date_key`);

--
-- Indexes for table `store_received_entries`
--
ALTER TABLE `store_received_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_number` (`entry_number`);

--
-- Indexes for table `sun_tests`
--
ALTER TABLE `sun_tests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_no` (`report_no`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `inspector_id` (`inspector_id`);

--
-- Indexes for table `sun_test_counters`
--
ALTER TABLE `sun_test_counters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `day_key` (`day_key`);

--
-- Indexes for table `sun_test_logs`
--
ALTER TABLE `sun_test_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sun_test_readings`
--
ALTER TABLE `sun_test_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sun_test_id` (`sun_test_id`);

--
-- Indexes for table `sun_test_reports`
--
ALTER TABLE `sun_test_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `idx_report_number` (`report_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_reporter_id` (`reporter_id`);

--
-- Indexes for table `swing_machine_entry`
--
ALTER TABLE `swing_machine_entry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `swing_id` (`swing_id`);

--
-- Indexes for table `system_health_checks`
--
ALTER TABLE `system_health_checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_check_status` (`check_type`,`status`,`checked_at`);

--
-- Indexes for table `system_performance`
--
ALTER TABLE `system_performance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_metric_time` (`metric_name`,`recorded_at`);

--
-- Indexes for table `tenacity_fiber_reports`
--
ALTER TABLE `tenacity_fiber_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`);

--
-- Indexes for table `tenacity_yarn_reports`
--
ALTER TABLE `tenacity_yarn_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`);

--
-- Indexes for table `tenacity_yarn_report_logs`
--
ALTER TABLE `tenacity_yarn_report_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `test_readings`
--
ALTER TABLE `test_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_trt_reporttest` (`report_test_id`);

--
-- Indexes for table `test_reports`
--
ALTER TABLE `test_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_no` (`report_no`),
  ADD KEY `fk_tr_project` (`project_id`),
  ADD KEY `fk_tr_inspector` (`inspector_id`);

--
-- Indexes for table `test_standards`
--
ALTER TABLE `test_standards`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `test_summary`
--
ALTER TABLE `test_summary`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ts_reporttest` (`report_test_id`);

--
-- Indexes for table `thickness_test_counters`
--
ALTER TABLE `thickness_test_counters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date_key` (`date_key`);

--
-- Indexes for table `thickness_test_reports`
--
ALTER TABLE `thickness_test_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_employee_id` (`employee_id`);

--
-- Indexes for table `user_qc_preferences`
--
ALTER TABLE `user_qc_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_field` (`user_id`,`field_name`),
  ADD KEY `idx_user_updated` (`user_id`,`updated_at`);

--
-- Indexes for table `uv_tests`
--
ALTER TABLE `uv_tests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_no` (`report_no`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `inspector_id` (`inspector_id`);

--
-- Indexes for table `uv_test_readings`
--
ALTER TABLE `uv_test_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uv_test_id` (`uv_test_id`);

--
-- Indexes for table `water_permeability_readings`
--
ALTER TABLE `water_permeability_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `water_test_id` (`water_test_id`);

--
-- Indexes for table `water_permeability_tests`
--
ALTER TABLE `water_permeability_tests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `idx_report_number` (`report_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_reporter_id` (`reporter_id`),
  ADD KEY `idx_wpt_report_number` (`report_number`),
  ADD KEY `idx_wpt_test_date` (`test_date`),
  ADD KEY `idx_wpt_status` (`status`),
  ADD KEY `idx_wpt_reporter` (`reporter_id`),
  ADD KEY `idx_wpt_status_date` (`status`,`test_date`);

--
-- Indexes for table `weathering_exposure_counters`
--
ALTER TABLE `weathering_exposure_counters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `day_key` (`day_key`);

--
-- Indexes for table `weathering_exposure_logs`
--
ALTER TABLE `weathering_exposure_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report_id` (`report_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `weathering_exposure_reports`
--
ALTER TABLE `weathering_exposure_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `idx_report_number` (`report_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_reporter_id` (`reporter_id`);

--
-- Indexes for table `yarn_tests`
--
ALTER TABLE `yarn_tests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_no` (`report_no`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `inspector_id` (`inspector_id`);

--
-- Indexes for table `yarn_test_readings`
--
ALTER TABLE `yarn_test_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `yarn_test_id` (`yarn_test_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `active_sessions`
--
ALTER TABLE `active_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `api_access_log`
--
ALTER TABLE `api_access_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=162;

--
-- AUTO_INCREMENT for table `backup_log`
--
ALTER TABLE `backup_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bag_size_master`
--
ALTER TABLE `bag_size_master`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `bom`
--
ALTER TABLE `bom`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `branding_entries`
--
ALTER TABLE `branding_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `bundle_test_completion`
--
ALTER TABLE `bundle_test_completion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `characteristics_readings`
--
ALTER TABLE `characteristics_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `characteristics_tests`
--
ALTER TABLE `characteristics_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `cnc_entries`
--
ALTER TABLE `cnc_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cut_length_fiber_reports`
--
ALTER TABLE `cut_length_fiber_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `daily_gsm_checks`
--
ALTER TABLE `daily_gsm_checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `error_log`
--
ALTER TABLE `error_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fabric_after_production_tests`
--
ALTER TABLE `fabric_after_production_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fabric_pre_production_tests`
--
ALTER TABLE `fabric_pre_production_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fabric_reports`
--
ALTER TABLE `fabric_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fabric_testing`
--
ALTER TABLE `fabric_testing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fabric_testing_readings`
--
ALTER TABLE `fabric_testing_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fg`
--
ALTER TABLE `fg`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fg_deliveries`
--
ALTER TABLE `fg_deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fg_delivery`
--
ALTER TABLE `fg_delivery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fg_entry`
--
ALTER TABLE `fg_entry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fg_received`
--
ALTER TABLE `fg_received`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fiber_entries`
--
ALTER TABLE `fiber_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `fiber_pretesting`
--
ALTER TABLE `fiber_pretesting`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fiber_pretesting_readings`
--
ALTER TABLE `fiber_pretesting_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fiber_testing`
--
ALTER TABLE `fiber_testing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fiber_testing_readings`
--
ALTER TABLE `fiber_testing_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fiber_testing_results`
--
ALTER TABLE `fiber_testing_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fiber_test_reports`
--
ALTER TABLE `fiber_test_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fiber_to_roll_entry`
--
ALTER TABLE `fiber_to_roll_entry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fineness_fiber_reports`
--
ALTER TABLE `fineness_fiber_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `length_calibrations`
--
ALTER TABLE `length_calibrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `machines`
--
ALTER TABLE `machines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `material_consumption`
--
ALTER TABLE `material_consumption`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `new_user`
--
ALTER TABLE `new_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `production`
--
ALTER TABLE `production`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_cost_settings`
--
ALTER TABLE `production_cost_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_entry`
--
ALTER TABLE `production_entry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_targets`
--
ALTER TABLE `production_targets`
  MODIFY `target_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `qc`
--
ALTER TABLE `qc`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_checks`
--
ALTER TABLE `qc_checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_entries`
--
ALTER TABLE `qc_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `qc_test_orders`
--
ALTER TABLE `qc_test_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `qc_test_readings`
--
ALTER TABLE `qc_test_readings`
  MODIFY `reading_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recycle`
--
ALTER TABLE `recycle`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_tests`
--
ALTER TABLE `report_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roll_entry`
--
ALTER TABLE `roll_entry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `roll_production`
--
ALTER TABLE `roll_production`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roll_qc_reports`
--
ALTER TABLE `roll_qc_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `roll_received`
--
ALTER TABLE `roll_received`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `roll_transfer`
--
ALTER TABLE `roll_transfer`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `scrap`
--
ALTER TABLE `scrap`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `scrap_recycle`
--
ALTER TABLE `scrap_recycle`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `scrap_type_costs`
--
ALTER TABLE `scrap_type_costs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_thread_approval_comments`
--
ALTER TABLE `sewing_thread_approval_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_thread_backups`
--
ALTER TABLE `sewing_thread_backups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_thread_counters`
--
ALTER TABLE `sewing_thread_counters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_thread_logs`
--
ALTER TABLE `sewing_thread_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sewing_thread_reports`
--
ALTER TABLE `sewing_thread_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sewing_thread_templates`
--
ALTER TABLE `sewing_thread_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_thread_user_preferences`
--
ALTER TABLE `sewing_thread_user_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `side_cut_scrap`
--
ALTER TABLE `side_cut_scrap`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `slow_query_log`
--
ALTER TABLE `slow_query_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `store_received_entries`
--
ALTER TABLE `store_received_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sun_tests`
--
ALTER TABLE `sun_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sun_test_counters`
--
ALTER TABLE `sun_test_counters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sun_test_logs`
--
ALTER TABLE `sun_test_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sun_test_readings`
--
ALTER TABLE `sun_test_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sun_test_reports`
--
ALTER TABLE `sun_test_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `swing_machine_entry`
--
ALTER TABLE `swing_machine_entry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_health_checks`
--
ALTER TABLE `system_health_checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_performance`
--
ALTER TABLE `system_performance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenacity_fiber_reports`
--
ALTER TABLE `tenacity_fiber_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tenacity_yarn_reports`
--
ALTER TABLE `tenacity_yarn_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tenacity_yarn_report_logs`
--
ALTER TABLE `tenacity_yarn_report_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `test_readings`
--
ALTER TABLE `test_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `test_reports`
--
ALTER TABLE `test_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `test_standards`
--
ALTER TABLE `test_standards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `test_summary`
--
ALTER TABLE `test_summary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `thickness_test_counters`
--
ALTER TABLE `thickness_test_counters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `thickness_test_reports`
--
ALTER TABLE `thickness_test_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_qc_preferences`
--
ALTER TABLE `user_qc_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `uv_tests`
--
ALTER TABLE `uv_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `uv_test_readings`
--
ALTER TABLE `uv_test_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `water_permeability_readings`
--
ALTER TABLE `water_permeability_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `water_permeability_tests`
--
ALTER TABLE `water_permeability_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `weathering_exposure_counters`
--
ALTER TABLE `weathering_exposure_counters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `weathering_exposure_logs`
--
ALTER TABLE `weathering_exposure_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weathering_exposure_reports`
--
ALTER TABLE `weathering_exposure_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `yarn_tests`
--
ALTER TABLE `yarn_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `yarn_test_readings`
--
ALTER TABLE `yarn_test_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `user_qc_preferences`
--
ALTER TABLE `user_qc_preferences`
  ADD CONSTRAINT `user_qc_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `new_user` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
