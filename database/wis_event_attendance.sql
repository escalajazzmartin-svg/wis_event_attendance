-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 24, 2026 at 05:54 AM
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
-- Database: `wis_event_attendance`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_periods`
--

CREATE TABLE `academic_periods` (
  `academic_period_id` bigint(20) UNSIGNED NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer','Other') NOT NULL DEFAULT '1st',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `academic_periods`
--

INSERT INTO `academic_periods` (`academic_period_id`, `academic_year`, `semester`, `start_date`, `end_date`, `is_current`, `created_at`) VALUES
(1, '2026-2027', '1st', '2026-08-01', '2026-12-31', 1, '2026-10-17 07:00:40');

-- --------------------------------------------------------

--
-- Table structure for table `admin_activity_logs`
--

CREATE TABLE `admin_activity_logs` (
  `activity_id` bigint(20) UNSIGNED NOT NULL,
  `actor_user_id` bigint(20) UNSIGNED NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_appeals`
--

CREATE TABLE `attendance_appeals` (
  `appeal_id` bigint(20) UNSIGNED NOT NULL,
  `attendance_id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `appeal_reason` text NOT NULL,
  `evidence_path` varchar(500) NOT NULL,
  `appeal_status` enum('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  `reviewed_by_department_head_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewer_remarks` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_appeals`
--

INSERT INTO `attendance_appeals` (`appeal_id`, `attendance_id`, `student_id`, `appeal_reason`, `evidence_path`, `appeal_status`, `reviewed_by_department_head_id`, `reviewed_at`, `reviewer_remarks`, `submitted_at`, `updated_at`) VALUES
(1, 4, 1, 'I was unable to attend the workshop due to an unexpected emergency. Please review my attendance record.', 'uploads/appeals/sample-emergency.pdf', 'pending', NULL, NULL, NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_audit_logs`
--

CREATE TABLE `attendance_audit_logs` (
  `audit_log_id` bigint(20) UNSIGNED NOT NULL,
  `attendance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `event_id` bigint(20) UNSIGNED DEFAULT NULL,
  `actor_user_id` bigint(20) UNSIGNED NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_value`)),
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_settings`
--

CREATE TABLE `attendance_settings` (
  `attendance_setting_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `present_until` datetime NOT NULL,
  `late_until` datetime NOT NULL,
  `absent_after` datetime NOT NULL,
  `updated_by` bigint(20) UNSIGNED NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `attendance_settings`
--

INSERT INTO `attendance_settings` (`attendance_setting_id`, `event_id`, `present_until`, `late_until`, `absent_after`, `updated_by`, `updated_at`) VALUES
(1, 1, '2026-11-10 08:15:00', '2026-11-10 09:00:00', '2026-11-10 09:00:00', 1, '2026-10-17 07:00:40'),
(2, 3, '2026-10-22 09:10:00', '2026-10-22 09:30:00', '2026-10-22 09:30:00', 1, '2026-10-19 11:51:55'),
(3, 4, '2026-11-18 13:15:00', '2026-11-18 14:00:00', '2026-11-18 14:00:00', 1, '2026-10-19 11:51:55');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` bigint(20) UNSIGNED NOT NULL,
  `department_code` varchar(30) NOT NULL,
  `department_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `department_head_can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `department_head_can_edit_attendance` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `description`, `is_active`, `department_head_can_edit`, `department_head_can_edit_attendance`, `created_at`) VALUES
(1, 'SHS', 'Senior High School', 'Senior high school department', 0, 0, 0, '2026-10-14 13:33:41'),
(2, 'JHS', 'Junior High School', 'Junior high school department', 0, 0, 0, '2026-10-14 13:33:41'),
(3, 'COLLEGE', 'College', 'College department', 0, 0, 0, '2026-10-14 13:33:41'),
(11, 'CITCS', 'College of Information Technology and Computer Studies', 'Information technology and computer studies', 1, 0, 0, '2026-10-17 07:00:40'),
(12, 'CAS', 'College of Arts and Sciences', 'Arts and sciences programs', 1, 0, 0, '2026-10-17 07:00:40'),
(13, 'SEBA', 'School of Economics, Business and Accountancy', 'Business and entrepreneurship programs', 1, 0, 0, '2026-10-17 07:00:40'),
(14, 'CITHM', 'College of Tourism and Hospitality Management', 'Tourism and hospitality programs', 1, 0, 0, '2026-10-17 07:00:40'),
(15, 'SOC', 'School of Criminology', 'Criminology programs', 1, 0, 0, '2026-10-17 07:00:40'),
(16, 'COE', 'College of Engineering', 'Engineering programs', 1, 0, 0, '2026-10-17 07:00:40'),
(17, 'CTE', 'College of Teacher Education', 'Teacher education programs', 1, 0, 0, '2026-10-17 07:00:40');

-- --------------------------------------------------------

--
-- Table structure for table `department_heads`
--

CREATE TABLE `department_heads` (
  `department_head_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `department_id` bigint(20) UNSIGNED NOT NULL,
  `employee_number` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `department_heads`
--

INSERT INTO `department_heads` (`department_head_id`, `user_id`, `department_id`, `employee_number`, `first_name`, `middle_name`, `last_name`, `created_at`) VALUES
(1, 2, 11, 'EMP-0001', 'Sample', NULL, 'Department Head', '2026-10-17 07:00:40');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `department_id` bigint(20) UNSIGNED NOT NULL,
  `requested_by` bigint(20) UNSIGNED NOT NULL,
  `academic_period_id` bigint(20) UNSIGNED NOT NULL,
  `event_title` varchar(200) NOT NULL,
  `event_description` text DEFAULT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `attendance_opens_at` datetime DEFAULT NULL,
  `attendance_closes_at` datetime DEFAULT NULL,
  `event_status` enum('pending','approved','rejected','cancelled','cancellation_requested','completed') NOT NULL DEFAULT 'pending',
  `admin_reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `admin_reviewed_at` datetime DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `cancellation_requested_at` datetime DEFAULT NULL,
  `cancellation_requested_by` bigint(20) UNSIGNED DEFAULT NULL,
  `cancellation_request_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`event_id`, `department_id`, `requested_by`, `academic_period_id`, `event_title`, `event_description`, `venue`, `start_at`, `end_at`, `attendance_opens_at`, `attendance_closes_at`, `event_status`, `admin_reviewed_by`, `admin_reviewed_at`, `admin_remarks`, `cancellation_requested_at`, `cancellation_requested_by`, `cancellation_request_remarks`, `created_at`, `updated_at`) VALUES
(1, 11, 1, 1, 'CITCS Orientation', 'Sample event for testing attendance.', 'School Campus', '2026-11-10 08:00:00', '2026-11-10 12:00:00', '2026-11-10 07:30:00', '2026-11-10 12:00:00', 'approved', 1, '2026-10-17 15:00:40', 'Approved sample event', NULL, NULL, NULL, '2026-10-17 07:00:40', '2026-10-17 07:00:40'),
(2, 11, 1, 1, 'IT Week Opening Program', 'Department-wide IT Week opening program.', 'Main Auditorium', '2026-09-15 08:00:00', '2026-09-15 12:00:00', '2026-09-15 07:30:00', '2026-09-15 12:00:00', 'completed', 1, '2026-09-10 10:00:00', 'Approved', NULL, NULL, NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(3, 11, 1, 1, 'Cybersecurity Awareness Seminar', 'Introductory cybersecurity awareness seminar.', 'Computer Laboratory 1', '2026-10-22 09:00:00', '2026-10-22 11:30:00', '2026-10-22 08:30:00', '2026-10-22 11:30:00', 'approved', 1, '2026-10-18 10:00:00', 'Approved', NULL, NULL, NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(4, 11, 1, 1, 'Web Development Workshop', 'Hands-on HTML, CSS, and PHP workshop.', 'Computer Laboratory 2', '2026-11-18 13:00:00', '2026-11-18 16:00:00', '2026-11-18 12:30:00', '2026-11-18 16:00:00', 'approved', 1, '2026-10-18 10:00:00', 'Approved', NULL, NULL, NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(5, 11, 1, 1, 'Student Research Forum', 'Student project and research presentations.', 'WIS Function Hall', '2026-12-05 08:00:00', '2026-12-05 15:00:00', '2026-12-05 07:30:00', '2026-12-05 15:00:00', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55');

-- --------------------------------------------------------

--
-- Table structure for table `event_attendance`
--

CREATE TABLE `event_attendance` (
  `attendance_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `attendance_status` enum('present','late','absent','excused') NOT NULL DEFAULT 'absent',
  `check_in_at` datetime DEFAULT NULL,
  `recorded_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `record_source` enum('barcode','department_head','appeal') NOT NULL DEFAULT 'barcode',
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_attendance`
--

INSERT INTO `event_attendance` (`attendance_id`, `event_id`, `student_id`, `attendance_status`, `check_in_at`, `recorded_by_user_id`, `record_source`, `remarks`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'present', '2026-11-10 08:00:00', 2, 'department_head', 'Sample attendance record', '2026-10-17 07:00:40', '2026-10-17 07:00:40'),
(2, 2, 1, 'present', '2026-09-15 08:05:00', 2, 'barcode', 'Checked in on time', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(3, 3, 1, 'late', '2026-10-22 09:18:00', 2, 'department_head', 'Arrived after the present cutoff', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(4, 4, 1, 'absent', NULL, 2, 'department_head', 'No check-in recorded', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(5, 2, 2, 'late', '2026-09-15 08:25:00', 2, 'barcode', 'Late arrival', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(6, 2, 3, 'absent', NULL, 2, 'department_head', 'No check-in recorded', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(7, 2, 4, 'present', '2026-09-15 07:55:00', 2, 'barcode', 'Checked in on time', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(8, 2, 5, 'excused', NULL, 2, 'department_head', 'Excuse documented', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(9, 2, 6, 'present', '2026-09-15 08:02:00', 2, 'barcode', 'Checked in on time', '2026-10-19 11:51:55', '2026-10-19 11:51:55');

-- --------------------------------------------------------

--
-- Table structure for table `event_change_requests`
--

CREATE TABLE `event_change_requests` (
  `change_request_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `requested_by_department_head_id` bigint(20) UNSIGNED NOT NULL,
  `request_payload` longtext NOT NULL,
  `request_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewer_remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_participants`
--

CREATE TABLE `event_participants` (
  `event_participant_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `participant_type` enum('student','year_level','section') NOT NULL,
  `student_id` bigint(20) UNSIGNED DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `section` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`, `description`, `created_at`) VALUES
(1, 'admin', 'System administrator', '2026-10-17 07:03:57'),
(2, 'department_head', 'Department Head', '2026-10-17 07:03:57'),
(3, 'student', 'Student', '2026-10-17 07:03:57');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `student_number` varchar(50) NOT NULL,
  `barcode_token` char(64) NOT NULL,
  `department_id` bigint(20) UNSIGNED NOT NULL,
  `section` varchar(100) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `enrollment_status` enum('enrolled','inactive','graduated','transferred') NOT NULL DEFAULT 'enrolled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `user_id`, `student_number`, `barcode_token`, `department_id`, `section`, `year_level`, `first_name`, `middle_name`, `last_name`, `enrollment_status`, `created_at`, `updated_at`) VALUES
(1, 3, 'STU-0001', '2153505580c8014ccb3f3fd2b3d4a9f8e15a717fe0e67d6b3effe657af064c52', 11, 'BSIT-1A', '1st Year', 'Sample', NULL, 'Student', 'enrolled', '2026-10-17 07:00:40', '2026-10-17 07:00:40'),
(2, 4, 'STU-0002', '946b8b2312a38ddd52f9dcf896a87f22527bbc1152ecefd2115151c0a3400ffe', 11, 'BSIT-1A', '1st Year', 'Alex', NULL, 'Reyes', 'enrolled', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(3, 5, 'STU-0003', '2d9b32bd46925b092f22d27a6667175bae5605ec3d310d1ea1da996ad05fa88a', 11, 'BSIT-1A', '1st Year', 'Jamie', NULL, 'Santos', 'enrolled', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(4, 6, 'STU-0004', 'd1b8f83cb6e039e11e20acc55aeeba271760b15524ce3bce404da859e1239f77', 11, 'BSIT-2A', '2nd Year', 'Taylor', NULL, 'Cruz', 'enrolled', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(5, 7, 'STU-0005', '4b1097846633437532310f75bf2a8e2edbecf88885b49a5d991f4d25c9a6dbd3', 11, 'BSIT-2A', '2nd Year', 'Morgan', NULL, 'Garcia', 'enrolled', '2026-10-19 11:51:55', '2026-10-19 11:51:55'),
(6, 8, 'STU-0006', '51d3f49cd932506a71d8355a0c012795869970075e68e952965f1c04a23c5234', 11, 'BSIT-3A', '3rd Year', 'Casey', NULL, 'Dela Cruz', 'enrolled', '2026-10-19 11:51:55', '2026-10-19 11:51:55');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','department_head','student') NOT NULL,
  `account_status` enum('active','inactive','locked') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `role_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `account_status`, `last_login_at`, `created_at`, `updated_at`, `role_id`) VALUES
(1, 'admin', 'admin001@wis.local', '$2y$10$wA6YQNMv0qxqbRv/LS.BBOJ3vGLU4lt2gZm7PwCCzi7N8bgJqlj3O', 'admin', 'active', '2026-10-24 00:22:29', '2026-10-17 07:00:40', '2026-10-23 16:22:29', 1),
(2, 'depthead', 'head001@wis.local', '$2y$10$zxdfz0.V.JeaC.dfd1KixOF3dMEP/xp3O427Fcz72paRZcpqUX3ci', 'department_head', 'active', '2026-10-20 13:24:46', '2026-10-17 07:00:40', '2026-10-20 05:24:46', 2),
(3, 'student', 'student001@wis.local', '$2y$10$RGoHbrLihD8iWwjgE7QO4.CluTXWlamPkkrAc8Y82enYy7Mt9Vohy', 'student', 'active', '2026-10-24 11:52:07', '2026-10-17 07:00:40', '2026-10-24 03:52:07', 3),
(4, 'student02', 'student002@wis.local', '$2y$10$RGoHbrLihD8iWwjgE7QO4.CluTXWlamPkkrAc8Y82enYy7Mt9Vohy', 'student', 'active', NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55', 3),
(5, 'student03', 'student003@wis.local', '$2y$10$RGoHbrLihD8iWwjgE7QO4.CluTXWlamPkkrAc8Y82enYy7Mt9Vohy', 'student', 'active', NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55', 3),
(6, 'student04', 'student004@wis.local', '$2y$10$RGoHbrLihD8iWwjgE7QO4.CluTXWlamPkkrAc8Y82enYy7Mt9Vohy', 'student', 'active', NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55', 3),
(7, 'student05', 'student005@wis.local', '$2y$10$RGoHbrLihD8iWwjgE7QO4.CluTXWlamPkkrAc8Y82enYy7Mt9Vohy', 'student', 'active', NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55', 3),
(8, 'student06', 'student006@wis.local', '$2y$10$RGoHbrLihD8iWwjgE7QO4.CluTXWlamPkkrAc8Y82enYy7Mt9Vohy', 'student', 'active', NULL, '2026-10-19 11:51:55', '2026-10-19 11:51:55', 3);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_periods`
--
ALTER TABLE `academic_periods`
  ADD PRIMARY KEY (`academic_period_id`),
  ADD UNIQUE KEY `uq_academic_period` (`academic_year`,`semester`);

--
-- Indexes for table `admin_activity_logs`
--
ALTER TABLE `admin_activity_logs`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `idx_admin_activity_created` (`created_at`),
  ADD KEY `idx_admin_activity_entity` (`entity_type`,`entity_id`),
  ADD KEY `fk_admin_activity_actor` (`actor_user_id`);

--
-- Indexes for table `attendance_appeals`
--
ALTER TABLE `attendance_appeals`
  ADD PRIMARY KEY (`appeal_id`),
  ADD KEY `idx_appeals_status` (`appeal_status`),
  ADD KEY `idx_appeals_student` (`student_id`,`submitted_at`),
  ADD KEY `fk_appeals_attendance` (`attendance_id`),
  ADD KEY `fk_appeals_reviewer` (`reviewed_by_department_head_id`);

--
-- Indexes for table `attendance_audit_logs`
--
ALTER TABLE `attendance_audit_logs`
  ADD PRIMARY KEY (`audit_log_id`),
  ADD KEY `idx_audit_event_date` (`event_id`,`created_at`),
  ADD KEY `idx_audit_actor_date` (`actor_user_id`,`created_at`),
  ADD KEY `fk_audit_attendance` (`attendance_id`);

--
-- Indexes for table `attendance_settings`
--
ALTER TABLE `attendance_settings`
  ADD PRIMARY KEY (`attendance_setting_id`),
  ADD UNIQUE KEY `event_id` (`event_id`),
  ADD KEY `fk_settings_editor` (`updated_by`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_code` (`department_code`),
  ADD UNIQUE KEY `department_name` (`department_name`);

--
-- Indexes for table `department_heads`
--
ALTER TABLE `department_heads`
  ADD PRIMARY KEY (`department_head_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `employee_number` (`employee_number`),
  ADD KEY `fk_heads_department` (`department_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `fk_events_requester` (`requested_by`),
  ADD KEY `fk_events_reviewer` (`admin_reviewed_by`),
  ADD KEY `idx_events_department_dates` (`department_id`,`start_at`,`end_at`),
  ADD KEY `idx_events_status` (`event_status`),
  ADD KEY `idx_events_period` (`academic_period_id`),
  ADD KEY `idx_events_cancellation_requester` (`cancellation_requested_by`);

--
-- Indexes for table `event_attendance`
--
ALTER TABLE `event_attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `uq_event_student` (`event_id`,`student_id`),
  ADD KEY `idx_attendance_student_status` (`student_id`,`attendance_status`),
  ADD KEY `fk_attendance_recorder` (`recorded_by_user_id`);

--
-- Indexes for table `event_change_requests`
--
ALTER TABLE `event_change_requests`
  ADD PRIMARY KEY (`change_request_id`),
  ADD KEY `idx_event_change_status` (`request_status`,`requested_at`),
  ADD KEY `idx_event_change_event` (`event_id`,`requested_at`),
  ADD KEY `fk_event_change_requester` (`requested_by_department_head_id`),
  ADD KEY `fk_event_change_reviewer` (`reviewed_by_user_id`);

--
-- Indexes for table `event_participants`
--
ALTER TABLE `event_participants`
  ADD PRIMARY KEY (`event_participant_id`),
  ADD KEY `idx_event_participants_event` (`event_id`),
  ADD KEY `idx_event_participants_student` (`student_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD UNIQUE KEY `barcode_token` (`barcode_token`),
  ADD KEY `fk_students_department` (`department_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_periods`
--
ALTER TABLE `academic_periods`
  MODIFY `academic_period_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_activity_logs`
--
ALTER TABLE `admin_activity_logs`
  MODIFY `activity_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_appeals`
--
ALTER TABLE `attendance_appeals`
  MODIFY `appeal_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendance_audit_logs`
--
ALTER TABLE `attendance_audit_logs`
  MODIFY `audit_log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_settings`
--
ALTER TABLE `attendance_settings`
  MODIFY `attendance_setting_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `department_heads`
--
ALTER TABLE `department_heads`
  MODIFY `department_head_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `event_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_attendance`
--
ALTER TABLE `event_attendance`
  MODIFY `attendance_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `event_change_requests`
--
ALTER TABLE `event_change_requests`
  MODIFY `change_request_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_participants`
--
ALTER TABLE `event_participants`
  MODIFY `event_participant_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_activity_logs`
--
ALTER TABLE `admin_activity_logs`
  ADD CONSTRAINT `fk_admin_activity_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `attendance_appeals`
--
ALTER TABLE `attendance_appeals`
  ADD CONSTRAINT `fk_appeals_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `event_attendance` (`attendance_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appeals_reviewer` FOREIGN KEY (`reviewed_by_department_head_id`) REFERENCES `department_heads` (`department_head_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appeals_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON UPDATE CASCADE;

--
-- Constraints for table `attendance_audit_logs`
--
ALTER TABLE `attendance_audit_logs`
  ADD CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_audit_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `event_attendance` (`attendance_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_audit_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `attendance_settings`
--
ALTER TABLE `attendance_settings`
  ADD CONSTRAINT `fk_settings_editor` FOREIGN KEY (`updated_by`) REFERENCES `department_heads` (`department_head_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_settings_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `department_heads`
--
ALTER TABLE `department_heads`
  ADD CONSTRAINT `fk_heads_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_heads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `fk_events_cancellation_requester` FOREIGN KEY (`cancellation_requested_by`) REFERENCES `department_heads` (`department_head_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_events_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_events_period` FOREIGN KEY (`academic_period_id`) REFERENCES `academic_periods` (`academic_period_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_events_requester` FOREIGN KEY (`requested_by`) REFERENCES `department_heads` (`department_head_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_events_reviewer` FOREIGN KEY (`admin_reviewed_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `event_attendance`
--
ALTER TABLE `event_attendance`
  ADD CONSTRAINT `fk_attendance_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_attendance_recorder` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON UPDATE CASCADE;

--
-- Constraints for table `event_change_requests`
--
ALTER TABLE `event_change_requests`
  ADD CONSTRAINT `fk_event_change_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_event_change_requester` FOREIGN KEY (`requested_by_department_head_id`) REFERENCES `department_heads` (`department_head_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_event_change_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `event_participants`
--
ALTER TABLE `event_participants`
  ADD CONSTRAINT `fk_event_participants_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_event_participants_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
