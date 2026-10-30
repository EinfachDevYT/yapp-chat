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

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
     . "frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

Nooky\Chat\Auth::boot();
