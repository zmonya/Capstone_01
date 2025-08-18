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
INSERT INTO `text_repository` VALUES ('140','113','For Home Agriculture:\n1. â€œAgriVirtuoso: Al-Driven Virtual Agronomy Advisor\"\nDevelop a web platform that provides personalized agronomy advice to home gardeners and\nsmall-scale farmers. Using Al, the system analyzes user-submitted data (e.g., soil samples, plant\nimages, local weather) to recommend optimal planting schedules, pest management strategies,\n\nand crop rotation plans.\n',NULL);
INSERT INTO `text_repository` VALUES ('141','114',NULL,NULL);
INSERT INTO `text_repository` VALUES ('142','114','3. â€œPlantPulse: Real-Time Plant Health Monitoring Dashboard\"\nBuild a comprehensive web dashboard that aggregates data from various sources (e.g., user\ninputs, local climate APIs) to monitor the health of home-grown plants. Utilize Al to detect signs\nof stress or disease from user-uploaded images and provide actionable insights to maintain\nplant vitality.\n',NULL);

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
INSERT INTO `users` VALUES ('26','testtest','$2y$10$MVrPUm2p//zV63IKsr7zruGdi5Q9tra.GIc6yNYVcC.y3rlBuoigO','karlpatrickmandapat940@gmail.com','client','‰PNG\r\n\Z\n\0\0\0\rIHDR\0\0\0È\0\0\0È\0\0\0­X®ž\0\0\0IDATxì]`Õ=“F\'t…ÀÇRDA¥÷.ÒUATÞ;Jè¡‹€€\"H/\"]@_é¢`¤J„\ZBHý÷ÜÙÙì’HK€”YÞy·wç½;ó¶gñ\0mÃ®}\rÄ}\rx)RI\nók7»	_téÒ!©#S¦L(UªTÂWÇÎ˜â+àñÃëÙ°±ÒIlìðt’/Fš4iÜÖðj•\"èR§ Òøð™¤[(I\Z…\nr›÷Å‹‘;wn†áæOŠ†ëÚv¡0ñ,þÆ3ÈÏGº4=¾„ÏK!ÍÛÓÙ°aƒê…FûöíUÏŸ??ºtéúè˜8q\"²gÏŽ·Þz:tÀ¼yó°fÍ\ZäÌ™µk×FÃ†\rISKå“O>ÁäÉ“QµjU|ðÁ¨_¿¾ækÛ¶-fÍšE\nzöì‰;wª>räH•Y³f…¯¯/2fÌ¨¶Õmß¾ÝRU\Z†û…òb±Ìø°ùã¸>>§ÆÙ8p\0½{÷¦ŠîÝ»£D‰ ¯W¯^ê:t(¦NŠ™3gªmÍÁ’K–,Ñq\ZttEäiªCu¿ÿþ;ž{î9]ãË/¿¬±_~ùE%×8|øp´iÓFí\'Ÿ|R¥ÕíÝ»÷¶>ó°.gÏžuò¬cpMsæÌAÁ‚ñÔSO!88‡ÖÔ‹-ÂÏ?ÿ¬ú÷ß¯’Ý±cÇI‰9§êÕ«ëœëÔ©ƒ_ýgÎœAëÖ­IÃÑ£G1xð`­7nÄæÍ›Ñ©S\'šXµj•ÆjÔ¨¹sç¢Y³fêwíxÝzgæ\Z/‰tÈ~mž„ÆÁOCO?ý´JvÌù×_QÅO<—^zI¯ÎŸ×g½zõ4öñÇëõtòäI¬X±B}ìxnwïÞ­×3kõÕW_é\ZråÊ…]»véüÉ#<†èÙ˜{Y^¬ÖI7ƒ<\\½z‘‘‘Ø¿¿ÚìÆ¯	üñG-dúôéµ˜<ŸŸ¹0òˆ)S¦PàÚµkšÇzÝÀ|\\¬uñOš4	¡¡¡Ê4h.0((HmÎAGW®\\9ðD8ÌXbÓå\Zø¥8j,û[ÌÃÃ\\+×S©R%Y›8**\nß|ón8J‚í˜1cÀó§Ÿ~B“&MÀŠ’qÜüÔyïÎÀ5FGó½àùçŸg¥K—Æ!CœnDD„ú­îÙgŸE·nÝ,3–äÉç9ºqã8gÈ_¹r%U½ØyÌ>ú[¶lQ[ÒqN\"ÜšV¯^­¾,Y² V­ZZ:xBÉ“wTëÖ­£éÄòåË1mÚ4µ­óxáÂœ?ûöíS¿kÇ¹¿öÚk®.7=J®Æ¨ŒUëqMcáááàõE#00\\ç¨Q£pìØ1º†#GŽ€w`êŽµáõÄóÐ¸qcñ˜íÜ¹sªpT¬ëËËËÛ¶m£K‘*U*xôy¹ú-Ý>\rJª“ïeþüóOÝÓ§O§KËG\05¤ã=nË–-•Ã€á=„À{/«`´ÉíÚµ+:vì^p¼ÇiÐ ¸sßºu+\\V¹reºÌKåÊ•+±°~ýz§Ï0ÌÍl9}6\nLŸ‰CÇNX.ð¡œ›ˆùé£MŒ1_~ù%xMÛçÍ‹–›„2eÊ”/^äÃ†\r³h*­G¨L™2aöìÙêûâ‹/TÞÚñ˜>ëDS·ðá‡ZªÊ¿ÿþ[%;æå9òöö¦©àE£Fô‚’üš5kê#:75IÍ›7§P0¦Št¼S«ZµªnZÞÛZÇæ# ý<§¼ø¸yxç`=\n1fq%Þa1Æ:½ûî»àé¿óçÏwsq®–Ã{à½žøËpIÝÔGB\ZäÎÀAÐfm+V¬Îó]»v-ÝèÛ·¯Ê[;žË’%Kª›såuÌ;Êþù=zôÐú1xóæMxl½àƒ-J:“2òäÉã6ý¨¨H\\¿~\'ä!Ö-D\rë‘‚Óç£A=9Àum‰i=‰i2ñK†À‡Åøæ±ÇÛ°*¬6U¬X1}Ë—oûÚ(e×BÞþÏu47w‚\r»¡ç/\\‚\r»ö5÷5`?‚<„{!ûI·ºAre÷ý×¤s‰<–÷ößyjÓ¸œ;5ËášÏbd´‡,Ú¸;à•iRy:<wy³§VR\ZÕUÞMW´h!¥•Êª\"AºÆõk%H;Iâ¨€§QªàcH›« JÔO®Ž§Ë”C‡Õ‘	ÀsçHuéräÈ\n¯4E£*ùTr!Gæ4¦ž¥Ú5¯«\\ò¨ä~æåãù@îâQx¤õFþ<9äSR yóf¨ñ$Y€—§‚¥_Â­Ì÷éSygA&o>ž^È‘5“’\nUh€‚Õ^Åsu›âµ:ÏëøæÍå ÙLÞQ©P8w:üöÛïx&G¤ó’!©2 _É*@Ö’¨Ûüu¼õz<[»x\\‰â™üyÑ´uc­Q%òf×5°¾©å˜9Ì·ŒsH®Æõ«¡îsYðâ‹eñ¼ð86uö¼f9Æ3UÊ£CÓr¨ül^œ”÷ë«ÌæuË†ÿy\ZYK5À–Æ/•FÓÎ²ù5bwI¡·Nòç¯¶ãø©½x¾@¤ÉòŒ†ž„á“ÿ\\D`à%ü×8ÿí>)z Ò\n#ðÒ\rÕqñOüé‡TrqòÓÎô;-ŸzŠ@Ð¡_(p-$ÇNª¾hÑ¬?$jÆ\\ˆøOe\\»x7Ï˜Ÿ¼Ö,ž\Zaj!,2A—Q¶6¿’áƒã»÷àùáøêÇ¿Àñ‹­@ËÏà|šØòºÎã€3MšTÈ˜¡\0Žÿ¾¥j–ÄºÏWâüÞ\rØûí—µÔkX¼ælZ¶\n¿­_‹BO=¦c!·+¡rÌÀS@ŽÂê;òË.d+Pg·ý¿Ç2 lÙ&=\\ó!H›Ú«ÖýŠœyýpõbn_ÄîŸöJ¦ü<yP$ðÏ?ÇðÙG±tªû‡\Z´»D[Ý _l5ObÈiÊÃ¿„…×bÒ¤!—‰N>:ìªJ WnÀµëaj‡à´Jv³WlÇ¶¥3qÓñ­‰`:ˆ±\"£N‡¸zUrýg}ßþŽßV|ˆµ[!è—¯`ß·üD:7¯ÅÜy+êŒÍ_µWNþí´ÃE»pí&®^ØƒÈÐsØµôDÈ§¢ßþ$³íZD…ãLp$v‰{‘—³Ôd’û©`Ï©`,Xþ#öJÎ¯vÁÁËÕÏ.:2;¿[ÞI,]³\r^¸†m§#p0ˆó;¬Ç /24UëÈ£(\rI¦ºAôl/nuˆC±<·w„HøÀÖ/¥ôíâ=N!h—9ïß˜ß“ºÇá6ýVÀ#uêT°a×À¾â¾<2¤Ov\rìk îkÀã‰b•°eë6lÞ²~ÖˆÁä\ZÈ5¹:ü(\'‰>©:rMtà‘&TGÎñb;s\\58ñ¾èÄX‘Ä{\"x|LU(FWÃã£Eå‚‘¢#ªâ1b¸HE<6Ì¢C« 1D$1XäàÊÈA9H$1P¤ û€Ê rPöðIô«„ìÞ­„l‚ì‚lïˆÞ×}*\"[±{‹$z‰d%zVDV=D\'º‹ì^Y»UPdéZNtÝBgÑ;•GË#³ ‹ sÑÛ» ]9dV”G¦¶å©M9dv Sërp¢U9dzÛBYø¶,‹L-ÅéÛBlâ-‘Ä›\"‰7D¾Q¾”ÍË\"có2&^I4I4iáµ2ÈÐ¤426)£2CãÒpâUÑ‰F\"é_)…¯ˆ.Hÿr)(^I4é@º¢õK!]ý’HWÏuE·PGt¢vI¤%^,´/Š^K¤…D\'jŠ¤©Qijˆ¬.ÒB5Ñ«ÆÀù\Z„ß§çS=ý³…hjJÒI¨:¥å‡‹jr,‡‹tªT,0!uÉ¡ÍÒÝ¤Ãp‹k®(G:iP‚8©‹p6ÚNŽå§¨Òt\ZVCül–®:”v‹\"&yäPÊ\Z©Š×…,–Æ(E‘&41MliÊwŽu„Lža.OH®_òwãJÌ$™­X40F÷­’>r%æäŠ.nOŒ§4¦SŸtÊ©Í%¦ùè¤RM\'mBl×<–N\nC–—®É!Mæ§½‹”—[\Zôë`‡¶¨Î&¶sƒXNþIÉÉàI–4ˆŽ[näýr S§%Ð1p®šQ3Ã“fÂ¡GyÔÉ#Ô!Ó—1’“Cyô’s\"ôJ*WäÃ“$—4I%z´Žá¡uâ=Œoî¹y4ò¥®€ðÄ!Ãd\"K„$ ŸPƒ2ÇQUYÂTsã‰Gyœ°èdH:‡s“ˆÊÜäR5„aêÔdN$C@žBæ`šÑÂ6<Œ•ŽºòH˜ù\0Ã|F‹Ã18oRuÑÅ/=ÇF\\<Ç‹\'9\rá‘/*Ãˆ49ŽôÚ#ÌˆIsÐü|®Ã€!9ªËÔeŒÆÅEI3C‚â†€Ü(ÉsÛ –Ó2258o\ZtXz@ƒL)$cbAnLê8ª)„k²\\y]ÇÉ VJy,Œ™’=ç`æ„ä’Œ1D%ÄR>¤·ÔÇôˆ! ÑÄM¾!–ÉƒÜ3@‡i©-^hÝdŒâ|,ƒQ!K€\Z!óp¦Û¡3\"på‰.MXz“ÇÁâÔ4Èõ\ZÂ$ŸUšxÔ0`æœbxR%	Mš©\Z¢	Ä0y1c$`6N^(€tÔ…kˆN>ÇM²;4Êƒ°bòqäæ:†§T\\—­:¹®<Õ£É4!S¸…g˜&dùÃÁU¼†ÂÌgzÌ\"CXçkJöä\Zd^™s%r3\\þÎP.ç¡`ç8UðF[ ‰ÅUz«‰%M-éT©Á©‹ÃÒ]¤ÌWÒ,Ÿ¨lê·|nRiVQœsæ CNŸ&±œ\"Ù„ š™Â2è±tkœ›†ÈQ¾\\V*%fIQcæ ý„¨ÚD—&gH\ZBÒ©.§Ð‘_4q:šÆ\\t‡ª*ËâSâbS®eS\Z`\'P[:išŽRÝEtmSó‰ÃpØ¢JmÅF]á¢;¯+ËGi%¡®ÌNÝâ“æ(–øÕIé€\'¥&ßÅ ƒp¸,¾•Êí„C¶‰NTrpzcÀ˜!ƒ.ÉbÙšXÇ0 Ã$F<rÄÃ‹~å‰.š\"´É%%4Â\0AKøª´„#Lñ‘gå6#àaLPÇ‘Çù™\\	0TE§*TÑd(qpª\Z\Z‘{ ÈMã&!Í-n™{@êq„g@Æˆ”&\Zd!–4ÑÄ¦B\'k§!ÐF·*Òñ@\\€¨lš_2’«!@,˜ù8Næ †L)Züd1&°Z´(„¦5óA¹æ,L1ÈIFÜ\0\0\0IDATä&|3fæs£‰‹‡ÕãR\'Ó•çÔ9?ò	Œ:1$!mˆ-ªæoÂ7ý`Ä<Ž€ãÄ€y3TˆÛ9Žchk„\n¡„\"9u€ttÓT,·\r\"¶¸I¶6‡NA¨Ïìn1M\'û[–-’ùå\0d	è °$uNMlQcšÃ¦ 4@ž,NuG§Éoñ9BnÂ™ÃáuŒÓy1FHñÔ-º)M…÷„¢9šBmvTÍVUsEÅ3¨úXŒ­:mª<^DÕÇ…“³ªŠM½ZN±s	(Ë% .²šŸèŒ;tµÅGY-w1˜òYT§.¨ž[ô<ÅPÝÂŠ™“×>\Zs›øcñC°øÍ!XòæPÅâ·Do!º…–C±´e\0–ˆ\\ò¶è­°T°¤•è­E·Ð&\0ËÚÃR‘KÛ`Y»aXÚNâ‚eí‡XÚ>\0Ë:ˆÞ1Ë;\rÇ²Nb–wŽå]ˆaXÞU¤`A»:[ë<-è0+zŒP,ï1+zŠ®½×|Þ{V8ðyŸ‘ø¼Ø‚ÏûŠþŽ ï|.rå»¢;°²ßHŸSúÂÊþ£ÜŸbéåÅÍ©PÔÖ.„^<Ð+æêâËaCX¼W“1±À›êT¾!1Þ3Å„8Î\ZÂ˜A]àÔI¡!ãÉ‘´ôÎDC\0ô¢V.ä&Y Äâ8†ÄKKÇ©ÂN9Ñš¦r5d9¢EQ‡F°÷Ú1üví8~»zû–¤¾ï\n}ÿ`ß•cŠßD{’þ½—ƒØ\'rßåcØgÉK2Vì½—Ä\'Ø{ñ¨+¨ÅÞ‹Çâ§$’þ‹ôhÄ/Rf­õ‚¬0O.?dË–-Q#ŸŸžCæË’Ó~sv{áÞ8JBtžNÌP[”„‹JÓ\ZO7”údAº2åJ„’`P$7ÈÜx ‚¶Äbå£OiTæ£¤S ÃÅËz8¢Œˆíb9Tr9†Çâ#¿9W%\"BšŒ•&\ny„D$—é“^šcS‡1rDÕ9P‚É§ât˜.7\rrå1\ráºØªšn\Z¹’@ƒÝáÚœ OhºNÑcÎ“‰¼éª¸>Îÿ!ÍÕ}ƒ¸ô%¿J˜]z\0VWzO¼æŒ´×ròyQ@NPx±/©<ÜÍ7¥\\o,¯>k^xßA”0)–6æpñ´§U~‡óª²Î¡Úì„F‡Ã5dw‰A‚|ÑD#r$q;mÕÙÉHS¸—>BÇ:µÜ‰¦ËêûUz3&,sHãå£¡˜.ó “°&%ºQ>]®:md´LX4áZ>§·6ÆT1©5ÍòSnÚ´	&L\0X¿Ofý<cwþBIÏž=q?cïö®¼ÛÍyÀ€J½|ù²Ê{íÜ7JH–Ã×N!«OFì¾¸ï!\';\Z«+Á‹¹Ê G*L,ÑkªEá|!ÒÃð@FïtH-BÉl…Ñ¦PCÉb&k²~Î„\\Ð³Ôé©FV¢-ªç,B¾y‘+m6|Yû}Í¿êÅ÷dŒ4ÇQû?eñxš¬Èž:³nH=³\Z“ËCSk\'1I-ª†Ä¢$ :áv/)¡‚¢Êq!7É\'½i0œ:T\'Ÿ\\Ó2½	SÌ/šsz–NJµ|%àç›ƒª´bÑÊvºM…G“|4”§:Fdz¦¹™!ÚÊÔÜPäF¿6ª²ôNwpbãÀéÓ§Áh;qâø¼ÐwïÞ\rþö)ü¿àà`ý‘8þ–}É¿˜Ù¡Cý])ú&MšýÑþn\Z}üABê.¤y[?~\\}Ó•Ä:ƒkÔÅCoqÍ™;vàÔ©Sà/AÒ&’?~w/×}ƒ0k&ÈŸÞol@ñÌq5<Å³Âòã? sÁFˆŠŽBZ/ó/ø.Þ4¿Ÿ=U&pLhDvÿi=åSò0%7ANÙÔógÌ…­çöaGàØù¦WîG7Še}R¥uSÉøïOìÄ™ëA˜Sc€\\KBiC.\nñÑ¡:AÃ*¢%Õgá1Ômq1\n×ÜÊ¡óVê“N\Z)\nê†¿ûû\'TÃ†¼\rOý»Qcšðb‡fù˜@/™¯ø˜C]\Zç¯ ‡>áPÛì-Ÿ%éhõI\'ÍÌ#iüÑ¼\Z5jè/_Š©ýÆÑ¼ys.\\üÙÐo¾ùFcVÇ_’ä…Ÿ/_>ðWùsVŒ¿TÈqíÛ·GÓ¦MÁÙãá&´8qÉ¼yóÂú>·¸. Æ×œùèW¶lYpƒ’ÉÍÌ?|ãZø³P*T\0Ç1v\'ÄÞ 2‚;uõÉM¸ŠW6õGÀÞÙØsñ [‡†?ôÃÍ¨p´ß>\r7¼+õÆKúáì ütÍ~\"|ôÇ\n•]·MÀ©ëÐà;ó©SŸa\\ø!á¡rz\r4øæÁ»Øt/ãŽ?ŽƒÐì»ÁˆˆŠ¤Šú_ql´è¼TÑr) 7Ö‹ó/ø¨`]àQ$ÕÍ1†Œ!$å‹M9\0“\'B,êÑ*­1äB’Ïc†ü#\Zœ·½§bëñ}Øph7Ž^:ƒÈ¨(‰Iþh(ß€Ü˜€•y™Ž(ur±-Š¹Ñ ÔpØÂ2£‘æP™^U™ºêŒ#óÑ€tÖ¯aòçJ«U«†´iÓÂúeMþÚ¦a˜?¾þ3¥ÑÆ_¦äØ~ýúéOr31ÀŸûôvüòãŒ3Àß-[¼x1¾ýö[Üú#äß†ƒbIè£®s¦M|úé§àñ8W¾ çZ¨óÑŽ¿/LÎà¾A¤Xæ‰áx2ÅÁ\"Ò”Šš1II·À!!q\Z€=àMHÒ$Cþ1F	‡N¼1@†KD2¢‘+n9étXóÝ`Ôt‹\n—<‚:ÏÜfJ¤ÎüäpŒ!—:ãÔ)	9\nMÀÁ³ÆÐf€‚O•Sƒ`ÝÄÃ€ŒMïHÈe”n“Êˆ€‹§ºeÏup¡!á AP—ì²H8o(†d6ÝbS\'Ð!·æ#jÒh:y™ª%E}ÐÍmƒÄ}\\©®4ˆ‹tªªH\'Žž+%KGŸ§/š9.på8tk¦§4ÍÍaªSq}4ÍæEA›˜4jf\nË ÇÉw8)±s¹\Z“Nš3B]À°úDw®×éP%f^®êŽ0xåŠÍ\\¼ Ýòˆ_mJÈÍ’ê›ÍòQô	˜ÏI£Ÿ¿ÓG=)€ó&â\\Ý6OŠ!\'Iïµ´ª†žTU“Ò˜è‘Nf+ACÆ˜D	¨.–„h¼§š¡B˜–ä C`pŒ0eœ¨4Ä-,µÄVS:Q£M0¢©´cˆP‚Te$Ý´\\y|Ñ0ƒªÈJERp#¦y *2~s˜Œ\"u\'ñ]¼qC®˜¸qMäU]¿ŠK!&.^gÌÔƒT§}\rôÓ¶xsÑu¹’›âbÈ5Iœ<7_Õã‰¼|Eõ‹r|ê<õ ñ;7‡Ì¹Æ„®¨9¡jŽï†\Zãº*jŽý}Ñ-ŒíŠêïuA\r‘5Þ}Œècº¢† úè.¨>ªjŒîª²úÈ.°PmDgTÑÕÕ†wFµaDTèŒª¢;PuH\'T*¶ êàN ª\rîŒªƒDWtf…ÍëEæ\\u@\'TéßQ ñþ¢û‹ÞOà/ºÈÊïŠî@åw: ò;b*÷½Ñ•ûtD¥Þ¢+DïÕ•zŠMÙCôíÝ?(”ãJÝØë\\äâ0¥^.nõÒ&Äà…\"Ec§4]‰H3¦tƒSTs<y4bü¼(i¹xiÞ‹àpPXQæ|¨Xˆ–­$ºÕ¢©È  î\nútª¢ËTM«æÖ9™¢HsÆ¨Ë¬Í\ZÓpFÜMkÅ-)¹+‰K¿‰YÍ™ßá`.ç°M!¥™õ§byct3©“dtqCnÌiÅõ\\¹Æ©;+&c%›ôÒÈ¡y,]l§JE$N%QÎ4L8y¦iöÂ£_ó˜³‡4Õ­8\rõiç¾Aôl›~³øœŒ‹Í†”‰‹5m0p-’˜Õ OL¦  8ŽR}ä	qˆÐC‹*¹äâäd¼Úì„ <‘Qgê”¤hq¸ÆT— ¸¥g\Z’ˆ&A•]C\"µÑ§ÃƒˆNU)t«B.çË\0A§ø¸^òÕÛä‹Guv¢Ë8ò£ÅáÇÐ§ä\"Á‡SZpµ­|J+	8Î¢‰iªä’9É±†)GcB½Eš¼hE]N˜-Ú!E0—i\Z´Ühz®Äk‡\\C2’oæ4L>äY‚AžHgHuv’¨ÌkˆIA:ŽAÓÒ9ˆ)OL•:ÌÇ@ø„ÛS,X7![ªJ‡ÍÔÎh	HƒEÖDDêIÓ9HË§Ž‰Ö¯ƒH„†xÅV^´èÒ,!R9t‰.ÂÑÄ0W¤¶¥’«º„9½˜9M|ÒD1ur9†ÅUhÈ±YåB£Iáä©“€Ú$¡4®B«>GgÖ†™›ub>ò]S¸äÂŒ”ÎùqS¹JÕÝs“¢c4&–%©ÊA5§èÚ\\b:Ff\'T™gaæ7½Žžãó:óÑÇ$*…GIˆJ7!Ãd¹Ñz@Ž Ì›D¢MÍâ;sÓÍ	i@xj³3A½uæ4±8ÆÊ)TšâåQIÑÐVC‚±7/&pO¢ä’ÁÒÓ/BçåÔŠ$´B\ZC&Òµ)Í1ÄÕ¯ºø5.†%E•fÎBó1 <=†Hi×ˆ9G±Ì#2ŽÂòi)Ä U‹Aiù-]¥ET#¦sáÅ0xñ…BÔXùÕ\'ãç¤R|lÔ-Ð¾ŒY>·90@8‚Ty>¦%¤\"2%	JÓ:IÀàD:›ÄÌK<¢›<*Rb\nBBêwÕéÛ`Ì¡«zk~q:ó‹Î&Ã(LÐp¬My¦×Ùk~åˆË!)Ä2›ePâu¤“µ‹ÁF¿Úœ¯HºD(Ï}ƒhD;Æ¥¦0dqœ$Ñu¤u4ÈÍ1ÄäY¶¡\nÇ‰bærŒ1#âÒqæeò![ÒhqaèqcÆ¥óÅPWž”8†PCu+·Ã9°r‹B[yÑÔ˜ZÁkÈêŒÒ0»áÉ\ZB M>”=€¡ºTR‚†¸síb°EKG\'ƒ3Tçp\rA\ZU‚1JÍg‰õ¤C8ÒäHâ…n!c37B‘ñr8‡a\nr¨‘GIÛÔ%	uSÉ¯<—˜z£ƒ\\ªÊ•1Êa‰9›”\'Òœ¬PÅa‘Ì\03ˆ_Ã0³ÎÜäÆÅ?3™!É\riP‹y\rÂÿŸmÆS½1½p/LÊ\"\"˜ñto(žé…Ïˆ^ÔÅD\'ží™Äs\"}0óyŠ‹´P¢f%E¥D–ê‹Y”¥EeD\nf—íbe9Ñ‰ò\"‰\nï`¶…Šï`Žÿe29\0\0\0IDAT`¶`N%Ñ+» Ê»˜SEìª\"‰j\"s‰êïb®…\Z¢5EÖì‡¹/˜˜W«œxQtµE¯ãyÌ¯ëbe=Ñë» è\rúc>eÃþXð’è,xYl¯ˆÞÈÂ\0|úê\0,xµ¿ÊO€¢‰Hâ5‘DS‘MâSÊf°°Ù@¯‹$š‹$ÞiáÍøì­AX( ü¬Å 8ÑRtâm‘‚E­ã³V¢µE‘D[‘,n\'º`qûÁXÜ~wpAGÑ‰N\"-t‚%D—¡XÒEô®\"-tè.R°´ÇP8Ñ3\0K‰^\"‰Þ\"XÖ\'\0ÄRÊ¾Ã°ìB|”ïŠNô†å„ÿ0,,\'úÃr†c91pxÌñôôÔO:3fÌv\rìkÀ¼ÜŸbÉÃÝì\nØˆ©Àm7Èèæ¡JãN1Óê}Ùû!à§Y®‡zàër­Ûm#ÉTà¶ä»J2¹›‰î\nüónh6Ç®€³±7ˆ3t?J±ûtÏcz—|ýžÇÜí€4J|8ëÐCÙ]¢®À=múo¶ÒÅŒž1	Þ›€­_Ï»\"¶¬ž‰Í_ÏÅìÖ5ÄNƒÏçM	¬š=Aåì…³°}Ép,ìšMìL‚»o¯™v¤­„/^ý-K÷Â³Ù\nbIÝ\0ñUWR?¦hÒš~„Á…K oZ/äô­¤¾{én(9Êk>¦Öçó&ªüßóT¢×S:ú–ïôÇòécð¿5s±b`uL˜>\r\r+tDm‘”+pOÄu¡OåöD»««âËíP©n+tÙuoŒWßî‰R>À+mú`L1?ôy³-ÊuüoÎ/€åšÿƒ”¼‹nY(:wmüP«^ú¼Víœˆ–ßÃä­â+®¾µê ñÊ®ð“|Õ–vÃÏ©ê vé¡hãˆxî¾ñ?²ØÃ&†ÿð÷ÅÌÁ£>DNÑ^xéméc·ùãÆ I‡þèÛg8†,;*„(¬qüŸ+bØ-	Wàž6ÈWçêR´ï‰·Þì‰?ýYì-³…þ¶Ÿõë¥Æ®Ñ½Uößw\n—!·Ë»€kÛÑ¤ûT1î­…žüÕV×Aæß/Û¯Àék¼f´ÆNi|½w$¦oü£þ¶<ŽÀDˆÄ=ÀjíÔþÍ‹ý¤Ê3p¹MìïbÄ¨{…Ï1}:t‰qÚZ’®À=m¤µÒ{ÛÖÚ\"-Å–v¤·Ý [¼*”„kqe2ùh?®Àð½’¯ÊÈj§LÎ¸íiÚ°&~Xö1v}=ÿaçÚyú·Ïüûgâ‡¦ŸàA¡{©×ÝŽõ ×ukn®ÍFZ·ú\'…zÜvƒ„‡‡#}úôÉùÂ^›]ÛVà¶Ä××÷¶ƒí ]ä^Ûnä¾x{}vîTä²Aî´N;nWà¾*`oû*›=(¥TÀÞ )åLÛë¼¯\nØä¾ÊfJ)°7HJ9Óö:ï«ö¹cÙlBJ®À=o°°0$7œ={6Ö5pìØ±d·Nþß±j;n[{Þ ·Í–DƒiÒ˜&å:ý~·×Õ“ôuŸ¤¿ˆ‡¼{ƒ<ä‚Û‡KZH±äÀIëLÝçlù¿-ÝçP{˜T Qmàë!(ñBS\'>Ž§c¿>yÇ»EFFÆ;G|è:]ÖÚg¨ùañÉ×X¾^ŒËoûî®‰jƒ4i×7Ö¬_nÙg/ÄòÇ×Q¤H‘ø¦¸ïñÜø·Þ¸m:÷u«;Þvùòåã#%\'HTäÖ!÷òmš¿‚•k¿¿5”ìl®ó™Âù±}÷Þd·¶¤¼ D½AÞêÒ³­R$å\"ßÍÜ­u.^åþ?ÈÞÍX›óà*¨7Èƒ[¶Ù®ÀÝUÀÞ wW§$Ç²\'œ0°7HÂÔÑÎ’L+`odzbíe%LÕÉž5sÂ¬*‘gÉÞõ7Ý\'ûXö¬îÛz¤HTdÁG£°~Å,|¿b¦v~»è‘)¡žó±ìØ¾v¡Û\Z¹æõŸÏÆÀžíúpv¾xT Qm®#“odöÍèþïWŒ%\'øøx»­‘kÎ”1IüÄRr:\rw\\K¢Û wœ±M°+ð+`o‡XlûPI¯pƒ„\'½j$àŒCo¦ìõ\'`)iªÙ GA”,ãÀ¡£Ø8{nZˆ×W\"äèvñFPDàÐ‚Þ8±wÆ,ûü.mTØ5”è¾¸~ß[ŠÉ;.aÚ×æw‘‚ömä ÅõfÿÚŒK&ãÅNïÁ–ŒÓh4Zt\Z„;ObÃQóœÆŽ˜„¨h\r\",*—èZ´þ,N]\nÁö? 0,a!8õ÷ænžÂ±Ýÿ¢Bxì ÞìéCºcÓò@Ðüqà f}¹GÎ›ÿEÂ.\"øì:¶M½˜oòF…]EÈéŸÑ³í¼÷É7´b7VœÔ\"Rê0pî÷¸…³oDØñM˜oÿ?$ZÃÕ%ÈYùÍzœ•óþÝ›P²aS¬;ãç2ßPñ&/Û\'›ûcåÞ0x>€í\'.aÌÔÅØ18&¬ØrY®áù7páÏÍ:6k±ª5q†êé¼<0~Æ§Èœ» 6\\ÈˆMZ#Ãµ@¬Y¼\0c§~Š\"[ãà†5<ˆÆý¿@Ž<1yÅvëãá‰LÞ¢Faðbsó‰u_í5c+VoþÖoÂÍˆ?uQLžTÙñù®€Gjlû|:ÒùåÃ™leñã)Ù3Y+býÆM(á{K¾Ø‚w&ÌÀedÄ”E[ÁÛìµ=1jÒLªððN‹VîEóW\n¢À3O S’X½î &N™ŽY«þDëV51sÞB<^ *~OS\'vmÄoÂ€³[°h¿y¡‰’d—ø&í‘SêÛµr¥º·i‰ôÙ¢kÓjXÜÈiÿ[NÒ§\0=^«xå@õñNÏŽ¨˜\'³¼¥ÙÞÙŸAŸ–õÐ²}[TÎ—ƒûvU>»½ÚS(ú¶Þƒ×V]=öz\r_o~[À¿F>ŒôïŒf•žÀŠ1/¡ÕÛ­Ñ«	­CÍÎ++F¼þ¬©ßg_¬ú«èÒ°v{yÒþ=ÛÂ¯@5G6ôéôºê/÷™€wztA@÷æÜ³=<ÄÛ½C[<_¹.´}ãú´G&/ø÷nóæçÛ»†úumÒõÞÄ«Ÿ‚_:`aë\"èÕµ:4yùe@ßv-¤ŠçH…A’¿h6àñŠh^X&¥»K¨\nðÜ%T.;]dW{ƒ$»Sj/(!+ï\r¤\0OOO¸â^‹˜ÖÈ9\Z†¯uÂ¾¹UÀÃÍJæÿC õë×\'óUšË›9Ó|ÑoZvû,A‚në†:§±ô8°qÁ\'N›J…!;(âÄ¾Ó×ÕÏ·©l^4\Zou7ßÅúnïEºâ)S¦`ß¾}ñÎãš oÓ¦NsÍS=¶Õœ·i™ýº3¦¬P¡¥*Ó¶Ã\'¿©*Y—·w=þmâÄ‰àÄ…„ØïjÅ·š	ºA\\\'Ó4/êø,Âéß¿Úuvš®JÞìæ;0Ç¯cÇŽE–zýœá?üíÔã£ôêÕD|rÜ:vüÒ¥˜ñÍŸ·¸#0vô 7_ùœ¦YàMU~Þô;v|6íÄl¦FÏ\\ÑX|;®‘àÿß\\)}|‚nlµ†¹Õ³vËŽnöÖe0mæT7Ÿeøzªæ“·5ûõë‡§}=ñé‡æÛ ½_+£±øvk×®ÅìÙ³ã›&ÖøöuŠ¨¯a>x¢Bgô0Ò4}z‡œ×é)DÉ§˜³ú×Ç¼÷úaæh®±¬FÌWßîÄ‰˜6mZ|ÓØã¥	ºA$_¢nÄÕ«Wù=<Ì;ƒ5‘+V 44ôA¥OQydƒ©PA‹ön¶*¿[>Ieïþ1Ë¾[£¾Í‡Î‹ŒÀuù4]m›NÝTÉÎzÖÜùÖ4sÜŸ®¨SºS{w#êÆ?¢™mþ†˜_K¼õÙÉˆ_~é˜™àô7*;\\¬²k—v*ÙëØ“½‡NTéÚ½ÑsŠš½æbÈ\ns®GÖŒU_ÀÜÝ*Ù¹>Ñ:v)Ñ‘îûøwû’¦8¬ÂîPîfƒÜÕ¡~²ï~\r7ÏAšÇŠë˜ÆtÆ¡s7€È«x<M&õUz2»H/AL«ìwŸí<ã-KJèõz=ÑÜ[ÌVüž-‰9{å9™ƒRúòJÕ>Û°	\rÚ¨žÐÝº)ò\Z\"WMûÆ‹~èº`?J7ñG…\næ7\0Š7o„¦-F¡NÓ˜\r®déz\rèŠC^„ùjKÎäÔ¨øJçÿ}ÌJ\rÏÔèÓ&æµ[ß÷Íïnµx{0Nýoˆ°íö * døÖ­Õ±:­ReÏ‡Ê•+;çûäci\0ÏŒn>Óå*Dá@¼Q:êiµF´ÂÄÅkÕj0Rexx$R©Óµ-ó\'ªO5ê¯7ªWÆW3~ƒxg~µº¾§Ç`ÇuNiQ-ªæÇÖ­æ£C\rYûÒñB_Dä‚’9€²Ã¿Óµ\rol®?_C¾‘­JÆ0#Ãñ^ÍTj?‘Ù[å„Ù1ÆÖ£ã‚y#Pù•á\Z·»SÙ fj±³z{{Æv&bûãä=LÔÓÜÿ6Âø·€íOð\n$È±^ƒðží¼ãwtÏ_6Ÿ‡EF#::‘òÎMH°ù´!0è2Žm‡ó.é‚.8dðµ+:N|á8?‰Eðe¾v<ÓØ£è¬× È³ÆWÍ¹r¾@à%óÅÿ•àPYo®‡†áê¥@æÕ‹¦¾tAí¨ˆ0„‡ÊûÙj±Û›áQTt}¬ãMyø¹aú4`w¤	²A8ó€A`\\X‡Í[6á\"Â0}ÚÇhÙu¾úb¾šþüÛ6A§‡`ã‚qÈ‘•¯G¢‘=[fàÌRlÚôäšCú¾˜:Ÿ\0ÇÜƒ¾Ó~ 6½‰å«Ö€Ä…{ú`HÏï9‡‡áÃ‡ãiyÖøÍ÷?`Üê_1í“YØ=7\0[~ø7/ÁŠ¥Ÿ!äÌßH—Ú3Ës*™TÆ,9ÀÏ×ý°	ï}^>˜6åC]³„µ]ýs-v‚\'Œ·ÚŽÄ­¡ˆ<¾Icv÷è* „¯AFŽ_ƒ4jÔYàƒAýûaþ”þ Ý@ÞÙ7gæo‹ª-ÞÑÕ>Q¡\0Qs6UŽ\\sb\0ƒÅ¼cÅÏAÆÍ…š…|ÑªUðsa\r_:Gú‡ßø\ZdÈ!z`®ë—ŸÃ !Q²U€®!U–|hÓ¦\rÜ__)EEpŒM¾ItïÛÖšå•	²?Û\0£¦G×Þ½°pÖ Œ­’\ZióW•Qv{”H\rò(`Û®Àƒ¬@‚lë5ß³¿ÛÉž9s=û~ˆëa‘w5¤Y³fwÅ³HŸ´nŠÓ§OZf‚Hë5HË\næ£ÈÝ$ýô§3Ø÷õdðuÇø½Ž#ÿ3ßÂµ>ºÓ™oð\0\0\0IDATÆYË=§Í×@´m$\\dƒp:W®˜oíÜö=l<L—¼Õ:í–cÿþýX1Ù³ÝÄY\0ü:DÚÂ…ÑyU æìÄù,Œ¼¥[Íã~Ù±þŽÓ¦~õ+–,Y‚¿ÖÍÁ›Cfj>>ïgªÀ=æŸUýôY8~Øý;Rü~aÅïGr|áø?ÐçÕzhÞs±¤¹ˆèàõè2x¾Îá«1­1fÚçâ.ž;ÕßíÃ??-Âä€axÑƒrf÷o…ñ;‚qöØ~,ôE¹MìˆEûâhhÒÊk3ÖÃÚ(»•„Öñ®+c-ý2¦u ß7‹ï:ÉR¸’ ¤a¿~ðõõEÖ´ž8{!å\nšÏ³ë·„™MÍ\'eß\Z‚6OþÇ/]º4Z>—­Júâõü7-5pøàA4­ð„2Îž¿ˆÆÅS½ÙSæ»97ò¿Œ…ÃÛáÐÑ3È[¾Æroˆ5kÖàÕ!ŸãàÁCêcW¥e{”«T×OýA3Aà™6«®³}¿†¸|-»õB»·*Iî,8éUk†Ã§.¡~ÿ9èßéUñ/W+!“§#kþòè0ß½Wi#.â•!3P¯`zD„\\EÝv=”Ûhì§¨#º´{= ¯ÍøÕë²Ï›øZÖ)/LdQDG˜kùXz/Ó°û­€GBd+Ù°¡¦ÉÊ\rE/+£ÚV—-_IäÎÂS]Ür©,T²ªæO‹4Ùóª]OÆª\"]\rQ2ßí²ä7ÇÏŸE\"@ý:Õ½P	ÕÙñ˜¦¬O¡xªJ\r•éüžV™G*¹J%QÕ†%Q°DU«ZÕJäG68<R¡^rj[/lÀ/h¹ðŸgÊ#K\Zo<%ËÉ]¤´¬ÏýÅxñŠ/(×Zìi€ºŽú¸ú³ñà*à‘©ƒí;· ò–dó>\\(žH\\:¶sÜ¿›´ÿó±eó:‰;š¼EìÐ€.ÏñÃÍÏœ±Û(ç.\\DË¥gôíàÛÐî?åßlÁê“án9®ýõ­›íf„_Å·Û~Åkn^‡ážÇá¼³ˆÖÏ‘ÆŽuÿ{›;´÷ZÙ ïŸ¯‰r¥+‚ŸsïÙ³›§ë<Ž?%2æÿêz4¢CÍ×ÿ<ßå)°gÏV\nô}k\0ø_\ré.GHçÒöìù£ßŽùNCcåÃ‘¿Î„`ÿ²!˜·â+ºß‡¿TK¸nëÌÞhR§\"^Îí½{ö€¯N_\nFÔuÇ_C95¯ù«#4\'†ÚåŸC!yðùUÆÐwñ÷¯ÝÖùÂØ_èvbƒGÇê§qA”¹ƒÐ²BkÑ®—¶‰4[XÈôë7Ü4ì>Á+à‘‡—…|NÑJS-û~?Òæ«€ïŒE“²‰Ïž>é‘5çc¨ñ>ÝÄË\n¨•5BÆð„ëöž¼2bü‚áè;x,äƒ´nÝ™ø´Úð¼l=Žó¦bñ—Û$/Ôÿ¸o*ø¦óAš¬¹ðxö¬àw—Ä…±L‚7öV¡ý‡hßÊ\\çä)ŸÀôƒúô„oÉ7°çŠŽöö„yh×±ŸZ=‡O@›Vo«þá”*³<SW_HC2ôîÜÿë÷<xkÝº5†Lß)ÓæÑÄ§³gÃ0lÛ¹òÀ…J‹ß¬•Oú¬xüñìõn/<óÄcâ·Ûƒ¨€GB$õ\ný\0ŸN+\nÊñÝ/ SÖu˜=ÂÏWåU“	s=‹†š¾ÅÑ¢–ãå¥á…¹sçèáý[½d’]&¾™ÊŽE;bÎœY\ZãÅ0gyiÑ­±ù\Zãõå5FË*~È•ÑyktDKyWiX­lfË˜\'••p—¬sŽc¦F±L-\"ëž‚â¾®ÇñÀÌOd£;\\³çšüœ9Ÿ8<õqM^ø`ªcáz†w(‹93?xK>t|©`N¼Öï¼Øª%†Íé(þÔ`­rÉ‹-[¾ŠaSæà­NÄo·QÙ ej|Ïà¯påúZx†Ç€~NºÅØ­°þ[{èBÜ–¯¶ÄÐ^|4q8²8ûçº¾ºÃ×¨´Ö:wÄ*É‚ÁÍTžØ9[e\\ÝŽ¸œß×;Í§jü‘‘#G\"Ôá·Å£«@‚lNÿ…áÈâü¼7JŸ›ï“§ÊôåëV\0\"/ƒ÷èÑQxïÍ·Ñ¸ÍñÄ°:/’¢à^ªH7àýUàŠn¾Éä)ZÃ&ÎAË±›ô/æúoÁ9ùÀñn/¤)S¦€?h€xÜJÖÅwý=ñó¾(ôX…\Zo‡Ådó}‡.ÝD–ÜÕÇõÞ¸@uëCF\ZüãÞ“WqCŒs† 2<ÃŽÆŽ®Âº\r\Z4{?Ÿ„.ÝÀî%CqãêEôþ6SÖóuÅŠ[ò3£ø®3îÌ)Ï›`äS}0ïç(ô\Zm¾3S,{1Ã£åUª¸¦ï…ÐÈ+H“»(àé…¡_­D‡.%¥²ýÛ­0¨Ï+xÇ¿?‚ÅÓ¡e+éCÛWâíVí1¿_eôêÚ#+¥EÿCc½{¦ä8º®]»:žûÇ¼K×îoS£äK7ÑÉ?L›1ƒá6òÉŒ¦}íøv,Øð—3Æïqµjm®£{oGDâ§cô|[tißQÑaðùñ}\'¿E‹(öROü±ø}ìËÒÝzöA|ûŸÿú99ÿ¦ð[Ýºuû·°í¿‡\nxÜ7NjPP~Pw:bj]%Ÿ~ËaËpZÀðo’+W¯\\Æk>@³nè6e&u/  _]Åèá½EÂØÑÃTŽ™07.aÜ¸qÈ%ù\'}0^ý/š‡	ãÇ¨>rì8\\–Ø¸qc*’Ç¹Î;WÄ¹˜Û8™ÛÈ»Ò\\×ò/°zõ\Z¨½OX…º½Wëœêµê%ë¹†ÐTÙ–¾ ÚÖ~™ŠÖÓÇ7d®ÆCáQ(Z¯=Êþ\'FŸˆn}º¡SÀûÊõ­ÐVéB®¡tÓ®x©DŒ•±ÔôAß å0_\\8þ¼®“1®÷6K²CwQxolÙ²!)â.jãFIŠkäœÝa÷\\xo{>¢=àv°c‰¬ö‘×OeÊ”I\"É«Ù¿´xïçóž7ˆ’òäÉ«r¹råJvëLŽ›>Ö‰K`Ç=o>¾Î®@¢®€½Aõé±\'÷¨+`oG}ÚñíÝOì\rr?U³Ç¤˜\nØ$Åœj{¡÷S{ƒÜOÕì1)¦öI1§Ú^èýTà¶$00ð~rÚcRZ’ñzo»AR¥J…S§Nÿß`»Á)¯·Ý ÞÞÞú37QQQˆŒŒ´a× Å]wztôðð€——¸YlxÛu;Í”tÜqƒÀ¾ÙHÁ°7H\n>ùIaézŽöyÔgÀ>~¢®€½Aõé±\'÷¨+`oG}ìã\'ê\nØ$QŸ{rºöyÔgÀ>þ£ªÀ]×Þ wU&›”R+`o”zæíußUì\rrWe²I)µöI©gÞ^÷]UÀÞ wU&›”R+p$¥VË^wŠ«€½ARÜ)·|/°7È½TËæ¦¸\nØ$År{Á÷R{ƒÜKµlnŠ«@¢Û )îØNÔ°7H¢>=öäuì\rò¨Ï€}üD]{ƒ$êÓcOîQWÀÞ úØÇOÔHI$QŸ{r‰³öIœçÅžU\"©€½AÉ‰°§‘8+`oÄy^ìY%’\nØ$‘œ{\Z‰³öIób\'I®°7Hr=³öº¤öI2ÚI’kì\r’\\Ï¬½®©€½A¤Œv’äZ{ƒ$ö3kÏï‘VÀÞ ´üöÁ{ì\r’ØÏ=¿GZ{ƒ<ÒòÛOì°7Hb?Cöüiì\ròHËÿhnýÎ°7Èkd3Rpì\r’‚O¾½ô;WÀÞ w®‘ÍHÁ°7H\n>ùöÒï\\{ƒÜ¹F6ãÞ+lFØ$ÙœJ{!¢öyUµs&›\nØ$ÙœJ{!¢öyUµs&›\nØ$ÙœÊ”²‡»N{ƒ<ÜzÛGKb°7H;aötnì\ròpëm-‰UÀÞ Iì„ÙÓ}¸°7ÈÃ­·}´Ä\\8æfo8Šb»ì\nX°7ˆU	[Úˆ£ö‰£(¶Ë®€U{ƒX•°¥]8*`o8Šb»ì\nXH¨\rbå³¥]dU{ƒ$«Ói/&¡+`o„®¨/YUÀÞ ÉêtÚ‹Iè\nØ$¡+jçKVðØþÝ2äÏ÷_E¡B…ø`ÏÉ>\'î\Z°A’Õý]â\\L†p\'$Î™Éjƒx{{ã‰\'žH²ðóóK¬×IŠW’Ù ¯î=ú»Nàé1Pò›(²êD¬“–˜/°\'»û#ÿÀ!(Ñ .D¥G·¡ƒ°ò‹Ux{Ì”íÔÞ9sƒ<®5üóÏ?8rä:„ÄZ·åˆøáCw#øÉ¥€÷©Ý2â$ˆ)mÎãN7¿…å³ÍÈ´ä+øf\nÒÌr§!ûd÷¿rª§öÂú©ñ}öÔX—-FeðŽ“ûCCüÑ<5þx3 -ö5õD¯2ãäZÎ“-æ ÕäßæÃß,W‚È¸«˜ ©6IH(pýaJÚ®G(P €Óœ5{6hO›1Ëé£òî»ïR<tx4j†cù² ê¿#hó>TïØ\n\r+VDøÕËðòöB†lYá÷R„(ª›Äu‚7ÆîÝ»ðóÏ»±k×.„‡‡»†ºGÖÈæ½~i×!ÓÕñèÛðkx^Î¸¥ä-Û\ZyK½Ž—\Z×Ç‰µÏáÄš\"8±:¿vÊ4¥êà¯Çpú×p„œ‰@dø\r”[PÖwUNœ8nâcÇŽád­,8|ø°kØ©—}6R¿Ö-ênD¦å¡ÁdgÈMIëã‰ôoNF†1û‘¾ý§È8ö0R!ÒCãdŸïRº)N6Ÿ#<ÆDGÇ^3¹÷ûø°Çmy«.v¶©‹ãS‡àpŸº8Ù¿®ÛÂ].œEŸ-BDDþ‘çJÊš5+2gÎŒlÙ²÷Ô={öÄSO=…æÍ›ÃÓÓ¾¾¾®ôÓ¯—^N°žÞr¥òfE@ƒ\Z8xûÿü›#\rœŒŠÂµÈDGÜŒuL®+,,&Âþuƒ¼1ð1´É5YWŒÆÖ/¢Ü™“ÈæÇz\"®‘×A¸@ì¨›—c÷TÎú÷Däª©ëõ2póB¯žÅ£#44ÁžÄˆ\r^Z÷7äžŒ[0¶Jf|ä×çƒ#ñúâ¢08[8jFGÃøOqDÈ¨™¯Ã0Ô«Ë=áE\\,ÓÈš~‹Û#:<Z‹G’Ù ¹Þû\Z™¾Fúv“‘ÊÿkDõúÚmÙ>>>Nû‡6 M‡Nxì±œNÞ_¿~µk×Fß¾}õÞúäÉ“h6	\0\0UIDAT(V¬¢ä\"½rå\ni	Š(/ô<{ãÒ¤CãšEQ¹VUì?qÿœAú¹‘ý@0N‡—â‘9{¬csƒpŽ¼—¦Ü³gO,‹ßÙ‡æ—NáµàTÈ”çŠdÞ‰arGD06ñÆ¥ÇªßÓáûÞˆ¼›—áÇ±‹BDŽgyºv¬ål®÷Ó\'nŒF@4Úˆã~3?^ïŒ7Êû`pÅ!ˆŽŽƒ%.Ã\0äËkÔ!xÜE‡ v;õÒdäœÓFh8Nvü\nQRÃè™Dlê}{’ÌáÓ+\"4$×åéVÈ-wRt!Zîù¿‹ž];:}TV¬X¡\'páÂ…èÕ«Æ‡k×®Áßß_š¶°<á†‰s?Ei‡Qoôïh<ü´˜ð7:/;…7ÖžÀ÷Çe³œ	Ãá‹žðþe._¾ÌaNð‘ðë¯¿±víZ´iÓÆsU\"äâ(Ùû2ª§÷…wÑØùDI\\9ªË½vÃýÃñæ¯ÝÑüç¨½mPœÄûÊ?ˆ	FTT8®–\Zˆ+OõÒáquÜ§ª…àd‘¸zõ*hÇÅ3fžÆÑÀ0íïú(~mÚNLMžFGáÆ¸\ZñÏÃKŠ|×äÑìV²ß=àyýüf¾ÏËi¤AXà©[iñ²=â5ú!>ùF\\j›!ã#ªG¤öÏëè|Ë™iƒŽ¢Ð¯ã‘ýÏñÈyxr~™vŒÄãßAêõsà»äd_=\rçÎÅÚ ëÖ­Ã†\r°iÓ&lÛ¶í¶«á¯²ëò\rüo-Ù\'7ÏËGáã‘iQsd[Õ\n9¿lí2ÇÉÍµ´2ïŽŒ;G ÃÏï‡GqK+UªÊ”)ƒråÊ¡|ùò¨(¯¯n¡¨i„D\"õŒsHóÉ9ë’;¤Vÿ­]¥Õ‘(´(Ü‰§‡ãã=Wo¥¹Ù9—¼¨¡Å€j¹ùãk$™\rr·å&Iª		¹Ûe&Y^þéÂ‚OÎØo¸.lÐ A¨T©’«+N¼>}úÄ‹¯3Ùmø$¡ÆÛyb*À§±~mž‚þªOq‹aÇh#GŽÄæÍ›cÿ¢‘7aÂ„‰ÆÏý\0\0\0ÿÿ:þØ\0\0\0IDAT\0ÂƒêÁÁ§z\0\0\0\0IEND®B`‚','3','2025-08-18 10:09:10');
INSERT INTO `users` VALUES ('27','testuser','$2y$10$kh5nsShh8uehS3IXTFWaEOLGnAQXP37ZWGQfhXKVGt16Jy3Aai3w.','testemail@gmail.com','client','‰PNG\r\n\Z\n\0\0\0\rIHDR\0\0\0È\0\0\0È\0\0\0­X®ž\0\0\0IDATxì]	\\TÕ÷ÿÎVEEÃB±~ej¤¹de¿Ê\\H*—,×,Ê4³,×,KÓRÓ\\²Ì-eÚ¢IîVjóW¿ÜrÉ%RSTT™áÏ/>Æ26Â,‡Ï|ß9÷ÜsÏ½÷{ï™÷æ=´F\Zå1˜ÞÆ{@ÿ0Ì@¡h:t\0ƒ9à=`¼øRè{Wx3f³Í›7¿ê9A®J;x”=ö*T¨€‡zÙÙÙùSFãÆóq$¿OV˜·b Y³fÈÈÈÀòåËqüøq´lÙ2|YYYØ±crss±uëVp‚äSÃŠ¯0°iÓ&9Õ¦M›¢\\¹r2Q¤AüýýmÛ¶¡nÝºœ ‚~ùyyyX½z5>Œ-[¶ $$$Ÿ:s$\'\'ƒ|víÚ­zõêð$T¬XÑ-Æ[¥J·‡\'­;õÆo”I‰n¸¡ÐµÔºté=:wî,ËJêëHïÚµ«¬\']Aù*©ì…Iò#P½’¤_\rä{ÿý÷ƒdq|É§8¾äW(|”lÛ¶í<P½ÊWo+LW¾J\ZùuïÞ½@ŸÊWI£6zùÈ¦$éŒ‚9 çC³X,ÐÃjµÊ²’ú:ÒéÔCRå«¤¾ÎH\'?Õ)IúÕ@¾”öÖKc,ÊŸ|©^IÒ¯ƒ@í%Ù\nƒò-¬^oW¾Jêë”îÈ½òURù&É@õJ’î¨Åi«÷\'ýjmÈG«ùS½ÞŸt²)PYAÙŠ’Ê—¤Fg`³ÙàŒ¿«}U‚8Æ=qâöíÛWbc3âÁ’“\rKvVþŒãÿi¹°¹S\\ºv&y½¡6õC:É«üôpµ?Å£øJ*ÊUV’lEü4¥%û/X/|hcÔcÏÑTÏ´aÛñœ;w.ß—|ê÷™é„ú¯|–_ßÄDôyyJ~™ê¯Ú$F>»wïÆ¡C‡ä‡/}ý‹c?ýÏ*Vc¶[PÄåñéã8êŽ<œK9€M?lÄâU»‘²q1r²Î_î3ûœCAôñv}÷¥ôÍ<º™gt½ŸãÜÓÒÒðýÆu8|4“ÿÀá#É2ŽjÓö{Ÿ›N¼JPõÅ‘…m$j[X]iÙ‹\ZSQuEWÛ÷ó:üþ×	lL·¢Á˜oÐ¤ßd<óÊûøò?Ka=¾{ŸÄÎ£™’x\nDƒ¤ö/ü¹	?ýyÕ´thš–ÿ.Nƒ‚Ù7W.#lÛ±UÄòÐhÀ<4¶@Ü) %ÏÃÌ·¦á›/¾Æ©_–c×žýX}ÌŠï÷CÂ/©˜¼ñOLûß	ÑÞ*A-TßJ®ü-{3£\rºu§ìVëY<þtwüwZOl³žCÂòíh:v\rZ^†‡_Z€¶/OÆ_\'Ó°ïð^ÎÌBÆ©bxyxpÀ¼3b26$}!ê¶Á*6íÖÃG°5åTlGÃ\"ñW@l>`ƒ\'‘¶åë|_+0¿K}lÚ}¸Ï¾8O\rŸŠÍŸÎÀºµk`µå¡y?ñFA—ŽÖ?ñ}r\n,ùOYƒFÏOÎC\\«þIÒ-Ê¨›¢±}Ç6ø!,´’ô%Þ©~é{PæÆŒ2§Îg‹:&ÿ|\rÞY†\"nk‰ÌÁ¾õ‹ñÇî\rØvøú,OFÇ)?\n_;ß*ÅS A••$›‚²‘T6’TÖƒl½t²H×ƒl\nÅ±“‘qìÔN«yg¢#ÂkLôÒ•kƒŸÉ$Î†Z¡H?Ÿ%	£úAeBãw@Û[ì÷U=Ùi(†#{Šö6ÄˆX	òu{¼ùr\'¡bo ×š‡Œ¬lXÄüýL°Y-Hjr-ÙÐÌ&|úd‚RLˆŠK ò×ÿ½(ÆgEŽ-\0ÕªUƒŸŸ_¾¯Åˆ—&.ÆŠ9¡ŽØ|f1\'MLò|öidˆKE\n–ýð;s9¹\ZNfd€²Ö&e•bXˆªz›ˆ—s~9rRÌÃ¾ql¢=õ¯p&yÚGîÇ¸7Â´u.Ê7~\\´³äû[…?9>öNl3â\\¦¹–<”/S^ðœ‡ù*\n_›D“j‘}1&“	&1Õ‡PE½UÆ%[FF:Ö¬Y‰›\"kâ‡?àà!;OÔÕ“DžMðìò~íÌfMÎ‘bù	5WÌ5#ƒ?·U-M¼Á¥œ<,}U%)ž£®ÊJ’A••$A_.®®ü”¤8UV’l}ùŸèK\\bUZÑ¬œ-îˆÆú‰½1g\\_´x´5,a$Q›^k#†\ZÐ»uJ 2]û¦§§ƒ×Ÿ9s†x—m¨Ž°id»Kå[¥Ü4º-Âš<Ž¦e­7´/&%>‹^¯ôÂãP¡vt~ï[4­dE›èÊQ?½cª`Ý3wæ÷OP\\Õÿé³‘v&=šÓYÊ¾y¨^aÓ˜vhÑµ»èÛŠ¦õkcí fX=ª~÷¿ÙåCë£Zôí¨hÅô–5°iX[$Mè‹~#ãÑìGìýŠMô¯°²èy{Y¦¾)AT$«‰ùU¬	‹H$ÿ»ÚK?²“/áæFÍ¤­mE+6í„/ÇM:=ƒÛcê¡öý-Qåîð„¿¥\ZÄ;&·¿~îBµ:ue;ŠñC±H\'—Á“OtATTM<×µjFçûR=ù®t7–¾ûÌ%»\rŸ|ü)·®‡»Êšp¾|8öêŒz­;á¦è¦ÒgÒáX=âòø)bHwÄµØÚàŸÊ×b7jC6ÅÔƒl½tù!69^à&Ié£ŽƒüôP±ô6Ò³è#¨\Z~x§ÌâÝžÚAìùk\0ªî©»ðtó 9î¢û@Ó0«ôSm‹’…ÅRvG\nÌ£ˆñëûT±ô6Ò•ýó1½°°g“ü1Ó›Õ;Kµ!~ãj…`Â¨g°løcù±U½ã<¨ìËgûe€\"Á‘(eW’6†ÒIêýU™¤#ÈOÙH\'PYIÒù¨žDéTÖCo\'@õJ’^}T™¤µ%]/I\'(»Òõe²AùT ?ÒI:‚æ®läCPåÂ¤ò!©@¾¤“t„ÞNº¯CžAô$(Âô6½îø.v5}[¥;ÛFïO›DÅ)JêÛå§êÈ_ézIv½Þ(ôe½nä¯¯wÔ•?IÇ:}YÏ=ùôõEéä«P”Ÿ¾Žüõe_ÔµZµjÁ„……9åïLìâøÞrË-¥Ú¿\Zãm·ÝVâã ªÿ’’5kÖ„/CÜÇ\0ÿ”Ü‹2À	â‹ÆC.98AJŽkîÉÐÎæþsÀ{àòÈ´ü‚þJé¬åØŽ€Áð¸¼.Z÷¡^Dc{‚È#<œ¾«ÈC¶ÉŸA$\r|`Œà1æ…­Ì€d€DÒÀfÀ˜Nc^ØÊH8A$\r|(”¯àññ\rÀÓ/šN¢ùáZg@ËI{\0æ€÷@Á=ÖD¾5ðDÒÀfÀ˜Nc^ØZxBœ ž°J<ÆRc€¤Ô¨çŽ=NOX%c©1À	RjÔsÇžÀ\0\'ˆ\'¬ÑY\\æÏ	â2*972À	â«Êsrœ .£’y#œ Þ¸ª<\'—1À	â2*972 Î²¡\0¸Ì|ð{€žÏ Äƒ(„NBˆa33@p‚f 8A\n!†ÍÌ\01P¢	B2˜Ob€Ä“V‹ÇZâp‚”8åÜ¡\'1À	âI«Åc-1L—zÒêFG‚Áð(¸nyA9â-gšƒp9œ .§”zÚéÓ§Áp\rV«Õ›öÏE0 Ñ¢2¬pôF#8å—1À	\"Þõ]‘*†í\ržŠ`@&ˆÍf3|%;A-¾’d#¨²’d#DDDÀ{`ŸÍ‘æ¦@e=ÈNeÁ)¿¼ˆM-,IGÐ‚œµ{?ùS!ˆ*ëAv*ç7`Å+ÐF\Z‹%&OÀéßÊ3Éç_ODª¸ô8xæ¼û´­;žŽw?X‰iÂ?ÛbÁ)9…ígÈzÚ a#àÒÏÆañ—ÐN[ƒíÓºaùŸÀû ó¤Ÿ‘“vÃç¬µîùZ¹r¥Ø–-[¤ÔÏæHe=ÈFÎ|ð\Z´‘#GŠ\ržƒý¢{û–B·âþØ1wÆ<|ø6jwï#mßÌ™}šãHv6FÍÝŒ†OÄÝíÁm•ËÈzÚ,ôJÐ³“öÍxYy~>B×¼Œ”ÔP´ÿýU4{{–Ï”uîxhÕªbccÑ A9<ýühŽTÖƒléÌ¯a@[ºtl¶ ¹ÉwþôB·!å—$ôíÕýÆvELˆýÏsñýðíªŸq_LFvmŒÞ/Þ‹¨ +§Ÿ—mõ›E±Ù¼;\Z<6÷6ˆ‚yítÜÐí=4¿+\ZÿjÝ›V-F\\ŸaÊÕí¤ÉdBRRäØôóS:%„Ò•”Î|ð\Z´¸¸‡Å·ŸâZ6]&Ô®ßâÓ»°G\nØäÆ ?´| þ“¸ü\n‘ueý¥T„¤b§f›R}¢ÙÍ¸©UÔ*´¾¯êw|~!UñllcYï	š—#ˆ+G›Ssag·g@~H§wB‚ãb“à¬}óæÍX¿~½×€>‡Ä…•”¤Û¯8Ð)Ä‰Â~† Å¦Öƒl½t²H×ƒl„„‡‡{\rÊ”)#/=in\nFó¦:§Øgg·g@&]*had\'¡8¾Ê‡ü	ª¬¤‘êÈN ÝEÙêÈFø§q¨½Qesûç:Ç€vçwÊ;5t·†tGÙÉF(Ž¯ò!‚*+id£:²HwDQv£:²þijoGÙkÔ¨áûìíöhn?Bà51À\\Ã\0\'ˆkxä(^Ê\0\'ˆ—.,OË5p‚¸†GŽâ¥p‚xéÂò´\\Ã\0\'ˆkxô¥(>5WNŸZnž¬³p‚8Ëûûœ >µÜ<Ygàq–1ö÷)8A|j¹Ý}²î7>N÷[‘1À	âF‹ÁCq?ò¿0•——\'¿óp5©ÿ„3¾ª3m®æ«êUl’Êv5I¾\n…ùºßrñˆJš¾ß@ÈÍÍ•_·½š$_g|¯¥ÍÕâ«z›¤²]M’¯Ba¾%½ÜŸû1 Ñæ`äÂˆ÷[.Ñ53p\r9AÄ™Ó(9Èvœr3/b€„Ä‹¶³ë§’ÿD]³´ÈÏbÄƒëéæˆžÆ\0ŸAøâi{¶DÇ«åää€aÌA‰®wæ–ç+ÿ’ƒ.;|	n¹b<¨e€/±ø«D7œ§uÆ	Â	âi{¶DÇ«ÑBc4Èÿãyz.Jt%¸3·d€YÑ-—…å.”r‚¸\r<fÀ˜Nc^ØÊH8A$\r|`Œà1æ…­Ì€d€DÒÀfÀg@±^\0\0ØIDAT˜ïMãù²•pŠ-99ÉCœb’½’>ƒxå²ò¤\\ÅÀ¥9‡íËÞÃôéÓ‘šk•±ÇMÂ¸ñs¥¾dÁlŒŸ»Vê‹?™Š¥[þ‹“gÊòÐékÐïÅ!R{Ø@¬/ãœÊV;œ9„Í+£oßXš´Mú}9j¤”SK_*ÝµÓ\',¢0ìÿ2gOnDBÂDQÞéÿ2~[=_ê+^’Ù©DŒ©°!¶<àÏŸ¿Bæ©£Â6]ÄÊÄôwßúÈþ/IiŽfäŠ£\'ÎQ	9ö=Y\'Œüb\n0 ­Úu‰ño\r\rE¨Ùž3ÍÛ=‹¡CzÖLtìÞCz¶€õB*:=3\0î¿´€ 5’zŠl6É8å‚ŽQÂ,âÝóH\'h¦Êè#@LÂXüv\nð/+úõë‡,?àl–è7h iX´h6ìÍ€	ÁxcÄ“²ÍkOÅÿæX¹ó¸ìC\ZÅÁ/÷œè¯4„ã·yCÞ¤3l¹\'*æ¿rè÷t+Ù‡¿ŸYÚDWH_7	¾ûAö%B ¦ÍÓÒ‡t3 g@‹<°>/lþèÒ¥ü4“ÐÕ+0WÀáTNƒ¹\\Uœê¯|ëBî¾§ Þ¹_1V¾ƒCó“qD~ +\'“^ ýògÿÀÍþ\ZöO·ŸE4sy‹7ò\rë7ç»Ð8ÚÔ•e«\r¬]·8wÝ[DÃ´áiW‡a“×Ëþv² aÏqÁEüçËß¥mÇEå%ò¿B¸´‘%¦m_„5iCê5ùÚí?‡L±Áïh_`Öü«‚(‡Xqc!P	°YQV¨µc‡\"¤\\C¡q·UÂ¤	1aìôxm|}š´«Ã Huæ¬QR¢ü-°Z­è4úM<7¨·yVÚ_h×DJhÔP£íh„T¹KÚZ<Ôð–íZ½8¼@ïŒEúÔ­Lç©\"¾©Ô\"¼±8\0C‡IIk`(b*f£jYŠèØ@ÌMj|`\n2`ß!m\\b˜Kp‚\\\"‚3`Ä€…(†!F„±Í·à3ˆ{­7ÆÍ¸” Û±eÎ0:tÙô0ArÎ·[±|\\¡ƒ\'ÌÅÄ~ý¥>uÑ:cËd¹ëëŸ£M¬ýCq·GZc~¯GìqÄã”§?Þ¤îFZÊQ´nÕ§œ‘m†tx]Ê/–¾T˜1òúy.›AEl›ôcË‘ßU–Ÿh÷$’>y[êó{·•’\'1v‹{WÙ°Š±¯1;¿š!l‡€<ý¸PêñÝâ¥÷#0}sªhš	ûó`âg›d0ò‹(À€öþÊmhý8Ý:ÍAbb\"òÄ&#J57t6†‰¯öÄàéÓ›qº<„)Sf‘K>‚Ë7ê¬¸#º*ÄCç¢˜ûL]ˆ<A¥ˆHñL#\07DÐ1 ïÂQ˜ó¿Ñ^CPP@Ìó VñÜE”ž~úiŒK:\"4 aÖ{\"6ðÅWŸãÈMÝ0~ùiW‡ê­Gc@ü@ø#\'WF‹ç\'¡^»X<ÿÜs€Éµ\"+£V­ZÈJ;*ÇeÐðÐ;˜·à¡Ù_fÿ\01ûØì>2v´–çW\"é«É¢ˆyóæ!ÈOºý•òëB °þ¸¿%-„h$~:ŠGvA…»ÛJ§.›KùhÇKO·Í2m7“fÆÌñt\\zØé;—\":Ø~Ÿ÷ÌA¨^½:’ÅÃ•¤Ùï\0™¥chl\r©UÅÃÂxXÎEüƒ5Ð`ßG—ìv1¸S¬X³3rP-ö]ÐÏ¸!Ó±jõjÌ¤’å\"¢å¸¨Ôä‰!ˆ‰³Ÿ™¨\\.¬Šßê%.Ðní0š	húì[j»«\Z\"\Zv“¶nÍoÅ±v½YípôzsÊ†ß%ë¹£\Z¾ül ¾]2¯¾?=>úVÚÕá…iŸIõ»UKV¯ƒ”=ÆÏÅXúÕQŠ*¼‘ð\ZP.\Z\"…ˆé3aÕã¤>zì,ø•”úÃƒß/ÐÇ»K>—ö[B¤¤ÃÐñãI ºœµZ‰0ý“÷¥”‡ J¸#ˆõ—Å>FIÉfÀ‘ÍÑÀeoe€çu-p‚\\kÜÆgÐ’““\r¿‘ÌvŸÙ<ÑÂà3HáÜp\r3€K	r\ZÛ—OÆ’%KpÆb“´¼7s>fNúPê‹.ÀäÙöÛ¢_üg>­Y‹“›gÉº¡3ÖâùÃ¤þÆk/â»QÏÉ8ÙÀçœ–ßÙ±y-úö}	ë7ï—~Ÿ|CÊioŒ¾TØûÓ2,™?	°Øo=ÑoŸ=¹o\'Ž£jŒð2~Y5_êß%ØŸÉPáÂ‘_EŒÏÄíä3ò·‰ÿØøÒÿÚ+lK\0k–|2Eê£^\Z,%ÝvÞsâ¢hš‹™!·¦Î•u²Àf@Ç€¶bûQŸ˜‘’’‚2&qKK8ÜùÀcxnP_Ð†íÒ­;^êó¬Ná‰Î=Ð%¶\rh¿¤ùŠgö;Z5+‡\"PË“qîõÅÍ^³†ú÷´€fªˆï¹YDî}s$¶üÊÕDÇŽ‘\'n@Yoo‡Ž=A8bÚ´iøê©âÙI ^m6’0ë}(ó –ýö7Í2Œ<”¯!úK…phéHÔz ÊU4	[\n`FÇ¸»e&k®´É¦?OÅÚ•›\0“ú¹åž8éC:ã\Zðâ&Ú¿Ž¬ÀŒ™o‰) ÿþð7›„nåÙhWÀÁsy°YS`.W\rgóò°{ñ×i îÿw=!óðÒ«‰òf‡~C>G<-œò\nÅ.—^y»)¶þ±ÙÃ/Y*â™áÚUß‹2=ç†lÿT“pQù‰$%ÍEîù³xê¾H”Û<OÚÕ¡÷ËJÿÝ©VÔîø&ü,9˜úÁ»MÄU~Á#¤ÊõÚôAÕ»!•ÁÉ€V»m<ÒENÜað}“VM4¶\":ÄÍØ,(o2¡N»„”‰ý´ª*¿2yÂhÃïƒœe¿LSß1…Ö¡fh?ê-q†ê-ôÓ\"]€îºˆÁ÷Abc{þeD=ð`ÿá¾2sf¢´×	—ç©¿<T<EZqf‚Á÷A,þ¡¨Wáøû ‚$~É€Vd-W2>Î\0\'ˆo\0ž~ÑhQQQˆbrP4u\\[”v|)íàþÝšN·^\\i3À	RÚ+Àý»5œ n½<<¸Òf€¤´W€û/-ŠÕ/\'H±hb\'_e€ÄWWžç],8AŠE;ù*œ ¾ºò<ïb1À	R,šØÉW¸¶ñU¶xÞ>Ç\0\'ˆÏ-9OØ8Aœa‹}}ŽNŸ[rž°3h6›\r×pàñìëhééép\'xúX<cÙy”Åe@£ÿh±X@ ]²ô6ÒÉF ]²ô6¥“ ÊJ’ ÊJ’ ÊJ’ ÊzIv‚ÞF:Ù¤ëA6‚ÞF:Ù¤;‚ì#{NŽî¯DwØÏ­ÐŒ[-¾ºôRe’z½NuÊ¿J•*pDÕªUA¸^vŠKñ	¤ëA6‚ÞF:Ù¤ëA6‚²U®\\YþQýIWÐóàÖ«ÍƒsšŸAÔ‚Iµù¥‘¯Ó£pó&“)?Aôó%.¨LRÁÍ§ÂÃs’y‰E‹LØ}Ê*7Â>«]’Maí±Ó†—aª^/Õ6³ÿA¹ÓÖ(“ÇJýü”®?s(›ÇNnÈ€L‹õo™¶K‰‘–jÅ¸©_bô’=HœµYÖYswHù·ðIXô+Þ]Ÿ‚¹+÷H›ÚW¾‹jˆë3Tv¼ðˆ³=‰øðEïæÔ¾ÎüZðÀIÇëwp*ò£>*ýccc¥¤9ªù‘¤²¤3¼†Ívîxþm^ZpZü³§O!0¤,ÔøÃŸm,ëÛðëÉ,d¦]@Õ*Áh›ó)º>T[&µ¡¶\nzv–ÏƒsÂ°ÓNÍ	B¥ðPQÊG˜pþ|®ÔÝñ°lÙ2$$$ ))Iæ¨?cPÙÒ™^Ã€f\r\n‡Õ\" ÎµCm M~ß¡xþ©û±dCjþeÕ=•Ê¢~˜n*ïž÷ÔÄºß5éK›„Ú(I›H±sßÛ[…\Z€9ýFbç$~0o\rŠÃýˆÄé¡zóÞ¢Þ}_” jtjŽ4?ÒAv‚ògéÈK,ÇÅ¦2-v·þePYJ†ñ}®¨£6äçÔœÍKÍOI²éA¼lÅ%Og@&-¬~¡I\'›•AuF6²ïÜ¹Þš+ÍMÊzÊž¾!xü	BË¸òÎ]ñ9±·-H-—¼NñÙËÙD0ò÷óóC½zô¯ ¼a[ðZÃ†\rÁøçÔ­[WqÊÒ‹à_w÷¢Åä©¸žN×sÊ½ˆNw_L_©2À	RªôsçîÎ\0\'ˆ»¯¯Tà)Uú¹swg€ÄÝWˆÇWªhgÏžÃ79àu/|Ý÷íÛ\'“Ÿ¤»èIºÑÓu¶ÙÇy ¯[S†ð%±À`\na€¤bØÌœ Äƒ(„NBˆaó?bÀk\Zs‚xÍRòD®œ ×ƒUŽé5p‚xÍRòD®œ ×ƒUŽé5p‚xÍRúÊDJvžœ %Ë7÷æap‚xØ‚ñpK–N’å›{ó08A<lÁx¸%Ë\0\'HÉòÍ½¹3cã1 …MÌ€b€D1Á’0`€Ä€61ŠmãÆ`0¼®Ü”$\Zý«1Æ£`˜Ç= „.\0‡`¼’þâ•ËÊ“rœ ®b’ãx%œ ^¹¬<)W1À	â*&9ŽW2à	â•¼ó¤<„NY(fé0À›—ÿ61ÿmfƒ=°gÏ™‘ü·yùoóÂÿvîõsDD„=Aä‘Ì\03`È€o1¤„ÌÀe8A.sÁ\Z3pœ WPÂfà2œ —¹`¸‚N+(a3p™NË\\¸Tã`ÞÁÀÿ\0\0ÿÿŸí×Y\0\0\0IDAT\0â1\'2\0\0\0\0IEND®B`‚','1','2025-08-18 10:29:50');

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

