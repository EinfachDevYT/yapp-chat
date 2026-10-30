<?php
declare(strict_types=1);

/**
 * Einstieg des Chats. Seiten über ?page=… (keine Rewrite-Regeln nötig):
 *   login, logout, password, admin, home (Standard)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Yapp\Chat\Auth;
use Yapp\Chat\Users;

$page   = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $page): never
{
    header('Location: ?page=' . urlencode($page));
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

// ---- Passwort ändern ------------------------------------------------------
if ($page === 'password') {
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
            Users::setPassword((int) $user['id'], $new, mustChange: false);
            Auth::logoutEverywhere((int) $user['id'], keepCurrent: true);
            Auth::refresh();
            Auth::flash('ok', 'Passwort geändert. Andere Geräte wurden abgemeldet.');
            redirect('home');
        }
    }
    render_password($error, $forced);
    exit;
}

// ---- Admin: Accounts verwalten --------------------------------------------
if ($page === 'admin') {
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
}

// ---- Start (Chats folgen in Schritt 3) -----------------------------------
render_home(Auth::takeFlash());


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
                Auth::flash('err', 'Dein eigenes Passwort änderst du unter „Passwort“.');
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
                ? "„{$target['username']}“ gesperrt und überall abgemeldet."
                : "„{$target['username']}“ entsperrt.");
            return;

        case 'make_admin':
        case 'revoke_admin':
            if ($action === 'revoke_admin' && ($isSelf || $lastAdmin)) {
                Auth::flash('err', 'Du kannst dir selbst bzw. dem letzten Admin die Rechte nicht nehmen.');
                return;
            }
            Users::setAdmin($id, $action === 'make_admin');
            Auth::flash('ok', "Rechte von „{$target['username']}“ geändert.");
            return;

        case 'delete':
            if ($isSelf || $lastAdmin) {
                Auth::flash('err', 'Diesen Account kannst du nicht löschen.');
                return;
            }
            Users::delete($id);
            Auth::flash('ok', "„{$target['username']}“ gelöscht.");
            return;
    }
}


// ---- Rendering ------------------------------------------------------------
function layout_head(string $title): void
{
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · yapp</title>';
    echo '<link rel="stylesheet" href="app.css"><script src="app.js" defer></script>';
    echo '</head><body><header><div class="brand">yapp</div><nav>';
    if (Auth::check() && !Auth::mustChangePassword()) {
        echo '<a href="?page=home">Chats</a>';
        if (Auth::isAdmin()) {
            echo '<a href="?page=admin">Admin</a>';
        }
        echo '<a href="?page=password">Passwort</a>';
    }
    if (Auth::check()) {
        echo '<form method="post" action="?page=logout" class="inline">' . csrf_field()
           . '<button class="ghost">Abmelden</button></form>';
    }
    echo '</nav></header><main>';
}

function layout_foot(): void
{
    echo '</main></body></html>';
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(Auth::csrfToken()) . '">';
}

function render_flash(?array $flash): void
{
    if ($flash === null) {
        return;
    }
    if ($flash['type'] === 'pw') {
        $d = json_decode($flash['msg'], true);
        echo '<div class="card notice"><strong>Startpasswort für „' . e($d['user'] ?? '') . '“:</strong>';
        echo '<p class="pw"><code>' . e($d['pw'] ?? '') . '</code></p>';
        echo '<p class="muted">Wird nur jetzt angezeigt. Beim ersten Login muss ein eigenes Passwort gesetzt werden.</p></div>';
        return;
    }
    echo '<p class="' . ($flash['type'] === 'err' ? 'err' : 'ok') . '">' . e($flash['msg']) . '</p>';
}

function render_login(string $error, string $username): void
{
    layout_head('Anmelden');
    echo '<div class="card narrow"><h1>Anmelden</h1>';
    if ($error !== '') {
        echo '<p class="err">' . e($error) . '</p>';
    }
    echo '<form method="post" action="?page=login">' . csrf_field();
    echo '<label>Benutzername<input name="username" value="' . e($username) . '" autofocus autocomplete="username" autocapitalize="none" spellcheck="false" required></label>';
    echo '<label>Passwort<input type="password" name="password" autocomplete="current-password" required></label>';
    echo '<button type="submit">Los geht’s</button></form>';
    echo '<p class="muted small">Noch keinen Account? Den bekommst du vom Admin.</p></div>';
    layout_foot();
}

function render_password(string $error, bool $forced): void
{
    layout_head('Passwort');
    echo '<div class="card narrow"><h1>' . ($forced ? 'Eigenes Passwort setzen' : 'Passwort ändern') . '</h1>';
    if ($forced) {
        echo '<p class="muted">Du hast dich mit einem Startpasswort angemeldet. Leg jetzt dein eigenes fest.</p>';
    }
    if ($error !== '') {
        echo '<p class="err">' . e($error) . '</p>';
    }
    echo '<form method="post" action="?page=password">' . csrf_field();
    if (!$forced) {
        echo '<label>Aktuelles Passwort<input type="password" name="current" autocomplete="current-password" required></label>';
    }
    echo '<label>Neues Passwort (min. 8 Zeichen)<input type="password" name="new" autocomplete="new-password" minlength="8" required></label>';
    echo '<label>Neues Passwort wiederholen<input type="password" name="again" autocomplete="new-password" minlength="8" required></label>';
    echo '<button type="submit">Speichern</button></form></div>';
    layout_foot();
}

function render_home(?array $flash): void
{
    $u = Auth::user();
    layout_head('Chats');
    render_flash($flash);
    echo '<div class="card"><h1>Hey ' . e($u['display_name']) . ' 👋</h1>';
    echo '<p class="muted">Du bist angemeldet als <code>@' . e($u['username']) . '</code>. Die Chats kommen im nächsten Schritt.</p></div>';
    layout_foot();
}

function render_admin(array $users, ?array $flash): void
{
    $me = Auth::id();
    layout_head('Admin');
    render_flash($flash);

    echo '<div class="card"><h1>Neuen Account anlegen</h1>';
    echo '<form method="post" class="grid"><input type="hidden" name="action" value="create">' . csrf_field();
    echo '<label>Benutzername<input name="username" placeholder="z. B. lena.k" pattern="[a-zA-Z0-9_.]{3,20}" autocapitalize="none" spellcheck="false" required></label>';
    echo '<label>Anzeigename<input name="display_name" placeholder="Lena" maxlength="40"></label>';
    echo '<label class="check"><input type="checkbox" name="is_admin" value="1"> Admin</label>';
    echo '<button type="submit">Anlegen</button></form>';
    echo '<p class="muted small">Ein Startpasswort wird erzeugt und einmalig angezeigt.</p></div>';

    echo '<div class="card"><h1>Accounts (' . count($users) . ')</h1>';
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
            echo action_button('reset_pw', $id, 'Passwort zurücksetzen', '', 'Neues Startpasswort für @' . $u['username'] . ' erzeugen? Alle Geräte werden abgemeldet.');
            echo $locked
                ? action_button('unlock', $id, 'entsperren')
                : action_button('lock', $id, 'sperren', 'warn', '@' . $u['username'] . ' sperren und überall abmelden?');
            echo $admin
                ? action_button('revoke_admin', $id, 'Admin entziehen')
                : action_button('make_admin', $id, 'zum Admin', '', '@' . $u['username'] . ' Admin-Rechte geben?');
            echo action_button('delete', $id, 'löschen', 'danger', '@' . $u['username'] . ' endgültig löschen – samt allen Chats?');
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    layout_foot();
}

function action_button(string $action, int $id, string $label, string $class = '', string $confirm = ''): string
{
    return '<form method="post" class="inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">' . csrf_field()
        . '<button class="' . e($class) . '">' . e($label) . '</button></form>';
}
