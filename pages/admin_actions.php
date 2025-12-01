<?php
if (!defined('ADMIN_ACTIONS_EMBEDDED')) {
    session_start();

    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: sign-in.html');
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: admin.php');
        exit();
    }

    require_once __DIR__ . '/../connect.php';

    $action = $_POST['action'] ?? '';
    $adminId = (int) $_SESSION['user_id'];

    switch ($action) {
        case 'booking_cancel':
            handleBookingCancel($conn, $adminId);
            break;
        case 'booking_notify':
            handleBookingNotify($conn, $adminId);
            break;
        case 'booking_update_status':
            handleBookingUpdateStatus($conn, $adminId);
            break;
        case 'booking_flag':
            handleBookingFlag($conn, $adminId);
            break;
        case 'user_warn':
            handleUserWarn($conn, $adminId);
            break;
        case 'user_suspend':
            handleUserSuspend($conn, $adminId);
            break;
        case 'user_restore':
            handleUserRestore($conn, $adminId);
            break;
        case 'user_force_logout':
            handleUserForceLogout($conn, $adminId);
            break;
        case 'admin_message_post':
            handleAdminMessagePost($conn, $adminId);
            break;
        case 'payment_override':
            handlePaymentOverride($conn, $adminId);
            break;
        case 'commission_override':
            handleCommissionOverride($conn, $adminId);
            break;
        default:
            setFlash('error', 'Unsupported admin action.');
            break;
    }

    $conn->close();
    header('Location: admin.php');
    exit();
}

function handleBookingCancel(mysqli $conn, int $adminId): void
{
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT) ?: 0;
    $reason = trim((string) ($_POST['reason'] ?? ''));

    $result = performBookingCancel($conn, $adminId, $bookingId, $reason);
    setFlash($result['success'] ? 'success' : 'error', $result['message']);
}

function handleBookingNotify(mysqli $conn, int $adminId): void
{
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT) ?: 0;
    $target = $_POST['target'] ?? 'tutor';
    $message = trim((string) ($_POST['message'] ?? ''));

    $result = performBookingNotify($conn, $adminId, $bookingId, $target, $message);
    setFlash($result['success'] ? 'success' : 'error', $result['message']);
}

function handleBookingUpdateStatus(mysqli $conn, int $adminId): void
{
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT) ?: 0;
    $status = $_POST['status'] ?? '';
    $note = trim((string) ($_POST['status_note'] ?? ''));

    $result = performBookingUpdateStatus($conn, $adminId, $bookingId, $status, $note);
    setFlash($result['success'] ? 'success' : 'error', $result['message']);
}

function handleBookingFlag(mysqli $conn, int $adminId): void
{
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT) ?: 0;
    $severity = $_POST['severity'] ?? 'medium';
    $note = trim((string) ($_POST['flag_note'] ?? ''));

    $result = performBookingFlag($conn, $adminId, $bookingId, $severity, $note);
    setFlash($result['success'] ? 'success' : 'error', $result['message']);
}

