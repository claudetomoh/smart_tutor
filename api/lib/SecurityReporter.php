<?php

declare(strict_types=1);

class SecurityReporter {
    private const REPORT_TYPES = [
        'executive' => [
            'title' => 'Executive Security Summary',
            'sections' => ['overview', 'risk_assessment', 'key_metrics', 'historical_trends', 'recommendations']
        ],
        'checklist' => [
            'title' => 'Security Audit Checklist',
            'sections' => ['checklist_summary', 'checklist_details']
        ],
        'compliance' => [
            'title' => 'Compliance Status Report',
            'sections' => ['compliance_summary', 'audit_results', 'gaps', 'action_plan']
        ],
        'incident' => [
            'title' => 'Security Incident Report',
            'sections' => ['incident_details', 'impact_analysis', 'response_actions', 'lessons']
        ],
        'audit' => [
            'title' => 'Security Audit Report',
            'sections' => ['audit_scope', 'findings', 'vulnerabilities', 'remediation']
        ]
    ];

    public static function generateReport(
        string $type,
        array $data,
        string $format = 'pdf'
    ): string {
        if (!isset(self::REPORT_TYPES[$type])) {
            throw new InvalidArgumentException("Invalid report type: $type");
        }

        $reportConfig = self::REPORT_TYPES[$type];
        $content = self::generateReportContent($type, $data, $reportConfig);

        return match($format) {
            'pdf' => self::generatePDF($content, $reportConfig['title']),
            'html' => $content,
            default => throw new InvalidArgumentException("Unsupported format: $format")
        };
    }

    private static function generateReportContent(
        string $type,
        array $data,
        array $config
    ): string {
        $content = self::getReportTemplate();
        
        // Replace template variables
        $content = str_replace(
            [
                '{{title}}',
                '{{generated_date}}',
                '{{report_period}}'
            ],
            [
                $config['title'],
                date('Y-m-d H:i:s'),
                $data['period'] ?? 'Last 30 days'
            ],
            $content
        );

        // Generate sections
        $sections = '';
        foreach ($config['sections'] as $section) {
            $sections .= self::generateSection($type, $section, $data);
        }

        return str_replace('{{content}}', $sections, $content);
    }

    private static function generateSection(
        string $type,
        string $section,
        array $data
    ): string {
        $method = 'generate' . str_replace('_', '', ucwords($section, '_')) . 'Section';
        
        if (method_exists(self::class, $method)) {
            return self::$method($data);
        }
        
        return "<h2>" . ucwords(str_replace('_', ' ', $section)) . "</h2><p>Section content not available.</p>";
    }

    private static function generateOverviewSection(array $data): string {
        $riskScore = $data['riskScore'] ?? 0;
        $riskClass = $riskScore > 75 ? 'critical' : ($riskScore > 50 ? 'high' : ($riskScore > 25 ? 'medium' : 'low'));

        return <<<HTML
        <section class="overview">
            <h2>Security Overview</h2>
            <div class="risk-score {$riskClass}">
                <h3>Current Risk Score</h3>
                <div class="score-value">{$riskScore}/100</div>
            </div>
            <div class="metrics-summary">
                <div class="metric">
                    <label>Security Events</label>
                    <value>{$data['totalEvents'] ?? 0}</value>
                </div>
                <div class="metric">
                    <label>Active Threats</label>
                    <value>{$data['activeThreats'] ?? 0}</value>
                </div>
                <div class="metric">
                    <label>Resolved Incidents</label>
                    <value>{$data['resolvedIncidents'] ?? 0}</value>
                </div>
            </div>
        </section>
        HTML;
    }

