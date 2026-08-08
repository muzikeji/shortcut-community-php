<?php
namespace Shortcut\Routes;

use Shortcut\{Database, Auth, Response};
use PDO;

function likeShortcut(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = \Shortcut\Routes\findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();

    $db->beginTransaction();
    try {
        $like = $db->prepare('SELECT id FROM likes WHERE shortcut_id = ? AND user_id = ?');
        $like->execute([$shortcut['id'], $authUser['id']]);

        if ($like->fetch()) {
            $db->prepare('DELETE FROM likes WHERE shortcut_id = ? AND user_id = ?')
               ->execute([$shortcut['id'], $authUser['id']]);
            $decSql = Database::isMySQL()
                ? 'UPDATE shortcuts SET like_count = GREATEST(0, like_count - 1) WHERE id = ?'
                : 'UPDATE shortcuts SET like_count = MAX(0, like_count - 1) WHERE id = ?';
            $db->prepare($decSql)->execute([$shortcut['id']]);
            $liked = false;
        } else {
            $db->prepare('INSERT INTO likes (shortcut_id, user_id) VALUES (?, ?)')
               ->execute([$shortcut['id'], $authUser['id']]);
            $db->prepare('UPDATE shortcuts SET like_count = like_count + 1 WHERE id = ?')
               ->execute([$shortcut['id']]);
            $liked = true;
        }
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        Response::error('操作失败，请重试', 500);
    }

    $count = $db->prepare('SELECT like_count FROM shortcuts WHERE id = ?');
    $count->execute([$shortcut['id']]);
    Response::json([
        'liked' => $liked,
        'like_count' => (int) $count->fetch()['like_count'],
    ]);
}

function getComments(string $idOrSlug): void {
    $db = Database::get();
    $shortcut = \Shortcut\Routes\findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();

    $sort = $_GET['sort'] ?? 'newest';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $orderBy = $sort === 'popular' ? 'c.like_count DESC, c.created_at DESC' : 'c.created_at DESC';

    $countStmt = $db->prepare('SELECT COUNT(*) FROM comments WHERE shortcut_id = ?');
    $countStmt->execute([$shortcut['id']]);
    $totalCount = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT c.*, u.username, u.avatar
        FROM comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.shortcut_id = ?
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$shortcut['id'], $perPage, $offset]);
    $rows = $stmt->fetchAll();

    Response::json([
        'comments' => $rows,
        'total' => $totalCount,
        'totalPages' => max(1, (int) ceil($totalCount / $perPage)),
        'page' => $page,
    ]);
}

function addComment(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = \Shortcut\Routes\findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) Response::error('无效的请求数据', 400);
    $content = strip_tags(trim($body['content'] ?? ''));
    $parentId = $body['parent_id'] ?? null;

    if (mb_strlen($content) < 1) Response::error('评论内容不能为空');
    if (mb_strlen($content) > 500) Response::error('评论内容不能超过 500 字');

    if ($parentId) {
        $parent = $db->prepare('SELECT id, shortcut_id FROM comments WHERE id = ?');
        $parent->execute([(int) $parentId]);
        $p = $parent->fetch();
        if (!$p || $p['shortcut_id'] != $shortcut['id']) {
            Response::error('回复的评论不存在');
        }
    }

    $db->prepare('INSERT INTO comments (shortcut_id, user_id, content, parent_id) VALUES (?, ?, ?, ?)')
       ->execute([$shortcut['id'], $authUser['id'], $content, $parentId]);
    $commentId = $db->lastInsertId();
    $db->prepare('UPDATE shortcuts SET comment_count = comment_count + 1 WHERE id = ?')
       ->execute([$shortcut['id']]);

    $stmt = $db->prepare('SELECT c.*, u.username, u.avatar FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?');
    $stmt->execute([$commentId]);
    Response::json(['comment' => $stmt->fetch()], 201);
}

function deleteComment(int $commentId): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $comment = $db->prepare('SELECT * FROM comments WHERE id = ?');
    $comment->execute([$commentId]);
    $c = $comment->fetch();
    if (!$c) Response::notFound();
    $role = $authUser['role'] ?? '';
    if ($c['user_id'] != $authUser['id'] && !in_array($role, ['admin', 'owner'], true)) {
        Response::forbidden();
    }

    $db->beginTransaction();
    try {
        $childCount = $db->prepare('SELECT COUNT(*) as cnt FROM comments WHERE parent_id = ?');
        $childCount->execute([$commentId]);
        $replies = (int) $childCount->fetch()['cnt'];

        $decSql = Database::isMySQL()
            ? 'UPDATE shortcuts SET comment_count = GREATEST(0, comment_count - ?) WHERE id = ?'
            : 'UPDATE shortcuts SET comment_count = MAX(0, comment_count - ?) WHERE id = ?';
        $db->prepare($decSql)
           ->execute([1 + $replies, $c['shortcut_id']]);
        $db->prepare('DELETE FROM comments WHERE id = ? OR parent_id = ?')
           ->execute([$commentId, $commentId]);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        Response::error('操作失败，请重试', 500);
    }

    Response::json(['message' => '删除成功']);
}
