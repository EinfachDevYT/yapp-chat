<?php
declare(strict_types=1);

/**
 * Stündliche Lizenzprüfung per Cron – hält die Offline-Toleranz frisch,
 * auch wenn gerade niemand den Chat benutzt.
 *
 *   0 * * * * php /var/www/yapp-chat/bin/license-check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "config.php fehlt – bitte aus config.example.php erstellen.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/License.php';

$cfg     = require $configFile;
$license = new Yapp\Chat\License($cfg['license'] ?? []);

$reason = null;
$status = $license->check($reason);

fwrite(STDOUT, date('c') . "  Lizenz: {$status}" . ($reason ? " ({$reason})" : '') . "\n");
exit($status === Yapp\Chat\License::OK ? 0 : 1);
