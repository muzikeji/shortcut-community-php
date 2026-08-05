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
    ];
    $defaults = [
        'siteName' => '捷径社区',
        'logoUrl' => '/logo.png',
        'icpBeian' => '',
        'seoTitle' => '',
        'seoDescription' => '分享和发现实用的 iOS 快捷指令',
        'siteDescription' => 'iOS 快捷指令分享社区',
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
        Response::json(dbToFrontend($pairs));
    } catch (\Exception $e) {
        Response::json(dbToFrontend([]));
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
    $db = Database::get();

    if (isset($body['key']) && isset($body['value'])) {
        $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
            ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`')
           ->execute([$body['key'], $body['value']]);
    } else {
        $dbPairs = frontendToDb($body);
        foreach ($dbPairs as $key => $value) {
            $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`')
               ->execute([$key, $value]);
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
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $body = json_decode(file_get_contents('php://input'), true);
    $db = Database::get();

    $dbPairs = frontendToDb($body);
    foreach ($dbPairs as $key => $value) {
        $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
            ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`')
           ->execute([$key, $value]);
    }

    Response::json(['message' => '保存成功']);
}
