<?php

declare(strict_types=1);

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

require_once __DIR__ . '/lib/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respondError(405, 'Method not allowed.');
}

try {
    $filters = normaliseFilters($_GET ?? []);
    $db = Database::getInstance();

    $result = fetchTutors($db, $filters);

    respondJson([
        'status' => 'ok',
        'message' => $result['total'] ? 'Tutors retrieved successfully.' : 'No tutors match the current filters yet.',
        'data' => $result['tutors'],
        'total' => $result['total'],
        'pagination' => [
            'page' => $filters['page'],
            'per_page' => $filters['limit'],
        ],
    ]);
} catch (PDOException $exception) {
    respondError(500, 'Unable to load tutors from the database.', [
        'detail' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    respondError(500, 'Unexpected server error while loading tutors.', [
        'detail' => $exception->getMessage(),
    ]);
}

function normaliseFilters(array $input): array
{
    $page = isset($input['page']) ? (int) $input['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    $limit = isset($input['limit']) ? (int) $input['limit'] : 12;
    if ($limit < 1) {
        $limit = 12;
    }
    if ($limit > 50) {
        $limit = 50;
    }

    $filters = [
        'page' => $page,
        'limit' => $limit,
        'search' => trim((string) ($input['q'] ?? $input['search'] ?? '')),
        'subject' => trim((string) ($input['subject'] ?? '')),
        'mode' => trim((string) ($input['mode'] ?? '')),
    ];

    $lower = static function (string $value): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    };

    if ($filters['search'] !== '') {
        $filters['search'] = $lower($filters['search']);
    }

    if ($filters['subject'] !== '') {
        $filters['subject'] = $lower($filters['subject']);
    }

    if ($filters['mode'] !== '') {
        $filters['mode'] = $lower($filters['mode']);
    }

    return $filters;
}

function fetchTutors(PDO $db, array $filters): array
{
    $conditions = ['u.role = :role', 'u.status = :status', "COALESCE(tp.verification_status, 'pending') = :verification_status"];
    $params = [
        'role' => 'tutor',
        'status' => 'active',
        'verification_status' => 'verified',
    ];

    if ($filters['subject'] !== '') {
        $conditions[] = 'EXISTS (
            SELECT 1
            FROM tutor_subjects ts_filter
            INNER JOIN subjects s_filter ON s_filter.id = ts_filter.subject_id
            WHERE ts_filter.tutor_id = u.id
              AND LOWER(s_filter.name) LIKE :subject_filter
        )';
        $params['subject_filter'] = '%' . $filters['subject'] . '%';
    }

    if ($filters['search'] !== '') {
        $conditions[] = '(
            LOWER(u.name) LIKE :search_name
            OR LOWER(u.email) LIKE :search_email
            OR LOWER(COALESCE(tp.teaching_experience, "")) LIKE :search_bio
            OR EXISTS (
                SELECT 1
                FROM tutor_subjects ts_search
                INNER JOIN subjects s_search ON s_search.id = ts_search.subject_id
                WHERE ts_search.tutor_id = u.id
                  AND LOWER(s_search.name) LIKE :search_subject
            )
        )';
        $like = '%' . $filters['search'] . '%';
        $params['search_name'] = $like;
        $params['search_email'] = $like;
        $params['search_bio'] = $like;
        $params['search_subject'] = $like;
    }

    if ($filters['mode'] !== '') {
        $conditions[] = '(
            COALESCE(JSON_CONTAINS(tp.availability, :mode_json_array, "$.modes"), 0) = 1
            OR COALESCE(JSON_CONTAINS(tp.availability, :mode_json_scalar), 0) = 1
        )';
        $params['mode_json_array'] = json_encode([$filters['mode']]);
        $params['mode_json_scalar'] = json_encode($filters['mode']);
    }

    $whereClause = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

    $countSql = 'SELECT COUNT(DISTINCT u.id) as total
        FROM users u
        LEFT JOIN tutor_profiles tp ON tp.tutor_id = u.id
        ' . $whereClause;

    $countStmt = $db->prepare($countSql);
    bindNamedParams($countStmt, $params);
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    if ($total === 0) {
        return [
            'total' => 0,
            'tutors' => [],
        ];
    }

    $offset = ($filters['page'] - 1) * $filters['limit'];

    $dataSql = 'SELECT
            u.id,
            u.name,
            u.email,
            u.status,
            COALESCE(tp.hourly_rate, 0) AS hourly_rate,
            COALESCE(tp.rating, 0) AS rating,
            COALESCE(tp.total_reviews, 0) AS total_reviews,
            tp.availability,
            tp.education,
            tp.teaching_experience
        FROM users u
    LEFT JOIN tutor_profiles tp ON tp.tutor_id = u.id
    ' . $whereClause . '
    GROUP BY u.id, u.name, u.email, u.status, tp.hourly_rate, tp.rating, tp.total_reviews, tp.availability, tp.education, tp.teaching_experience
        ORDER BY rating DESC, u.name ASC
        LIMIT :limit OFFSET :offset';

    $dataStmt = $db->prepare($dataSql);
    bindNamedParams($dataStmt, $params);
    $dataStmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();

    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return [
            'total' => $total,
            'tutors' => [],
        ];
    }

    $tutorIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $subjectsMap = fetchSubjectsForTutors($db, $tutorIds);

    $tutors = array_map(static function (array $row) use ($subjectsMap): array {
        $subjects = $subjectsMap[$row['id']] ?? [];
        $availability = parseAvailability($row['availability'] ?? null);
        $modes = extractModes($row['availability'] ?? null);

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'subjects' => $subjects,
            'rating' => (float) $row['rating'],
            'total_reviews' => (int) $row['total_reviews'],
            'price' => (float) $row['hourly_rate'],
            'photo' => '/public/images/tutor-placeholder.svg',
            'bio' => buildTutorBio($row),
            'location' => 'Remote',
            'availability' => $availability,
            'mode' => $modes,
            'highlights' => buildHighlights($row, $subjects),
            'status' => $row['status'],
        ];
    }, $rows);

    return [
        'total' => $total,
        'tutors' => $tutors,
    ];
}

function bindNamedParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $paramType = PDO::PARAM_STR;
        if (is_int($value)) {
            $paramType = PDO::PARAM_INT;
        } elseif (is_bool($value)) {
            $paramType = PDO::PARAM_BOOL;
        }

        $stmt->bindValue(':' . $key, $value, $paramType);
    }
}

function fetchSubjectsForTutors(PDO $db, array $tutorIds): array
{
    if (!$tutorIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($tutorIds), '?'));
    $sql = 'SELECT ts.tutor_id, s.name
        FROM tutor_subjects ts
        INNER JOIN subjects s ON s.id = ts.subject_id
        WHERE ts.tutor_id IN (' . $placeholders . ')
        ORDER BY s.name ASC';

    $stmt = $db->prepare($sql);
    foreach ($tutorIds as $index => $value) {
        $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tutorId = (int) $row['tutor_id'];
        if (!isset($map[$tutorId])) {
            $map[$tutorId] = [];
        }
        $map[$tutorId][] = $row['name'];
    }

    return $map;
}

function parseAvailability(?string $raw): array
{
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    if (isset($decoded['days']) && is_array($decoded['days'])) {
        return normaliseStringArray($decoded['days']);
    }

    if (array_values($decoded) === $decoded) {
        return normaliseStringArray($decoded);
    }

    $days = [];
    foreach ($decoded as $value) {
        if (is_array($value) && isset($value['day'])) {
            $days[] = (string) $value['day'];
        }
    }

    return normaliseStringArray($days);
}

function extractModes(?string $rawAvailability): array
{
    if ($rawAvailability) {
        $decoded = json_decode($rawAvailability, true);
        if (is_array($decoded)) {
            if (isset($decoded['modes']) && is_array($decoded['modes'])) {
                $modes = normaliseStringArray($decoded['modes']);
                if ($modes) {
                    return $modes;
                }
            }

            if (array_values($decoded) === $decoded) {
                $collected = [];
                foreach ($decoded as $entry) {
                    if (is_array($entry) && isset($entry['mode'])) {
                        $collected[] = (string) $entry['mode'];
                    }
                }
                $modes = normaliseStringArray($collected);
                if ($modes) {
                    return $modes;
                }
            }
        }
    }

    return ['online'];
}

function normaliseStringArray(array $values): array
{
    $result = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            continue;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            continue;
        }
        $result[] = $trimmed;
    }

    return array_values(array_unique($result));
}

function buildTutorBio(array $row): string
{
    $experience = trim((string) ($row['teaching_experience'] ?? ''));
    if ($experience !== '') {
        return $experience;
    }

    $education = trim((string) ($row['education'] ?? ''));
    if ($education !== '') {
        return $education;
    }

    return 'Experienced tutor currently accepting new learners.';
}

function buildHighlights(array $row, array $subjects): array
{
    $highlights = [];

    if ($subjects) {
        $highlights[] = 'Specialises in ' . implode(', ', $subjects);
    }

    if (!empty($row['education'])) {
        $highlights[] = trim((string) $row['education']);
    }

    if ($row['total_reviews'] > 0) {
        $highlights[] = $row['total_reviews'] . ' positive review' . ($row['total_reviews'] === 1 ? '' : 's');
    }

    return $highlights;
}

function respondJson(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function respondError(int $status, string $message, array $meta = []): void
{
    respondJson([
        'status' => 'error',
        'message' => $message,
        'meta' => $meta,
    ], $status);
}

