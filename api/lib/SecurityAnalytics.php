<?php

declare(strict_types=1);

class SecurityAnalytics {
    private static array $riskFactors = [
        'failed_login' => 1,
        'suspicious_ip' => 3,
        'brute_force' => 5,
        'admin_access' => 2,
        'multiple_2fa_failure' => 4,
        'password_reset' => 2,
        'unusual_time' => 1
    ];

    public static function analyzeSecurityTrends(int $days = 30): array {
        $db = Database::getInstance();
        
        return [
            'riskScore' => self::calculateRiskScore($days),
            'trends' => self::calculateTrends($days),
            'anomalies' => self::detectAnomalies($days),
            'recommendations' => self::generateRecommendations($days),
            'hotspots' => self::identifyHotspots($days)
        ];
    }

    private static function calculateRiskScore(int $days): array {
        $db = Database::getInstance();
        $score = 0;
        $factors = [];

        // Calculate base risk score
        $stmt = $db->prepare('
            SELECT 
                event_type,
                COUNT(*) as count
            FROM security_events
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY event_type
        ');
        $stmt->execute([$days]);
        
        while ($row = $stmt->fetch()) {
            $factor = self::$riskFactors[$row['event_type']] ?? 1;
            $score += $row['count'] * $factor;
            $factors[$row['event_type']] = $row['count'] * $factor;
        }

        // Normalize score (0-100)
        $normalizedScore = min(100, ($score / (50 * $days)) * 100);

        return [
            'score' => round($normalizedScore, 2),
            'factors' => $factors,
            'level' => self::getRiskLevel($normalizedScore)
        ];
    }

    private static function calculateTrends(int $days): array {
        $db = Database::getInstance();
        
        // Get daily event counts
        $stmt = $db->prepare('
            SELECT 
                DATE(created_at) as date,
                event_type,
                COUNT(*) as count
            FROM security_events
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at), event_type
            ORDER BY date
        ');
        $stmt->execute([$days]);
        
        $trends = [];
        while ($row = $stmt->fetch()) {
            if (!isset($trends[$row['event_type']])) {
                $trends[$row['event_type']] = [];
            }
            $trends[$row['event_type']][$row['date']] = $row['count'];
        }

        // Calculate trend lines and changes
        foreach ($trends as $type => $data) {
            $trends[$type] = [
                'data' => $data,
                'change' => self::calculateTrendChange($data),
                'prediction' => self::predictNextDayValue($data)
            ];
        }

        return $trends;
    }

    private static function detectAnomalies(int $days): array {
        $db = Database::getInstance();
        $anomalies = [];

        // Detect unusual patterns
        $patterns = [
            'login_spikes' => '
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM login_attempts
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
                HAVING count > (
                    SELECT AVG(daily_count) + 2 * STDDEV(daily_count)
                    FROM (
                        SELECT COUNT(*) as daily_count
                        FROM login_attempts
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                        GROUP BY DATE(created_at)
                    ) as stats
                )',
            
            'ip_concentration' => '
                SELECT ip_address, COUNT(*) as count
                FROM security_events
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY ip_address
                HAVING count > (
                    SELECT AVG(ip_count) + 2 * STDDEV(ip_count)
                    FROM (
                        SELECT COUNT(*) as ip_count
                        FROM security_events
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                        GROUP BY ip_address
                    ) as stats
                )'
        ];

        foreach ($patterns as $type => $query) {
            $stmt = $db->prepare($query);
            $stmt->execute([$days, $days]);
            $anomalies[$type] = $stmt->fetchAll();
        }

        return $anomalies;
    }

    private static function generateRecommendations(int $days): array {
        $recommendations = [];
        $riskScore = self::calculateRiskScore($days);

        // Base recommendations on risk factors
        foreach ($riskScore['factors'] as $factor => $score) {
            if ($score > 50) {
                $recommendations[] = self::getRecommendation($factor);
            }
        }

        // Add general recommendations based on risk score
        if ($riskScore['score'] > 75) {
            $recommendations[] = [
                'priority' => 'critical',
                'action' => 'Implement additional security measures',
                'details' => 'High risk score detected. Consider implementing stricter access controls and monitoring.'
            ];
        }

        return $recommendations;
    }

    private static function identifyHotspots(int $days): array {
        $db = Database::getInstance();
        
        // Identify security hotspots
        $queries = [
            'vulnerable_accounts' => '
                SELECT 
                    u.email,
                    COUNT(se.id) as event_count,
                    GROUP_CONCAT(DISTINCT se.event_type) as event_types
                FROM users u
                JOIN security_events se ON se.user_id = u.id
                WHERE se.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY u.id
                HAVING event_count > 10
                ORDER BY event_count DESC
                LIMIT 10
            ',
            
            'suspicious_ips' => '
                SELECT 
                    ip_address,
                    COUNT(*) as event_count,
                    GROUP_CONCAT(DISTINCT event_type) as event_types
                FROM security_events
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY ip_address
                HAVING event_count > 5
                ORDER BY event_count DESC
                LIMIT 10
            '
        ];

        $hotspots = [];
        foreach ($queries as $type => $query) {
            $stmt = $db->prepare($query);
            $stmt->execute([$days]);
            $hotspots[$type] = $stmt->fetchAll();
        }

        return $hotspots;
    }

    private static function getRiskLevel(float $score): string {
        if ($score >= 75) return 'critical';
        if ($score >= 50) return 'high';
        if ($score >= 25) return 'medium';
        return 'low';
    }

    private static function calculateTrendChange(array $data): float {
        if (count($data) < 2) return 0;

        $values = array_values($data);
        $first = array_sum(array_slice($values, 0, 3)) / min(3, count($values));
        $last = array_sum(array_slice($values, -3)) / min(3, count($values));

        return round((($last - $first) / max(1, $first)) * 100, 2);
    }

    private static function predictNextDayValue(array $data): float {
        if (count($data) < 7) return array_sum($data) / count($data);

        // Simple moving average prediction
        $last7 = array_slice($data, -7);
        return round(array_sum($last7) / count($last7), 2);
    }

    private static function getRecommendation(string $factor): array {
        $recommendations = [
            'failed_login' => [
                'priority' => 'high',
                'action' => 'Review password policies',
                'details' => 'High number of failed logins detected. Consider implementing stronger password requirements and account lockout policies.'
            ],
            'suspicious_ip' => [
                'priority' => 'critical',
                'action' => 'Implement IP filtering',
                'details' => 'Multiple suspicious IPs detected. Consider implementing IP-based access controls and geographic restrictions.'
            ],
            'brute_force' => [
                'priority' => 'critical',
                'action' => 'Enhance brute force protection',
                'details' => 'Brute force attempts detected. Implement progressive delays and advanced bot protection.'
            ],
            'multiple_2fa_failure' => [
                'priority' => 'high',
                'action' => 'Review 2FA implementation',
                'details' => 'Multiple 2FA failures detected. Consider implementing additional verification steps and monitoring.'
            ]
        ];

        return $recommendations[$factor] ?? [
            'priority' => 'medium',
            'action' => 'Review security logs',
            'details' => 'Review recent security events and implement appropriate measures.'
        ];
    }
}