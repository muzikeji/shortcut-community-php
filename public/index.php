<?php
define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/vendor/autoload.php';

use Shortcut\{Database, Auth, Response};

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$path = '/' . trim(substr($uri, strlen($base)), '/');

Database::init(ROOT_DIR);

if (strpos($path, '/api/') === 0) {
    routeApi(trim(substr($path, 4), '/'));
} elseif (strpos($path, '/uploads/') === 0) {
    serveUpload($path);
} elseif ($path === '/install.php' || $path === '/install') {
    require ROOT_DIR . '/public/install.php';
} else {
    serveStatic($path);
}

function routeApi(string $path): void {
    $parts = explode('/', $path);
    $method = $_SERVER['REQUEST_METHOD'];

    // Users & Auth
    if ($parts[0] === 'users') {
        require_once ROOT_DIR . '/src/routes/users.php';
        if ($method === 'POST' && !isset($parts[1])) {
            \Shortcut\Routes\registerUser();
        } elseif ($method === 'POST' && ($parts[1] ?? '') === 'register') {
            \Shortcut\Routes\registerUser();
        } elseif ($method === 'POST' && ($parts[1] ?? '') === 'login') {
            \Shortcut\Routes\loginUser();
        } elseif ($method === 'GET' && ($parts[1] ?? '') === 'me') {
            \Shortcut\Routes\getCurrentUser();
        } elseif ($method === 'PUT' && ($parts[1] ?? '') === 'profile') {
            \Shortcut\Routes\updateProfile();
        } elseif ($method === 'PUT' && ($parts[1] ?? '') === 'password') {
            \Shortcut\Routes\updatePassword();
        } elseif ($method === 'POST' && ($parts[1] ?? '') === 'avatar') {
            \Shortcut\Routes\uploadAvatar();
        } elseif ($method === 'GET' && isset($parts[1])) {
            \Shortcut\Routes\getUserById($parts[1]);
        } else {
            Response::notFound();
        }
        return;
    }

    // Auth alias (backward compat)
    if ($parts[0] === 'auth') {
        require_once ROOT_DIR . '/src/routes/users.php';
        if ($method === 'POST' && ($parts[1] ?? '') === 'register') {
            \Shortcut\Routes\registerUser();
        } elseif ($method === 'POST' && ($parts[1] ?? '') === 'login') {
            \Shortcut\Routes\loginUser();
        } elseif ($method === 'GET' && ($parts[1] ?? '') === 'me') {
            \Shortcut\Routes\getCurrentUser();
        } else {
            Response::notFound();
        }
        return;
    }

    // Shortcuts (including interact endpoints)
    if ($parts[0] === 'shortcuts') {
        require_once ROOT_DIR . '/src/routes/shortcuts.php';
        require_once ROOT_DIR . '/src/routes/interact.php';

        if ($method === 'GET' && !isset($parts[1])) {
            \Shortcut\Routes\getShortcuts();
        } elseif ($method === 'POST' && ($parts[1] ?? '') === 'fetch-name') {
            \Shortcut\Routes\fetchName();
        } elseif ($method === 'POST' && !isset($parts[1])) {
            \Shortcut\Routes\createShortcut();
        } elseif ($method === 'GET' && isset($parts[1]) && ($parts[2] ?? '') === 'download') {
            \Shortcut\Routes\downloadShortcut($parts[1]);
        } elseif ($method === 'GET' && isset($parts[1]) && ($parts[2] ?? '') === 'similar') {
            \Shortcut\Routes\getSimilar($parts[1]);
        } elseif ($method === 'GET' && isset($parts[1]) && ($parts[2] ?? '') === 'versions') {
            \Shortcut\Routes\getVersions($parts[1]);
        } elseif ($method === 'POST' && isset($parts[1]) && ($parts[2] ?? '') === 'versions') {
            \Shortcut\Routes\addVersion($parts[1]);
        } elseif ($method === 'POST' && isset($parts[1]) && in_array(($parts[2] ?? ''), ['refresh', 'refresh-stats'])) {
            \Shortcut\Routes\refreshStats($parts[1]);
        } elseif ($method === 'POST' && isset($parts[1]) && ($parts[2] ?? '') === 'like') {
            \Shortcut\Routes\likeShortcut($parts[1]);
        } elseif ($method === 'GET' && isset($parts[1]) && ($parts[2] ?? '') === 'comments') {
            \Shortcut\Routes\getComments($parts[1]);
        } elseif ($method === 'POST' && isset($parts[1]) && ($parts[2] ?? '') === 'comments') {
            \Shortcut\Routes\addComment($parts[1]);
        } elseif ($method === 'DELETE' && isset($parts[1]) && ($parts[2] ?? '') === 'comments' && isset($parts[3])) {
            \Shortcut\Routes\deleteComment((int) $parts[3]);
        } elseif ($method === 'PUT' && isset($parts[1]) && ($parts[2] ?? '') === 'restore') {
            \Shortcut\Routes\restoreShortcut($parts[1]);
        } elseif ($method === 'PUT' && isset($parts[1]) && ($parts[2] ?? '') === 'remove') {
            \Shortcut\Routes\removeShortcut($parts[1]);
        } elseif ($method === 'PUT' && isset($parts[1])) {
            \Shortcut\Routes\updateShortcut($parts[1]);
        } elseif ($method === 'PATCH' && isset($parts[1])) {
            \Shortcut\Routes\updateShortcut($parts[1]);
        } elseif ($method === 'DELETE' && isset($parts[1])) {
            \Shortcut\Routes\deleteShortcut($parts[1]);
        } elseif ($method === 'GET' && isset($parts[1])) {
            \Shortcut\Routes\getShortcutByIdOrSlug($parts[1]);
        } else {
            Response::notFound();
        }
        return;
    }

    // Interact backward compat
    if ($parts[0] === 'interact') {
        require_once ROOT_DIR . '/src/routes/interact.php';
        if ($method === 'POST' && isset($parts[1]) && ($parts[2] ?? '') === 'like') {
            \Shortcut\Routes\likeShortcut($parts[1]);
        } elseif ($method === 'GET' && isset($parts[1]) && ($parts[2] ?? '') === 'comments') {
            \Shortcut\Routes\getComments($parts[1]);
        } elseif ($method === 'POST' && isset($parts[1]) && ($parts[2] ?? '') === 'comments') {
            \Shortcut\Routes\addComment($parts[1]);
        } elseif ($method === 'DELETE' && ($parts[1] ?? '') === 'comments' && isset($parts[2])) {
            \Shortcut\Routes\deleteComment((int) $parts[2]);
        } else {
            Response::notFound();
        }
        return;
    }

    // Admin
    if ($parts[0] === 'admin') {
        require_once ROOT_DIR . '/src/routes/admin.php';
        if ($method === 'GET' && ($parts[1] ?? '') === 'dashboard') {
            \Shortcut\Routes\getDashboard();
        } elseif ($method === 'GET' && ($parts[1] ?? '') === 'users' && !isset($parts[2])) {
            \Shortcut\Routes\getUsers();
        } elseif ($method === 'POST' && ($parts[1] ?? '') === 'users') {
            \Shortcut\Routes\adminCreateUser();
        } elseif ($method === 'PUT' && ($parts[1] ?? '') === 'users' && isset($parts[2]) && ($parts[3] ?? '') === 'role') {
            \Shortcut\Routes\updateUserRole((int) $parts[2]);
        } elseif ($method === 'PUT' && ($parts[1] ?? '') === 'users' && isset($parts[2]) && ($parts[3] ?? '') === 'ban') {
            \Shortcut\Routes\banUser((int) $parts[2]);
        } elseif ($method === 'PUT' && ($parts[1] ?? '') === 'users' && isset($parts[2]) && ($parts[3] ?? '') === 'unban') {
            \Shortcut\Routes\unbanUser((int) $parts[2]);
        } elseif ($method === 'GET' && ($parts[1] ?? '') === 'shortcuts') {
            \Shortcut\Routes\getAllShortcuts();
        } elseif ($method === 'DELETE' && ($parts[1] ?? '') === 'shortcuts' && isset($parts[2])) {
            \Shortcut\Routes\deleteShortcut((int) $parts[2]);
        } else {
            Response::notFound();
        }
        return;
    }

    // Settings
    if ($parts[0] === 'settings') {
        require_once ROOT_DIR . '/src/routes/settings.php';
        if ($method === 'GET') {
            \Shortcut\Routes\getPublicSettings();
        } elseif ($method === 'PUT') {
            \Shortcut\Routes\updateSetting();
        } else {
            Response::notFound();
        }
        return;
    }

    // Update
    if ($parts[0] === 'update') {
        require_once ROOT_DIR . '/src/routes/update.php';
        if ($method === 'GET' && ($parts[1] ?? '') === 'check') {
            \Shortcut\Routes\checkUpdate();
        } elseif ($method === 'POST' && ($parts[1] ?? '') === 'run') {
            \Shortcut\Routes\doUpdate();
        } else {
            Response::notFound();
        }
        return;
    }

    // Version (public debug)
    if ($parts[0] === 'version') {
        require_once ROOT_DIR . '/src/routes/update.php';
        \Shortcut\Routes\getVersion();
        return;
    }

    Response::notFound();
}

function serveUpload(string $path): void {
    $file = ROOT_DIR . $path;
    if (!file_exists($file) || !is_file($file)) {
        Response::notFound();
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeTypes = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    ];
    header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

function serveStatic(string $path): void {
    $frontendDir = ROOT_DIR . '/frontend';
    $file = $path === '/' ? '/index.html' : $path;

    $candidate = $frontendDir . $file;
    if (file_exists($candidate) && is_file($candidate)) {
        $mimeTypes = [
            'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
            'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        ];
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        readfile($candidate);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    readfile($frontendDir . '/index.html');
}
