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

    ignore_user_abort(true);
    set_time_limit(0);

    $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
    $tmpDir = $rootDir . '/data/.tmp_update';
    $backupDir = $rootDir . '/.backup_' . date('YmdHis');

    $stage = $_GET['stage'] ?? 'download';
    $metaFile = $tmpDir . '/meta.json';
    $current = getCurrentVersion();

    if ($stage === 'download') {
        if (is_dir($tmpDir)) rrmdir($tmpDir);
        mkdir($tmpDir, 0755, true);

        $latest = fetchLatestRelease();
        if (!$latest) {
            rrmdir($tmpDir);
            Response::error('无法获取最新版本信息', 502);
        }

        if (!version_compare(ltrim($latest['tag'], 'v'), ltrim($current, 'v'), '>')) {
            rrmdir($tmpDir);
            Response::json(['message' => '已是最新版本', 'version' => $current]);
            return;
        }

        file_put_contents($metaFile, json_encode($latest));

        $zipFile = $tmpDir . '/update.zip';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: PHP-Shortcut-Updater\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $zipContent = file_get_contents($latest['downloadUrl'], false, $ctx);
        if (!$zipContent || strlen($zipContent) < 1024) {
            rrmdir($tmpDir);
            Response::error('下载更新包失败，请检查网络后重试', 502);
        }
        file_put_contents($zipFile, $zipContent);

        Response::json([
            'stage' => 'download',
            'message' => '更新包下载完成',
            'version' => $latest['tag'],
            'size' => $latest['size'],
        ]);
        return;
    }

    if ($stage === 'install') {
        if (!file_exists($metaFile)) {
            Response::error('未找到下载任务，请先执行下载');
        }

        $latest = json_decode(file_get_contents($metaFile), true);
        if (!$latest) {
            Response::error('更新包信息已损坏');
        }

        $zipFile = $tmpDir . '/update.zip';
        if (!file_exists($zipFile) || filesize($zipFile) < 1024) {
            Response::error('更新包文件缺失或损坏，请重新执行下载');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            Response::error('无法打开更新包', 500);
        }

        try {
            mkdir($backupDir, 0755, true);
            if (is_dir($rootDir . '/data')) {
                copyDir($rootDir . '/data', $backupDir . '/data');
            }
            if (is_dir($rootDir . '/uploads')) {
                copyDir($rootDir . '/uploads', $backupDir . '/uploads');
            }
            if (file_exists($rootDir . '/.env')) {
                copy($rootDir . '/.env', $backupDir . '/.env');
            }

            $extractDir = $tmpDir . '/extracted';
            mkdir($extractDir, 0755, true);
            $zip->extractTo($extractDir);
            $zip->close();

            $srcBase = '';
            if (is_dir($extractDir . '/php-shortcut')) {
                $srcBase = $extractDir . '/php-shortcut';
            } else {
                $srcBase = $extractDir;
            }

            $errors = [];
            deployFiles($srcBase, $rootDir, $errors);
        } catch (\Exception $e) {
            if (isset($zip)) @$zip->close();
            rrmdir($tmpDir);
            Response::error('解压过程出错: ' . $e->getMessage(), 500);
        }

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
        return;
    }

    Response::error('无效的升级阶段');
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
        if (substr($asset['name'], -4) === '.zip') {
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
        if (strpos($file, '.backup_') === 0) continue;
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

function deployFiles(string $src, string $dst, array &$errors): void {
    $dir = opendir($src);
    while (($item = readdir($dir)) !== false) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;

        if ($item === 'data' || $item === 'uploads' || $item === '.env') continue;
        if (strpos($item, '.backup_') === 0) continue;

        if (is_dir($s)) {
            if (!is_dir($d)) mkdir($d, 0755, true);
            deployFiles($s, $d, $errors);
        } else {
            if (!@copy($s, $d)) {
                $errors[] = str_replace($dst . '/', '', $d);
            }
        }
    }
    closedir($dir);
}

function getVersion(): void {
    Response::json([
        'version' => getCurrentVersion(),
        'php' => PHP_VERSION,
        'ziparchive' => class_exists('ZipArchive'),
    ]);
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
