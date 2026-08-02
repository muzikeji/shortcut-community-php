<?php
namespace Shortcut;

class Response {
    public static function json($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $code = 400): void {
        self::json(['error' => $message], $code);
    }

    public static function notFound(): void {
        self::error('不存在', 404);
    }

    public static function forbidden(): void {
        self::error('无权访问', 403);
    }

    public static function unauthorized(): void {
        self::error('请先登录', 401);
    }
}