    private static function generateRiskAssessmentSection(array $data): string {
        $risks = $data['risks'] ?? [];
        $riskHtml = '';

        foreach ($risks as $risk) {
            $riskHtml .= <<<HTML
            <tr class="risk-{$risk['severity']}">
                <td>{$risk['category']}</td>
                <td>{$risk['description']}</td>
                <td>{$risk['severity']}</td>
                <td>{$risk['likelihood']}</td>
                <td>{$risk['impact']}</td>
            </tr>
            HTML;
        }

        return <<<HTML
        <section class="risk-assessment">
            <h2>Risk Assessment</h2>
            <table class="risk-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Severity</th>
                        <th>Likelihood</th>
                        <th>Impact</th>
                    </tr>
                </thead>
                <tbody>
                    {$riskHtml}
                </tbody>
            </table>
        </section>
        HTML;
    }

    private static function generateComplianceSummarySection(array $data): string {
        $frameworks = $data['compliance'] ?? [];
        $complianceHtml = '';

        foreach ($frameworks as $framework => $status) {
            $complianceHtml .= <<<HTML
            <tr>
                <td>{$framework}</td>
                <td>{$status['score']}%</td>
                <td class="status-{$status['status']}">{$status['status']}</td>
                <td>{$status['lastAudit']}</td>
                <td>{$status['nextAudit']}</td>
            </tr>
            HTML;
        }

        return <<<HTML
        <section class="compliance-summary">
            <h2>Compliance Summary</h2>
            <table class="compliance-table">
                <thead>
                    <tr>
                        <th>Framework</th>
                        <th>Compliance Score</th>
                        <th>Status</th>
                        <th>Last Audit</th>
                        <th>Next Audit</th>
                    </tr>
                </thead>
                <tbody>
                    {$complianceHtml}
                </tbody>
            </table>
        </section>
        HTML;
    }

    private static function generateRecommendationsSection(array $data): string {
        $recommendations = $data['recommendations'] ?? [];
        $recommendationsHtml = '';

        foreach ($recommendations as $rec) {
            $recommendationsHtml .= <<<HTML
            <div class="recommendation priority-{$rec['priority']}">
                <h4>{$rec['title']}</h4>
                <p>{$rec['description']}</p>
                <div class="recommendation-meta">
                    <span>Priority: {$rec['priority']}</span>
                    <span>Effort: {$rec['effort']}</span>
                    <span>Impact: {$rec['impact']}</span>
                </div>
            </div>
            HTML;
        }

        return <<<HTML
        <section class="recommendations">
            <h2>Security Recommendations</h2>
            <div class="recommendations-list">
                {$recommendationsHtml}
            </div>
        </section>
        HTML;
    }

