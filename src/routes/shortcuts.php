<?php
namespace Shortcut\Routes;

use Shortcut\{Database, Auth, Response, PlistParser};
use PDO;

function fetchShortcutMeta(string $url): ?array {
    if (!preg_match('#/shortcuts/([a-zA-Z0-9]+)#', $url, $m)) return null;
    $id = $m[1];
    try {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15\r\n",
            ],
        ]);
        $data = file_get_contents("https://www.icloud.com/shortcuts/api/records/{$id}?locale=zh_CN", false, $ctx);
        if (!$data) return null;
        $json = json_decode($data, true);
        $fields = $json['fields'] ?? [];
        $asset = $fields['shortcut']['value'] ?? [];
        $colorHex = decodeIconColor($fields['icon_color']['value'] ?? null);
        return [
            'name' => trim($fields['name']['value'] ?? ''),
            'color' => $colorHex,
            'shortcutUrl' => $asset['downloadURL'] ?? null,
            'shortcutSize' => $asset['size'] ?? 0,
        ];
    } catch (\Exception $e) {
        return null;
    }
}

function decodeIconColor($raw): string {
    if ($raw === null) return '';
    $num = is_int($raw) ? $raw : hexdec(ltrim((string) $raw, '0x'));
    return '#' . str_pad(dechex(($num >> 8) & 0xFFFFFF), 6, '0', STR_PAD_LEFT);
}

function isValidShortcutUrl(string $url): bool {
    return (bool) preg_match('#^https?://(www\.)?icloud\.com/shortcuts/[a-zA-Z0-9]+$#', $url);
}

