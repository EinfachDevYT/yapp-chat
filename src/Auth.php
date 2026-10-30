<?php
declare(strict_types=1);

namespace Yapp\Chat;

/**
 * Login, Sitzungen und CSRF-Schutz.
 *
 * Sitzungen liegen in der DB (Tabelle sessions), nicht in PHP-Sessions:
 * so kann der Admin einen User sofort überall abmelden (sperren, Passwort
 * zurücksetzen), und „zuletzt online“ fällt nebenbei ab. Im Cookie steht ein
 * zufälliges Token, in der DB nur dessen SHA-256.
 */
final class Auth
{
    private const COOKIE      = 'yapp_sid';
    private const CSRF_COOKIE = 'yapp_csrf';        // nur für Gäste (Login-Formular)

    private const LIFETIME    = 30 * 86400;         // Sitzung läuft nach 30 Tagen Inaktivität ab
    private const TOUCH_EVERY = 60;                 // last_seen höchstens einmal pro Minute schreiben

    private const FAIL_WINDOW    = 15 * 60;         // Zeitfenster für Fehlversuche
    private const MAX_FAILS_USER = 5;               // pro Benutzername + IP
    private const MAX_FAILS_IP   = 20;              // pro IP insgesamt

    /** Hash für Dummy-Verify bei unbekanntem User (gleicher Aufwand wie echter Login). */
    private const DUMMY_HASH = '$2y$12$Wsf5GILtn7DOicPetT8oDOxrlsGVWfbito..pGYO/3LkqfaJjeoYC';

    private static ?array $session = null;
    private static ?array $user    = null;

    /** Liest das Sitzungs-Cookie und lädt Sitzung + User. Einmal pro Request aufrufen. */
    public static function boot(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($token) || $token === '') {
            return;
        }

        $now  = time();
        $stmt = Db::pdo()->prepare(
            'SELECT s.*, u.status AS user_status
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ?'
        );
        $stmt->execute([hash('sha256', $token)]);
        $session = $stmt->fetch();

