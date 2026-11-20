-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 20, 2026 at 02:39 PM
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
-- Database: `mhms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `allergies`
--

CREATE TABLE `allergies` (
  `allergy_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `allergen` varchar(100) NOT NULL,
  `reaction` varchar(200) DEFAULT NULL,
  `severity` enum('Mild','Moderate','Severe') DEFAULT 'Mild',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `allergies`
--

INSERT INTO `allergies` (`allergy_id`, `patient_id`, `allergen`, `reaction`, `severity`, `created_at`) VALUES
(1, 1, 'demo', 'fictional', 'Moderate', '2026-11-13 14:34:24');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` enum('Scheduled','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `patient_id`, `doctor_id`, `starts_at`, `ends_at`, `reason`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-11-20 23:22:00', '2026-11-20 23:24:00', 'fictional', 'Cancelled', 1, '2026-11-13 17:50:03', '2026-11-13 17:50:10'),
(2, 1, 1, '2026-11-14 23:25:00', '2026-11-14 23:31:00', 'fictional', 'Scheduled', 1, '2026-11-13 17:51:18', '2026-11-13 18:53:59');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `patient_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 14:05:52'),
(2, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 14:10:35'),
(3, 1, 1, 'ADD_PATIENT', 'Patient profile created', '::1', '2026-11-13 14:18:51'),
(4, 1, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 14:20:33'),
(5, 1, 1, 'ADD_MEDICAL_HISTORY', 'Medical history entry created', '::1', '2026-11-13 14:23:53'),
(6, 1, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 14:27:20'),
(7, 1, 1, 'ADD_ALLERGY', 'Allergy entry created', '::1', '2026-11-13 14:34:24'),
(8, 1, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 14:34:27'),
(9, 1, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 14:34:29'),
(10, 1, NULL, 'ADD_DOCTOR', 'Doctor account created; user ID 2', '::1', '2026-11-13 14:37:40'),
(11, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 14:42:02'),
(12, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 14:42:57'),
(13, 1, 1, 'ASSIGN_PATIENT', 'Patient assigned to doctor ID 1', '::1', '2026-11-13 14:53:22'),
(14, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 15:10:23'),
(15, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 15:12:10'),
(16, 2, 1, 'ADD_CONSULTATION', 'Consultation created; visit ID 1', '::1', '2026-11-13 15:16:28'),
(17, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 15:42:30'),
(18, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 15:44:28'),
(19, 2, 1, 'ADD_PRESCRIPTION', 'Prescription saved for visit ID 1', '::1', '2026-11-13 15:46:59'),
(20, 2, 1, 'VIEW_PRESCRIPTION_PRINT', 'Opened printable prescription for visit ID 1', '::1', '2026-11-13 15:55:29'),
(21, 2, 1, 'UPLOAD_REPORT', 'Report uploaded; report ID 1', '::1', '2026-11-13 17:23:14'),
(22, 2, 1, 'DOWNLOAD_REPORT', 'Report downloaded; report ID 1', '::1', '2026-11-13 17:23:22'),
(23, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 17:25:32'),
(24, 1, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:26:00'),
(25, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 17:32:32'),
(26, 2, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:35:29'),
(27, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 17:36:22'),
(28, 1, 2, 'ADD_PATIENT', 'Patient profile created', '::1', '2026-11-13 17:37:03'),
(29, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:37:41'),
(30, 1, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:38:02'),
(31, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:38:07'),
(32, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 17:40:27'),
(33, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 17:43:36'),
(34, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:43:47'),
(35, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:44:21'),
(36, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:44:23'),
(37, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:44:23'),
(38, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:44:23'),
(39, 1, 2, 'EDIT_PATIENT', 'Changed fields: emergency_contact. Reason: adding emergency number', '::1', '2026-11-13 17:45:13'),
(40, 1, 2, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:45:18'),
(41, 1, 1, 'BOOK_APPOINTMENT', 'Appointment created; ID 1', '::1', '2026-11-13 17:50:03'),
(42, 1, 1, 'UPDATE_APPOINTMENT', 'Appointment ID 1 marked Cancelled', '::1', '2026-11-13 17:50:10'),
(43, 1, 1, 'BOOK_APPOINTMENT', 'Appointment created; ID 2', '::1', '2026-11-13 17:51:18'),
(44, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 17:51:38'),
(45, 2, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:58:58'),
(46, 2, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-13 17:59:12'),
(47, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:01:04'),
(48, 1, 1, 'CREATE_PATIENT_ACCOUNT', 'Patient login created; user ID 3', '::1', '2026-11-13 18:02:04'),
(49, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:08:50'),
(50, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-13 18:08:50'),
(51, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-13 18:13:55'),
(52, 3, 1, 'VIEW_MEDICAL_SUMMARY', 'Opened printable medical summary', '::1', '2026-11-13 18:14:13'),
(53, 3, 1, 'VIEW_PATIENT_TIMELINE', 'Opened timeline; filter: all', '::1', '2026-11-13 18:17:44'),
(54, 3, 1, 'VIEW_PATIENT_TIMELINE', 'Opened timeline; filter: all', '::1', '2026-11-13 18:17:57'),
(55, 3, 1, 'VIEW_PATIENT_TIMELINE', 'Opened timeline; filter: all', '::1', '2026-11-13 18:17:59'),
(56, 3, 1, 'VIEW_MEDICAL_SUMMARY', 'Opened printable medical summary', '::1', '2026-11-13 18:18:06'),
(57, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:21:47'),
(58, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:23:27'),
(59, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-13 18:23:27'),
(60, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:25:38'),
(61, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-13 18:25:38'),
(62, 3, 1, 'CHANGE_PASSWORD', 'User changed own password', '::1', '2026-11-13 18:26:11'),
(63, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:28:57'),
(64, 1, 1, 'CHANGE_ACCOUNT_STATUS', 'Account user ID 3 changed from active to inactive. Reason: fictional', '::1', '2026-11-13 18:29:23'),
(65, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:29:49'),
(66, 1, 1, 'CHANGE_ACCOUNT_STATUS', 'Account user ID 3 changed from inactive to active. Reason: fictional', '::1', '2026-11-13 18:30:17'),
(67, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:30:28'),
(68, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-13 18:30:28'),
(69, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:33:29'),
(70, 1, 2, 'ASSIGN_PATIENT', 'Patient assigned to doctor ID 1', '::1', '2026-11-13 18:34:07'),
(71, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:35:10'),
(72, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-13 18:35:41'),
(73, 1, 2, 'REMOVE_PATIENT_ASSIGNMENT', 'Removed assignment ID 2 for doctor ID 1. Reason: fictional', '::1', '2026-11-13 18:35:58'),
(74, 1, 1, 'RESCHEDULE_APPOINTMENT', 'Appointment ID 2. Old slot: 2026-11-13 23:22:00 to 2026-11-13 23:25:00. New slot: 2026-11-14 23:25:00 to 2026-11-14 23:31:00. Times: IST. Reason: adding emergency number', '::1', '2026-11-13 18:53:59'),
(75, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-14 07:36:05'),
(76, 1, 1, 'DOWNLOAD_REPORT', 'Report downloaded; report ID 1', '::1', '2026-11-14 07:36:30'),
(77, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-14 07:37:15'),
(78, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-14 07:37:17'),
(79, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-14 07:38:10'),
(80, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-14 08:39:02'),
(81, 1, 1, 'UPLOAD_REPORT', 'Report uploaded; report ID 2', '::1', '2026-11-14 08:40:39'),
(82, 1, 1, 'DOWNLOAD_REPORT', 'Report downloaded; report ID 1', '::1', '2026-11-14 08:40:43'),
(83, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-14 08:42:09'),
(84, 2, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-14 08:43:01'),
(85, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-14 08:44:06'),
(86, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-14 08:44:06'),
(87, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-20 06:56:10'),
(88, 1, 1, 'VIEW_PATIENT', 'Opened patient profile', '::1', '2026-11-20 06:56:54'),
(89, 1, 1, 'DOWNLOAD_REPORT', 'Report downloaded; report ID 2', '::1', '2026-11-20 06:57:17'),
(90, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-20 06:58:19'),
(91, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-20 06:58:19'),
(92, 1, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-20 06:59:25'),
(93, 1, 1, 'DOWNLOAD_REPORT', 'Report downloaded; report ID 2', '::1', '2026-11-20 07:00:06'),
(94, 2, NULL, 'LOGIN', 'User logged in', '::1', '2026-11-20 07:00:49'),
(95, 3, 1, 'LOGIN', 'User logged in', '::1', '2026-11-20 07:01:08'),
(96, 3, 1, 'VIEW_PATIENT_PORTAL', 'Patient opened own portal', '::1', '2026-11-20 07:01:08');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`) VALUES
(2, 'Cardiology'),
(5, 'Dermatology'),
(1, 'General Medicine'),
(4, 'Orthopedics'),
(3, 'Pediatrics');

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
--

CREATE TABLE `doctors` (
  `doctor_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `license_no` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`doctor_id`, `user_id`, `department_id`, `specialization`, `license_no`) VALUES
(1, 2, 1, 'General Medicine', 'DEMO-DOC-001');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `document_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_reports`
--

CREATE TABLE `lab_reports` (
  `report_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `visit_id` int(11) DEFAULT NULL,
  `test_name` varchar(150) NOT NULL,
  `report_date` date DEFAULT NULL,
  `result_summary` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lab_reports`
--

INSERT INTO `lab_reports` (`report_id`, `patient_id`, `visit_id`, `test_name`, `report_date`, `result_summary`, `file_path`, `uploaded_by`, `created_at`) VALUES
(1, 1, NULL, 'demo report', '2026-11-13', 'fictional report', 'e24906ede8c0bb84b89ad43c03b927e8.png', 2, '2026-11-13 17:23:14'),
(2, 1, NULL, 'demo report', '2026-11-06', NULL, '5de9938b1ba2767fd43a192d9516c4ea.png', 1, '2026-11-14 08:40:39');

-- --------------------------------------------------------

--
-- Table structure for table `medical_history`
--

CREATE TABLE `medical_history` (
  `history_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `category` enum('Chronic Disease','Surgery','Family History','Lifestyle','Mental Health','Other') NOT NULL,
  `condition_name` varchar(150) NOT NULL,
  `diagnosed_date` date DEFAULT NULL,
  `status` enum('Active','Resolved','Ongoing') DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_history`
--

INSERT INTO `medical_history` (`history_id`, `patient_id`, `category`, `condition_name`, `diagnosed_date`, `status`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 'Chronic Disease', 'heart operation', '2026-11-12', 'Ongoing', NULL, 1, '2026-11-13 14:23:53');

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `mrn` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`patient_id`, `user_id`, `mrn`, `full_name`, `dob`, `gender`, `blood_group`, `phone`, `email`, `address`, `emergency_contact`, `created_at`) VALUES
(1, 3, 'MH05B02FB8DA5924B6', 'Ducati Panigale', '2007-06-25', 'Male', 'B+', '8564792236', 'ducatipanigal123@gmail.com', NULL, NULL, '2026-11-13 14:18:51'),
(2, NULL, 'MH0A6D288CF92DA57A', 'sree', '2026-11-10', 'Male', 'O+', '8985003004', NULL, NULL, '8106345055', '2026-11-13 17:37:03');

-- --------------------------------------------------------

--
-- Table structure for table `patient_assignments`
--

CREATE TABLE `patient_assignments` (
  `assignment_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patient_assignments`
--

INSERT INTO `patient_assignments` (`assignment_id`, `patient_id`, `doctor_id`, `assigned_by`, `created_at`) VALUES
(1, 1, 1, 1, '2026-11-13 14:53:22');

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `prescription_id` int(11) NOT NULL,
  `visit_id` int(11) NOT NULL,
  `medicine_name` varchar(150) NOT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `prescriptions`
--

INSERT INTO `prescriptions` (`prescription_id`, `visit_id`, `medicine_name`, `dosage`, `frequency`, `duration`, `instructions`, `created_at`) VALUES
(1, 1, 'demo medicine', 'demo dose', 'demo frequency', 'demo time', 'fictional', '2026-11-13 15:46:59');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('patient','doctor','admin') NOT NULL DEFAULT 'patient',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password_hash`, `role`, `status`, `created_at`) VALUES
(1, 'System Administrator', 'admin@mhms.com', '$2y$10$Yna4eWwUaEUQkqzS57dmWe/zd0e4gW.SpGS.BOjL4GXO11IKRqCoe', 'admin', 'active', '2026-11-13 14:02:10'),
(2, 'demo doctor', 'doctor1@example.com', '$2y$10$DPjhEmghVWqY38VpQMamFO2j7qCuXaRxZVU58yL3UB4T/wewvpOdu', 'doctor', 'active', '2026-11-13 14:37:40'),
(3, 'Ducati Panigale', 'patient1@example.com', '$2y$10$ZifKh4MB7iMh5M5APSIWS.sKZOHJpdq7lHEHMOPHT9xgLqcOVRlbG', 'patient', 'active', '2026-11-13 18:02:04');

-- --------------------------------------------------------

--
-- Table structure for table `visits`
--

CREATE TABLE `visits` (
  `visit_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `visit_date` date NOT NULL,
  `symptoms` text DEFAULT NULL,
  `diagnosis` varchar(255) DEFAULT NULL,
  `treatment_plan` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `visits`
--

INSERT INTO `visits` (`visit_id`, `patient_id`, `doctor_id`, `visit_date`, `symptoms`, `diagnosis`, `treatment_plan`, `follow_up_date`, `created_at`) VALUES
(1, 1, 1, '2026-11-13', 'fictional symptomes', 'demo assist', 'fictional plan', '2026-11-13', '2026-11-13 15:16:28');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `allergies`
--
ALTER TABLE `allergies`
  ADD PRIMARY KEY (`allergy_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `doctor_schedule` (`doctor_id`,`starts_at`,`ends_at`),
  ADD KEY `patient_schedule` (`patient_id`,`starts_at`,`ends_at`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_name` (`department_name`);

--
-- Indexes for table `doctors`
--
ALTER TABLE `doctors`
  ADD PRIMARY KEY (`doctor_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `lab_reports`
--
ALTER TABLE `lab_reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `visit_id` (`visit_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD UNIQUE KEY `mrn` (`mrn`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `patient_assignments`
--
ALTER TABLE `patient_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD UNIQUE KEY `unique_patient_doctor` (`patient_id`,`doctor_id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`prescription_id`),
  ADD KEY `visit_id` (`visit_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `visits`
--
ALTER TABLE `visits`
  ADD PRIMARY KEY (`visit_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `allergies`
--
ALTER TABLE `allergies`
  MODIFY `allergy_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `doctors`
--
ALTER TABLE `doctors`
  MODIFY `doctor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_reports`
--
ALTER TABLE `lab_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `medical_history`
--
ALTER TABLE `medical_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `patient_assignments`
--
ALTER TABLE `patient_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `prescription_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `visits`
--
ALTER TABLE `visits`
  MODIFY `visit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `allergies`
--
ALTER TABLE `allergies`
  ADD CONSTRAINT `allergies_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`);

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE SET NULL;

--
-- Constraints for table `doctors`
--
ALTER TABLE `doctors`
  ADD CONSTRAINT `doctors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `doctors_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `lab_reports`
--
ALTER TABLE `lab_reports`
  ADD CONSTRAINT `lab_reports_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `lab_reports_ibfk_2` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `lab_reports_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD CONSTRAINT `medical_history_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `medical_history_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `patient_assignments`
--
ALTER TABLE `patient_assignments`
  ADD CONSTRAINT `patient_assignments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `patient_assignments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  ADD CONSTRAINT `patient_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `prescriptions_ibfk_1` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`);

--
-- Constraints for table `visits`
--
ALTER TABLE `visits`
  ADD CONSTRAINT `visits_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `visits_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
