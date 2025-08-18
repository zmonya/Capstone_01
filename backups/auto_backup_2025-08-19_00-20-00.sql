-- Database Backup for arc-hive-maindb
-- Generated: 2025-08-19 00:20:00
-- Type: Automatic

-- Table structure for departments

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(255) NOT NULL COMMENT 'Name of the department',
  `department_type` varchar(50) NOT NULL COMMENT 'Type (e.g., college, office, sub_department)',
  `name_type` varchar(50) NOT NULL COMMENT 'Category (e.g., Academic, Administrative)',
  `parent_department_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent department',
  PRIMARY KEY (`department_id`),
  KEY `idx_parent_department` (`parent_department_id`),
  CONSTRAINT `fk_departments_parent` FOREIGN KEY (`parent_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for departments
INSERT INTO `departments` VALUES ('1','College of Education','college','Academic',NULL);
INSERT INTO `departments` VALUES ('2','College of Arts and Sciences','college','Academic',NULL);
INSERT INTO `departments` VALUES ('3','College of Engineering and Technology','college','Academic',NULL);
INSERT INTO `departments` VALUES ('4','College of Business and Management','college','Academic',NULL);
INSERT INTO `departments` VALUES ('5','College of Agriculture and Forestry','college','Academic',NULL);
INSERT INTO `departments` VALUES ('6','College of Veterinary Medicine','college','Academic',NULL);
INSERT INTO `departments` VALUES ('7','Bachelor of Elementary Education','sub_department','Program','1');
INSERT INTO `departments` VALUES ('8','Early Childhood Education','sub_department','Program','1');
INSERT INTO `departments` VALUES ('9','Secondary Education','sub_department','Program','1');
INSERT INTO `departments` VALUES ('10','Technology and Livelihood Education','sub_department','Program','1');
INSERT INTO `departments` VALUES ('11','BS Development Communication','sub_department','Program','2');
INSERT INTO `departments` VALUES ('12','BS Psychology','sub_department','Program','2');
INSERT INTO `departments` VALUES ('13','AB Economics','sub_department','Program','2');
INSERT INTO `departments` VALUES ('14','BS Geodetic Engineering','sub_department','Program','3');
INSERT INTO `departments` VALUES ('15','BS Agricultural and Biosystems Engineering','sub_department','Program','3');
INSERT INTO `departments` VALUES ('16','BS Information Technology','sub_department','Program','3');
INSERT INTO `departments` VALUES ('17','BS Business Administration','sub_department','Program','4');
INSERT INTO `departments` VALUES ('18','BS Tourism Management','sub_department','Program','4');
INSERT INTO `departments` VALUES ('19','BS Entrepreneurship','sub_department','Program','4');
INSERT INTO `departments` VALUES ('20','BS Agribusiness','sub_department','Program','4');
INSERT INTO `departments` VALUES ('21','BS Agriculture','sub_department','Program','5');
INSERT INTO `departments` VALUES ('22','BS Forestry','sub_department','Program','5');
INSERT INTO `departments` VALUES ('23','BS Animal Science','sub_department','Program','5');
INSERT INTO `departments` VALUES ('24','BS Food Technology','sub_department','Program','5');
INSERT INTO `departments` VALUES ('25','Doctor of Veterinary Medicine','sub_department','Program','6');
INSERT INTO `departments` VALUES ('26','Admission and Registration Services','office','Administrative',NULL);
INSERT INTO `departments` VALUES ('27','Audit Offices','office','Administrative',NULL);
INSERT INTO `departments` VALUES ('28','External Linkages and International Affairs','office','Administrative',NULL);
INSERT INTO `departments` VALUES ('29','Management Information Systems','office','Administrative',NULL);
INSERT INTO `departments` VALUES ('30','Office of the President','office','Administrative',NULL);

-- Table structure for document_types

