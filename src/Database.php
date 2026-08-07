<?php
namespace Shortcut;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;
    private static string $dbPath;

    public static function init(string $baseDir): void {
        self::$dbPath = $baseDir . '/data/database.sqlite';
        $dir = dirname(self::$dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public static function get(): PDO {
        if (self::$instance === null) {
            self::$instance = new PDO('sqlite:' . self::$dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance->exec('PRAGMA journal_mode = WAL');
            self::$instance->exec('PRAGMA foreign_keys = ON');
            self::initTables();
        }
        return self::$instance;
    }

    private static function initTables(): void {
        $db = self::$instance;

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
}
