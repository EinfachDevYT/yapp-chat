<?php
declare(strict_types=1);

namespace Nooky\Chat;

/**
 * Accounts. Neue User legt ausschließlich ein Admin an (Admin-Seite oder
 * bin/setup.php) – eine Selbstregistrierung gibt es nicht.
 */
final class Users
{
    /** Benutzername: 3–20 Zeichen, Kleinbuchstaben, Ziffern, Punkt, Unterstrich. */
    private const USERNAME_PATTERN = '/^[a-z0-9_.]{3,20}$/';

    private const DISPLAY_NAME_MAX = 40;
    private const PASSWORD_MIN     = 8;
    private const PASSWORD_MAX     = 72; // bcrypt ignoriert alles danach

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    /** Gibt eine Fehlermeldung zurück oder null, wenn der Name passt. */
    public static function validateUsername(string $username): ?string
    {
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return 'Benutzername: 3–20 Zeichen, nur a–z, 0–9, Punkt und Unterstrich.';
        }
        return null;
    }

    public static function validateDisplayName(string $name): ?string
    {
        if ($name === '' || mb_strlen($name) > self::DISPLAY_NAME_MAX) {
            return 'Anzeigename: 1–' . self::DISPLAY_NAME_MAX . ' Zeichen.';
        }
        if (preg_match('/[\p{C}]/u', $name)) {
            return 'Anzeigename enthält unzulässige Zeichen.';
        }
        return null;
    }

    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) < self::PASSWORD_MIN) {
            return 'Passwort: mindestens ' . self::PASSWORD_MIN . ' Zeichen.';
        }
        if (strlen($password) > self::PASSWORD_MAX) {
            return 'Passwort: höchstens ' . self::PASSWORD_MAX . ' Bytes.';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Gut abtippbares Startpasswort, z. B. „k7mq-x3vt-9pwe“ (ohne Verwechsler). */
    public static function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            if ($i > 0 && $i % 4 === 0) {
                $out .= '-';
            }
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** Legt einen User an und gibt seine ID zurück. Validierung macht der Aufrufer. */
    public static function create(
        string $username,
        string $displayName,
        string $password,
        bool $isAdmin = false,
        bool $mustChangePassword = true,
    ): int {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO users (username, display_name, pass_hash, is_admin, must_change_pw, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            self::normalizeUsername($username),
            $displayName,
            self::hash($password),
            $isAdmin ? 1 : 0,
            $mustChangePassword ? 1 : 0,
            time(),
        ]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([self::normalizeUsername($username)]);
        return $stmt->fetch() ?: null;
    }

    public static function all(): array
    {
        return Db::pdo()->query('SELECT * FROM users ORDER BY username')->fetchAll();
    }

    public static function countActiveAdmins(): int
    {
        return (int) Db::pdo()
            ->query("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND status = 'active'")
            ->fetchColumn();
    }

    public static function setPassword(int $id, string $password, bool $mustChange): void
    {
        $stmt = Db::pdo()->prepare('UPDATE users SET pass_hash = ?, must_change_pw = ? WHERE id = ?');
        $stmt->execute([self::hash($password), $mustChange ? 1 : 0, $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Db::pdo()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status === 'locked' ? 'locked' : 'active', $id]);
    }

    public static function setAdmin(int $id, bool $isAdmin): void
    {
        $stmt = Db::pdo()->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
        $stmt->execute([$isAdmin ? 1 : 0, $id]);
    }

    /** Löscht den User samt Sitzungen, Chats (ON DELETE CASCADE) und Dateien. */
    public static function delete(int $id): void
    {
        $files = Chats::photosOf($id);
        $files[] = self::find($id)['avatar'] ?? null;

        $stmt = Db::pdo()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);

        foreach ($files as $f) {
            Media::delete($f);
        }
    }

    // ---- Profil ---------------------------------------------------------

    /** Info-Text („Bio“) wie bei WhatsApp: kurz, eine Zeile. */
    public const BIO_MAX = 139;

    /** Vorschläge für die Info, antippen statt tippen. */
    public const BIO_PRESETS = [
        'Hey, ich bin bei nooky!',
        'Verfügbar',
        'Beschäftigt',
        'In der Schule',
        'Bei der Arbeit',
        'Am Zocken 🎮',
        'Schlafe 😴',
        'Nur Notfälle',
    ];

    public static function validateBio(string $bio): ?string
    {
        if (mb_strlen($bio) > self::BIO_MAX) {
            return 'Info: höchstens ' . self::BIO_MAX . ' Zeichen.';
        }
        if (preg_match('/[\p{Cc}]/u', $bio)) {
            return 'Info enthält unzulässige Zeichen.';
        }
        return null;
    }

    public static function updateProfile(int $id, string $displayName, string $bio): void
    {
        $old = self::find($id);
        $bioChanged = ($old['bio'] ?? '') !== $bio;
        $stmt = Db::pdo()->prepare(
            'UPDATE users SET display_name = ?, bio = ?, bio_updated_at = CASE WHEN ? THEN ? ELSE bio_updated_at END WHERE id = ?'
        );
        $stmt->execute([$displayName, $bio !== '' ? $bio : null, $bioChanged ? 1 : 0, time(), $id]);
    }

    /** Setzt (oder entfernt mit null) das Profilbild und löscht das alte. */
    public static function setAvatar(int $id, ?string $file): void
    {
        $old = self::find($id)['avatar'] ?? null;
        Db::pdo()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$file, $id]);
        if ($old !== null && $old !== $file) {
            Media::delete($old);
        }
    }

    /** Aktive User außer $me, für „Neuer Chat“. */
    public static function contactsFor(int $me): array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT id, username, display_name, avatar, bio, last_seen_at, logged_out_at FROM users
             WHERE id != ? AND status = 'active' ORDER BY display_name COLLATE NOCASE"
        );
        $stmt->execute([$me]);
        return $stmt->fetchAll();
    }

    // ---- Online-Status --------------------------------------------------

    /**
     * Online = in den letzten 75 s aktiv und seitdem nicht abgemeldet.
     * Offene Seiten melden sich alle paar Sekunden (Polling), der Server
     * schreibt das höchstens alle 30 s – nach dem Schließen ist man also
     * nach spätestens gut einer Minute offline, nach dem Abmelden sofort.
     */
    public const ONLINE_WINDOW = 75;

    public static function isOnline(?array $user): bool
    {
        if ($user === null || $user['last_seen_at'] === null) {
            return false;
        }
        $seen = (int) $user['last_seen_at'];
        return time() - $seen < self::ONLINE_WINDOW && $seen > (int) ($user['logged_out_at'] ?? 0);
    }

    /** „online“, „zuletzt online heute um 14:32“, „… gestern um …“, „… am 03.10.“ */
    public static function presence(?array $user): string
    {
        if ($user === null || $user['status'] !== 'active') {
            return 'nicht verfügbar';
        }
        if (self::isOnline($user)) {
            return 'online';
        }
        if ($user['last_seen_at'] === null) {
            return 'noch nie online';
        }
        $ts   = (int) $user['last_seen_at'];
        $time = date('H:i', $ts);
        if (date('Y-m-d', $ts) === date('Y-m-d')) {
            return "zuletzt online heute um $time";
        }
        if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) {
            return "zuletzt online gestern um $time";
        }
        return 'zuletzt online am ' . date(date('Y', $ts) === date('Y') ? 'd.m.' : 'd.m.Y', $ts);
    }
}
