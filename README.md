# yapp-chat

Selbstgehosteter 1:1-Chat. PHP 8.4, SQLite, nginx, kein Framework.
Design „yapp" (dark, Lime + Violett/Pink) für 14–25-Jährige.

> Open Source. Die Lizenzprüfung ist bewusst offen und lesbar – ihre
> Sicherheit liegt nicht im Verstecken, sondern darin, dass gültige Tokens
> nur der [Lizenzserver](https://github.com/EinfachDevYT/yapp-license) mit
> seinem privaten Ed25519-Schlüssel ausstellen kann.

## Aufbau

```
public/   Document Root
src/      Logik (aktuell: License.php)
bin/      CLI (aktuell: license-check.php für den stündlichen Cron-Check)
data/     DB + Uploads + Lizenz-Status (nicht im Repo)
config.php   lokale Konfiguration (nicht im Repo, Vorlage: config.example.php)
```

## Konfiguration

```bash
cp config.example.php config.php
```

Dann `key`, `verify_url`, `public_key` (aus dem Lizenzserver-Setup) und
`domain` eintragen.

## Lizenzprüfung

- `src/License.php` holt stündlich ein signiertes Token vom Lizenzserver,
  verifiziert es lokal mit dem Public Key und speichert es in `data/`.
- Ist der Server nicht erreichbar, läuft der Chat bis zu **12 h** mit dem
  letzten gültigen Token weiter, danach gesperrt.
- Lehnt der Server aktiv ab (gesperrt/abgelaufen/…), wird sofort gesperrt.

Stündlicher Cron-Check (hält die Toleranz auch ohne Traffic frisch):

```cron
0 * * * * php /var/www/yapp-chat/bin/license-check.php
```

## Status

- [x] Lizenzprüfung (Client-Seite)
- [ ] Accounts (nur Admin legt an), Login
- [ ] Chats, Fotos, Löschen (für mich / für alle, 48 h)
- [ ] Lesebestätigung, zuletzt online
- [ ] Profilbild, Bio
- [ ] yapp-Design (Bricolage + Onest, selbst gehostet)
