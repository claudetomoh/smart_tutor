<?php

declare(strict_types=1);

class SecurityLogger {
    private const IMPORTANT_EVENTS = [
        'login_failed',
        'password_reset',
        'two_factor_disabled',
        'role_changed',
        'account_locked',
        'suspicious_ip'
    ];

    public static function log(
        string $eventType,
        ?string $description = null,
        ?int $userId = null,
        ?string $ipAddress = null
    ): void {
        $db = Database::getInstance();
        
        $stmt = $db->prepare('
            INSERT INTO security_events 
            (event_type, description, user_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ');

        $ipAddress = $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt->execute([
            $eventType,
            $description,
            $userId,
            $ipAddress,
            $userAgent
        ]);

        // Alert administrators about important security events
        if (in_array($eventType, self::IMPORTANT_EVENTS)) {
            self::alertAdministrators($eventType, $description, $userId, $ipAddress);
        }
    }

    public static function getSuspiciousActivities(?int $userId = null, int $hours = 24): array {
        $db = Database::getInstance();
        
        $sql = '
            SELECT 
                se.*, 
                u.email,
                u.name
            FROM security_events se
            LEFT JOIN users u ON se.user_id = u.id
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ';
        
        $params = [$hours];
        
        if ($userId) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        
        $sql .= ' ORDER BY created_at DESC';
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    private static function alertAdministrators(
        string $eventType,
        ?string $description,
        ?int $userId,
        string $ipAddress
    ): void {
        // In production, implement email/SMS notifications to administrators
        // For now, just log to a file
        $logEntry = sprintf(
            "[%s] Security Event: %s | User ID: %s | IP: %s | Details: %s\n",
            date('Y-m-d H:i:s'),
            $eventType,
            $userId ?? 'N/A',
            $ipAddress,
            $description ?? 'No details'
        );
        
        file_put_contents(
            __DIR__ . '/../logs/security.log',
            $logEntry,
            FILE_APPEND
        );
    }
}