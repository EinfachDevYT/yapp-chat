<?php
declare(strict_types=1);

namespace Nooky\Chat;

/**
 * 1:1-Chats und Nachrichten.
 *
 * Löschen:
 *   - „für mich“: Eintrag in message_hidden, nur der eigene Verlauf ändert sich.
 *   - „für alle“: nur eigene Nachrichten, nur innerhalb von 48 h. Text und Foto
 *     werden sofort entfernt, übrig bleibt „Nachricht gelöscht“.
 *
 * Lesebestätigung: chat_reads.last_read_id pro User und Chat.
 */
final class Chats
{
    public const DELETE_FOR_ALL_WINDOW = 48 * 3600;
    public const MAX_TEXT              = 4000;
    private const PAGE                 = 60;

    /** Holt den Chat zwischen zwei Usern oder legt ihn an. */
    public static function between(int $me, int $other): int
    {
        [$a, $b] = $me < $other ? [$me, $other] : [$other, $me];
        $pdo = Db::pdo();
        $pdo->prepare('INSERT OR IGNORE INTO chats (user_a, user_b, created_at) VALUES (?, ?, ?)')
            ->execute([$a, $b, time()]);
        $stmt = $pdo->prepare('SELECT id FROM chats WHERE user_a = ? AND user_b = ?');
        $stmt->execute([$a, $b]);
        return (int) $stmt->fetchColumn();
    }

