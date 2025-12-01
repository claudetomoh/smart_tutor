<?php

declare(strict_types=1);

final class RealtimeNotifier
{
    public static function sendMessageEvent(array $recipientIds, int $threadId, string $preview, int $senderId): void
    {
        $endpoint = self::getBridgeUrl();
        $secret = self::getBridgeSecret();
        $targets = array_values(array_unique(array_map('intval', $recipientIds)));

        if ($endpoint === '' || $secret === '' || count($targets) === 0 || $threadId <= 0) {
            return;
        }

        $payload = [
            'secret' => $secret,
            'recipients' => $targets,
            'thread_id' => $threadId,
            'preview' => self::truncatePreview($preview),
            'sender_id' => $senderId,
        ];

        self::postJson($endpoint, $payload);
    }

    private static function truncatePreview(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($trimmed, 0, 180);
        }

        return substr($trimmed, 0, 180);
    }

    private static function getBridgeUrl(): string
    {
        $value = getenv('REALTIME_BRIDGE_URL');
        if (!empty($value)) {
            return $value;
        }

        return self::configValue('realtime_bridge_url');
    }

    private static function getBridgeSecret(): string
    {
        $value = getenv('REALTIME_BRIDGE_SECRET');
        if (!empty($value)) {
            return $value;
        }

        return self::configValue('realtime_bridge_secret');
    }

    private static function configValue(string $key): string
    {
        static $config;

        if (!is_array($config)) {
            $configPath = __DIR__ . '/../config/app.php';
            if (file_exists($configPath)) {
                /** @phpstan-ignore-next-line */
                $loaded = require $configPath;
                if (is_array($loaded)) {
                    $config = $loaded;
                }
            }

            if (!is_array($config)) {
                $config = [];
            }
        }

        $value = $config[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    private static function postJson(string $endpoint, array $payload): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => 2,
            ],
        ]);

        @file_get_contents($endpoint, false, $context);
    }
}
