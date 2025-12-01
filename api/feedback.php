<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// GET: Fetch feedback for a tutor or student
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = $_GET['userId'] ?? null;
    $role = $_GET['role'] ?? null;

    if (!$userId || !$role) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID and role required']);
        exit;
    }

    // Mock feedback data - in production, fetch from database
    $feedback = [
        'ratings' => [
            'overall' => 4.8,
            'knowledge' => 4.9,
            'communication' => 4.7,
            'punctuality' => 4.8,
            'helpfulness' => 4.7
        ],
        'reviews' => [
            [
                'id' => 1,
                'rating' => 5,
                'comment' => 'Excellent at explaining complex topics in simple terms.',
                'author' => 'Student A',
                'date' => '2025-10-28',
                'response' => 'Thank you for your kind feedback! Looking forward to our next session.'
            ],
            [
                'id' => 2,
                'rating' => 4,
                'comment' => 'Very patient and thorough in explanations.',
                'author' => 'Student B',
                'date' => '2025-10-25'
            ]
        ],
        'stats' => [
            'total_sessions' => 24,
            'total_reviews' => 18,
            'repeat_students' => 8
        ]
    ];

    echo json_encode([
        'status' => 'ok',
        'data' => $feedback
    ]);
    exit;
}

// POST: Submit new feedback or tutor response
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $payload = $input ? json_decode($input, true) : null;

    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // Handle tutor response to feedback
    if ($payload['type'] === 'response') {
        $required = ['reviewId', 'response', 'tutorId'];
        $missing = array_filter($required, fn($field) => empty($payload[$field]));

        if ($missing) {
            http_response_code(422);
            echo json_encode([
                'error' => 'Missing required fields for response',
                'fields' => array_values($missing)
            ]);
            exit;
        }

        // Mock response - in production, save to database
        echo json_encode([
            'status' => 'ok',
            'message' => 'Response submitted successfully',
            'data' => [
                'reviewId' => $payload['reviewId'],
                'response' => $payload['response'],
                'tutorId' => $payload['tutorId'],
                'created_at' => gmdate('c')
            ]
        ]);
        exit;
    }

    // Handle new feedback submission
    $required = ['userId', 'authorId', 'rating', 'comment', 'sessionId'];
    $missing = array_filter($required, fn($field) => empty($payload[$field]));

    if ($missing) {
        http_response_code(422);
        echo json_encode([
            'error' => 'Missing required fields',
            'fields' => array_values($missing)
        ]);
        exit;
    }

    // Validate rating is between 1 and 5
    if ($payload['rating'] < 1 || $payload['rating'] > 5) {
        http_response_code(422);
        echo json_encode(['error' => 'Rating must be between 1 and 5']);
        exit;
    }

    // Mock response - in production, save to database
    echo json_encode([
        'status' => 'ok',
        'message' => 'Feedback submitted successfully',
        'data' => [
            'id' => rand(1000, 9999),
            'userId' => $payload['userId'],
            'authorId' => $payload['authorId'],
            'rating' => $payload['rating'],
            'comment' => $payload['comment'],
            'sessionId' => $payload['sessionId'],
            'created_at' => gmdate('c')
        ]
    ]);
}