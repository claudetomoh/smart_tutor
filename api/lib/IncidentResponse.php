<?php

declare(strict_types=1);

class IncidentResponse {
    private const PLAYBOOKS = [
        'brute_force' => [
            'title' => 'Brute Force Attack Response',
            'trigger' => [
                'condition' => 'failed_login_count > 10 FROM SAME IP IN 5 MINUTES',
                'severity' => 'high'
            ],
            'steps' => [
                ['action' => 'block_ip', 'duration' => '24h'],
                ['action' => 'notify_admin', 'channel' => ['email', 'sms']],
                ['action' => 'log_incident'],
                ['action' => 'update_waf_rules']
            ]
        ],
        'suspicious_location' => [
            'title' => 'Suspicious Location Access',
            'trigger' => [
                'condition' => 'login_attempt FROM NEW LOCATION FOR ADMIN ACCOUNT',
                'severity' => 'medium'
            ],
            'steps' => [
                ['action' => 'require_additional_verification'],
                ['action' => 'notify_user', 'channel' => ['email']],
                ['action' => 'log_incident']
            ]
        ],
        'account_compromise' => [
            'title' => 'Potential Account Compromise',
            'trigger' => [
                'condition' => 'multiple_password_resets OR unusual_activity_pattern',
                'severity' => 'critical'
            ],
            'steps' => [
                ['action' => 'lock_account'],
                ['action' => 'revoke_all_sessions'],
                ['action' => 'notify_admin', 'channel' => ['email', 'sms']],
                ['action' => 'notify_user', 'channel' => ['email']],
                ['action' => 'require_password_reset'],
                ['action' => 'enable_enhanced_monitoring']
            ]
        ],
        'data_exfiltration' => [
            'title' => 'Potential Data Exfiltration',
            'trigger' => [
                'condition' => 'unusual_data_access_pattern OR high_volume_data_transfer',
                'severity' => 'critical'
            ],
            'steps' => [
                ['action' => 'restrict_data_access'],
                ['action' => 'notify_admin', 'channel' => ['email', 'sms']],
                ['action' => 'start_audit_logging'],
                ['action' => 'initiate_investigation']
            ]
        ]
    ];

    private static array $activeIncidents = [];

    public static function handleSecurityEvent(array $event): void {
        // Check if event triggers any playbooks
        foreach (self::PLAYBOOKS as $type => $playbook) {
            if (self::matchesTrigger($event, $playbook['trigger'])) {
                self::executePlaybook($type, $event);
            }
        }
    }

    private static function matchesTrigger(array $event, array $trigger): bool {
        switch ($trigger['condition']) {
            case 'failed_login_count > 10 FROM SAME IP IN 5 MINUTES':
                return self::checkFailedLogins($event);

            case 'login_attempt FROM NEW LOCATION FOR ADMIN ACCOUNT':
                return self::checkNewLocation($event);

            case 'multiple_password_resets OR unusual_activity_pattern':
                return self::checkAccountCompromise($event);

            case 'unusual_data_access_pattern OR high_volume_data_transfer':
                return self::checkDataExfiltration($event);

            default:
                return false;
        }
    }

    private static function executePlaybook(string $type, array $event): void {
        $playbook = self::PLAYBOOKS[$type];
        $incidentId = uniqid('incident_');
        
        self::$activeIncidents[$incidentId] = [
            'type' => $type,
            'event' => $event,
            'status' => 'active',
            'steps' => [],
            'startTime' => time()
        ];

        foreach ($playbook['steps'] as $step) {
            self::executeStep($incidentId, $step);
        }

        // Log incident completion
        self::logIncident($incidentId, $type, $event, $playbook['trigger']['severity']);
    }

    private static function executeStep(string $incidentId, array $step): void {
        try {
            switch ($step['action']) {
                case 'block_ip':
                    self::blockIP($step['duration'], self::$activeIncidents[$incidentId]['event']);
                    break;

                case 'notify_admin':
                    self::notifyAdmin($step['channel'], self::$activeIncidents[$incidentId]);
                    break;

                case 'notify_user':
                    self::notifyUser($step['channel'], self::$activeIncidents[$incidentId]);
                    break;

                case 'lock_account':
                    self::lockAccount(self::$activeIncidents[$incidentId]['event']);
                    break;

                case 'revoke_all_sessions':
                    self::revokeSessions(self::$activeIncidents[$incidentId]['event']);
                    break;

                case 'start_audit_logging':
                    self::startAuditLogging(self::$activeIncidents[$incidentId]['event']);
                    break;

                default:
                    // Log unknown action
                    error_log("Unknown incident response action: {$step['action']}");
            }

            // Record step completion
            self::$activeIncidents[$incidentId]['steps'][] = [
                'action' => $step['action'],
                'status' => 'completed',
                'timestamp' => time()
            ];

        } catch (Exception $e) {
            // Log step failure
            self::$activeIncidents[$incidentId]['steps'][] = [
                'action' => $step['action'],
                'status' => 'failed',
                'error' => $e->getMessage(),
                'timestamp' => time()
            ];

            // Notify admin of failure
            self::notifyAdmin(
                ['email'],
                ['error' => "Incident response step failed: {$step['action']}", 'incident' => $incidentId]
            );
        }
    }

    private static function checkFailedLogins(array $event): bool {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT COUNT(*) as count
            FROM login_attempts
            WHERE ip_address = ?
            AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ');
        
        $stmt->execute([$event['ip_address']]);
        $result = $stmt->fetch();
        
        return $result['count'] > 10;
    }

    private static function checkNewLocation(array $event): bool {
        if (empty($event['user_id'])) return false;

        $db = Database::getInstance();
        
        // Check if user is admin
        $stmt = $db->prepare('
            SELECT role FROM users WHERE id = ?
        ');
        $stmt->execute([$event['user_id']]);
        $user = $stmt->fetch();
        
        if ($user['role'] !== 'admin') return false;

        // Check if location is new
        $stmt = $db->prepare('
            SELECT COUNT(*) as count
            FROM security_events
            WHERE user_id = ?
            AND location = ?
            AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ');
        
        $stmt->execute([$event['user_id'], $event['location']]);
        $result = $stmt->fetch();
        
        return $result['count'] === 0;
    }

    private static function logIncident(
        string $incidentId,
        string $type,
        array $event,
        string $severity
    ): void {
        $db = Database::getInstance();
        
        $stmt = $db->prepare('
            INSERT INTO security_incidents 
            (incident_id, type, severity, details, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ');
        
        $stmt->execute([
            $incidentId,
            $type,
            $severity,
            json_encode([
                'event' => $event,
                'steps' => self::$activeIncidents[$incidentId]['steps']
            ])
        ]);
    }

    // Implementation of other private methods...
    // These would handle specific actions like blocking IPs,
    // notifying users, etc.
}