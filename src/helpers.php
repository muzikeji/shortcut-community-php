<?php

function env(string $key, string $default = ''): string {
    $value = $_SERVER[$key] ?? null;
    if ($value !== null) {
        return $value;
    }
    if (function_exists('getenv')) {
        $value = getenv($key);
        if ($value !== false && $value !== null) {
            return $value;
        }
    }
    return $default;
}

function setenv(string $key, string $value): void {
    $_SERVER[$key] = $value;
    if (function_exists('putenv')) {
        putenv("{$key}={$value}");
    }
}
