-- Tutoring and business logic tables

-- Subject areas
CREATE TABLE IF NOT EXISTS subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    parent_id BIGINT UNSIGNED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (parent_id) REFERENCES subjects(id),
    KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tutor profiles
CREATE TABLE IF NOT EXISTS tutor_profiles (
    tutor_id BIGINT UNSIGNED NOT NULL,
    hourly_rate DECIMAL(10,2) NOT NULL,
    availability JSON,
    education TEXT,
    teaching_experience TEXT,
    verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    rating DECIMAL(3,2),
    total_reviews INT UNSIGNED DEFAULT 0,
    total_sessions INT UNSIGNED DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tutor_id),
    FOREIGN KEY (tutor_id) REFERENCES users(id),
    KEY `verification_rating` (`verification_status`, `rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tutor subjects (many-to-many relationship)
CREATE TABLE IF NOT EXISTS tutor_subjects (
    tutor_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    experience_years INT UNSIGNED,
    proficiency_level ENUM('beginner', 'intermediate', 'advanced', 'expert') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tutor_id, subject_id),
    FOREIGN KEY (tutor_id) REFERENCES users(id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    KEY `proficiency` (`proficiency_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student profiles
CREATE TABLE IF NOT EXISTS student_profiles (
    student_id BIGINT UNSIGNED NOT NULL,
    education_level VARCHAR(50),
    learning_preferences JSON,
    profile_completion TINYINT UNSIGNED DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id),
    FOREIGN KEY (student_id) REFERENCES users(id),
    KEY `profile_completion` (`profile_completion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tutoring sessions
CREATE TABLE IF NOT EXISTS tutoring_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    tutor_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    booking_request_id BIGINT UNSIGNED DEFAULT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    status ENUM('scheduled', 'ongoing', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'scheduled',
    meeting_link VARCHAR(255),
    price DECIMAL(10,2) NOT NULL,
    payment_status ENUM('pending', 'initiated', 'paid', 'refunded', 'failed') NOT NULL DEFAULT 'pending',
    platform_fee DECIMAL(10,2) DEFAULT 0,
    commission_rate DECIMAL(5,2) DEFAULT 0,
    commission_amount DECIMAL(10,2) DEFAULT 0,
    tutor_payout DECIMAL(10,2) DEFAULT 0,
    payout_status ENUM('pending','scheduled','paid','on_hold') DEFAULT 'pending',
    payout_released_at DATETIME DEFAULT NULL,
    notes TEXT,
    cancellation_reason TEXT,
    cancelled_by BIGINT UNSIGNED,
    admin_notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (tutor_id) REFERENCES users(id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id),
    FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id),
    KEY `session_status` (`status`, `start_time`),
    KEY `payment_status` (`payment_status`),
    KEY `payout_status_idx` (`payout_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session feedback
CREATE TABLE IF NOT EXISTS session_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    reviewee_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
    feedback_text TEXT,
    anonymous BOOLEAN DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY `session_reviewer` (`session_id`, `reviewer_id`),
    FOREIGN KEY (session_id) REFERENCES tutoring_sessions(id),
    FOREIGN KEY (reviewer_id) REFERENCES users(id),
    FOREIGN KEY (reviewee_id) REFERENCES users(id),
    KEY `rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED DEFAULT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    message_text TEXT NOT NULL,
    context_type ENUM('booking','session','support','broadcast','general') DEFAULT 'general',
    context_id BIGINT UNSIGNED DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME,
    deleted_by_sender BOOLEAN DEFAULT FALSE,
    deleted_by_recipient BOOLEAN DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (thread_id) REFERENCES message_threads(id) ON DELETE SET NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id),
    KEY `conversation` (`sender_id`, `recipient_id`, `created_at`),
    KEY `thread_lookup` (`thread_id`,`created_at`),
    KEY `context_lookup` (`context_type`,`context_id`),
    KEY `unread` (`recipient_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    initiator_id BIGINT UNSIGNED DEFAULT NULL,
    action_url VARCHAR(255) DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME,
    data JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (initiator_id) REFERENCES users(id),
    KEY `user_unread` (`user_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments
CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('pending', 'initiated', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(50),
    transaction_id VARCHAR(255),
    initiated_by BIGINT UNSIGNED DEFAULT NULL,
    platform_fee DECIMAL(10,2) DEFAULT 0,
    commission_amount DECIMAL(10,2) DEFAULT 0,
    tutor_payout DECIMAL(10,2) DEFAULT 0,
    payout_status ENUM('pending','scheduled','paid','on_hold') DEFAULT 'pending',
    error_message TEXT,
    refund_reason TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (session_id) REFERENCES tutoring_sessions(id),
    FOREIGN KEY (initiated_by) REFERENCES users(id),
    KEY `transaction` (`transaction_id`),
    KEY `payment_status` (`status`, `created_at`),
    KEY `payout_status` (`payout_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User files (for documents, certificates, etc.)
CREATE TABLE IF NOT EXISTS user_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    purpose ENUM('profile', 'verification', 'session', 'message') NOT NULL,
    reference_id BIGINT UNSIGNED,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    KEY `user_files` (`user_id`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking requests captured before a tutoring session is confirmed
CREATE TABLE IF NOT EXISTS booking_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tutor_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NULL,
    student_name VARCHAR(255) NOT NULL,
    student_email VARCHAR(255) NOT NULL,
    student_phone VARCHAR(40) DEFAULT NULL,
    requested_for DATETIME NOT NULL,
    timezone VARCHAR(60) DEFAULT NULL,
    message TEXT,
    status ENUM('pending','accepted','declined','expired','cancelled') NOT NULL DEFAULT 'pending',
    reference VARCHAR(20) NOT NULL UNIQUE,
    admin_notes TEXT,
    status_changed_by BIGINT UNSIGNED DEFAULT NULL,
    notified_tutor_at DATETIME DEFAULT NULL,
    notified_student_at DATETIME DEFAULT NULL,
    cancelled_by_admin BIGINT UNSIGNED DEFAULT NULL,
    cancellation_reason TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (tutor_id) REFERENCES users(id),
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (status_changed_by) REFERENCES users(id),
    FOREIGN KEY (cancelled_by_admin) REFERENCES users(id),
    KEY `tutor_status` (tutor_id, status),
    KEY `requested_for` (requested_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','initiated','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    notes TEXT,
    admin_id BIGINT UNSIGNED DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    resolution_notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (session_id) REFERENCES tutoring_sessions(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (admin_id) REFERENCES users(id),
    KEY `payment_request_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    tutor_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED DEFAULT NULL,
    commission_rate DECIMAL(5,2) NOT NULL,
    commission_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending','due','paid','refunded') NOT NULL DEFAULT 'pending',
    noted_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (session_id) REFERENCES tutoring_sessions(id),
    FOREIGN KEY (tutor_id) REFERENCES users(id),
    FOREIGN KEY (booking_id) REFERENCES booking_requests(id),
    FOREIGN KEY (noted_by) REFERENCES users(id),
    KEY `commission_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    author_id BIGINT UNSIGNED NOT NULL,
    audience ENUM('admins','tutors','students','all_users') NOT NULL DEFAULT 'admins',
    subject VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    priority ENUM('normal','important','critical') NOT NULL DEFAULT 'normal',
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY `audience_priority` (`audience`,`priority`,`created_at`),
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    source ENUM('admin','system','security') NOT NULL DEFAULT 'admin',
    title VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    level ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    data JSON DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY `user_read_state` (`user_id`,`is_read`,`created_at`),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;