<?php

declare(strict_types=1);

class SecurityNotifier {
    private static array $criticalEvents = [
        'brute_force_attempt',
        'suspicious_ip',
        'account_locked',
        'admin_action',
        'multiple_2fa_failures',
        'password_reset_request'
    ];

    private static array $threatLevels = [
        'low' => ['color' => '#ffc107', 'actions' => ['log']],
        'medium' => ['color' => '#fd7e14', 'actions' => ['log', 'notify']],
        'high' => ['color' => '#dc3545', 'actions' => ['log', 'notify', 'block']],
        'critical' => ['color' => '#dc3545', 'actions' => ['log', 'notify', 'block', 'lockdown']]
    ];

    public static function notifyAdmins(array $event): void {
        $notification = self::formatNotification($event);
        
        // Send to WebSocket server
        self::sendWebSocketMessage([
            'type' => 'security_alert',
            'data' => $notification
        ]);

        // Send email for critical events
        if (in_array($event['event_type'], self::$criticalEvents)) {
            self::sendEmailNotification($notification);
        }

        // Trigger automated response if needed
        self::handleAutomatedResponse($event);
    }

    private static function formatNotification(array $event): array {
        $threatLevel = self::calculateThreatLevel($event);
        
        return [
            'id' => uniqid('alert_'),
            'timestamp' => time(),
            'type' => $event['event_type'],
            'message' => self::generateMessage($event),
            'details' => $event,
            'threatLevel' => $threatLevel,
            'color' => self::$threatLevels[$threatLevel]['color']
        ];
    }

    private static function calculateThreatLevel(array $event): string {
        // Calculate threat level based on event type and context
        if (in_array($event['event_type'], ['brute_force_attempt', 'admin_account_compromise'])) {
            return 'critical';
        }

        if (in_array($event['event_type'], ['suspicious_ip', 'multiple_2fa_failures'])) {
            return 'high';
        }

        if (in_array($event['event_type'], ['account_locked', 'password_reset_request'])) {
            return 'medium';
        }

        return 'low';
    }

    private static function generateMessage(array $event): string {
        return match($event['event_type']) {
            'brute_force_attempt' => sprintf(
                'Brute force attack detected from IP %s (%d failed attempts)',
                $event['ip_address'],
                $event['attempts']
            ),
            'suspicious_ip' => sprintf(
                'Suspicious activity detected from IP %s in %s',
                $event['ip_address'],
                $event['location'] ?? 'Unknown Location'
            ),
            'account_locked' => sprintf(
                'Account locked: %s (Multiple failed login attempts)',
                $event['user_email']
            ),
            default => $event['description'] ?? 'Security event detected'
        };
    }

    private static function handleAutomatedResponse(array $event): void {
        $threatLevel = self::calculateThreatLevel($event);
        $actions = self::$threatLevels[$threatLevel]['actions'];

        foreach ($actions as $action) {
            match($action) {
                'block' => self::blockIP($event['ip_address']),
                'lockdown' => self::initiateAccountLockdown($event['user_id'] ?? null),
                'notify' => self::sendUrgentNotifications($event),
                default => null
            };
        }
    }

    private static function blockIP(string $ip): void {
        // Add IP to blocked list
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO blocked_ips (ip_address, reason, blocked_until)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ');
        $stmt->execute([$ip, 'Automated security response']);

        // Update firewall rules if applicable
        if (function_exists('exec')) {
            exec("sudo ufw deny from $ip to any");
        }
    }

    private static function initiateAccountLockdown(?int $userId): void {
        if (!$userId) return;

        $db = Database::getInstance();
        
        // Invalidate all sessions
        $stmt = $db->prepare('
            UPDATE refresh_tokens 
            SET revoked_at = NOW() 
            WHERE user_id = ? AND revoked_at IS NULL
        ');
        $stmt->execute([$userId]);

        // Lock account
        $stmt = $db->prepare('
            UPDATE users 
            SET status = "suspended", 
                force_password_change = 1
            WHERE id = ?
        ');
        $stmt->execute([$userId]);
    }

    private static function sendUrgentNotifications(array $event): void {
        // Send SMS to security team
        if (isset($_ENV['SECURITY_PHONE'])) {
            // Implement SMS sending
        }

        // Send to security monitoring service
        if (isset($_ENV['SECURITY_WEBHOOK'])) {
            $ch = curl_init($_ENV['SECURITY_WEBHOOK']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    private static function sendWebSocketMessage(array $message): void {
        // Implement WebSocket message sending
        $wsServer = isset($_ENV['WS_SERVER']) ? $_ENV['WS_SERVER'] : 'ws://localhost:8080';
        
        // Basic WebSocket client implementation
        $client = new WebSocket\Client($wsServer);
        $client->send(json_encode($message));
        $client->close();
    }

    private static function sendEmailNotification(array $notification): void {
        $subject = "Security Alert: {$notification['type']}";
        $message = self::generateEmailTemplate($notification);
        
        // Get admin email addresses
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT email FROM users WHERE role = "admin"');
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($admins as $email) {
            mail(
                $email,
                $subject,
                $message,
                [
                    'From' => 'security@smarttutor.com',
                    'Content-Type' => 'text/html; charset=UTF-8'
                ]
            );
        }
    }

    private static function generateEmailTemplate(array $notification): string {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <body style="font-family: Arial, sans-serif;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: {$notification['color']};">Security Alert</h2>
                <p><strong>Type:</strong> {$notification['type']}</p>
                <p><strong>Message:</strong> {$notification['message']}</p>
                <p><strong>Time:</strong> {$notification['timestamp']}</p>
                <p><strong>Threat Level:</strong> {$notification['threatLevel']}</p>
                <hr>
                <p>Please check the security dashboard for more details.</p>
            </div>
        </body>
        </html>
        HTML;
    }
}