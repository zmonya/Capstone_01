-- Database Backup for arc-hive-maindb
-- Generated: 2025-08-18 19:34:41
-- Type: Automatic

-- Table structure for departments

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(255) NOT NULL COMMENT 'Name of the department',
  `department_type` enum('college','office','sub_department') NOT NULL COMMENT 'Type (e.g., college, office, sub_department)',
  `name_type` enum('Academic','Administrative','Program') NOT NULL COMMENT 'Category (e.g., Academic, Administrative, Program)',
  `parent_department_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent department',
  PRIMARY KEY (`department_id`),
  KEY `idx_parent_department` (`parent_department_id`),
  KEY `idx_department_type` (`department_type`,`name_type`),
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
  `file_status` enum('active','archived','deleted','pending_ocr','ocr_complete') NOT NULL DEFAULT 'active' COMMENT 'Lifecycle status of the file',
  `location_id` int(11) DEFAULT NULL COMMENT 'References storage_locations.location_id',
  `copy_type` enum('original','copy') DEFAULT NULL COMMENT 'Type of copy (e.g., copy, original)',
  `file_path` varchar(255) NOT NULL COMMENT 'File storage path',
  `department_id` int(11) DEFAULT NULL COMMENT 'References departments.department_id',
  PRIMARY KEY (`file_id`),
  KEY `idx_parent_file` (`parent_file_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_document_type` (`document_type_id`),
  KEY `idx_location_id` (`location_id`),
  KEY `idx_file_status` (`file_status`),
  KEY `idx_upload_date` (`upload_date`),
  KEY `idx_file_copy_type` (`copy_type`),
  KEY `idx_file_document_status` (`document_type_id`,`file_status`),
  KEY `fk_files_department` (`department_id`),
  CONSTRAINT `fk_files_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`document_type_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_location` FOREIGN KEY (`location_id`) REFERENCES `storage_locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_parent` FOREIGN KEY (`parent_file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for files
INSERT INTO `files` VALUES ('14',NULL,'Annual Report 2025.pdf','Annual report for 2025','20','2025-07-10 09:00:00','1000000','pdf','1','active',NULL,NULL,'uploads/annual_report_2025.pdf',NULL);
INSERT INTO `files` VALUES ('15','14','Annual Report 2025 Audit Copy.pdf','Audit copy of annual report','20','2025-07-11 10:00:00','1000000','pdf','1','active',NULL,'copy','uploads/annual_report_2025_audit.pdf',NULL);
INSERT INTO `files` VALUES ('16','14','Annual Report 2025 Executive Copy.pdf','Executive copy of annual report','20','2025-07-12 11:00:00','1000000','pdf','1','active',NULL,'copy','uploads/annual_report_2025_exec.pdf',NULL);
INSERT INTO `files` VALUES ('17',NULL,'Faculty Evaluation 2025.pdf','Faculty evaluation report','24','2025-07-15 14:00:00','500000','pdf',NULL,'active',NULL,NULL,'uploads/faculty_evaluation_2025.pdf',NULL);
INSERT INTO `files` VALUES ('18',NULL,'Budget Proposal 2025.pdf','Budget proposal for 2025','25','2025-07-20 08:00:00','750000','pdf',NULL,'active',NULL,NULL,'uploads/budget_proposal_2025.pdf',NULL);
INSERT INTO `files` VALUES ('19',NULL,'University Gala Invitation 2025.pdf','Gala invitation','21','2025-08-01 12:00:00','200000','pdf','5','active',NULL,NULL,'uploads/gala_invitation_2025.pdf',NULL);
INSERT INTO `files` VALUES ('20','19','University Gala Invitation External.pdf','External gala invitation','21','2025-08-02 13:00:00','200000','pdf','5','active',NULL,'copy','uploads/gala_invitation_external.pdf',NULL);
INSERT INTO `files` VALUES ('21',NULL,'Department Meeting Notice Aug 2025.pdf','Meeting notice','20','2025-08-06 09:00:00','150000','pdf','3','active',NULL,NULL,'uploads/meeting_notice_aug_2025.pdf',NULL);
INSERT INTO `files` VALUES ('22','21','Department Meeting Notice Audit.pdf','Audit copy of meeting notice','20','2025-08-06 10:00:00','150000','pdf','3','active',NULL,'copy','uploads/meeting_notice_audit.pdf',NULL);
INSERT INTO `files` VALUES ('23',NULL,'University Announcement 2025.pdf','University announcement','21','2025-08-07 11:00:00','300000','pdf','4','active',NULL,NULL,'uploads/announcement_2025.pdf',NULL);
INSERT INTO `files` VALUES ('24',NULL,'Research Proposal 2025.pdf','Research proposal','20','2025-08-08 12:00:00','600000','pdf',NULL,'active',NULL,NULL,'uploads/research_proposal_2025.pdf',NULL);
INSERT INTO `files` VALUES ('25','24','Research Proposal External.pdf','External research proposal','20','2025-08-08 13:00:00','600000','pdf',NULL,'active',NULL,'copy','uploads/research_proposal_external.pdf',NULL);
INSERT INTO `files` VALUES ('26',NULL,'Budget Allocation Memo 2025.pdf','Budget allocation memo','23','2025-08-09 14:00:00','250000','pdf','1','active',NULL,NULL,'uploads/budget_memo_2025.pdf',NULL);
INSERT INTO `files` VALUES ('27',NULL,'CRITIQUE OF PAPER PUBLISHED BY RATHOD.docx','Paper critique','14',NULL,'482059','docx',NULL,'active',NULL,NULL,'uploads/d3994ae5f1d1b75d_CRITIQUEOFPAPERPUBLISHEDBYRATHOD.docx',NULL);
INSERT INTO `files` VALUES ('28',NULL,'thesis.pdf','Thesis document','14',NULL,'9310558','pdf',NULL,'active',NULL,NULL,'uploads/cde5e08644b1c85d_thesis.pdf',NULL);
INSERT INTO `files` VALUES ('29','28','thesis_copy1.pdf','Thesis copy 1','14',NULL,'9310558','pdf',NULL,'active',NULL,'copy','uploads/54a2f12a84e343b5_thesis.pdf',NULL);
INSERT INTO `files` VALUES ('30','28','thesis_copy2.pdf','Thesis copy 2','14',NULL,'9310558','pdf',NULL,'active',NULL,'copy','uploads/a6e6517be80d137f_thesis.pdf',NULL);
INSERT INTO `files` VALUES ('31',NULL,'CamScanner 08-01-2025 17.20.pdf','Scanned document','14',NULL,'570260','pdf',NULL,'active',NULL,NULL,'uploads/8cdbf57b72014f68_CamScanner08-01-202517.20.pdf',NULL);
INSERT INTO `files` VALUES ('32','28','thesis_copy3.pdf','Thesis copy 3','14',NULL,'9310558','pdf',NULL,'active',NULL,'copy','uploads/06cf714570d972da_thesis.pdf',NULL);
INSERT INTO `files` VALUES ('33','28','thesis_copy4.pdf','Thesis copy 4','14',NULL,'9310558','pdf',NULL,'active',NULL,'copy','uploads/1e08e17f655934a2_thesis.pdf',NULL);
INSERT INTO `files` VALUES ('34',NULL,'CamScanner 08-01-2025 17.16.pdf','Scanned document','14',NULL,'634638','pdf',NULL,'active',NULL,NULL,'uploads/958acb384be0fa46_CamScanner08-01-202517.16.pdf',NULL);
INSERT INTO `files` VALUES ('112',NULL,'arc-hive-maindb.txt',NULL,'15','2025-08-14 14:57:29','12246','txt',NULL,'pending_ocr',NULL,NULL,'uploads/a2a231b9a2c94742_arc-hive-maindb.txt',NULL);
INSERT INTO `files` VALUES ('113',NULL,'461228094_946651137504535_8475665677864111509_n.png',NULL,'15','2025-08-16 16:43:50','24747','png',NULL,'ocr_complete',NULL,NULL,'Uploads/88777dbb9e5b610c_461228094_946651137504535_8475665677864111509_n.png',NULL);
INSERT INTO `files` VALUES ('114',NULL,'461597278_1045444953946557_2039167713515838212_n.png',NULL,'15','2025-08-16 16:59:25','21350','png',NULL,'ocr_complete',NULL,NULL,'Uploads/d6fa36ff3899b9da_461597278_1045444953946557_2039167713515838212_n.png',NULL);
INSERT INTO `files` VALUES ('115',NULL,'Arc-Hive Questionnaires.docx',NULL,'26',NULL,'18062','docx',NULL,'active',NULL,NULL,'uploads/4f40cfec9d5ef071_Arc-HiveQuestionnaires.docx',NULL);

-- Table structure for storage_locations

CREATE TABLE `storage_locations` (
  `location_id` int(11) NOT NULL AUTO_INCREMENT,
  `location_name` varchar(255) NOT NULL COMMENT 'Name of the storage unit (e.g., Building A, Cabinet 1, Folder X)',
  `location_type` enum('college','sub_department','building','room','cabinet','layer','box','folder') NOT NULL COMMENT 'Type of storage unit',
  `department_id` int(11) DEFAULT NULL COMMENT 'References departments.department_id for college/sub_department',
  `parent_location_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent storage location',
  `storage_capacity` int(11) DEFAULT NULL COMMENT 'Capacity (number of files, for folders only)',
  `qr_code` varchar(100) DEFAULT NULL COMMENT 'QR code identifier for the storage unit',
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `idx_qr_code` (`qr_code`),
  KEY `idx_department_id` (`department_id`),
  KEY `idx_parent_location` (`parent_location_id`),
  KEY `idx_location_type` (`location_type`),
  CONSTRAINT `fk_storage_locations_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_storage_locations_parent` FOREIGN KEY (`parent_location_id`) REFERENCES `storage_locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for text_repository

CREATE TABLE `text_repository` (
  `content_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL COMMENT 'References files.file_id',
  `extracted_text` text DEFAULT NULL COMMENT 'Extracted text content from file',
  `word_positions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of word positions for highlighting (e.g., [{"word": "example", "start": 10, "end": 17}, ...])' CHECK (json_valid(`word_positions`)),
  PRIMARY KEY (`content_id`),
  KEY `idx_file_id` (`file_id`),
  FULLTEXT KEY `idx_extracted_text` (`extracted_text`),
  CONSTRAINT `fk_text_repository_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=143 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for text_repository
INSERT INTO `text_repository` VALUES ('107','112',NULL,NULL);
INSERT INTO `text_repository` VALUES ('108','14',NULL,NULL);
INSERT INTO `text_repository` VALUES ('109','15',NULL,NULL);
INSERT INTO `text_repository` VALUES ('110','16',NULL,NULL);
INSERT INTO `text_repository` VALUES ('111','17',NULL,NULL);
INSERT INTO `text_repository` VALUES ('112','18',NULL,NULL);
INSERT INTO `text_repository` VALUES ('113','19',NULL,NULL);
INSERT INTO `text_repository` VALUES ('114','20',NULL,NULL);
INSERT INTO `text_repository` VALUES ('115','21',NULL,NULL);
INSERT INTO `text_repository` VALUES ('116','22',NULL,NULL);
INSERT INTO `text_repository` VALUES ('117','23',NULL,NULL);
INSERT INTO `text_repository` VALUES ('118','24',NULL,NULL);
INSERT INTO `text_repository` VALUES ('119','25',NULL,NULL);
INSERT INTO `text_repository` VALUES ('120','26',NULL,NULL);
INSERT INTO `text_repository` VALUES ('121','27',NULL,NULL);
INSERT INTO `text_repository` VALUES ('122','28',NULL,NULL);
INSERT INTO `text_repository` VALUES ('123','29',NULL,NULL);
INSERT INTO `text_repository` VALUES ('124','30',NULL,NULL);
INSERT INTO `text_repository` VALUES ('125','31',NULL,NULL);
INSERT INTO `text_repository` VALUES ('126','32',NULL,NULL);
INSERT INTO `text_repository` VALUES ('127','33',NULL,NULL);
INSERT INTO `text_repository` VALUES ('128','34',NULL,NULL);
INSERT INTO `text_repository` VALUES ('139','113',NULL,NULL);
INSERT INTO `text_repository` VALUES ('140','113','For Home Agriculture:\n1. “AgriVirtuoso: Al-Driven Virtual Agronomy Advisor\"\nDevelop a web platform that provides personalized agronomy advice to home gardeners and\nsmall-scale farmers. Using Al, the system analyzes user-submitted data (e.g., soil samples, plant\nimages, local weather) to recommend optimal planting schedules, pest management strategies,\n\nand crop rotation plans.\n',NULL);
INSERT INTO `text_repository` VALUES ('141','114',NULL,NULL);
INSERT INTO `text_repository` VALUES ('142','114','3. “PlantPulse: Real-Time Plant Health Monitoring Dashboard\"\nBuild a comprehensive web dashboard that aggregates data from various sources (e.g., user\ninputs, local climate APIs) to monitor the health of home-grown plants. Utilize Al to detect signs\nof stress or disease from user-uploaded images and provide actionable insights to maintain\nplant vitality.\n',NULL);

-- Table structure for transactions

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'References users.user_id',
  `users_department_id` int(11) DEFAULT NULL COMMENT 'References users_department.users_department_id',
  `file_id` int(11) DEFAULT NULL COMMENT 'References files.file_id',
  `transaction_type` enum('upload','download','sent','received','requested','accepted','denied','edited','copied','distributed','retrieve','login','login_success','login_failure','edit_user','fetch_document_types','ocr_process','ocr_retry') NOT NULL COMMENT 'Type of transaction (covers all activity logs)',
  `transaction_status` enum('completed','failed','scheduled','pending') NOT NULL COMMENT 'Status of the transaction',
  `transaction_time` datetime NOT NULL COMMENT 'Timestamp of the transaction',
  `description` varchar(255) DEFAULT NULL COMMENT 'Optional description of the transaction',
  PRIMARY KEY (`transaction_id`),
  KEY `idx_user_type_time` (`user_id`,`transaction_type`,`transaction_time`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_users_department_id` (`users_department_id`),
  KEY `idx_file_id` (`file_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_transaction_time` (`transaction_time`),
  KEY `idx_transaction_activity` (`transaction_type`,`transaction_status`,`transaction_time`),
  CONSTRAINT `fk_transactions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_users_department` FOREIGN KEY (`users_department_id`) REFERENCES `users_department` (`users_department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=179 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
INSERT INTO `transactions` VALUES ('125','15',NULL,NULL,'login_success','completed','2025-08-11 22:56:25','User logged in successfully');
INSERT INTO `transactions` VALUES ('126','14',NULL,NULL,'fetch_document_types','completed','2025-08-11 23:15:26','Fetched document type fields');
INSERT INTO `transactions` VALUES ('127',NULL,NULL,NULL,'login_failure','failed','2025-08-12 23:16:36','Invalid login attempt for username: ADMIN');
INSERT INTO `transactions` VALUES ('128','14',NULL,NULL,'login_success','completed','2025-08-12 23:18:37','User logged in successfully');
INSERT INTO `transactions` VALUES ('129',NULL,NULL,NULL,'login_failure','failed','2025-08-12 23:29:08','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('130','14',NULL,NULL,'login_success','completed','2025-08-12 23:29:54','User logged in successfully');
INSERT INTO `transactions` VALUES ('131',NULL,NULL,NULL,'login_failure','failed','2025-08-13 01:45:25','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('132','14',NULL,NULL,'login_success','completed','2025-08-13 01:45:30','User logged in successfully');
INSERT INTO `transactions` VALUES ('133','14',NULL,NULL,'login_success','completed','2025-08-13 10:13:13','User logged in successfully');
INSERT INTO `transactions` VALUES ('134','14',NULL,NULL,'login_success','completed','2025-08-13 10:38:45','User logged in successfully');
INSERT INTO `transactions` VALUES ('135',NULL,NULL,NULL,'login_failure','failed','2025-08-13 12:27:14','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('136',NULL,NULL,NULL,'login_failure','failed','2025-08-13 12:27:21','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('137','14',NULL,NULL,'login_success','completed','2025-08-13 12:27:35','User logged in successfully');
INSERT INTO `transactions` VALUES ('138',NULL,NULL,NULL,'login_failure','failed','2025-08-13 13:25:58','Invalid login attempt for username: Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `transactions` VALUES ('139','14',NULL,NULL,'login_success','completed','2025-08-13 13:26:04','User logged in successfully');
INSERT INTO `transactions` VALUES ('140','15',NULL,NULL,'login_success','completed','2025-08-14 14:47:19','User logged in successfully');
INSERT INTO `transactions` VALUES ('141','15',NULL,NULL,'upload','completed','2025-08-14 14:47:41','Uploaded arc-hive-maindb.txt');
INSERT INTO `transactions` VALUES ('142','15',NULL,'112','upload','completed','2025-08-14 14:57:29','Uploaded arc-hive-maindb.txt');
INSERT INTO `transactions` VALUES ('143','15',NULL,NULL,'login_success','completed','2025-08-15 12:29:15','User logged in successfully');
INSERT INTO `transactions` VALUES ('144','15',NULL,NULL,'login_success','completed','2025-08-16 16:34:07','User logged in successfully');
INSERT INTO `transactions` VALUES ('145','15',NULL,NULL,'login_success','completed','2025-08-16 16:34:20','User logged in successfully');
INSERT INTO `transactions` VALUES ('146','15',NULL,NULL,'login_success','completed','2025-08-16 16:34:59','User logged in successfully');
INSERT INTO `transactions` VALUES ('147',NULL,NULL,'112','ocr_retry','scheduled','2025-08-16 16:43:03','Retrying OCR processing for file');
INSERT INTO `transactions` VALUES ('148','15',NULL,'113','upload','completed','2025-08-16 16:43:50','Uploaded 461228094_946651137504535_8475665677864111509_n.png');
INSERT INTO `transactions` VALUES ('149',NULL,NULL,'113','ocr_process','completed','2025-08-16 16:43:52','OCR processed for file ID 113');
INSERT INTO `transactions` VALUES ('150','15',NULL,'114','upload','completed','2025-08-16 16:59:25','Uploaded 461597278_1045444953946557_2039167713515838212_n.png');
INSERT INTO `transactions` VALUES ('151',NULL,NULL,'114','ocr_process','completed','2025-08-16 16:59:26','OCR processed for file ID 114');
INSERT INTO `transactions` VALUES ('152',NULL,NULL,NULL,'login_failure','failed','2025-08-17 19:33:48','Invalid login attempt for username: user');
INSERT INTO `transactions` VALUES ('153','15',NULL,NULL,'login_success','completed','2025-08-17 19:33:52','User logged in successfully');
INSERT INTO `transactions` VALUES ('154','1',NULL,NULL,'edit_user','completed','2025-08-18 10:09:10','Added user: testtest');
INSERT INTO `transactions` VALUES ('155','26',NULL,NULL,'','completed','2025-08-18 10:13:52','Searched files with query: dwdw, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('156','26',NULL,NULL,'','completed','2025-08-18 10:13:57','Searched files with query: ka, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('157','26',NULL,NULL,'','completed','2025-08-18 10:13:57','Searched files with query: kar, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('158','26',NULL,NULL,'','completed','2025-08-18 10:13:58','Searched files with query: karl, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('159','26',NULL,NULL,'','completed','2025-08-18 10:13:59','Searched files with query: karl, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('160','26',NULL,NULL,'','completed','2025-08-18 10:14:02','Searched files with query: , type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('161','26',NULL,NULL,'','completed','2025-08-18 10:14:38','Searched files with query: karl, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('162','26',NULL,NULL,'','completed','2025-08-18 10:14:41','Searched files with query: , type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('163','26',NULL,NULL,'','completed','2025-08-18 10:14:43','Searched files with query: test, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('164','26',NULL,NULL,'','completed','2025-08-18 10:14:43','Searched files with query: test, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('165','26',NULL,NULL,'','completed','2025-08-18 10:14:51','Searched files with query: usa, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('166','26',NULL,NULL,'','completed','2025-08-18 10:14:53','Searched files with query: us, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('167','26',NULL,NULL,'','completed','2025-08-18 10:14:55','Searched files with query: , type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('168','26',NULL,NULL,'','completed','2025-08-18 10:14:59','Searched files with query: test, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('169','26',NULL,NULL,'','completed','2025-08-18 10:15:01','Searched files with query: test, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('170','26',NULL,NULL,'','completed','2025-08-18 10:15:03','Searched files with query: , type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('171','26',NULL,NULL,'','completed','2025-08-18 10:15:04','Searched files with query: test, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('172','26',NULL,NULL,'','completed','2025-08-18 10:15:06','Searched files with query: , type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('173','26',NULL,NULL,'','completed','2025-08-18 10:15:15','Searched files with query: sta, type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('174','26',NULL,NULL,'','completed','2025-08-18 10:15:17','Searched files with query: , type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('175','26',NULL,NULL,'','completed','2025-08-18 10:15:18','Searched files with query: , type: , folder: , hardcopy: ');
INSERT INTO `transactions` VALUES ('176','14',NULL,NULL,'edit_user','completed','2025-08-18 10:29:50','Added user: testuser');
INSERT INTO `transactions` VALUES ('177','14',NULL,NULL,'login_success','completed','2025-08-18 19:18:13','User logged in successfully');
INSERT INTO `transactions` VALUES ('178','14',NULL,NULL,'login_success','completed','2025-08-18 19:34:26','User logged in successfully');

-- Table structure for users

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL COMMENT 'Unique username for login',
  `password` varchar(255) NOT NULL COMMENT 'Hashed password',
  `email` varchar(255) DEFAULT NULL COMMENT 'Unique email for user',
  `role` enum('admin','user','client') NOT NULL COMMENT 'User role (e.g., admin, user, client)',
  `profile_pic` blob DEFAULT NULL COMMENT 'Optional user profile picture',
  `position` int(11) NOT NULL DEFAULT 0 COMMENT 'Position or rank (0 for default)',
  `created_at` datetime DEFAULT NULL COMMENT 'Account creation timestamp',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `idx_username` (`username`),
  UNIQUE KEY `idx_email` (`email`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
INSERT INTO `users` VALUES ('26','testtest','$2y$10$MVrPUm2p//zV63IKsr7zruGdi5Q9tra.GIc6yNYVcC.y3rlBuoigO','karlpatrickmandapat940@gmail.com','client','�PNG\r\n\Z\n\0\0\0\rIHDR\0\0\0�\0\0\0�\0\0\0�X��\0\0\0IDATx�]`�=�F\'t���RDA��.�UAT��;J衋��\"H/\"]@_��`�J�\ZBH������HK��Y�y��w�;�g�\0mî�}\r�}\rx)RI\n��k7�	_�t��!�#S�L(U�T�W�Θ�+����ٰ��Il��t�/F�4i���j�\"�R� ���[(I\Z�\nr��ŋ�;wn���O����v�0�,��3��G��4=���K!��ӝٰa��F���Uϟ??�t���8q\"�gώ��z:t��y�f�\Z�̙�k�FÆ\rIS�K�O>��ɓQ�jU|���_���k۶-f͚E\nz�쉝;w�>r�H�Y�f���/2f̨��m߾�RU\Z����b������>>���8p\0�{����ݻ�D���W�^�:t(�N���3g�m���K�,�q\ZttE�i�Cu���;�{�9]��/���_~�E%�8|�p�i�F�\'�|R���ݻ��>�.gϞu�cpMs��A����SO!88��ԋ-��?�������ݱcǐI�9��ի��ԩ�_�gΜA�֭I�ѣG1x�`�7n��͛ѩS\'�X�j��jԨ��s�Y�f�w�x�zg�\Z/�t�~m����OCO?��Jv���_Q�O<��^zI�Ο�g�z�4�����t��I�X�B}�xnw�ޭ�3k��W_�\Zr�ʅ]�v���#<�����{Y^��I7�<\\�z���ؿ���Ə�	��G-d��鵘<����0�)S�P�ڵk��z��|\\�u�O�4	����4h�.0((Hm�AGW�\\9�D8�Xb��\Z��8j,��[���\\+�S�R%�Y�8**\n�|�n8J��1c��~B�&M���q���y���5FG����g�K�Ɛ!C�nDD�����g�E�n�,3����9�q�8g�_�r%U��y̏>�[�lQ[�qN\"ܚ��V�^��,Y��V�ZZ:xB��wT�֭������1m�4���x���?���S�kǹ���k�.7=J�ƨ�U�qMc�����E#00\\�Q�p��1��#G��w`ꐎ�����иqc��ܹs�pT�����۶m�K�*U*x�y��-ݍ>\rJ���e���O�ӧO�K�G\05��=n˖-����=���{/�`���ڵ+:v�^p��iР�sߺu+\\V�re��K�ʕ+��~�z��0��l9}6\nL��C�NX.�����M�1_~�%xM��͋����2eʔ/^�Æ\r�h*�G�L�2a������/T����>�DS���Z�ʿ��[%;��9�������E�F������5k�#:75I͛7�P0��t�S�Z��nZ��Z��# �<����yx�`=\n1fq%��a1�:��������wsq���{�����pI��GB\Z����A�fm+V�΍�]�v-��۷��[;�˒%K��s�u�;���=z���1x��Mxl���-J:�2����6���H\\�~\'�!�-�D\r둂��A=9�um�i=�i2�K���������*��6U�X1}˗o��(e�B����u�47w�\r���/\\�\r��5�5`?�<�{!�I��Are����s�<����yjӸ�;5���bd��,ڸ;��iRy:<wy��VR�\Z�U�MW�h!��ʪ\"A���k%H;I⨀�Q��cH�� J�O���˔C�Ց	�s�Hu�r�ȁ\n�4E�*�Tr!G�4����5��\\��~����@��Qx��F�<9�SR�y�f��$Y������_�����SygA&o>�^ȑ5��\nUh���^�su��:������ �L�Q�P8w:����x&G��!�2 _�*@֒���u��z<[�x\\���yѴuc�Q%�f�5����9̷�sH������sY��e��86u��f9�3UʣC�r��l^����́�uː��y\ZYK5���/�F�β�5bwI��N�篶����x�@��򌆏����\\�D`�%��8���>)z �\n#��\r�q�O��Trq����;-�z�@С_(p-$�N��h��?$j�\\��Oe\\�x7Ϙ���,�\Zaj!,2�A�Q�6����������ǿ���@���|�����3M�TȘ�\0����j�ĺ�W���\r������kX��lZ�\n��_�BO=�c!�+�r��S@���;��.d+Pg����2�l�&=\\�!H�������y�p�bn_���J��<yP$��?���G�t���\Z��D[� _l5Ob�i������bҤ!��N>:�J Wn���aj��Jv�WlǶ�3q��`:���\"�N��zUr�g}�����V|��[!藯`߷�D:7���y+��_�WN���E�p�&�^؃��sص�Dȧ���$��ZD��Lp$v�{����d����`ϩ`,X�#�Jίv�����.:2;�[�I,]�\r^��m�#p0��;�� /24U�ȣ(\rI��A�l/n�u�C�<�w�H���/����=N!h�9�ߘߓ���6�V�#u�T�a����<2�Ov\r�k �k��b��e�6l޲~ֈ��\Z�5�:�(\'�>�:rMt��&TG��b;�s\\58���X��{\"x|LU(FW��E傑�#��1b�HE<6��C� 1D$1X����A9H$1P� ��� rP���I����ޭ�l��l���}*\"[�{�$z�d%zVDV=D\'���^Y�UPd�ZNt�Bg�;�G�#� � s�ۻ�]9dV�G��吩M9dv S�rp�U9dz�BY��,�L-���Bl�-�ě\"�7D�Q����\"c�2&^I4I4i�2�Ф426)�2C��p�UщF\"�_)���.H�r)(^I4�@���K!]��HW�uE�PGt�vI�%^,��/�^K��D\'j���Qij��.�B5ѫ���\Z�ߧ�S=���hjJ�I�:����jr,��t�T,0!uɡ��ݤ�p�k�(G:iP�8��p6�N�����t\ZVC�l��:�v�\"&y�P�\Z��ׅ,��(E�&41Mli�w�u�L�a.OH�_�w�J�$��X40F���>�r%��.n�O��4�S�t���%��褏RM\'mBl�<�N\nC����!M槽���[\Z��`����&�s�XN�I���I�4��[n��r S�%�1p��Q3�Óf¡Gy��#�!ӗ1��Cy���s\"�J*W�Ó$�4I%z���u�=�o�y4������!�d\"K�$ �P�2��QUY�Ts�Gy���dH:�s�����R5�a��dN$C@�B�`���6<�����H��\0Ð|F��18oRu��/=�F\\<ǁ�\'9\r�/*Ð�49���#̈Is���|�À!9���e���EI3C�����(�s� ��2258o\ZtXz@�L)$cbAnL�8�)�k�\\y]�� VJy�,���=�`����1D%�R>�����! ��M�!�Ƀ�3@�i�-^h�d��|,�Q!K�\Z!�p�ۡ3\"p�.MXz�����4��\Z�$�U�x�0`�bxR%�	M��\Z�	�0y1c$`6N^(�tԅk�N>�M�;4ʃ�b�q��:��T\\��:��<գ�4!S��g�&d����U����gz�\"CX�kJ��\Zd^���s%r3\\��P.�`�8U�F[���Uz��%M-�T������]��W�,��l�|nRiVQ�s� CN�&��\"ل ����2�tk�����Q�\\V*%fIQc� ����D�&gH\ZBҩ.�Б_4q:��\\t��*���S�bS�eS\Z`\'P[:i��R�EtmS��pآJmŐF]�;�+�Gi%���N���(���I��\'�&�Š�p�,�����C��NTrpzc��!�.�bٚX�0 �$F�<r���~�.�\"��%%4�\0AK����#L�g�6#�aLPǑ���\\	0TE�*T�d(qp�\Z\Z�{ �M�&�!�-n�{@�q�g@ƈ�&\Zd!�4�ĦB�\'k�!�F�*��@\\��l�_2��!@,��8N� �L)Z�d1&�Z�(��5�A��,L�1�IF�\0\0\0IDAT�&|3f�s������R\'ӕ��9?�	�:1$!m�-��o�7�`�<���Āy3T��9�chk�\n��\"9u�tt�T,�\r\"��I�6�NA���n1M\'�[�-���\0d	蠰$uNMlQc�æ 4@�,NuG��o�9Bn��u��y1FH��-�)M����9�BmvT�VUsE�3��X��:m�<^D�ǅ����M�ZN�s	(�%�.����;t��GY-w1��YT�.��[�<�P������>\Zs��c�C���!X��P��Do!���C��e\0��\\���T����E��&\0���R�K�`Y�aX�N�e퇁X�>\0�:��1�;\rǲNb�w��]�aX�U�`A��:[�<-�0+z�P,�1+z����|�{V8�y�����؂�������|.r廢;���H�S������ܟb���ͩP��.�^<�+����aCX�W�1����T�!1�3ń8�\ZA]��I�!�ɑ����DC\0��V.�&Y ��8��KKǩ�N9њ��r5d9�EQ�F���1�v�8~�z�����\n}�`ߕc��D{�������\'r��c�g�K2V콗�\'�{��+��ދ��$����h�/Rf����0O.?d˖-Q#����C�˒�~sv{��8JBt�N�P[���J�\ZO7��dA�2�J��`P$7ȁ�x ���b�OiT棤S ����z8����b9Tr9���#�9W%\"B���&\ny�D$��^�cS�1rD�9P�ɧ�t�.7\rr�1\r�ت�n\Z��@���ڜ�Oh�N�cΓ��骸>��!��}���%�J�]z\0VWzO�挴�r�yQ@NPx�/�<��7�\\o,�>k^x�A�0)�6�p�U~���Ρ��F��5�dw�A�|�D#r$q;m���HS��>B�:�܉����Uz3&,sH�壡�.󠓰&%�Q>]�:md�LX4�Z>��6�T1�5��Snڴ	&L\0X��Of�<cw�BIϞ=q?c������y��J�|���{��7JH���N!�OF쾸�!\';\Z�+����� �G*L,�k��E�|!���@F�tH-B�l�ѦPC�b&k�~΄\\г��FV�-��,�B�y�+m6|Y�}Ϳ���d�4�Q�?e�x��Ȟ:�nH=�\Z��CSk\'1I-��Ģ$ :�v/)�����q!7�\'�i0�:T\'�\\�2��	S�/�sz�NJ�|%�盃��b��v�M�G�|4��:�Fdz���!����P�F�6����N�wpb����ӧ�h;q����w��\r��)�����`��8��}ɐ��١C�])�&M����n\Z}�AB�.�y[?~\\}ӕ�:�k��Coq͙�;v�ԩS�/A�&��?~w/�}�0k&ȟ�ol@��q5<ų���?�s�F���BZ/�/�.�4��=U&pLhDv��i=�S�0%7AN���g̅���aG����W�G7�e}R�uS���O�ę�A�Sc�\\KBiC.\n����:A�*�%�g�1�mq1\n��ʡ�V�N\Z)\n�����\'T�Æ�\rO��Qc��b�f��@/����C]\Z� �>�P��-�%�h�I\'��#i�Ѽ\Z5j�/_����ƍѼys.\\���o��FcV�_�䅟/_>�W�sV��T�q�۷GӦM����&�8qɼy���>��. �ל��W�lYp�����?|�Z��P*T\0�1v\'�� 2�;u��M��W6�G����s� [��?��ͨp��>\r7�+���K��� �t�~\"|��\n�]�M�����;�S�a�\\�!�rz\r4�����t/㏎?�����������_ql��T�r) 7֋�/��`]�Q$��1��!$�M�9\0�\'B,��*�1�B��c��#�\Z����b��}�ph7�^:�Ȩ(�I�h(߀ܘ��y��(ur�-��� �p�2����P�^U���#�рt֯a��J�U���i���eM�ڦa�?��3���_���~���Or31����v���3��-[�x1���[��#����bI���s�M|����8W���Z��ю�/LΝ�A�X��x2��\"Ҕ��1II���!!q\Z��=�MH�$C�1F	�N�1�@�KD2��+n9�tX��`�t�\n�<�:���fJ����p�!�:��)	9\nM�����f��O�S��`��À�M�H�e�n�ʈ����e�up�!ၠAP��H8o(�d6�bS\'��!��#j�h:y��%E}��m��}\\��4���t��H\'���+%KG��/�9.p�8tk��4��a�Sq}4��EA���4jf\nˠ��w8)��s�\Z�N�3B]���Dw���P%f^��0x��\\����_mJ�͒����Q�	��I����G=)��&�\\�6O�!\'Iﵴ���TU�Ҙ��Nf+ACƘD	�.��h����B��� C`p�0e��4�-,��VS:Q�M0���c�P�Te$ݴ\\y|�0���JERp�#�y *2�~s��\"u\'��]�qC���qM�U]��K!&.^g�ԃT�}\r�Ӷxs�u����b�5I�<7_����|E��r|�<� �;7�̹Ƅ��9�j��\Z�*j��}�-����uA\r�5�}��c������.�>�j���.�PmDgT��ՆwF�aDT茪�;PuH\'T*����N �\r�DWtf���E�\\u@\'T��Q ������O�/�����@�w:��;b*�����tD�ޢ+D���z�M�C���?(��J���\\��0�^.n��&���\"Ec�4]�H3�t�STs<y4b��(i�xi���pPX�Q�|�X���$�բ�����\n�t���TM���9��Hsƨˬ�\Z�pF�Mk�-)�+�K��Y͙��`.�M!����byct3��dtqCn�i��\\�Ʃ;+&c%�����y,]l�JE$N%Q�4L8y�i�£_���4խ8\r�i�A�l�~����������5m0�p-��� OL�� 8�R}�	q��C�*����d��선<�Qgꔤhq��T����g�\Z��&A�]C\"�ѧÃ�N�U)t�B.��\0A���^����Guv��8�����Ч�\"��SZp��|J+	8΢�i����9ɱ�)GcB�E��hE]N��-�!E0�i\Z��hz��k�\\C2�o�4L>�Y�A�HgHuv���k�IA:�A��9�)OL�:��@���S,X7![�J����h	H�E�D�D�I�9H˧��֯�H��x�V^���,!R9t�.���0W�������9��9M|�D1ur9��UhȱY�B�I�䩓���$�4�B�>Gg֏���ub>�]S������qS�J��s��c4&�%��A5���\\b:F�f\'T�ga�7�����:���$*�GI�J7!�d��z@� ̛D�M��;s��	i@xj�3A�u�4�8��)T���QI��VC��7/&pO�����/B����$�B\ZC&ҵ)�1�կ��5.�%E�f�B�1 <=�Hi׈9G��#2���i)Ġ�U�Ai��-]�ET#�s��0x��B�X��\'��R|l�-о�Y>�90@8�Ty>�%�\"2%	J�:I��D:���K<��<*Rb\nBB�w���`̡�zk~q:��&�(L�p�My���k~��!)�2�eP�u�����F�ڜ�H�D(�}�hD;ƥ�0dq�$�u�u4��1��Y��\nǉb�r�1#��q�e�![�hqa�qcƐ���PW��8�PC�u+��9�r�B[y�ԘZ�kȐ��0���\ZB�M>�=���TR���s�b�EKG�\'�3T�p\rA\ZU�1J�g���C8��H���n�!c37B��r8�a\nr��GI��%	uS�ɯ<��z���\\�ʕ1�a�9��\'���P�a��\03�_�0������?3�!�\r�iP�y\r���m�S�1�p/Lʁ\"\"��to(��ψ^��D\'�퍙�s\"}0�y���P�f%E�D��Y��EeD\nf��be9щ�\"�\n�`����`��e29\0\0\0IDAT`�`N%�+��ʻ�SE�\"�j\"s���b��\Z�5E�쇹/��W��xQt�E��y̯�be=�뻠��\r�c>e��X��,xYl�����\0|��\0,x���O���H�5�DS�M�S�f���@��$��$�i�́��AX(���� 8�Rt�m��E��V��E�D[�,n\'�`q��X�~wpAGщN\"-t�%D��X�E��\"-t��.R���P8�3\0K�^\"��\"X�\'\0�Rʾð�B|��N����0,,\'��r�c91px�����O:3f�v\r�k��ܟb�Í��\n����m7���J�N1��}���!�Y��z��r��ہm#�T��J2����\n��nh6Ǯ���7�3t?J��t�cz�|�����4J|8��C�]���=m��o��Ō�1	ޛ��_��\"�����_����5�N���M	��=A�셳�}�p,�M�L��o��v���/^�-K�³�\nbI�\0�UWR?���h��~���K oZ/�����{�n(9��k>�����&����T��S:�������c�5s�b`uL�>\r\r+tDm��+pO�u�O��D������P�n+t�uo��W��R>�+m�`L1?�y�-�u�o�/��������nY(�:wm�P�^��V휈�����+�����ʮ�|Ֆv�ϩ�v�h��x��?���&������̍��>DN�^x�m�c���ƠI����g8�,;*�(�q��+b�-	W��6�W��R���?�Y�-�������ƮѽU��w\n�!�˻�k�Ѥ�T1���V��A��/ۯ��k�f��Ni|�w$�o����<��D��=�j���͋���3p�M��bĨ{��1}:t�q�Z���=m����{���\"-Ŗv��� �[�*��kqe2�h?��𽒯��j�L���iڰ&~X�1v}=��a��y�����g⇦��A�{��ݎ� �ukn��FZ��\'�z�v����#}�����^�]��V��������]��^��n��x{}v�T��A�N;nW�*`o��*�=(�T�� )�L�뼯\n���fJ)�7HJ9��:���c�lBJ��=o���0$7�={6�5p�رd�N���j;n[�{� �͖D�iҘ&�:��~��Փ�u������{�<�ۇKZH����I�L��l��-��P{�T Qm���!(�BS\'>��c�>�yǻEFF�;G|�:]��g��a���X�^��o���j�4i�7֬_n�g/����Q�H���������޸m:�u�;�v����#%\'HT��!��m����k��5��l�����}��d�����D�A�����R$�\"��ܭu.^��?���X���*��7ȃ[��ٮ��U�� wW�$ǲ\'�0�7H���ΒL+`o�dzb�e%L�ɞ5s¬*�gɐ��7�\'�X����z�HTd�G��~�,|�b�v~��)����ؾv��\Z����������pv�xT Qm�#�od�����W�%\'��x���kΔ1I��Rr:\rw\\K�� w��M�+�+`o��Xl�PI�p��\'�j$��Co���\'`)i�� GA�,�����8{nZ��W\"��v�FPD�Ђ�8�w�,��.mT�5���~�[��;.a���w���m� ��f�ڌ�K&��N�����h4Zt\Z�;Ob�Q�Ǝ���h\r\",*��Z��,N]\n��?� 0,a!8���n�±���Bx� ���C�c��@��q� f}�GΛ�E�.\"��:�M��o�F�]E��ѳ����7�b7V��\"R�0p�����oD��M�o�?$Z��%�Y��z����ݏ�P�aS�;��2�P�&/ہ\'��c��0x�>��\'.a����18&�؏rY���7p���:6k��5q���<0~ƧȜ� 6\\ȈMZ#õ@�Y�\0c�~�\"�[���5<����@�<1y�v���LޢFa�bs�u_�5c+Vo��o�͈?uQL�T�����Gjl�|:���Ùle��)�3Y+b��M(�{K�؂w&��edĔE[���=1j�L���N��V�E�W\n��3O�S��X�� &N��Y��D�V51s�B<^�*~OS\'vm�o�[�h�y���d��&�S�۵r���i����k�jX��i�[Nҧ\0=^�x�@���Nώ��\'�����ٟA���в}[TΗ��vU>����S(�����V]=�z\r_o�~�[��F>���f����1/��ۭѫ	��C��++F�����g_����Ұv{y��=�¯@5G6�����/���wztA@��ܳ=<�۽C[<_�.�}���G&/��n���ۻ��um����ī��_:`a�\"�յ:4y�e@�v-���H�A��h6��h^X&��K�\n��%T.;�]�dW{�$�Sj/(!+�\r��\0OOO��^����9\Z��u¾�U���J��C����\'�U�˛9�|�oZv��,A�n��:���8�q�\'N�J�!;(�ľ���Ϸ�l^4\Zou7���n�E��)S�`߾}��㚠oӦNs�S=�՜�i���3��P��*Ӷ�\'��*�Y��w=�m�ĉ��ą���jŷ�	�A\\\'�4/��,��߿��uv��J���;0ǯcǎE�z���?�������D|r�:v�ҥ��͟��#0v� 7_���Y�MU~��;v|6��l�F�\\�X|;�����\\)}|�n�l���ճvˎn��e0m�T7�e�z�擷5��뇧}=���۠�_+���vk׮��ٳ�&���u���a>x�Bg�0�4}z����)Dɧ����Ǽ��a�h���F�W��ĉ�6mZ|���	�A$_�n�իW�=<�;�5�+V 44�A�OQyd��PA��n��*�[>Ie��1�˾[��͇΋��u�4]m�N�T��z�����4sܟ��S�S{w#��?��m���_K��ٝɈ_~阙��7*;\\��k�v*ٍ�ؓ��NT�ڽ�s����b�\ns�G֌U_���*ٹ>�:v)ё���w���8���P�f��ա~��~\r7�A�Ǌ��tơs7�ȫx<M&�Uz2�H/AL��w��<�-K�J��z=��[�V��-�9{�9��R��J�>۰	\r����ݺ)�\Z\"WM�Ƌ~�`?J7�G�\n�7\0�7o��-F�NӘ\r�d�z\r�C^��jK��Ԩ�J��}�J\r����&�[����n�x{0N�o�����*� d�֭ձ:��Reχʕ+;���ci\0όn>��*D�@�Q:��i�F����k�j0Rexx$R�ӵ-�\'�O5ꯁ7�W�W3~�xg~�����`�uNiQ-���֭�C\rY���B_D�䂒9��ÿӵ\rol�?_C���J�0#��^�Tj?��[��1��֣�y#P���\Z��S�� fj��z{{�v&b����=L����6�����O�\n$��^����wt�_6��EF#::���MH���!0�2�m���.�.8d�+:N|�8?�E�e�v�<�أ�� ȳ�W͹r�@�%�����PYo�����@��Ջ��tA�0�����j�ہ��QTt}��My��a�4`w��	�A8�A`\\X��[6�\"�0}��h�u��b�����6A��`�qȑ��G��=[f��Rl���C���:��\0�܃��~ 6���րą{�`H��9����Ç�iy����?`��_1�Y�=7\0[~�7/����!���H��3�s*�T�,9�����	�}^>�6�C]���]�s-v��\'��ڎĐ���<�Icv��*� ��AF�_�4j�Y��A��a�����@��7g�o��-���>Q�\0Qs6U�\\sb\0�żc��A�����|ѪU�sa\r_:G����\ZdȐ!z`�띗�à!Q�U��!U�|hӦ\r�__)EEp�M�It��֚�	�?�\0��G�޽�p� ���\Zi�W�Qv{�H�\r�(`ۮ���@�l�5߳��ɞ9s=�~��a�w5�Y�fwųH��n�ӧOZf�H�5H�\n���$���3���d�uǝ����#�3�µ>���o�\0\0\0IDAT�Y�=���@�m$\\d�p:W��o���=l<L���:��c���X1����Y�\0�:D��yU ����,���[��~ٱ��Ӧ~�+�,Y������Cfj>>�g��=���U��Y8~��;R�~a��Gr�|���?���zh�s��������2x���1�1f���.�;�����??-��ax��rf�o��;�q��~,�E�M�E��hh��k3���(�����+c-�2�u��7��:��R�� �a�~���Eִ�8{!�\n�ϳ���M��\'e�\Z�6O���/]�4Z>��J����7�-5p��A4���2Ξ����S��S�97򿌅�����3�[���ro�5k���!����C�cW�e{��T�O�A3A��6���}���|-��B��*I�,8�Uk�ç.�~�9���U�/W+�!��#k���0߽Wi#.�!3P�`zD�\\E�v=��h짨#��{= �����ϛ�Z�)/Ld�QDG�k�Xz/Ӱ���GBd+ٰ��ɐ�\rE/�+��V�-_I���S]�r�,T���O�4��]Oƪ\"]�\rQ2����7�ϟE\"@�:Ր�P	��񘦬O�x�J\r����V��G*�J%QՆ%Q�DU�Z�J��G68<R�^�rj[/l�/h��g�#K\Zo<%��]������x�/(�Z��i���������*������;� �d�>\\(�H\\:�sܿ����e�:�;��E�Ѐ.��������(�.\\D˥g������?���l���n9������f�_ŷ�~Łkn^���ἳ��ϑƎu�{�;��Z�� �r�+��s�ٳ����<�?%2���z4�C���<��)��g�V\n�}k\0�_\r�.GH������ߎ�NCc�Ñ�΄`��!���+�߇�TK�n���hR�\"^�퍽{���N_\nF�u�_C95����#4\'����C!y��U��w��������_�vb��G���qA���вBkѮ���4[X���7�4�>�+�����|N�JS-�~?�櫀�E�����>�5�c��>���\n��5B�������2b����;x,��n��������l=��b��$/���o*���A����x���w�ą�L�7�V���h��\\��)������o�7������yhױ�Z=�O@�Vo���*�<SW_HC2������<xkݺ5�L߁)���ħ�g�0l�����J����O��x����n/<��c�ۃ��GB$�\n�\0�N+\n���/ S�u�=��W�U�	s=�����Ѣ���ᅹs����[�d�]&����E;bΜY\Z��0gyiѭ��\Z���5F�*~ȕ�yktDKyWiX�lf˘\'��p���s�c��F�L-\"랂⾮����Od�;\\����9�8<��qM^�`�c��z�w(�93?xK>t|�`N����ت%���(��`�r��-[��aS��N�o�Q�� ej|��p��Zx�ǀ~N��ح��[�{�Bܖ����^|4q8�8������ר��:w�*�ɂ��T��9[e\\ݎ����;ͧj���#G\"��ţ�@�lN�������7J������V\0\"/����Qx�ͷѸ��İ:/���^�H7��U���n���)Z�&�A˱��/��o�9���n/�)S��?h�x�J��w�=��(�X�\Zo��d�}�.�D�����޸@u�CF\Z��ޓWqC�s� 2<��Ǝ�º\r\Z4{?��.���%Cq��E��6S��u�Ŋ[�3���3��)ϛ`�S}0��(�\Zm�3S,�{1ã�U������+H��(�酡_�D�.%���ۭ0��+xǿ?��ӡe+�C�W��V�1�_e���#+�E��Cc�{��8��]�:����K��oS��K7��?L��1��6�Ɍ�}��v,��3��q�jm��{oGD�c�|[ti�Q�a���}\'�E�(�RO���}����z�A�|����99���[ݺu����\nx�7NjPP�~Pw:bj]�%�~��a�pZ��o�+W�\\�k>@�n�6e&u/�� _]���E����T��07.aܸq�%�\'}0^�/��	�Ǩ>r�8\\�ظqc*�ǹΝ;WĹ��8��Ȼ�\\��/�z�\Z��OX���W���%빆�T��� ��~����Ǐ7d�ƏC�Q(Z�=��\'F���n}��S������V�B��tӮx�D�����A� �0_\\8����1��6K�CwQ�xo�lٲ!)�.j�FI�k��a�\\�xo�{>�=�v�c�����OeʔI\"ɫٿ�x���7��������r�r�Jv�L��>։K`�=o�>��ή@����A��\'��+`o�G}����O�\rr?U�Ǥ�\n�$Ŝj{��S{��O��1)��I1��^��T�$00�~r�cRZ��zo�AR�J�S�N��ߝ�`��)��� ����37QQQ����a� �]wzt�������Ylx�u�;͔t�q����H��7H\n>�Ia�z��y�g�>~����A��\'��+`o�G}��\'�\n�$Q�{r���y�g�>����]�� wU&��R+`o��z��u�U�\rrWe�I)��I�g�^�]U�� wU&��R+p$�V�^w����AR�)�|/�7ȽT�榸\n�$ŝr{��R{��K�ln��@�� )��N��7H�>=��u�\r�π}�D]{�$��cO�QW�� ����O�HI$Q�{r���I��ŞU\"���Aɉ���8+`o��y^�Y%�\n�$��{\Z���I��b\'I��7Hr=�����I�2�I�k�\r�\\Ϭ�����A��v��Z{�$�3k��V�� �����{�\r��ϐ=�GZ{�<���O��7Hb?C��i�\r�H��hn���7ȝkd3Rp�\r��O���;W�� w���H��7H\n>����\\{�ܹF6��+�lF�$ٜJ{!��yU�s&�\n�$ٜJ{!��yU�s&�\n�$ٜʔ����N{�<�z�GKb�7H;a�tn�\r�p�m-�U�� I���}��7�í�}��\\�8�fo�8�b��\nX�7�U	[������(�ˮ�U{�X���]�8*`o�8�b��\nXH�\rb峥]�dU{�$��i/&�+`o�����/YU�� ��tڋI�\n�$�+j�KV����2���_E�B���`��>\'��\Z�A���]�\\L�p\'$Ι�j�x{{�\'�H����K��I��W�� ��=���N��1P�(��D����/�\'��#��!(� .D�G�����Ux{�����9s�<�5���?8r�:��Z����Cw#�ɥ����2�$�)m��N7�����ȴ�+�f\n��r�!�d��r�������}��X�-Fe����CC��<5�x3� -�5�D�2��ZΓ-� ��ߐ���,W�ȸ�� �6IH(p�aJڮG(P��Ӝ5{6hO�1�����R<tx4j�c�� 꿏#h�>T��\n\r+VD������B�lY��R�(���u��7��ݻ��ϻ�k�.�������G���~i�!������kx^θ��-�\ZyK���\Z�ǉ���Ě\"8�:�v�4����p��p���@d�\r�[P�wUN�8n�cǎ�d�,8|��kة�}6R��-�nD����dg�MI���oNF�1������8�0R!ҍC�d��R�)N6�#<ƍDG�^3�������my�.v����S��p��8ٿ���].�E�-BDD���Jʚ5+2gΌlٲ���={��SO=��͛��������ӯ�^N���r��fE@�\Z8x����#\r���µ�DG܌uL�+,,&��u��1�1��5YW���/�ܙ����z\"��ׁA�@쨛�c�T���D䪩��2p�B���ţ#44���Ĉ\r^Z�7䞌�[0�Jf|���#���08�[8jFG��OqDȏ����0���=�E\\,�Ț~��#:<Z�G�� ���\Z��F�v����kD���m�>>>N��6�M�Nx챜N�_�~�k�F߾}����ɓh6	\0\0UIDAT(V���\"�r�\ni	�(/�<{�ҤC�EQ�VU�?q��A����@0N����9{�cs�p����ܳgO,��ه�N��TȔ��dމarGD06���Ǫ����ވ����Ǳ�BD�gy�v��l���\'n���F@4ڈ�~3?^�7��`p�!����%.�\0��k�!x�E� v;��d��Fh8Nv�\nQR���Dl�}{����+\"4$���V�-wRt!Z�����];:}TV�X�\'p��իƍ�k׮���_���<���s?E�i�Qo��h<����7:/;�7֞���e��	�ዞ��e._��aN��믿�v�Z�i��sU\"��(��2����w�؝�DI\\9�˽v�����������mP����?�	FTT8��\Z�+O���qu�����d��z�*h��3f����0���(~m�NLM�FG�Ƹ\Z�Ϗ�K�|����V��=�y��f���i�AX�[i�=�5�!>�F\\j�!�#�G�����|˙i���Я�����yxr~�v���ߏA��s��d_=\r����� �֭Æ\r�i�&l۶������\r�o-�\'7��G����iQsd[�\n9�l��2��͵�2���;G ����GqK+U�ʔ)�r�ʡ|��(��n��i�D\"��sH��9�;�V��]�Ց(�(܉����=Wo���9�����ŀj���k$�\rr��&I�		��e&Y^��O��o�.lРA�T���+N��>}����3�m��$���yb*���~m����Oq�a�h#G���͛c���7a����\0\0\0��:��\0\0\0IDAT\0����z\0\0\0\0IEND�B`�','3','2025-08-18 10:09:10');
INSERT INTO `users` VALUES ('27','testuser','$2y$10$kh5nsShh8uehS3IXTFWaEOLGnAQXP37ZWGQfhXKVGt16Jy3Aai3w.','testemail@gmail.com','client','�PNG\r\n\Z\n\0\0\0\rIHDR\0\0\0�\0\0\0�\0\0\0�X��\0\0\0IDATx�]	\\T����VEE�B�~ej��de��\\H*�,�,�4�,�,K�R�\\��-eڢI�Vj�W��r�%RSTT����/>�26�,��|�9��sϽ�{���=�F�\Z�1���{@�0�@�h:t\0�9�=`��R�{Wx3f�͛7��9A�J;x��=�*T���z����SF�ƍ�q$�OV��b�Y�f�������q��q�l�2|YYYرcrss�u�Vp��SÊ�0�i�&9զM��\\�r2Q�A����m۶�nݺ� �~�yyyX�z5>�-[� $$$�:s$\'\'�|v���z���$T�X�-�[�J��\'��;���o�I�n��еԺt�=:w�,�J��H�ڵ��\']A�*��I�#P���_\r�{����dq|ɧ8��W(�|�l۶�<P��Wo+LW�J\Z�u�޽@��WI�6z�Ȧ$錂9��C�X,��j�ʲ��:���CR嫤��H\'?�)I��@����Kc,ʟ|�^Iү�@�%�\n��-�^oW�J���Ƚ�UR�&ɏ@�J�����i��\'�jm�G���S�ޟt�)PYAي�ʗ�Fg`������}U�8�=q����Wbc3����\rKvV����i���S\\�v&y��6�C:ɫ���p�?ţ�J*��UV�lE��4�%�/X/�|hc��c��Tϴa���;w.ߗ|������|�_��D�yyJ~���$F>�w�ơC��/}��c?��*Vc�[P�����8�<�K9�M?l��U���q1r��_�3��CA��v}����<��gt����������u8|4����#�2�j��{��N�JP�ő�m$j[X]iً\ZSQuE�W���:���	lL����oФ�d<�����?Ka=�{��Σ��x\nD����/��	?�yմth���.N���7W.#l۱U���h�<4�@�)�%��̷��/�Ʃ_�cמ�X}̊��C�/����OL��	��*A-T�J��-{3�\r�u��V�Y<�tw�wZOl��C���h:v\rZ�^��_Z��/O�_\'Ӱ��^��BƩbxyxp��3b26$}!��*6���G�5�TlG�\"�W@l>`��\'����|_+0�K}l��}�Ͼ8O\r��͟����k`��y?�FA���?�}r\n,�OY�F�OΏC\\��I�-ʨ���}�6�!,���%ީ~�{P���2��g�:&�|\r�Y��\"nk����������\r�v��,OF�)?\n_;�*�S A��$����T6�Tփl��t�H׃l\nű���q��N�yg�#�kL�Ґ�k���$ΆZ�H?�%	���AeB�w@�[���U=�i(�#{��6ĈX	�u{��r\'�bo ך���lX���L�Y-Hjr-���&|�d�RL��K�����(�gE�-\0ժU���_�����&.Ɗ9���|f1\'ML�|�id�KE\n����;s9�\ZNfd���&e�bX��z���s~9rR�þql�=��p&y�G�Ǹ7´u.�7~\\����[�?�9>�Nl3�\\���<�/S^��*\n_�D�j�}1&�	&1ՇPE�U�%[FF:֬Y��\"k⇍?��!;O�ՓD�M���~���fMΑb�	5W�5#�?�U-M����<,}U%)����J�A��$A_.�����8UV�l}���K\\bUZѬ�-�����1g\\_�x�5,a�$Q�^k#�\ZлuJ�2]�����ן9s�x�m���id�K�[��4�-<��e�7�/&%>�^����P�vt~�[4�dE���Q?�c�`�3w��OP\\����v&=��Yʾy�^aӘvhѵ��ۊ��kc�fX=�~����C�Z��h���5�iX[$M�~#���G���M�����y{Y��)AT$���U�	�H$���K?��/��Fͤ�mE+6��/�M:=��c���-Q�������\Z�;&��~�B�:ue;��C�H\'���OtATTM<��jF��R=��t7����%�\r�|�)����ʚp�|8��z�;���g��X=���)�bHwĵ؍ڐ�����b7jC6�ԃl��t�!�69^�&I������P��6���#�\Z~x���ݞ�A��k\0��t� 9��@�0��Sm����RvG\ṇ����T��6ҕ��1���g��1ӛ�;K�!~�j�`¨g�l�c��U��<���g�e�\"��(eW�6��I��U��#�O�H\'PYI����D�T�Co\'�@�J�^}T���%]/I\'(���e�A��T ?�I:��l�CP�¤�!�@���t��N��C�A�$(��6���.v5}[�;�F�O�D�)J�����_�zIv���(�e�n䯯wԕ?I�:}Y�=���E��P������e_ԵZ�j����9��L����r�-�ڿ\Z�m��V������5kք/C��\0��܋2�	⁋�C.98AJ�k������s�{��ȴ���J���؎�����.Z��^Dc{��#<����C�ɟA$\r|`��1慭̀d�D��f��Nc^��H8A$\r|(�����\r��/�N����Zg@�I{\0��@�=���D�5�D��f��Nc^�ZxB� ��J<�Rc��Ԩ�=�NOX%c�1�	Rj�sǞ�\0\'�\'���Y\\��	�2*9�72�	⍫�sr� .��y#� ޸�<\'�1�	�2*9�72��β�\0��|��{��� ��(�N�B�a33@p�f�8A\n!���\01P�	B2�Ob�ēV��Z�p��8�ܡ\'1�	�I��c-1L�z��FG���(�nyA9�-g��p9� .��z��ӧ�p\rV�՛��E0�Ѣ2�p�F#8�1�	\"��]�*��\r��`@&��f3|%;A-��d#���d#DDD�{`�͑�@e=�Ne�)���M-,IGЂ��{?�S!�*�Av*�7`�+�F�\Z�%&O�����3��_OD���8x������;��w?X�i�?�b�)�9��g�z� a#����a���N[��Ӻa�����󤟑�v�����Z�r�ؖ-[��Ϗ�He=�F��|�\Z��#G�\r�����{��B����1w�<|��6jw�#m�̙�}��Hv6F�݌��O����m���z�,�Jг���xYy~>B׼���P���U4�{{�ϔu�xhժbccѠA9<��h�Tփl���a@[�tl� ��w��B�!�$�����vEL���s����q_LFvm��/ދ� +���m��E�ټ;\Z<6�6��y�t���=4�+\Z�j��V-F\\�a����dBRR����S:%�ҕ��|�\Z��������Z6]&Ԯ��ӻ�G\n��� ?�|�����\n��ue��T��b�f�R}��͸�U�*����w|~!U�llcY�	��#�+G�Ssag�g@~H�wB��b���}���X�~�׀>�ą����ۯ8�)ĉ�~��Ŧփl��t�H׃l������{\rʔ)#/=in\nF�:��gg�g@&]*h�ad\'�8�ʇ�	�������N �Eٍ��F��q��Qes��:ǀv�w�;5t��tG��F(���!�*+id�:�HwDQv�:��ijoG�kԨ�����hn?B�51��\\�\0\'�kx�(^�\0\'��.,O�5p���G��p�x���\\�\0\'�kx��(>5WN�Zn���p�8���� >��<Yg�q�1��)8A|j��}��7>N�[�1�	�F��Cq?�0���\'��p5���3���3m���Ul��v5I�\n����r�J����@��͕_���$_g|�����z���]M��Ba�%�ܟ�1���`��[.�53p�\r9Aę�(9�v��r3/b��ċ��맒�D]�����bă��戞�\0�A��i{�Dǫ���a�A��w��+���.;|	n�b<�e�/���D7��u�	�	�i{�Dǫ�Bc4���yz.Jt%�3�d�Y�-���.�r��\r<f��Nc^��H8A$\r|`��1慭̀d�D��f�g@�^\0\0�IDAT��M����p�-99�C�b����>�x��\\���9�������ӑ�k���M¸�s��d�l���V�?���[���g����k���!R{�@�/��V;�9��+�o�X��M�}9j��SK_*ݵ�\'�,��0��2gOnDB�DQ���2~[=_�+^��٩D���!�<�ϟ�B橣�6]����wߐ���/Ii��f䊣\'�Q	9�=Y\'��b\n0���u��o�\r\rE�ٞ3��=��Cz�Lt��Cz���B*:=3\0����� 5�z��l6�8傁�Q�,���H\'h���#@L�X�v\n�/+���,?�l���7h�iX�h6�̀	�xcē��kO���X���C\Z��/���4��yCޤ3l�\'*��r��t+ه��Y�DWH_7	��A�%B ���҇t3�g@�<�>/l��ҥ�4���+0W��TN��\\U�ꁯ|�B ޹_1V��C�qD~ +\'�^� ��g����\Z�O��E4sy�7�\r�7��8���e�\r�]�8w�[Dô�iW�a����v���a�q�E���ߥm�E�%�B���%�m_�5iC�5����?�L���h_`����(�Xqc!P	�YQV��c�\"�\\C�q�U¤	1a��xm|}���àHu�QR��-�Z��4�M<7��yV�_h�DJh�P��h�T�K�Z<����Z�8�@�E�ԭL��\"���\"��8\0C�IIk`(b*f�jY����@�Mj|`\n2`�!m\\b��Kp�\\\"�3`Ā�(�!F��ͷ�3�{�7����� ۱e�0:t��0Arη[�|\\��\'���~��>u�:�c�d��럣M��Cq�GZc~�G�q�㔧?���FZ�Q�n����m�tx]�/��T�1��y.��AEl���cˑ�U��h�$�>y[��{���\'�1v�{Wٰ����1;��!l��<��P�����#0}s�h�	��`�g�d�0�(�����mh�8�:�Abb\"��&#�J57t6���������Ӑ�q�<�)Sf�K>��7ꬸ#�*�C���L]�<A��H�L#\07DН1���Q���^CPP��@���V��E��~�i�K:\"4 a�{\"6��W���M�0~�iW��Gc@�@�#\'WF��\'�^�X<��s���\"+�V�Z�J;*�e���;�����_f�\01���>2v���W\"�ɢ�y��!�O�����B ����%-�h$~:���GvA���J�.�K�h�KO��2m7�f���t\\z��;�\":�~���A�^�:��Õ���\0���chl\r�U���xX�E��5�`�G��v1�S�X�3rP-�]�ϸ!ӱj�j̤��\"�帨��!������\\.����%.�n�0�	h��[j��\Z\"\Zv��n�oŝ�v�Y�p�zsʆ�%���\Z��l �]2��?=>�V���i�I��UKV���=���X��Q�*���\ZP.\Z\"���3a��>z�,�����Ã�/�ǻK>��[B������I ���Z�0�������J�#����>FI�f�����eoe��u-p�\\k��gВ��\r���v��<���3H��p\r3�K	r\ZۗOƒ%Kp�b���7s>fN�P�.����ۢ_�g>�Y���gɺ�3���ä��k/�Q��8������ٱy-��}	�7�~��|C�io���T���2,�?	��o=�o�=�o\'��j��2~Y5_��%؟�P�_E�����3�������+lK\0k�|2E�^\Z,%�v�s�h���!���Εu��f@ǀ�b�Q�������2&qKK8���cxnP_І�ҭ;^���N��=�%�\rh����g�;Z5+�\"P˓q�����^������f���YD�}s$����Dǎ�\'n@Yoo��=A8bڴi�����I ^m6�0�}(� ���7�2�<��!�K�ph�H�z��U4	[\n`FǸ�e&k��ɦ?O�ڕ�\0����8�C:�\Z��&ڿ�����o�)����7��n��hW��sy�YS`.W\rg��{��i ���w=!��ҫ��f�~C>G<-��\n�.�^y�)�����/Y*���Uߋ2=�l�T�pQ��$%�E���x�H��<O�ա��J�ݩV���&�,9����M�U~�#������Aջ!��ɀV�m<�EN�a�}�VM4�\":���,(o2�N�������*�2y�h���e�LS�1�֡fh?�-q��-��\"]��������Abc{�eD=�`���2sf���	����<T<EZqf���A,���W��� �$~ɀVd-W2>�\0\'��o\0�~�hQQQ�brP4u\\[�v|)���ݚN�^\\i3�	R�+���5� n�<<��f���W��/-��/\'H�hb\'_e��WW��],8A�E;�*� ���<�b1�	R,���W���U�x�>�\0\'��-9O�8A�a�}}�N�[r��3h6�\r�p����h���p\'x�X<c�y��e@��h�X@ ]��6��F ]��6�����J����J����J����zIv��F:���A6��F:��;��#{N��Dw�ϭЌ[-���Re�z�NuʿJ�*pDժUA�^v�K�	��A6��F:���A6��U�\\Y�Q�IW���֫̓s���AԂI�����ӣp�&�)?A��%.�LR�ͧ��s�y�E�L�}�*7�>�]�Ma�ӆ�a�^/�6��A����(��J����?s(��N�nȀL��o��K���jŸ�_b��=H��Y�YswH���IX�+�]���+�H��W��j��3Tv����=���E��Ծ��Z���I��wp*�>*�ccc��9������3���v�x�m^ZpZ���O!0�,��ßm,�����,d�]@�*�h��)�>T[&���\nzv���s°�N�	B��PQ�G�p�|����l�2$$$ ))I��?cP�ҙ^Àf\r\n��\" ��Cm�M~ߍ�x����dCj�e�=�ʢ~�n*��ĺ�5�K���(I�H�s��[�\Z�9�Fb�$~0o\r�������z�ޢ�}_� jtj�4?�Av��g��K,�Ŧ2-v��ePYJ��}���6�����K�OI��A�l�%Og@&-�~�I\'��AuF6��ܹ��+�M��z��ʞ�!x��	B˸��]�9��-H-���N����D0����C�z�� �a[�ZÆ\r���ԭ[Wq�ҋ�_w���䩸�N�s���Nw_L_�2�	R��s���\0\'�����T�)U��swg���W��W�hgϞ�79�u/|����\'�����I���u���y��[S��%��`\na��b��� ��(�N�B�a�?b�k\Zs�x�R�D�� ׃U��5p�x�R�D�� ׃U��5p�x�R��DJv�� %�7��ap�x؂�pK�N���{�08A<l�x�%�\0\'H��ͽ�3c�1 �M̀b�D1��0`�Ā61�m�ƍ`0����$\Z��1ƣ`��= �.\0�`�����ʓr� �b��x%� ^��<)W1�	�*&9�W2�	╼�<�NY(f�0����61�mf�=�g�����y�o���v��sDD�=A��\03`Ȁo1�����e8A.s�\Z3p� WP�f�2� ��`���N�+(a3p�N��\\�T�`����\0\0�����Y\0\0\0IDAT\0�1\'2\0\0\0\0IEND�B`�','1','2025-08-18 10:29:50');

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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
INSERT INTO `users_department` VALUES ('16','26','30');
INSERT INTO `users_department` VALUES ('17','27','13');
INSERT INTO `users_department` VALUES ('18','27','26');

