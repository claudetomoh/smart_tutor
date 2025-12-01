CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `bio` text DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','tutor','admin') NOT NULL DEFAULT 'student',
  `status` enum('active','inactive','suspended','warned') NOT NULL DEFAULT 'active',
  `status_reason` varchar(255) DEFAULT NULL,
  `suspended_until` datetime DEFAULT NULL,
  `warnings_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `last_warning_at` datetime DEFAULT NULL,
  `policy_notes` text DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT '0',
  `last_failed_login` datetime DEFAULT NULL,
  `two_factor_secret` varchar(32) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `backup_codes` json DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `role_status` (`role`,`status`),
  KEY `two_factor` (`two_factor_enabled`,`status`),
  KEY `suspension_window` (`status`,`suspended_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `email_ip` (`email`,`ip_address`),
  KEY `attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_actions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) UNSIGNED NOT NULL,
  `action_type` enum('booking_cancel','booking_notify','user_warn','user_suspend','user_restore','payment_review','message_send','user_force_logout') NOT NULL,
  `target_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `booking_id` bigint(20) UNSIGNED DEFAULT NULL,
  `session_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text,
  `metadata` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `action_lookup` (`action_type`,`created_at`),
  KEY `admin_lookup` (`admin_id`,`created_at`),
  CONSTRAINT `admin_actions_admin_fk` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`),
  CONSTRAINT `admin_actions_user_fk` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_threads` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject` varchar(190) DEFAULT NULL,
  `context_type` enum('booking','session','support','general') DEFAULT 'general',
  `context_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `last_message_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_preview` varchar(255) DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `thread_context` (`context_type`,`context_id`),
  CONSTRAINT `thread_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `thread_participants` (
  `thread_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('student','tutor','admin','support') NOT NULL,
  `last_read_at` datetime DEFAULT NULL,
  `notifications_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `muted_until` datetime DEFAULT NULL,
  PRIMARY KEY (`thread_id`,`user_id`),
  KEY `participant_user` (`user_id`),
  CONSTRAINT `thread_participant_thread_fk` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `thread_participant_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_messages` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `author_id` bigint(20) UNSIGNED NOT NULL,
  `audience` enum('admins','tutors','students','all_users') NOT NULL DEFAULT 'admins',
  `subject` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `priority` enum('normal','important','critical') NOT NULL DEFAULT 'normal',
  `pinned` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `audience_priority` (`audience`,`priority`,`created_at`),
  CONSTRAINT `admin_messages_author_fk` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `source` enum('admin','system','security') NOT NULL DEFAULT 'admin',
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `level` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `data` json DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_read_state` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `user_notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `user_notifications_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;