const http = require('http');
const WebSocket = require('ws');
const jwt = require('jsonwebtoken');

class SecurityWebSocketServer {
    constructor(port = 8080) {
        this.wss = new WebSocket.Server({ port });
        this.clients = new Map(); // Map WebSocket connections to user data
        this.bridgeSecret = process.env.REALTIME_BRIDGE_SECRET || 'change-bridge-secret';
        this.bridgePort = process.env.REALTIME_BRIDGE_PORT || 8090;

        this.wss.on('connection', this.handleConnection.bind(this));
        console.log(`WebSocket server started on port ${port}`);

        this.startBridgeServer();
    }

    handleConnection(ws, req) {
        console.log('New client connected');

        ws.on('message', async(message) => {
            try {
                const data = JSON.parse(message);

                switch (data.type) {
                    case 'authenticate':
                        this.handleAuthentication(ws, data.token);
                        break;
                    case 'subscribe':
                        this.handleSubscription(ws, data.channels);
                        break;
                    case 'security_alert':
                        this.broadcastSecurityAlert(data.data);
                        break;
                    case 'message_event':
                        this.handleMessageEvent(ws, data);
                        break;
                }
            } catch (error) {
                console.error('Error processing message:', error);
                ws.send(JSON.stringify({
                    type: 'error',
                    message: 'Invalid message format'
                }));
            }
        });

        ws.on('close', () => {
            this.clients.delete(ws);
            console.log('Client disconnected');
        });

        // Send initial connection acknowledgment
        ws.send(JSON.stringify({
            type: 'connection',
            message: 'Connected to security notification server'
        }));
    }

    startBridgeServer() {
        this.bridgeServer = http.createServer((req, res) => {
            if (req.method !== 'POST' || req.url !== '/notify/messages') {
                res.statusCode = 404;
                res.end('Not found');
                return;
            }

            let body = '';
            req.on('data', (chunk) => {
                body += chunk;
            });

            req.on('end', () => {
                try {
                    const payload = JSON.parse(body || '{}');
                    if (!payload.secret || payload.secret !== this.bridgeSecret) {
                        res.statusCode = 401;
                        res.end('Unauthorized');
                        return;
                    }

                    this.handleBridgeMessage(payload);
                    res.statusCode = 200;
                    res.end('OK');
                } catch (error) {
                    console.error('Bridge request error:', error);
                    res.statusCode = 400;
                    res.end('Invalid payload');
                }
            });
        });

        this.bridgeServer.listen(this.bridgePort, () => {
            console.log(`Bridge HTTP server listening on port ${this.bridgePort}`);
        });
    }

    async handleAuthentication(ws, token) {
        try {
            // Verify JWT token (use same secret as API)
            const decoded = jwt.verify(token, process.env.JWT_SECRET);

            this.clients.set(ws, {
                userId: decoded.sub,
                role: decoded.role,
                subscriptions: []
            });

            ws.send(JSON.stringify({
                type: 'auth_success',
                message: 'Authentication successful'
            }));
        } catch (error) {
            ws.send(JSON.stringify({
                type: 'auth_error',
                message: 'Authentication failed'
            }));
            ws.close();
        }
    }

    handleSubscription(ws, channels) {
        const client = this.clients.get(ws);
        if (!client) return;

        client.subscriptions = channels;
        this.clients.set(ws, client);

        ws.send(JSON.stringify({
            type: 'subscription_update',
            channels: channels
        }));
    }

    handleMessageEvent(ws, payload) {
        const client = this.clients.get(ws);
        if (!client) {
            return;
        }

        this.dispatchMessagePayload(payload, client.userId, client.role);
    }

    handleBridgeMessage(payload) {
        const senderId = parseInt(payload.sender_id, 10) || 0;
        const senderRole = payload.sender_role || 'system';
        this.dispatchMessagePayload(payload, senderId, senderRole);
    }

    dispatchMessagePayload(payload, senderId, senderRole) {
        const recipients = Array.isArray(payload.recipients) ?
            payload.recipients.map((id) => parseInt(id, 10)).filter((id) => Number.isInteger(id) && id > 0) :
            [];

        if (recipients.length === 0) {
            return;
        }

        const message = {
            type: 'message_notification',
            thread_id: payload.threadId || payload.thread_id || null,
            preview: payload.preview || '',
            sender_id: senderId,
            sender_role: senderRole,
            created_at: new Date().toISOString(),
            metadata: payload.metadata || {}
        };

        this.sendToUsers(recipients, message);
    }

    broadcastSecurityAlert(alert) {
        const criticalEvents = new Set([
            'brute_force_attempt',
            'suspicious_ip',
            'account_locked',
            'admin_action'
        ]);

        this.clients.forEach((client, ws) => {
            // Only send to authenticated admin users
            if (client.role === 'admin') {
                // For critical events, send regardless of subscription
                if (criticalEvents.has(alert.type) ||
                    client.subscriptions.includes('security_alerts')) {
                    ws.send(JSON.stringify({
                        type: 'security_alert',
                        data: alert
                    }));
                }
            }
        });
    }

    broadcastToRole(role, message) {
        this.clients.forEach((client, ws) => {
            if (client.role === role) {
                ws.send(JSON.stringify(message));
            }
        });
    }

    sendToUsers(userIds, message) {
        this.clients.forEach((client, ws) => {
            if (userIds.includes(client.userId)) {
                ws.send(JSON.stringify(message));
            }
        });
    }
}

// Start the server
const server = new SecurityWebSocketServer(process.env.WS_PORT || 8080);