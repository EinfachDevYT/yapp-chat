<?php
declare(strict_types=1);

/**
 * Einstieg des Chats. Seiten über ?page=… (keine Rewrite-Regeln nötig):
 *   login, logout, password, home (Chats), new (neuer Chat), chat, profile,
 *   user (fremdes Profil), admin
 * Live-Funktionen (Nachrichten senden/abholen, löschen) laufen über api.php.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Nooky\Chat\Auth;
use Nooky\Chat\Chats;
use Nooky\Chat\Media;
use Nooky\Chat\Users;
use Nooky\Chat\View;

$page   = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

function e(?string $s): string
{
    return View::e($s);
}

function redirect(string $query): never
{
    header('Location: ?page=' . $query);
    exit;
}

function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

// ---- Login / Logout --------------------------------------------------------
if ($page === 'login') {
    if (Auth::check()) {
        redirect('home');
    }
    $error = '';
    if ($isPost) {
        if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
            $error = 'Sitzung abgelaufen, bitte nochmal.';
        } elseif (Auth::attempt(post('username'), post('password'), $error)) {
            redirect(Auth::mustChangePassword() ? 'password' : 'home');
        }
    }
    render_login($error, trim(post('username')));
    exit;
}

if ($page === 'logout') {
    if ($isPost && Auth::checkCsrf($_POST['csrf'] ?? null)) {
        Auth::logout();
    }
    redirect('login');
}

// ---- Ab hier nur eingeloggt -----------------------------------------------
if (!Auth::check()) {
    redirect('login');
}

if ($isPost && !Auth::checkCsrf($_POST['csrf'] ?? null)) {
    http_response_code(400);
    exit('Ungültige Anfrage (CSRF). Bitte Seite neu laden.');
}

// Startpasswort vom Admin? Dann zuerst ein eigenes setzen.
if (Auth::mustChangePassword() && $page !== 'password') {
    redirect('password');
}

$me = (int) Auth::id();

switch ($page) {
    // ---- Passwort ändern --------------------------------------------------
    case 'password':
        $user   = Auth::user();
        $forced = Auth::mustChangePassword();
        $error  = '';
        if ($isPost) {
            $new = post('new');
            if (!$forced && !password_verify(post('current'), $user['pass_hash'])) {
                $error = 'Aktuelles Passwort stimmt nicht.';
            } elseif ($new !== post('again')) {
                $error = 'Die neuen Passwörter stimmen nicht überein.';
            } elseif (($err = Users::validatePassword($new)) !== null) {
                $error = $err;
            } elseif (password_verify($new, $user['pass_hash'])) {
                $error = 'Bitte ein neues Passwort wählen, nicht das bisherige.';
            } else {
                Users::setPassword($me, $new, mustChange: false);
                Auth::logoutEverywhere($me, keepCurrent: true);
                Auth::refresh();
                Auth::flash('ok', 'Passwort geändert. Andere Geräte wurden abgemeldet.');
                redirect($forced ? 'profile' : 'home');
            }
        }
        render_password($error, $forced);
        exit;

    // ---- Neuer Chat -------------------------------------------------------
    case 'new':
        if ($isPost) {
            $other = Users::find((int) post('user'));
            if ($other === null || (int) $other['id'] === $me || $other['status'] !== 'active') {
                Auth::flash('err', 'Diese Person gibt es nicht (mehr).');
                redirect('new');
            }
            redirect('chat&id=' . Chats::between($me, (int) $other['id']));
        }
        render_new(Users::contactsFor($me), Auth::takeFlash());
        exit;

    // ---- Chat -------------------------------------------------------------
    case 'chat':
        $chat = Chats::forUser((int) ($_GET['id'] ?? 0), $me);
        if ($chat === null) {
            redirect('home');
        }
        render_chat($chat);
        exit;

    // ---- Eigenes Profil ---------------------------------------------------
    case 'profile':
        if ($isPost) {
            profile_action();
            redirect('profile');
        }
        render_profile(Auth::user(), Auth::takeFlash());
        exit;

    // ---- Fremdes Profil ---------------------------------------------------
    case 'user':
        $u = Users::find((int) ($_GET['id'] ?? 0));
        if ($u === null || (int) $u['id'] === $me) {
            redirect($u === null ? 'home' : 'profile');
        }
        render_user($u);
        exit;

    // ---- Admin: Accounts verwalten ----------------------------------------
    case 'admin':
        if (!Auth::isAdmin()) {
            http_response_code(403);
            exit('Nur für Admins.');
        }
        if ($isPost) {
            admin_action(post('action'), (int) post('id'));
            redirect('admin');
        }
        render_admin(Users::all(), Auth::takeFlash());
        exit;

    // ---- Start: Chat-Liste ------------------------------------------------
    default:
        render_home(Chats::listFor($me), Auth::takeFlash());
}


// ---- Profil-Aktionen ------------------------------------------------------
function profile_action(): void
{
    $me = (int) Auth::id();
    $u  = Auth::user();

    switch (post('action')) {
        case 'avatar':
            $file = Media::storeAvatar($_FILES['avatar'] ?? [], $error);
            if ($file === null) {
                Auth::flash('err', $error ?? 'Upload fehlgeschlagen.');
                return;
            }
            Users::setAvatar($me, $file);
            Auth::flash('ok', 'Profilbild aktualisiert.');
            return;

        case 'avatar_remove':
            Users::setAvatar($me, null);
            Auth::flash('ok', 'Profilbild entfernt.');
            return;

        case 'details':
            $name = trim(post('display_name'));
            $bio  = trim(preg_replace('/\s+/u', ' ', post('bio')) ?? '');
            $err  = Users::validateDisplayName($name) ?? Users::validateBio($bio);
            if ($err !== null) {
                Auth::flash('err', $err);
                return;
            }
            Users::updateProfile($me, $name, $bio);
            Auth::flash('ok', 'Profil gespeichert.');
            return;
    }
}

// ---- Admin-Aktionen -------------------------------------------------------
function admin_action(string $action, int $id): void
{
    $me = Auth::id();

    if ($action === 'create') {
        $username = Users::normalizeUsername(post('username'));
        $name     = trim(post('display_name'));
        $name     = $name !== '' ? $name : $username;
        $isAdmin  = post('is_admin') === '1';

        $err = Users::validateUsername($username) ?? Users::validateDisplayName($name);
        if ($err === null && Users::findByUsername($username) !== null) {
            $err = "Benutzername „{$username}“ ist schon vergeben.";
        }
        if ($err !== null) {
            Auth::flash('err', $err);
            return;
        }
        $password = Users::generatePassword();
        Users::create($username, $name, $password, $isAdmin, mustChangePassword: true);
        Auth::flash('pw', json_encode(['user' => $username, 'pw' => $password], JSON_UNESCAPED_UNICODE));
        return;
    }

    $target = Users::find($id);
    if ($target === null) {
        Auth::flash('err', 'Account nicht gefunden.');
        return;
    }
    $isSelf = $id === $me;
    // Der letzte aktive Admin darf weder gesperrt, gelöscht noch herabgestuft werden.
    $lastAdmin = (int) $target['is_admin'] === 1 && $target['status'] === 'active' && Users::countActiveAdmins() <= 1;

    switch ($action) {
        case 'reset_pw':
            if ($isSelf) {
                Auth::flash('err', 'Dein eigenes Passwort änderst du im Profil.');
                return;
            }
            $password = Users::generatePassword();
            Users::setPassword($id, $password, mustChange: true);
            Auth::logoutEverywhere($id);
            Auth::flash('pw', json_encode(['user' => $target['username'], 'pw' => $password], JSON_UNESCAPED_UNICODE));
            return;

        case 'lock':
        case 'unlock':
            if ($isSelf || ($action === 'lock' && $lastAdmin)) {
                Auth::flash('err', 'Diesen Account kannst du nicht sperren.');
                return;
            }
            Users::setStatus($id, $action === 'lock' ? 'locked' : 'active');
            if ($action === 'lock') {
                Auth::logoutEverywhere($id);
            }
            Auth::flash('ok', $action === 'lock'
                ? "@{$target['username']} gesperrt und überall abgemeldet."
                : "@{$target['username']} entsperrt.");
            return;

        case 'make_admin':
        case 'revoke_admin':
            if ($action === 'revoke_admin' && ($isSelf || $lastAdmin)) {
                Auth::flash('err', 'Du kannst dir selbst bzw. dem letzten Admin die Rechte nicht nehmen.');
                return;
            }
            Users::setAdmin($id, $action === 'make_admin');
            Auth::flash('ok', "Rechte von @{$target['username']} geändert.");
            return;

        case 'delete':
            if ($isSelf || $lastAdmin) {
                Auth::flash('err', 'Diesen Account kannst du nicht löschen.');
                return;
            }
            Users::delete($id);
            Auth::flash('ok', "@{$target['username']} gelöscht.");
            return;
    }
}


// ---- Rendering ------------------------------------------------------------
function render_login(string $error, string $username): void
{
    View::head('Anmelden', ['bar' => false]);
    echo '<main class="auth"><div class="card">';
    echo '<div class="brand-big">' . View::mark() . '<span>nooky</span></div>';
    if ($error !== '') {
        echo '<p class="err">' . e($error) . '</p>';
    }
    echo '<form method="post" action="?page=login">' . View::csrfField();
    echo '<label>Benutzername<input type="text" name="username" value="' . e($username) . '" autofocus autocomplete="username" autocapitalize="none" spellcheck="false" required></label>';
    echo '<label>Passwort<input type="password" name="password" autocomplete="current-password" required></label>';
    echo '<button class="btn block" type="submit">Los geht’s</button></form>';
    echo '<p class="muted small">nooky ist invite only. Deinen Account bekommst du von der Person, die dich eingeladen hat.</p>';
    echo '</div></main></body></html>';
}

function render_password(string $error, bool $forced): void
{
    View::head('Passwort');
    echo '<main class="page"><div class="card narrow"><h1>' . ($forced ? 'Eigenes Passwort setzen' : 'Passwort ändern') . '</h1>';
    if ($forced) {
        echo '<p class="muted">Du hast dich mit einem Startpasswort angemeldet. Leg jetzt dein eigenes fest.</p>';
    }
    if ($error !== '') {
        echo '<p class="err">' . e($error) . '</p>';
    }
    echo '<form method="post" action="?page=password">' . View::csrfField();
    if (!$forced) {
        echo '<label>Aktuelles Passwort<input type="password" name="current" autocomplete="current-password" required></label>';
    }
    echo '<label>Neues Passwort (min. 8 Zeichen)<input type="password" name="new" autocomplete="new-password" minlength="8" required></label>';
    echo '<label>Neues Passwort wiederholen<input type="password" name="again" autocomplete="new-password" minlength="8" required></label>';
    echo '<button class="btn block" type="submit">Speichern</button></form></div></main>';
    View::foot();
}

function render_home(array $chats, ?array $flash): void
{
    View::head('Chats', ['body' => 'home-view']);
    echo '<main class="page">';
    View::flash($flash);
    echo '<h1>Chats</h1>';
    echo '<div id="chatlist">' . View::chatList($chats, (int) Auth::id()) . '</div>';
    echo '</main>';
    echo '<a class="fab" href="?page=new" title="Neuer Chat" aria-label="Neuer Chat">' . View::icon('plus') . '</a>';
    View::foot();
}

function render_new(array $contacts, ?array $flash): void
{
    View::head('Neuer Chat');
    echo '<main class="page">';
    View::flash($flash);
    echo '<h1>Neuer Chat</h1>';
    if ($contacts === []) {
        echo '<div class="empty">' . View::mark() . '<h2>Noch niemand da</h2><p>Sobald der Admin weitere Leute einlädt, tauchen sie hier auf.</p></div>';
    } else {
        echo '<div class="search"><input type="search" id="contact-search" placeholder="Suchen …" aria-label="Kontakte durchsuchen" autocomplete="off"></div>';
        echo '<div class="list" id="contacts">';
        foreach ($contacts as $c) {
            echo '<form method="post" action="?page=new" data-name="' . e(mb_strtolower($c['display_name'] . ' ' . $c['username'])) . '">' . View::csrfField()
               . '<input type="hidden" name="user" value="' . (int) $c['id'] . '">'
               . '<button class="row" type="submit">' . View::avatar($c, '', true)
               . '<span class="main"><span class="top"><span class="name">' . e($c['display_name']) . '</span></span>'
               . '<span class="bottom"><span class="preview">' . e($c['bio'] ?: '@' . $c['username']) . '</span></span></span></button></form>';
        }
        echo '</div>';
    }
    echo '</main>';
    View::foot();
}

function render_chat(array $chat): void
{
    $o  = $chat['other'];
    $on = Users::isOnline($o);
    View::head($o['display_name'], ['body' => 'chat-view', 'bar' => false]);
    echo '<div class="chat" id="chat" data-chat="' . (int) $chat['id'] . '" data-max-upload="' . Media::MAX_UPLOAD . '">';
    echo '<header class="topbar"><a class="iconbtn" href="?page=home" aria-label="Zurück zu den Chats">' . View::icon('back') . '</a>';
    echo '<a class="who" href="?page=user&amp;id=' . (int) $o['id'] . '">' . View::avatar($o, 's')
       . '<span class="txt"><b>' . e($o['display_name']) . '</b><small id="presence" class="' . ($on ? 'on' : '') . '">' . e(Users::presence($o)) . '</small></span></a></header>';

    echo '<div class="stream" id="stream"><div class="stream-inner" id="messages">'
       . '<button class="btn ghost small older" id="older" hidden type="button">Ältere Nachrichten laden</button></div></div>';

    echo '<div class="composer">';
    if ($o['status'] !== 'active') {
        echo '<p class="composer-note muted">Dieser Account ist nicht mehr aktiv. Du kannst keine Nachrichten mehr senden.</p>';
    } else {
        echo '<div class="preview-strip" id="preview" hidden><img alt="Ausgewähltes Foto" id="preview-img"><button class="iconbtn" type="button" id="preview-clear" aria-label="Foto entfernen">' . View::icon('close') . '</button></div>';
        echo '<p class="composer-note err" id="send-error" hidden></p>';
        echo '<form id="composer" autocomplete="off">';
        echo '<label class="attach" title="Foto senden" aria-label="Foto senden">' . View::icon('image') . '<input type="file" id="photo" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
        echo '<textarea id="text" rows="1" maxlength="' . Chats::MAX_TEXT . '" placeholder="Nachricht" aria-label="Nachricht"></textarea>';
        echo '<button class="send" type="submit" aria-label="Senden">' . View::icon('send') . '</button></form>';
    }
    echo '</div></div>';

    // Menü für eine Nachricht + Foto-Großansicht
    echo '<dialog id="msg-menu"><div class="sheet">'
       . '<button type="button" data-del="me">' . View::icon('eyeoff') . ' Für mich löschen</button>'
       . '<button type="button" data-del="all" class="danger">' . View::icon('trash') . ' Für alle löschen</button>'
       . '<p class="hint" id="del-hint"></p>'
       . '<button type="button" data-close>Abbrechen</button></div></dialog>';
    echo '<dialog id="viewer"><div class="viewer"><img alt="Foto" id="viewer-img"><button class="iconbtn close" type="button" data-close aria-label="Schließen">' . View::icon('close') . '</button></div></dialog>';
    echo '<template id="tpl-ticks">' . View::icon('ticks') . '</template>';
    echo '</body></html>';
}

function render_profile(array $u, ?array $flash): void
{
    View::head('Mein Profil');
    echo '<main class="page">';
    View::flash($flash);

    // Profilbild
    echo '<div class="profile-head"><div class="avatar-edit">' . View::avatar($u, 'l');
    echo '<form method="post" enctype="multipart/form-data" id="avatar-form">' . View::csrfField() . '<input type="hidden" name="action" value="avatar">'
       . '<label class="cam" title="Profilbild ändern" aria-label="Profilbild ändern">' . View::icon('camera')
       . '<input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" data-autosubmit></label>'
       . '<noscript><button class="btn small">Hochladen</button></noscript></form></div>';
    echo '<h1>' . e($u['display_name']) . '</h1><span class="handle">@' . e($u['username']) . '</span>';
    if (!empty($u['avatar'])) {
        echo '<form method="post">' . View::csrfField() . '<input type="hidden" name="action" value="avatar_remove"><button class="btn ghost small">Profilbild entfernen</button></form>';
    }
    echo '</div>';

    // Name + Info
    $bio = (string) ($u['bio'] ?? '');
    echo '<form method="post" class="card">' . View::csrfField() . '<input type="hidden" name="action" value="details">';
    echo '<label>Name<input type="text" name="display_name" value="' . e($u['display_name']) . '" maxlength="40" required>'
       . '<span class="small">Diesen Namen sehen die anderen in ihren Chats.</span></label>';
    echo '<label for="bio">Info <span class="counter" id="bio-count">' . mb_strlen($bio) . ' / ' . Users::BIO_MAX . '</span>'
       . '<input type="text" id="bio" name="bio" value="' . e($bio) . '" maxlength="' . Users::BIO_MAX . '" placeholder="Erzähl was über dich …"></label>';
    echo '<div class="presets" aria-label="Vorschläge">';
    foreach (Users::BIO_PRESETS as $p) {
        echo '<button type="button" class="chip" data-bio="' . e($p) . '">' . e($p) . '</button>';
    }
    echo '</div><button class="btn" type="submit">Speichern</button></form>';

    echo '<div class="card"><div class="field-row">' . View::icon('at') . '<div class="f"><div class="k">Benutzername</div><div class="v">@' . e($u['username']) . '</div></div></div>';
    echo '<div class="field-row">' . View::icon('shield') . '<div class="f"><div class="k">Sicherheit</div><div class="v"><a href="?page=password">Passwort ändern</a></div></div></div></div>';
    echo '</main>';
    View::foot();
}

function render_user(array $u): void
{
    View::head($u['display_name']);
    $chatId = $u['status'] === 'active' ? Chats::between((int) Auth::id(), (int) $u['id']) : null;
    echo '<main class="page">';
    echo '<div class="profile-head">' . View::avatar($u, 'l') . '<h1>' . e($u['display_name']) . '</h1><span class="handle">@' . e($u['username']) . '</span>';
    echo '<span class="small ' . (Users::isOnline($u) ? 'ok' : 'muted') . '">' . e(Users::presence($u)) . '</span></div>';

    echo '<div class="card">';
    echo '<div class="field-row">' . View::icon('info') . '<div class="f"><div class="k">Info</div><div class="v">' . ($u['bio'] ? e($u['bio']) : '<span class="muted">Noch keine Info</span>') . '</div>';
    if ($u['bio'] && $u['bio_updated_at']) {
        echo '<div class="k">' . e(date('d.m.Y', (int) $u['bio_updated_at'])) . '</div>';
    }
    echo '</div></div>';
    echo '<div class="field-row">' . View::icon('clock') . '<div class="f"><div class="k">Dabei seit</div><div class="v">' . e(date('d.m.Y', (int) $u['created_at'])) . '</div></div></div>';
    echo '</div>';
    if ($chatId !== null) {
        echo '<div class="actions-row"><a class="btn" href="?page=chat&amp;id=' . $chatId . '">' . View::icon('chat') . ' Nachricht</a></div>';
    }
    echo '</main>';
    View::foot();
}

function render_admin(array $users, ?array $flash): void
{
    $me = Auth::id();
    View::head('Admin');
    echo '<main class="page wide">';
    View::flash($flash);

    echo '<h1>Accounts</h1><div class="card"><h2>Neuen Account anlegen</h2>';
    echo '<form method="post" class="grid"><input type="hidden" name="action" value="create">' . View::csrfField();
    echo '<label>Benutzername<input type="text" name="username" placeholder="z. B. lena.k" pattern="[a-zA-Z0-9_.]{3,20}" autocapitalize="none" spellcheck="false" required></label>';
    echo '<label>Anzeigename<input type="text" name="display_name" placeholder="Lena" maxlength="40"></label>';
    echo '<label class="check"><input type="checkbox" name="is_admin" value="1"> Admin</label>';
    echo '<button class="btn" type="submit">Anlegen</button></form>';
    echo '<p class="muted small">Ein Startpasswort wird erzeugt und einmalig angezeigt.</p></div>';

    echo '<div class="card"><h2>Alle (' . count($users) . ')</h2>';
    echo '<table><thead><tr><th>Account</th><th>Rolle</th><th>Status</th><th>Zuletzt online</th><th></th></tr></thead><tbody>';
    foreach ($users as $u) {
        $id     = (int) $u['id'];
        $self   = $id === $me;
        $locked = $u['status'] === 'locked';
        $admin  = (int) $u['is_admin'] === 1;

        echo '<tr><td><strong>' . e($u['display_name']) . '</strong><br><span class="muted">@' . e($u['username']) . ($self ? ' (du)' : '') . '</span></td>';
        echo '<td>' . ($admin ? '<span class="badge admin">Admin</span>' : 'User') . '</td>';
        echo '<td><span class="badge ' . ($locked ? 'bad' : 'ok') . '">' . ($locked ? 'gesperrt' : 'aktiv') . '</span>';
        if ((int) $u['must_change_pw'] === 1) {
            echo ' <span class="badge">Startpasswort</span>';
        }
        echo '</td>';
        echo '<td>' . ($u['last_seen_at'] ? e(date('d.m.Y H:i', (int) $u['last_seen_at'])) : '<span class="muted">nie</span>') . '</td>';
        echo '<td class="actions">';
        if (!$self) {
            echo action_button('reset_pw', $id, 'Passwort zurücksetzen', 'ghost', 'Neues Startpasswort für @' . $u['username'] . ' erzeugen? Alle Geräte werden abgemeldet.');
            echo $locked
                ? action_button('unlock', $id, 'entsperren', 'ghost')
                : action_button('lock', $id, 'sperren', 'ghost', '@' . $u['username'] . ' sperren und überall abmelden?');
            echo $admin
                ? action_button('revoke_admin', $id, 'Admin entziehen', 'ghost')
                : action_button('make_admin', $id, 'zum Admin', 'ghost', '@' . $u['username'] . ' Admin-Rechte geben?');
            echo action_button('delete', $id, 'löschen', 'danger', '@' . $u['username'] . ' endgültig löschen – samt allen Chats und Fotos?');
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></main>';
    View::foot();
}

function action_button(string $action, int $id, string $label, string $class = '', string $confirm = ''): string
{
    return '<form method="post"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">' . View::csrfField()
        . '<button class="btn small ' . e($class) . '">' . e($label) . '</button></form>';
}
