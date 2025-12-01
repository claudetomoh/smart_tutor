import React, { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import SecurityNotifications from './SecurityNotifications';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js';
import { Line, Bar } from 'react-chartjs-2';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend
);

const SecurityDashboard = () => {
    const { token } = useAuth();
    const [securityEvents, setSecurityEvents] = useState([]);
    const [stats, setStats] = useState({
        totalEvents: 0,
        failedLogins: 0,
        suspicious: 0,
        lockedAccounts: 0
    });
    const [loading, setLoading] = useState(true);
    const [timeframe, setTimeframe] = useState('24h');
    const [eventChart, setEventChart] = useState(null);
    const [locationChart, setLocationChart] = useState(null);

    useEffect(() => {
        fetchSecurityData();
        const interval = setInterval(fetchSecurityData, 60000); // Refresh every minute
        return () => clearInterval(interval);
    }, [timeframe]);

    const fetchSecurityData = async () => {
        try {
            const response = await fetch('/api/security/events?' + new URLSearchParams({
                timeframe: timeframe
            }), {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (!response.ok) throw new Error('Failed to fetch security data');
            
            const data = await response.json();
            setSecurityEvents(data.events);
            setStats(data.stats);
            
            // Process chart data
            processChartData(data.events);
        } catch (error) {
            console.error('Error fetching security data:', error);
        } finally {
            setLoading(false);
        }
    };

    const processChartData = (events) => {
        // Process events for time-based chart
        const timeData = {};
        const locationData = {};
        
        events.forEach(event => {
            const hour = new Date(event.created_at).getHours();
            timeData[hour] = (timeData[hour] || 0) + 1;
            
            if (event.location) {
                locationData[event.location] = (locationData[event.location] || 0) + 1;
            }
        });
        
        setEventChart({
            labels: Object.keys(timeData),
            datasets: [{
                label: 'Security Events',
                data: Object.values(timeData),
                borderColor: 'rgb(75, 192, 192)',
                tension: 0.1
            }]
        });
        
        setLocationChart({
            labels: Object.keys(locationData),
            datasets: [{
                label: 'Events by Location',
                data: Object.values(locationData),
                backgroundColor: 'rgba(53, 162, 235, 0.5)',
            }]
        });
    };

    const getEventSeverityClass = (eventType) => {
        const severityMap = {
            'login_failed': 'warning',
            'account_locked': 'danger',
            'suspicious_ip': 'danger',
            'two_factor_disabled': 'warning',
            'password_reset': 'info'
        };
        return severityMap[eventType] || 'info';
    };

    if (loading) return <div>Loading security dashboard...</div>;

    return (
        <div className="security-dashboard">
            <h2>Security Dashboard</h2>
            
            {/* Stats Overview */}
            <div className="stats-grid">
                <div className="stat-card">
                    <h3>Total Events</h3>
                    <p className="stat-number">{stats.totalEvents}</p>
                </div>
                <div className="stat-card warning">
                    <h3>Failed Logins</h3>
                    <p className="stat-number">{stats.failedLogins}</p>
                </div>
                <div className="stat-card danger">
                    <h3>Suspicious Activities</h3>
                    <p className="stat-number">{stats.suspicious}</p>
                </div>
                <div className="stat-card danger">
                    <h3>Locked Accounts</h3>
                    <p className="stat-number">{stats.lockedAccounts}</p>
                </div>
            </div>

            {/* Time Range Selector */}
            <div className="timeframe-selector">
                <select 
                    value={timeframe} 
                    onChange={(e) => setTimeframe(e.target.value)}
                >
                    <option value="1h">Last Hour</option>
                    <option value="24h">Last 24 Hours</option>
                    <option value="7d">Last 7 Days</option>
                    <option value="30d">Last 30 Days</option>
                </select>
            </div>

            {/* Charts */}
            <div className="charts-grid">
                <div className="chart-card">
                    <h3>Events Over Time</h3>
                    {eventChart && <Line data={eventChart} />}
                </div>
                <div className="chart-card">
                    <h3>Events by Location</h3>
                    {locationChart && <Bar data={locationChart} />}
                </div>
            </div>

            {/* Real-time Notifications */}
            <SecurityNotifications />

            {/* Recent Events Table */}
            <div className="recent-events">
                <h3>Recent Security Events</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event Type</th>
                            <th>User</th>
                            <th>IP Address</th>
                            <th>Location</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        {securityEvents.map(event => (
                            <tr key={event.id} className={getEventSeverityClass(event.event_type)}>
                                <td>{new Date(event.created_at).toLocaleString()}</td>
                                <td>{event.event_type}</td>
                                <td>{event.user_email || 'N/A'}</td>
                                <td>{event.ip_address}</td>
                                <td>{event.location || 'Unknown'}</td>
                                <td>{event.description}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default SecurityDashboard;