CREATE TABLE `document_types` (
  `document_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(50) NOT NULL COMMENT 'Name of the document type (e.g., Memorandum)',
  `field_name` varchar(50) NOT NULL COMMENT 'Field identifier for the document type',
  `field_label` varchar(255) NOT NULL COMMENT 'Human-readable label for the field',
  `field_type` enum('text','number','date','file') NOT NULL COMMENT 'Data type of the field',
  `is_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether the field is mandatory',
  PRIMARY KEY (`document_type_id`),
  KEY `idx_type_name` (`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for document_types
INSERT INTO `document_types` VALUES ('1','Memorandum','memo','Memorandum Content','text','1');
INSERT INTO `document_types` VALUES ('2','Letter','letter','Letter Content','text','1');
INSERT INTO `document_types` VALUES ('3','Notice','notice','Notice Content','text','1');
INSERT INTO `document_types` VALUES ('4','Announcement','announcement','Announcement Content','text','1');
INSERT INTO `document_types` VALUES ('5','Invitation','invitation','Invitation Content','text','1');
INSERT INTO `document_types` VALUES ('6','Sample Type','sample','Sample Type Content','text','1');

-- Table structure for files

CREATE TABLE `files` (
  `file_id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_file_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent file (e.g., for versions or copies)',
  `file_name` varchar(255) NOT NULL COMMENT 'Name of the file',
  `meta_data` varchar(255) DEFAULT NULL COMMENT 'Optional metadata (e.g., description)',
  `user_id` int(11) NOT NULL COMMENT 'Uploader user ID',
  `upload_date` datetime DEFAULT NULL COMMENT 'File upload timestamp',
  `file_size` int(11) NOT NULL COMMENT 'File size in bytes',
  `file_type` enum('pdf','docx','txt','png','jpg','jpeg','csv','xlsx') NOT NULL COMMENT 'File type (e.g., pdf, docx, txt, png, jpg, jpeg, csv, xlsx)',
  `document_type_id` int(11) DEFAULT NULL COMMENT 'References document_types.document_type_id',
  `file_status` varchar(50) NOT NULL DEFAULT 'active' COMMENT 'Status (e.g., active, archived)',
  `physical_storage_path` varchar(100) DEFAULT NULL COMMENT 'Hierarchical storage path (e.g., C1/L1/B1/F1)',
  `storage_location` varchar(255) DEFAULT NULL COMMENT 'Physical location description (e.g., Archive Room 101)',
  `storage_capacity` int(11) DEFAULT NULL COMMENT 'Folder capacity (e.g., number of files)',
  `copy_type` varchar(50) DEFAULT NULL COMMENT 'Type of copy (e.g., copy, original)',
  `file_path` varchar(255) NOT NULL COMMENT 'File storage path',
  PRIMARY KEY (`file_id`),
  KEY `idx_parent_file` (`parent_file_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_document_type` (`document_type_id`),
  KEY `idx_physical_storage_path` (`physical_storage_path`),
  CONSTRAINT `fk_files_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`document_type_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_parent` FOREIGN KEY (`parent_file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for files
INSERT INTO `files` VALUES ('14',NULL,'Annual Report 2025.pdf','Annual report for 2025','20','2025-07-10 09:00:00','1000000','pdf','1','active','C1/L1/B1/F1','Archive Room 101','50',NULL,'uploads/annual_report_2025.pdf');
INSERT INTO `files` VALUES ('15','14','Annual Report 2025 Audit Copy.pdf','Audit copy of annual report','20','2025-07-11 10:00:00','1000000','pdf','1','active','C1/L1/B1/F1','Archive Room 101','50','copy','uploads/annual_report_2025_audit.pdf');
INSERT INTO `files` VALUES ('16','14','Annual Report 2025 Executive Copy.pdf','Executive copy of annual report','20','2025-07-12 11:00:00','1000000','pdf','1','active','C1/L1/B1/F1','Archive Room 101','50','copy','uploads/annual_report_2025_exec.pdf');
INSERT INTO `files` VALUES ('17',NULL,'Faculty Evaluation 2025.pdf','Faculty evaluation report','24','2025-07-15 14:00:00','500000','pdf',NULL,'active','C1/L1/B1/F2','Archive Room 101','50',NULL,'uploads/faculty_evaluation_2025.pdf');
INSERT INTO `files` VALUES ('18',NULL,'Budget Proposal 2025.pdf','Budget proposal for 2025','25','2025-07-20 08:00:00','750000','pdf',NULL,'active','C1/L1/B1/F2','Archive Room 101','50',NULL,'uploads/budget_proposal_2025.pdf');
INSERT INTO `files` VALUES ('19',NULL,'University Gala Invitation 2025.pdf','Gala invitation','21','2025-08-01 12:00:00','200000','pdf','5','active','C1/L2/B1/F1','Archive Room 101','50',NULL,'uploads/gala_invitation_2025.pdf');
INSERT INTO `files` VALUES ('20','19','University Gala Invitation External.pdf','External gala invitation','21','2025-08-02 13:00:00','200000','pdf','5','active','C1/L2/B1/F1','Archive Room 101','50','copy','uploads/gala_invitation_external.pdf');
INSERT INTO `files` VALUES ('21',NULL,'Department Meeting Notice Aug 2025.pdf','Meeting notice','20','2025-08-06 09:00:00','150000','pdf','3','active','C1/L2/B1/F1','Archive Room 101','50',NULL,'uploads/meeting_notice_aug_2025.pdf');
INSERT INTO `files` VALUES ('22','21','Department Meeting Notice Audit.pdf','Audit copy of meeting notice','20','2025-08-06 10:00:00','150000','pdf','3','active','C1/L2/B1/F1','Archive Room 101','50','copy','uploads/meeting_notice_audit.pdf');
INSERT INTO `files` VALUES ('23',NULL,'University Announcement 2025.pdf','University announcement','21','2025-08-07 11:00:00','300000','pdf','4','active','C2/L1/B1/F1','Archive Room 102','50',NULL,'uploads/announcement_2025.pdf');
INSERT INTO `files` VALUES ('24',NULL,'Research Proposal 2025.pdf','Research proposal','20','2025-08-08 12:00:00','600000','pdf',NULL,'active','C2/L1/B1/F1','Archive Room 102','50',NULL,'uploads/research_proposal_2025.pdf');
INSERT INTO `files` VALUES ('25','24','Research Proposal External.pdf','External research proposal','20','2025-08-08 13:00:00','600000','pdf',NULL,'active','C2/L1/B1/F1','Archive Room 102','50','copy','uploads/research_proposal_external.pdf');
INSERT INTO `files` VALUES ('26',NULL,'Budget Allocation Memo 2025.pdf','Budget allocation memo','23','2025-08-09 14:00:00','250000','pdf','1','active','C1/L1/B1/F1','Archive Room 101','50',NULL,'uploads/budget_memo_2025.pdf');
INSERT INTO `files` VALUES ('27',NULL,'CRITIQUE OF PAPER PUBLISHED BY RATHOD.docx','Paper critique','14',NULL,'482059','docx',NULL,'active','C1/L1/B1/F1','Archive Room 101','50',NULL,'uploads/d3994ae5f1d1b75d_CRITIQUEOFPAPERPUBLISHEDBYRATHOD.docx');
INSERT INTO `files` VALUES ('28',NULL,'thesis.pdf','Thesis document','14',NULL,'9310558','pdf',NULL,'active','C1/L1/B1/F2','Archive Room 101','50',NULL,'uploads/cde5e08644b1c85d_thesis.pdf');
INSERT INTO `files` VALUES ('29','28','thesis_copy1.pdf','Thesis copy 1','14',NULL,'9310558','pdf',NULL,'active','C1/L1/B1/F2','Archive Room 101','50','copy','uploads/54a2f12a84e343b5_thesis.pdf');
INSERT INTO `files` VALUES ('30','28','thesis_copy2.pdf','Thesis copy 2','14',NULL,'9310558','pdf',NULL,'active','C1/L1/B1/F2','Archive Room 101','50','copy','uploads/a6e6517be80d137f_thesis.pdf');
INSERT INTO `files` VALUES ('31',NULL,'CamScanner 08-01-2025 17.20.pdf','Scanned document','14',NULL,'570260','pdf',NULL,'active','C1/L2/B1/F1','Archive Room 101','50',NULL,'uploads/8cdbf57b72014f68_CamScanner08-01-202517.20.pdf');
INSERT INTO `files` VALUES ('32','28','thesis_copy3.pdf','Thesis copy 3','14',NULL,'9310558','pdf',NULL,'active','C1/L2/B1/F1','Archive Room 101','50','copy','uploads/06cf714570d972da_thesis.pdf');
INSERT INTO `files` VALUES ('33','28','thesis_copy4.pdf','Thesis copy 4','14',NULL,'9310558','pdf',NULL,'active','C1/L2/B1/F1','Archive Room 101','50','copy','uploads/1e08e17f655934a2_thesis.pdf');
INSERT INTO `files` VALUES ('34',NULL,'CamScanner 08-01-2025 17.16.pdf','Scanned document','14',NULL,'634638','pdf',NULL,'active','C2/L1/B1/F1','Archive Room 102','50',NULL,'uploads/958acb384be0fa46_CamScanner08-01-202517.16.pdf');
INSERT INTO `files` VALUES ('112',NULL,'arc-hive-maindb.txt',NULL,'15','2025-08-14 14:57:29','12246','txt',NULL,'pending_ocr',NULL,NULL,NULL,NULL,'uploads/a2a231b9a2c94742_arc-hive-maindb.txt');
INSERT INTO `files` VALUES ('113',NULL,'Portfolio.jpg',NULL,'14','2025-08-18 22:49:33','139439','jpg',NULL,'ocr_failed',NULL,NULL,NULL,NULL,'Uploads/06934fc028f12e8c_Portfolio.jpg');
INSERT INTO `files` VALUES ('114',NULL,'Portfolio.pdf',NULL,'14','2025-08-18 22:50:24','1699994','pdf',NULL,'ocr_failed',NULL,NULL,NULL,NULL,'Uploads/6a928454758a9302_Portfolio.pdf');
INSERT INTO `files` VALUES ('115',NULL,'FileUploadTrends_Report.pdf',NULL,'14','2025-08-18 22:51:21','3981','pdf',NULL,'ocr_failed',NULL,NULL,NULL,NULL,'Uploads/8a1e1b7507ee9b77_FileUploadTrends_Report.pdf');

-- Table structure for text_repository

CREATE TABLE `text_repository` (
  `content_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL COMMENT 'References files.file_id',
  `extracted_text` text DEFAULT NULL COMMENT 'Extracted text content from file',
  PRIMARY KEY (`content_id`),
  KEY `idx_file_id` (`file_id`),
  FULLTEXT KEY `idx_extracted_text` (`extracted_text`),
  CONSTRAINT `fk_text_repository_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=111 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for text_repository
INSERT INTO `text_repository` VALUES ('107','112',NULL);
INSERT INTO `text_repository` VALUES ('108','113',NULL);
INSERT INTO `text_repository` VALUES ('109','114',NULL);
INSERT INTO `text_repository` VALUES ('110','115',NULL);

-- Table structure for transactions

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'References users.user_id',
  `users_department_id` int(11) DEFAULT NULL COMMENT 'References users_department.users_department_id',
  `file_id` int(11) DEFAULT NULL COMMENT 'References files.file_id',
  `transaction_type` varchar(50) NOT NULL COMMENT 'Type of transaction (e.g., upload, download, login)',
  `transaction_status` varchar(50) NOT NULL COMMENT 'Status (e.g., completed, failed)',
  `transaction_time` datetime NOT NULL COMMENT 'Timestamp of the transaction',
  `description` varchar(255) DEFAULT NULL COMMENT 'Optional description of the transaction',
  PRIMARY KEY (`transaction_id`),
  KEY `idx_user_type_time` (`user_id`,`transaction_type`,`transaction_time`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_users_department_id` (`users_department_id`),
  KEY `idx_file_id` (`file_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_transaction_time` (`transaction_time`),
  CONSTRAINT `fk_transactions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_users_department` FOREIGN KEY (`users_department_id`) REFERENCES `users_department` (`users_department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=150 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for transactions
INSERT INTO `transactions` VALUES ('14','14',NULL,'27','upload','completed','2025-08-01 17:16:00','Uploaded CRITIQUE OF PAPER PUBLISHED BY RATHOD.docx');
INSERT INTO `transactions` VALUES ('15','14',NULL,'28','upload','completed','2025-08-01 17:16:00','Uploaded thesis.pdf');
INSERT INTO `transactions` VALUES ('16','14',NULL,'29','upload','completed','2025-08-01 17:16:00','Uploaded thesis_copy1.pdf');
INSERT INTO `transactions` VALUES ('17','14',NULL,'30','upload','completed','2025-08-01 17:16:00','Uploaded thesis_copy2.pdf');
INSERT INTO `transactions` VALUES ('18','14',NULL,'31','upload','completed','2025-08-01 17:20:00','Uploaded CamScanner 08-01-2025 17.20.pdf');
INSERT INTO `transactions` VALUES ('19','14',NULL,'32','upload','completed','2025-08-01 17:20:00','Uploaded thesis_copy3.pdf');
INSERT INTO `transactions` VALUES ('20','14',NULL,'33','upload','completed','2025-08-01 17:20:00','Uploaded thesis_copy4.pdf');
INSERT INTO `transactions` VALUES ('21','14',NULL,'34','upload','completed','2025-08-01 17:20:00','Uploaded CamScanner 08-01-2025 17.16.pdf');
INSERT INTO `transactions` VALUES ('22','20',NULL,'14','upload','completed','2025-07-10 09:00:00','Uploaded Annual Report 2025.pdf');
INSERT INTO `transactions` VALUES ('23','20',NULL,'15','upload','completed','2025-07-11 10:00:00','Uploaded Annual Report 2025 Audit Copy.pdf');
INSERT INTO `transactions` VALUES ('24','20',NULL,'16','upload','completed','2025-07-12 11:00:00','Uploaded Annual Report 2025 Executive Copy.pdf');
INSERT INTO `transactions` VALUES ('25','24',NULL,'17','upload','completed','2025-07-15 14:00:00','Uploaded Faculty Evaluation 2025.pdf');
INSERT INTO `transactions` VALUES ('26','25',NULL,'18','upload','completed','2025-07-20 08:00:00','Uploaded Budget Proposal 2025.pdf');
INSERT INTO `transactions` VALUES ('27','21',NULL,'19','upload','completed','2025-08-01 12:00:00','Uploaded University Gala Invitation 2025.pdf');
INSERT INTO `transactions` VALUES ('28','21',NULL,'20','upload','completed','2025-08-02 13:00:00','Uploaded University Gala Invitation External.pdf');
INSERT INTO `transactions` VALUES ('29','20',NULL,'21','upload','completed','2025-08-06 09:00:00','Uploaded Department Meeting Notice Aug 2025.pdf');
INSERT INTO `transactions` VALUES ('30','20',NULL,'22','upload','completed','2025-08-06 10:00:00','Uploaded Department Meeting Notice Audit.pdf');
INSERT INTO `transactions` VALUES ('31','21',NULL,'23','upload','completed','2025-08-07 11:00:00','Uploaded University Announcement 2025.pdf');
INSERT INTO `transactions` VALUES ('32','20',NULL,'24','upload','completed','2025-08-08 12:00:00','Uploaded Research Proposal 2025.pdf');
INSERT INTO `transactions` VALUES ('33','20',NULL,'25','upload','completed','2025-08-08 13:00:00','Uploaded Research Proposal External.pdf');
INSERT INTO `transactions` VALUES ('34','23',NULL,'26','upload','completed','2025-08-09 14:00:00','Uploaded Budget Allocation Memo 2025.pdf');
INSERT INTO `transactions` VALUES ('124','14',NULL,NULL,'edit_user','completed','2025-08-11 22:56:09','Edited user: user');
INSERT INTO `transactions` VALUES ('125','15',NULL,NULL,'login','completed','2025-08-11 22:56:25','User logged in successfully');
INSERT INTO `transactions` VALUES ('126','14',NULL,NULL,'fetch_document_types','completed','2025-08-11 23:15:26','Fetched document type fields');
INSERT INTO `transactions` VALUES ('127',NULL,NULL,NULL,'login','failed','2025-08-12 23:16:36','Invalid login attempt for username: ADMIN');
INSERT INTO `transactions` VALUES ('128','14',NULL,NULL,'login','completed','2025-08-12 23:18:37','User logged in successfully');
INSERT INTO `transactions` VALUES ('129',NULL,NULL,NULL,'login','failed','2025-08-12 23:29:08','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('130','14',NULL,NULL,'login','completed','2025-08-12 23:29:54','User logged in successfully');
INSERT INTO `transactions` VALUES ('131',NULL,NULL,NULL,'login','failed','2025-08-13 01:45:25','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('132','14',NULL,NULL,'login','completed','2025-08-13 01:45:30','User logged in successfully');
INSERT INTO `transactions` VALUES ('133','14',NULL,NULL,'login','completed','2025-08-13 10:13:13','User logged in successfully');
INSERT INTO `transactions` VALUES ('134','14',NULL,NULL,'login','completed','2025-08-13 10:38:45','User logged in successfully');
INSERT INTO `transactions` VALUES ('135',NULL,NULL,NULL,'login','failed','2025-08-13 12:27:14','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('136',NULL,NULL,NULL,'login','failed','2025-08-13 12:27:21','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('137','14',NULL,NULL,'login','completed','2025-08-13 12:27:35','User logged in successfully');
INSERT INTO `transactions` VALUES ('138',NULL,NULL,NULL,'login','failed','2025-08-13 13:25:58','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('139','14',NULL,NULL,'login','completed','2025-08-13 13:26:04','User logged in successfully');
INSERT INTO `transactions` VALUES ('140','15',NULL,NULL,'login_success','','2025-08-14 14:47:19','User logged in successfully');
INSERT INTO `transactions` VALUES ('141','15',NULL,NULL,'upload','completed','2025-08-14 14:47:41','Uploaded arc-hive-maindb.txt');
INSERT INTO `transactions` VALUES ('142','15',NULL,'112','upload','completed','2025-08-14 14:57:29','Uploaded arc-hive-maindb.txt');
INSERT INTO `transactions` VALUES ('143','15',NULL,NULL,'login_success','','2025-08-15 12:29:15','User logged in successfully');
INSERT INTO `transactions` VALUES ('144','14',NULL,'113','upload','completed','2025-08-18 22:49:33','Uploaded Portfolio.jpg');
INSERT INTO `transactions` VALUES ('145',NULL,NULL,'113','ocr_process','failed','2025-08-18 22:49:35','OCR failed for file ID 113');
INSERT INTO `transactions` VALUES ('146','14',NULL,'114','upload','completed','2025-08-18 22:50:24','Uploaded Portfolio.pdf');
INSERT INTO `transactions` VALUES ('147',NULL,NULL,'114','ocr_process','failed','2025-08-18 22:50:24','OCR failed for file ID 114');
INSERT INTO `transactions` VALUES ('148','14',NULL,'115','upload','completed','2025-08-18 22:51:21','Uploaded FileUploadTrends_Report.pdf');
INSERT INTO `transactions` VALUES ('149',NULL,NULL,'115','ocr_process','failed','2025-08-18 22:51:22','OCR failed for file ID 115');

-- Table structure for users

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL COMMENT 'Unique username for login',
  `password` varchar(255) NOT NULL COMMENT 'Hashed password',
  `email` varchar(255) DEFAULT NULL COMMENT 'Unique email for user',
  `role` varchar(50) NOT NULL COMMENT 'User role (e.g., admin, user, client)',
  `profile_pic` blob DEFAULT NULL COMMENT 'Optional user profile picture',
  `position` int(11) NOT NULL DEFAULT 0 COMMENT 'Position or rank (0 for default)',
  `created_at` datetime DEFAULT NULL COMMENT 'Account creation timestamp',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `idx_username` (`username`),
  UNIQUE KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for users
INSERT INTO `users` VALUES ('1','AdminUser','$2y$10$J3z1b3zK7G6kXz1Y6z3X9uJ7X8z1Y9z2K3z4L5z6M7z8N9z0P1z2','admin@example.com','admin',NULL,'0','2025-07-04 10:48:00');
INSERT INTO `users` VALUES ('10','Trevor Mundo','$2y$10$uv2Q/VDISAkVggfX92u1GeB9SVZRWryEAN0Mq8Cba1ugPtPMNFU8W','trevor@example.com','client',NULL,'0','2025-03-19 18:52:12');
INSERT INTO `users` VALUES ('12','ADMIN1234','$2y$10$TLlND66RAIX9Mo6D3z/Q9eQlbxsrG8ZVAB9ZLqjrTtHpVidVd4ay6','admin1234@example.com','admin',NULL,'0','2025-07-04 10:59:00');
INSERT INTO `users` VALUES ('13','newuser','$2y$10$hW3hp.Ruo.ian6EEUKoADOxGZUX8enOuwdMhjhO.y85jfUkXswS6i','newuser@example.com','user',NULL,'1','2025-07-04 11:11:55');
INSERT INTO `users` VALUES ('14','Sgt Caleb Steven A Lagunilla PA (Res)','$2y$10$NHLno0YjMoh3NRgB4a76HutxvjLjBGz/5/lKEMypNY5MDH2MHiQBe','caleb@example.com','admin',NULL,'1','2025-07-04 11:26:39');
INSERT INTO `users` VALUES ('15','user','$2y$10$OVU0nH8jZ7SIec6iNs8Ate8vuxx7xUSM10YePtoUZxhd0FIz3eRXW','user@example.com','admin',NULL,'1','2025-07-16 07:03:20');
INSERT INTO `users` VALUES ('20','Mary Johnson','$2y$10$samplehash1','mary@example.com','admin',NULL,'1','2025-07-01 09:00:00');
INSERT INTO `users` VALUES ('21','Robert Lee','$2y$10$samplehash2','robert@example.com','user',NULL,'1','2025-07-01 09:00:00');
INSERT INTO `users` VALUES ('22','Susan Kim','$2y$10$samplehash3','susan@example.com','user',NULL,'1','2025-07-01 09:00:00');
INSERT INTO `users` VALUES ('23','James Brown','$2y$10$samplehash4','james@example.com','admin',NULL,'1','2025-07-01 09:00:00');
INSERT INTO `users` VALUES ('24','Linda Davis','$2y$10$samplehash5','linda@example.com','user',NULL,'1','2025-07-01 09:00:00');
INSERT INTO `users` VALUES ('25','Michael Chen','$2y$10$samplehash6','michael@example.com','admin',NULL,'1','2025-07-01 09:00:00');

-- Table structure for users_department

CREATE TABLE `users_department` (
  `users_department_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'References users.user_id',
  `department_id` int(11) NOT NULL COMMENT 'References departments.department_id',
  PRIMARY KEY (`users_department_id`),
  UNIQUE KEY `idx_user_department` (`user_id`,`department_id`),
  KEY `idx_department_id` (`department_id`),
  CONSTRAINT `fk_users_department_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_users_department_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for users_department
INSERT INTO `users_department` VALUES ('3','1','30');
INSERT INTO `users_department` VALUES ('1','10','3');
INSERT INTO `users_department` VALUES ('2','10','16');
INSERT INTO `users_department` VALUES ('6','12','13');
INSERT INTO `users_department` VALUES ('7','12','26');
INSERT INTO `users_department` VALUES ('4','13','3');
INSERT INTO `users_department` VALUES ('5','14','10');
INSERT INTO `users_department` VALUES ('8','15','16');
INSERT INTO `users_department` VALUES ('9','20','30');
INSERT INTO `users_department` VALUES ('10','21','28');
INSERT INTO `users_department` VALUES ('11','22','28');
INSERT INTO `users_department` VALUES ('12','23','27');
INSERT INTO `users_department` VALUES ('13','23','30');
INSERT INTO `users_department` VALUES ('14','24','29');
INSERT INTO `users_department` VALUES ('15','25','30');

