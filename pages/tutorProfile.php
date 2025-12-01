<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: sign-in.html');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'tutor') {
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/../connect.php';

function bindStatementParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $references = [];
    foreach ($params as $key => $value) {
        $references[$key] = &$params[$key];
    }

    $stmt->bind_param($types, ...$references);
}

function fetchScalar(mysqli $conn, string $sql, string $types = '', array $params = [], $default = 0)
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Tutor profile scalar failed: ' . $conn->error);
        return $default;
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        error_log('Tutor profile scalar exec failed: ' . $stmt->error);
        $stmt->close();
        return $default;
    }

    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();

    return $value ?? $default;
}

function fetchAssocList(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Tutor profile list failed: ' . $conn->error);
        return [];
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        error_log('Tutor profile list exec failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        return [];
    }

    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows ?: [];
}

function formatDateTimeValue(?string $value, string $format = 'D, M j • g:i A'): string
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

function formatCurrency(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function ensureTutorAvailabilityTable(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS tutor_availability (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tutor_id BIGINT UNSIGNED NOT NULL,
        available_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        format ENUM('online','in_person','hybrid') NOT NULL DEFAULT 'online',
        notes TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE CASCADE,
        KEY tutor_schedule (tutor_id, available_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        error_log('Failed to ensure tutor_availability table: ' . $conn->error);
    }
}

function formatTimeWindow(?string $start, ?string $end): string
{
    if (empty($start) || empty($end)) {
        return 'TBD';
    }

    $startLabel = date('g:i A', strtotime($start));
    $endLabel = date('g:i A', strtotime($end));

    return $startLabel . ' — ' . $endLabel;
}

function availabilityFormatLabel(?string $format): string
{
    switch ($format) {
        case 'in_person':
            return 'In person';
        case 'hybrid':
            return 'Hybrid';
        default:
            return 'Online';
    }
}

function streamEarningsReport(mysqli $conn, int $userId): void
{
    $rows = fetchAssocList(
        $conn,
        'SELECT ts.id, ts.price, ts.payment_status, ts.updated_at, students.name AS student_name
         FROM tutoring_sessions ts
         LEFT JOIN users students ON students.id = ts.student_id
         WHERE ts.tutor_id = ?
         ORDER BY ts.updated_at DESC',
        'i',
        [$userId]
    );

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="smarttutor-earnings-' . date('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit();
    }

    fputcsv($output, ['Invoice', 'Student', 'Amount', 'Status', 'Payout date']);
    foreach ($rows as $row) {
        fputcsv($output, [
            '#INV-' . str_pad((string) ($row['id'] ?? 0), 4, '0', STR_PAD_LEFT),
            $row['student_name'] ?? 'Student',
            number_format((float) ($row['price'] ?? 0), 2),
            ucfirst($row['payment_status'] ?? 'pending'),
            formatDateTimeValue($row['updated_at'] ?? '', 'M j, Y'),
        ]);
    }

    fclose($output);
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$name = $_SESSION['name'] ?? 'Tutor';

$email = '';
$bio = '';
$profileQuery = $conn->prepare('SELECT email, bio FROM users WHERE id = ? LIMIT 1');

$banner = null;
$formErrors = [];
$availabilityFormInput = [
    'available_date' => '',
    'start_time' => '',
    'end_time' => '',
    'format' => 'online',
    'notes' => '',
];

ensureTutorAvailabilityTable($conn);

$action = $_GET['action'] ?? '';
if ($action === 'download_report') {
    streamEarningsReport($conn, $userId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['action'] ?? '';
    if ($formAction === 'add_availability') {
        $availabilityFormInput['available_date'] = trim($_POST['available_date'] ?? '');
        $availabilityFormInput['start_time'] = trim($_POST['start_time'] ?? '');
        $availabilityFormInput['end_time'] = trim($_POST['end_time'] ?? '');
        $availabilityFormInput['format'] = in_array($_POST['format'] ?? 'online', ['online', 'in_person', 'hybrid'], true) ? $_POST['format'] : 'online';
        $availabilityFormInput['notes'] = trim($_POST['notes'] ?? '');

        if ($availabilityFormInput['available_date'] === '' || !strtotime($availabilityFormInput['available_date'])) {
            $formErrors[] = 'Please choose a valid date.';
        }

        if ($availabilityFormInput['start_time'] === '' || $availabilityFormInput['end_time'] === '') {
            $formErrors[] = 'Provide both a start and end time.';
        } else {
            $startTimestamp = strtotime($availabilityFormInput['available_date'] . ' ' . $availabilityFormInput['start_time']);
            $endTimestamp = strtotime($availabilityFormInput['available_date'] . ' ' . $availabilityFormInput['end_time']);
            if ($startTimestamp === false || $endTimestamp === false) {
                $formErrors[] = 'Invalid time selection.';
            } elseif ($endTimestamp <= $startTimestamp) {
                $formErrors[] = 'End time must be later than the start time.';
            }
        }

        if (empty($formErrors)) {
            $insert = $conn->prepare('INSERT INTO tutor_availability (tutor_id, available_date, start_time, end_time, format, notes) VALUES (?, ?, ?, ?, ?, ?)');
            if ($insert) {
                $insert->bind_param(
                    'isssss',
                    $userId,
                    $availabilityFormInput['available_date'],
                    $availabilityFormInput['start_time'],
                    $availabilityFormInput['end_time'],
                    $availabilityFormInput['format'],
                    $availabilityFormInput['notes']
                );

                if ($insert->execute()) {
                    $banner = ['type' => 'success', 'message' => 'Availability slot added successfully.'];
                    $availabilityFormInput = [
                        'available_date' => '',
                        'start_time' => '',
                        'end_time' => '',
                        'format' => 'online',
                        'notes' => '',
                    ];
                } else {
                    $formErrors[] = 'Unable to save the slot. Please try again.';
                }

                $insert->close();
            } else {
                $formErrors[] = 'Unable to prepare the save statement.';
            }
        }

        if (!empty($formErrors) && $banner === null) {
            $banner = ['type' => 'error', 'message' => 'Please fix the highlighted issues below.'];
        }
    }
}
if ($profileQuery) {
    $profileQuery->bind_param('i', $userId);
    if ($profileQuery->execute()) {
        $profileQuery->bind_result($email, $bio);
        $profileQuery->fetch();
    }
    $profileQuery->close();
}

$profileStats = [
    'hourly_rate' => null,
    'rating' => null,
    'total_reviews' => 0,
    'total_sessions' => 0,
];

$statsQuery = $conn->prepare('SELECT hourly_rate, rating, total_reviews, total_sessions FROM tutor_profiles WHERE tutor_id = ? LIMIT 1');

if ($statsQuery) {
    $statsQuery->bind_param('i', $userId);
    if ($statsQuery->execute()) {
        $statsQuery->bind_result($profileStats['hourly_rate'], $profileStats['rating'], $profileStats['total_reviews'], $profileStats['total_sessions']);
        $statsQuery->fetch();
    }
    $statsQuery->close();
}

$todaySessions = (int) fetchScalar(
    $conn,
    'SELECT COUNT(*) FROM tutoring_sessions WHERE tutor_id = ? AND DATE(start_time) = CURDATE()',
    'i',
    [$userId],
    0
);

$pendingRequestsCount = (int) fetchScalar(
    $conn,
    "SELECT COUNT(*) FROM booking_requests WHERE tutor_id = ? AND status = 'pending'",
    'i',
    [$userId],
    0
);

$activeStudents = (int) fetchScalar(
    $conn,
    "SELECT COUNT(DISTINCT student_id) FROM tutoring_sessions WHERE tutor_id = ? AND status IN ('scheduled','ongoing','completed')",
    'i',
    [$userId],
    0
);

$monthEarnings = (float) fetchScalar(
    $conn,
    "SELECT COALESCE(SUM(price), 0) FROM tutoring_sessions WHERE tutor_id = ? AND payment_status = 'paid' AND DATE_FORMAT(start_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')",
    'i',
    [$userId],
    0.0
);

$averageRating = $profileStats['rating'] ?? fetchScalar(
    $conn,
    'SELECT AVG(rating) FROM session_feedback sf JOIN tutoring_sessions ts ON ts.id = sf.session_id WHERE ts.tutor_id = ?',
    'i',
    [$userId],
    null
);

$upcomingSessions = fetchAssocList(
    $conn,
    'SELECT ts.id, ts.start_time, ts.end_time, ts.status, ts.meeting_link, ts.price, ts.payment_status, subj.name AS subject_name, u.name AS student_name, u.email AS student_email
     FROM tutoring_sessions ts
     LEFT JOIN users u ON u.id = ts.student_id
     LEFT JOIN subjects subj ON subj.id = ts.subject_id
     WHERE ts.tutor_id = ?
     ORDER BY ts.start_time ASC
     LIMIT 5',
    'i',
    [$userId]
);

$nextSession = $upcomingSessions[0] ?? null;

$actionItems = fetchAssocList(
    $conn,
    'SELECT title, message FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 3',
    'i',
    [$userId]
);

$feedbackEntries = fetchAssocList(
    $conn,
    'SELECT sf.rating, sf.feedback_text, sf.created_at, reviewer.name AS reviewer_name
     FROM session_feedback sf
     JOIN tutoring_sessions ts ON ts.id = sf.session_id
     LEFT JOIN users reviewer ON reviewer.id = sf.reviewer_id
     WHERE ts.tutor_id = ?
     ORDER BY sf.created_at DESC
     LIMIT 3',
    'i',
    [$userId]
);

$earningRows = fetchAssocList(
    $conn,
    'SELECT ts.id, ts.price, ts.payment_status, ts.updated_at, students.name AS student_name
     FROM tutoring_sessions ts
     LEFT JOIN users students ON students.id = ts.student_id
     WHERE ts.tutor_id = ?
     ORDER BY ts.updated_at DESC
     LIMIT 5',
    'i',
    [$userId]
);

$tutorAvailability = fetchAssocList(
    $conn,
    'SELECT id, available_date, start_time, end_time, format, notes
     FROM tutor_availability
     WHERE tutor_id = ?
     ORDER BY available_date ASC, start_time ASC
     LIMIT 8',
    'i',
    [$userId]
);

$studentRoster = fetchAssocList(
    $conn,
    "SELECT u.name AS student_name, u.email AS student_email, MAX(ts.start_time) AS last_session
     FROM tutoring_sessions ts
     LEFT JOIN users u ON u.id = ts.student_id
     WHERE ts.tutor_id = ?
     GROUP BY ts.student_id, u.name, u.email
     ORDER BY last_session DESC
     LIMIT 8",
    'i',
    [$userId]
);

$conn->close();

$highlights = [
    [
        'title' => $todaySessions > 0 ? sprintf('%d session%s today', $todaySessions, $todaySessions === 1 ? '' : 's') : 'No sessions today',
        'description' => $nextSession ? sprintf('First class starts %s.', formatDateTimeValue($nextSession['start_time'], 'D • g:i A')) : 'Open new slots to boost your visibility.',
    ],
    [
        'title' => $pendingRequestsCount > 0 ? sprintf('%d new request%s', $pendingRequestsCount, $pendingRequestsCount === 1 ? '' : 's') : 'No pending requests',
        'description' => $pendingRequestsCount > 0 ? 'Confirm or decline to keep students updated.' : 'Share availability to attract more learners.',
    ],
    [
        'title' => $activeStudents > 0 ? sprintf('%d active student%s', $activeStudents, $activeStudents === 1 ? '' : 's') : 'No active students yet',
        'description' => $activeStudents > 0 ? 'Send quick progress notes after each session.' : 'Accept a booking to build your roster.',
    ],
];

function statusBadgeClass(?string $status): string
{
    switch ($status) {
        case 'scheduled':
        case 'ongoing':
        case 'completed':
        case 'paid':
            return 'status-badge status-confirmed';
        case 'pending':
            return 'status-badge status-pending';
        case 'cancelled':
        case 'declined':
        case 'failed':
        case 'no_show':
            return 'status-badge status-declined';
        default:
            return 'status-badge';
    }
}

function describeFormat(?string $meetingLink): string
{
    if (empty($meetingLink)) {
        return 'In person';
    }

    $normalized = strtolower($meetingLink);
    if (strpos($normalized, 'zoom') !== false || strpos($normalized, 'http') === 0) {
        return 'Online';
    }

    return 'Hybrid';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tutor Dashboard • SmartTutor Connect</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../src/css/main.css">
    <link rel="stylesheet" href="../src/css/pages.css">
    <link rel="stylesheet" href="../src/css/dashboard.css">
</head>

<body class="page-body tutor-profile">
    <header class="page-header">
        <div class="container">
            <a class="brand-mark" href="../index.html">
                <img src="../public/images/logo.png" alt="SmartTutor Connect logo">
                <span>SmartTutor Connect</span>
            </a>
            <nav class="page-nav" aria-label="Tutor navigation">
                <a href="dashboard.php">Dashboard</a>
                <a href="#schedule" class="active">Schedule</a>
                <a href="#students">Students</a>
                <a href="#earnings">Earnings</a>
            </nav>
            <div class="user-chip">
                <img src="../public/images/tutor-portrait.svg" alt="Tutor portrait">
                <div>
                    <span><?php echo htmlspecialchars($name); ?></span>
                    <small><?php echo htmlspecialchars($email ?: 'Tutor · SmartTutor Connect'); ?></small>
                </div>
            </div>
        </div>
    </header>

    <main class="profile-layout">
        <aside class="profile-sidebar">
            <section class="sidebar-card">
                <h2>Today's highlights</h2>
                <ul class="sidebar-list">
                    <?php foreach ($highlights as $item): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                            <span><?php echo htmlspecialchars($item['description']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="sidebar-card" id="earnings">
                <h3>Performance snapshot</h3>
                <ul class="mini-list">
                    <li>
                        <div>
                            <strong><?php echo htmlspecialchars(formatCurrency((float) $monthEarnings)); ?></strong>
                            <span>Earned this month</span>
                        </div>
                        <span class="badge-soft">Keep momentum</span>
                    </li>
                    <li>
                        <div>
                            <strong><?php echo $averageRating ? htmlspecialchars(number_format((float) $averageRating, 1)) . ' ★' : 'No ratings yet'; ?></strong>
                            <span>Average student rating</span>
                        </div>
                        <span class="badge-soft"><?php echo $profileStats['total_reviews'] ? htmlspecialchars($profileStats['total_reviews'] . ' reviews') : 'New tutor'; ?></span>
                    </li>
                    <li>
                        <div>
                            <strong><?php echo htmlspecialchars((string) $activeStudents); ?></strong>
                            <span>Active students</span>
                        </div>
                        <span class="badge-soft"><?php echo $profileStats['total_sessions'] ? htmlspecialchars($profileStats['total_sessions'] . ' sessions overall') : 'Let\'s book your first'; ?></span>
                    </li>
                </ul>
            </section>

            <section class="sidebar-card" id="students">
                <h3>Action centre</h3>
                <ul class="timeline">
                    <?php if (count($actionItems) === 0): ?>
                        <li>No alerts right now. Enjoy the focus time!</li>
                    <?php else: ?>
                        <?php foreach ($actionItems as $notification): ?>
                            <li><strong><?php echo htmlspecialchars($notification['title']); ?>:</strong> <?php echo htmlspecialchars($notification['message']); ?></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </section>
        </aside>

        <section class="profile-main">
            <?php if ($banner !== null): ?>
                <div class="inline-banner inline-banner-<?php echo htmlspecialchars($banner['type']); ?>">
                    <?php echo htmlspecialchars($banner['message']); ?>
                </div>
            <?php endif; ?>
            <section class="dashboard-section" id="schedule">
                <header>
                    <div>
                        <h2>Upcoming sessions</h2>
                        <p>Review and manage your lessons for the next few days.</p>
                    </div>
                    <div class="section-actions">
                        <a class="btn btn-outline-maroon" href="#availability-list">Open calendar</a>
                        <a class="btn btn-primary" href="#availability-form">Add availability</a>
                    </div>
                    <p class="section-helper">Review the confirmed lessons and manage your availability without leaving this page.</p>
                </header>

                <div class="table-responsive">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Subject</th>
                                <th>Date &amp; time</th>
                                <th>Format</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($upcomingSessions) === 0): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state-card">
                                            <div class="empty-icon" aria-hidden="true">&#128197;</div>
                                            <div>
                                                <h3>Set your first availability</h3>
                                                <p>Share a few preferred slots and we will notify students searching for your subjects.</p>
                                                <ul class="empty-state-list">
                                                    <li>Highlight popular hours (afternoons, weekends) for faster bookings.</li>
                                                    <li>Add a meeting link so reminders are populated automatically.</li>
                                                </ul>
                                                <div class="empty-state-actions">
                                                    <a class="btn btn-primary" href="#availability-form">Add slot now</a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($upcomingSessions as $session): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($session['student_name'] ?? 'Student'); ?><br>
                                            <small><?php echo htmlspecialchars($session['student_email'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($session['subject_name'] ?? 'General tutoring'); ?></td>
                                        <td><?php echo htmlspecialchars(formatDateTimeValue($session['start_time']) . ' — ' . formatDateTimeValue($session['end_time'], 'g:i A')); ?></td>
                                        <td><?php echo htmlspecialchars(describeFormat($session['meeting_link'] ?? '')); ?></td>
                                        <td><span class="<?php echo statusBadgeClass($session['status']); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $session['status'] ?? 'scheduled'))); ?></span></td>
                                        <td>
                                            <?php if (!empty($session['meeting_link'])): ?>
                                                <a class="btn btn-sm btn-outline-maroon" href="<?php echo htmlspecialchars($session['meeting_link']); ?>" target="_blank" rel="noopener">Join link</a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-maroon" type="button">Add link</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="availability-tools" id="availability-form">
                    <div class="availability-card">
                        <h3>Add an availability slot</h3>
                        <p class="section-helper">Students can only request times you expose here. Keep a few slots per week open to stay discoverable.</p>

                        <?php if (!empty($formErrors)): ?>
                            <ul class="form-errors">
                                <?php foreach ($formErrors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <form method="POST" class="availability-form">
                            <input type="hidden" name="action" value="add_availability">
                            <div class="form-grid">
                                <label>
                                    Date
                                    <input type="date" name="available_date" value="<?php echo htmlspecialchars($availabilityFormInput['available_date']); ?>" required>
                                </label>
                                <label>
                                    Start time
                                    <input type="time" name="start_time" value="<?php echo htmlspecialchars($availabilityFormInput['start_time']); ?>" required>
                                </label>
                                <label>
                                    End time
                                    <input type="time" name="end_time" value="<?php echo htmlspecialchars($availabilityFormInput['end_time']); ?>" required>
                                </label>
                                <label>
                                    Format
                                    <select name="format">
                                        <option value="online" <?php echo $availabilityFormInput['format'] === 'online' ? 'selected' : ''; ?>>Online</option>
                                        <option value="in_person" <?php echo $availabilityFormInput['format'] === 'in_person' ? 'selected' : ''; ?>>In person</option>
                                        <option value="hybrid" <?php echo $availabilityFormInput['format'] === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                                    </select>
                                </label>
                            </div>
                            <label class="notes-field">
                                Notes (optional)
                                <textarea name="notes" rows="3" placeholder="Mention preferred meeting location or topics."><?php echo htmlspecialchars($availabilityFormInput['notes']); ?></textarea>
                            </label>
                            <div class="empty-state-actions">
                                <button class="btn btn-primary" type="submit">Save slot</button>
                                <a class="btn btn-outline-maroon" href="#availability-list">View saved slots</a>
                            </div>
                        </form>
                    </div>

                    <div class="availability-card" id="availability-list">
                        <h3>Your availability</h3>
                        <?php if (count($tutorAvailability) === 0): ?>
                            <p class="text-muted">No custom slots yet. Add a few times to appear in student searches.</p>
                        <?php else: ?>
                            <ul class="availability-list">
                                <?php foreach ($tutorAvailability as $slot): ?>
                                    <li>
                                        <div>
                                            <strong><?php echo htmlspecialchars(formatDateTimeValue($slot['available_date'], 'D, M j')); ?></strong>
                                            <span><?php echo htmlspecialchars(formatTimeWindow($slot['start_time'], $slot['end_time'])); ?> • <?php echo htmlspecialchars(availabilityFormatLabel($slot['format'] ?? 'online')); ?></span>
                                        </div>
                                        <?php if (!empty($slot['notes'])): ?>
                                            <p><?php echo htmlspecialchars($slot['notes']); ?></p>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="dashboard-section">
                <header>
                    <div>
                        <h2>Student feedback</h2>
                        <p>Celebrate wins and spot coaching opportunities.</p>
                    </div>
                    <div class="section-actions">
                        <a class="btn btn-outline-maroon" href="#roster">View roster</a>
                    </div>
                    <p class="section-helper">Jump down to the roster for quick contact info or export your notes after reading feedback.</p>
                </header>

                <div class="insight-grid">
                    <?php if (count($feedbackEntries) === 0): ?>
                        <article class="insight-card insight-card-empty">
                            <div>
                                <h3>Collect testimonials</h3>
                                <p>Share a recap email after sessions and drop in a quick review link so students can rate you in seconds.</p>
                            </div>
                            <ul class="empty-state-list">
                                <li>Celebrate small wins to boost confidence.</li>
                                <li>Ask for specific feedback (clarity, pacing, resources).</li>
                            </ul>
                        </article>
                    <?php else: ?>
                        <?php foreach ($feedbackEntries as $feedback): ?>
                            <article class="insight-card">
                                <strong><?php echo htmlspecialchars($feedback['reviewer_name'] ?? 'Student'); ?> rated <?php echo htmlspecialchars((string) ($feedback['rating'] ?? '5')); ?>/5</strong>
                                <span><?php echo htmlspecialchars($feedback['feedback_text'] ?? 'Great session!'); ?></span>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="dashboard-section">
                <header>
                    <div>
                        <h2>Earnings overview</h2>
                        <p>Track paid sessions, outstanding invoices, and payout schedule.</p>
                    </div>
                    <div class="section-actions">
                        <a class="btn btn-outline-maroon" href="?action=download_report">Download CSV report</a>
                    </div>
                    <p class="section-helper">Export payouts instantly or review the table to track pending invoices.</p>
                </header>

                <div class="table-responsive">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Student</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Payout date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($earningRows) === 0): ?>
                                <tr>
                                    <td colspan="6">No earnings recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($earningRows as $row): ?>
                                    <tr>
                                        <td>#INV-<?php echo str_pad((string) ($row['id'] ?? 0), 4, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo htmlspecialchars($row['student_name'] ?? 'Student'); ?></td>
                                        <td><?php echo htmlspecialchars(formatCurrency((float) ($row['price'] ?? 0))); ?></td>
                                        <td><span class="<?php echo statusBadgeClass($row['payment_status'] ?? 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($row['payment_status'] ?? 'pending')); ?></span></td>
                                        <td><?php echo htmlspecialchars(formatDateTimeValue($row['updated_at'] ?? '', 'M j, Y')); ?></td>
                                        <td><button class="btn btn-sm btn-outline-maroon" type="button">View details</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-section" id="roster">
                <header>
                    <div>
                        <h2>Student roster</h2>
                        <p>Recent learners, last touchpoints, and quick contact actions.</p>
                    </div>
                    <div class="section-actions">
                        <a class="btn btn-outline-maroon" href="mailto:<?php echo htmlspecialchars($email); ?>?subject=Student%20Update">Email yourself a copy</a>
                    </div>
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
                                    <td colspan="3">No students yet. Confirm a booking to grow your roster.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentRoster as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_name'] ?? 'Student'); ?></td>
                                        <td><?php echo htmlspecialchars(formatDateTimeValue($student['last_session'] ?? '')); ?></td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-maroon" href="mailto:<?php echo htmlspecialchars($student['student_email'] ?? ''); ?>">Email</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>

</body>

</html>
