<?php
declare(strict_types=1);

/**
 * Einrichtung des Chats:
 *   - legt die Datenbank an bzw. bringt das Schema auf den neuesten Stand
 *   - legt den ersten Admin an (oder setzt bei vorhandenem Namen das
 *     Passwort neu und macht ihn wieder zum aktiven Admin – Notfall-Reset)
 *
 * Aufruf (als der User, dem data/ gehört, z. B. www-data):
 *   sudo -u www-data php bin/setup.php
 *
 * Nicht-interaktiv:
 *   ADMIN_USER=admin ADMIN_NAME="Admin" ADMIN_PASS=geheim123 php bin/setup.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur über die Kommandozeile.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Users.php';

use Nooky\Chat\Db;
use Nooky\Chat\Users;

function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden && stream_isatty(STDIN)) {
        shell_exec('stty -echo 2>/dev/null');
        $value = rtrim((string) fgets(STDIN), "\n");
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    } else {
        $value = rtrim((string) fgets(STDIN), "\n");
    }
    return $value;
}

echo "== nooky-chat Setup ==\n\n";

$dataDir = dirname(__DIR__) . '/data';
if (!is_writable($dataDir)) {
    fwrite(STDERR, "data/ ist nicht beschreibbar. Als richtigen User aufrufen (z. B. sudo -u www-data).\n");
    exit(1);
}

// Datenbank anlegen/migrieren.
$pdo = Db::pdo();
@chmod(Db::path(), 0640);
echo "Datenbank:  " . Db::path() . " (Schema v" . Db::version() . ")\n";

// Erster Admin.
$user = Users::normalizeUsername(getenv('ADMIN_USER') ?: '');
$name = trim(getenv('ADMIN_NAME') ?: '');
$pass = getenv('ADMIN_PASS') ?: '';

if ($user === '') {
    $user = Users::normalizeUsername(prompt("\nAdmin-Benutzername: "));
}
while (($err = Users::validateUsername($user)) !== null) {
    fwrite(STDOUT, $err . "\n");
    $user = Users::normalizeUsername(prompt("Admin-Benutzername: "));
}

$existing = Users::findByUsername($user);

if ($name === '') {
    $name = $existing['display_name'] ?? '';
}
if ($name === '') {
    $name = trim(prompt("Anzeigename [{$user}]: ")) ?: $user;
}
if (($err = Users::validateDisplayName($name)) !== null) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}

if ($pass === '') {
    $pass  = prompt("Passwort: ", true);
    $again = prompt("Passwort wiederholen: ", true);
    if ($pass !== $again) {
        fwrite(STDERR, "Passwörter stimmen nicht überein.\n");
        exit(1);
    }
}
if (($err = Users::validatePassword($pass)) !== null) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}

if ($existing === null) {
    Users::create($user, $name, $pass, isAdmin: true, mustChangePassword: false);
    echo "Admin:      '{$user}' angelegt\n";
} else {
    $id = (int) $existing['id'];
    Users::setPassword($id, $pass, mustChange: false);
    Users::setAdmin($id, true);
    Users::setStatus($id, 'active');
    $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name, $id]);
    $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
    echo "Admin:      '{$user}' existierte – Passwort neu gesetzt, Admin + aktiv\n";
}

echo "\nFertig. Weitere Accounts legst du im Chat unter „Admin“ an.\n";
