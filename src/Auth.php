<?php
namespace Shortcut;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    private static string $secret = 'shortcut-community-jwt-secret-change-me';

    public static function setSecret(string $secret): void {
        self::$secret = $secret;
    }

    public static function generateToken(array $user): string {
        $payload = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'] ?? 'user',
            'iat' => time(),
            'exp' => time() + 86400 * 30,
        ];
        return JWT::encode($payload, self::$secret, 'HS256');
    }

    public static function verifyToken(string $token): ?array {
        try {
            $decoded = JWT::decode($token, new Key(self::$secret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function getTokenFromHeader(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function requireAuth(): ?array {
        $token = self::getTokenFromHeader();
        if (!$token) return null;
        return self::verifyToken($token);
    }

    public static function optionalAuth(): ?array {
        return self::requireAuth();
    }

    public static function requireAdmin(): ?array {
        $user = self::requireAuth();
        if (!$user || !in_array(($user['role'] ?? ''), ['admin', 'owner'])) return null;
        return $user;
    }

    public static function requireOwner(): ?array {
        $user = self::requireAuth();
        if (!$user || ($user['role'] ?? '') !== 'owner') return null;
        return $user;
    }
}