function getShortcuts(): void {
    $db = Database::get();
    $authUser = Auth::optionalAuth();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = mb_substr(trim($_GET['search'] ?? ''), 0, 100);
    $sort = $_GET['sort'] ?? '';
    $userId = $_GET['userId'] ?? '';
    $includeRemoved = !empty($_GET['includeRemoved']);
    $status = $_GET['status'] ?? '';

    $where = [];
    $params = [];

    if ($status && $userId && $authUser && (int) $userId === $authUser['id']) {
        $where[] = 's.status = ?';
        $params[] = $status;
    } elseif (!$includeRemoved) {
        $where[] = "s.status = 'active'";
        $where[] = "(u.banned IS NULL OR u.banned = 0)";
    }
    if ($search) {
        $where[] = '(s.title LIKE ? OR s.description LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    if ($userId) {
        $where[] = 's.user_id = ?';
        $params[] = (int) $userId;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $orderBy = 'ORDER BY s.created_at DESC';
    if ($sort === 'likes') $orderBy = 'ORDER BY s.like_count DESC';
    if ($sort === 'downloads') $orderBy = 'ORDER BY s.download_count DESC';

    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['total'];

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare("
        SELECT s.*, u.username, u.avatar
        FROM shortcuts s
        LEFT JOIN users u ON s.user_id = u.id
        {$whereClause}
        {$orderBy}
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $shortcuts = $stmt->fetchAll();

    $likedIds = [];
    if ($authUser && $shortcuts) {
        $ids = array_column($shortcuts, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $likeStmt = $db->prepare("SELECT shortcut_id FROM likes WHERE user_id = ? AND shortcut_id IN ({$placeholders})");
        $likeStmt->execute(array_merge([$authUser['id']], $ids));
        $likedIds = array_column($likeStmt->fetchAll(), 'shortcut_id');
    }

    $result = array_map(function($s) use ($likedIds) {
        $s['liked'] = in_array($s['id'], $likedIds);
        return $s;
    }, $shortcuts);

    Response::json([
        'shortcuts' => $result,
        'total' => $total,
        'totalPages' => max(1, (int) ceil($total / $limit)),
    ]);
}

function getShortcutByIdOrSlug(string $idOrSlug): void {
    $db = Database::get();

    $s = findShortcutWithUser($db, $idOrSlug);
    if (!$s) Response::notFound();

    $authUser = Auth::optionalAuth();
    $s['liked'] = false;
    if ($authUser) {
        $like = $db->prepare('SELECT id FROM likes WHERE shortcut_id = ? AND user_id = ?');
        $like->execute([$s['id'], $authUser['id']]);
        $s['liked'] = (bool) $like->fetch();
    }

    Response::json(['shortcut' => $s]);
}

function findShortcutWithUser(PDO $db, string $value): ?array {
    if (is_numeric($value)) {
        $stmt = $db->prepare('SELECT s.*, u.username, u.avatar FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?');
        $stmt->execute([(int) $value]);
        $s = $stmt->fetch();
        if ($s) return $s;
    }
    $stmt = $db->prepare('SELECT s.*, u.username, u.avatar FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id WHERE s.slug = ?');
    $stmt->execute([$value]);
    return $stmt->fetch() ?: null;
}

function fetchName(): void {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) Response::error('无效的请求数据', 400);
    $url = $body['url'] ?? '';
    $title = trim($body['title'] ?? '');
    $description = trim($body['description'] ?? '');
    $category = trim($body['category'] ?? '');
    $slug = trim($body['slug'] ?? '');
    $color = $body['color'] ?? '';

    if (!$url) Response::error('请提供快捷指令链接');
    if (!isValidShortcutUrl($url)) Response::error('请输入有效的 iCloud 快捷指令链接');

    $db = Database::get();

    $exist = $db->prepare('SELECT id FROM shortcuts WHERE file_url = ?');
    $exist->execute([$url]);
    if ($exist->fetch()) Response::error('该快捷指令已被分享');

    $finalSlug = $slug ?: (string) time();
    if (!preg_match('/^[a-z0-9]+$/i', $finalSlug)) Response::error('无效的快捷指令标识');
    $slugExist = $db->prepare('SELECT id FROM shortcuts WHERE slug = ?');
    $slugExist->execute([$finalSlug]);
    if ($slugExist->fetch()) Response::error('该标识已被使用，请重试');

    $meta = fetchShortcutMeta($url);
    $stats = null;
    if ($meta && $meta['shortcutUrl']) {
        $stats = PlistParser::parseShortcutInfo($meta['shortcutUrl']);
    }
    $statsJson = $stats ? json_encode($stats) : '';

    $finalColor = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : ($meta['color'] ?? '');

    $finalTitle = $meta['name'] ?: $title;
    if (!$finalTitle) Response::error('无法获取快捷指令名称，请手动填写');

    $isAdmin = ($authUser['role'] ?? '') === 'admin';
    $status = $isAdmin ? 'active' : 'pending';

    $db->prepare('INSERT INTO shortcuts (slug, title, description, category, file_url, file_size, user_id, color, stats, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $finalSlug,
        $finalTitle,
        $description,
        $category ?: '其他',
        $url,
        $stats['size'] ?? 0,
        $authUser['id'],
        $finalColor,
        $statsJson,
        $status,
    ]);

    $shortcutId = $db->lastInsertId();
    $db->prepare('INSERT INTO shortcut_versions (shortcut_id, url, version_note) VALUES (?, ?, ?)')
       ->execute([$shortcutId, $url, '初始版本']);

    $stmt = $db->prepare('SELECT s.*, u.username, u.avatar FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?');
    $stmt->execute([$shortcutId]);
    $created = $stmt->fetch();

    if (!$isAdmin) {
        sendWechatNotify($db, $created, $authUser);
    }

    Response::json(['shortcut' => $created], 201);
}

function updateShortcut(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();
    if ($shortcut['user_id'] != $authUser['id']) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) Response::error('无效的请求数据', 400);
    $updates = [];
    $params = [];
    foreach (['title', 'description', 'category'] as $field) {
        if (isset($body[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = $body[$field];
        }
    }
    if (!$updates) Response::error('没有要修改的内容');

    $needReview = $shortcut['status'] !== 'active';
    if ($needReview) {
        $updates[] = "status = 'pending'";
    }

    $params[] = $shortcut['id'];
    $db->prepare('UPDATE shortcuts SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    if ($needReview) {
        $updated = $db->prepare('SELECT s.*, u.username, u.avatar FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?');
        $updated->execute([$shortcut['id']]);
        sendWechatNotify($db, $updated->fetch(), $authUser);
    }

    $stmt = $db->prepare('SELECT s.*, u.username, u.avatar FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?');
    $stmt->execute([$shortcut['id']]);
    Response::json(['shortcut' => $stmt->fetch()]);
}

function deleteShortcut(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();
    if ($shortcut['user_id'] != $authUser['id']) Response::forbidden();

    $db->prepare('DELETE FROM shortcuts WHERE id = ?')->execute([$shortcut['id']]);
    Response::json(['message' => '删除成功']);
}

function removeShortcut(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();
    if ($shortcut['user_id'] != $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
        Response::forbidden();
    }

    $db->prepare("UPDATE shortcuts SET status = 'removed' WHERE id = ?")->execute([$shortcut['id']]);
    Response::json(['message' => '下架成功']);
}

function restoreShortcut(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();
    if ($shortcut['user_id'] != $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
        Response::forbidden();
    }

    $db->prepare("UPDATE shortcuts SET status = 'active' WHERE id = ?")->execute([$shortcut['id']]);
    Response::json(['message' => '恢复成功']);
}

function refreshStats(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();
    if ($shortcut['user_id'] != $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
        Response::forbidden();
    }

    $meta = fetchShortcutMeta($shortcut['file_url']);
    if (!$meta || !$meta['shortcutUrl']) {
        Response::error('未能获取快捷指令下载地址', 500);
    }

    $stats = PlistParser::parseShortcutInfo($meta['shortcutUrl']);
    if (!$stats) Response::error('统计信息抓取失败', 500);

    $db->prepare('UPDATE shortcuts SET stats = ? WHERE id = ?')
       ->execute([json_encode($stats), $shortcut['id']]);
    Response::json(['stats' => $stats]);
}

function downloadShortcut(string $idOrSlug): void {
    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();

    $db->prepare('UPDATE shortcuts SET download_count = download_count + 1 WHERE id = ?')
       ->execute([$shortcut['id']]);

    header('Location: ' . $shortcut['file_url'], true, 302);
    exit;
}

function getVersions(string $idOrSlug): void {
    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();

    $stmt = $db->prepare('SELECT id, shortcut_id, url, version_note, created_at FROM shortcut_versions WHERE shortcut_id = ? ORDER BY created_at DESC');
    $stmt->execute([$shortcut['id']]);
    Response::json(['versions' => $stmt->fetchAll()]);
}

function addVersion(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();
    if ($shortcut['user_id'] != $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
        Response::forbidden();
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) Response::error('无效的请求数据', 400);
    $url = $body['url'] ?? '';
    $note = $body['version_note'] ?? '';
    if (!$url) Response::error('请提供新的快捷指令链接');
    if (!isValidShortcutUrl($url)) Response::error('请输入有效的 iCloud 快捷指令链接');

    $db->prepare('INSERT INTO shortcut_versions (shortcut_id, url, version_note) VALUES (?, ?, ?)')
       ->execute([$shortcut['id'], $url, $note]);
    $db->prepare('UPDATE shortcuts SET file_url = ? WHERE id = ?')
       ->execute([$url, $shortcut['id']]);

    $meta = fetchShortcutMeta($url);
    $stats = null;
    if ($meta && $meta['shortcutUrl']) {
        $stats = PlistParser::parseShortcutInfo($meta['shortcutUrl']);
        if ($stats) {
            $db->prepare('UPDATE shortcuts SET stats = ? WHERE id = ?')
               ->execute([json_encode($stats), $shortcut['id']]);
        }
    }

    $stmt = $db->prepare('SELECT id, shortcut_id, url, version_note, created_at FROM shortcut_versions WHERE shortcut_id = ? ORDER BY created_at DESC');
    $stmt->execute([$shortcut['id']]);
    Response::json([
        'versions' => $stmt->fetchAll(),
        'stats' => $stats,
        'message' => '版本更新成功',
    ]);
}

function getSimilar(string $idOrSlug): void {
    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();

    $stmt = $db->prepare("
        SELECT s.*, u.username, u.avatar
        FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id
        WHERE s.category = ? AND s.id != ? AND s.status = 'active'
        ORDER BY s.download_count DESC
        LIMIT 5
    ");
    $stmt->execute([$shortcut['category'], $shortcut['id']]);
    Response::json(['shortcuts' => $stmt->fetchAll()]);
}

function findShortcut(PDO $db, string $value): ?array {
    if (is_numeric($value)) {
        $stmt = $db->prepare('SELECT * FROM shortcuts WHERE id = ?');
        $stmt->execute([(int) $value]);
        $s = $stmt->fetch();
        if ($s) return $s;
    }
    $stmt = $db->prepare('SELECT * FROM shortcuts WHERE slug = ?');
    $stmt->execute([$value]);
    return $stmt->fetch() ?: null;
}

function sendWechatNotify(PDO $db, array $shortcut, array $authUser): void {
    try {
        $stmt = $db->query("SELECT `value` FROM settings WHERE `key` = 'wechat_bot_token'");
        $row = $stmt->fetch();
        if (!$row || !$row['value']) return;

        $token = $row['value'];
    } catch (\Exception $e) {
        error_log('sendWechatNotify token read failed: ' . $e->getMessage());
        return;
    }

    // 优先从设置读取，其次从当前请求推断站点地址
    $siteUrl = 'http://localhost';
    try {
        $stmt = $db->query("SELECT `value` FROM settings WHERE `key` = 'site_url'");
        $urlRow = $stmt->fetch();
        if ($urlRow && $urlRow['value']) {
            $siteUrl = rtrim($urlRow['value'], '/');
        }
    } catch (\Exception $e) {}

    if ($siteUrl === 'http://localhost' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
            ? 'https' : 'http';
        $siteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    $adminUrl = $siteUrl . '/admin';
    $shortcutUrl = $siteUrl . '/shortcut/' . $shortcut['slug'];
    $title = $shortcut['title'] ?? '未命名';
    $username = $authUser['username'] ?? '未知';
    $description = mb_substr($shortcut['description'] ?? '', 0, 100);
    $category = $shortcut['category'] ?? '其他';

    $content = "> **新投稿待审核**\n"
        . "> 名称：<font color=\"info\">{$title}</font>\n"
        . "> 分类：{$category}\n"
        . "> 作者：{$username}\n"
        . "> 简介：{$description}\n"
        . "> [查看详情]({$shortcutUrl})\n"
        . "> \n"
        . "> [前往审核]({$adminUrl})";

    $data = [
        'msgtype' => 'markdown',
        'markdown' => ['content' => $content],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key={$token}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
