-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 13, 2025 at 06:53 PM
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
-- Database: `arc-hive-maindb`
--

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL COMMENT 'Name of the department',
  `department_type` varchar(50) NOT NULL COMMENT 'Type (e.g., college, office, sub_department)',
  `name_type` varchar(50) NOT NULL COMMENT 'Category (e.g., Academic, Administrative)',
  `parent_department_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent department'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `department_type`, `name_type`, `parent_department_id`) VALUES
(1, 'College of Education', 'college', 'Academic', NULL),
(2, 'College of Arts and Sciences', 'college', 'Academic', NULL),
(3, 'College of Engineering and Technology', 'college', 'Academic', NULL),
(4, 'College of Business and Management', 'college', 'Academic', NULL),
(5, 'College of Agriculture and Forestry', 'college', 'Academic', NULL),
(6, 'College of Veterinary Medicine', 'college', 'Academic', NULL),
(7, 'Bachelor of Elementary Education', 'sub_department', 'Program', 1),
(8, 'Early Childhood Education', 'sub_department', 'Program', 1),
(9, 'Secondary Education', 'sub_department', 'Program', 1),
(10, 'Technology and Livelihood Education', 'sub_department', 'Program', 1),
(11, 'BS Development Communication', 'sub_department', 'Program', 2),
(12, 'BS Psychology', 'sub_department', 'Program', 2),
(13, 'AB Economics', 'sub_department', 'Program', 2),
(14, 'BS Geodetic Engineering', 'sub_department', 'Program', 3),
(15, 'BS Agricultural and Biosystems Engineering', 'sub_department', 'Program', 3),
(16, 'BS Information Technology', 'sub_department', 'Program', 3),
(17, 'BS Business Administration', 'sub_department', 'Program', 4),
(18, 'BS Tourism Management', 'sub_department', 'Program', 4),
(19, 'BS Entrepreneurship', 'sub_department', 'Program', 4),
(20, 'BS Agribusiness', 'sub_department', 'Program', 4),
(21, 'BS Agriculture', 'sub_department', 'Program', 5),
(22, 'BS Forestry', 'sub_department', 'Program', 5),
(23, 'BS Animal Science', 'sub_department', 'Program', 5),
(24, 'BS Food Technology', 'sub_department', 'Program', 5),
(25, 'Doctor of Veterinary Medicine', 'sub_department', 'Program', 6),
(26, 'Admission and Registration Services', 'office', 'Administrative', NULL),
(27, 'Audit Offices', 'office', 'Administrative', NULL),
(28, 'External Linkages and International Affairs', 'office', 'Administrative', NULL),
(29, 'Management Information Systems', 'office', 'Administrative', NULL),
(30, 'Office of the President', 'office', 'Administrative', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `document_type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL COMMENT 'Name of the document type (e.g., Memorandum)',
  `field_name` varchar(50) NOT NULL COMMENT 'Field identifier for the document type',
  `field_label` varchar(255) NOT NULL COMMENT 'Human-readable label for the field',
  `field_type` enum('text','number','date','file') NOT NULL COMMENT 'Data type of the field',
  `is_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether the field is mandatory'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`document_type_id`, `type_name`, `field_name`, `field_label`, `field_type`, `is_required`) VALUES
(1, 'Memorandum', 'memo', 'Memorandum Content', 'text', 1),
(2, 'Letter', 'letter', 'Letter Content', 'text', 1),
(3, 'Notice', 'notice', 'Notice Content', 'text', 1),
(4, 'Announcement', 'announcement', 'Announcement Content', 'text', 1),
(5, 'Invitation', 'invitation', 'Invitation Content', 'text', 1),
(6, 'Sample Type', 'sample', 'Sample Type Content', 'text', 1);

-- --------------------------------------------------------

--
-- Table structure for table `files`
--

CREATE TABLE `files` (
  `file_id` int(11) NOT NULL,
  `parent_file_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent file (e.g., for versions or copies)',
  `file_name` varchar(255) NOT NULL COMMENT 'Name of the file',
  `meta_data` varchar(255) DEFAULT NULL COMMENT 'Optional metadata (e.g., description)',
  `user_id` int(11) NOT NULL COMMENT 'Uploader user ID',
  `upload_date` datetime DEFAULT NULL COMMENT 'File upload timestamp',
  `file_size` int(11) NOT NULL COMMENT 'File size in bytes',
  `file_type` enum('pdf','docx','txt') NOT NULL COMMENT 'File type (e.g., pdf, docx, txt)',
  `document_type_id` int(11) DEFAULT NULL COMMENT 'References document_types.document_type_id',
  `file_status` varchar(50) NOT NULL DEFAULT 'active' COMMENT 'Status (e.g., active, archived)',
  `physical_storage_path` varchar(100) DEFAULT NULL COMMENT 'Hierarchical storage path (e.g., C1/L1/B1/F1)',
  `storage_location` varchar(255) DEFAULT NULL COMMENT 'Physical location description (e.g., Archive Room 101)',
  `storage_capacity` int(11) DEFAULT NULL COMMENT 'Folder capacity (e.g., number of files)',
  `copy_type` varchar(50) DEFAULT NULL COMMENT 'Type of copy (e.g., copy, original)',
  `file_path` varchar(255) NOT NULL COMMENT 'File storage path'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `files`
