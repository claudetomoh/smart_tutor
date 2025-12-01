<?php
session_start();

// Only administrators may view this console
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: sign-in.html');
    exit();
}

require_once __DIR__ . '/../connect.php';

ensureContactTable($conn);
$currentAdminId = (int) ($_SESSION['user_id'] ?? 0);

function fetchScalar(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_row();
    $value = (int) ($row[0] ?? 0);
    $result->free();

    return $value;
}

function fetchAssocList(mysqli $conn, string $sql): array
{
    $rows = [];

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    return $rows;
}

$roleCounts = [
    'student' => 0,
    'tutor' => 0,
    'admin' => 0
];
if ($result = $conn->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')) {
    while ($row = $result->fetch_assoc()) {
        $key = $row['role'] ?? '';
        if ($key !== '') {
            $roleCounts[$key] = (int) $row['total'];
        }
    }
    $result->free();
}

$bookingStatusCounts = [
    'pending' => 0,
    'accepted' => 0,
    'declined' => 0,
    'expired' => 0
];
if ($result = $conn->query('SELECT status, COUNT(*) AS total FROM booking_requests GROUP BY status')) {
    while ($row = $result->fetch_assoc()) {
        $status = $row['status'] ?? '';
        if ($status !== '') {
            $bookingStatusCounts[$status] = (int) $row['total'];
        }
    }
    $result->free();
}

$sessionStatusCounts = [];
if ($result = $conn->query('SELECT status, COUNT(*) AS total FROM tutoring_sessions GROUP BY status')) {
    while ($row = $result->fetch_assoc()) {
        $status = $row['status'] ?? '';
        if ($status !== '') {
            $sessionStatusCounts[$status] = (int) $row['total'];
        }
    }
    $result->free();
}

