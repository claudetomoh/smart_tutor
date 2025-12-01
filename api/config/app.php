<?php

declare(strict_types=1);

return [
    'jwt_secret' => getenv('JWT_SECRET') ?: 'change-me-in-env',
    'ws_url' => getenv('WS_URL') ?: 'ws://localhost:8080',
    'realtime_bridge_url' => getenv('REALTIME_BRIDGE_URL') ?: 'http://localhost:8090/notify/messages',
    'realtime_bridge_secret' => getenv('REALTIME_BRIDGE_SECRET') ?: 'change-bridge-secret',
];