    /** Chat + Gegenüber, nur wenn $me Mitglied ist. */
    public static function forUser(int $chatId, int $me): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM chats WHERE id = ? AND (user_a = ? OR user_b = ?)');
        $stmt->execute([$chatId, $me, $me]);
        $chat = $stmt->fetch();
        if ($chat === false) {
            return null;
        }
        $otherId = (int) $chat['user_a'] === $me ? (int) $chat['user_b'] : (int) $chat['user_a'];
        $chat['other'] = Users::find($otherId);
        return $chat;
    }

    /** Chat-Liste mit letzter Nachricht und Zahl ungelesener Nachrichten. */
    public static function listFor(int $me): array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.last_message_at, c.created_at,
                    u.id AS other_id, u.username, u.display_name, u.avatar, u.last_seen_at, u.status,
                    COALESCE(r.last_read_id, 0) AS last_read_id,
                    COALESCE((SELECT o.last_read_id FROM chat_reads o WHERE o.chat_id = c.id AND o.user_id = u.id), 0) AS other_read
             FROM chats c
             JOIN users u ON u.id = CASE WHEN c.user_a = :me THEN c.user_b ELSE c.user_a END
             LEFT JOIN chat_reads r ON r.chat_id = c.id AND r.user_id = :me
             WHERE (c.user_a = :me OR c.user_b = :me) AND c.last_message_at IS NOT NULL
             ORDER BY c.last_message_at DESC'
        );
        $stmt->execute([':me' => $me]);
        $chats = $stmt->fetchAll();

        $last = $pdo->prepare(
            'SELECT m.* FROM messages m
             WHERE m.chat_id = ? AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
             ORDER BY m.id DESC LIMIT 1'
        );
        $unread = $pdo->prepare(
            'SELECT COUNT(*) FROM messages m
             WHERE m.chat_id = ? AND m.sender_id != ? AND m.id > ? AND m.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)'
        );
        foreach ($chats as &$c) {
            $last->execute([$c['id'], $me]);
            $c['last'] = $last->fetch() ?: null;
            $unread->execute([$c['id'], $me, $c['last_read_id'], $me]);
            $c['unread'] = (int) $unread->fetchColumn();
        }
        return $chats;
    }

    public static function unreadTotal(int $me): int
    {
        return array_sum(array_column(self::listFor($me), 'unread'));
    }

    /**
     * Nachrichten für die Anzeige.
     *   after  > 0: alles nach dieser ID (Polling)
     *   before > 0: ältere Seite vor dieser ID
     *   sonst:      die neuesten
     */
    public static function messages(int $chatId, int $me, int $after = 0, int $before = 0): array
    {
        $hidden = 'NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = :me)';
        $pdo = Db::pdo();

        if ($after > 0) {
            $stmt = $pdo->prepare("SELECT m.* FROM messages m WHERE m.chat_id = :c AND m.id > :x AND $hidden ORDER BY m.id LIMIT 200");
            $stmt->execute([':c' => $chatId, ':x' => $after, ':me' => $me]);
            return $stmt->fetchAll();
        }
        $cond = $before > 0 ? 'AND m.id < :x' : '';
        $stmt = $pdo->prepare("SELECT m.* FROM messages m WHERE m.chat_id = :c $cond AND $hidden ORDER BY m.id DESC LIMIT " . self::PAGE);
        $params = [':c' => $chatId, ':me' => $me];
        if ($before > 0) {
            $params[':x'] = $before;
        }
        $stmt->execute($params);
        return array_reverse($stmt->fetchAll());
    }

    /** IDs der Nachrichten, die seit $since „für alle“ gelöscht wurden. */
    public static function deletedSince(int $chatId, int $since): array
    {
        $stmt = Db::pdo()->prepare('SELECT id FROM messages WHERE chat_id = ? AND deleted_at >= ?');
        $stmt->execute([$chatId, $since]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Für die Ausgabe ans Frontend (ohne interne Felder). */
    public static function present(array $m, int $me): array
    {
        $mine = (int) $m['sender_id'] === $me;
        return [
            'id'      => (int) $m['id'],
            'mine'    => $mine,
            'body'    => $m['deleted_at'] === null ? $m['body'] : null,
            'photo'   => $m['deleted_at'] === null && $m['photo'] ? 'media.php?f=' . $m['photo'] : null,
            'w'       => $m['photo_w'] !== null ? (int) $m['photo_w'] : null,
            'h'       => $m['photo_h'] !== null ? (int) $m['photo_h'] : null,
            'at'      => (int) $m['created_at'],
            'deleted' => $m['deleted_at'] !== null,
            // Bis wann „für alle löschen“ möglich ist (0 = gar nicht).
            'del_until' => $mine && $m['deleted_at'] === null ? (int) $m['created_at'] + self::DELETE_FOR_ALL_WINDOW : 0,
        ];
    }

    /** Neue Nachricht. Gibt die gespeicherte Zeile zurück. */
    public static function send(int $chatId, int $me, ?string $body, ?array $photo): array
    {
        $now = time();
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO messages (chat_id, sender_id, body, photo, photo_w, photo_h, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$chatId, $me, $body, $photo['file'] ?? null, $photo['w'] ?? null, $photo['h'] ?? null, $now]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE chats SET last_message_at = ? WHERE id = ?')->execute([$now, $chatId]);
        self::markRead($chatId, $me, $id);

        $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Löscht eine Nachricht. $scope: 'me' oder 'all'. Gibt eine Fehlermeldung oder null zurück. */
    public static function delete(int $messageId, int $me, string $scope): ?string
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT m.* FROM messages m JOIN chats c ON c.id = m.chat_id
             WHERE m.id = ? AND (c.user_a = ? OR c.user_b = ?)'
        );
        $stmt->execute([$messageId, $me, $me]);
        $m = $stmt->fetch();
        if ($m === false) {
            return 'Nachricht nicht gefunden.';
        }

        if ($scope === 'all') {
            if ((int) $m['sender_id'] !== $me) {
                return 'Nur eigene Nachrichten können für alle gelöscht werden.';
            }
            if ($m['deleted_at'] !== null) {
                return null;
            }
            if (time() - (int) $m['created_at'] > self::DELETE_FOR_ALL_WINDOW) {
                return 'Für alle löschen geht nur innerhalb von 48 Stunden.';
            }
            $pdo->prepare('UPDATE messages SET body = NULL, photo = NULL, photo_w = NULL, photo_h = NULL, deleted_at = ? WHERE id = ?')
                ->execute([time(), $messageId]);
            Media::delete($m['photo']);
            return null;
        }

        $pdo->prepare('INSERT OR IGNORE INTO message_hidden (message_id, user_id) VALUES (?, ?)')->execute([$messageId, $me]);
        return null;
    }

    /** Merkt sich, bis wohin $me gelesen hat (nur vorwärts). */
    public static function markRead(int $chatId, int $me, int $upTo): void
    {
        $pdo = Db::pdo();
        $max = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE chat_id = ?');
        $max->execute([$chatId]);
        $upTo = min($upTo, (int) $max->fetchColumn());
        if ($upTo <= 0) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO chat_reads (chat_id, user_id, last_read_id) VALUES (?, ?, ?)
             ON CONFLICT(chat_id, user_id) DO UPDATE SET last_read_id = MAX(last_read_id, excluded.last_read_id)'
        )->execute([$chatId, $me, $upTo]);
    }

    /** Bis zu welcher Nachricht das Gegenüber gelesen hat. */
    public static function readBy(int $chatId, int $userId): int
    {
        $stmt = Db::pdo()->prepare('SELECT last_read_id FROM chat_reads WHERE chat_id = ? AND user_id = ?');
        $stmt->execute([$chatId, $userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** Alle Dateien (Fotos) in Chats eines Users, z. B. vor dem Löschen des Accounts. */
    public static function photosOf(int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT m.photo FROM messages m JOIN chats c ON c.id = m.chat_id
             WHERE (c.user_a = ? OR c.user_b = ?) AND m.photo IS NOT NULL'
        );
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Darf $me die Datei sehen? (eigenes Chat-Foto oder Profilbild eines Users) */
    public static function canSeeFile(string $file, int $me): bool
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE avatar = ?');
        $stmt->execute([$file]);
        if ($stmt->fetchColumn()) {
            return true;
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM messages m JOIN chats c ON c.id = m.chat_id
             WHERE m.photo = ? AND (c.user_a = ? OR c.user_b = ?)'
        );
        $stmt->execute([$file, $me, $me]);
        return (bool) $stmt->fetchColumn();
    }
}
