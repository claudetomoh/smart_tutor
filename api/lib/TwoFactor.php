<?php

declare(strict_types=1);

class TwoFactor {
    private const ALGORITHM = 'sha1';
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const SECRET_LENGTH = 20;

    public static function generateSecret(): string {
        $secret = random_bytes(self::SECRET_LENGTH);
        return base32_encode($secret);
    }

    public static function generateQRCode(string $secret, string $email, string $issuer = 'SmartTutor'): string {
        $label = rawurlencode($issuer . ':' . $email);
        $parameters = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => self::ALGORITHM,
            'digits' => self::DIGITS,
            'period' => self::PERIOD
        ]);
        
        return "otpauth://totp/{$label}?{$parameters}";
    }

    public static function verifyCode(string $secret, string $code): bool {
        $timeSlice = floor(time() / self::PERIOD);
        
        // Check current time slice and one before/after to account for clock drift
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(
                self::generateCode($secret, $timeSlice + $i),
                $code
            )) {
                return true;
            }
        }
        
        return false;
    }

    public static function generateBackupCodes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    private static function generateCode(string $secret, int $timeSlice): string {
        $secretKey = base32_decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac(self::ALGORITHM, $time, $secretKey, true);
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % pow(10, self::DIGITS);
        
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }
}