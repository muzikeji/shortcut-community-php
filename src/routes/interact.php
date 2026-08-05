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

    $like = $db->prepare('SELECT id FROM likes WHERE shortcut_id = ? AND user_id = ?');
    $like->execute([$shortcut['id'], $authUser['id']]);

    if ($like->fetch()) {
        $db->prepare('DELETE FROM likes WHERE shortcut_id = ? AND user_id = ?')
           ->execute([$shortcut['id'], $authUser['id']]);
        $db->prepare('UPDATE shortcuts SET like_count = MAX(0, like_count - 1) WHERE id = ?')
           ->execute([$shortcut['id']]);
        $liked = false;
    } else {
        $db->prepare('INSERT INTO likes (shortcut_id, user_id) VALUES (?, ?)')
           ->execute([$shortcut['id'], $authUser['id']]);
        $db->prepare('UPDATE shortcuts SET like_count = like_count + 1 WHERE id = ?')
           ->execute([$shortcut['id']]);
        $liked = true;
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

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 50)));

    $total = $db->prepare('SELECT COUNT(*) as total FROM comments WHERE shortcut_id = ?');
    $total->execute([$shortcut['id']]);
    $totalCount = (int) $total->fetch()['total'];

    $sort = $_GET['sort'] ?? 'newest';
    $orderBy = $sort === 'popular' ? 'c.like_count DESC, c.created_at DESC' : 'c.created_at DESC';

    $allRows = $db->prepare("
        SELECT c.*, u.username, u.avatar
        FROM comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.shortcut_id = ?
        ORDER BY {$orderBy}
    ");
    $allRows->execute([$shortcut['id']]);
    $rows = $allRows->fetchAll();

    $comments = [];
    $replyMap = [];
    foreach ($rows as $r) {
        $r['replies'] = [];
        if ($r['parent_id']) {
            $replyMap[$r['parent_id']][] = $r;
        } else {
            $comments[] = $r;
        }
    }

    foreach ($comments as &$c) {
        if (isset($replyMap[$c['id']])) {
            $c['replies'] = $replyMap[$c['id']];
        }
    }
    unset($c);

    $offset = ($page - 1) * $limit;
    $paged = array_slice($comments, $offset, $limit);

    Response::json([
        'comments' => $paged,
        'total' => $totalCount,
        'totalPages' => max(1, (int) ceil(count($comments) / $limit)),
    ]);
}

function addComment(string $idOrSlug): void {
    $authUser = Auth::requireAuth();
    if (!$authUser) Response::unauthorized();

    $db = Database::get();
    $shortcut = \Shortcut\Routes\findShortcut($db, $idOrSlug);
    if (!$shortcut) Response::notFound();

    $body = json_decode(file_get_contents('php://input'), true);
    $content = trim($body['content'] ?? '');
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
    if ($c['user_id'] != $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
        Response::forbidden();
    }

    $childCount = $db->prepare('SELECT COUNT(*) as cnt FROM comments WHERE parent_id = ?');
    $childCount->execute([$commentId]);
    $replies = (int) $childCount->fetch()['cnt'];

    $db->prepare('UPDATE shortcuts SET comment_count = MAX(0, comment_count - ?) WHERE id = ?')
       ->execute([1 + $replies, $c['shortcut_id']]);
    $db->prepare('DELETE FROM comments WHERE id = ? OR parent_id = ?')
       ->execute([$commentId, $commentId]);

    Response::json(['message' => '删除成功']);
}