function fetchBooking(mysqli $conn, int $bookingId): ?array
{
    $stmt = $conn->prepare('SELECT br.*, u.name AS tutor_name, u.email AS tutor_email FROM booking_requests br LEFT JOIN users u ON u.id = br.tutor_id WHERE br.id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $bookingId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function lookupUserIdByEmail(mysqli $conn, string $email): ?int
{
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $email);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $stmt->bind_result($userId);
    $stmt->fetch();
    $stmt->close();

    return $userId ? (int) $userId : null;
}

function fetchPaymentRecord(mysqli $conn, int $paymentId): ?array
{
    $stmt = $conn->prepare('SELECT p.*, ts.tutor_id, ts.student_id FROM payments p LEFT JOIN tutoring_sessions ts ON ts.id = p.session_id WHERE p.id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $paymentId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function fetchCommissionRecord(mysqli $conn, int $commissionId): ?array
{
    $stmt = $conn->prepare('SELECT id, session_id, tutor_id, booking_id, commission_amount, status FROM commission_ledger WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $commissionId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function notifyUser(
    mysqli $conn,
    int $userId,
    int $adminId,
    string $title,
    string $message,
    array $payload = [],
    ?string $actionUrl = null
): bool
{
    $stmt = $conn->prepare('INSERT INTO notifications (user_id, type, title, message, initiator_id, action_url, data) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }

    $type = 'admin_notice';
    $link = $actionUrl ?: '/pages/dashboard.php#notifications';
    $data = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmt->bind_param('isssiss', $userId, $type, $title, $message, $adminId, $link, $data);
    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
        recordUserNotification($conn, $userId, $adminId, $title, $message, 'info', $payload);
    }

    return $success;
}

function buildNotificationActionUrl(string $target, int $bookingId, ?string $reference): string
{
    $base = $target === 'tutor' ? '/pages/tutorProfile.php' : '/pages/dashboard.php';
    $params = ['booking' => $bookingId];

    if ($reference) {
        $params['reference'] = $reference;
    }

    return $base . '?' . http_build_query($params);
}

function handleAdminMessagePost(mysqli $conn, int $adminId): void
{
    $subject = trim((string) ($_POST['message_subject'] ?? ''));
    $body = trim((string) ($_POST['message_body'] ?? ''));
    $audience = $_POST['message_audience'] ?? 'admins';
    $priority = $_POST['message_priority'] ?? 'normal';
    $pinned = isset($_POST['message_pinned']) ? 1 : 0;

    $allowedAudience = ['admins', 'tutors', 'students', 'all_users'];
    $allowedPriority = ['normal', 'important', 'critical'];

    if ($subject === '' || $body === '') {
        setFlash('error', 'Subject and message body are required.');
        return;
    }

    if (!in_array($audience, $allowedAudience, true) || !in_array($priority, $allowedPriority, true)) {
        setFlash('error', 'Invalid announcement parameters.');
        return;
    }

    $stmt = $conn->prepare('INSERT INTO admin_messages (author_id, audience, subject, body, priority, pinned) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        setFlash('error', 'Unable to post announcement.');
        return;
    }

    $stmt->bind_param('issssi', $adminId, $audience, $subject, $body, $priority, $pinned);
    if (!$stmt->execute()) {
        $stmt->close();
        setFlash('error', 'Failed to save announcement.');
        return;
    }
    $stmt->close();

    $notifLevel = priorityToNotificationLevel($priority);
    if ($audience !== 'admins') {
        broadcastAudienceNotification($conn, $audience, $subject, $body, $notifLevel, $adminId);
    }

    setFlash('success', 'Announcement posted successfully.');
}

function performBookingCancel(mysqli $conn, int $adminId, int $bookingId, string $reason): array
{
    if ($bookingId <= 0) {
        return ['success' => false, 'message' => 'Invalid booking reference.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return ['success' => false, 'message' => 'Please provide a cancellation reason.'];
    }

    $booking = fetchBooking($conn, $bookingId);
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found.'];
    }

    $stmt = $conn->prepare('UPDATE booking_requests SET status = ?, cancellation_reason = ?, cancelled_by_admin = ?, status_changed_by = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to update booking status.'];
    }

    $status = 'cancelled';
    $stmt->bind_param('ssiii', $status, $reason, $adminId, $adminId, $bookingId);

    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to cancel the booking.'];
    }

    $stmt->close();

    logAdminAction($conn, $adminId, 'booking_cancel', (int) ($booking['student_id'] ?? 0) ?: null, $bookingId, null, $reason, [
        'reference' => $booking['reference'] ?? null,
    ]);

    return [
        'success' => true,
        'message' => 'Booking ' . ($booking['reference'] ?? '#') . ' cancelled successfully.',
        'data' => [
            'booking_id' => $bookingId,
            'status' => 'cancelled',
        ],
    ];
}

function performBookingNotify(mysqli $conn, int $adminId, int $bookingId, string $target, string $message): array
{
    if ($bookingId <= 0) {
        return ['success' => false, 'message' => 'Invalid booking reference.'];
    }

    $target = in_array($target, ['tutor', 'student'], true) ? $target : 'tutor';
    $message = trim($message);
    if ($message === '') {
        return ['success' => false, 'message' => 'Please include a short message.'];
    }

    $booking = fetchBooking($conn, $bookingId);
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found.'];
    }

    $userId = null;
    $title = 'Booking update';
    $payload = [
        'booking_reference' => $booking['reference'] ?? null,
        'target' => $target,
    ];
    $actionUrl = buildNotificationActionUrl($target, $bookingId, $booking['reference'] ?? null);

    if ($target === 'tutor') {
        $userId = isset($booking['tutor_id']) ? (int) $booking['tutor_id'] : null;
    } else {
        if (!empty($booking['student_id'])) {
            $userId = (int) $booking['student_id'];
        } elseif (!empty($booking['student_email'])) {
            $userId = lookupUserIdByEmail($conn, $booking['student_email']);
        }
        $title = 'Student booking notice';
    }

    if (!$userId) {
        return ['success' => false, 'message' => 'Unable to determine a user account to notify.'];
    }

    if (!notifyUser($conn, $userId, $adminId, $title, $message, $payload, $actionUrl)) {
        return ['success' => false, 'message' => 'Notification could not be sent.'];
    }

    $column = $target === 'tutor' ? 'notified_tutor_at' : 'notified_student_at';
    $sql = "UPDATE booking_requests SET {$column} = NOW(), updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Notification recorded but booking timeline could not be updated.'];
    }

    $stmt->bind_param('i', $bookingId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Notification recorded but booking timeline could not be updated.'];
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'booking_notify', $userId, $bookingId, null, $message, $payload);

    return [
        'success' => true,
        'message' => 'Notification queued successfully.',
        'data' => [
            'booking_id' => $bookingId,
            'target' => $target,
        ],
    ];
}

