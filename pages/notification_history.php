<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: sign-in.html');
    exit();
}

require_once __DIR__ . '/../connect.php';

function bindStatementParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $references = [];
    foreach ($params as $index => $value) {
        $references[$index] = &$params[$index];
    }

    $stmt->bind_param($types, ...$references);
}

function fetchAssocList(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
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

function fetchScalar(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();

    return (int) ($value ?? 0);
}

function truncateText(?string $value, int $limit = 240): string
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

function formatDateTimeValue(?string $value, string $format = 'M j, Y - g:i A'): string
{
    if (empty($value)) {
        return 'TBD';
    }

    try {
        $date = new DateTime($value);
        return $date->format($format);
    } catch (Exception $e) {
        return $value;
    }
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

function markAllNotificationsRead(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $affected = max(0, $stmt->affected_rows);
    $stmt->close();

    return $affected;
}

function buildQueryString(array $params): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }

        if ($value === '') {
            continue;
        }

        $filtered[$key] = $value;
    }

    return http_build_query($filtered);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$name = $_SESSION['name'] ?? 'Member';
$role = $_SESSION['role'] ?? 'student';

$tab = $_GET['tab'] ?? 'notifications';
$allowedTabs = ['notifications', 'announcements'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'notifications';
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

$perPage = 10;
$pageParam = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$currentPage = $pageParam > 0 ? $pageParam : 1;

$notificationsLevel = $_GET['level'] ?? '';
if (!in_array($notificationsLevel, ['info', 'success', 'warning', 'danger'], true)) {
    $notificationsLevel = '';
}

$notificationStatus = $_GET['status'] ?? '';
if (!in_array($notificationStatus, ['unread', 'read'], true)) {
    $notificationStatus = '';
}

$announcementPriority = $_GET['priority'] ?? '';
if (!in_array($announcementPriority, ['normal', 'important', 'critical'], true)) {
    $announcementPriority = '';
}

$announcementPinnedOnly = isset($_GET['pinned']) && $_GET['pinned'] === '1';

$notificationBaseParams = ['tab' => 'notifications'];
if ($notificationsLevel !== '') {
    $notificationBaseParams['level'] = $notificationsLevel;
}
if ($notificationStatus !== '') {
    $notificationBaseParams['status'] = $notificationStatus;
}

$announcementBaseParams = ['tab' => 'announcements'];
if ($announcementPriority !== '') {
    $announcementBaseParams['priority'] = $announcementPriority;
}
if ($announcementPinnedOnly) {
    $announcementBaseParams['pinned'] = '1';
}

$announcements = [];
$notifications = [];
$pagination = ['page' => 1, 'totalPages' => 1, 'baseParams' => $tab === 'announcements' ? $announcementBaseParams : $notificationBaseParams];
$autoMarkMessage = null;
$unreadNotificationCount = fetchScalar(
    $conn,
    'SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0',
    'i',
    [$userId]
);

if ($tab === 'announcements' && count($announcementAudiences) > 0) {
    $audiencePlaceholders = implode(',', array_fill(0, count($announcementAudiences), '?'));
    $conditions = ['am.audience IN (' . $audiencePlaceholders . ')'];
    $types = str_repeat('s', count($announcementAudiences));
    $params = $announcementAudiences;

    if ($announcementPriority !== '') {
        $conditions[] = 'am.priority = ?';
        $types .= 's';
        $params[] = $announcementPriority;
    }

    if ($announcementPinnedOnly) {
        $conditions[] = 'am.pinned = 1';
    }

    $whereClause = implode(' AND ', $conditions);
    $totalAnnouncements = fetchScalar(
        $conn,
        "SELECT COUNT(*) FROM admin_messages am WHERE $whereClause",
        $types,
        $params
    );

    $totalAnnouncementPages = max(1, (int) ceil($totalAnnouncements / $perPage));
    $announcementPage = min($currentPage, $totalAnnouncementPages);
    $announcementOffset = ($announcementPage - 1) * $perPage;

    $announcements = fetchAssocList(
        $conn,
        "SELECT am.*, authors.name AS author_name
         FROM admin_messages am
         LEFT JOIN users authors ON authors.id = am.author_id
         WHERE $whereClause
         ORDER BY am.pinned DESC, am.created_at DESC
         LIMIT ?, ?",
        $types . 'ii',
        array_merge($params, [$announcementOffset, $perPage])
    );

    $pagination = [
        'page' => $announcementPage,
        'totalPages' => $totalAnnouncementPages,
        'baseParams' => $announcementBaseParams,
    ];
} else {
    $conditions = ['user_id = ?'];
    $types = 'i';
    $params = [$userId];

    if ($notificationsLevel !== '') {
        $conditions[] = 'level = ?';
        $types .= 's';
        $params[] = $notificationsLevel;
    }

    if ($notificationStatus === 'unread') {
        $conditions[] = 'is_read = 0';
    } elseif ($notificationStatus === 'read') {
        $conditions[] = 'is_read = 1';
    }

    $whereClause = implode(' AND ', $conditions);

    $totalNotifications = fetchScalar(
        $conn,
        "SELECT COUNT(*) FROM user_notifications WHERE $whereClause",
        $types,
        $params
    );

    $totalNotificationPages = max(1, (int) ceil($totalNotifications / $perPage));
    $notificationPage = min($currentPage, $totalNotificationPages);
    $notificationOffset = ($notificationPage - 1) * $perPage;

    $notifications = fetchAssocList(
        $conn,
        "SELECT id, title, body, level, is_read, created_at
         FROM user_notifications
         WHERE $whereClause
         ORDER BY created_at DESC
         LIMIT ?, ?",
        $types . 'ii',
        array_merge($params, [$notificationOffset, $perPage])
    );

    if ($notificationStatus !== 'unread') {
        $autoMarkedCount = markAllNotificationsRead($conn, $userId);
        if ($autoMarkedCount > 0) {
            $unreadNotificationCount = max(0, $unreadNotificationCount - $autoMarkedCount);
            foreach ($notifications as &$notification) {
                if ((int) ($notification['is_read'] ?? 0) === 0) {
                    $notification['is_read'] = 1;
                }
            }
            unset($notification);
            $autoMarkMessage = $autoMarkedCount . ' notifications marked as read after viewing this page.';
        }
    }

    $pagination = [
        'page' => $notificationPage,
        'totalPages' => $totalNotificationPages,
        'baseParams' => $notificationBaseParams,
    ];
}

$notificationFlash = $_SESSION['notification_flash'] ?? null;
unset($_SESSION['notification_flash']);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications & Announcements - SmartTutor Connect</title>
    <link href="../src/css/main.css" rel="stylesheet">
    <link href="../src/css/pages.css" rel="stylesheet">
    <style>
        .history-layout {
            width: min(900px, calc(100% - 40px));
            margin: 40px auto 60px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .history-intro h1 {
            color: #ffffff;
        }
        .history-intro p {
            color: rgba(255, 255, 255, 0.85);
        }
        .tabs {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .tabs a {
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            text-decoration: none;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
        }
        .tabs a.active {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.55);
        }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin: 12px 0 6px;
        }
        .filter-bar label {
            font-size: 0.9rem;
            font-weight: 600;
            color: rgba(0, 0, 0, 0.75);
        }
        .filter-bar select {
            margin-left: 6px;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid rgba(0, 0, 0, 0.2);
            background: #fff;
            font: inherit;
        }
        .announcement-list,
        .notification-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .announcement-item,
        .notification-item {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 14px;
            padding: 16px 18px;
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
            gap: 8px;
            flex-wrap: wrap;
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
            margin-top: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .inline-form {
            display: inline-flex;
            gap: 8px;
            align-items: center;
        }
        .inline-checkbox {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        .notice-flash {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.95rem;
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
            color: rgba(0, 0, 0, 0.65);
        }
        .auto-mark-note {
            margin: 4px 0 10px;
            font-size: 0.85rem;
            color: #1b568f;
        }
        .pagination-bar {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: flex-end;
            margin-top: 16px;
        }
        .pagination-bar a,
        .pagination-bar span.pagination-disabled {
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(0, 0, 0, 0.2);
            text-decoration: none;
            color: inherit;
            font-weight: 600;
        }
        .pagination-bar a:hover {
            border-color: #7d1224;
            color: #7d1224;
        }
        .pagination-disabled {
            opacity: 0.5;
        }
    </style>
</head>
<body class="history-page">
    <header class="page-header">
        <div class="container">
            <a class="brand-mark" href="../index.html">
                <img src="../public/images/logo.png" alt="SmartTutor Connect logo">
                <span>SmartTutor Connect</span>
            </a>
            <nav class="page-nav">
                <a href="dashboard.php">Back to dashboard</a>
                <span class="welcome-text">Hi, <?php echo htmlspecialchars($name); ?> 👋</span>
                <a href="../logout.php" class="btn btn-outline">Log out</a>
            </nav>
        </div>
    </header>

    <main class="history-layout">
        <div class="history-intro">
            <h1>Notifications & announcements</h1>
            <p>Review everything the admin team has shared with <?php echo htmlspecialchars(ucfirst($role)); ?> accounts.</p>
            <?php if (!empty($notificationFlash['message'])): ?>
                <div class="notice-flash <?php echo htmlspecialchars($notificationFlash['type'] ?? 'success'); ?>">
                    <?php echo htmlspecialchars($notificationFlash['message']); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tabs">
            <a href="?tab=notifications" class="<?php echo $tab === 'notifications' ? 'active' : ''; ?>">Your notifications <?php if ($unreadNotificationCount > 0): ?><span class="badge-soft" style="margin-left:8px;"><?php echo number_format($unreadNotificationCount); ?> unread</span><?php endif; ?></a>
            <a href="?tab=announcements" class="<?php echo $tab === 'announcements' ? 'active' : ''; ?>">Admin announcements</a>
        </div>

        <?php if ($tab === 'announcements'): ?>
            <section class="dashboard-card">
                <header>
                    <h2>Announcements for you</h2>
                    <?php
                        $audienceLabels = array_map('announcementAudienceLabel', $announcementAudiences);
                    ?>
                    <p class="metric-note">Combined feed for <?php echo htmlspecialchars(implode(', ', $audienceLabels)); ?>.</p>
                </header>
                <div class="filter-bar">
                    <form method="get" class="inline-form">
                        <input type="hidden" name="tab" value="announcements">
                        <label>
                            Priority
                            <select name="priority" onchange="this.form.submit()">
                                <option value="">All priorities</option>
                                <option value="normal" <?php echo $announcementPriority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="important" <?php echo $announcementPriority === 'important' ? 'selected' : ''; ?>>Important</option>
                                <option value="critical" <?php echo $announcementPriority === 'critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </label>
                        <label class="inline-checkbox">
                            <input type="checkbox" name="pinned" value="1" <?php echo $announcementPinnedOnly ? 'checked' : ''; ?> onchange="this.form.submit()">
                            Pinned only
                        </label>
                    </form>
                </div>
                <ul class="announcement-list">
                    <?php if (count($announcements) === 0): ?>
                        <li class="announcement-item"><p class="empty-feed-state">No announcements are available for your account yet.</p></li>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <?php $isPinned = (int) ($announcement['pinned'] ?? 0) === 1; ?>
                            <li class="announcement-item <?php echo $isPinned ? 'announcement-item--pinned' : ''; ?>">
                                <div class="announcement-meta">
                                    <?php if ($isPinned): ?><span class="audience-pill">Pinned</span><?php endif; ?>
                                    <span class="<?php echo announcementPriorityClass($announcement['priority'] ?? 'normal'); ?>"><?php echo htmlspecialchars(ucfirst($announcement['priority'] ?? 'normal')); ?></span>
                                    <span class="audience-pill"><?php echo htmlspecialchars(announcementAudienceLabel($announcement['audience'] ?? 'all_users')); ?></span>
                                    <span><?php echo htmlspecialchars(formatDateTimeValue($announcement['created_at'] ?? '')); ?></span>
                                </div>
                                <h3><?php echo htmlspecialchars($announcement['subject'] ?? 'Announcement'); ?></h3>
                                <p><?php echo htmlspecialchars(truncateText($announcement['body'] ?? '', 600)); ?></p>
                                <?php if (!empty($announcement['author_name'])): ?>
                                    <p class="announcement-meta">By <?php echo htmlspecialchars($announcement['author_name']); ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <?php if ($pagination['totalPages'] > 1): ?>
                    <div class="pagination-bar">
                        <?php if ($pagination['page'] > 1): ?>
                            <?php $prevParams = $pagination['baseParams']; $prevParams['page'] = $pagination['page'] - 1; ?>
                            <a href="?<?php echo htmlspecialchars(buildQueryString($prevParams)); ?>">Previous</a>
                        <?php else: ?>
                            <span class="pagination-disabled">Previous</span>
                        <?php endif; ?>
                        <span>Page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?></span>
                        <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                            <?php $nextParams = $pagination['baseParams']; $nextParams['page'] = $pagination['page'] + 1; ?>
                            <a href="?<?php echo htmlspecialchars(buildQueryString($nextParams)); ?>">Next</a>
                        <?php else: ?>
                            <span class="pagination-disabled">Next</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="dashboard-card">
                <header class="notification-meta" style="justify-content: space-between; align-items: center;">
                    <div>
                        <h2>Your notification history</h2>
                        <p class="metric-note">All alerts delivered to your account.</p>
                    </div>
                    <?php if ($unreadNotificationCount > 0): ?>
                        <form class="inline-form" method="post" action="notification_actions.php">
                            <input type="hidden" name="action" value="notifications_mark_read">
                            <input type="hidden" name="mark_all" value="1">
                            <input type="hidden" name="redirect_to" value="notification_history.php?tab=notifications">
                            <button type="submit" class="btn btn-sm btn-primary">Mark all read</button>
                        </form>
                    <?php endif; ?>
                </header>
                <div class="filter-bar">
                    <form method="get" class="inline-form">
                        <input type="hidden" name="tab" value="notifications">
                        <label>
                            Level
                            <select name="level" onchange="this.form.submit()">
                                <option value="">All levels</option>
                                <option value="info" <?php echo $notificationsLevel === 'info' ? 'selected' : ''; ?>>Info</option>
                                <option value="success" <?php echo $notificationsLevel === 'success' ? 'selected' : ''; ?>>Success</option>
                                <option value="warning" <?php echo $notificationsLevel === 'warning' ? 'selected' : ''; ?>>Warning</option>
                                <option value="danger" <?php echo $notificationsLevel === 'danger' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </label>
                        <label>
                            Status
                            <select name="status" onchange="this.form.submit()">
                                <option value="">All</option>
                                <option value="unread" <?php echo $notificationStatus === 'unread' ? 'selected' : ''; ?>>Unread only</option>
                                <option value="read" <?php echo $notificationStatus === 'read' ? 'selected' : ''; ?>>Read only</option>
                            </select>
                        </label>
                    </form>
                </div>
                <?php if (!empty($autoMarkMessage)): ?>
                    <p class="auto-mark-note"><?php echo htmlspecialchars($autoMarkMessage); ?></p>
                <?php endif; ?>
                <ul class="notification-list">
                    <?php if (count($notifications) === 0): ?>
                        <li class="notification-item"><p class="empty-feed-state">No notifications yet. Actions will appear here once recorded.</p></li>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
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
                                <p><?php echo htmlspecialchars(truncateText($notification['body'] ?? '', 600)); ?></p>
                                <?php if (!$isRead && $notificationId > 0): ?>
                                    <div class="notification-item-actions">
                                        <form method="post" action="notification_actions.php" class="inline-form">
                                            <input type="hidden" name="action" value="notifications_mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo $notificationId; ?>">
                                            <input type="hidden" name="redirect_to" value="notification_history.php?tab=notifications">
                                            <button type="submit" class="btn btn-sm btn-outline">Mark as read</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <?php if ($pagination['totalPages'] > 1): ?>
                    <div class="pagination-bar">
                        <?php if ($pagination['page'] > 1): ?>
                            <?php $prevParams = $pagination['baseParams']; $prevParams['page'] = $pagination['page'] - 1; ?>
                            <a href="?<?php echo htmlspecialchars(buildQueryString($prevParams)); ?>">Previous</a>
                        <?php else: ?>
                            <span class="pagination-disabled">Previous</span>
                        <?php endif; ?>
                        <span>Page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?></span>
                        <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                            <?php $nextParams = $pagination['baseParams']; $nextParams['page'] = $pagination['page'] + 1; ?>
                            <a href="?<?php echo htmlspecialchars(buildQueryString($nextParams)); ?>">Next</a>
                        <?php else: ?>
                            <span class="pagination-disabled">Next</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
