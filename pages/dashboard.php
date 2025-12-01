<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: sign-in.html');
    exit();
}

require_once __DIR__ . '/../connect.php';

$notificationFlash = $_SESSION['notification_flash'] ?? null;
unset($_SESSION['notification_flash']);

function bindStatementParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }

    $stmt->bind_param($types, ...$refs);
}

function fetchScalar(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Dashboard scalar failed: ' . $conn->error);
        return 0;
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        error_log('Dashboard scalar exec failed: ' . $stmt->error);
        $stmt->close();
        return 0;
    }

    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();

    return (int) ($value ?? 0);
}

function fetchAssocList(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Dashboard list failed: ' . $conn->error);
        return [];
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        error_log('Dashboard list exec failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function formatDateTimeValue(?string $value, string $format = 'M j • g:i A'): string
{
    if (empty($value)) {
        return 'TBD';
    }

    try {
        $date = new DateTime($value);
        return $date->format($format);
    } catch (Exception $exception) {
        return $value;
    }
}

function formatDateRange(?string $start, ?string $end): string
{
    if (empty($start)) {
        return 'TBD';
    }

    try {
        $startDate = new DateTime($start);
    } catch (Exception $exception) {
        return $start;
    }

    $label = $startDate->format('D, M j • g:i A');

    if (empty($end)) {
        return $label;
    }

    try {
        $endDate = new DateTime($end);
    } catch (Exception $exception) {
        return $label;
    }

    $sameDay = $startDate->format('Y-m-d') === $endDate->format('Y-m-d');
    $endLabel = $sameDay ? $endDate->format('g:i A') : $endDate->format('D, M j • g:i A');

    return $label . ' — ' . $endLabel;
}

function truncateText(?string $value, int $limit = 100): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit)) . '...';
    }

    if (strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(substr($value, 0, $limit)) . '...';
}

function loadAppConfig(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }

    $configPath = __DIR__ . '/../api/config/app.php';
    if (file_exists($configPath)) {
        /** @phpstan-ignore-next-line */
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
            return $config;
        }
    }

    $config = [];
    return $config;
}

function appConfigValue(string $key, string $default = ''): string
{
    $config = loadAppConfig();
    if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
        return $config[$key];
    }

    return $default;
}