function performBookingUpdateStatus(mysqli $conn, int $adminId, int $bookingId, string $status, string $note = ''): array
{
    if ($bookingId <= 0) {
        return ['success' => false, 'message' => 'Invalid booking reference.'];
    }

    $status = strtolower(trim($status));
    $allowed = ['pending', 'accepted', 'declined', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        return ['success' => false, 'message' => 'Unsupported booking status.'];
    }

    $booking = fetchBooking($conn, $bookingId);
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found.'];
    }

    if ($booking['status'] === $status) {
        return ['success' => false, 'message' => 'Booking already has this status.'];
    }

    $noteEntry = $note !== '' ? appendBookingNote($booking['admin_notes'] ?? '', $note, $adminId) : ($booking['admin_notes'] ?? null);
    $cancellationReason = $status === 'cancelled' ? ($note ?: ($booking['cancellation_reason'] ?? null)) : ($booking['cancellation_reason'] ?? null);

    $stmt = $conn->prepare('UPDATE booking_requests SET status = ?, status_changed_by = ?, admin_notes = ?, cancellation_reason = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to update booking status.'];
    }

    $stmt->bind_param('sissi', $status, $adminId, $noteEntry, $cancellationReason, $bookingId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to apply status update.'];
    }
    $stmt->close();

    $actionType = match ($status) {
        'accepted' => 'booking_accept',
        'declined' => 'booking_decline',
        'cancelled' => 'booking_cancel',
        default => 'booking_update',
    };

    logAdminAction($conn, $adminId, $actionType, (int) ($booking['student_id'] ?? 0) ?: null, $bookingId, null, $note, ['status' => $status]);

    if (!empty($booking['student_id'])) {
        $level = $status === 'accepted' ? 'success' : ($status === 'pending' ? 'info' : 'warning');
        $title = 'Booking update';
        $message = sprintf('Booking %s is now %s.', $booking['reference'] ?? '#', $status);
        recordUserNotification($conn, (int) $booking['student_id'], $adminId, $title, $message, $level, [
            'booking_reference' => $booking['reference'] ?? null,
            'status' => $status,
        ]);
    }

    return [
        'success' => true,
        'message' => sprintf('Booking %s marked as %s.', $booking['reference'] ?? '#', $status),
        'data' => [
            'booking_id' => $bookingId,
            'status' => $status,
        ],
    ];
}

function performBookingFlag(mysqli $conn, int $adminId, int $bookingId, string $severity, string $note): array
{
    if ($bookingId <= 0) {
        return ['success' => false, 'message' => 'Invalid booking reference.'];
    }

    $severity = in_array($severity, ['low', 'medium', 'high'], true) ? $severity : 'medium';
    $note = trim($note);
    if ($note === '') {
        return ['success' => false, 'message' => 'Please explain why this booking is being flagged.'];
    }

    $booking = fetchBooking($conn, $bookingId);
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found.'];
    }

    $prefixedNote = sprintf('[%s] %s', strtoupper($severity), $note);
    $combinedNotes = appendBookingNote($booking['admin_notes'] ?? '', $prefixedNote, $adminId);

    $stmt = $conn->prepare('UPDATE booking_requests SET admin_notes = ?, status_changed_by = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to flag booking.'];
    }

    $stmt->bind_param('sii', $combinedNotes, $adminId, $bookingId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to flag booking.'];
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'booking_flag', (int) ($booking['student_id'] ?? 0) ?: null, $bookingId, null, $prefixedNote, ['severity' => $severity]);

    return [
        'success' => true,
        'message' => 'Booking note recorded and flagged for review.',
        'data' => [
            'booking_id' => $bookingId,
            'severity' => $severity,
        ],
    ];
}

