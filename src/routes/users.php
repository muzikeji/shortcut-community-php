<?php
namespace Shortcut\Routes;

use Shortcut\{Database, Auth, Response};
use PDO;

function registerUser(): void {
    $body = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (mb_strlen($username) < 2 || mb_strlen($username) > 20) {
        Response::error('用户名长度应为 2-20 个字符');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error('请输入有效的邮箱地址');
    }
    if (strlen($password) < 6) {
        Response::error('密码长度不能少于 6 位');
    }

    $db = Database::get();
    $existing = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $existing->execute([$username, $email]);
    if ($existing->fetch()) {
        Response::error('用户名或邮箱已被使用');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)')
       ->execute([$username, $email, $hash]);

    $userId = $db->lastInsertId();
    $user = $db->prepare('SELECT id, username, email, avatar, bio, role, created_at FROM users WHERE id = ?');
    $user->execute([$userId]);
    $userData = $user->fetch();

    Response::json([
        'user' => $userData,
        'token' => Auth::generateToken($userData),
    ]);
}

function loginUser(): void {
    $body = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        Response::error('请输入用户名和密码');
    }

    $db = Database::get();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        Response::error('用户名或密码错误', 401);
    }
    if ($user['banned']) {
        Response::error('账号已被封禁', 403);
    }

    Response::json([
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'avatar' => $user['avatar'],
            'bio' => $user['bio'],
            'role' => $user['role'],
            'created_at' => $user['created_at'],
        ],
        'token' => Auth::generateToken($user),
    ]);
}

function getCurrentUser(): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $stmt = $db->prepare('SELECT id, username, email, avatar, bio, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$authUser['id']]);
    $user = $stmt->fetch();
    if (!$user) Response::error('用户不存在', 404);

    Response::json(['user' => $user]);
}

function getUserById(string $id): void {
    $db = Database::get();
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.avatar, u.bio, u.created_at,
               COUNT(s.id) as shortcut_count
        FROM users u
        LEFT JOIN shortcuts s ON s.user_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ');
    $stmt->execute([(int) $id]);
    $user = $stmt->fetch();
    if (!$user) Response::notFound();

    Response::json(['user' => $user]);
}

function updateProfile(): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $body = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $email = trim($body['email'] ?? '');
    $bio = isset($body['bio']) ? trim($body['bio']) : null;

    $db = Database::get();

    $fields = [];
    $params = [];

    if (isset($body['username']) && $username !== '') {
        $exist = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $exist->execute([$username, $authUser['id']]);
        if ($exist->fetch()) Response::error('用户名已被使用');
        $fields[] = 'username = ?';
        $params[] = $username;
    }
    if (isset($body['email']) && $email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('请输入有效的邮箱地址');
        }
        $exist = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $exist->execute([$email, $authUser['id']]);
        if ($exist->fetch()) Response::error('邮箱已被使用');
        $fields[] = 'email = ?';
        $params[] = $email;
    }
    if ($bio !== null) {
        $fields[] = 'bio = ?';
        $params[] = $bio;
    }

    if (!empty($fields)) {
        $params[] = $authUser['id'];
        $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
           ->execute($params);
    }

    $stmt = $db->prepare('SELECT id, username, email, avatar, bio, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$authUser['id']]);
    Response::json(['user' => $stmt->fetch()]);
}

function updatePassword(): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $body = json_decode(file_get_contents('php://input'), true);
    $current = $body['currentPassword'] ?? '';
    $newPass = $body['newPassword'] ?? '';

    if (strlen($newPass) < 6) Response::error('新密码长度不能少于 6 位');

    $db = Database::get();
    $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$authUser['id']]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password'])) {
        Response::error('当前密码错误');
    }

    $db->prepare('UPDATE users SET password = ? WHERE id = ?')
       ->execute([password_hash($newPass, PASSWORD_BCRYPT), $authUser['id']]);

    Response::json(['message' => '密码修改成功']);
}

function uploadAvatar(): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    if (empty($_FILES['avatar'])) Response::error('请选择文件');

    $file = $_FILES['avatar'];
    if ($file['error'] !== UPLOAD_ERR_OK) Response::error('文件上传失败');
    if ($file['size'] > 2 * 1024 * 1024) Response::error('文件大小不能超过 2MB');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
        Response::error('只支持 jpg / png / gif 格式');
    }

    $uploadDir = ROOT_DIR . '/uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = $authUser['id'] . '_' . time() . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename);

    $avatarUrl = '/uploads/' . $filename;
    $db = Database::get();
    $db->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$avatarUrl, $authUser['id']]);

    Response::json(['avatar' => $avatarUrl]);
}