--

INSERT INTO `files` (`file_id`, `parent_file_id`, `file_name`, `meta_data`, `user_id`, `upload_date`, `file_size`, `file_type`, `document_type_id`, `file_status`, `physical_storage_path`, `storage_location`, `storage_capacity`, `copy_type`, `file_path`) VALUES
(14, NULL, 'Annual Report 2025.pdf', 'Annual report for 2025', 20, '2025-07-10 09:00:00', 1000000, 'pdf', 1, 'active', 'C1/L1/B1/F1', 'Archive Room 101', 50, NULL, 'uploads/annual_report_2025.pdf'),
(15, 14, 'Annual Report 2025 Audit Copy.pdf', 'Audit copy of annual report', 20, '2025-07-11 10:00:00', 1000000, 'pdf', 1, 'active', 'C1/L1/B1/F1', 'Archive Room 101', 50, 'copy', 'uploads/annual_report_2025_audit.pdf'),
(16, 14, 'Annual Report 2025 Executive Copy.pdf', 'Executive copy of annual report', 20, '2025-07-12 11:00:00', 1000000, 'pdf', 1, 'active', 'C1/L1/B1/F1', 'Archive Room 101', 50, 'copy', 'uploads/annual_report_2025_exec.pdf'),
(17, NULL, 'Faculty Evaluation 2025.pdf', 'Faculty evaluation report', 24, '2025-07-15 14:00:00', 500000, 'pdf', NULL, 'active', 'C1/L1/B1/F2', 'Archive Room 101', 50, NULL, 'uploads/faculty_evaluation_2025.pdf'),
(18, NULL, 'Budget Proposal 2025.pdf', 'Budget proposal for 2025', 25, '2025-07-20 08:00:00', 750000, 'pdf', NULL, 'active', 'C1/L1/B1/F2', 'Archive Room 101', 50, NULL, 'uploads/budget_proposal_2025.pdf'),
(19, NULL, 'University Gala Invitation 2025.pdf', 'Gala invitation', 21, '2025-08-01 12:00:00', 200000, 'pdf', 5, 'active', 'C1/L2/B1/F1', 'Archive Room 101', 50, NULL, 'uploads/gala_invitation_2025.pdf'),
(20, 19, 'University Gala Invitation External.pdf', 'External gala invitation', 21, '2025-08-02 13:00:00', 200000, 'pdf', 5, 'active', 'C1/L2/B1/F1', 'Archive Room 101', 50, 'copy', 'uploads/gala_invitation_external.pdf'),
(21, NULL, 'Department Meeting Notice Aug 2025.pdf', 'Meeting notice', 20, '2025-08-06 09:00:00', 150000, 'pdf', 3, 'active', 'C1/L2/B1/F1', 'Archive Room 101', 50, NULL, 'uploads/meeting_notice_aug_2025.pdf'),
(22, 21, 'Department Meeting Notice Audit.pdf', 'Audit copy of meeting notice', 20, '2025-08-06 10:00:00', 150000, 'pdf', 3, 'active', 'C1/L2/B1/F1', 'Archive Room 101', 50, 'copy', 'uploads/meeting_notice_audit.pdf'),
(23, NULL, 'University Announcement 2025.pdf', 'University announcement', 21, '2025-08-07 11:00:00', 300000, 'pdf', 4, 'active', 'C2/L1/B1/F1', 'Archive Room 102', 50, NULL, 'uploads/announcement_2025.pdf'),
(24, NULL, 'Research Proposal 2025.pdf', 'Research proposal', 20, '2025-08-08 12:00:00', 600000, 'pdf', NULL, 'active', 'C2/L1/B1/F1', 'Archive Room 102', 50, NULL, 'uploads/research_proposal_2025.pdf'),
(25, 24, 'Research Proposal External.pdf', 'External research proposal', 20, '2025-08-08 13:00:00', 600000, 'pdf', NULL, 'active', 'C2/L1/B1/F1', 'Archive Room 102', 50, 'copy', 'uploads/research_proposal_external.pdf'),
(26, NULL, 'Budget Allocation Memo 2025.pdf', 'Budget allocation memo', 23, '2025-08-09 14:00:00', 250000, 'pdf', 1, 'active', 'C1/L1/B1/F1', 'Archive Room 101', 50, NULL, 'uploads/budget_memo_2025.pdf'),
(27, NULL, 'CRITIQUE OF PAPER PUBLISHED BY RATHOD.docx', 'Paper critique', 14, NULL, 482059, 'docx', NULL, 'active', 'C1/L1/B1/F1', 'Archive Room 101', 50, NULL, 'uploads/d3994ae5f1d1b75d_CRITIQUEOFPAPERPUBLISHEDBYRATHOD.docx'),
(28, NULL, 'thesis.pdf', 'Thesis document', 14, NULL, 9310558, 'pdf', NULL, 'active', 'C1/L1/B1/F2', 'Archive Room 101', 50, NULL, 'uploads/cde5e08644b1c85d_thesis.pdf'),
(29, 28, 'thesis_copy1.pdf', 'Thesis copy 1', 14, NULL, 9310558, 'pdf', NULL, 'active', 'C1/L1/B1/F2', 'Archive Room 101', 50, 'copy', 'uploads/54a2f12a84e343b5_thesis.pdf'),
(30, 28, 'thesis_copy2.pdf', 'Thesis copy 2', 14, NULL, 9310558, 'pdf', NULL, 'active', 'C1/L1/B1/F2', 'Archive Room 101', 50, 'copy', 'uploads/a6e6517be80d137f_thesis.pdf'),
(31, NULL, 'CamScanner 08-01-2025 17.20.pdf', 'Scanned document', 14, NULL, 570260, 'pdf', NULL, 'active', 'C1/L2/B1/F1', 'Archive Room 101', 50, NULL, 'uploads/8cdbf57b72014f68_CamScanner08-01-202517.20.pdf'),
(32, 28, 'thesis_copy3.pdf', 'Thesis copy 3', 14, NULL, 9310558, 'pdf', NULL, 'active', 'C1/L2/B1/F1', 'Archive Room 101', 50, 'copy', 'uploads/06cf714570d972da_thesis.pdf'),
(33, 28, 'thesis_copy4.pdf', 'Thesis copy 4', 14, NULL, 9310558, 'pdf', NULL, 'active', 'C1/L2/B1/F1', 'Archive Room 101', 50, 'copy', 'uploads/1e08e17f655934a2_thesis.pdf'),
(34, NULL, 'CamScanner 08-01-2025 17.16.pdf', 'Scanned document', 14, NULL, 634638, 'pdf', NULL, 'active', 'C2/L1/B1/F1', 'Archive Room 102', 50, NULL, 'uploads/958acb384be0fa46_CamScanner08-01-202517.16.pdf');

