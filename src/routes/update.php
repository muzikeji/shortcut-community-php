<?php
namespace Shortcut\Routes;

use Shortcut\{Auth, Response};

function checkUpdate(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    $current = getCurrentVersion();
    $latest = fetchLatestRelease();

    if (!$latest) {
        Response::error('无法获取最新版本信息', 502);
    }

    Response::json([
        'current' => $current,
        'latest' => $latest['tag'],
        'published' => $latest['published'],
        'hasUpdate' => version_compare(ltrim($latest['tag'], 'v'), ltrim($current, 'v'), '>'),
        'downloadUrl' => $latest['downloadUrl'],
        'size' => $latest['size'],
    ]);
}

function doUpdate(): void {
    $authUser = Auth::requireAdmin();
    if (!$authUser) Response::forbidden();

    if (!class_exists('ZipArchive')) {
        Response::error('缺少 PHP ZipArchive 扩展，无法执行在线升级', 500);
    }

    set_time_limit(300);
    $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
    $tmpDir = $rootDir . '/data/.tmp_update';
    $backupDir = $rootDir . '/data/.backup_' . date('YmdHis');

    if (is_dir($tmpDir)) rrmdir($tmpDir);
    mkdir($tmpDir, 0755, true);

    $latest = fetchLatestRelease();
    if (!$latest) {
        rrmdir($tmpDir);
        Response::error('无法获取最新版本信息', 502);
    }

    $current = getCurrentVersion();
    if (!version_compare(ltrim($latest['tag'], 'v'), ltrim($current, 'v'), '>')) {
        rrmdir($tmpDir);
        Response::json(['message' => '已是最新版本', 'version' => $current]);
        return;
    }

    $zipFile = $tmpDir . '/update.zip';
    $zipContent = @file_get_contents($latest['downloadUrl']);
    if (!$zipContent) {
        rrmdir($tmpDir);
        Response::error('下载更新包失败', 502);
    }
    file_put_contents($zipFile, $zipContent);

    $zip = new \ZipArchive();
    if ($zip->open($zipFile) !== true) {
        rrmdir($tmpDir);
        Response::error('无法打开更新包', 500);
    }

    mkdir($backupDir, 0755, true);
    if (is_dir($rootDir . '/data') && basename($rootDir . '/data') !== 'data') {
        // safety check
    }
    $dataBackup = is_dir($rootDir . '/data');
    $uploadsBackup = is_dir($rootDir . '/uploads');

    if ($dataBackup) {
        copyDir($rootDir . '/data', $backupDir . '/data');
    }
    if ($uploadsBackup) {
        copyDir($rootDir . '/uploads', $backupDir . '/uploads');
    }
    if (file_exists($rootDir . '/.env')) {
        copy($rootDir . '/.env', $backupDir . '/.env');
    }

    $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        $relative = preg_replace('#^php-shortcut/#', '', $entry);
        if ($relative === '' || $relative === 'php-shortcut/') continue;

        // Skip protected dirs
        if (strpos($relative, 'data/') === 0) continue;
        if (strpos($relative, 'uploads/') === 0) continue;
        if ($relative === '.env') continue;

        $target = $rootDir . '/' . $relative;

        if (substr($entry, -1) === '/') {
            if (!is_dir($target)) mkdir($target, 0755, true);
            continue;
        }

        $parent = dirname($target);
        if (!is_dir($parent)) mkdir($parent, 0755, true);

        if ($zip->extractTo($rootDir, $entry)) {
            $extractedPath = $rootDir . '/' . $entry;
            if ($extractedPath !== $target) {
                rename($extractedPath, $target);
            }
        } else {
            $errors[] = $relative;
        }
    }
    $zip->close();

    rrmdir($tmpDir);

    if (!empty($errors)) {
        Response::json([
            'message' => '部分文件更新失败',
            'errors' => $errors,
            'backup' => '数据已备份到 ' . basename($backupDir),
            'version' => $latest['tag'],
        ]);
        return;
    }

    Response::json([
        'message' => '升级完成',
        'version' => $latest['tag'],
        'from' => $current,
        'backup' => '数据已备份到 ' . basename($backupDir),
        'note' => '请在终端运行 composer install 以更新依赖',
    ]);
}

function getCurrentVersion(): string {
    $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
    $verFile = $rootDir . '/VERSION';
    if (file_exists($verFile)) {
        return trim(file_get_contents($verFile));
    }
    return '1.0.0';
}

function fetchLatestRelease(): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header' => "User-Agent: PHP-Shortcut-Updater\r\n",
        ],
    ]);
    $data = @file_get_contents(
        'https://api.github.com/repos/muzikeji/shortcut-community-php/releases/latest',
        false,
        $ctx
    );
    if (!$data) return null;

    $release = json_decode($data, true);
    if (!$release) return null;

    $downloadUrl = null;
    $size = 0;
    foreach ($release['assets'] ?? [] as $asset) {
        if (str_ends_with($asset['name'], '.zip')) {
            $downloadUrl = $asset['browser_download_url'];
            $size = $asset['size'];
            break;
        }
    }

    return [
        'tag' => $release['tag_name'] ?? '',
        'published' => $release['published_at'] ?? '',
        'downloadUrl' => $downloadUrl,
        'size' => $size,
    ];
}

function copyDir(string $src, string $dst): void {
    if (!is_dir($src)) return;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $s = $src . '/' . $file;
        $d = $dst . '/' . $file;
        if (is_dir($s)) {
            copyDir($s, $d);
        } else {
            copy($s, $d);
        }
    }
    closedir($dir);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}
