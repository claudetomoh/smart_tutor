<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/SecurityReporter.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify authentication
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $type = $_GET['type'] ?? 'executive';
    $format = $_GET['format'] ?? 'pdf';
    
    if (!in_array($type, ['executive', 'compliance', 'incident', 'audit'])) {
        throw new InvalidArgumentException('Invalid report type');
    }
    
    if (!in_array($format, ['pdf', 'html'])) {
        throw new InvalidArgumentException('Invalid format');
    }
    
    $report = Security::generateSecurityReport($type);
    
    if ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="security_report_' . date('Y-m-d') . '.pdf"');
    } else {
        header('Content-Type: text/html');
    }
    
    echo $report;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}