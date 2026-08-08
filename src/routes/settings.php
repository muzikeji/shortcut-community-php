<?php
namespace Shortcut\Routes;

use Shortcut\{Database, Auth, Response};

function dbToFrontend(array $pairs): array {
    $map = [
        'site_name' => 'siteName',
        'site_logo' => 'logoUrl',
        'icp_number' => 'icpBeian',
        'seo_title' => 'seoTitle',
        'seo_description' => 'seoDescription',
        'wechat_bot_token' => 'wechatBotToken',
    ];
    $defaults = [
        'siteName' => '捷径社区',
        'logoUrl' => '/logo.png',
        'icpBeian' => '',
        'seoTitle' => '',
        'seoDescription' => '分享和发现实用的 iOS 快捷指令',
        'siteDescription' => 'iOS 快捷指令分享社区',
        'wechatBotToken' => '',
    ];
    foreach ($map as $dbKey => $jsKey) {
        if (isset($pairs[$dbKey]) && $pairs[$dbKey] !== '') {
            $defaults[$jsKey] = $pairs[$dbKey];
        }
    }
    return $defaults;
}

function frontendToDb(array $body): array {
    $map = [
        'siteName' => 'site_name',
        'logoUrl' => 'site_logo',
        'icpBeian' => 'icp_number',
        'seoTitle' => 'seo_title',
        'seoDescription' => 'seo_description',
        'wechatBotToken' => 'wechat_bot_token',
    ];
    $result = [];
    foreach ($map as $jsKey => $dbKey) {
        if (array_key_exists($jsKey, $body)) {
            $result[$dbKey] = $body[$jsKey];
        }
    }
    return $result;
}

function getPublicSettings(): void {
    try {
        $db = Database::get();
        $stmt = $db->query('SELECT `key`, `value` FROM settings');
        $pairs = [];
        while ($row = $stmt->fetch()) {
            $pairs[$row['key']] = $row['value'];
        }
        $result = dbToFrontend($pairs);
        unset($result['wechatBotToken']);
        Response::json($result);
    } catch (\Exception $e) {
        error_log('getPublicSettings failed: ' . $e->getMessage());
        $result = dbToFrontend([]);
        unset($result['wechatBotToken']);
        Response::json($result);
    }
}

function getAdminSettings(): void {
    $authUser = Auth::requireOwner();
    if (!$authUser) Response::forbidden();

    try {
        $db = Database::get();
        $stmt = $db->query('SELECT `key`, `value` FROM settings');
        $pairs = [];
        while ($row = $stmt->fetch()) {
            $pairs[$row['key']] = $row['value'];
        }
        Response::json(dbToFrontend($pairs));
    } catch (\Exception $e) {
        error_log('getAdminSettings failed: ' . $e->getMessage());
        Response::json(dbToFrontend([]));
    }
}

function updateSetting(): void {
    $authUser = Auth::requireOwner();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) Response::error('无效的请求数据', 400);
    $db = Database::get();

    if (isset($body['key']) && isset($body['value'])) {
        $allowedKeys = ['site_name', 'site_logo', 'icp_number', 'seo_title', 'seo_description', 'wechat_bot_token'];
        if (!in_array($body['key'], $allowedKeys, true)) {
            Response::error('无效的设置键名', 400);
        }
        if (Database::isMySQL()) {
            $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
               ->execute([$body['key'], $body['value']]);
        } else {
            $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`')
               ->execute([$body['key'], $body['value']]);
        }
    } else {
        $dbPairs = frontendToDb($body);
        $upsertSql = Database::isMySQL()
            ? 'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            : 'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`';
        foreach ($dbPairs as $key => $value) {
            $db->prepare($upsertSql)->execute([$key, $value]);
        }
    }

    // Return updated settings
    $stmt = $db->query('SELECT `key`, `value` FROM settings');
    $pairs = [];
    while ($row = $stmt->fetch()) {
        $pairs[$row['key']] = $row['value'];
    }
    Response::json(dbToFrontend($pairs));
}

function updateSiteSettings(): void {
    $authUser = Auth::requireOwner();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) Response::error('无效的请求数据', 400);
    $db = Database::get();

    $dbPairs = frontendToDb($body);
    $upsertSql = Database::isMySQL()
        ? 'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        : 'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`';
    foreach ($dbPairs as $key => $value) {
        $db->prepare($upsertSql)->execute([$key, $value]);
    }

    Response::json(['message' => '保存成功']);
}
