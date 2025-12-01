#!/usr/bin/env php
<?php

declare(strict_types=1);

// Load configuration
$config = require __DIR__ . '/../config/database.php';

// Create database connection without database selected
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['charset']
    );
    
    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

// Create database if it doesn't exist
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '{$config['database']}' created successfully\n";
} catch (PDOException $e) {
    die("Error creating database: " . $e->getMessage() . "\n");
}

// Select the database
$pdo->exec("USE `{$config['database']}`");

// Function to run SQL file
function runSQLFile(PDO $pdo, string $file): void {
    if (!file_exists($file)) {
        echo "Warning: SQL file not found: $file\n";
        return;
    }

    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        echo "Successfully executed SQL file: $file\n";
    } catch (PDOException $e) {
        echo "Error executing SQL file $file: " . $e->getMessage() . "\n";
    }
}

// Run SQL files in order
$sqlFiles = [
    __DIR__ . '/../db/schema.sql',
    __DIR__ . '/../db/tutoring_schema.sql',
    __DIR__ . '/../db/security_schema.sql',
    __DIR__ . '/../db/security_audit.sql',
    __DIR__ . '/../db/security_analytics.sql'
];

foreach ($sqlFiles as $file) {
    runSQLFile($pdo, $file);
}

$subjectMap = seedSubjects($pdo);
seedTutorDirectory($pdo, $subjectMap);

// Create default admin user if it doesn't exist
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $defaultAdmin = [
            'email' => 'admin@smarttutor.com',
            'name' => 'System Administrator',
            'password_hash' => password_hash('CHANGE_THIS_PASSWORD', PASSWORD_DEFAULT),
            'role' => 'admin'
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO users (email, name, password_hash, role)
            VALUES (:email, :name, :password_hash, :role)
        ");
        $stmt->execute($defaultAdmin);
        
        echo "Default admin user created successfully\n";
        echo "Email: {$defaultAdmin['email']}\n";
        echo "Password: CHANGE_THIS_PASSWORD (Please change this immediately!)\n";
    }
} catch (PDOException $e) {
    echo "Error creating default admin user: " . $e->getMessage() . "\n";
}

echo "\nDatabase initialization completed!\n";
echo "Next steps:\n";
echo "1. Change the default admin password\n";
echo "2. Update database credentials in config/database.php\n";
echo "3. Set up proper permissions for the database user\n";
echo "4. Review seeded tutor accounts (passwords use Password123!) before production\n";

function seedSubjects(PDO $pdo): array {
    $subjects = [
        ['name' => 'Mathematics', 'description' => 'Core mathematics tutoring for secondary and pre-university levels.'],
        ['name' => 'Physics', 'description' => 'Mechanics, electricity, and modern physics enrichment.'],
        ['name' => 'English', 'description' => 'Academic writing, grammar, and comprehension skills.'],
        ['name' => 'Biology', 'description' => 'Cell biology, genetics, and human anatomy coaching.'],
        ['name' => 'Chemistry', 'description' => 'Organic, inorganic, and physical chemistry support.'],
        ['name' => 'Computer Science', 'description' => 'Programming fundamentals, algorithms, and computational thinking.'],
        ['name' => 'History', 'description' => 'African and world history exam preparation.'],
        ['name' => 'Social Studies', 'description' => 'Civics, governance, and cultural studies guidance.'],
        ['name' => 'Essay Writing', 'description' => 'Structured writing for academic and standardized assessments.'],
    ];

    $pdo->beginTransaction();

    $select = $pdo->prepare('SELECT id FROM subjects WHERE name = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO subjects (name, description, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');

    foreach ($subjects as $subject) {
        $select->execute([$subject['name']]);
        if ($select->fetchColumn()) {
            continue;
        }
        $insert->execute([$subject['name'], $subject['description']]);
    }

    $pdo->commit();

    $map = [];
    $stmt = $pdo->query('SELECT id, name FROM subjects');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[$row['name']] = (int) $row['id'];
    }

    echo "Seeded subject catalogue (" . count($subjects) . " entries ensured).\n";

    return $map;
}