function handlePaymentOverride(mysqli $conn, int $adminId): void
{
    $paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT) ?: 0;
    $status = $_POST['payment_status'] ?? '';
    $note = trim((string) ($_POST['payment_note'] ?? ''));

    $result = performPaymentOverride($conn, $adminId, $paymentId, $status, $note);
    setFlash($result['success'] ? 'success' : 'error', $result['message']);
}

function handleCommissionOverride(mysqli $conn, int $adminId): void
{
    $commissionId = filter_input(INPUT_POST, 'commission_id', FILTER_VALIDATE_INT) ?: 0;
    $status = $_POST['commission_status'] ?? '';
    $amount = isset($_POST['commission_amount']) ? trim((string) $_POST['commission_amount']) : null;
    $note = trim((string) ($_POST['commission_note'] ?? ''));

    $result = performCommissionOverride($conn, $adminId, $commissionId, $status, $amount, $note);
    setFlash($result['success'] ? 'success' : 'error', $result['message']);
}

function performPaymentOverride(mysqli $conn, int $adminId, int $paymentId, string $status, string $note = ''): array
{
    if ($paymentId <= 0) {
        return ['success' => false, 'message' => 'Missing payment reference.'];
    }

    $status = strtolower(trim($status));
    $allowed = ['pending', 'initiated', 'completed', 'failed', 'refunded'];
    if (!in_array($status, $allowed, true)) {
        return ['success' => false, 'message' => 'Unsupported payment status.'];
    }

    $payment = fetchPaymentRecord($conn, $paymentId);
    if (!$payment) {
        return ['success' => false, 'message' => 'Payment not found.'];
    }

    $refundReason = $status === 'refunded' ? ($note ?: ($payment['refund_reason'] ?? null)) : ($payment['refund_reason'] ?? null);
    $errorMessage = $status === 'failed' ? ($note ?: ($payment['error_message'] ?? null)) : ($payment['error_message'] ?? null);

    $stmt = $conn->prepare('UPDATE payments SET status = ?, refund_reason = ?, error_message = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to override payment status.'];
    }

    $stmt->bind_param('sssi', $status, $refundReason, $errorMessage, $paymentId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to override payment.'];
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'payment_review', null, null, (int) ($payment['session_id'] ?? 0) ?: null, $note, [
        'payment_id' => $paymentId,
        'status' => $status,
    ]);

    notifyPaymentParties($conn, $payment, $adminId, $status, $note);

    return [
        'success' => true,
        'message' => 'Payment status updated to ' . ucfirst($status) . '.',
    ];
}

function performCommissionOverride(
    mysqli $conn,
    int $adminId,
    int $commissionId,
    string $status,
    ?string $amount,
    string $note
): array {
    if ($commissionId <= 0) {
        return ['success' => false, 'message' => 'Missing commission reference.'];
    }

    $status = strtolower(trim($status));
    $allowed = ['pending', 'due', 'paid', 'refunded'];
    if (!in_array($status, $allowed, true)) {
        return ['success' => false, 'message' => 'Unsupported commission status.'];
    }

    $commission = fetchCommissionRecord($conn, $commissionId);
    if (!$commission) {
        return ['success' => false, 'message' => 'Commission entry not found.'];
    }

    $amountValue = $amount !== null && $amount !== '' ? (float) $amount : (float) ($commission['commission_amount'] ?? 0);

    $stmt = $conn->prepare('UPDATE commission_ledger SET status = ?, commission_amount = ?, noted_by = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to update commission entry.'];
    }

    $stmt->bind_param('sdii', $status, $amountValue, $adminId, $commissionId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to update commission entry.'];
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'commission_update', (int) ($commission['tutor_id'] ?? 0) ?: null, $commission['booking_id'] ?? null, $commission['session_id'] ?? null, $note, [
        'commission_id' => $commissionId,
        'status' => $status,
        'amount' => $amountValue,
    ]);

    if (!empty($commission['tutor_id'])) {
        $title = 'Commission update';
        $body = sprintf('Commission ledger entry #%d is now %s.', $commissionId, $status);
        recordUserNotification($conn, (int) $commission['tutor_id'], $adminId, $title, $body, 'info', [
            'commission_id' => $commissionId,
            'amount' => $amountValue,
            'status' => $status,
        ]);
    }

    return [
        'success' => true,
        'message' => 'Commission entry updated.',
    ];
}

