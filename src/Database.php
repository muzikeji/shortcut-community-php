<?php
namespace Shortcut;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;
    private static string $dns;
    private static ?string $user = null;
    private static ?string $pass = null;

    public static function init(string $baseDir): void {
        $driver = env('DB_DRIVER') ?: 'sqlite';

        if ($driver === 'mysql') {
            $host = env('DB_HOST') ?: 'localhost';
            $port = env('DB_PORT') ?: '3306';
            $name = env('DB_NAME') ?: 'shortcut';
            self::$user = env('DB_USER') ?: 'root';
            self::$pass = env('DB_PASS') ?: '';
            self::$dns = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        } else {
            $dbPath = $baseDir . '/data/database.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$dns = 'sqlite:' . $dbPath;
        }
    }

    public static function isMySQL(): bool {
        return strpos(self::$dns, 'mysql:') === 0;
    }

    public static function get(): PDO {
        if (self::$instance === null) {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            if (self::isMySQL()) {
                self::$instance = new PDO(self::$dns, self::$user, self::$pass, $options);
            } else {
                self::$instance = new PDO(self::$dns, null, null, $options);
                self::$instance->exec('PRAGMA journal_mode = WAL');
                self::$instance->exec('PRAGMA foreign_keys = ON');
            }

            self::initTables();
        }
        return self::$instance;
    }

    private static function initTables(): void {
        $db = self::$instance;

        if (self::isMySQL()) {
            self::initMySQLTables($db);
        } else {
            self::initSQLiteTables($db);
        }
    }

    private static function initSQLiteTables(PDO $db): void {
        $db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                avatar TEXT DEFAULT \'\',
                bio TEXT DEFAULT \'\',
                role TEXT DEFAULT \'user\',
                banned INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS shortcuts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT UNIQUE NOT NULL,
                title TEXT NOT NULL,
                description TEXT DEFAULT \'\',
                category TEXT DEFAULT \'其他\',
                file_url TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                download_count INTEGER DEFAULT 0,
                like_count INTEGER DEFAULT 0,
                comment_count INTEGER DEFAULT 0,
                user_id INTEGER NOT NULL,
                color TEXT DEFAULT \'\',
                stats TEXT DEFAULT \'\',
                status TEXT DEFAULT \'active\',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS likes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                shortcut_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id),
                UNIQUE(shortcut_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                shortcut_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                content TEXT NOT NULL,
                parent_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (parent_id) REFERENCES comments(id)
            );

            CREATE TABLE IF NOT EXISTS shortcut_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                shortcut_id INTEGER NOT NULL,
                url TEXT NOT NULL,
                version_note TEXT DEFAULT \'\',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT \'\'
            );

            CREATE TABLE IF NOT EXISTS login_attempts (
                ip TEXT NOT NULL,
                attempt_time INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_shortcuts_user ON shortcuts(user_id);
            CREATE INDEX IF NOT EXISTS idx_shortcuts_created ON shortcuts(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_shortcuts_slug ON shortcuts(slug);
            CREATE INDEX IF NOT EXISTS idx_likes_shortcut ON likes(shortcut_id);
            CREATE INDEX IF NOT EXISTS idx_likes_user ON likes(user_id);
            CREATE INDEX IF NOT EXISTS idx_comments_shortcut ON comments(shortcut_id);
            CREATE INDEX IF NOT EXISTS idx_versions_shortcut ON shortcut_versions(shortcut_id);
        ');
    }

    private static function initMySQLTables(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                avatar VARCHAR(500) DEFAULT '',
                bio TEXT DEFAULT '',
                role VARCHAR(20) DEFAULT 'user',
                banned TINYINT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS shortcuts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(255) NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL,
                description TEXT DEFAULT '',
                category VARCHAR(50) DEFAULT '其他',
                file_url VARCHAR(500) NOT NULL,
                file_size INT DEFAULT 0,
                download_count INT DEFAULT 0,
                like_count INT DEFAULT 0,
                comment_count INT DEFAULT 0,
                user_id INT NOT NULL,
                color VARCHAR(50) DEFAULT '',
                stats TEXT DEFAULT '',
                status VARCHAR(20) DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS likes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shortcut_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id),
                UNIQUE(shortcut_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shortcut_id INT NOT NULL,
                user_id INT NOT NULL,
                content TEXT NOT NULL,
                parent_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (parent_id) REFERENCES comments(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS shortcut_versions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shortcut_id INT NOT NULL,
                url VARCHAR(500) NOT NULL,
                version_note TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(100) PRIMARY KEY,
                `value` TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS login_attempts (
                ip VARCHAR(45) NOT NULL,
                attempt_time INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        self::createMySQLIndex($db, 'idx_shortcuts_user', 'shortcuts', 'user_id');
        self::createMySQLIndex($db, 'idx_shortcuts_created', 'shortcuts', 'created_at');
        self::createMySQLIndex($db, 'idx_shortcuts_slug', 'shortcuts', 'slug');
        self::createMySQLIndex($db, 'idx_likes_shortcut', 'likes', 'shortcut_id');
        self::createMySQLIndex($db, 'idx_likes_user', 'likes', 'user_id');
        self::createMySQLIndex($db, 'idx_comments_shortcut', 'comments', 'shortcut_id');
        self::createMySQLIndex($db, 'idx_versions_shortcut', 'shortcut_versions', 'shortcut_id');
    }

    private static function createMySQLIndex(PDO $db, string $name, string $table, string $column): void {
        try {
            $db->exec("CREATE INDEX `{$name}` ON `{$table}` (`{$column}`)");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42000') throw $e;
        }
    }
}