        if ($session === false || (int) $session['expires_at'] < $now || $session['user_status'] !== 'active') {
            if ($session !== false) {
                Db::pdo()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$session['id']]);
            }
            self::clearCookie(self::COOKIE);
            return;
        }

        // Aktivität vermerken (gedrosselt) und Ablauf nach hinten schieben.
        if ($now - (int) $session['last_seen_at'] >= self::TOUCH_EVERY) {
            $pdo = Db::pdo();
            $pdo->prepare('UPDATE sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?')
                ->execute([$now, $now + self::LIFETIME, $session['id']]);
            $pdo->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')
                ->execute([$now, $session['user_id']]);
            self::setCookie(self::COOKIE, $token, $now + self::LIFETIME);
        }

        self::$session = $session;
        self::$user    = Users::find((int) $session['user_id']);
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user !== null ? (int) self::$user['id'] : null;
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function isAdmin(): bool
    {
        return self::$user !== null && (int) self::$user['is_admin'] === 1;
    }

    public static function mustChangePassword(): bool
    {
        return self::$user !== null && (int) self::$user['must_change_pw'] === 1;
    }

    /**
     * Prüft Benutzername + Passwort und legt bei Erfolg eine neue Sitzung an.
     * $error enthält bei Misserfolg eine anzeigbare Meldung.
     */
    public static function attempt(string $username, string $password, ?string &$error = null): bool
    {
        $error    = null;
        $username = Users::normalizeUsername($username);
        $ip       = self::ip();
        $pdo      = Db::pdo();

        if (self::tooManyFailures($ip, $username)) {
            $error = 'Zu viele Fehlversuche. Bitte warte 15 Minuten.';
            return false;
        }

        $user = Users::findByUsername($username);
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
            self::recordFailure($ip, $username);
            $error = 'Benutzername oder Passwort falsch.';
            return false;
        }
        if (!password_verify($password, $user['pass_hash'])) {
            self::recordFailure($ip, $username);
            $error = 'Benutzername oder Passwort falsch.';
            return false;
        }
        if ($user['status'] !== 'active') {
            $error = 'Dieser Account ist gesperrt.';
            return false;
        }

        if (password_needs_rehash($user['pass_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $pdo->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
                ->execute([Users::hash($password), $user['id']]);
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND username = ?')->execute([$ip, $username]);

        self::startSession((int) $user['id']);
        return true;
    }

    /** Beendet die aktuelle Sitzung. */
    public static function logout(): void
    {
        if (self::$session !== null) {
            Db::pdo()->prepare('DELETE FROM sessions WHERE id = ?')->execute([self::$session['id']]);
        }
        self::clearCookie(self::COOKIE);
        self::$session = null;
        self::$user    = null;
    }

    /** Meldet einen User auf allen Geräten ab, optional außer der aktuellen Sitzung. */
    public static function logoutEverywhere(int $userId, bool $keepCurrent = false): void
    {
        if ($keepCurrent && self::$session !== null) {
            Db::pdo()->prepare('DELETE FROM sessions WHERE user_id = ? AND id != ?')
                ->execute([$userId, self::$session['id']]);
            return;
        }
        Db::pdo()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
    }

    /** Lädt den User der aktuellen Sitzung neu (nach eigenen Änderungen). */
    public static function refresh(): void
    {
        if (self::$user !== null) {
            self::$user = Users::find((int) self::$user['id']);
        }
    }

    // ---- CSRF -----------------------------------------------------------

    /** CSRF-Token: eingeloggt aus der Sitzung, als Gast aus einem eigenen Cookie. */
    public static function csrfToken(): string
    {
        if (self::$session !== null) {
            return self::$session['csrf'];
        }
        $token = $_COOKIE[self::CSRF_COOKIE] ?? '';
        if (!is_string($token) || strlen($token) !== 32) {
            $token = bin2hex(random_bytes(16));
            self::setCookie(self::CSRF_COOKIE, $token, 0);
            $_COOKIE[self::CSRF_COOKIE] = $token;
        }
        return $token;
    }

    public static function checkCsrf(mixed $token): bool
    {
        $expected = self::$session !== null
            ? self::$session['csrf']
            : ($_COOKIE[self::CSRF_COOKIE] ?? '');

        return is_string($token) && is_string($expected) && $expected !== ''
            && hash_equals($expected, $token);
    }

    // ---- Flash-Meldungen (einmalig, über einen Redirect hinweg) ---------

    public static function flash(string $type, string $message): void
    {
        if (self::$session === null) {
            return;
        }
        Db::pdo()->prepare('UPDATE sessions SET flash = ? WHERE id = ?')
            ->execute([json_encode(['type' => $type, 'msg' => $message], JSON_UNESCAPED_UNICODE), self::$session['id']]);
    }

    /** Gibt die Flash-Meldung zurück (['type' => …, 'msg' => …]) und löscht sie. */
    public static function takeFlash(): ?array
    {
        if (self::$session === null || self::$session['flash'] === null) {
            return null;
        }
        Db::pdo()->prepare('UPDATE sessions SET flash = NULL WHERE id = ?')->execute([self::$session['id']]);
        $flash = json_decode((string) self::$session['flash'], true);
        self::$session['flash'] = null;
        return is_array($flash) ? $flash : null;
    }

    // ---- intern ---------------------------------------------------------

    private static function startSession(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $now   = time();
        $pdo   = Db::pdo();

        // Abgelaufene Sitzungen bei der Gelegenheit aufräumen.
        $pdo->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([$now]);

        $pdo->prepare(
            'INSERT INTO sessions (token_hash, user_id, csrf, ip, user_agent, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $token),
            $userId,
            bin2hex(random_bytes(16)),
            self::ip(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $now,
            $now,
            $now + self::LIFETIME,
        ]);
        $pdo->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([$now, $userId]);

        self::setCookie(self::COOKIE, $token, $now + self::LIFETIME);
        self::clearCookie(self::CSRF_COOKIE);

        $stmt = $pdo->prepare('SELECT * FROM sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        self::$session = $stmt->fetch() ?: null;
        self::$user    = Users::find($userId);
    }

    private static function tooManyFailures(string $ip, string $username): bool
    {
        $pdo   = Db::pdo();
        $since = time() - self::FAIL_WINDOW;

        $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([$since]);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ?');
        $stmt->execute([$ip, $username]);
        if ((int) $stmt->fetchColumn() >= self::MAX_FAILS_USER) {
            return true;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() >= self::MAX_FAILS_IP;
    }

    private static function recordFailure(string $ip, string $username): void
    {
        Db::pdo()->prepare('INSERT INTO login_attempts (ts, ip, username) VALUES (?, ?, ?)')
            ->execute([time(), $ip, mb_substr($username, 0, 64)]);
    }

    /** Client-IP. nginx spricht direkt mit PHP-FPM, daher reicht REMOTE_ADDR. */
    private static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    private static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookie(string $name): void
    {
        self::setCookie($name, '', time() - 3600);
        unset($_COOKIE[$name]);
    }
}
