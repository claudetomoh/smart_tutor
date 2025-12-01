import React, { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    RadialLinearScale,
    Title,
    Tooltip,
    Legend,
    Filler
} from 'chart.js';
import { Line, Bar, Doughnut, Radar } from 'react-chartjs-2';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    RadialLinearScale,
    Title,
    Tooltip,
    Legend,
    Filler
);

const SecurityMetrics = () => {
    const { token } = useAuth();
    const [metrics, setMetrics] = useState(null);
    const [timeRange, setTimeRange] = useState('7d');
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchMetrics();
    }, [timeRange]);

    const fetchMetrics = async () => {
        try {
            const response = await fetch(`/api/security/metrics?range=${timeRange}`, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) throw new Error('Failed to fetch metrics');
            
            const data = await response.json();
            setMetrics(data);
        } catch (error) {
            console.error('Error fetching security metrics:', error);
        } finally {
            setLoading(false);
        }
    };

    const riskScoreConfig = {
        data: {
            datasets: [{
                data: [metrics?.riskScore ?? 0],
                backgroundColor: (context) => {
                    const score = metrics?.riskScore ?? 0;
                    if (score > 75) return '#dc3545';
                    if (score > 50) return '#fd7e14';
                    if (score > 25) return '#ffc107';
                    return '#28a745';
                },
                circumference: 180,
                rotation: 270
            }],
            labels: ['Risk Score']
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                title: {
                    display: true,
                    text: 'Security Risk Score'
                }
            }
        }
    };

    const threatTrendsConfig = {
        data: metrics?.threatTrends ? {
            labels: metrics.threatTrends.dates,
            datasets: [
                {
                    label: 'High Severity',
                    data: metrics.threatTrends.high,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    fill: true
                },
                {
                    label: 'Medium Severity',
                    data: metrics.threatTrends.medium,
                    borderColor: '#fd7e14',
                    backgroundColor: 'rgba(253, 126, 20, 0.1)',
                    fill: true
                }
            ]
        } : null,
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Threat Trends'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    stacked: true
                }
            }
        }
    };

    const securityPostureConfig = {
        data: metrics?.securityPosture ? {
            labels: [
                'Access Control',
                'Authentication',
                'Data Protection',
                'Monitoring',
                'Incident Response',
                'Compliance'
            ],
            datasets: [{
                label: 'Current Posture',
                data: [
                    metrics.securityPosture.accessControl,
                    metrics.securityPosture.authentication,
                    metrics.securityPosture.dataProtection,
                    metrics.securityPosture.monitoring,
                    metrics.securityPosture.incidentResponse,
                    metrics.securityPosture.compliance
                ],
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                borderColor: 'rgb(54, 162, 235)',
                pointBackgroundColor: 'rgb(54, 162, 235)',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgb(54, 162, 235)'
            }]
        } : null,
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Security Posture Assessment'
                }
            },
            scales: {
                r: {
                    min: 0,
                    max: 100,
                    beginAtZero: true
                }
            }
        }
    };

    const complianceScoreConfig = {
        data: metrics?.compliance ? {
            labels: Object.keys(metrics.compliance),
            datasets: [{
                data: Object.values(metrics.compliance),
                backgroundColor: [
                    '#28a745',
                    '#17a2b8',
                    '#ffc107',
                    '#dc3545'
                ]
            }]
        } : null,
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Compliance Scores'
                }
            }
        }
    };

    if (loading) return <div>Loading security metrics...</div>;

    return (
        <div className="security-metrics">
            <div className="metrics-controls">
                <select 
                    value={timeRange} 
                    onChange={(e) => setTimeRange(e.target.value)}
                    className="time-range-select"
                >
                    <option value="1d">Last 24 Hours</option>
                    <option value="7d">Last 7 Days</option>
                    <option value="30d">Last 30 Days</option>
                    <option value="90d">Last 90 Days</option>
                </select>
            </div>

            <div className="metrics-grid">
                <div className="metric-card risk-score">
                    <Doughnut {...riskScoreConfig} />
                    <div className="risk-score-label">
                        <h3>{metrics?.riskScore ?? 0}/100</h3>
                        <p>Current Risk Level</p>
                    </div>
                </div>

                <div className="metric-card threat-trends">
                    {threatTrendsConfig.data && (
                        <Line {...threatTrendsConfig} />
                    )}
                </div>

                <div className="metric-card security-posture">
                    {securityPostureConfig.data && (
                        <Radar {...securityPostureConfig} />
                    )}
                </div>

                <div className="metric-card compliance">
                    {complianceScoreConfig.data && (
                        <Doughnut {...complianceScoreConfig} />
                    )}
                </div>
            </div>

            <div className="metrics-summary">
                <h3>Key Findings</h3>
                <ul>
                    {metrics?.findings?.map((finding, index) => (
                        <li key={index} className={`finding-${finding.severity}`}>
                            <span className="finding-severity">{finding.severity}</span>
                            <span className="finding-message">{finding.message}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
};

export default SecurityMetrics;