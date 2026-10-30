<?php
declare(strict_types=1);

/**
 * JSON-API für die Live-Funktionen des Chats (wird von app.js benutzt).
 *
 *   GET  ?a=messages&chat=ID[&after=ID|&before=ID][&since=TS][&read=ID]
 *        Nachrichten abholen, „für alle gelöschte“ seit TS, Lesestand des
 *        Gegenübers, Online-Status. read=ID markiert bis dahin als gelesen.
 *   POST ?a=send    chat, text, photo (Datei)
 *   POST ?a=delete  id, scope=me|all
 *   GET  ?a=chats   Chat-Liste als HTML + Zahl ungelesener Nachrichten
 *   GET  ?a=push_key        öffentlicher VAPID-Schlüssel
 *   POST ?a=subscribe       endpoint (Gerät für Push anmelden)
 *   POST ?a=unsubscribe     endpoint
 *   GET  ?a=notify          Text für die Benachrichtigung (vom Service Worker)
 *
 * POST-Anfragen brauchen den CSRF-Token im Header X-CSRF-Token.
 */

const NOOKY_API = true;

require dirname(__DIR__) . '/src/bootstrap.php';

use Nooky\Chat\Auth;
use Nooky\Chat\Chats;
use Nooky\Chat\Media;
use Nooky\Chat\Push;
use Nooky\Chat\Users;
use Nooky\Chat\View;

header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    out(['error' => $message], $status);
}

if (!Auth::check()) {
    fail('Nicht angemeldet.', 401);
}
if (Auth::mustChangePassword()) {
    fail('Bitte zuerst ein eigenes Passwort setzen.', 403);
}

$me     = (int) Auth::id();
$action = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($isPost && !Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    fail('Sitzung abgelaufen. Bitte Seite neu laden.', 400);
}

switch ($action) {
    case 'messages':
        Push::markActive(Auth::user());
        $chat = Chats::forUser((int) ($_GET['chat'] ?? 0), $me);
        if ($chat === null) {
            fail('Chat nicht gefunden.', 404);
        }
        $chatId = (int) $chat['id'];
        $now    = time();

        if (($read = (int) ($_GET['read'] ?? 0)) > 0) {
            Chats::markRead($chatId, $me, $read);
        }

        $after  = (int) ($_GET['after'] ?? 0);
        $before = (int) ($_GET['before'] ?? 0);
        $rows   = Chats::messages($chatId, $me, $after, $before);
        $other  = $chat['other'];

        out([
            'now'      => $now,
            'messages' => array_map(static fn (array $m) => Chats::present($m, $me), $rows),
            'more'     => $after === 0 && count($rows) >= 60,
            'deleted'  => isset($_GET['since']) ? Chats::deletedSince($chatId, (int) $_GET['since']) : [],
            'read_by_other' => Chats::readBy($chatId, (int) $other['id']),
            'presence' => Users::presence($other),
            'online'   => Users::isOnline($other),
        ]);

    case 'send':
        if (!$isPost) {
            fail('POST erwartet.', 405);
        }
        $chat = Chats::forUser((int) ($_POST['chat'] ?? 0), $me);
        if ($chat === null) {
            fail('Chat nicht gefunden.', 404);
        }
        if (($chat['other']['status'] ?? '') !== 'active') {
            fail('Dieser Account ist nicht mehr aktiv.', 409);
        }

        $text = is_string($_POST['text'] ?? null) ? trim(str_replace("\r\n", "\n", $_POST['text'])) : '';
        if (mb_strlen($text) > Chats::MAX_TEXT) {
            fail('Die Nachricht ist zu lang (max. ' . Chats::MAX_TEXT . ' Zeichen).');
        }

        $photo = null;
        if (isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photo = Media::storePhoto($_FILES['photo'], $error);
            if ($photo === null) {
                fail($error ?? 'Upload fehlgeschlagen.');
            }
        } elseif (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            // PHP verwirft zu große Anfragen komplett (post_max_size).
            fail('Das Bild ist zu groß.', 413);
        }

        if ($text === '' && $photo === null) {
            fail('Leere Nachricht.');
        }
        $row = Chats::send((int) $chat['id'], $me, $text !== '' ? $text : null, $photo);

        // Erst antworten, dann das Gegenüber per Push wecken (blockiert den Absender nicht).
        echo json_encode(['message' => Chats::present($row, $me)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        Push::notify((int) $chat['other']['id']);
        exit;

    case 'delete':
        if (!$isPost) {
            fail('POST erwartet.', 405);
        }
        $scope = ($_POST['scope'] ?? '') === 'all' ? 'all' : 'me';
        $err   = Chats::delete((int) ($_POST['id'] ?? 0), $me, $scope);
        if ($err !== null) {
            fail($err);
        }
        out(['ok' => true]);

    case 'chats':
        Push::markActive(Auth::user());
        $chats = Chats::listFor($me);
        out([
            'html'   => View::chatList($chats, $me),
            'unread' => array_sum(array_column($chats, 'unread')),
        ]);

    case 'push_key':
        out(['key' => Push::publicKey()]);

    case 'subscribe':
    case 'unsubscribe':
        if (!$isPost) {
            fail('POST erwartet.', 405);
        }
        $endpoint = is_string($_POST['endpoint'] ?? null) ? $_POST['endpoint'] : '';
        if ($action === 'unsubscribe') {
            Push::unsubscribe($me, $endpoint);
            out(['ok' => true]);
        }
        if (!Push::subscribe($me, $endpoint)) {
            fail('Ungültiges Abo.');
        }
        out(['ok' => true]);

    case 'push_test':
        if (!$isPost) {
            fail('POST erwartet.', 405);
        }
        Push::notify($me, force: true);
        out(['ok' => true]);

    case 'notify':
        // Neueste ungelesene Nachricht für die Benachrichtigung.
        $chats  = Chats::listFor($me);
        $unread = array_sum(array_column($chats, 'unread'));
        foreach ($chats as $c) {
            if ($c['unread'] > 0 && $c['last'] !== null) {
                $l = $c['last'];
                $body = $l['photo'] ? '📷 ' . ($l['body'] ?: 'Foto') : (string) $l['body'];
                out([
                    'title'  => $c['display_name'],
                    'body'   => ($c['unread'] > 1 ? $c['unread'] . ' neue Nachrichten · ' : '') . mb_substr($body, 0, 140),
                    'chat'   => (int) $c['id'],
                    'icon'   => $c['avatar'] ? 'media.php?f=' . $c['avatar'] : 'brand/icon-192.png',
                    'unread' => $unread,
                ]);
            }
        }
        out(['title' => 'nooky', 'body' => 'Neue Nachricht', 'chat' => 0, 'icon' => 'brand/icon-192.png', 'unread' => $unread]);

    default:
        fail('Unbekannte Aktion.', 404);
}
