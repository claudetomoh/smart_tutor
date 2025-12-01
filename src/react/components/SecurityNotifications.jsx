import React, { useEffect, useState } from 'react';
import { useAuth } from '../auth/AuthContext';

const SecurityNotifications = () => {
    const { token } = useAuth();
    const [notifications, setNotifications] = useState([]);
    const [wsConnection, setWsConnection] = useState(null);

    useEffect(() => {
        const ws = new WebSocket('ws://localhost:8080');
        
        ws.onopen = () => {
            console.log('Connected to security notifications');
            // Authenticate the connection
            ws.send(JSON.stringify({
                type: 'authenticate',
                token: token
            }));
        };

        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            
            switch (data.type) {
                case 'auth_success':
                    // Subscribe to security alerts
                    ws.send(JSON.stringify({
                        type: 'subscribe',
                        channels: ['security_alerts']
                    }));
                    break;
                
                case 'security_alert':
                    handleNewAlert(data.data);
                    break;
            }
        };

        ws.onerror = (error) => {
            console.error('WebSocket error:', error);
        };

        setWsConnection(ws);

        return () => {
            if (ws) {
                ws.close();
            }
        };
    }, [token]);

    const handleNewAlert = (alert) => {
        // Add new alert to the list
        setNotifications(prev => [{
            ...alert,
            id: alert.id || Date.now(),
            isNew: true
        }, ...prev].slice(0, 50)); // Keep last 50 notifications

        // Show browser notification for critical alerts
        if (alert.threatLevel === 'critical' || alert.threatLevel === 'high') {
            showBrowserNotification(alert);
        }

        // Play sound for critical alerts
        if (alert.threatLevel === 'critical') {
            playAlertSound();
        }
    };

    const showBrowserNotification = (alert) => {
        if (!("Notification" in window)) return;

        if (Notification.permission === "granted") {
            new Notification("Security Alert", {
                body: alert.message,
                icon: "/images/security-alert.png",
                tag: alert.id
            });
        } else if (Notification.permission !== "denied") {
            Notification.requestPermission().then(permission => {
                if (permission === "granted") {
                    showBrowserNotification(alert);
                }
            });
        }
    };

    const playAlertSound = () => {
        const audio = new Audio('/sounds/alert.mp3');
        audio.play().catch(e => console.log('Error playing alert sound:', e));
    };

    const markAsRead = (notificationId) => {
        setNotifications(prev =>
            prev.map(notif =>
                notif.id === notificationId
                    ? { ...notif, isNew: false }
                    : notif
            )
        );
    };

    const getNotificationStyle = (threatLevel) => {
        const baseStyle = {
            padding: '15px',
            marginBottom: '10px',
            borderRadius: '4px',
            boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
            animation: 'none'
        };

        const styles = {
            critical: {
                ...baseStyle,
                backgroundColor: '#dc3545',
                color: 'white',
                borderLeft: '5px solid #721c24'
            },
            high: {
                ...baseStyle,
                backgroundColor: '#fd7e14',
                color: 'white',
                borderLeft: '5px solid #862e1b'
            },
            medium: {
                ...baseStyle,
                backgroundColor: '#ffc107',
                color: 'black',
                borderLeft: '5px solid #876404'
            },
            low: {
                ...baseStyle,
                backgroundColor: '#28a745',
                color: 'white',
                borderLeft: '5px solid #165724'
            }
        };

        return {
            ...styles[threatLevel],
            animation: 'fadeIn 0.5s ease-in-out'
        };
    };

    return (
        <div className="security-notifications">
            <h3>Real-time Security Alerts</h3>
            <div className="notifications-container">
                {notifications.map(notification => (
                    <div
                        key={notification.id}
                        className={`notification ${notification.isNew ? 'new' : ''}`}
                        style={getNotificationStyle(notification.threatLevel)}
                        onClick={() => markAsRead(notification.id)}
                    >
                        <div className="notification-header">
                            <span className="notification-type">{notification.type}</span>
                            <span className="notification-time">
                                {new Date(notification.timestamp * 1000).toLocaleTimeString()}
                            </span>
                        </div>
                        <div className="notification-message">{notification.message}</div>
                        {notification.details && (
                            <div className="notification-details">
                                <small>
                                    IP: {notification.details.ip_address}<br />
                                    Location: {notification.details.location || 'Unknown'}
                                </small>
                            </div>
                        )}
                    </div>
                ))}
                {notifications.length === 0 && (
                    <div className="no-notifications">
                        No security alerts at this time
                    </div>
                )}
            </div>
        </div>
    );
};

export default SecurityNotifications;