function handleUserWarn(mysqli $conn, int $adminId): void
{
    $userId = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    $note = trim((string) ($_POST['warning_note'] ?? ''));

    if (!$userId || $note === '') {
        setFlash('error', 'Provide a user and a warning note.');
        return;
    }

    $user = fetchUserRecord($conn, $userId);
    if (!$user) {
        setFlash('error', 'User not found.');
        return;
    }

    if ($user['id'] === $adminId) {
        setFlash('error', 'You cannot issue a warning to yourself.');
        return;
    }

    $policyNotes = buildPolicyNote($note, $user['policy_notes'] ?? null);
    $stmt = $conn->prepare('UPDATE users SET warnings_count = warnings_count + 1, last_warning_at = NOW(), status = IF(status <> "suspended", "warned", status), status_reason = ?, policy_notes = ? WHERE id = ?');
    if (!$stmt) {
        setFlash('error', 'Unable to record warning.');
        return;
    }

    $stmt->bind_param('ssi', $note, $policyNotes, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        setFlash('error', 'Failed to update user record.');
        return;
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'user_warn', $userId, null, null, $note, ['reason' => 'policy_warning']);
    notifyUser($conn, $userId, $adminId, 'Account warning issued', $note, ['level' => 'warning']);
    setFlash('success', 'Warning sent to ' . ($user['name'] ?? 'user') . '.');
}

function handleUserSuspend(mysqli $conn, int $adminId): void
{
    $userId = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    $reason = trim((string) ($_POST['suspend_reason'] ?? ''));
    $days = filter_input(INPUT_POST, 'suspend_days', FILTER_VALIDATE_INT);

    if (!$userId || $reason === '') {
        setFlash('error', 'Suspensions require a target user and reason.');
        return;
    }

    $user = fetchUserRecord($conn, $userId);
    if (!$user) {
        setFlash('error', 'User not found.');
        return;
    }

    if ($user['id'] === $adminId) {
        setFlash('error', 'You cannot suspend yourself.');
        return;
    }

    $until = null;
    if ($days && $days > 0) {
        $untilDate = new DateTimeImmutable('now');
        $until = $untilDate->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }

    $policyNotes = buildPolicyNote('Suspended: ' . $reason, $user['policy_notes'] ?? null);
    $stmt = $conn->prepare('UPDATE users SET status = "suspended", status_reason = ?, suspended_until = ?, policy_notes = ?, force_password_change = 1 WHERE id = ?');
    if (!$stmt) {
        setFlash('error', 'Unable to suspend this user.');
        return;
    }

    $stmt->bind_param('sssi', $reason, $until, $policyNotes, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        setFlash('error', 'Failed to update user status.');
        return;
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'user_suspend', $userId, null, null, $reason, ['suspended_until' => $until]);
    notifyUser($conn, $userId, $adminId, 'Account suspended', $reason, ['suspended_until' => $until]);
    setFlash('success', ($user['name'] ?? 'User') . ' suspended.');
}

function handleUserRestore(mysqli $conn, int $adminId): void
{
    $userId = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    if (!$userId) {
        setFlash('error', 'Missing user reference.');
        return;
    }

    $user = fetchUserRecord($conn, $userId);
    if (!$user) {
        setFlash('error', 'User not found.');
        return;
    }

    $stmt = $conn->prepare('UPDATE users SET status = "active", status_reason = NULL, suspended_until = NULL WHERE id = ?');
    if (!$stmt) {
        setFlash('error', 'Unable to restore this account.');
        return;
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        setFlash('error', 'Failed to restore account.');
        return;
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'user_restore', $userId, null, null, 'Account restored', []);
    notifyUser($conn, $userId, $adminId, 'Account restored', 'Your account has been reactivated.', ['restored' => true]);
    setFlash('success', ($user['name'] ?? 'User') . ' restored.');
}

