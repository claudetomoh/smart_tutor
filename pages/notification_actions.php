<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json', true, 401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/../connect.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$redirectTarget = sanitizeRedirect($_POST['redirect_to'] ?? 'dashboard.php#notifications');

function setNotificationFlash(string $type, string $message): void
{
    $_SESSION['notification_flash'] = ['type' => $type, 'message' => $message];
}

function sanitizeRedirect(string $value): string
{
    $value = trim($value);
    if ($value === '' || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        return 'dashboard.php#notifications';
    }

    if (preg_match('/^[a-zA-Z]+:\/\//', $value)) {
        return 'dashboard.php#notifications';
    }

    return $value;
}

switch ($action) {
    case 'notifications_mark_read':
        $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
        $markAll = isset($_POST['mark_all']) && $_POST['mark_all'] === '1';
        if ($markAll) {
            $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
            if ($stmt && $stmt->bind_param('i', $userId) && $stmt->execute()) {
                setNotificationFlash('success', 'All notifications marked as read.');
            } else {
                setNotificationFlash('error', 'Unable to update notifications.');
            }
            if ($stmt) {
                $stmt->close();
            }
        } elseif ($notificationId) {
            $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?');
            if ($stmt && $stmt->bind_param('ii', $notificationId, $userId) && $stmt->execute()) {
                setNotificationFlash('success', 'Notification marked as read.');
            } else {
                setNotificationFlash('error', 'Unable to update that notification.');
            }
            if ($stmt) {
                $stmt->close();
            }
        } else {
            setNotificationFlash('error', 'Select a notification to update.');
        }
        break;
    default:
        setNotificationFlash('error', 'Unsupported notification action.');
}

$conn->close();
    header('Location: ' . $redirectTarget);
exit();
