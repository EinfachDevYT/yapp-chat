<?php
declare(strict_types=1);

namespace Yapp\Chat;

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

    /** Löscht den User samt Sitzungen und Chats (ON DELETE CASCADE). */
    public static function delete(int $id): void
    {
        $stmt = Db::pdo()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }
}
