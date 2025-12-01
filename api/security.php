<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/SecurityLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify JWT token
$headers = getallheaders();
$token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'No token provided']);
    exit;
}

// Validate admin access
try {
    $tokenData = Security::validateJWT($token);
    if (!$tokenData || $tokenData['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized access']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

// Process timeframe
$timeframe = $_GET['timeframe'] ?? '24h';
$hours = match($timeframe) {
    '1h' => 1,
    '24h' => 24,
    '7d' => 168,
    '30d' => 720,
    default => 24
};

try {
    $db = Database::getInstance();
    
    // Get basic stats
    $stats = [
        'totalEvents' => 0,
        'failedLogins' => 0,
        'suspicious' => 0,
        'lockedAccounts' => 0
    ];
    
    // Total events
    $stmt = $db->prepare('
        SELECT COUNT(*) as count
        FROM security_events
        WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
    ');
    $stmt->execute([$hours]);
    $stats['totalEvents'] = $stmt->fetch()['count'];
    
    // Failed logins
    $stmt = $db->prepare('
        SELECT COUNT(*) as count
        FROM login_attempts
        WHERE success = 0
        AND attempted_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
    ');
    $stmt->execute([$hours]);
    $stats['failedLogins'] = $stmt->fetch()['count'];
    
    // Suspicious activities
    $stmt = $db->prepare('
        SELECT COUNT(*) as count
        FROM security_events
        WHERE event_type IN ("suspicious_ip", "brute_force_attempt", "unusual_activity")
        AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
    ');
    $stmt->execute([$hours]);
    $stats['suspicious'] = $stmt->fetch()['count'];
    
    // Locked accounts
    $stmt = $db->prepare('
        SELECT COUNT(DISTINCT email) as count
        FROM users
        WHERE failed_login_attempts >= ?
        AND last_failed_login > DATE_SUB(NOW(), INTERVAL ? HOUR)
    ');
    $stmt->execute([Security::MAX_LOGIN_ATTEMPTS, $hours]);
    $stats['lockedAccounts'] = $stmt->fetch()['count'];
    
    // Get recent events with location data
    $stmt = $db->prepare('
        SELECT 
            se.*,
            u.email as user_email,
            COALESCE(
                (SELECT country_name 
                FROM ip_locations 
                WHERE ip_address = se.ip_address 
                LIMIT 1),
                "Unknown"
            ) as location
        FROM security_events se
        LEFT JOIN users u ON se.user_id = u.id
        WHERE se.created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY se.created_at DESC
        LIMIT 100
    ');
    $stmt->execute([$hours]);
    $events = $stmt->fetchAll();
    
    echo json_encode([
        'status' => 'ok',
        'stats' => $stats,
        'events' => $events
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}