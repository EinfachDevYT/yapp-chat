<?php
declare(strict_types=1);

namespace Nooky\Chat;

/**
 * Gemeinsame Bausteine fürs HTML: Seitenrahmen, Icons, Avatare.
 * Kein Inline-CSS/-JS (die Content-Security-Policy verbietet beides).
 */
final class View
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e(Auth::csrfToken()) . '">';
    }

    /** Kopf der Seite. $opts: body (Klasse), bar (false = keine Kopfleiste) */
    public static function head(string $title, array $opts = []): void
    {
        $e = self::e(...);
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">';
        echo '<title>' . $e($title) . ' · nooky</title>';
        echo '<meta name="theme-color" content="#12111b" media="(prefers-color-scheme: dark)">';
        echo '<meta name="theme-color" content="#fbf7f3" media="(prefers-color-scheme: light)">';
        echo '<link rel="icon" href="brand/icon.svg" type="image/svg+xml"><link rel="icon" href="brand/icon-32.png" sizes="32x32">';
        echo '<link rel="apple-touch-icon" href="brand/icon-180.png">';
        echo '<link rel="preload" href="fonts/figtree.woff2" as="font" type="font/woff2" crossorigin>';
        echo '<link rel="stylesheet" href="app.css"><script src="app.js" defer></script>';
        echo '</head><body class="' . $e($opts['body'] ?? '') . '"' . (Auth::check() ? ' data-csrf="' . $e(Auth::csrfToken()) . '"' : '') . '>';

        if (($opts['bar'] ?? true) === false) {
            return;
        }
        echo '<div class="app"><header class="topbar">';
        echo '<a class="logo" href="?page=home" aria-label="nooky – Chats">' . self::mark() . '<span>nooky</span></a><span class="spacer"></span>';
        if (Auth::check() && !Auth::mustChangePassword()) {
            $unread = Chats::unreadTotal((int) Auth::id());
            echo '<a class="iconbtn" href="?page=home" title="Chats" aria-label="Chats">' . self::icon('chat')
               . '<span class="dot" data-unread' . ($unread ? '' : ' hidden') . '>' . $unread . '</span></a>';
            if (Auth::isAdmin()) {
                echo '<a class="iconbtn" href="?page=admin" title="Accounts verwalten" aria-label="Admin">' . self::icon('shield') . '</a>';
            }
            echo '<a class="iconbtn" href="?page=profile" title="Mein Profil" aria-label="Mein Profil">' . self::icon('user') . '</a>';
        }
        if (Auth::check()) {
            echo '<form method="post" action="?page=logout" class="inline">' . self::csrfField()
               . '<button class="iconbtn" title="Abmelden" aria-label="Abmelden">' . self::icon('logout') . '</button></form>';
        }
        echo '</header>';
    }

    public static function foot(): void
    {
        echo '</div></body></html>';
    }

    public static function flash(?array $flash): void
    {
        if ($flash === null) {
            return;
        }
        if ($flash['type'] === 'pw') {
            $d = json_decode($flash['msg'], true);
            echo '<div class="card notice"><strong>Startpasswort für @' . self::e($d['user'] ?? '') . ':</strong>';
            echo '<p class="pw"><code>' . self::e($d['pw'] ?? '') . '</code></p>';
            echo '<p class="muted small">Wird nur jetzt angezeigt. Beim ersten Login setzt die Person ein eigenes Passwort.</p></div>';
            return;
        }
        echo '<div class="flash ' . ($flash['type'] === 'err' ? 'err' : 'ok') . '">' . self::e($flash['msg']) . '</div>';
    }

    /** Profilbild oder Anfangsbuchstabe. $size: '', 's', 'l' */
    public static function avatar(?array $user, string $size = '', bool $withOnline = false): string
    {
        $cls = 'avatar' . ($size !== '' ? ' ' . $size : '');
        if ($user !== null && !empty($user['avatar'])) {
            $inner = '<img src="media.php?f=' . self::e($user['avatar']) . '" alt="" loading="lazy">';
        } else {
            $name  = (string) ($user['display_name'] ?? '?');
            $inner = self::e(mb_strtoupper(mb_substr($name, 0, 1)));
        }
        $html = '<span class="' . $cls . '">' . $inner . '</span>';
        if ($withOnline) {
            $html = '<span class="avatar-wrap">' . $html . (Users::isOnline($user) ? '<span class="online-dot" title="online"></span>' : '') . '</span>';
        }
        return $html;
    }

    /** Chat-Liste (Startseite und Live-Aktualisierung über die API). */
    public static function chatList(array $chats, int $me): string
    {
        if ($chats === []) {
            return '<div class="empty">' . self::mark() . '<h2>Noch ganz schön leer hier</h2>'
                 . '<p>Tipp unten rechts auf <strong>+</strong> und schreib jemandem.</p></div>';
        }
        $html = '<div class="list">';
        foreach ($chats as $c) {
            $last = $c['last'];
            $preview = '';
            if ($last !== null) {
                $mine = (int) $last['sender_id'] === $me;
                if ($mine && $last['deleted_at'] === null) {
                    $read = (int) $c['other_read'] >= (int) $last['id'];
                    $preview .= '<span class="tick' . ($read ? ' read' : '') . '" title="' . ($read ? 'Gelesen' : 'Gesendet') . '">' . self::icon('ticks') . '</span> ';
                }
                if ($last['deleted_at'] !== null) {
                    $preview .= '<em>Nachricht gelöscht</em>';
                } elseif ($last['photo']) {
                    $preview .= '📷 ' . self::e($last['body'] ?: 'Foto');
                } else {
                    $preview .= self::e(mb_substr((string) $last['body'], 0, 120));
                }
            }
            $unread = (int) $c['unread'];
            $html .= '<a class="row" href="?page=chat&amp;id=' . (int) $c['id'] . '">'
                . self::avatar(['display_name' => $c['display_name'], 'avatar' => $c['avatar'], 'last_seen_at' => $c['last_seen_at'], 'logged_out_at' => $c['logged_out_at']], '', true)
                . '<span class="main"><span class="top"><span class="name">' . self::e($c['display_name']) . '</span>'
                . '<span class="time' . ($unread ? ' unread' : '') . '">' . ($c['last_message_at'] ? self::shortTime((int) $c['last_message_at']) : '') . '</span></span>'
                . '<span class="bottom"><span class="preview">' . $preview . '</span>'
                . ($unread ? '<span class="badge-count">' . $unread . '</span>' : '') . '</span></span></a>';
        }
        return $html . '</div>';
    }

    /** Uhrzeit für Listen: heute „14:32“, gestern „Gestern“, sonst Datum. */
    public static function shortTime(int $ts): string
    {
        if (date('Y-m-d', $ts) === date('Y-m-d')) {
            return date('H:i', $ts);
        }
        if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) {
            return 'Gestern';
        }
        return date('d.m.y', $ts);
    }

    /** Das Logo-Zeichen (Bogen-Sprechblase mit Augen). */
    public static function mark(): string
    {
        return '<svg viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="nkg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#FF6A4D"/><stop offset="1" stop-color="#FFB38A"/></linearGradient></defs>'
            . '<path d="M10 56V30a22 22 0 0 1 44 0v14a12 12 0 0 1-12 12z" fill="url(#nkg)"/>'
            . '<circle cx="25" cy="33" r="3.6" fill="#12111B"/><circle cx="39" cy="33" r="3.6" fill="#12111B"/></svg>';
    }

    /** Strich-Icons (24er Raster, currentColor). */
    public static function icon(string $name): string
    {
        $p = [
            'chat'   => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
            'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
            'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/>',
            'back'   => '<path d="M15 5l-7 7 7 7"/>',
            'plus'   => '<path d="M12 5v14M5 12h14"/>',
            'send'   => '<path d="M5 12l15-7-5 15-3-6z"/><path d="M12 14l8-9"/>',
            'image'  => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
            'camera' => '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/>',
            'close'  => '<path d="M6 6l12 12M18 6L6 18"/>',
            'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
            'at'     => '<circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-3.5 7.1"/>',
            'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'trash'  => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
            'eyeoff' => '<path d="M3 3l18 18M10.6 6.1A9.6 9.6 0 0 1 12 6c5 0 9 6 9 6a15.6 15.6 0 0 1-3.2 3.8M6.2 7.6C4.2 9.1 3 12 3 12s4 6 9 6a8.7 8.7 0 0 0 3.6-.8"/>',
            'pencil' => '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
            'ticks'  => '<path d="M1.5 6.5l3.5 3.5 8-8.5M9 9.5l.5.5 8-8.5"/>',
        ][$name] ?? '';
        $vb = $name === 'ticks' ? '0 0 19 12' : '0 0 24 24';
        return '<svg viewBox="' . $vb . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }
}