function base64UrlEncodeString(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function getAppJwtSecret(): string
{
    $secret = getenv('JWT_SECRET');
    if ($secret) {
        return $secret;
    }

    $configPath = __DIR__ . '/../api/config/app.php';
    if (file_exists($configPath)) {
        /** @phpstan-ignore-next-line */
        $config = require $configPath;
        if (is_array($config) && !empty($config['jwt_secret'])) {
            return (string) $config['jwt_secret'];
        }
    }

    return '';
}

function issueRealtimeToken(int $userId, string $role): string
{
    $secret = getAppJwtSecret();
    if ($secret === '') {
        return '';
    }

    try {
        $header = base64UrlEncodeString(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = base64UrlEncodeString(json_encode([
            'sub' => $userId,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
    } catch (JsonException $exception) {
        error_log('Failed to encode websocket token payload: ' . $exception->getMessage());
        return '';
    }

    $signature = hash_hmac('sha256', $header . '.' . $payload, $secret, true);

    return $header . '.' . $payload . '.' . base64UrlEncodeString($signature);
}

function getRealtimeClientUrl(): string
{
    $url = getenv('WS_URL');
    if (!empty($url)) {
        return $url;
    }

    return appConfigValue('ws_url');
}

function bookingStatusBadge(string $status): string
{
    return match ($status) {
        'accepted' => 'status-badge status-confirmed',
        'declined' => 'status-badge status-declined',
        'expired' => 'status-badge status-expired',
        default => 'status-badge status-pending',
    };
}

function sessionStatusBadge(string $status): string
{
    return match ($status) {
        'ongoing' => 'status-badge status-confirmed',
        'completed' => 'status-badge status-confirmed',
        'cancelled', 'no_show' => 'status-badge status-declined',
        default => 'status-badge status-pending',
    };
}

function announcementAudienceLabel(string $audience): string
{
    return match ($audience) {
        'all_users' => 'All users',
        'students' => 'Students',
        'tutors' => 'Tutors',
        'admins' => 'Admin team',
        default => ucfirst(str_replace('_', ' ', $audience)),
    };
}

function announcementPriorityClass(string $priority): string
{
    return match ($priority) {
        'critical' => 'priority-pill priority-critical',
        'important' => 'priority-pill priority-important',
        default => 'priority-pill priority-normal',
    };
}

function notificationLevelClass(string $level, bool $isRead = true): string
{
    $base = match ($level) {
        'danger' => 'notification-pill notification-danger',
        'warning' => 'notification-pill notification-warning',
        'success' => 'notification-pill notification-success',
        default => 'notification-pill notification-info',
    };

    return $isRead ? $base : $base . ' notification-unread';
}

function resolveDisplayName(string $currentName, string $email): string
{
    $trimmed = trim($currentName);
    $email = trim($email);
    if ($trimmed === '' && $email !== '') {
        $trimmed = explode('@', $email, 2)[0] ?? '';
    }

    $overrides = [
        'claudetomo20@gmail.com' => 'Claude Tomoh',
    ];

    if ($email !== '') {
        $normalizedEmail = strtolower($email);
        if (isset($overrides[$normalizedEmail])) {
            return $overrides[$normalizedEmail];
        }
    }

    return $trimmed !== '' ? $trimmed : $currentName;
}

$user_id = (int) $_SESSION['user_id'];
$name = $_SESSION['name'] ?? '';
$role = $_SESSION['role'];

$email = '';
$bio = '';
$dbName = '';
$stmt = $conn->prepare('SELECT name, email, bio FROM users WHERE id = ? LIMIT 1');

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    if ($stmt->execute()) {
        $stmt->bind_result($dbName, $email, $bio);
        $stmt->fetch();
    }
    $stmt->close();
} else {
    error_log('Dashboard profile query failed: ' . $conn->error);
}

if ($dbName !== '') {
    $name = $dbName;
}

$name = resolveDisplayName($name, $email);
if (strtolower(trim($email)) === 'claudetomo20@gmail.com') {
    $name = 'Claude Tomoh';
}
$_SESSION['name'] = $name;

$tutorStats = [
    'pending_requests' => 0,
    'upcoming_sessions' => 0,
    'active_students' => 0,
];
$studentStats = [
    'upcoming_sessions' => 0,
    'completed_sessions' => 0,
    'booked_tutors' => 0,
];
$pendingRequests = [];
$upcomingSessions = [];
$studentRoster = [];
$bookedTutors = [];
$availableTutors = [];
$studentUpcomingSessions = [];
$studentPastSessions = [];
$adminAnnouncements = [];
$userNotifications = [];
$unreadNotificationCount = 0;

if ($role === 'tutor') {
    $tutorStats['pending_requests'] = fetchScalar($conn, "SELECT COUNT(*) FROM booking_requests WHERE tutor_id = ? AND status = 'pending'", 'i', [$user_id]);
    $tutorStats['upcoming_sessions'] = fetchScalar($conn, "SELECT COUNT(*) FROM tutoring_sessions WHERE tutor_id = ? AND start_time >= NOW() AND status IN ('scheduled','ongoing')", 'i', [$user_id]);
    $tutorStats['active_students'] = fetchScalar($conn, "SELECT COUNT(DISTINCT student_id) FROM tutoring_sessions WHERE tutor_id = ? AND status IN ('scheduled','ongoing','completed')", 'i', [$user_id]);

    $pendingRequests = fetchAssocList(
        $conn,
        "SELECT id, student_name, student_email, requested_for, status, reference, created_at
         FROM booking_requests
         WHERE tutor_id = ? AND status = 'pending'
         ORDER BY created_at DESC
         LIMIT 5",
        'i',
        [$user_id]
    );

    $upcomingSessions = fetchAssocList(
        $conn,
        "SELECT ts.start_time, ts.end_time, ts.status, ts.meeting_link, ts.payment_status, u.name AS student_name, u.email AS student_email FROM tutoring_sessions ts LEFT JOIN users u ON u.id = ts.student_id WHERE ts.tutor_id = ? AND ts.start_time >= NOW() ORDER BY ts.start_time ASC LIMIT 5",
        'i',
        [$user_id]
    );

    $studentRoster = fetchAssocList(
        $conn,
        "SELECT ts.student_id AS student_id, u.name AS student_name, u.email AS student_email, MAX(ts.start_time) AS last_session FROM tutoring_sessions ts LEFT JOIN users u ON u.id = ts.student_id WHERE ts.tutor_id = ? GROUP BY ts.student_id, u.name, u.email ORDER BY last_session DESC LIMIT 6",
        'i',
        [$user_id]
    );
} elseif ($role === 'student') {
    $studentStats['upcoming_sessions'] = fetchScalar(
        $conn,
        "SELECT COUNT(*) FROM tutoring_sessions WHERE student_id = ? AND start_time >= NOW() AND status IN ('scheduled','ongoing')",
        'i',
        [$user_id]
    );

    $studentStats['completed_sessions'] = fetchScalar(
        $conn,
        "SELECT COUNT(*) FROM tutoring_sessions WHERE student_id = ? AND status = 'completed'",
        'i',
        [$user_id]
    );

    $studentStats['booked_tutors'] = fetchScalar(
        $conn,
        'SELECT COUNT(DISTINCT tutor_id) FROM tutoring_sessions WHERE student_id = ?',
        'i',
        [$user_id]
    );

    $bookedTutors = fetchAssocList(
        $conn,
        'SELECT t.id, t.name, t.email, COALESCE(tp.hourly_rate, 0) AS hourly_rate, COALESCE(tp.rating, 0) AS rating,
                COUNT(ts.id) AS total_sessions,
                MAX(CASE WHEN ts.start_time >= NOW() THEN ts.start_time ELSE NULL END) AS next_session,
                MAX(ts.start_time) AS last_session
         FROM tutoring_sessions ts
         INNER JOIN users t ON t.id = ts.tutor_id
         LEFT JOIN tutor_profiles tp ON tp.tutor_id = t.id
         WHERE ts.student_id = ?
         GROUP BY t.id, t.name, t.email, tp.hourly_rate, tp.rating
         ORDER BY last_session DESC
         LIMIT 4',
        'i',
        [$user_id]
    );

    $studentUpcomingSessions = fetchAssocList(
        $conn,
        'SELECT ts.id, ts.start_time, ts.end_time, ts.status, ts.meeting_link, ts.payment_status,
                subj.name AS subject_name,
                tutors.name AS tutor_name,
                tutors.email AS tutor_email
         FROM tutoring_sessions ts
         LEFT JOIN users tutors ON tutors.id = ts.tutor_id
         LEFT JOIN subjects subj ON subj.id = ts.subject_id
         WHERE ts.student_id = ? AND ts.start_time >= NOW()
         ORDER BY ts.start_time ASC
         LIMIT 5',
        'i',
        [$user_id]
    );

    $studentPastSessions = fetchAssocList(
        $conn,
        'SELECT ts.id, ts.start_time, ts.end_time, ts.status,
                subj.name AS subject_name,
                tutors.name AS tutor_name,
                tutors.email AS tutor_email,
                sf.rating, sf.feedback_text
         FROM tutoring_sessions ts
         LEFT JOIN users tutors ON tutors.id = ts.tutor_id
         LEFT JOIN subjects subj ON subj.id = ts.subject_id
         LEFT JOIN session_feedback sf ON sf.session_id = ts.id
         WHERE ts.student_id = ? AND ts.start_time < NOW()
         ORDER BY ts.start_time DESC
         LIMIT 6',
        'i',
        [$user_id]
    );

        $availableTutors = fetchAssocList(
                $conn,
                "SELECT u.id, u.name, u.email,
                                COALESCE(tp.hourly_rate, 0) AS hourly_rate,
                                COALESCE(tp.rating, 0) AS rating,
                                COALESCE(tp.total_reviews, 0) AS total_reviews,
                                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS subjects
                 FROM users u
                 LEFT JOIN tutor_profiles tp ON tp.tutor_id = u.id
                 LEFT JOIN tutor_subjects ts ON ts.tutor_id = u.id
                 LEFT JOIN subjects s ON s.id = ts.subject_id
                 WHERE u.role = 'tutor'
                     AND u.status = 'active'
                     AND COALESCE(tp.verification_status, 'pending') = 'verified'
                 GROUP BY u.id, u.name, u.email, tp.hourly_rate, tp.rating, tp.total_reviews
                 ORDER BY u.updated_at DESC
                 LIMIT 6"
        );
}

$announcementAudiences = ['all_users'];
if ($role === 'student') {
    $announcementAudiences[] = 'students';
} elseif ($role === 'tutor') {
    $announcementAudiences[] = 'tutors';
}
if ($role === 'admin') {
    $announcementAudiences[] = 'admins';
}
$announcementAudiences = array_values(array_unique($announcementAudiences));

if (count($announcementAudiences) > 0) {
    $placeholders = implode(',', array_fill(0, count($announcementAudiences), '?'));
    $types = str_repeat('s', count($announcementAudiences));
    $adminAnnouncements = fetchAssocList(
        $conn,
        "SELECT am.*, authors.name AS author_name
         FROM admin_messages am
         LEFT JOIN users authors ON authors.id = am.author_id
         WHERE am.audience IN ($placeholders)
         ORDER BY am.pinned DESC, am.created_at DESC
         LIMIT 6",
        $types,
        $announcementAudiences
    );
}

$userNotifications = fetchAssocList(
    $conn,
    'SELECT id, title, body, level, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 6',
    'i',
    [$user_id]
);

$unreadNotificationCount = fetchScalar(
    $conn,
    'SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0',
    'i',
    [$user_id]
);

$directMessageOptions = [];
$recipientLookup = [];

if ($role === 'tutor') {
    foreach ($studentRoster as $studentRow) {
        $studentId = (int) ($studentRow['student_id'] ?? 0);
        if ($studentId === 0) {
            continue;
        }

        $recipientName = trim((string) ($studentRow['student_name'] ?? 'Student'));
        $emailValue = trim((string) ($studentRow['student_email'] ?? ''));
        $label = $recipientName !== '' ? $recipientName : 'Student';
        if ($emailValue !== '') {
            $label .= ' (' . $emailValue . ')';
        }
        $recipientLookup[$studentId] = $label;
    }
} elseif ($role === 'student') {
    $lists = [$bookedTutors, $availableTutors];
    foreach ($lists as $list) {
        foreach ($list as $tutorRow) {
            $tutorId = (int) ($tutorRow['id'] ?? 0);
            if ($tutorId === 0) {
                continue;
            }

            $recipientName = trim((string) ($tutorRow['name'] ?? 'Tutor'));
            $emailValue = trim((string) ($tutorRow['email'] ?? ''));
            $label = $recipientName !== '' ? $recipientName : 'Tutor';
            if ($emailValue !== '') {
                $label .= ' (' . $emailValue . ')';
            }
            $recipientLookup[$tutorId] = $label;
        }
    }
}

foreach ($recipientLookup as $id => $label) {
    $directMessageOptions[] = [
        'id' => (int) $id,
        'label' => $label,
    ];
}

$heroStats = [];
if ($role === 'tutor') {
    $heroStats = [
        ['label' => 'Requests', 'value' => number_format($tutorStats['pending_requests']), 'hint' => 'Awaiting review'],
        ['label' => 'Sessions', 'value' => number_format($tutorStats['upcoming_sessions']), 'hint' => 'Scheduled next'],
        ['label' => 'Active students', 'value' => number_format($tutorStats['active_students']), 'hint' => 'Currently engaged'],
    ];
} else {
    $heroStats = [
        ['label' => 'Upcoming', 'value' => number_format($studentStats['upcoming_sessions']), 'hint' => 'Lessons booked'],
        ['label' => 'Completed', 'value' => number_format($studentStats['completed_sessions']), 'hint' => 'Finished sessions'],
        ['label' => 'Tutors', 'value' => number_format($studentStats['booked_tutors']), 'hint' => 'Experts you\'ve met'],
    ];
}

$heroStats[] = [
    'label' => 'Alerts',
    'value' => number_format($unreadNotificationCount),
    'hint' => $unreadNotificationCount === 1 ? 'new notification' : 'new notifications',
];

$heroMessage = $role === 'tutor'
    ? 'Confirm fresh requests, share meeting links, and keep every learner on track.'
    : 'Check what\'s next, message a tutor, and keep your study streak alive.';

$bioSnippet = $bio !== '' ? truncateText($bio, 140) : '';

$heroChips = [
    ['label' => ucfirst($role) . ' workspace', 'variant' => ''],
];

if ($unreadNotificationCount > 0) {
    $heroChips[] = [
        'label' => number_format($unreadNotificationCount) . ' new alert' . ($unreadNotificationCount === 1 ? '' : 's'),
        'variant' => 'alert',
    ];
} else {
    $heroChips[] = ['label' => 'Inbox clear', 'variant' => 'success'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard • SmartTutor Connect</title>
    <link href="../src/css/main.css" rel="stylesheet">
    <link href="../src/css/pages.css" rel="stylesheet">
    <link href="../src/css/dashboard.css" rel="stylesheet">
    <style>
        .announcement-grid {
            display: grid;
            gap: 20px;
            margin-top: 24px;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .notification-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .inline-form {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }
        .announcement-list,
        .notification-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .announcement-item,
        .notification-item {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 14px;
            padding: 14px 16px;
            background: #fff;
        }
        .announcement-item--pinned {
            border-color: rgba(125, 18, 36, 0.4);
            box-shadow: 0 0 0 1px rgba(125, 18, 36, 0.2);
        }
        .announcement-meta,
        .notification-meta {
            font-size: 0.85rem;
            color: rgba(0, 0, 0, 0.6);
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .priority-pill,
        .notification-pill,
        .audience-pill {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .audience-pill {
            background: rgba(77, 20, 23, 0.1);
            color: #4d1417;
        }
        .priority-normal {
            background: rgba(0, 0, 0, 0.08);
            color: #2c2c2c;
        }
        .priority-important {
            background: rgba(230, 142, 0, 0.18);
            color: #7a4100;
        }
        .priority-critical {
            background: rgba(179, 38, 30, 0.18);
            color: #7f1f19;
        }
        .notification-info {
            background: rgba(27, 86, 143, 0.16);
            color: #1b568f;
        }
        .notification-success {
            background: rgba(46, 160, 67, 0.18);
            color: #22543d;
        }
        .notification-warning {
            background: rgba(230, 142, 0, 0.18);
            color: #7a4100;
        }
        .notification-danger {
            background: rgba(179, 38, 30, 0.2);
            color: #7f1f19;
        }
        .notification-pill.notification-unread {
            box-shadow: 0 0 0 1px rgba(125, 18, 36, 0.35);
        }
        .notification-item-actions {
            margin-top: 10px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .notice-flash {
            margin-bottom: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 0.9rem;
        }
        .notice-flash.success {
            background: rgba(46, 160, 67, 0.15);
            color: #22543d;
        }
        .notice-flash.error {
            background: rgba(179, 38, 30, 0.18);
            color: #7f1f19;
        }
        .empty-feed-state {
            margin: 0;
            padding: 8px 0;
            color: rgba(0, 0, 0, 0.6);
        }
    </style>
</head>

<body>
    <header class="page-header">
        <div class="container">
            <a class="brand-mark" href="../index.html">
                <img src="../public/images/logo.png" alt="SmartTutor Connect logo">
                <span>SmartTutor Connect</span>
            </a>

            <nav class="page-nav">
                <span class="welcome-text">Hi, <?php echo htmlspecialchars($name); ?> 👋</span>
                <?php if ($role === 'admin'): ?>
                    <a href="admin.php">Admin console</a>
                <?php endif; ?>
                <a href="../logout.php" class="btn btn-outline">Log out</a>
            </nav>
        </div>
    </header>

    <main class="dashboard-layout">
        <?php if (!empty($notificationFlash['message'])): ?>
            <div class="notice-flash <?php echo htmlspecialchars($notificationFlash['type'] ?? 'success'); ?>">
                <?php echo htmlspecialchars($notificationFlash['message']); ?>
            </div>
        <?php endif; ?>
        <section class="dashboard-card dashboard-hero" aria-labelledby="dashboard-heading">
            <div class="dashboard-hero__intro">
                <p class="dashboard-eyebrow"><?php echo ucfirst($role); ?> workspace</p>
                <h1 id="dashboard-heading">Welcome back, <?php echo htmlspecialchars($name); ?></h1>
                <p class="dashboard-lede"><?php echo htmlspecialchars($heroMessage); ?></p>
                <?php if (!empty($heroChips)): ?>
                    <div class="hero-chips">
                        <?php foreach ($heroChips as $chip): ?>
                            <span class="hero-chip<?php echo $chip['variant'] ? ' hero-chip--' . $chip['variant'] : ''; ?>"><?php echo htmlspecialchars($chip['label']); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="hero-meta">
                <div>
                    <p class="hero-meta__label">Account type</p>
                    <p class="hero-meta__value"><?php echo ucfirst($role); ?></p>
                </div>
                <div>
                    <p class="hero-meta__label">Profile</p>
                    <p class="hero-meta__value"><?php echo htmlspecialchars($bioSnippet ?: 'Add a quick bio so tutors know your goals.'); ?></p>
                </div>
            </div>
            <?php if (!empty($heroStats)): ?>
                <div class="hero-stats" role="list">
                    <?php foreach ($heroStats as $stat): ?>
                        <div class="hero-stat" role="listitem">
                            <span><?php echo htmlspecialchars($stat['label']); ?></span>
                            <strong><?php echo htmlspecialchars($stat['value']); ?></strong>
                            <small><?php echo htmlspecialchars($stat['hint']); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="hero-actions">
                <?php if ($role === 'tutor'): ?>
                    <a href="tutorProfile.php" class="btn btn-primary btn-sm">Open tutor profile</a>
                    <a href="../index.html#find" class="btn btn-outline btn-sm">Public directory</a>
                <?php else: ?>
                    <a href="../index.html#find" class="btn btn-primary btn-sm">Browse tutors</a>
                    <a href="../index.html#feedback" class="btn btn-outline btn-sm">Share feedback</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="announcement-grid" aria-label="Platform announcements and notifications">
            <article class="dashboard-card">
                <header class="notification-header">
                    <div>
                        <h2>Platform announcements</h2>
                        <p class="metric-note">Quick notes from the SmartTutor team.</p>
                    </div>
                    <a class="btn btn-sm btn-outline" href="notification_history.php?tab=announcements">View all</a>
                </header>
                <ul class="announcement-list">
                    <?php if (count($adminAnnouncements) === 0): ?>
                        <li class="announcement-item"><p class="empty-feed-state">No announcements yet. Check back soon.</p></li>
                    <?php else: ?>
                        <?php foreach ($adminAnnouncements as $announcement): ?>
                            <?php $isPinned = (int) ($announcement['pinned'] ?? 0) === 1; ?>
                            <li class="announcement-item <?php echo $isPinned ? 'announcement-item--pinned' : ''; ?>">
                                <div class="announcement-meta">
                                    <?php if ($isPinned): ?><span class="audience-pill">Pinned</span><?php endif; ?>
                                    <span class="<?php echo announcementPriorityClass($announcement['priority'] ?? 'normal'); ?>"><?php echo htmlspecialchars(ucfirst($announcement['priority'] ?? 'normal')); ?></span>
                                    <span class="audience-pill"><?php echo htmlspecialchars(announcementAudienceLabel($announcement['audience'] ?? 'all_users')); ?></span>
                                    <span><?php echo htmlspecialchars(formatDateTimeValue($announcement['created_at'] ?? '')); ?></span>
                                </div>
                                <h3><?php echo htmlspecialchars($announcement['subject'] ?? 'Announcement'); ?></h3>
                                <p><?php echo htmlspecialchars(truncateText($announcement['body'] ?? '', 240)); ?></p>
                                <?php if (!empty($announcement['author_name'])): ?>
                                    <p class="announcement-meta">By <?php echo htmlspecialchars($announcement['author_name']); ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </article>
            <article class="dashboard-card" id="notifications">
                <header class="notification-header">
                    <div>
                        <h2>Your notifications</h2>
                        <p class="metric-note">System alerts covering bookings, security, and account status.</p>
                    </div>
                    <div class="notification-actions">
                        <a class="btn btn-sm btn-outline" href="notification_history.php?tab=notifications">View history</a>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <form class="inline-form" method="post" action="notification_actions.php">
                                <input type="hidden" name="action" value="notifications_mark_read">
                                <input type="hidden" name="mark_all" value="1">
                                <input type="hidden" name="redirect_to" value="dashboard.php#notifications">
                                <button type="submit" class="btn btn-sm btn-primary">Mark all read (<?php echo number_format($unreadNotificationCount); ?>)</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </header>
                <ul class="notification-list">
                    <?php if (count($userNotifications) === 0): ?>
                        <li class="notification-item"><p class="empty-feed-state">No notifications yet. Actions will show up here.</p></li>
                    <?php else: ?>
                        <?php foreach ($userNotifications as $notification): ?>
                            <?php
                                $isRead = (int) ($notification['is_read'] ?? 0) === 1;
                                $notificationId = (int) ($notification['id'] ?? 0);
                            ?>
                            <li class="notification-item">
                                <div class="notification-meta">
                                    <span class="<?php echo notificationLevelClass($notification['level'] ?? 'info', $isRead); ?>"><?php echo htmlspecialchars(ucfirst($notification['level'] ?? 'info')); ?></span>
                                    <span><?php echo htmlspecialchars(formatDateTimeValue($notification['created_at'] ?? '')); ?></span>
                                    <?php if (!$isRead): ?><span class="audience-pill">New</span><?php endif; ?>
                                </div>
                                <h3><?php echo htmlspecialchars($notification['title'] ?? 'Notification'); ?></h3>
                                <p><?php echo htmlspecialchars(truncateText($notification['body'] ?? '', 220)); ?></p>
                                <?php if (!$isRead && $notificationId > 0): ?>
                                    <div class="notification-item-actions">
                                        <form method="post" action="notification_actions.php" class="inline-form">
                                            <input type="hidden" name="action" value="notifications_mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo $notificationId; ?>">
                                            <input type="hidden" name="redirect_to" value="dashboard.php#notifications">
                                            <button type="submit" class="btn btn-sm btn-outline">Mark as read</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </article>
        </section>

        <section class="dashboard-card" id="messages">
            <header class="notification-header">
                <div>
                    <h2>Direct messages</h2>
                    <p class="metric-note">Chats with tutors, students, and the support desk.</p>
                </div>
                <div class="notification-actions">
                    <span class="message-unread-indicator" data-message-unread>
                        <strong>0</strong> unread
                    </span>
                    <button type="button" class="btn btn-sm btn-outline" data-refresh-threads>Sync</button>
                </div>
            </header>
            <p id="messageStatus" class="metric-note" role="status" aria-live="polite"></p>
            <div class="messages-layout">
                <aside class="message-thread-panel">
                    <h3 class="section-subtitle">Conversations</h3>
                    <ul class="message-thread-list" data-message-threads>
                        <li class="placeholder">Loading your conversations…</li>
                    </ul>
                </aside>
                <div class="message-panel">
                    <div class="message-log" data-message-log>
                        <p class="placeholder">Select a conversation to view messages.</p>
                    </div>
                    <form class="message-form" data-message-reply>
                        <input type="hidden" name="thread_id" value="" data-active-thread>
                        <label>
                            <span>Reply</span>
                            <textarea name="message" rows="2" placeholder="Write a quick reply…" required></textarea>
                        </label>
                        <button type="submit" class="btn btn-primary">Send reply</button>
                    </form>
                    <div class="message-divider"><span>Start a new conversation</span></div>
                    <?php if (count($directMessageOptions) > 0): ?>
                        <form class="message-form message-form--new" data-message-new>
                            <label>
                                <span>Recipient</span>
                                <select name="recipient_id" required>
                                    <option value="">Select a contact…</option>
                                    <?php foreach ($directMessageOptions as $option): ?>
                                        <option value="<?php echo (int) ($option['id'] ?? 0); ?>"><?php echo htmlspecialchars($option['label'] ?? 'User'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Message</span>
                                <textarea name="message" rows="2" placeholder="Say hello or confirm next steps…" required></textarea>
                            </label>
                            <button type="submit" class="btn btn-outline">Send new message</button>
                        </form>
                    <?php else: ?>
                        <p class="message-empty-hint">New chats unlock once you have active bookings on your calendar.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($role === 'tutor'): ?>
            <section class="dashboard-grid" aria-label="Tutor metrics">
                <article class="dashboard-card">
                    <p class="metric-label">Pending tutoring requests</p>
                    <p class="metric-value"><?php echo number_format($tutorStats['pending_requests']); ?></p>
                    <p class="metric-note">Students awaiting confirmation.</p>
                </article>
                <article class="dashboard-card">
                    <p class="metric-label">Upcoming sessions</p>
                    <p class="metric-value"><?php echo number_format($tutorStats['upcoming_sessions']); ?></p>
                    <p class="metric-note">Scheduled within the next few days.</p>
                </article>
                <article class="dashboard-card">
                    <p class="metric-label">Active students</p>
                    <p class="metric-value"><?php echo number_format($tutorStats['active_students']); ?></p>
                    <p class="metric-note">Across your current programmes.</p>
                </article>
            </section>

            <section class="dashboard-card" id="schedule">
                <header>
                    <h2>Upcoming sessions</h2>
                    <p class="metric-note">Review schedules, confirm attendance, and share meeting links.</p>
                </header>
                <div class="table-responsive">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($upcomingSessions) === 0): ?>
                                <tr>
                                    <td colspan="5">No upcoming sessions yet. Add your availability to get booked.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($upcomingSessions as $session): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($session['student_name'] ?? 'Student'); ?><br>
                                            <small><?php echo htmlspecialchars($session['student_email'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatDateRange($session['start_time'] ?? '', $session['end_time'] ?? '')); ?></td>
                                        <td><span class="<?php echo sessionStatusBadge($session['status'] ?? 'scheduled'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $session['status'] ?? 'scheduled'))); ?></span></td>
                                        <td><?php echo htmlspecialchars(ucwords($session['payment_status'] ?? 'pending')); ?></td>
                                        <td>
                                            <?php if (!empty($session['meeting_link'])): ?>
                                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($session['meeting_link']); ?>" target="_blank" rel="noopener">Join link</a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline" type="button">Add link</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-card" id="requests">
                <header>
                    <h2>New tutoring requests</h2>
                    <p class="metric-note">Respond quickly to lock in sessions and keep your calendar full.</p>
                </header>
                <?php if ($role === 'tutor'): ?>
                    <p id="tutorRequestsStatus" class="metric-note" role="status" aria-live="polite"></p>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Preferred time</th>
                                <th>Reference</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <?php if ($role === 'tutor'): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($pendingRequests) === 0): ?>
                                <tr>
                                    <td colspan="<?php echo $role === 'tutor' ? '6' : '5'; ?>">No pending requests right now.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pendingRequests as $request): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($request['student_name'] ?? 'Prospect'); ?><br>
                                            <small><?php echo htmlspecialchars($request['student_email'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatDateTimeValue($request['requested_for'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($request['reference'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars(formatDateTimeValue($request['created_at'] ?? '')); ?></td>
                                        <td><span class="<?php echo bookingStatusBadge($request['status'] ?? 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($request['status'] ?? 'pending')); ?></span></td>
                                        <?php if ($role === 'tutor'): ?>
                                            <td>
                                                <div class="section-actions">
                                                    <button type="button" class="btn btn-sm btn-primary tutor-request-action" data-action="tutor_accept" data-booking-id="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                        Accept
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline tutor-request-action" data-action="tutor_decline" data-booking-id="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                        Decline
                                                    </button>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-card" id="students">
                <header>
                    <h2>Student roster</h2>
                    <p class="metric-note">Recent learners and their last activity.</p>
                </header>
                <div class="table-responsive">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Last session</th>
                                <th>Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($studentRoster) === 0): ?>
                                <tr>
                                    <td colspan="3">You have not met with any students yet. Confirm a request to populate this view.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentRoster as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_name'] ?? 'Student'); ?></td>
                                        <td><?php echo htmlspecialchars(formatDateTimeValue($student['last_session'] ?? '')); ?></td>
                                        <td><a href="mailto:<?php echo htmlspecialchars($student['student_email'] ?? ''); ?>" class="btn btn-sm btn-outline">Email</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <section class="dashboard-card quick-actions-card">
                <div class="quick-actions-card__header">
                    <div>
                        <h2>Quick actions</h2>
                        <p>Pick up where you left off or explore something new.</p>
                    </div>
                    <span class="badge-soft">Student space</span>
                </div>
                <div class="quick-actions-grid">
                    <article>
                        <h3>Book a tutor</h3>
                        <p>Discover verified mentors tailored to your goals.</p>
                        <a href="../index.html#find" class="btn btn-primary">Browse tutors</a>
                    </article>
                    <article>
                        <h3>Review progress</h3>
                        <p>Look over session notes and share feedback.</p>
                        <div class="section-actions">
                            <a href="#student-history" class="btn btn-outline">Past sessions</a>
                            <a href="../index.html#feedback" class="btn btn-outline">Give feedback</a>
                        </div>
                    </article>
                </div>
            </section>
            <section class="dashboard-grid student-metrics" aria-label="Student progress">
                <article class="dashboard-card">
                    <p class="metric-label">Upcoming sessions</p>
                    <p class="metric-value"><?php echo number_format($studentStats['upcoming_sessions']); ?></p>
                    <p class="metric-note">Lessons scheduled and ready.</p>
                </article>
                <article class="dashboard-card">
                    <p class="metric-label">Sessions completed</p>
                    <p class="metric-value"><?php echo number_format($studentStats['completed_sessions']); ?></p>
                    <p class="metric-note">Track your learning streak.</p>
                </article>
                <article class="dashboard-card">
                    <p class="metric-label">Tutors booked</p>
                    <p class="metric-value"><?php echo number_format($studentStats['booked_tutors']); ?></p>
                    <p class="metric-note">Experts who helped you learn.</p>
                </article>
            </section>

            <section class="dashboard-card" id="student-upcoming">
                <header>
                    <h2>Upcoming sessions</h2>
                    <p class="metric-note">Your next lessons and meeting links.</p>
                </header>
                <div class="table-responsive">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Tutor</th>
                                <th>Schedule</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($studentUpcomingSessions) === 0): ?>
                                <tr>
                                    <td colspan="5">No upcoming sessions yet. Book a tutor to get started.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentUpcomingSessions as $session): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($session['tutor_name'] ?? 'Tutor'); ?><br>
                                            <small><?php echo htmlspecialchars($session['tutor_email'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatDateRange($session['start_time'] ?? '', $session['end_time'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($session['subject_name'] ?? 'TBD'); ?></td>
                                        <td><span class="<?php echo sessionStatusBadge($session['status'] ?? 'scheduled'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $session['status'] ?? 'scheduled'))); ?></span></td>
                                        <td>
                                            <?php if (!empty($session['meeting_link'])): ?>
                                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($session['meeting_link']); ?>" target="_blank" rel="noopener">Join link</a>
                                            <?php else: ?>
                                                <span class="table-note">Awaiting meeting link</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-card" id="student-tutors">
                <header>
                    <h2>Your tutors</h2>
                    <p class="metric-note">Stay close to mentors you've booked and discover newly approved experts.</p>
                </header>
                <?php
                    $bookedTutorIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $bookedTutors), static fn(int $id): bool => $id > 0));
                    $verifiedTutors = array_values(array_filter($availableTutors, static function (array $row) use ($bookedTutorIds): bool {
                        $tutorId = (int) ($row['id'] ?? 0);
                        if ($tutorId === 0) {
                            return true;
                        }

                        return !in_array($tutorId, $bookedTutorIds, true);
                    }));
                ?>

                <?php if (count($bookedTutors) > 0): ?>
                    <p class="section-subtitle">Tutors you've booked</p>
                    <div class="student-tutor-list">
                        <?php foreach ($bookedTutors as $tutor): ?>
                            <article class="student-tutor-card">
                                <div class="student-tutor-card__header">
                                    <h3><?php echo htmlspecialchars($tutor['name'] ?? 'Tutor'); ?></h3>
                                    <?php if (!empty($tutor['rating'])): ?>
                                        <span class="rating-pill">★ <?php echo number_format((float) $tutor['rating'], 1); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="student-tutor-card__meta"><?php echo htmlspecialchars($tutor['email'] ?? ''); ?></p>
                                <p class="student-tutor-card__meta">
                                    <?php if (!empty($tutor['hourly_rate'])): ?>
                                        <?php echo '$' . number_format((float) $tutor['hourly_rate'], 2); ?> per hour •
                                    <?php endif; ?>
                                    <?php echo number_format((int) ($tutor['total_sessions'] ?? 0)); ?> session<?php echo ((int) ($tutor['total_sessions'] ?? 0)) === 1 ? '' : 's'; ?> together
                                </p>
                                <p class="student-tutor-card__meta">Next session: <?php echo htmlspecialchars(formatDateTimeValue($tutor['next_session'] ?? '', 'D, M j • g:i A')); ?></p>
                                <div class="section-actions">
                                    <a class="btn btn-sm btn-outline" href="mailto:<?php echo htmlspecialchars($tutor['email'] ?? ''); ?>">Message</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p class="section-subtitle">Verified tutors</p>
                <?php if (count($verifiedTutors) === 0): ?>
                    <p class="empty-state">Approved tutors will appear here after the admin team verifies their profiles.</p>
                <?php else: ?>
                    <div class="student-tutor-list">
                        <?php foreach ($verifiedTutors as $tutor): ?>
                            <article class="student-tutor-card">
                                <div class="student-tutor-card__header">
                                    <h3><?php echo htmlspecialchars($tutor['name'] ?? 'Tutor'); ?></h3>
                                    <?php if (!empty($tutor['rating'])): ?>
                                        <span class="rating-pill">★ <?php echo number_format((float) $tutor['rating'], 1); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="student-tutor-card__meta"><?php echo htmlspecialchars($tutor['email'] ?? ''); ?></p>
                                <?php if (!empty($tutor['subjects'])): ?>
                                    <p class="student-tutor-card__meta">Subjects: <?php echo htmlspecialchars($tutor['subjects']); ?></p>
                                <?php endif; ?>
                                <p class="student-tutor-card__meta">
                                    <?php if (!empty($tutor['hourly_rate'])): ?>
                                        <?php echo '$' . number_format((float) $tutor['hourly_rate'], 2); ?> per hour
                                    <?php endif; ?>
                                    <?php if (!empty($tutor['total_reviews'])): ?>
                                        • <?php echo number_format((int) $tutor['total_reviews']); ?> review<?php echo ((int) $tutor['total_reviews']) === 1 ? '' : 's'; ?>
                                    <?php endif; ?>
                                </p>
                                <div class="section-actions">
                                    <a class="btn btn-sm btn-outline" href="mailto:<?php echo htmlspecialchars($tutor['email'] ?? ''); ?>">Message</a>
                                    <a class="btn btn-sm btn-primary" href="../index.html#find">Book</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-card" id="student-history">
                <header>
                    <h2>Past sessions</h2>
                    <p class="metric-note">Review completed lessons and feedback.</p>
                </header>
                <div class="table-responsive">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Tutor</th>
                                <th>Session date</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($studentPastSessions) === 0): ?>
                                <tr>
                                    <td colspan="5">No completed sessions yet. They will appear here once finished.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentPastSessions as $session): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($session['tutor_name'] ?? 'Tutor'); ?><br>
                                            <small><?php echo htmlspecialchars($session['tutor_email'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatDateTimeValue($session['start_time'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($session['subject_name'] ?? 'TBD'); ?></td>
                                        <td><span class="<?php echo sessionStatusBadge($session['status'] ?? 'completed'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $session['status'] ?? 'completed'))); ?></span></td>
                                        <td>
                                            <?php if (!empty($session['rating'])): ?>
                                                <span class="rating-pill">★ <?php echo number_format((float) $session['rating'], 1); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($session['feedback_text'])): ?>
                                                <p class="table-note"><?php echo htmlspecialchars(truncateText($session['feedback_text'], 90)); ?></p>
                                            <?php else: ?>
                                                <span class="table-note">No feedback shared yet.</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>
<?php
$wsClientUrl = getRealtimeClientUrl();
$realtimeConfig = [
    'url' => $wsClientUrl,
    'token' => '',
];
if ($wsClientUrl !== '') {
    $token = issueRealtimeToken($user_id, $role);
    $realtimeConfig['token'] = $token;
}
?>
    <script>
        window.__SMARTTUTOR_WS__ = <?php echo json_encode($realtimeConfig, JSON_UNESCAPED_SLASHES); ?>;
    </script>
<?php if ($role === 'tutor'): ?>
    <script>
        (function () {
            const apiUrl = '../api/tutor/bookings.php';
            const buttons = document.querySelectorAll('.tutor-request-action');
            const statusBox = document.getElementById('tutorRequestsStatus');

            if (buttons.length === 0) {
                return;
            }

            function setStatus(message, isError) {
                if (!statusBox) {
                    return;
                }
                statusBox.textContent = message || '';
                statusBox.style.color = isError ? '#7f1f19' : 'rgba(77, 20, 23, 0.7)';
            }

            function sendTutorAction(action, bookingId, reason) {
                const payload = { action: action, booking_id: bookingId };
                if (reason) {
                    payload.reason = reason;
                }

                setStatus('Submitting your response…');

                return fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok || data.success === false) {
                                const message = (data && data.message) ? data.message : 'Update failed.';
                                throw new Error(message);
                            }
                            return data;
                        });
                    })
                    .then(function (data) {
                        setStatus(data.message || 'Booking updated.');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 600);
                    })
                    .catch(function (error) {
                        console.error(error);
                        setStatus(error.message || 'Unable to update booking.', true);
                    });
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const action = button.getAttribute('data-action');
                    const bookingId = parseInt(button.getAttribute('data-booking-id'), 10);
                    if (!action || !bookingId) {
                        return;
                    }

                    if (action === 'tutor_decline' || action === 'tutor_cancel') {
                        const note = window.prompt('Please share a short note for the student.');
                        if (!note) {
                            return;
                        }
                        sendTutorAction(action, bookingId, note.trim());
                    } else {
                        sendTutorAction(action, bookingId);
                    }
                });
            });
        })();
    </script>
<?php endif; ?>
    <script>
        (function () {
            const threadsList = document.querySelector('[data-message-threads]');
            const log = document.querySelector('[data-message-log]');
            if (!threadsList || !log) {
                return;
            }

            const replyForm = document.querySelector('[data-message-reply]');
            const replyInput = replyForm ? replyForm.querySelector('textarea[name="message"]') : null;
            const replyThreadField = replyForm ? replyForm.querySelector('[data-active-thread]') : null;
            const newForm = document.querySelector('[data-message-new]');
            const newMessageInput = newForm ? newForm.querySelector('textarea[name="message"]') : null;
            const newRecipientField = newForm ? newForm.querySelector('select[name="recipient_id"]') : null;
            const refreshButton = document.querySelector('[data-refresh-threads]');
            const statusNode = document.getElementById('messageStatus');
            const unreadDisplay = document.querySelector('[data-message-unread]');
            const endpoint = '../api/messages.php';
            const currentUserId = <?php echo (int) $user_id; ?>;
            const threadMeta = new Map();
            const realtimeConfig = window.__SMARTTUTOR_WS__ || {};
            let currentThreadId = null;
            let loadingThreads = false;
            let realtimeSocket = null;
            let reconnectTimer = null;

            function setStatus(message, isError) {
                if (!statusNode) {
                    return;
                }
                statusNode.textContent = message || '';
                statusNode.style.color = isError ? '#7f1f19' : 'rgba(0, 0, 0, 0.65)';
            }

            function parseDate(value) {
                if (!value) {
                    return null;
                }
                try {
                    const normalized = value.includes('T') ? value : value.replace(' ', 'T');
                    const date = new Date(normalized);
                    return Number.isNaN(date.getTime()) ? null : date;
                } catch (error) {
                    return null;
                }
            }

            function formatTimestamp(value) {
                const date = parseDate(value);
                if (!date) {
                    return value || '';
                }
                return date.toLocaleString(undefined, { hour: 'numeric', minute: 'numeric', month: 'short', day: 'numeric' });
            }

            function handleResponse(response) {
                return response.json().then(function (payload) {
                    if (!response.ok || payload.success === false) {
                        const message = (payload && payload.message) ? payload.message : 'Request failed.';
                        throw new Error(message);
                    }
                    return payload;
                });
            }

            function highlightActiveThread() {
                const items = threadsList.querySelectorAll('.thread-item');
                items.forEach(function (item) {
                    const threadId = parseInt(item.getAttribute('data-thread-id'), 10);
                    if (currentThreadId && threadId === currentThreadId) {
                        item.classList.add('is-active');
                    } else {
                        item.classList.remove('is-active');
                    }
                });
            }

            function updateUnreadDisplay() {
                if (!unreadDisplay) {
                    return;
                }
                let total = 0;
                threadMeta.forEach(function (meta) {
                    total += meta.unreadCount || 0;
                });
                const indicatorValue = unreadDisplay.querySelector('strong');
                if (indicatorValue) {
                    indicatorValue.textContent = String(total);
                }
            }

            function setThreadMeta(threadId, meta) {
                const existing = threadMeta.get(threadId) || {};
                threadMeta.set(threadId, Object.assign({}, existing, meta));
                updateUnreadDisplay();
            }

            function renderThreads(threads) {
                if (!Array.isArray(threads) || threads.length === 0) {
                    threadsList.innerHTML = '<li class="placeholder">No conversations yet.</li>';
                    updateUnreadDisplay();
                    return;
                }

                const fragment = document.createDocumentFragment();
                threads.forEach(function (thread) {
                    const id = parseInt(thread.id, 10);
                    const unreadCount = parseInt(thread.unread_count, 10) || 0;
                    const lastReadAt = parseDate(thread.self_last_read_at);
                    setThreadMeta(id, {
                        unreadCount: unreadCount,
                        lastReadAt: lastReadAt,
                    });

                    const item = document.createElement('li');
                    item.className = 'thread-item';
                    if (unreadCount > 0 || thread.has_unread) {
                        item.classList.add('thread-item--unread');
                    }
                    item.setAttribute('data-thread-id', String(id));

                    const headerRow = document.createElement('div');
                    headerRow.className = 'thread-header-row';

                    const subject = document.createElement('p');
                    subject.className = 'thread-subject';
                    subject.textContent = thread.subject || 'Conversation';
                    headerRow.appendChild(subject);

                    if (unreadCount > 0) {
                        const badge = document.createElement('span');
                        badge.className = 'thread-unread-badge';
                        badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
                        headerRow.appendChild(badge);
                    }

                    item.appendChild(headerRow);

                    const preview = document.createElement('p');
                    preview.className = 'thread-meta';
                    const participantNames = thread.participants || '';
                    const lastActive = thread.last_message_at ? formatTimestamp(thread.last_message_at) : '';
                    const pieces = [];
                    if (participantNames) {
                        pieces.push(participantNames);
                    }
                    if (lastActive) {
                        pieces.push('Updated ' + lastActive);
                    }
                    preview.textContent = pieces.join(' • ');
                    item.appendChild(preview);

                    if (thread.last_message_preview) {
                        const snippet = document.createElement('p');
                        snippet.className = 'thread-meta';
                        snippet.textContent = thread.last_message_preview;
                        item.appendChild(snippet);
                    }

                    item.addEventListener('click', function () {
                        if (id) {
                            loadMessages(id);
                        }
                    });

                    fragment.appendChild(item);
                });

                threadsList.innerHTML = '';
                threadsList.appendChild(fragment);
                highlightActiveThread();
                updateUnreadDisplay();
            }

            function renderMessages(messages) {
                if (!Array.isArray(messages) || messages.length === 0) {
                    log.innerHTML = '<p class="placeholder">No messages yet. Say hello!</p>';
                    return;
                }

                const fragment = document.createDocumentFragment();
                const meta = currentThreadId ? threadMeta.get(currentThreadId) : null;
                const lastReadAt = meta && meta.lastReadAt ? meta.lastReadAt : null;

                messages.forEach(function (message) {
                    const bubble = document.createElement('div');
                    bubble.className = 'message-bubble';
                    if (parseInt(message.sender_id, 10) === currentUserId) {
                        bubble.classList.add('message-bubble--own');
                    }

                    const messageDate = parseDate(message.created_at);
                    if (lastReadAt && messageDate && messageDate > lastReadAt) {
                        bubble.classList.add('message-bubble--unread');
                    }

                    const metaRow = document.createElement('p');
                    metaRow.className = 'message-bubble__meta';
                    const author = message.sender_name || 'You';
                    const timestamp = messageDate ? formatTimestamp(message.created_at) : '';
                    metaRow.textContent = timestamp ? author + ' • ' + timestamp : author;
                    bubble.appendChild(metaRow);

                    const body = document.createElement('p');
                    body.className = 'message-bubble__body';
                    body.textContent = message.message_text || '';
                    bubble.appendChild(body);

                    fragment.appendChild(bubble);
                });

                log.innerHTML = '';
                log.appendChild(fragment);
                log.scrollTop = log.scrollHeight;
            }

            function loadMessages(threadId) {
                if (!threadId) {
                    return;
                }
                currentThreadId = threadId;
                if (replyThreadField) {
                    replyThreadField.value = String(threadId);
                }
                highlightActiveThread();
                log.innerHTML = '<p class="placeholder">Loading messages…</p>';
                setStatus('Loading conversation…');
                fetch(endpoint + '?thread_id=' + encodeURIComponent(threadId), { credentials: 'same-origin' })
                    .then(handleResponse)
                    .then(function (payload) {
                        renderMessages(payload.data || []);
                        const now = new Date();
                        setThreadMeta(threadId, { lastReadAt: now, unreadCount: 0 });
                        setStatus('');
                    })
                    .catch(function (error) {
                        setStatus(error.message || 'Unable to load messages.', true);
                    });
            }

            function fetchThreads(options) {
                const silent = options && options.silent;
                if (loadingThreads) {
                    return;
                }
                loadingThreads = true;
                if (!silent) {
                    setStatus('Refreshing conversations…');
                    threadsList.innerHTML = '<li class="placeholder">Loading your conversations…</li>';
                }
                fetch(endpoint, { credentials: 'same-origin' })
                    .then(handleResponse)
                    .then(function (payload) {
                        const data = payload.data || [];
                        renderThreads(data);
                        if (!currentThreadId && data.length > 0) {
                            const firstId = parseInt(data[0].id, 10);
                            if (firstId) {
                                loadMessages(firstId);
                            }
                        }
                        if (!silent) {
                            setStatus('');
                        }
                        loadingThreads = false;
                    })
                    .catch(function (error) {
                        if (!silent) {
                            setStatus(error.message || 'Unable to load conversations.', true);
                        }
                        loadingThreads = false;
                    });
            }

            function connectRealtime() {
                const url = realtimeConfig.url;
                const token = realtimeConfig.token;
                if (!url || !token || typeof WebSocket === 'undefined') {
                    return;
                }
                try {
                    const socket = new WebSocket(url);
                    realtimeSocket = socket;

                    socket.addEventListener('open', function () {
                        socket.send(JSON.stringify({ type: 'authenticate', token: token }));
                        socket.send(JSON.stringify({ type: 'subscribe', channels: ['messages'] }));
                    });

                    socket.addEventListener('message', function (event) {
                        try {
                            const payload = JSON.parse(event.data);
                            if (payload.type === 'message_notification' && payload.thread_id) {
                                handleRealtimeNotification(payload);
                            }
                        } catch (error) {
                            console.warn('Realtime message parse error', error);
                        }
                    });

                    socket.addEventListener('close', function () {
                        realtimeSocket = null;
                        if (reconnectTimer) {
                            window.clearTimeout(reconnectTimer);
                        }
                        reconnectTimer = window.setTimeout(connectRealtime, 4000);
                    });

                    socket.addEventListener('error', function () {
                        socket.close();
                    });
                } catch (error) {
                    console.warn('Realtime connection unavailable', error);
                }
            }

            function handleRealtimeNotification(payload) {
                const threadId = parseInt(payload.thread_id, 10);
                if (!threadId) {
                    return;
                }
                if (currentThreadId && threadId === currentThreadId) {
                    loadMessages(threadId);
                }
                fetchThreads({ silent: true });
            }

            if (refreshButton) {
                refreshButton.addEventListener('click', function () {
                    fetchThreads();
                });
            }

            if (replyForm && replyInput) {
                replyForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    const message = replyInput.value.trim();
                    if (!currentThreadId) {
                        setStatus('Select a conversation first.', true);
                        return;
                    }
                    if (!message) {
                        setStatus('Add a message before sending.', true);
                        return;
                    }

                    setStatus('Sending message…');
                    fetch(endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send', thread_id: currentThreadId, message: message })
                    })
                        .then(handleResponse)
                        .then(function () {
                            replyInput.value = '';
                            loadMessages(currentThreadId);
                            fetchThreads({ silent: true });
                            setStatus('Message sent.');
                        })
                        .catch(function (error) {
                            setStatus(error.message || 'Unable to send message.', true);
                        });
                });
            }

            if (newForm && newMessageInput && newRecipientField) {
                newForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    const message = newMessageInput.value.trim();
                    const recipientId = parseInt(newRecipientField.value, 10);
                    if (!recipientId) {
                        setStatus('Choose a recipient to start chatting.', true);
                        return;
                    }
                    if (!message) {
                        setStatus('Add a quick note before sending.', true);
                        return;
                    }

                    setStatus('Starting conversation…');
                    fetch(endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send', recipient_id: recipientId, message: message })
                    })
                        .then(handleResponse)
                        .then(function (payload) {
                            newMessageInput.value = '';
                            newRecipientField.value = '';
                            const createdThreadId = payload.data && payload.data.thread_id ? parseInt(payload.data.thread_id, 10) : 0;
                            if (createdThreadId) {
                                loadMessages(createdThreadId);
                            }
                            fetchThreads();
                            setStatus('Conversation started.');
                        })
                        .catch(function (error) {
                            setStatus(error.message || 'Unable to send message.', true);
                        });
                });
            }

            window.addEventListener('beforeunload', function () {
                if (reconnectTimer) {
                    window.clearTimeout(reconnectTimer);
                }
                if (realtimeSocket) {
                    realtimeSocket.close();
                }
            });

            fetchThreads();
            connectRealtime();
        })();
    </script>
</body>
</html>