function handleUserForceLogout(mysqli $conn, int $adminId): void
{
    $userId = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    $note = trim((string) ($_POST['force_reason'] ?? '')); // Optional note for auditing

    if (!$userId) {
        setFlash('error', 'Missing user reference.');
        return;
    }

    $user = fetchUserRecord($conn, $userId);
    if (!$user) {
        setFlash('error', 'User not found.');
        return;
    }

    if ($user['id'] === $adminId) {
        setFlash('error', 'You cannot force logout your own account.');
        return;
    }

    $stmt = $conn->prepare('UPDATE users SET force_password_change = 1, password_changed_at = NULL WHERE id = ?');
    if (!$stmt) {
        setFlash('error', 'Unable to flag account for logout.');
        return;
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        setFlash('error', 'Failed to flag account.');
        return;
    }
    $stmt->close();

    logAdminAction($conn, $adminId, 'user_force_logout', $userId, null, null, $note, []);
    $message = $note !== '' ? $note : 'You have been signed out on all devices. Please reset your password.';
    notifyUser($conn, $userId, $adminId, 'Security action required', $message, ['force_password_change' => true]);
    setFlash('success', 'Forced logout queued for ' . ($user['name'] ?? 'user') . '.');
}

function fetchUserRecord(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT id, name, email, role, status, policy_notes FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $user ?: null;
}

function buildPolicyNote(string $note, ?string $current): string
{
    $stamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
    $entry = '[' . $stamp . '] ' . $note;

    if (!$current || trim($current) === '') {
        return $entry;
    }

    return $current . PHP_EOL . $entry;
}

function appendBookingNote(?string $currentNotes, string $note, int $adminId): string
{
    $stamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
    $entry = sprintf('[%s • admin #%d] %s', $stamp, $adminId, $note);

    if ($currentNotes === null || trim($currentNotes) === '') {
        return $entry;
    }

    return trim($currentNotes) . PHP_EOL . $entry;
}

function broadcastAudienceNotification(mysqli $conn, string $audience, string $title, string $body, string $level, int $adminId): void
{
    $roleCondition = match ($audience) {
        'tutors' => "role = 'tutor'",
        'students' => "role = 'student'",
        'all_users' => "role IN ('tutor', 'student')",
        default => null,
    };

    if ($roleCondition === null) {
        return;
    }

    $sql = "INSERT INTO user_notifications (user_id, source, title, body, level, created_by)
            SELECT id, 'admin', ?, ?, ?, ? FROM users WHERE {$roleCondition}";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('sssi', $title, $body, $level, $adminId);
    $stmt->execute();
    $stmt->close();
}

function priorityToNotificationLevel(string $priority): string
{
    return match ($priority) {
        'important' => 'warning',
        'critical' => 'danger',
        default => 'info',
    };
}

function recordUserNotification(
    mysqli $conn,
    int $userId,
    ?int $adminId,
    string $title,
    string $body,
    string $level = 'info',
    array $payload = []
): void {
    $source = $adminId ? 'admin' : 'system';
    $data = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    if ($adminId) {
        $stmt = $conn->prepare('INSERT INTO user_notifications (user_id, source, title, body, level, data, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssssi', $userId, $source, $title, $body, $level, $data, $adminId);
    } else {
        $stmt = $conn->prepare('INSERT INTO user_notifications (user_id, source, title, body, level, data) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssss', $userId, $source, $title, $body, $level, $data);
    }

    $stmt->execute();
    $stmt->close();
}

function notifyPaymentParties(mysqli $conn, array $payment, int $adminId, string $status, string $note = ''): void
{
    $title = 'Payment status updated';
    $body = sprintf('Payment #%d is now %s.', (int) ($payment['id'] ?? 0), $status);
    if ($note !== '') {
        $body .= ' ' . $note;
    }

    if (!empty($payment['student_id'])) {
        recordUserNotification($conn, (int) $payment['student_id'], $adminId, $title, $body, 'info', [
            'payment_id' => $payment['id'] ?? null,
            'status' => $status,
        ]);
    }

    if (!empty($payment['tutor_id'])) {
        recordUserNotification($conn, (int) $payment['tutor_id'], $adminId, $title, $body, 'info', [
            'payment_id' => $payment['id'] ?? null,
            'status' => $status,
        ]);
    }
}

function logAdminAction(
    mysqli $conn,
    int $adminId,
    string $actionType,
    ?int $targetUserId,
    ?int $bookingId,
    ?int $sessionId,
    string $notes = '',
    array $metadata = []
): void {
    $target = $targetUserId ?: null;
    $booking = $bookingId ?: null;
    $session = $sessionId ?: null;
    $notesValue = $notes !== '' ? $notes : null;
    $meta = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $stmt = $conn->prepare('INSERT INTO admin_actions (admin_id, action_type, target_user_id, booking_id, session_id, notes, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('isiiiss', $adminId, $actionType, $target, $booking, $session, $notesValue, $meta);
    $stmt->execute();
    $stmt->close();
}

function setFlash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}
