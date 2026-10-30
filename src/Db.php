<?php
declare(strict_types=1);

namespace Nooky\Chat;

use PDO;

/**
 * Schlanker SQLite-Zugriff für den Chat. Das Schema wird über
 * PRAGMA user_version versioniert: neue Änderungen kommen als neuer Eintrag
 * in MIGRATIONS dazu, bestehende Einträge werden nie verändert.
 */
final class Db
{
    private static ?PDO $pdo = null;

    private const MIGRATIONS = [
        1 => <<<'SQL'
            CREATE TABLE users (
                id             INTEGER PRIMARY KEY,
                username       TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                display_name   TEXT    NOT NULL,
                pass_hash      TEXT    NOT NULL,
                is_admin       INTEGER NOT NULL DEFAULT 0,
                must_change_pw INTEGER NOT NULL DEFAULT 1,   -- Startpasswort vom Admin → beim ersten Login ändern
                status         TEXT    NOT NULL DEFAULT 'active', -- active | locked
                bio            TEXT,
                avatar         TEXT,                          -- Dateiname in data/uploads
                created_at     INTEGER NOT NULL,
                last_seen_at   INTEGER
            );

            -- Login-Sitzungen. Im Cookie steht nur das Token, hier nur dessen SHA-256.
            CREATE TABLE sessions (
                id           INTEGER PRIMARY KEY,
                token_hash   TEXT    NOT NULL UNIQUE,
                user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                csrf         TEXT    NOT NULL,
                flash        TEXT,                            -- einmalige Meldung für den nächsten Seitenaufruf
                ip           TEXT,
                user_agent   TEXT,
                created_at   INTEGER NOT NULL,
                last_seen_at INTEGER NOT NULL,
                expires_at   INTEGER NOT NULL
            );
            CREATE INDEX idx_sessions_user ON sessions(user_id);

            -- Fehlgeschlagene Logins (Bremse gegen Durchprobieren).
            CREATE TABLE login_attempts (
                id       INTEGER PRIMARY KEY,
                ts       INTEGER NOT NULL,
                ip       TEXT    NOT NULL,
                username TEXT    NOT NULL
            );
            CREATE INDEX idx_login_attempts_ts ON login_attempts(ts);

            -- 1:1-Chats. user_a ist immer die kleinere ID, damit es pro Paar genau einen Chat gibt.
            CREATE TABLE chats (
                id              INTEGER PRIMARY KEY,
                user_a          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                user_b          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                created_at      INTEGER NOT NULL,
                last_message_at INTEGER,
                CHECK (user_a < user_b),
                UNIQUE (user_a, user_b)
            );
            CREATE INDEX idx_chats_user_b ON chats(user_b);

            CREATE TABLE messages (
                id         INTEGER PRIMARY KEY,
                chat_id    INTEGER NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
                sender_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                body       TEXT,
                photo      TEXT,                              -- Dateiname in data/uploads
                created_at INTEGER NOT NULL,
                deleted_at INTEGER                            -- „für alle gelöscht“ (Inhalt wird geleert)
            );
            CREATE INDEX idx_messages_chat ON messages(chat_id, id);

            -- „Für mich gelöscht“: Nachricht ist nur für diesen User ausgeblendet.
            CREATE TABLE message_hidden (
                message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                PRIMARY KEY (message_id, user_id)
            );

            -- Lesebestätigung: bis zu welcher Nachricht hat der User den Chat gelesen.
            CREATE TABLE chat_reads (
                chat_id      INTEGER NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
                user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                last_read_id INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (chat_id, user_id)
            );
            SQL,

        // Fotomaße (Chat springt beim Laden nicht) und „Info seit“ fürs Profil.
        2 => <<<'SQL'
            ALTER TABLE users ADD COLUMN bio_updated_at INTEGER;
            ALTER TABLE messages ADD COLUMN photo_w INTEGER;
            ALTER TABLE messages ADD COLUMN photo_h INTEGER;
            CREATE INDEX idx_messages_deleted ON messages(chat_id, deleted_at);
            SQL,

        // Abmelden setzt sofort auf offline.
        3 => <<<'SQL'
            ALTER TABLE users ADD COLUMN logged_out_at INTEGER;
            SQL,

        // Push-Benachrichtigungen: ein Eintrag pro Gerät/Browser.
        // active_at = letzte Abfrage aus einer offenen App (dann kein Push nötig).
        4 => <<<'SQL'
            CREATE TABLE push_subscriptions (
                id         INTEGER PRIMARY KEY,
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                endpoint   TEXT    NOT NULL UNIQUE,
                created_at INTEGER NOT NULL,
                last_ok_at INTEGER
            );
            CREATE INDEX idx_push_user ON push_subscriptions(user_id);
            ALTER TABLE users ADD COLUMN active_at INTEGER;
            SQL,
    ];

    public static function path(): string
    {
        return dirname(__DIR__) . '/data/chat.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $pdo = new PDO('sqlite:' . self::path(), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        self::migrate($pdo);

        return $pdo;
    }

    /** Aktuelle Schema-Version. */
    public static function version(): int
    {
        return (int) self::pdo()->query('PRAGMA user_version')->fetchColumn();
    }

    private static function migrate(PDO $pdo): void
    {
        $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        foreach (self::MIGRATIONS as $version => $sql) {
            if ($version <= $current) {
                continue;
            }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                // Zweiter Prozess war schneller? Dann nichts doppelt anlegen.
                if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= $version) {
                    $pdo->exec('COMMIT');
                    continue;
                }
                $pdo->exec($sql);
                $pdo->exec('PRAGMA user_version = ' . $version);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }
    }
}