    private static function generateHistoricalTrendsSection(array $data): void {
        $historicalData = $data['historical'] ?? [];
        ?>
        <section class="historical-trends">
            <h2>Historical Security Trends</h2>
            
            <?php if (isset($historicalData['riskScores'])): ?>
            <div class="trend-section">
                <h3>Risk Score Trend</h3>
                <div class="trend-chart">
                    <table class="trend-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Risk Score</th>
                                <th>Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prevScore = null;
                            foreach ($historicalData['riskScores'] as $date => $score): 
                                $change = $prevScore !== null ? $score - $prevScore : 0;
                                $changeClass = $change > 0 ? 'worse' : ($change < 0 ? 'better' : 'same');
                                $prevScore = $score;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($date); ?></td>
                                    <td><?php echo htmlspecialchars($score); ?></td>
                                    <td class="<?php echo $changeClass; ?>">
                                        <?php echo $change > 0 ? '+' . $change : $change; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($historicalData['incidentTrends'])): ?>
            <div class="trend-section">
                <h3>Security Incidents Trend</h3>
                <div class="trend-chart">
                    <table class="trend-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Critical</th>
                                <th>High</th>
                                <th>Medium</th>
                                <th>Low</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historicalData['incidentTrends'] as $date => $incidents): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($date); ?></td>
                                    <td><?php echo $incidents['critical'] ?? 0; ?></td>
                                    <td><?php echo $incidents['high'] ?? 0; ?></td>
                                    <td><?php echo $incidents['medium'] ?? 0; ?></td>
                                    <td><?php echo $incidents['low'] ?? 0; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($historicalData['complianceHistory'])): ?>
            <div class="trend-section">
                <h3>Compliance Score History</h3>
                <div class="trend-chart">
                    <?php foreach ($historicalData['complianceHistory'] as $framework => $audits): ?>
                        <div class="framework-history">
                            <h4><?php echo htmlspecialchars($framework); ?></h4>
                            <table class="trend-table">
                                <thead>
                                    <tr>
                                        <th>Audit Date</th>
                                        <th>Score</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($audits as $audit): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($audit['audit_date']); ?></td>
                                            <td><?php echo htmlspecialchars($audit['compliance_score']); ?>%</td>
                                            <td class="status-<?php echo htmlspecialchars(strtolower($audit['status'])); ?>">
                                                <?php echo htmlspecialchars($audit['status']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function generateChecklistSummarySection(array $data): void {
        $checklist = $data['checklist'] ?? [];
        $totalItems = 0;
        $passedItems = 0;
        $failedItems = 0;
        $pendingItems = 0;
        
        foreach ($checklist as $category => $items) {
            foreach ($items as $item) {
                $totalItems++;
                switch ($item['status']) {
                    case 'pass': $passedItems++; break;
                    case 'fail': $failedItems++; break;
                    case 'pending': $pendingItems++; break;
                }
            }
        }
        
        $completionRate = $totalItems > 0 ? round(($passedItems + $failedItems) / $totalItems * 100, 1) : 0;
        $passRate = $totalItems > 0 ? round($passedItems / $totalItems * 100, 1) : 0;
        ?>
        <section class="checklist-summary">
            <h2>Security Audit Checklist Summary</h2>
            <div class="checklist-metrics">
                <div class="metric">
                    <label>Total Items</label>
                    <value><?php echo $totalItems; ?></value>
                </div>
                <div class="metric">
                    <label>Completion Rate</label>
                    <value><?php echo $completionRate; ?>%</value>
                </div>
                <div class="metric">
                    <label>Pass Rate</label>
                    <value><?php echo $passRate; ?>%</value>
                </div>
            </div>
            <div class="status-breakdown">
                <div class="status-item status-pass">
                    <label>Passed</label>
                    <value><?php echo $passedItems; ?></value>
                </div>
                <div class="status-item status-fail">
                    <label>Failed</label>
                    <value><?php echo $failedItems; ?></value>
                </div>
                <div class="status-item status-pending">
                    <label>Pending</label>
                    <value><?php echo $pendingItems; ?></value>
                </div>
            </div>
        </section>
        <?php
    }

    private static function generateChecklistDetailsSection(array $data): void {
        $checklist = $data['checklist'] ?? [];
        ?>
        <section class="checklist-details">
            <h2>Security Audit Checklist Details</h2>
            <?php foreach ($checklist as $category => $items): ?>
                <div class="checklist-category">
                    <h3><?php echo htmlspecialchars($category); ?></h3>
                    <div class="checklist-items">
                        <?php foreach ($items as $item): ?>
                            <div class="checklist-item severity-<?php echo htmlspecialchars($item['severity']); ?>">
                                <div class="item-header">
                                    <h4><?php echo htmlspecialchars($item['item']); ?></h4>
                                    <span class="status status-<?php echo htmlspecialchars($item['status']); ?>">
                                        <?php echo htmlspecialchars(strtoupper($item['status'])); ?>
                                    </span>
                                </div>
                                <div class="item-details">
                                    <p class="description"><?php echo htmlspecialchars($item['description']); ?></p>
                                    <?php if ($item['status'] === 'fail'): ?>
                                        <div class="remediation">
                                            <h5>Remediation Steps:</h5>
                                            <p><?php echo htmlspecialchars($item['remediation_steps']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['notes'])): ?>
                                        <div class="audit-notes">
                                            <h5>Audit Notes:</h5>
                                            <p><?php echo htmlspecialchars($item['notes']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    private static function generatePDF(string $html, string $title): string {
        // For now, we'll return HTML since we don't have a PDF library
        // In production, you would want to use a PDF library like TCPDF, FPDF, or mPDF
        return $html;
    }
}