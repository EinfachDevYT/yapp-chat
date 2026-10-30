<?php
declare(strict_types=1);

/**
 * Liefert Fotos und Profilbilder aus data/uploads aus – nur an eingeloggte
 * User und nur, wenn sie die Datei sehen dürfen (Chat-Mitglied bzw. Profilbild).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Nooky\Chat\Auth;
use Nooky\Chat\Chats;
use Nooky\Chat\Media;

$file = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';

if (!Auth::check() || !preg_match(Media::NAME_PATTERN, $file) || !Chats::canSeeFile($file, (int) Auth::id())) {
    http_response_code(404);
    exit;
}
Media::send($file);
