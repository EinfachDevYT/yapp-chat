<?php
declare(strict_types=1);

/**
 * Gemeinsamer Start für alle Web-Seiten: Klassen laden, Config lesen,
 * Sicherheits-Header setzen, Sitzung laden.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nooky\\Chat\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config.php fehlt – bitte aus config.example.php erstellen.');
}
$config = require $configFile;

date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Berlin'));

header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
     . "frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// Lizenz: ohne gültige Lizenz gibt es statt des Chats nur die Sperrseite.
// Die Online-Prüfung läuft höchstens einmal pro Stunde (meist schon per Cron).
$licenseReason = null;
if ((new Nooky\Chat\License($config['license'] ?? []))->check($licenseReason) !== Nooky\Chat\License::OK) {
    http_response_code(503);
    header('Retry-After: 3600');
    if (defined('NOOKY_API')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'license', 'reason' => $licenseReason]);
        exit;
    }
    require __DIR__ . '/views/locked.php';
    exit;
}

Nooky\Chat\Auth::boot();
