<?php
namespace Shortcut\Routes;

use Shortcut\{Database, Auth, Response};

function getPublicSettings(): void {
    try {
        $db = Database::get();
        $stmt = $db->query('SELECT `key`, `value` FROM settings');
        $pairs = [];
        while ($row = $stmt->fetch()) {
            $pairs[$row['key']] = $row['value'];
        }
        Response::json(['settings' => $pairs]);
    } catch (\Exception $e) {
        Response::json(['settings' => []], 200);
    }
}

function getAdminSettings(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    getPublicSettings();
}

function updateSetting(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!isset($body['key']) || !isset($body['value'])) {
        Response::error('缺少 key 或 value');
    }

    $db = Database::get();
    $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
        ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`')
       ->execute([$body['key'], $body['value']]);

    Response::json(['message' => '保存成功']);
}

function updateSiteSettings(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    $db = Database::get();

    $fields = ['site_name', 'site_logo', 'icp_number', 'seo_title', 'seo_description', 'seo_keywords', 'footer_text'];
    foreach ($fields as $field) {
        if (array_key_exists($field, $body)) {
            $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`')
               ->execute([$field, $body[$field]]);
        }
    }

    Response::json(['message' => '保存成功']);
}