function seedTutorDirectory(PDO $pdo, array $subjectMap): void {

    $tutors = [
        [
            'email' => 'jane.doe@smarttutor.test',
            'name' => 'Jane Doe',
            'password' => 'Password123!',
            'profile' => [
                'hourly_rate' => 25.00,
                'rating' => 4.9,
                'total_reviews' => 18,
                'total_sessions' => 120,
                'education' => 'BSc Mathematics, University of Ghana',
                'teaching_experience' => 'STEM specialist with 8 years of experience preparing students for WAEC and IB exams.',
                'availability' => [
                    'days' => ['Mon', 'Wed', 'Sat'],
                    'modes' => ['online', 'in-person']
                ],
            ],
            'subjects' => [
                ['name' => 'Mathematics', 'experience_years' => 8, 'proficiency_level' => 'expert'],
                ['name' => 'Physics', 'experience_years' => 6, 'proficiency_level' => 'advanced'],
            ],
        ],
        [
            'email' => 'kwame.mensah@smarttutor.test',
            'name' => 'Kwame Mensah',
            'password' => 'Password123!',
            'profile' => [
                'hourly_rate' => 18.00,
                'rating' => 4.7,
                'total_reviews' => 24,
                'total_sessions' => 90,
                'education' => 'MA English Literature, University of Cape Coast',
                'teaching_experience' => 'Cambridge-trained English tutor focused on academic writing and IELTS preparation.',
                'availability' => [
                    'days' => ['Tue', 'Thu', 'Fri'],
                    'modes' => ['online']
                ],
            ],
            'subjects' => [
                ['name' => 'English', 'experience_years' => 7, 'proficiency_level' => 'expert'],
                ['name' => 'Essay Writing', 'experience_years' => 7, 'proficiency_level' => 'advanced'],
            ],
        ],
        [
            'email' => 'aisha.bello@smarttutor.test',
            'name' => 'Aisha Bello',
            'password' => 'Password123!',
            'profile' => [
                'hourly_rate' => 22.00,
                'rating' => 4.8,
                'total_reviews' => 15,
                'total_sessions' => 75,
                'education' => 'BSc Biochemistry, KNUST',
                'teaching_experience' => 'Biochemist offering practical lessons with virtual lab simulations.',
                'availability' => [
                    'days' => ['Wed', 'Thu', 'Sun'],
                    'modes' => ['online', 'hybrid']
                ],
            ],
            'subjects' => [
                ['name' => 'Biology', 'experience_years' => 5, 'proficiency_level' => 'advanced'],
                ['name' => 'Chemistry', 'experience_years' => 5, 'proficiency_level' => 'advanced'],
            ],
        ],
        [
            'email' => 'samuel.owusu@smarttutor.test',
            'name' => 'Samuel Owusu',
            'password' => 'Password123!',
            'profile' => [
                'hourly_rate' => 27.00,
                'rating' => 4.6,
                'total_reviews' => 11,
                'total_sessions' => 64,
                'education' => 'BSc Computer Engineering, Ashesi University',
                'teaching_experience' => 'Software engineer teaching Python, JavaScript, and robotics for teens.',
                'availability' => [
                    'days' => ['Mon', 'Tue', 'Sat'],
                    'modes' => ['online', 'in-person']
                ],
            ],
            'subjects' => [
                ['name' => 'Computer Science', 'experience_years' => 6, 'proficiency_level' => 'advanced'],
                ['name' => 'Mathematics', 'experience_years' => 4, 'proficiency_level' => 'advanced'],
            ],
        ],
        [
            'email' => 'elizabeth.addy@smarttutor.test',
            'name' => 'Elizabeth Addy',
            'password' => 'Password123!',
            'profile' => [
                'hourly_rate' => 16.00,
                'rating' => 4.5,
                'total_reviews' => 20,
                'total_sessions' => 82,
                'education' => 'Former lecturer, University of Education Winneba',
                'teaching_experience' => 'Helping students master essay-based subjects with confidence.',
                'availability' => [
                    'days' => ['Fri', 'Sat', 'Sun'],
                    'modes' => ['in-person', 'hybrid']
                ],
            ],
            'subjects' => [
                ['name' => 'History', 'experience_years' => 10, 'proficiency_level' => 'expert'],
                ['name' => 'Social Studies', 'experience_years' => 10, 'proficiency_level' => 'expert'],
            ],
        ],
    ];

    $pdo->beginTransaction();

    $selectUser = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $insertUser = $pdo->prepare('INSERT INTO users (email, name, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, "tutor", "active", NOW(), NOW())');
    $insertProfile = $pdo->prepare('INSERT INTO tutor_profiles (tutor_id, hourly_rate, availability, education, teaching_experience, verification_status, rating, total_reviews, total_sessions, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "verified", ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE hourly_rate = VALUES(hourly_rate), availability = VALUES(availability), education = VALUES(education), teaching_experience = VALUES(teaching_experience), rating = VALUES(rating), total_reviews = VALUES(total_reviews), total_sessions = VALUES(total_sessions), updated_at = NOW()');
    $insertTutorSubject = $pdo->prepare('INSERT INTO tutor_subjects (tutor_id, subject_id, experience_years, proficiency_level, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE experience_years = VALUES(experience_years), proficiency_level = VALUES(proficiency_level), updated_at = NOW()');

    foreach ($tutors as $tutor) {
        $selectUser->execute([$tutor['email']]);
        $tutorId = $selectUser->fetchColumn();

        if (!$tutorId) {
            $hashed = password_hash($tutor['password'], PASSWORD_DEFAULT);
            $insertUser->execute([$tutor['email'], $tutor['name'], $hashed]);
            $tutorId = (int) $pdo->lastInsertId();
        } else {
            $tutorId = (int) $tutorId;
            $pdo->prepare('UPDATE users SET role = "tutor", status = "active", updated_at = NOW() WHERE id = ?')->execute([$tutorId]);
        }

        $profile = $tutor['profile'];
        $availabilityJson = json_encode($profile['availability']);
        $insertProfile->execute([
            $tutorId,
            $profile['hourly_rate'],
            $availabilityJson,
            $profile['education'],
            $profile['teaching_experience'],
            $profile['rating'],
            $profile['total_reviews'],
            $profile['total_sessions'],
        ]);

        foreach ($tutor['subjects'] as $subjectEntry) {
            $subjectName = $subjectEntry['name'];
            if (!isset($subjectMap[$subjectName])) {
                continue;
            }
            $subjectId = $subjectMap[$subjectName];
            $insertTutorSubject->execute([
                $tutorId,
                $subjectId,
                $subjectEntry['experience_years'],
                $subjectEntry['proficiency_level'],
            ]);
        }
    }

    $pdo->commit();

    echo "Seeded tutor directory data (" . count($tutors) . " tutors ensured).\n";
}