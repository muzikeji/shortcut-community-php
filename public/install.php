<?php
header('Content-Type: text/html; charset=utf-8');
defined('ROOT_DIR') || define('ROOT_DIR', dirname(__DIR__));

function installHtml(string $msg, string $type = 'info', bool $done = false): void {
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
            h1 { font-size: 24px; margin-bottom: 24px; color: #333; }
            .step { background: <?= $bg ?>; border-left: 4px solid <?= $color ?>; padding: 16px; border-radius: 6px; margin-bottom: 16px; color: #555; line-height: 1.6; }
            .step p { margin: 4px 0; }
            .step .icon { font-size: 20px; margin-right: 8px; }
            form { margin-top: 20px; }
            label { display: block; margin-bottom: 12px; color: #666; }
            input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; margin-top: 4px; }
            button { margin-top: 16px; width: 100%; padding: 12px; background: <?= $color ?>; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
            button:hover { opacity: 0.9; }
            button:disabled { opacity: 0.5; cursor: not-allowed; }
            a { color: <?= $color ?>; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>捷径社区安装程序</h1>
            <div class="step"><?= $msg ?></div>
            <?php if ($done): ?>
                <p style="text-align:center;margin-top:12px"><a href="/">前往首页</a></p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

function checkPhpVersion(): string|false {
    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        return 'PHP 版本过低（当前: ' . PHP_VERSION . '），需要 >= 7.4';
    }
    return false;
}

function checkExtensions(): string|false {
    $missing = [];
    if (!extension_loaded('pdo_sqlite')) $missing[] = 'PDO SQLite';
    if (!extension_loaded('json')) $missing[] = 'JSON';
    if (!extension_loaded('mbstring')) $missing[] = 'mbstring';
    if (!extension_loaded('openssl')) $missing[] = 'OpenSSL';
    return $missing ? '缺少 PHP 扩展: ' . implode(', ', $missing) : false;
}

function checkComposerDeps(): string|false {
    $autoload = ROOT_DIR . '/vendor/autoload.php';
    if (file_exists($autoload)) return false;
    return 'Composer 依赖未安装，请运行 composer install';
}

function createDatabase(): string|false {
    $dataDir = ROOT_DIR . '/data';
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true)) return '无法创建 data/ 目录';
    }
    if (!is_writable($dataDir)) return 'data/ 目录不可写';

    $dbFile = $dataDir . '/database.sqlite';
    try {
        $db = new PDO("sqlite:{$dbFile}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            avatar TEXT DEFAULT '',
            bio TEXT DEFAULT '',
            role TEXT DEFAULT 'user',
            banned INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS shortcuts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            description TEXT DEFAULT '',
            category TEXT DEFAULT '其他',
            file_url TEXT NOT NULL,
            file_size INTEGER DEFAULT 0,
            download_count INTEGER DEFAULT 0,
            like_count INTEGER DEFAULT 0,
            comment_count INTEGER DEFAULT 0,
            user_id INTEGER NOT NULL,
            color TEXT DEFAULT '',
            stats TEXT DEFAULT '',
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS likes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shortcut_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, shortcut_id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shortcut_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            parent_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id),
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (parent_id) REFERENCES comments(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS shortcut_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shortcut_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            version_note TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT DEFAULT ''
        )");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_shortcuts_user ON shortcuts(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_shortcuts_created ON shortcuts(created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_shortcuts_slug ON shortcuts(slug)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_likes_shortcut ON likes(shortcut_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_likes_user ON likes(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_comments_shortcut ON comments(shortcut_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_versions_shortcut ON shortcut_versions(shortcut_id)");

        return false;
    } catch (PDOException $e) {
        return '数据库创建失败: ' . $e->getMessage();
    }
}

function createAdmin(string $username, string $email, string $password): string|false {
    $dbFile = ROOT_DIR . '/data/database.sqlite';
    try {
        $db = new PDO("sqlite:{$dbFile}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $exists = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $exists->execute([$username, $email]);
        if ($exists->fetch()) return '管理员账号已存在';

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)')
           ->execute([$username, $email, $hash, 'admin']);

        return false;
    } catch (PDOException $e) {
        return '创建管理员失败: ' . $e->getMessage();
    }
}

function runMigrations(): void {
    $dbFile = ROOT_DIR . '/data/database.sqlite';
    try {
        $db = new PDO("sqlite:{$dbFile}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try { $db->exec("ALTER TABLE shortcuts ADD COLUMN stats TEXT DEFAULT ''"); } catch (\Exception $e) {}
        try { $db->exec("ALTER TABLE shortcuts ADD COLUMN status TEXT DEFAULT 'active'"); } catch (\Exception $e) {}
        try { $db->exec("ALTER TABLE shortcuts ADD COLUMN color TEXT DEFAULT '#000000'"); } catch (\Exception $e) {}
    } catch (\Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $error = checkPhpVersion();
    if ($error) { installHtml("❌ {$error}", 'error'); return; }
    $error = checkExtensions();
    if ($error) { installHtml("❌ {$error}", 'error'); return; }

    $created = false;
    $error = createDatabase();
    if ($error && strpos($error, '数据库创建失败') === 0) {
        installHtml("❌ {$error}", 'error');
        return;
    }

    if ($error && strpos($error, 'Composer 依赖未安装') === 0) {
        installHtml($error .
            '<p>请在终端中运行：<br><code>cd ' . ROOT_DIR . ' && composer install</code></p>', 'warning');
        return;
    }

    $depsError = checkComposerDeps();
    if ($depsError) {
        installHtml('Composer 依赖未安装，请运行：<br><code>cd ' . ROOT_DIR . ' && composer install</code>', 'warning');
        return;
    }

    runMigrations();

    try {
        $dbFile = ROOT_DIR . '/data/database.sqlite';
        $db = new PDO("sqlite:{$dbFile}");
        $adminCount = $db->query("SELECT COUNT(*) as cnt FROM users WHERE role = 'admin'")->fetch()['cnt'];
    } catch (\Exception $e) {
        installHtml('❌ 数据库读取失败', 'error');
        return;
    }

    if ((int) $adminCount > 0) {
        installHtml('✅ 系统已安装完成！' . '<p>如果您需要重置，请删除 data/database.sqlite 后重新访问此页面。</p>', 'success', true);
        return;
    }

    $msg = '✅ 环境检查通过，数据库已就绪。<p>请创建管理员账号：</p>';
    installHtml($msg, 'info');
    ?>
    <form method="post">
        <label>用户名
            <input name="username" required minlength="2" maxlength="20" placeholder="至少 2 个字符">
        </label>
        <label>邮箱
            <input name="email" type="email" required placeholder="admin@example.com">
        </label>
        <label>密码
            <input name="password" type="password" required minlength="6" placeholder="至少 6 个字符">
        </label>
        <button type="submit">完成安装</button>
    </form>
    </div></body></html>
    <?php
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (mb_strlen($username) < 2) {
        installHtml('❌ 用户名至少需要 2 个字符', 'error'); return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        installHtml('❌ 请输入有效的邮箱地址', 'error'); return;
    }
    if (strlen($password) < 6) {
        installHtml('❌ 密码至少需要 6 个字符', 'error'); return;
    }

    $error = createAdmin($username, $email, $password);
    if ($error) {
        installHtml("❌ {$error}", 'error');
        return;
    }

    installHtml('✅ 安装完成！管理员账号 <strong>' . htmlspecialchars($username) . '</strong> 已创建。<p>请妥善保管您的登录信息。</p>', 'success', true);
}
