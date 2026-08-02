<?php
namespace Shortcut\Routes;

use Shortcut\{Database, Auth, Response};

function getDashboard(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $db = Database::get();
    $users = $db->query('SELECT COUNT(*) as cnt FROM users')->fetch()['cnt'];
    $shortcuts = $db->query("SELECT COUNT(*) as cnt FROM shortcuts WHERE status = 'active'")->fetch()['cnt'];
    $comments = $db->query('SELECT COUNT(*) as cnt FROM comments')->fetch()['cnt'];
    $likes = $db->query('SELECT COUNT(*) as cnt FROM likes')->fetch()['cnt'];
    $downloads = $db->query('SELECT COALESCE(SUM(download_count), 0) as cnt FROM shortcuts')->fetch()['cnt'];

    Response::json(['stats' => [
        'users' => (int) $users,
        'shortcuts' => (int) $shortcuts,
        'comments' => (int) $comments,
        'likes' => (int) $likes,
        'downloads' => (int) $downloads,
    ]]);
}

function getUsers(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $db = Database::get();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, (int) ($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $total = $db->query('SELECT COUNT(*) as cnt FROM users')->fetch()['cnt'];
    $stmt = $db->prepare('SELECT id, username, email, avatar, bio, role, banned, created_at FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$limit, $offset]);

    Response::json([
        'users' => $stmt->fetchAll(),
        'total' => (int) $total,
    ]);
}

function updateUserRole(int $userId): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    $role = $body['role'] ?? '';
    if (!in_array($role, ['user', 'admin'])) Response::error('无效的角色');

    $db = Database::get();
    $db->prepare('UPDATE users SET role = ? WHERE id = ? AND id != ?')
       ->execute([$role, $userId, $authUser['id']]);

    Response::json(['message' => '角色更新成功']);
}

function toggleBanUser(int $userId): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    $banned = $body['banned'] ? 1 : 0;

    $db = Database::get();
    if ($userId == $authUser['id']) Response::error('不能操作自己的账号');

    $db->prepare('UPDATE users SET banned = ? WHERE id = ?')->execute([$banned, $userId]);

    Response::json(['message' => $banned ? '已封禁' : '已解封']);
}

function banUser(int $userId): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();
    if ($userId == $authUser['id']) Response::error('不能操作自己的账号');

    Database::get()->prepare('UPDATE users SET banned = 1 WHERE id = ?')->execute([$userId]);
    Response::json(['message' => '已封禁']);
}

function unbanUser(int $userId): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    Database::get()->prepare('UPDATE users SET banned = 0 WHERE id = ?')->execute([$userId]);
    Response::json(['message' => '已解封']);
}

function adminCreateUser(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (mb_strlen($username) < 2) Response::error('用户名长度应为 2-20 个字符');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('请输入有效的邮箱地址');
    if (strlen($password) < 6) Response::error('密码长度不能少于 6 位');

    $db = Database::get();
    $existing = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $existing->execute([$username, $email]);
    if ($existing->fetch()) Response::error('用户名或邮箱已被使用');

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)')
       ->execute([$username, $email, $hash]);

    $id = $db->lastInsertId();
    $stmt = $db->prepare('SELECT id, username, email, avatar, bio, role, banned, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    Response::json(['user' => $stmt->fetch()], 201);
}

function getAllShortcuts(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $db = Database::get();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, (int) ($_GET['limit'] ?? 20));
    $search = $_GET['search'] ?? '';
    $offset = ($page - 1) * $limit;

    $where = '';
    $params = [];
    if ($search) {
        $where = 'WHERE s.title LIKE ? OR u.username LIKE ?';
        $params = ["%{$search}%", "%{$search}%"];
    }

    $count = $db->prepare("SELECT COUNT(*) as cnt FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id {$where}");
    $count->execute($params);

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare("
        SELECT s.*, u.username
        FROM shortcuts s LEFT JOIN users u ON s.user_id = u.id
        {$where}
        ORDER BY s.created_at DESC LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    Response::json([
        'shortcuts' => $stmt->fetchAll(),
        'total' => (int) $count->fetch()['cnt'],
    ]);
}