$recentBookings = fetchAssocList($conn, 'SELECT
    br.student_name,
    br.student_email,
    br.requested_for,
    br.status,
    br.reference,
    u.name AS tutor_name
FROM booking_requests br
LEFT JOIN users u ON u.id = br.tutor_id
ORDER BY br.created_at DESC
LIMIT 5');

$recentSecurityEvents = fetchAssocList($conn, 'SELECT event_type, description, ip_address, created_at FROM security_events ORDER BY created_at DESC LIMIT 5');

$contactRequests = fetchAssocList($conn, 'SELECT name, email, requester_role, message, status, created_at FROM contact_requests ORDER BY created_at DESC LIMIT 6');

$bookingQueue = fetchAssocList($conn, 'SELECT br.*, t.name AS tutor_name, t.email AS tutor_email,
    sc.name AS status_changed_by_name,
    st.name AS student_user_name,
    st.id AS student_user_id
FROM booking_requests br
LEFT JOIN users t ON t.id = br.tutor_id
LEFT JOIN users st ON st.id = br.student_id
LEFT JOIN users sc ON sc.id = br.status_changed_by
ORDER BY br.created_at DESC
LIMIT 15');

$paymentStatusCounts = [];
if ($result = $conn->query('SELECT status, COUNT(*) AS total FROM payments GROUP BY status')) {
    while ($row = $result->fetch_assoc()) {
        $paymentStatusCounts[$row['status']] = (int) $row['total'];
    }
    $result->free();
}

$pendingPaymentsList = fetchAssocList($conn, 'SELECT p.id, p.amount, p.currency, p.status, p.created_at,
    p.platform_fee, p.commission_amount, p.tutor_payout, p.payout_status,
    ts.start_time, ts.end_time,
    tutors.name AS tutor_name, tutors.email AS tutor_email,
    students.name AS student_name, students.email AS student_email
FROM payments p
LEFT JOIN tutoring_sessions ts ON ts.id = p.session_id
LEFT JOIN users tutors ON tutors.id = ts.tutor_id
LEFT JOIN users students ON students.id = ts.student_id
ORDER BY p.created_at DESC
LIMIT 8');

$commissionStatusCounts = [];
if ($result = $conn->query('SELECT status, COUNT(*) AS total FROM commission_ledger GROUP BY status')) {
    while ($row = $result->fetch_assoc()) {
        $commissionStatusCounts[$row['status']] = (int) $row['total'];
    }
    $result->free();
}

$commissionQueue = fetchAssocList($conn, 'SELECT cl.id, cl.commission_rate, cl.commission_amount, cl.status, cl.created_at,
    cl.booking_id, cl.session_id,
    tutors.name AS tutor_name, tutors.email AS tutor_email
FROM commission_ledger cl
LEFT JOIN users tutors ON tutors.id = cl.tutor_id
ORDER BY cl.created_at DESC
LIMIT 8');

$tutorDirectory = fetchAssocList($conn, 'SELECT u.id, u.name, u.email, u.status,
    COALESCE(tp.verification_status, "pending") AS verification_status,
    COALESCE(tp.hourly_rate, 0) AS hourly_rate,
    COALESCE(tp.rating, 0) AS rating,
    COALESCE(tp.total_sessions, 0) AS total_sessions,
    u.updated_at
FROM users u
LEFT JOIN tutor_profiles tp ON tp.tutor_id = u.id
WHERE u.role = "tutor"
ORDER BY u.updated_at DESC
LIMIT 12');

$managedUsers = fetchAssocList($conn, 'SELECT id, name, email, role, status, status_reason, warnings_count, last_warning_at, suspended_until, policy_notes FROM users ORDER BY created_at DESC LIMIT 25');
$adminMessages = fetchAssocList($conn, 'SELECT am.*, u.name AS author_name FROM admin_messages am LEFT JOIN users u ON u.id = am.author_id ORDER BY am.pinned DESC, am.created_at DESC LIMIT 6');
$recentPlatformNotifications = fetchAssocList($conn, 'SELECT un.title, un.body, un.level, un.created_at, us.name AS recipient_name FROM user_notifications un LEFT JOIN users us ON us.id = un.user_id ORDER BY un.created_at DESC LIMIT 6');

$latestMetrics = [];
if ($result = $conn->query('SELECT risk_score, active_threats, resolved_threats, failed_logins, successful_logins, compliance_score, vulnerability_count, incident_count, created_at FROM security_metrics ORDER BY created_at DESC LIMIT 1')) {
    $latestMetrics = $result->fetch_assoc() ?: [];
    $result->free();
}

$activeIncidents = fetchScalar($conn, "SELECT COUNT(*) FROM security_incidents WHERE status = 'active'");
$lockedAccounts = fetchScalar($conn, 'SELECT COUNT(*) FROM users WHERE failed_login_attempts >= 5');

$totalUsers = array_sum($roleCounts);
$pendingBookings = $bookingStatusCounts['pending'] ?? 0;
$scheduledSessions = ($sessionStatusCounts['scheduled'] ?? 0) + ($sessionStatusCounts['ongoing'] ?? 0);

$conn->close();

$adminFlash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

function formatDateTime(?string $value): string
{
    if (empty($value)) {
        return 'TBD';
    }

    try {
        $date = new DateTime($value);
        return $date->format('M j, Y • g:i A');
    } catch (Exception $e) {
        return $value;
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bookingBadgeClass(string $status): string
{
    return match ($status) {
        'accepted' => 'status-badge status-confirmed',
        'declined' => 'status-badge status-declined',
        'expired' => 'status-badge status-expired',
        default => 'status-badge status-pending',
    };
}

function userStatusBadgeClass(string $status): string
{
    return match ($status) {
        'suspended' => 'status-badge status-declined',
        'inactive' => 'status-badge status-expired',
        'warned' => 'status-badge status-pending',
        default => 'status-badge status-confirmed',
    };
}

function contactBadgeClass(string $status): string
{
    return match ($status) {
        'closed' => 'status-badge status-confirmed',
        'in_progress' => 'status-badge status-pending',
        default => 'status-badge status-pending',
    };
}

function formatAudienceLabel(string $audience): string
{
    return match ($audience) {
        'all_users' => 'All users',
        'tutors' => 'Tutors only',
        'students' => 'Students only',
        'admins' => 'Admin team',
        default => ucfirst(str_replace('_', ' ', $audience)),
    };
}

function priorityBadgeClass(string $priority): string
{
    return match ($priority) {
        'critical' => 'priority-badge priority-critical',
        'important' => 'priority-badge priority-important',
        default => 'priority-badge priority-normal',
    };
}

function notificationLevelBadgeClass(string $level): string
{
    return match ($level) {
        'critical' => 'notification-badge notification-critical',
        'warning' => 'notification-badge notification-warning',
        'success' => 'notification-badge notification-success',
        default => 'notification-badge notification-info',
    };
}

function summarizeText(string $value, int $limit = 140): string
{
    $value = trim($value);
    if ($value === '') {
        return '—';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit - 1)) . '…';
    }

    if (strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(substr($value, 0, $limit - 1)) . '…';
}

function ensureContactTable(mysqli $conn): void
{
    $conn->query('CREATE TABLE IF NOT EXISTS contact_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL,
        requester_role ENUM("student", "parent", "tutor", "organization", "other") NOT NULL DEFAULT "other",
        message TEXT NOT NULL,
        status ENUM("new", "in_progress", "closed") NOT NULL DEFAULT "new",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        handled_at TIMESTAMP NULL DEFAULT NULL,
        handled_by VARCHAR(150) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Console • SmartTutor Connect</title>
    <link href="../src/css/main.css" rel="stylesheet">
    <link href="../src/css/pages.css" rel="stylesheet">
    <link href="../src/css/dashboard.css" rel="stylesheet">
    <style>
        .status-declined {
            background: rgba(179, 38, 30, 0.2);
            color: #7f1f19;
        }
        .status-expired {
            background: rgba(20, 20, 20, 0.14);
            color: #2c2c2c;
        }
        .metric-note {
            font-size: 0.85rem;
            color: rgba(77, 20, 23, 0.65);
        }
        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        .admin-action-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .admin-action-form {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .admin-action-form input[type="text"],
        .admin-action-form textarea,
        .admin-action-form select {
            flex: 1 1 140px;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid rgba(77, 20, 23, 0.15);
            font: inherit;
        }
        .admin-action-form textarea {
            min-height: 60px;
        }
        .admin-action-form button {
            padding: 10px 16px;
            border-radius: 999px;
            border: 0;
            font-weight: 600;
            background: #7d1224;
            color: #fff;
            cursor: pointer;
        }
        .admin-flash {
            margin: 10px 0 24px;
            padding: 14px 18px;
            border-radius: 12px;
            font-weight: 600;
        }
        .admin-flash.success {
            background: rgba(46, 160, 67, 0.15);
            color: #22543d;
        }
        .admin-flash.error {
            background: rgba(179, 38, 30, 0.18);
            color: #7f1f19;
        }
        .all-bookings-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
            align-items: center;
        }
        .all-bookings-controls input,
        .all-bookings-controls select {
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid rgba(77, 20, 23, 0.2);
            font: inherit;
        }
        .table-pagination {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 14px;
        }
        .table-pagination button {
            border: 0;
            border-radius: 999px;
            padding: 8px 16px;
            background: rgba(77, 20, 23, 0.12);
            font-weight: 600;
            cursor: pointer;
        }
        .booking-grid-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .btn-sm {
            padding: 6px 10px;
            font-size: 0.85rem;
            border-radius: 999px;
        }
        .announcement-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 16px;
        }
        .announcement-form .form-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .message-board,
        .notification-list {
            list-style: none;
            padding: 0;
            margin: 18px 0 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .message-item,
        .notification-card {
            border: 1px solid rgba(77, 20, 23, 0.15);
            border-radius: 14px;
            padding: 14px 16px;
            background: #fff;
        }
        .message-item.message-pinned {
            border-color: rgba(125, 18, 36, 0.35);
            box-shadow: 0 0 0 2px rgba(125, 18, 36, 0.08);
        }
        .message-pin {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #7d1224;
        }
        .priority-badge,
        .notification-badge,
        .audience-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .priority-normal {
            background: rgba(20, 20, 20, 0.08);
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
        .audience-pill {
            background: rgba(77, 20, 23, 0.08);
            color: #4d1417;
        }
        .notification-info {
            background: rgba(27, 86, 143, 0.14);
            color: #1b568f;
        }
        .notification-warning {
            background: rgba(230, 142, 0, 0.18);
            color: #7a4100;
        }
        .notification-critical {
            background: rgba(179, 38, 30, 0.2);
            color: #7f1f19;
        }
        .notification-success {
            background: rgba(46, 160, 67, 0.16);
            color: #22543d;
        }
        .message-meta,
        .notification-meta,
        .notification-recipient {
            font-size: 0.85rem;
            color: rgba(0, 0, 0, 0.6);
        }
        .notification-card h3,
        .message-item h3 {
            margin: 8px 0 6px;
        }
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .inline-checkbox {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        .section-subheading {
            margin-top: 18px;
        }
    </style>
</head>
<body class="page-body">
    <header class="page-header">
        <div class="container">
            <a class="brand-mark" href="../index.html">
                <img src="../public/images/logo.png" alt="SmartTutor Connect logo">
                <span>SmartTutor Connect</span>
            </a>
            <nav class="page-nav">
                <span class="welcome-text">Hi, <?php echo escape($_SESSION['name'] ?? 'Admin'); ?> 👋</span>
                <a href="dashboard.php">User dashboard</a>
                <a href="admin.php" class="active">Admin console</a>
                <a href="../logout.php" class="btn btn-outline">Log out</a>
            </nav>
        </div>
    </header>

    <main class="page-content" style="width: min(1200px, calc(100% - 40px)); margin: 40px auto 60px;">
        <?php if (!empty($adminFlash['message'])): ?>
            <div class="admin-flash <?php echo escape($adminFlash['type'] ?? 'success'); ?>">
                <?php echo escape($adminFlash['message']); ?>
            </div>
        <?php endif; ?>
        <section class="dashboard-grid">
            <article class="dashboard-card">
                <p class="metric-label">Total platform users</p>
                <p class="metric-value"><?php echo number_format($totalUsers); ?></p>
                <p class="metric-note"><?php echo number_format($roleCounts['admin'] ?? 0); ?> admins keep an eye on the platform.</p>
            </article>
            <article class="dashboard-card">
                <p class="metric-label">Active tutors</p>
                <p class="metric-value"><?php echo number_format($roleCounts['tutor'] ?? 0); ?></p>
                <p class="metric-note">Verified tutors currently listed in the directory.</p>
            </article>
            <article class="dashboard-card">
                <p class="metric-label">Students onboarded</p>
                <p class="metric-value"><?php echo number_format($roleCounts['student'] ?? 0); ?></p>
                <p class="metric-note">Students have registered or signed up.</p>
            </article>
            <article class="dashboard-card">
                <p class="metric-label">Pending booking requests</p>
                <p class="metric-value"><?php echo number_format($pendingBookings); ?></p>
                <p class="metric-note"><?php echo number_format($bookingStatusCounts['accepted'] ?? 0); ?> already accepted, <?php echo number_format($bookingStatusCounts['declined'] ?? 0); ?> declined.</p>
            </article>
            <article class="dashboard-card">
                <p class="metric-label">Sessions scheduled / ongoing</p>
                <p class="metric-value"><?php echo number_format($scheduledSessions); ?></p>
                <p class="metric-note">Keep an eye on live tutoring engagement.</p>
            </article>
            <article class="dashboard-card">
                <p class="metric-label">Active security incidents</p>
                <p class="metric-value"><?php echo number_format($activeIncidents); ?></p>
                <p class="metric-note"><?php echo number_format($lockedAccounts); ?> accounts flagged for review.</p>
            </article>
        </section>

        <section class="dashboard-card" id="payments-panel">
            <header>
                <h2>Payments & commissions</h2>
                <p class="metric-note">Track settlement statuses and apply quick manual overrides.</p>
            </header>
            <div class="admin-grid">
                <article class="stat-card">
                    <p class="metric-label">Pending payouts</p>
                    <p class="metric-value"><?php echo number_format($paymentStatusCounts['pending'] ?? 0); ?></p>
                    <p class="metric-note">Payments awaiting manual review.</p>
                </article>
                <article class="stat-card">
                    <p class="metric-label">Failed payments</p>
                    <p class="metric-value"><?php echo number_format($paymentStatusCounts['failed'] ?? 0); ?></p>
                    <p class="metric-note">Needs retry or refund.</p>
                </article>
                <article class="stat-card">
                    <p class="metric-label">Commissions due</p>
                    <p class="metric-value"><?php echo number_format($commissionStatusCounts['due'] ?? 0); ?></p>
                    <p class="metric-note">Entries waiting to be marked paid.</p>
                </article>
            </div>
            <div class="admin-grid" style="margin-top: 20px;">
                <article class="dashboard-card">
                    <header>
                        <h3>Recent payments</h3>
                        <p class="metric-note">Override statuses or leave internal notes.</p>
                    </header>
                    <div class="feedback-table-container">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Participants</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Override</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($pendingPaymentsList) === 0): ?>
                                    <tr>
                                        <td colspan="5">No payment activity recorded yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pendingPaymentsList as $payment): ?>
                                        <tr>
                                            <td>
                                                #<?php echo (int) ($payment['id'] ?? 0); ?><br>
                                                <small><?php echo htmlspecialchars(formatDateTime($payment['created_at'] ?? '')); ?></small>
                                            </td>
                                            <td>
                                                Tutor: <?php echo htmlspecialchars($payment['tutor_name'] ?? 'TBD'); ?><br>
                                                Student: <?php echo htmlspecialchars($payment['student_name'] ?? 'TBD'); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($payment['currency'] ?? 'USD'); ?> <?php echo number_format((float) ($payment['amount'] ?? 0), 2); ?><br>
                                                <small>Payout: <?php echo number_format((float) ($payment['tutor_payout'] ?? 0), 2); ?></small>
                                            </td>
                                            <td><span class="status-badge "><?php echo htmlspecialchars(ucfirst($payment['status'] ?? 'pending')); ?></span></td>
                                            <td>
                                                <form method="post" action="admin_actions.php" class="admin-action-form">
                                                    <input type="hidden" name="action" value="payment_override">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int) ($payment['id'] ?? 0); ?>">
                                                    <?php $currentPaymentStatus = $payment['status'] ?? 'pending'; ?>
                                                    <select name="payment_status" required>
                                                        <option value="pending" <?php echo $currentPaymentStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="initiated" <?php echo $currentPaymentStatus === 'initiated' ? 'selected' : ''; ?>>Initiated</option>
                                                        <option value="completed" <?php echo $currentPaymentStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                        <option value="failed" <?php echo $currentPaymentStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                                        <option value="refunded" <?php echo $currentPaymentStatus === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                                    </select>
                                                    <input type="text" name="payment_note" placeholder="Add note (optional)">
                                                    <button type="submit">Apply</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <article class="dashboard-card">
                    <header>
                        <h3>Commission ledger</h3>
                        <p class="metric-note">Adjust earnings and mark payouts on hold or paid.</p>
                    </header>
                    <div class="feedback-table-container">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Entry</th>
                                    <th>Tutor</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Override</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($commissionQueue) === 0): ?>
                                    <tr>
                                        <td colspan="5">Commission entries will appear after sessions are logged.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($commissionQueue as $entry): ?>
                                        <tr>
                                            <td>
                                                #<?php echo (int) ($entry['id'] ?? 0); ?><br>
                                                <small><?php echo htmlspecialchars(formatDateTime($entry['created_at'] ?? '')); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($entry['tutor_name'] ?? 'Tutor'); ?><br>
                                                <small><?php echo htmlspecialchars($entry['tutor_email'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                Rate: <?php echo number_format((float) ($entry['commission_rate'] ?? 0), 2); ?>%<br>
                                                Value: <?php echo number_format((float) ($entry['commission_amount'] ?? 0), 2); ?>
                                            </td>
                                            <td><span class="status-badge "><?php echo htmlspecialchars(ucfirst($entry['status'] ?? 'pending')); ?></span></td>
                                            <td>
                                                <form method="post" action="admin_actions.php" class="admin-action-form">
                                                    <input type="hidden" name="action" value="commission_override">
                                                    <input type="hidden" name="commission_id" value="<?php echo (int) ($entry['id'] ?? 0); ?>">
                                                    <?php $commissionStatus = $entry['status'] ?? 'pending'; ?>
                                                    <select name="commission_status" required>
                                                        <option value="pending" <?php echo $commissionStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="due" <?php echo $commissionStatus === 'due' ? 'selected' : ''; ?>>Due</option>
                                                        <option value="paid" <?php echo $commissionStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                                        <option value="refunded" <?php echo $commissionStatus === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                                    </select>
                                                    <input type="number" step="0.01" name="commission_amount" placeholder="Override amount">
                                                    <input type="text" name="commission_note" placeholder="Note">
                                                    <button type="submit">Apply</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="dashboard-card">
            <header>
                <h2>Latest booking activity</h2>
                <p class="metric-note">Showing the five most recent requests, regardless of outcome.</p>
            </header>
            <div class="feedback-table-container">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Tutor</th>
                            <th>Requested for</th>
                            <th>Status</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recentBookings) === 0): ?>
                            <tr>
                                <td colspan="5">No booking activity yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentBookings as $booking): ?>
                                <tr>
                                    <td><?php echo escape($booking['student_name'] ?? 'Unknown'); ?><br><small><?php echo escape($booking['student_email'] ?? ''); ?></small></td>
                                    <td><?php echo escape($booking['tutor_name'] ?? 'Tutor'); ?></td>
                                    <td><?php echo escape(formatDateTime($booking['requested_for'] ?? '')); ?></td>
                                    <td><span class="<?php echo bookingBadgeClass($booking['status'] ?? 'pending'); ?>"><?php echo escape(ucfirst($booking['status'] ?? 'pending')); ?></span></td>
                                    <td><?php echo escape($booking['reference'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-card">
            <header>
                <h2>Booking control center</h2>
                <p class="metric-note">Cancel requests or send quick notifications without leaving the console.</p>
            </header>
            <div class="feedback-table-container">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Tutor</th>
                            <th>Student</th>
                            <th>Requested for</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bookingQueue) === 0): ?>
                            <tr>
                                <td colspan="6">No booking requests captured yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bookingQueue as $booking): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo escape($booking['reference'] ?? '—'); ?></strong><br>
                                        <small><?php echo escape(formatDateTime($booking['created_at'] ?? '')); ?></small>
                                    </td>
                                    <td>
                                        <?php echo escape($booking['tutor_name'] ?? 'Tutor'); ?><br>
                                        <small><?php echo escape($booking['tutor_email'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo escape($booking['student_name'] ?? ''); ?><br>
                                        <small><?php echo escape($booking['student_email'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo escape(formatDateTime($booking['requested_for'] ?? '')); ?></td>
                                    <td>
                                        <span class="<?php echo bookingBadgeClass($booking['status'] ?? 'pending'); ?>">
                                            <?php echo escape(ucfirst($booking['status'] ?? 'pending')); ?>
                                        </span>
                                        <?php if (!empty($booking['status_changed_by_name'])): ?>
                                            <br><small>by <?php echo escape($booking['status_changed_by_name']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($booking['cancellation_reason'])): ?>
                                            <br><small><?php echo escape($booking['cancellation_reason']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="admin-action-stack">
                                            <form method="post" action="admin_actions.php" class="admin-action-form">
                                                <input type="hidden" name="action" value="booking_update_status">
                                                <input type="hidden" name="booking_id" value="<?php echo (int) ($booking['id'] ?? 0); ?>">
                                                <select name="status" required>
                                                    <option value="">Pick status</option>
                                                    <option value="accepted">Approve</option>
                                                    <option value="declined">Decline</option>
                                                    <option value="pending">Reopen</option>
                                                </select>
                                                <input type="text" name="status_note" placeholder="Optional note">
                                                <button type="submit">Update</button>
                                            </form>
                                            <form method="post" action="admin_actions.php" class="admin-action-form">
                                                <input type="hidden" name="action" value="booking_cancel">
                                                <input type="hidden" name="booking_id" value="<?php echo (int) ($booking['id'] ?? 0); ?>">
                                                <input type="text" name="reason" placeholder="Reason" required>
                                                <button type="submit">Cancel</button>
                                            </form>
                                            <form method="post" action="admin_actions.php" class="admin-action-form">
                                                <input type="hidden" name="action" value="booking_notify">
                                                <input type="hidden" name="booking_id" value="<?php echo (int) ($booking['id'] ?? 0); ?>">
                                                <select name="target" required>
                                                    <option value="tutor">Notify tutor</option>
                                                    <option value="student">Notify student</option>
                                                </select>
                                                <textarea name="message" placeholder="Quick note" required></textarea>
                                                <button type="submit">Notify</button>
                                            </form>
                                            <form method="post" action="admin_actions.php" class="admin-action-form">
                                                <input type="hidden" name="action" value="booking_flag">
                                                <input type="hidden" name="booking_id" value="<?php echo (int) ($booking['id'] ?? 0); ?>">
                                                <select name="severity">
                                                    <option value="low">Low</option>
                                                    <option value="medium" selected>Medium</option>
                                                    <option value="high">High</option>
                                                </select>
                                                <input type="text" name="flag_note" placeholder="Flag this booking" required>
                                                <button type="submit">Flag</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-card" id="all-bookings-panel">
            <header>
                <h2>All bookings (API powered)</h2>
                <p class="metric-note">Live data from the admin API with bulk filters and inline actions.</p>
            </header>
            <div class="all-bookings-controls">
                <input type="search" id="allBookingsSearch" placeholder="Search reference, tutor, student" aria-label="Search bookings">
                <select id="allBookingsStatusFilter" aria-label="Filter by booking status">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="accepted">Accepted</option>
                    <option value="declined">Declined</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <button type="button" class="btn btn-outline" id="allBookingsRefresh">Refresh</button>
            </div>
            <div id="allBookingsStatus" class="metric-note" role="status" aria-live="polite"></div>
            <div class="feedback-table-container">
                <table class="dashboard-table" id="allBookingsTable">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Tutor</th>
                            <th>Student</th>
                            <th>Requested for</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">Loading bookings…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="table-pagination">
                <button type="button" id="allBookingsPrev">Previous</button>
                <span id="allBookingsPageLabel">Page 1</span>
                <button type="button" id="allBookingsNext">Next</button>
            </div>
        </section>

        <section class="admin-grid">
            <article class="dashboard-card">
                <header>
                    <h2>Broadcast announcements</h2>
                    <p class="metric-note">Post updates for admins, tutors, students, or everyone at once.</p>
                </header>
                <form method="post" action="admin_actions.php" class="announcement-form">
                    <input type="hidden" name="action" value="admin_message_post">
                    <input type="text" name="message_subject" placeholder="Subject" required>
                    <textarea name="message_body" placeholder="Share the latest update" required></textarea>
                    <div class="form-row">
                        <select name="message_audience" aria-label="Audience" required>
                            <option value="all_users">All users</option>
                            <option value="students">Students only</option>
                            <option value="tutors">Tutors only</option>
                            <option value="admins">Admin team</option>
                        </select>
                        <select name="message_priority" aria-label="Priority" required>
                            <option value="normal">Normal priority</option>
                            <option value="important">Important</option>
                            <option value="critical">Critical</option>
                        </select>
                        <label class="inline-checkbox">
                            <input type="checkbox" name="message_pinned" value="1">
                            Pin announcement
                        </label>
                        <button type="submit">Post announcement</button>
                    </div>
                </form>
                <h3 class="section-subheading">Recent announcements</h3>
                <ul class="message-board">
                    <?php if (count($adminMessages) === 0): ?>
                        <li class="message-item"><p class="metric-note">No admin announcements posted yet.</p></li>
                    <?php else: ?>
                        <?php foreach ($adminMessages as $message): ?>
                            <?php $isPinned = (int) ($message['pinned'] ?? 0) === 1; ?>
                            <li class="message-item <?php echo $isPinned ? 'message-pinned' : ''; ?>">
                                <?php if ($isPinned): ?><span class="message-pin">Pinned</span><?php endif; ?>
                                <div class="notification-header">
                                    <h3><?php echo escape($message['subject'] ?? 'Announcement'); ?></h3>
                                    <span class="<?php echo priorityBadgeClass($message['priority'] ?? 'normal'); ?>">
                                        <?php echo escape(ucfirst($message['priority'] ?? 'normal')); ?>
                                    </span>
                                </div>
                                <p class="message-meta">
                                    <span class="audience-pill"><?php echo escape(formatAudienceLabel($message['audience'] ?? 'admins')); ?></span>
                                    • <?php echo escape(formatDateTime($message['created_at'] ?? '')); ?>
                                    <?php if (!empty($message['author_name'])): ?> • by <?php echo escape($message['author_name']); ?><?php endif; ?>
                                </p>
                                <p class="message-body"><?php echo escape(summarizeText($message['body'] ?? '', 220)); ?></p>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </article>
            <article class="dashboard-card">
                <header>
                    <h2>Recent platform notifications</h2>
                    <p class="metric-note">A quick look at the latest user-facing alerts.</p>
                </header>
                <ul class="notification-list">
                    <?php if (count($recentPlatformNotifications) === 0): ?>
                        <li class="notification-card">
                            <p class="metric-note">No notifications have been delivered yet.</p>
                        </li>
                    <?php else: ?>
                        <?php foreach ($recentPlatformNotifications as $notification): ?>
                            <li class="notification-card">
                                <div class="notification-header">
                                    <span class="<?php echo notificationLevelBadgeClass($notification['level'] ?? 'info'); ?>">
                                        <?php echo escape(ucfirst($notification['level'] ?? 'info')); ?>
                                    </span>
                                    <span class="notification-meta"><?php echo escape(formatDateTime($notification['created_at'] ?? '')); ?></span>
                                </div>
                                <h3><?php echo escape($notification['title'] ?? 'Notification'); ?></h3>
                                <p><?php echo escape(summarizeText($notification['body'] ?? '', 180)); ?></p>
                                <?php if (!empty($notification['recipient_name'])): ?>
                                    <p class="notification-recipient">Sent to <?php echo escape($notification['recipient_name']); ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </article>
        </section>

        <section class="admin-grid">
            <article class="dashboard-card">
                <header>
                    <h2>Security metrics</h2>
                    <p class="metric-note">Most recent snapshot captured on <?php echo escape(formatDateTime($latestMetrics['created_at'] ?? '')); ?>.</p>
                </header>
                <?php if ($latestMetrics): ?>
                    <ul class="stat-card" style="list-style: none; padding: 0;">
                        <li class="metric-label">Risk score</li>
                        <li class="metric-value"><?php echo escape($latestMetrics['risk_score'] ?? '0'); ?></li>
                    </ul>
                    <div class="stat-card">
                        <p class="metric-label">Active threats</p>
                        <p class="metric-value"><?php echo escape($latestMetrics['active_threats'] ?? '0'); ?></p>
                        <p class="metric-note"><?php echo escape($latestMetrics['resolved_threats'] ?? '0'); ?> threats resolved, <?php echo escape($latestMetrics['incident_count'] ?? '0'); ?> total incidents so far.</p>
                    </div>
                    <div class="stat-card">
                        <p class="metric-label">Signal health</p>
                        <p class="metric-value"><?php echo escape($latestMetrics['compliance_score'] ?? '0'); ?>%</p>
                        <p class="metric-note"><?php echo escape($latestMetrics['vulnerability_count'] ?? '0'); ?> uncovered vulnerabilities, <?php echo escape($latestMetrics['failed_logins'] ?? '0'); ?> failed logins vs <?php echo escape($latestMetrics['successful_logins'] ?? '0'); ?> successful ones.</p>
                    </div>
                <?php else: ?>
                    <p class="metric-note">Metrics are not available yet.</p>
                <?php endif; ?>
            </article>

            <article class="dashboard-card">
                <header>
                    <h2>Security events</h2>
                    <p class="metric-note">The most recent five entries from the security log.</p>
                </header>
                <div class="feedback-table-container">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Description</th>
                                <th>IP</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentSecurityEvents) === 0): ?>
                                <tr>
                                    <td colspan="4">No events have been recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentSecurityEvents as $event): ?>
                                    <tr>
                                        <td><?php echo escape($event['event_type'] ?? 'Event'); ?></td>
                                        <td><?php echo escape($event['description'] ?? '—'); ?></td>
                                        <td><?php echo escape($event['ip_address'] ?? '—'); ?></td>
                                        <td><?php echo escape(formatDateTime($event['created_at'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="dashboard-card">
            <header>
                <h2>Latest contact requests</h2>
                <p class="metric-note">Live submissions from the marketing site contact form.</p>
            </header>
            <div class="feedback-table-container">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Requester</th>
                            <th>Role</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($contactRequests) === 0): ?>
                            <tr>
                                <td colspan="5">No contact submissions yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($contactRequests as $request): ?>
                                <tr>
                                    <td>
                                        <?php echo escape($request['name'] ?? 'Visitor'); ?><br>
                                        <small><?php echo escape($request['email'] ?? '—'); ?></small>
                                    </td>
                                    <td><?php echo escape(ucfirst($request['requester_role'] ?? 'other')); ?></td>
                                    <td><?php echo escape(summarizeText($request['message'] ?? '')); ?></td>
                                    <td><span class="<?php echo contactBadgeClass($request['status'] ?? 'new'); ?>"><?php echo escape(ucwords(str_replace('_', ' ', $request['status'] ?? 'new'))); ?></span></td>
                                    <td><?php echo escape(formatDateTime($request['created_at'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-card">
            <header>
                <h2>Tutor directory (console view)</h2>
                <p class="metric-note">Review tutor availability without navigating to the public site.</p>
            </header>
            <div class="feedback-table-container">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Tutor</th>
                            <th>Status</th>
                            <th>Verification</th>
                            <th>Rate</th>
                            <th>Rating</th>
                            <th>Sessions</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($tutorDirectory) === 0): ?>
                            <tr>
                                <td colspan="7">No tutors found yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tutorDirectory as $tutor): ?>
                                <tr>
                                    <td>
                                        <?php echo escape($tutor['name'] ?? 'Tutor'); ?><br>
                                        <small><?php echo escape($tutor['email'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo escape(ucfirst($tutor['status'] ?? 'active')); ?></td>
                                    <td><?php echo escape(ucfirst($tutor['verification_status'] ?? 'pending')); ?></td>
                                    <td><?php echo escape(number_format((float) ($tutor['hourly_rate'] ?? 0), 2)); ?></td>
                                    <td><?php echo escape(number_format((float) ($tutor['rating'] ?? 0), 1)); ?></td>
                                    <td><?php echo escape(number_format((int) ($tutor['total_sessions'] ?? 0))); ?></td>
                                    <td><?php echo escape(formatDateTime($tutor['updated_at'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-card">
            <header>
                <h2>Manage users</h2>
                <p class="metric-note">Issue warnings, pause accounts, or re-activate users directly from the console.</p>
            </header>
            <div class="feedback-table-container">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Warnings</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($managedUsers) === 0): ?>
                            <tr>
                                <td colspan="6">No user accounts found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($managedUsers as $user): ?>
                                <?php
                                    $userId = (int) ($user['id'] ?? 0);
                                    $isSelf = $userId === $currentAdminId;
                                    $isSuspended = ($user['status'] ?? '') === 'suspended';
                                ?>
                                <tr>
                                    <td>
                                        <?php echo escape($user['name'] ?? 'User'); ?><br>
                                        <small><?php echo escape($user['email'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo escape(ucfirst($user['role'] ?? '')); ?></td>
                                    <td>
                                        <span class="<?php echo userStatusBadgeClass($user['status'] ?? 'active'); ?>"><?php echo escape(ucfirst($user['status'] ?? 'active')); ?></span>
                                        <?php if (!empty($user['status_reason'])): ?>
                                            <br><small><?php echo escape($user['status_reason']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($user['suspended_until'])): ?>
                                            <br><small>Until <?php echo escape(formatDateTime($user['suspended_until'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo (int) ($user['warnings_count'] ?? 0); ?></strong>
                                        <?php if (!empty($user['last_warning_at'])): ?>
                                            <br><small>Last: <?php echo escape(formatDateTime($user['last_warning_at'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $notes = $user['policy_notes'] ?? '';
                                            echo $notes !== '' ? escape(summarizeText($notes, 90)) : '<span class="table-note">No policy notes</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($isSelf): ?>
                                            <small>Actions disabled for your account.</small>
                                        <?php else: ?>
                                            <div class="admin-action-stack">
                                                <form method="post" action="admin_actions.php" class="admin-action-form">
                                                    <input type="hidden" name="action" value="user_warn">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $userId; ?>">
                                                    <input type="text" name="warning_note" placeholder="Warning note" required>
                                                    <button type="submit">Warn</button>
                                                </form>
                                                <?php if ($isSuspended): ?>
                                                    <form method="post" action="admin_actions.php" class="admin-action-form">
                                                        <input type="hidden" name="action" value="user_restore">
                                                        <input type="hidden" name="target_user_id" value="<?php echo $userId; ?>">
                                                        <button type="submit">Restore</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" action="admin_actions.php" class="admin-action-form">
                                                        <input type="hidden" name="action" value="user_suspend">
                                                        <input type="hidden" name="target_user_id" value="<?php echo $userId; ?>">
                                                        <select name="suspend_days">
                                                            <option value="0">Indefinite</option>
                                                            <option value="3">3 days</option>
                                                            <option value="7">7 days</option>
                                                            <option value="30">30 days</option>
                                                        </select>
                                                        <input type="text" name="suspend_reason" placeholder="Reason" required>
                                                        <button type="submit">Suspend</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" action="admin_actions.php" class="admin-action-form">
                                                    <input type="hidden" name="action" value="user_force_logout">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $userId; ?>">
                                                    <input type="text" name="force_reason" placeholder="Logout note (optional)">
                                                    <button type="submit">Force logout</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script>
        (function () {
            const apiUrl = '../api/admin/bookings.php';
            const tableBody = document.querySelector('#allBookingsTable tbody');
            if (!tableBody) {
                return;
            }

            const statusBox = document.getElementById('allBookingsStatus');
            const searchInput = document.getElementById('allBookingsSearch');
            const statusSelect = document.getElementById('allBookingsStatusFilter');
            const refreshBtn = document.getElementById('allBookingsRefresh');
            const prevBtn = document.getElementById('allBookingsPrev');
            const nextBtn = document.getElementById('allBookingsNext');
            const pageLabel = document.getElementById('allBookingsPageLabel');

            const state = { page: 1, totalPages: 1, loading: false, perPage: 10 };

            function escapeHtml(value) {
                if (value === null || value === undefined) {
                    return '';
                }
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function setStatus(message, isError) {
                if (!statusBox) {
                    return;
                }
                statusBox.textContent = message || '';
                statusBox.style.color = isError ? '#7f1f19' : 'rgba(77, 20, 23, 0.65)';
            }

            function updatePagination() {
                if (!pageLabel) {
                    return;
                }
                pageLabel.textContent = 'Page ' + state.page + ' / ' + state.totalPages;
                if (prevBtn) {
                    prevBtn.disabled = state.page <= 1 || state.loading;
                }
                if (nextBtn) {
                    nextBtn.disabled = state.page >= state.totalPages || state.loading;
                }
            }

            function renderRows(rows) {
                if (!rows || rows.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="6">No bookings match your filters.</td></tr>';
                    return;
                }

                const rowsHtml = rows.map(function (row) {
                    const reference = escapeHtml(row.reference || '#');
                    const tutor = escapeHtml(row.tutor_name || 'Unassigned');
                    const tutorEmail = escapeHtml(row.tutor_email || '');
                    const student = escapeHtml(row.student_name || '');
                    const studentEmail = escapeHtml(row.student_email || '');
                    const requestedFor = escapeHtml(row.requested_for || '');
                    const status = escapeHtml(row.status || 'pending');

                    return (
                        '<tr>' +
                        '<td><strong>' + reference + '</strong><br><small>' + escapeHtml(row.created_at || '') + '</small></td>' +
                        '<td>' + tutor + (tutorEmail ? '<br><small>' + tutorEmail + '</small>' : '') + '</td>' +
                        '<td>' + (student || 'N/A') + (studentEmail ? '<br><small>' + studentEmail + '</small>' : '') + '</td>' +
                        '<td>' + requestedFor + '</td>' +
                        '<td>' + (status.charAt(0).toUpperCase() + status.slice(1)) + '</td>' +
                        '<td>' +
                        '<div class="booking-grid-actions">' +
                        '<button type="button" class="btn btn-sm booking-cancel-btn" data-id="' + row.id + '" data-reference="' + reference + '">Cancel</button>' +
                        '<button type="button" class="btn btn-sm btn-outline booking-notify-btn" data-id="' + row.id + '" data-target="tutor">Notify tutor</button>' +
                        '<button type="button" class="btn btn-sm btn-outline booking-notify-btn" data-id="' + row.id + '" data-target="student">Notify student</button>' +
                        '</div>' +
                        '</td>' +
                        '</tr>'
                    );
                }).join('');

                tableBody.innerHTML = rowsHtml;
            }

            function buildQueryParams(page) {
                const params = new URLSearchParams();
                params.set('page', page);
                params.set('per_page', state.perPage);
                const statusValue = statusSelect ? statusSelect.value : '';
                if (statusValue) {
                    params.set('status', statusValue);
                }
                const searchValue = searchInput ? searchInput.value.trim() : '';
                if (searchValue) {
                    params.set('search', searchValue);
                }
                return params;
            }

            function fetchBookings(page) {
                if (state.loading) {
                    return;
                }

                state.loading = true;
                if (typeof page === 'number') {
                    state.page = page;
                }
                setStatus('Loading bookings…');

                const params = buildQueryParams(state.page);

                fetch(apiUrl + '?' + params.toString(), { credentials: 'same-origin' })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Unable to load bookings.');
                        }
                        return response.json();
                    })
                    .then(function (payload) {
                        renderRows(payload.data || []);
                        const pagination = payload.pagination || {};
                        state.totalPages = pagination.totalPages || 1;
                        state.page = pagination.page || state.page;
                        updatePagination();
                        if (!payload.data || payload.data.length === 0) {
                            setStatus('No bookings found for the current filter.');
                        } else {
                            setStatus('');
                        }
                    })
                    .catch(function (error) {
                        console.error(error);
                        setStatus(error.message || 'Unable to load bookings.', true);
                    })
                    .finally(function () {
                        state.loading = false;
                        updatePagination();
                    });
            }

            function postBookingAction(body, loadingMessage) {
                setStatus(loadingMessage || 'Submitting…');
                return fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok || data.success === false) {
                                const message = (data && data.message) ? data.message : 'Request failed.';
                                throw new Error(message);
                            }
                            return data;
                        });
                    })
                    .then(function (data) {
                        setStatus(data.message || 'Action completed.');
                        fetchBookings(state.page);
                        return data;
                    })
                    .catch(function (error) {
                        console.error(error);
                        setStatus(error.message || 'Action failed.', true);
                        throw error;
                    });
            }

            if (refreshBtn) {
                refreshBtn.addEventListener('click', function () {
                    fetchBookings(1);
                });
            }

            if (statusSelect) {
                statusSelect.addEventListener('change', function () {
                    fetchBookings(1);
                });
            }

            if (searchInput) {
                searchInput.addEventListener('keypress', function (event) {
                    if (event.key === 'Enter') {
                        fetchBookings(1);
                    }
                });
            }

            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    if (state.page > 1) {
                        fetchBookings(state.page - 1);
                    }
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    if (state.page < state.totalPages) {
                        fetchBookings(state.page + 1);
                    }
                });
            }

            document.addEventListener('click', function (event) {
                const cancelBtn = event.target.closest('.booking-cancel-btn');
                if (cancelBtn) {
                    const bookingId = parseInt(cancelBtn.getAttribute('data-id'), 10);
                    const reference = cancelBtn.getAttribute('data-reference') || '#';
                    const reason = window.prompt('Provide a cancellation reason for booking ' + reference + ':');
                    if (!reason) {
                        return;
                    }
                    postBookingAction({ action: 'booking_cancel', booking_id: bookingId, reason: reason.trim() }, 'Cancelling booking…');
                    return;
                }

                const notifyBtn = event.target.closest('.booking-notify-btn');
                if (notifyBtn) {
                    const bookingId = parseInt(notifyBtn.getAttribute('data-id'), 10);
                    const target = notifyBtn.getAttribute('data-target') || 'tutor';
                    const promptLabel = target === 'student' ? 'Message to student:' : 'Message to tutor:';
                    const message = window.prompt(promptLabel);
                    if (!message) {
                        return;
                    }
                    postBookingAction({ action: 'booking_notify', booking_id: bookingId, target: target, message: message.trim() }, 'Sending notification…');
                }
            });

            fetchBookings(state.page);
        })();
    </script>
</body>
</html>