-- --------------------------------------------------------

--
-- Table structure for table `text_repository`
--

CREATE TABLE `text_repository` (
  `content_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL COMMENT 'References files.file_id',
  `extracted_text` text DEFAULT NULL COMMENT 'Extracted text content from file'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'References users.user_id',
  `users_department_id` int(11) DEFAULT NULL COMMENT 'References users_department.users_department_id',
  `file_id` int(11) DEFAULT NULL COMMENT 'References files.file_id',
  `transaction_type` varchar(50) NOT NULL COMMENT 'Type of transaction (e.g., upload, download, login)',
  `transaction_status` varchar(50) NOT NULL COMMENT 'Status (e.g., completed, failed)',
  `transaction_time` datetime NOT NULL COMMENT 'Timestamp of the transaction',
  `description` varchar(255) DEFAULT NULL COMMENT 'Optional description of the transaction'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `user_id`, `users_department_id`, `file_id`, `transaction_type`, `transaction_status`, `transaction_time`, `description`) VALUES
(14, 14, NULL, 27, 'upload', 'completed', '2025-08-01 17:16:00', 'Uploaded CRITIQUE OF PAPER PUBLISHED BY RATHOD.docx'),
(15, 14, NULL, 28, 'upload', 'completed', '2025-08-01 17:16:00', 'Uploaded thesis.pdf'),
(16, 14, NULL, 29, 'upload', 'completed', '2025-08-01 17:16:00', 'Uploaded thesis_copy1.pdf'),
(17, 14, NULL, 30, 'upload', 'completed', '2025-08-01 17:16:00', 'Uploaded thesis_copy2.pdf'),
(18, 14, NULL, 31, 'upload', 'completed', '2025-08-01 17:20:00', 'Uploaded CamScanner 08-01-2025 17.20.pdf'),
(19, 14, NULL, 32, 'upload', 'completed', '2025-08-01 17:20:00', 'Uploaded thesis_copy3.pdf'),
(20, 14, NULL, 33, 'upload', 'completed', '2025-08-01 17:20:00', 'Uploaded thesis_copy4.pdf'),
(21, 14, NULL, 34, 'upload', 'completed', '2025-08-01 17:20:00', 'Uploaded CamScanner 08-01-2025 17.16.pdf'),
(22, 20, NULL, 14, 'upload', 'completed', '2025-07-10 09:00:00', 'Uploaded Annual Report 2025.pdf'),
(23, 20, NULL, 15, 'upload', 'completed', '2025-07-11 10:00:00', 'Uploaded Annual Report 2025 Audit Copy.pdf'),
(24, 20, NULL, 16, 'upload', 'completed', '2025-07-12 11:00:00', 'Uploaded Annual Report 2025 Executive Copy.pdf'),
(25, 24, NULL, 17, 'upload', 'completed', '2025-07-15 14:00:00', 'Uploaded Faculty Evaluation 2025.pdf'),
(26, 25, NULL, 18, 'upload', 'completed', '2025-07-20 08:00:00', 'Uploaded Budget Proposal 2025.pdf'),
(27, 21, NULL, 19, 'upload', 'completed', '2025-08-01 12:00:00', 'Uploaded University Gala Invitation 2025.pdf'),
(28, 21, NULL, 20, 'upload', 'completed', '2025-08-02 13:00:00', 'Uploaded University Gala Invitation External.pdf'),
(29, 20, NULL, 21, 'upload', 'completed', '2025-08-06 09:00:00', 'Uploaded Department Meeting Notice Aug 2025.pdf'),
(30, 20, NULL, 22, 'upload', 'completed', '2025-08-06 10:00:00', 'Uploaded Department Meeting Notice Audit.pdf'),
(31, 21, NULL, 23, 'upload', 'completed', '2025-08-07 11:00:00', 'Uploaded University Announcement 2025.pdf'),
(32, 20, NULL, 24, 'upload', 'completed', '2025-08-08 12:00:00', 'Uploaded Research Proposal 2025.pdf'),
(33, 20, NULL, 25, 'upload', 'completed', '2025-08-08 13:00:00', 'Uploaded Research Proposal External.pdf'),
(34, 23, NULL, 26, 'upload', 'completed', '2025-08-09 14:00:00', 'Uploaded Budget Allocation Memo 2025.pdf'),
(124, 14, NULL, NULL, 'edit_user', 'completed', '2025-08-11 22:56:09', 'Edited user: user'),
(125, 15, NULL, NULL, 'login', 'completed', '2025-08-11 22:56:25', 'User logged in successfully'),
(126, 14, NULL, NULL, 'fetch_document_types', 'completed', '2025-08-11 23:15:26', 'Fetched document type fields'),
(127, NULL, NULL, NULL, 'login', 'failed', '2025-08-12 23:16:36', 'Invalid login attempt for username: ADMIN'),
(128, 14, NULL, NULL, 'login', 'completed', '2025-08-12 23:18:37', 'User logged in successfully'),
(129, NULL, NULL, NULL, 'login', 'failed', '2025-08-12 23:29:08', 'Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)'),
(130, 14, NULL, NULL, 'login', 'completed', '2025-08-12 23:29:54', 'User logged in successfully'),
(131, NULL, NULL, NULL, 'login', 'failed', '2025-08-13 01:45:25', 'Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)'),
(132, 14, NULL, NULL, 'login', 'completed', '2025-08-13 01:45:30', 'User logged in successfully'),
(133, 14, NULL, NULL, 'login', 'completed', '2025-08-13 10:13:13', 'User logged in successfully'),
(134, 14, NULL, NULL, 'login', 'completed', '2025-08-13 10:38:45', 'User logged in successfully'),
(135, NULL, NULL, NULL, 'login', 'failed', '2025-08-13 12:27:14', 'Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)'),
(136, NULL, NULL, NULL, 'login', 'failed', '2025-08-13 12:27:21', 'Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)'),
(137, 14, NULL, NULL, 'login', 'completed', '2025-08-13 12:27:35', 'User logged in successfully'),
(138, NULL, NULL, NULL, 'login', 'failed', '2025-08-13 13:25:58', 'Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)'),
(139, 14, NULL, NULL, 'login', 'completed', '2025-08-13 13:26:04', 'User logged in successfully');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL COMMENT 'Unique username for login',
  `password` varchar(255) NOT NULL COMMENT 'Hashed password',
  `email` varchar(255) DEFAULT NULL COMMENT 'Unique email for user',
  `role` varchar(50) NOT NULL COMMENT 'User role (e.g., admin, user, client)',
  `profile_pic` blob DEFAULT NULL COMMENT 'Optional user profile picture',
  `position` int(11) NOT NULL DEFAULT 0 COMMENT 'Position or rank (0 for default)',
  `created_at` datetime DEFAULT NULL COMMENT 'Account creation timestamp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `role`, `profile_pic`, `position`, `created_at`) VALUES
(1, 'AdminUser', '$2y$10$J3z1b3zK7G6kXz1Y6z3X9uJ7X8z1Y9z2K3z4L5z6M7z8N9z0P1z2', 'admin@example.com', 'admin', NULL, 0, '2025-07-04 10:48:00'),
(10, 'Trevor Mundo', '$2y$10$uv2Q/VDISAkVggfX92u1GeB9SVZRWryEAN0Mq8Cba1ugPtPMNFU8W', 'trevor@example.com', 'client', NULL, 0, '2025-03-19 18:52:12'),
(12, 'ADMIN1234', '$2y$10$TLlND66RAIX9Mo6D3z/Q9eQlbxsrG8ZVAB9ZLqjrTtHpVidVd4ay6', 'admin1234@example.com', 'admin', NULL, 0, '2025-07-04 10:59:00'),
(13, 'newuser', '$2y$10$hW3hp.Ruo.ian6EEUKoADOxGZUX8enOuwdMhjhO.y85jfUkXswS6i', 'newuser@example.com', 'user', NULL, 1, '2025-07-04 11:11:55'),
(14, 'Sgt Caleb Steven A Lagunilla PA (Res)', '$2y$10$NHLno0YjMoh3NRgB4a76HutxvjLjBGz/5/lKEMypNY5MDH2MHiQBe', 'caleb@example.com', 'admin', NULL, 1, '2025-07-04 11:26:39'),
(15, 'user', '$2y$10$OVU0nH8jZ7SIec6iNs8Ate8vuxx7xUSM10YePtoUZxhd0FIz3eRXW', 'user@example.com', 'client', NULL, 1, '2025-07-16 07:03:20'),
(20, 'Mary Johnson', '$2y$10$samplehash1', 'mary@example.com', 'admin', NULL, 1, '2025-07-01 09:00:00'),
(21, 'Robert Lee', '$2y$10$samplehash2', 'robert@example.com', 'user', NULL, 1, '2025-07-01 09:00:00'),
(22, 'Susan Kim', '$2y$10$samplehash3', 'susan@example.com', 'user', NULL, 1, '2025-07-01 09:00:00'),
(23, 'James Brown', '$2y$10$samplehash4', 'james@example.com', 'admin', NULL, 1, '2025-07-01 09:00:00'),
(24, 'Linda Davis', '$2y$10$samplehash5', 'linda@example.com', 'user', NULL, 1, '2025-07-01 09:00:00'),
(25, 'Michael Chen', '$2y$10$samplehash6', 'michael@example.com', 'admin', NULL, 1, '2025-07-01 09:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `users_department`
--

CREATE TABLE `users_department` (
  `users_department_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'References users.user_id',
  `department_id` int(11) NOT NULL COMMENT 'References departments.department_id'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users_department`
--

INSERT INTO `users_department` (`users_department_id`, `user_id`, `department_id`) VALUES
(3, 1, 30),
(1, 10, 3),
(2, 10, 16),
(6, 12, 13),
(7, 12, 26),
(4, 13, 3),
(5, 14, 10),
(8, 15, 16),
(9, 20, 30),
(10, 21, 28),
(11, 22, 28),
(12, 23, 27),
(13, 23, 30),
(14, 24, 29),
(15, 25, 30);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD KEY `idx_parent_department` (`parent_department_id`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`document_type_id`),
  ADD KEY `idx_type_name` (`type_name`);

--
-- Indexes for table `files`
--
ALTER TABLE `files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `idx_parent_file` (`parent_file_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_document_type` (`document_type_id`),
  ADD KEY `idx_physical_storage_path` (`physical_storage_path`);

--
-- Indexes for table `text_repository`
--
ALTER TABLE `text_repository`
  ADD PRIMARY KEY (`content_id`),
  ADD KEY `idx_file_id` (`file_id`),
  ADD FULLTEXT KEY `idx_extracted_text` (`extracted_text`);

--
-- Indexes for table `transactions`
--
CREATE INDEX `idx_user_type_time` ON `transactions` (`user_id`, `transaction_type`, `transaction_time`);

ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_users_department_id` (`users_department_id`),
  ADD KEY `idx_file_id` (`file_id`),
  ADD KEY `idx_transaction_type` (`transaction_type`),
  ADD KEY `idx_transaction_time` (`transaction_time`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `idx_username` (`username`),
  ADD UNIQUE KEY `idx_email` (`email`);

--
-- Indexes for table `users_department`
--
ALTER TABLE `users_department`
  ADD PRIMARY KEY (`users_department_id`),
  ADD UNIQUE KEY `idx_user_department` (`user_id`, `department_id`),
  ADD KEY `idx_department_id` (`department_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `document_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `files`
--
ALTER TABLE `files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `text_repository`
--
ALTER TABLE `text_repository`
  MODIFY `content_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=106;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=140;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `users_department`
--
ALTER TABLE `users_department`
  MODIFY `users_department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_departments_parent` FOREIGN KEY (`parent_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `files`
--
ALTER TABLE `files`
  ADD CONSTRAINT `fk_files_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`document_type_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_files_parent` FOREIGN KEY (`parent_file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chk_physical_storage_path` CHECK (`physical_storage_path` REGEXP '^[A-Z][0-9]+(/[A-Z][0-9]+){3}$');

--
-- Constraints for table `text_repository`
--
ALTER TABLE `text_repository`
  ADD CONSTRAINT `fk_text_repository_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transactions_users_department` FOREIGN KEY (`users_department_id`) REFERENCES `users_department` (`users_department_id`) ON DELETE SET NULL;

--
-- Constraints for table `users_department`
--
ALTER TABLE `users_department`
  ADD CONSTRAINT `fk_users_department_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_users_department_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;