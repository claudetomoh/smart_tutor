<?php

declare(strict_types=1);

class Security {
    // Password requirements
    const MIN_PASSWORD_LENGTH = 12;
    const REQUIRE_UPPERCASE = true;
    const REQUIRE_LOWERCASE = true;
    const REQUIRE_NUMBERS = true;
    const REQUIRE_SPECIAL = true;
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOGIN_LOCKOUT_TIME = 900; // 15 minutes in seconds

    public static function validatePassword(string $password): array {
        $errors = [];
        
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = "Password must be at least " . self::MIN_PASSWORD_LENGTH . " characters long";
        }
        
        if (self::REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }
        
        if (self::REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }
        
        if (self::REQUIRE_NUMBERS && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }
        
        if (self::REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }
        
        return $errors;
    }

    public static function isAccountLocked(string $email): bool {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE email = ? 
            AND success = 0 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ');
        
        $stmt->execute([$email, self::LOGIN_LOCKOUT_TIME]);
        $result = $stmt->fetch();
        
        return $result['attempts'] >= self::MAX_LOGIN_ATTEMPTS;
    }

    public static function logLoginAttempt(string $email, string $ipAddress, bool $success): void {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO login_attempts (email, ip_address, success)
            VALUES (?, ?, ?)
        ');
        
        $stmt->execute([$email, $ipAddress, $success ? 1 : 0]);
    }

    public static function generateRefreshToken(): string {
        return bin2hex(random_bytes(32));
    }

    public static function generateSecurityReport(string $type = 'executive'): string {
        $data = self::gatherSecurityMetrics();
        return SecurityReporter::generateReport($type, $data);
    }

    public static function getCustomAuditChecklist(): array {
        $db = Database::getInstance();
        $stmt = $db->query('
            SELECT 
                c.id,
                c.category,
                c.item,
                c.description,
                c.severity,
                c.remediation_steps,
                COALESCE(cr.status, "pending") as status,
                COALESCE(cr.notes, "") as notes,
                cr.checked_at
            FROM audit_checklist c
            LEFT JOIN checklist_results cr ON c.id = cr.checklist_id
                AND cr.audit_date = CURRENT_DATE()
            WHERE c.is_active = 1
            ORDER BY c.category, c.severity DESC
        ');
        
        $checklist = [];
        while ($row = $stmt->fetch()) {
            if (!isset($checklist[$row['category']])) {
                $checklist[$row['category']] = [];
            }
            $checklist[$row['category']][] = $row;
        }
        
        return $checklist;
    }

    public static function updateAuditChecklistItem(int $itemId, string $status, string $notes = ''): void {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO checklist_results (checklist_id, status, notes, audit_date)
            VALUES (?, ?, ?, CURRENT_DATE())
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                notes = VALUES(notes)
        ');
        
        $stmt->execute([$itemId, $status, $notes]);
    }

    public static function getHistoricalMetrics(string $startDate, string $endDate): array {
        $db = Database::getInstance();
        
        // Get daily risk scores
        $stmt = $db->prepare('
            SELECT DATE(created_at) as date, risk_score
            FROM security_metrics
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
        ');
        $stmt->execute([$startDate, $endDate]);
        $riskScores = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Get incident trends
        $stmt = $db->prepare('
            SELECT 
                DATE(created_at) as date,
                severity,
                COUNT(*) as count
            FROM security_incidents
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at), severity
        ');
        $stmt->execute([$startDate, $endDate]);
        $incidentTrends = [];
        while ($row = $stmt->fetch()) {
            if (!isset($incidentTrends[$row['date']])) {
                $incidentTrends[$row['date']] = [];
            }
            $incidentTrends[$row['date']][$row['severity']] = $row['count'];
        }

        // Get compliance history
        $stmt = $db->prepare('
            SELECT 
                a.framework_name,
                a.audit_date,
                a.compliance_score,
                a.status
            FROM compliance_audits a
            INNER JOIN (
                SELECT framework_name, MAX(audit_date) as latest_date
                FROM compliance_audits
                WHERE audit_date BETWEEN ? AND ?
                GROUP BY framework_name
            ) latest ON a.framework_name = latest.framework_name 
                AND a.audit_date = latest.latest_date
        ');
        $stmt->execute([$startDate, $endDate]);
        $complianceHistory = $stmt->fetchAll(PDO::FETCH_GROUP);

        return [
            'riskScores' => $riskScores,
            'incidentTrends' => $incidentTrends,
            'complianceHistory' => $complianceHistory
        ];
    }

    private static function gatherSecurityMetrics(): array {
        $db = Database::getInstance();
        
        // Get risk score based on various factors
        $riskScore = self::calculateRiskScore();
        
        // Get security events count
        $stmt = $db->query('
            SELECT 
                COUNT(*) as total_events,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_threats,
                SUM(CASE WHEN status = "resolved" THEN 1 ELSE 0 END) as resolved_incidents
            FROM security_events 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ');
        $eventMetrics = $stmt->fetch();

        // Get compliance status
        $stmt = $db->query('
            SELECT framework_name, compliance_score, status, last_audit_date, next_audit_date 
            FROM compliance_audits
            WHERE is_active = 1
        ');
        $compliance = [];
        while ($row = $stmt->fetch()) {
            $compliance[$row['framework_name']] = [
                'score' => $row['compliance_score'],
                'status' => $row['status'],
                'lastAudit' => $row['last_audit_date'],
                'nextAudit' => $row['next_audit_date']
            ];
        }

        // Get risk assessment data
        $stmt = $db->query('
            SELECT category, description, severity, likelihood, impact 
            FROM risk_assessments
            WHERE is_active = 1
            ORDER BY severity DESC, likelihood DESC
        ');
        $risks = $stmt->fetchAll();

        // Get security recommendations
        $stmt = $db->query('
            SELECT title, description, priority, effort_level as effort, impact 
            FROM security_recommendations
            WHERE is_implemented = 0
            ORDER BY priority DESC, impact DESC
            LIMIT 5
        ');
        $recommendations = $stmt->fetchAll();

        return [
            'period' => 'Last 30 days',
            'riskScore' => $riskScore,
            'totalEvents' => $eventMetrics['total_events'],
            'activeThreats' => $eventMetrics['active_threats'],
            'resolvedIncidents' => $eventMetrics['resolved_incidents'],
            'compliance' => $compliance,
            'risks' => $risks,
            'recommendations' => $recommendations
        ];
    }

    private static function calculateRiskScore(): int {
        $db = Database::getInstance();
        
        // Factors affecting risk score:
        // 1. Number of active threats
        // 2. Failed login attempts
        // 3. Compliance status
        // 4. System vulnerabilities
        // 5. Recent security incidents
        
        $score = 100; // Start with perfect score
        
        // Active threats (-10 points each, max -30)
        $stmt = $db->query('
            SELECT COUNT(*) as count 
            FROM security_events 
            WHERE status = "active" 
            AND severity IN ("high", "critical")
        ');
        $activeThreats = min(3, (int)$stmt->fetchColumn());
        $score -= $activeThreats * 10;
        
        // Failed login attempts in last hour (-5 points per 10 attempts, max -15)
        $stmt = $db->query('
            SELECT COUNT(*) as count 
            FROM login_attempts 
            WHERE success = 0 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ');
        $failedLogins = min(3, floor((int)$stmt->fetchColumn() / 10));
        $score -= $failedLogins * 5;
        
        // Compliance status (-10 points per non-compliant framework)
        $stmt = $db->query('
            SELECT COUNT(*) as count 
            FROM compliance_audits 
            WHERE is_active = 1 
            AND compliance_score < 80
        ');
        $nonCompliantFrameworks = min(3, (int)$stmt->fetchColumn());
        $score -= $nonCompliantFrameworks * 10;
        
        // System vulnerabilities (-8 points each, max -24)
        $stmt = $db->query('
            SELECT COUNT(*) as count 
            FROM security_vulnerabilities 
            WHERE status = "open" 
            AND severity IN ("high", "critical")
        ');
        $vulnerabilities = min(3, (int)$stmt->fetchColumn());
        $score -= $vulnerabilities * 8;
        
        // Recent security incidents (-7 points each, max -21)
        $stmt = $db->query('
            SELECT COUNT(*) as count 
            FROM security_incidents 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) 
            AND severity IN ("high", "critical")
        ');
        $recentIncidents = min(3, (int)$stmt->fetchColumn());
        $score -= $recentIncidents * 7;
        
        return max(0, $score); // Ensure score doesn't go below 0
    }
}