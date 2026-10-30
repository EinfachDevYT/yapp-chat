# nooky-chat

Selbstgehosteter 1:1-Chat. PHP 8.4, SQLite, nginx, kein Framework.
Design „nooky“: gemütliche Nachtecke – Indigo, Glut (Koralle/Pfirsich), Mint; Unbounded + Figtree, selbst gehostet. Für 14–25-Jährige.

> Open Source. Die Lizenzprüfung ist bewusst offen und lesbar – ihre
> Sicherheit liegt nicht im Verstecken, sondern darin, dass gültige Tokens
> nur der [Lizenzserver](https://github.com/EinfachDevYT/yapp-license) mit
> seinem privaten Ed25519-Schlüssel ausstellen kann.

> **Installation:** siehe [INSTALL.md](INSTALL.md) (Lizenzserver + Chat, Schritt für Schritt).

## Aufbau

```
public/   Document Root des Chats
          index.php (Seiten), api.php (Live-Chat, JSON), media.php (Fotos mit Rechteprüfung),
          app.css, app.js, fonts/, brand/ (Logo, Icons), manifest.webmanifest
landing/  Landing Page für nooky.cc (statisch, Impressum, Datenschutz)
src/      Logik (Db, Auth, Users, Chats, Media, View, License, bootstrap.php, views/)
bin/      CLI: setup.php (DB + erster Admin), license-check.php (Cron)
data/     chat.sqlite + Uploads + Lizenz-Status (nicht im Repo)
config.php   lokale Konfiguration (nicht im Repo, Vorlage: config.example.php)
```

## Konfiguration

```bash
cp config.example.php config.php
```

Dann `key`, `verify_url`, `public_key` (aus dem Lizenzserver-Setup) und
`domain` eintragen.

## Einrichtung

```bash
sudo -u www-data php bin/setup.php
```

Legt `data/chat.sqlite` an (bzw. migriert das Schema) und fragt den ersten
Admin ab. Nicht-interaktiv:
`ADMIN_USER=admin ADMIN_NAME="Admin" ADMIN_PASS=… php bin/setup.php`.

Gibt es den Namen schon, setzt das Skript dessen Passwort neu und macht ihn
wieder zum aktiven Admin. Das ist der Notfall-Reset, falls man sich
ausgesperrt hat.

## Accounts und Login

- Es gibt **keine Selbstregistrierung.** Accounts legt nur ein Admin unter
  `?page=admin` an. Dabei wird ein Startpasswort erzeugt und einmalig
  angezeigt. Beim ersten Login muss der User ein eigenes Passwort setzen.
- Admins können Passwörter zurücksetzen, Accounts sperren und entsperren,
  Admin-Rechte vergeben und entziehen sowie Accounts löschen. Der letzte
  aktive Admin ist dabei geschützt.
- Sitzungen liegen in der DB (Cookie `nooky_sid`, HttpOnly, SameSite=Lax,
  30 Tage gleitend). Sperren oder ein Passwort-Reset melden den User sofort
  auf allen Geräten ab.
- Nach 5 Fehlversuchen pro Name+IP bzw. 20 pro IP gibt es eine Pause von
  15 Minuten. Alle Formulare sind mit einem CSRF-Token geschützt.
- Das DB-Schema für Chats, Nachrichten, „für mich gelöscht“ und
  Lesebestätigungen liegt bereits in `src/Db.php` (Schema v1).

## Lizenzprüfung

- `src/License.php` holt stündlich ein signiertes Token vom Lizenzserver,
  verifiziert es lokal mit dem Public Key und speichert es in `data/`.
- Ist der Server nicht erreichbar, läuft der Chat bis zu **12 h** mit dem
  letzten gültigen Token weiter, danach gesperrt.
- Lehnt der Server aktiv ab (gesperrt/abgelaufen/…), wird sofort gesperrt.

Stündlicher Cron-Check (hält die Toleranz auch ohne Traffic frisch):

```cron
0 * * * * php /var/www/nooky-chat/bin/license-check.php
```

## Funktionen

- **Chats:** 1:1, Nachrichten werden alle 3 s abgeholt (im Hintergrund-Tab pausiert).
  Fotos bis 10 MB; jedes Bild wird neu gerechnet (max. 1600 px, WebP), dabei fallen
  EXIF-Daten inkl. GPS-Position weg. Fotos sieht nur, wer im Chat ist.
- **Löschen:** Nachricht antippen → „Für mich löschen“ (nur im eigenen Verlauf) oder
  „Für alle löschen“ (eigene Nachrichten, bis 48 h nach dem Senden; Text und Foto
  werden sofort vom Server entfernt).
- **Lesebestätigung:** ✓ gesendet, ✓✓ in Mint = gelesen.
- **Zuletzt online:** „online“ bei Aktivität in den letzten 2 Minuten, sonst
  „zuletzt online heute um …“.
- **Profil wie bei WhatsApp:** Profilbild (quadratisch zugeschnitten), Name, Info
  (max. 139 Zeichen, mit Vorschlägen wie „Verfügbar“, „In der Schule“), Info-Datum,
  „Dabei seit“. Andere Profile über den Namen im Chat.
- **Lizenz:** Ohne gültige Lizenz zeigt der Chat eine Sperrseite (API: 503 JSON).

## Status

- [x] Lizenzprüfung (Client-Seite) + Sperrseite im Chat
- [x] Accounts (nur Admin legt an), Login, Sitzungen
- [x] DB-Schema + Setup-Skript (inkl. erstem Admin)
- [x] Chats, Fotos, Löschen (für mich / für alle, 48 h)
- [x] Lesebestätigung, zuletzt online
- [x] Profilbild, Info (Bio)
- [x] nooky-Design, Logo, App-Icons
- [x] Landing Page nooky.cc
