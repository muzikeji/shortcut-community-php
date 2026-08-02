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
    return (bool) preg_match('#^https?://(www\.)?icloud\.com/shortcuts/[a-zA-Z0-9]+#', $url);
}

function getShortcuts(): void {
    $db = Database::get();
    $authUser = Auth::optionalAuth();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = $_GET['search'] ?? '';
    $sort = $_GET['sort'] ?? '';
    $userId = $_GET['userId'] ?? '';
    $includeRemoved = !empty($_GET['includeRemoved']);

    $where = [];
    $params = [];

    if (!$includeRemoved) {
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
    $numeric = is_numeric($idOrSlug);

    if ($numeric) {
        $stmt = $db->prepare('
            SELECT s.*, u.username, u.avatar
            FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id
            WHERE s.id = ?
        ');
        $stmt->execute([(int) $idOrSlug]);
        $s = $stmt->fetch();
    } else {
        $stmt = $db->prepare('
            SELECT s.*, u.username, u.avatar
            FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id
            WHERE s.slug = ?
        ');
        $stmt->execute([$idOrSlug]);
        $s = $stmt->fetch();
    }

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

function fetchName(): void {
    $body = json_decode(file_get_contents('php://input'), true);
    $url = $body['url'] ?? '';
    if (!$url || !isValidShortcutUrl($url)) {
        Response::error('无效的 iCloud 快捷指令链接');
    }
    $meta = fetchShortcutMeta($url);
    if (!$meta || !$meta['name']) {
        Response::error('未能获取快捷指令名称', 404);
    }
    $stats = null;
    if ($meta['shortcutUrl']) {
        $stats = PlistParser::parseShortcutInfo($meta['shortcutUrl']);
    }
    Response::json([
        'name' => $meta['name'],
        'color' => $meta['color'],
        'stats' => $stats,
    ]);
}

function createShortcut(): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $body = json_decode(file_get_contents('php://input'), true);
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

    $db->prepare('INSERT INTO shortcuts (slug, title, description, category, file_url, file_size, user_id, color, stats)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $finalSlug,
        $meta['name'] ?: $title,
        $description,
        $category ?: '其他',
        $url,
        $stats['size'] ?? 0,
        $authUser['id'],
        $finalColor,
        $statsJson,
    ]);

    $shortcutId = $db->lastInsertId();
    $db->prepare('INSERT INTO shortcut_versions (shortcut_id, url, version_note) VALUES (?, ?, ?)')
       ->execute([$shortcutId, $url, '初始版本']);

    $stmt = $db->prepare('SELECT s.*, u.username, u.avatar FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?');
    $stmt->execute([$shortcutId]);
    Response::json(['shortcut' => $stmt->fetch()], 201);
}

function updateShortcut(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();
    if ($shortcut['user_id'] != $authUser['id']) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    $params = [];
    foreach (['title', 'description', 'category'] as $field) {
        if (isset($body[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = $body[$field];
        }
    }
    if (!$updates) Response::error('没有要修改的内容');

    $params[] = $shortcut['id'];
    $db->prepare('UPDATE shortcuts SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

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
    Response::json(['similar' => $stmt->fetchAll()]);
}

function findShortcut(PDO $db, string $value): ?array {
    if (is_numeric($value)) {
        $stmt = $db->prepare('SELECT * FROM shortcuts WHERE id = ?');
        $stmt->execute([(int) $value]);
    } else {
        $stmt = $db->prepare('SELECT * FROM shortcuts WHERE slug = ?');
        $stmt->execute([$value]);
    }
    return $stmt->fetch() ?: null;
}
