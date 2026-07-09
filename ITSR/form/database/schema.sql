CREATE DATABASE IF NOT EXISTS `itsr_form`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `itsr_form`;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(150) NOT NULL DEFAULT '',
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NULL DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','staff','department_user') NOT NULL DEFAULT 'staff',
    `department` VARCHAR(150) NOT NULL DEFAULT '',
    `status` ENUM('active','blocked') NOT NULL DEFAULT 'active',
    `profile_picture_path` VARCHAR(255) NULL DEFAULT NULL,
    `profile_picture_position_x` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `profile_picture_position_y` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_username` (`username`),
    UNIQUE KEY `unique_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `actor_name` VARCHAR(100) NOT NULL DEFAULT '',
    `actor_role` VARCHAR(20) NOT NULL DEFAULT '',
    `action_type` VARCHAR(50) NOT NULL DEFAULT '',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_action_type` (`action_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `deleted_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_request_id` INT UNSIGNED NOT NULL,
    `company` VARCHAR(255) NOT NULL DEFAULT '',
    `department` VARCHAR(255) NOT NULL DEFAULT '',
    `requestor_name` VARCHAR(255) NOT NULL DEFAULT '',
    `requestor_email` VARCHAR(255) NOT NULL DEFAULT '',
    `requestor_phone` VARCHAR(100) NOT NULL DEFAULT '',
    `status` VARCHAR(30) NOT NULL DEFAULT '',
    `location` VARCHAR(255) NOT NULL DEFAULT '',
    `assign_to` VARCHAR(255) NOT NULL DEFAULT '',
    `request_date` DATE NULL,
    `deleted_by_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `deleted_by_username` VARCHAR(100) NOT NULL DEFAULT '',
    `original_created_at` DATETIME NULL,
    `pdf_path` VARCHAR(255) NOT NULL DEFAULT '',
    `deleted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `snapshot_json` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_original_request_id` (`original_request_id`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company` VARCHAR(255) NOT NULL DEFAULT '',
    `department` VARCHAR(255) NOT NULL DEFAULT '',
    `requestor_email` VARCHAR(255) NOT NULL DEFAULT '',
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'medium',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `latest_user_update_at` DATETIME NULL,
    `resubmitted_at` DATETIME NULL,
    `request_date` DATE NULL,
    `requestor_name` VARCHAR(255) NOT NULL DEFAULT '',
    `requestor_phone` VARCHAR(100) NOT NULL DEFAULT '',
    `requestor_signature_name` VARCHAR(100) NOT NULL DEFAULT '',
    `requestor_signed_at` DATETIME NULL,
    `requestor_signature_path` VARCHAR(255) NULL,
    `approved_by_name` VARCHAR(255) NOT NULL DEFAULT '',
    `approved_by_phone` VARCHAR(100) NOT NULL DEFAULT '',
    `approver_signature_name` VARCHAR(100) NOT NULL DEFAULT '',
    `approver_signed_at` DATETIME NULL,
    `approved_by_signature_path` VARCHAR(255) NULL,
    `service_types` TEXT NULL,
    `other_service_specify` VARCHAR(255) NOT NULL DEFAULT '',
    `location` VARCHAR(255) NOT NULL DEFAULT '',
    `ip_tag_no` VARCHAR(255) NOT NULL DEFAULT '',
    `problem_description` TEXT NULL,
    `assign_to` VARCHAR(255) NOT NULL DEFAULT '',
    `task_type` VARCHAR(50) NOT NULL DEFAULT 'Normal',
    `sla_hours` DECIMAL(5,2) NULL DEFAULT NULL,
    `assigned_at` DATETIME NULL,
    `due_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `actual_working_minutes_taken` INT NULL,
    `total_hour_taken_display` VARCHAR(100) NULL DEFAULT NULL,
    `sla_result` VARCHAR(20) NOT NULL DEFAULT 'Pending',
    `is_overdue` TINYINT(1) NOT NULL DEFAULT 0,
    `date_receive` DATE NULL,
    `date_complete` DATE NULL,
    `total_hour_taken` VARCHAR(100) NOT NULL DEFAULT '',
    `staff_signature_path` VARCHAR(255) NULL,
    `corrective_action` TEXT NULL,
    `pdf_path` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` INT UNSIGNED NOT NULL,
    `attachment_type` VARCHAR(50) NOT NULL,
    `original_file_name` VARCHAR(255) NOT NULL,
    `stored_file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_type` VARCHAR(120) NOT NULL DEFAULT '',
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_request_id` (`request_id`),
    KEY `idx_attachment_type` (`attachment_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `request_returns` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` INT UNSIGNED NOT NULL,
    `returned_by` INT UNSIGNED NULL DEFAULT NULL,
    `return_reason` VARCHAR(255) NOT NULL,
    `missing_document` VARCHAR(100) NOT NULL DEFAULT '',
    `return_comment` TEXT NULL,
    `resubmitted_at` DATETIME NULL,
    `resubmission_note` TEXT NULL,
    `returned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_request_id` (`request_id`),
    KEY `idx_returned_at` (`returned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `request_tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_request_id` INT UNSIGNED NOT NULL,
    `task_no` INT UNSIGNED NOT NULL DEFAULT 1,
    `task_title` VARCHAR(255) NOT NULL,
    `task_description` TEXT NULL,
    `assigned_to` VARCHAR(255) NOT NULL DEFAULT '',
    `status` VARCHAR(30) NOT NULL DEFAULT 'assigned',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'medium',
    `task_type` VARCHAR(50) NOT NULL DEFAULT 'Normal',
    `sla_hours` DECIMAL(5,2) NULL DEFAULT NULL,
    `assigned_at` DATETIME NULL,
    `due_at` DATETIME NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `actual_working_minutes_taken` INT NULL,
    `total_hour_taken_display` VARCHAR(100) NULL DEFAULT NULL,
    `sla_result` VARCHAR(20) NOT NULL DEFAULT 'Pending',
    `is_overdue` TINYINT(1) NOT NULL DEFAULT 0,
    `date_assigned` DATE NULL,
    `date_started` DATE NULL,
    `date_completed` DATE NULL,
    `solution` TEXT NULL,
    `is_read_staff` TINYINT(1) NOT NULL DEFAULT 0,
    `staff_read_at` DATETIME NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_parent_request_id` (`parent_request_id`),
    KEY `idx_assigned_to` (`assigned_to`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `request_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` INT UNSIGNED NOT NULL,
    `task_id` INT UNSIGNED NULL DEFAULT NULL,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `action` VARCHAR(80) NOT NULL,
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_request_id` (`request_id`),
    KEY `idx_task_id` (`task_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
