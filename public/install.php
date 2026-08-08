<?php
header('Content-Type: text/html; charset=utf-8');
defined('ROOT_DIR') || define('ROOT_DIR', dirname(__DIR__));

function installHeader(string $msg, string $type = 'info'): void {
    $color = $type === 'error' ? '#e74c3c' : ($type === 'success' ? '#27ae60' : '#3498db');
    $bg = $type === 'error' ? '#fde8e8' : ($type === 'success' ? '#e8f5e9' : '#e3f2fd');
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>捷径社区 - 安装</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
            .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); padding: 40px; max-width: 520px; width: 100%; }
            h1 { font-size: 24px; margin-bottom: 8px; color: #333; }
            h2 { font-size: 16px; color: #888; margin-bottom: 24px; font-weight: 400; }
            .step { background: <?= $bg ?>; border-left: 4px solid <?= $color ?>; padding: 16px; border-radius: 6px; margin-bottom: 16px; color: #555; line-height: 1.6; }
            .step p { margin: 4px 0; }
            form { margin-top: 20px; }
            .section { margin-bottom: 24px; }
            .section-title { font-size: 14px; font-weight: 600; color: #333; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
            label { display: block; margin-bottom: 12px; color: #666; font-size: 13px; }
            input, select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; margin-top: 4px; }
            .radio-group { display: flex; gap: 16px; margin-top: 4px; }
            .radio-group label { display: flex; align-items: center; gap: 6px; font-size: 14px; margin-bottom: 0; cursor: pointer; }
            .radio-group input[type="radio"] { width: auto; margin: 0; }
            .mysql-fields { display: none; margin-top: 12px; padding: 12px; background: #f9f9f9; border-radius: 6px; }
            .mysql-fields.visible { display: block; }
            .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .field-row label { font-size: 12px; }
            .field-row input { font-size: 13px; }
            button { margin-top: 16px; width: 100%; padding: 12px; background: <?= $color ?>; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
            button:hover { opacity: 0.9; }
            button:disabled { opacity: 0.5; cursor: not-allowed; }
            a { color: <?= $color ?>; }
            code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>捷径社区安装程序</h1>
            <div class="step"><?= $msg ?></div>
    <?php
}

function installFooter(bool $done = false): void {
    if ($done): ?>
        <p style="text-align:center;margin-top:12px"><a href="/">前往首页</a></p>
    <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

function installHtml(string $msg, string $type = 'info', bool $done = false): void {
    installHeader($msg, $type);
    installFooter($done);
}

function checkPhpVersion(): ?string {
    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        return 'PHP 版本过低（当前: ' . PHP_VERSION . '），需要 >= 7.4';
    }
    return false;
}

function checkExtensions(): ?string {
    $missing = [];
    if (!extension_loaded('pdo_sqlite') && !extension_loaded('pdo_mysql')) $missing[] = 'PDO (至少需要 SQLite 或 MySQL)';
    if (!extension_loaded('json')) $missing[] = 'JSON';
    if (!extension_loaded('mbstring')) $missing[] = 'mbstring';
    if (!extension_loaded('openssl')) $missing[] = 'OpenSSL';
    return $missing ? '缺少 PHP 扩展: ' . implode(', ', $missing) : false;
}

function checkComposerDeps(): ?string {
    $autoload = ROOT_DIR . '/vendor/autoload.php';
    if (file_exists($autoload)) return false;
    return 'Composer 依赖未安装，请运行 composer install';
}

function writeEnv(string $driver, array $mysqlConfig = []): bool {
    $envFile = ROOT_DIR . '/.env';
    $lines = [];

    $jwtSecret = env('JWT_SECRET');
    if (!$jwtSecret) {
        $jwtSecret = 'sc_' . bin2hex(random_bytes(32));
    }
    $lines[] = 'JWT_SECRET=' . $jwtSecret;

    $lines[] = 'DB_DRIVER=' . $driver;

    if ($driver === 'mysql') {
        $lines[] = 'DB_HOST=' . ($mysqlConfig['host'] ?? 'localhost');
        $lines[] = 'DB_PORT=' . ($mysqlConfig['port'] ?? '3306');
        $lines[] = 'DB_NAME=' . ($mysqlConfig['name'] ?? 'shortcut');
        $lines[] = 'DB_USER=' . ($mysqlConfig['user'] ?? 'root');
        $lines[] = 'DB_PASS=' . ($mysqlConfig['pass'] ?? '');
    }

    $content = implode("\n", $lines) . "\n";
    return file_put_contents($envFile, $content) !== false;
}

function loadEnv(): void {
    $envFile = ROOT_DIR . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                setenv(trim($parts[0]), trim($parts[1], " \t\n\r\0\x0B\"'"));
            }
        }
    }
}

function initDatabase(): ?string {
    $driver = env('DB_DRIVER') ?: 'sqlite';

    if ($driver === 'mysql') {
        if (!extension_loaded('pdo_mysql')) {
            return 'MySQL 驱动不可用，请启用 pdo_mysql 扩展';
        }

        try {
            $host = env('DB_HOST') ?: 'localhost';
            $port = env('DB_PORT') ?: '3306';
            $name = str_replace(['`', '\\'], '', env('DB_NAME') ?: 'shortcut');
            $user = env('DB_USER') ?: 'root';
            $pass = env('DB_PASS') ?: '';

            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return false;
        } catch (PDOException $e) {
            return 'MySQL 连接失败: ' . $e->getMessage();
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        return 'SQLite 驱动不可用，请启用 pdo_sqlite 扩展';
    }

    $dataDir = ROOT_DIR . '/data';
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true)) return '无法创建 data/ 目录';
    }
    if (!is_writable($dataDir)) return 'data/ 目录不可写';

    try {
        $dbFile = $dataDir . '/database.sqlite';
        new PDO("sqlite:{$dbFile}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return false;
    } catch (PDOException $e) {
        return 'SQLite 初始化失败: ' . $e->getMessage();
    }
}

function hasAdmin(): bool {
    try {
        $db = Shortcut\Database::get();
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM users WHERE role IN ('admin', 'owner')");
        return (int) $stmt->fetch()['cnt'] > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function createOwner(string $username, string $email, string $password): ?string {
    try {
        $db = Shortcut\Database::get();

        $exists = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $exists->execute([$username, $email]);
        if ($exists->fetch()) return '站长账号已存在';

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)')
           ->execute([$username, $email, $hash, 'owner']);

        return false;
    } catch (PDOException $e) {
        return '创建站长账号失败: ' . $e->getMessage();
    }
}

function runMigrations(): void {
    try {
        $db = Shortcut\Database::get();
        if (!Shortcut\Database::isMySQL()) {
            try { $db->exec("ALTER TABLE shortcuts ADD COLUMN stats TEXT DEFAULT ''"); } catch (\Exception $e) {}
            try { $db->exec("ALTER TABLE shortcuts ADD COLUMN status TEXT DEFAULT 'active'"); } catch (\Exception $e) {}
            try { $db->exec("ALTER TABLE shortcuts ADD COLUMN color TEXT DEFAULT '#000000'"); } catch (\Exception $e) {}
        }
    } catch (\Exception $e) {}
}

// ===== Main flow =====

require_once ROOT_DIR . '/vendor/autoload.php';
loadEnv();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $error = checkPhpVersion();
    if ($error) { installHtml($error, 'error'); return; }
    $error = checkExtensions();
    if ($error) { installHtml($error, 'error'); return; }
    $depsError = checkComposerDeps();
    if ($depsError) {
        installHtml('Composer 依赖未安装，请运行：<br><code>cd ' . ROOT_DIR . ' && composer install</code>', 'warning');
        return;
    }

    $error = initDatabase();
    if ($error) { installHtml($error, 'error'); return; }

    Shortcut\Database::init(ROOT_DIR);
    Shortcut\Database::get();
    runMigrations();

    if (hasAdmin()) {
        $driver = env('DB_DRIVER') ?: 'sqlite';
        installHtml('系统已安装完成！（' . strtoupper($driver) . '）<p>如果您需要重置，请手动清空数据库后重新访问此页面。</p>', 'success', true);
        return;
    }

    $currentDriver = env('DB_DRIVER') ?: 'sqlite';
    $msg = '环境检查通过，请配置管理员账号：';
    installHeader($msg, 'info');
    ?>
    <form method="post" id="installForm">
        <div class="section">
            <div class="section-title">数据库配置</div>
            <div class="radio-group">
                <label>
                    <input type="radio" name="db_driver" value="sqlite" <?= $currentDriver === 'sqlite' ? 'checked' : '' ?> onchange="toggleMySQL()"> SQLite（默认，零配置）
                </label>
                <label>
                    <input type="radio" name="db_driver" value="mysql" <?= $currentDriver === 'mysql' ? 'checked' : '' ?> onchange="toggleMySQL()"> MySQL
                </label>
            </div>
            <div class="mysql-fields <?= $currentDriver === 'mysql' ? 'visible' : '' ?>" id="mysqlFields">
                <div class="field-row">
                    <label>主机
                        <input name="db_host" value="<?= htmlspecialchars(env('DB_HOST') ?: 'localhost') ?>" placeholder="localhost">
                    </label>
                    <label>端口
                        <input name="db_port" value="<?= htmlspecialchars(env('DB_PORT') ?: '3306') ?>" placeholder="3306">
                    </label>
                </div>
                <div class="field-row">
                    <label>数据库名
                        <input name="db_name" value="<?= htmlspecialchars(env('DB_NAME') ?: 'shortcut') ?>" placeholder="shortcut">
                    </label>
                    <label>用户名
                        <input name="db_user" value="<?= htmlspecialchars(env('DB_USER') ?: 'root') ?>" placeholder="root">
                    </label>
                </div>
                <label>密码
                    <input name="db_pass" type="password" value="<?= htmlspecialchars(env('DB_PASS') ?: '') ?>" placeholder="数据库密码">
                </label>
            </div>
        </div>

        <div class="section">
            <div class="section-title">站长账号</div>
            <label>用户名
                <input name="username" required minlength="2" maxlength="20" placeholder="至少 2 个字符">
            </label>
            <label>邮箱
                <input name="email" type="email" required placeholder="admin@example.com">
            </label>
            <label>密码
                <input name="password" type="password" required minlength="6" placeholder="至少 6 个字符">
            </label>
        </div>

        <button type="submit">完成安装</button>
    </form>
    <script>
        function toggleMySQL() {
            var mysql = document.getElementById('mysqlFields');
            var driver = document.querySelector('input[name="db_driver"]:checked').value;
            if (driver === 'mysql') {
                mysql.classList.add('visible');
            } else {
                mysql.classList.remove('visible');
            }
        }
    </script>
    <?php installFooter(); ?>
    <?php
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hasAdmin()) {
        installHeader('系统已安装，安装程序已禁用。如需重置安装，请手动清空数据库并删除 install.php。', 'warning');
        installFooter();
        return;
    }

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $dbDriver = trim($_POST['db_driver'] ?? 'sqlite');

    if (mb_strlen($username) < 2) {
        installHtml('用户名至少需要 2 个字符', 'error'); return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        installHtml('请输入有效的邮箱地址', 'error'); return;
    }
    if (strlen($password) < 6) {
        installHtml('密码至少需要 6 个字符', 'error'); return;
    }

    $mysqlConfig = [];
    if ($dbDriver === 'mysql') {
        $mysqlConfig = [
            'host' => trim($_POST['db_host'] ?? 'localhost'),
            'port' => trim($_POST['db_port'] ?? '3306'),
            'name' => str_replace(['`', '\\'], '', trim($_POST['db_name'] ?? 'shortcut')),
            'user' => trim($_POST['db_user'] ?? 'root'),
            'pass' => trim($_POST['db_pass'] ?? ''),
        ];
        if (empty($mysqlConfig['host'])) $mysqlConfig['host'] = 'localhost';
        if (empty($mysqlConfig['port'])) $mysqlConfig['port'] = '3306';
        if (empty($mysqlConfig['name'])) $mysqlConfig['name'] = 'shortcut';
        if (empty($mysqlConfig['user'])) $mysqlConfig['user'] = 'root';
    }

    if (!writeEnv($dbDriver, $mysqlConfig)) {
        installHtml('无法写入 .env 配置文件，请检查目录权限', 'error'); return;
    }

    loadEnv();

    $error = initDatabase();
    if ($error) { installHtml($error, 'error'); return; }

    Shortcut\Database::init(ROOT_DIR);
    Shortcut\Database::get();
    runMigrations();

    $error = createOwner($username, $email, $password);
    if ($error) {
        installHtml($error, 'error');
        return;
    }

    $driverLabel = $dbDriver === 'mysql'
        ? 'MySQL (' . $mysqlConfig['host'] . '/' . $mysqlConfig['name'] . ')'
        : 'SQLite';

    installHtml(
        '安装完成！<p>数据库: <strong>' . $driverLabel . '</strong></p><p>站长账号: <strong>' . htmlspecialchars($username) . '</strong></p><p>请妥善保管您的登录信息。</p>',
        'success',
        true
    );
}
