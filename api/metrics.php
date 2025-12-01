<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/SecurityAnalytics.php';
require_once __DIR__ . '/lib/SecurityAudit.php';

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

// Get time range
$range = $_GET['range'] ?? '7d';
$days = match($range) {
    '1d' => 1,
    '7d' => 7,
    '30d' => 30,
    '90d' => 90,
    default => 7
};

try {
    // Get security analytics
    $analytics = SecurityAnalytics::analyzeSecurityTrends($days);
    
    // Get security audit results
    $auditResults = SecurityAudit::performSecurityAudit();
    
    // Calculate security posture
    $securityPosture = [
        'accessControl' => calculatePostureScore($auditResults, 'authentication', 'access_controls'),
        'authentication' => calculatePostureScore($auditResults, 'authentication', ['password_policy', 'mfa_usage']),
        'dataProtection' => calculatePostureScore($auditResults, 'data_protection'),
        'monitoring' => calculatePostureScore($auditResults, 'infrastructure', 'network_security'),
        'incidentResponse' => calculatePostureScore($auditResults, 'incident_response'),
        'compliance' => calculatePostureScore($auditResults, 'compliance')
    ];
    
    // Generate response
    echo json_encode([
        'status' => 'ok',
        'riskScore' => $analytics['riskScore']['score'],
        'threatTrends' => [
            'dates' => array_keys($analytics['trends']),
            'high' => array_column($analytics['trends'], 'high'),
            'medium' => array_column($analytics['trends'], 'medium')
        ],
        'securityPosture' => $securityPosture,
        'compliance' => array_column($auditResults['compliance']['checks'], 'score'),
        'findings' => generateKeyFindings($analytics, $auditResults)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

function calculatePostureScore(array $results, string $category, $checks = null): float {
    if (!isset($results[$category])) {
        return 0;
    }

    if ($checks === null) {
        return $results[$category]['score'];
    }

    $checks = (array)$checks;
    $scores = array_column(
        array_intersect_key(
            $results[$category]['checks'],
            array_flip($checks)
        ),
        'score'
    );

    return !empty($scores) ? array_sum($scores) / count($scores) : 0;
}

function generateKeyFindings(array $analytics, array $auditResults): array {
    $findings = [];

    // Add high-risk findings
    if ($analytics['riskScore']['score'] > 75) {
        $findings[] = [
            'severity' => 'critical',
            'message' => 'Overall security risk score is critically high'
        ];
    }

    // Add compliance findings
    foreach ($auditResults['compliance']['checks'] as $check => $result) {
        if ($result['status'] === 'failed') {
            $findings[] = [
                'severity' => 'high',
                'message' => "Failed compliance check: $check"
            ];
        }
    }

    // Add trend-based findings
    foreach ($analytics['trends'] as $type => $data) {
        if ($data['change'] > 50) {
            $findings[] = [
                'severity' => 'warning',
                'message' => "Significant increase in $type events: {$data['change']}%"
            ];
        }
    }

    // Add audit-based findings
    foreach ($auditResults as $category => $data) {
        if ($data['score'] < 60) {
            $findings[] = [
                'severity' => 'high',
                'message' => "Low security score in $category: {$data['score']}/100"
            ];
        }
    }

    return $findings;
}