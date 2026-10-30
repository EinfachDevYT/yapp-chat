# Installation: Lizenzserver + Chat (Debian 13, nginx, PHP 8.4, als root)

## Reihenfolge – warum zuerst der Lizenzserver?

Der Chat braucht drei Werte, die **nur der Lizenzserver** liefert:

1. den **Public Key** (entsteht beim Setup des Lizenzservers),
2. den **Lizenzschlüssel** (legst du im Panel des Lizenzservers an),
3. die **Prüf-URL** (`https://lizenz…/api/verify.php`, muss per HTTPS erreichbar sein).

Ohne diese drei Werte meldet die Lizenzprüfung des Chats `blocked`.
Deshalb gilt: **Teil A (Lizenzserver) komplett fertig → dann Teil B (Chat).**

```
Teil A: Lizenzserver           Teil B: Chat
────────────────────           ────────────
setup.php ──► PUBLIC KEY ────────► config.php  'public_key'
Panel     ──► LIZENZSCHLÜSSEL ───► config.php  'key'
HTTPS     ──► PRÜF-URL ──────────► config.php  'verify_url'
Key-Domain = Chat-Domain ◄───────► config.php  'domain'
```

## Domains

| Domain | Wofür |
|---|---|
| `license.nooky.cc` | Lizenzserver (Panel + Prüf-API) |
| `chat.nooky.cc` | Chat |
| `nooky.cc` | Landing Page „Invite only“ (Teil C) |

Alle drei können auf denselben Server zeigen. Alle Befehle unten sind schon auf
diese Domains eingestellt.

---

## Teil 0: Vorbereitung (einmalig)

**DNS:** Für alle drei Domains einen A-Record (und ggf. AAAA) auf die IP des
Servers anlegen. Erst weitermachen, wenn alle auflösen:

```bash
getent hosts license.nooky.cc chat.nooky.cc nooky.cc
```

**Pakete:**

```bash
apt update && apt install -y nginx php8.4-fpm php8.4-sqlite3 php8.4-curl php8.4-mbstring php8.4-gd git certbot python3-certbot-nginx
```

> Laufen Lizenzserver und Chat auf **verschiedenen** Servern: die Pakete auf
> beiden installieren.

---

## Teil A: Lizenzserver

### A1. Code holen

```bash
git clone https://github.com/EinfachDevYT/yapp-license /var/www/nooky-license
```

### A2. Setup: Schlüsselpaar + Panel-Login

```bash
php /var/www/nooky-license/bin/setup.php
```

- Fragt Benutzername + Passwort fürs **Panel des Lizenzservers** ab.
- Gibt am Ende den **PUBLIC KEY** aus (lange Base64-Zeichenkette).
  👉 **Notieren** – kommt in B3 in die `config.php` des Chats.
  Später wieder anzeigen:
  `grep '"public"' /var/www/nooky-license/data/keys.json`

### A3. Rechte setzen

`setup.php` lief als root – Datenbank und `keys.json` gehören jetzt root.
PHP läuft als `www-data` und muss sie lesen können:

```bash
chown -R root:root /var/www/nooky-license
```

```bash
chown -R www-data:www-data /var/www/nooky-license/data && chmod 700 /var/www/nooky-license/data
```

### A4. nginx

```bash
cat > /etc/nginx/sites-available/nooky-license <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name license.nooky.cc;

    root /var/www/nooky-license/public;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
EOF
```

👉 In der Datei `server_name` auf deine Lizenz-Domain ändern.

```bash
ln -s /etc/nginx/sites-available/nooky-license /etc/nginx/sites-enabled/ && rm -f /etc/nginx/sites-enabled/default
```

```bash
nginx -t && systemctl reload nginx
```

### A5. HTTPS

```bash
certbot --nginx -d license.nooky.cc
```

### A6. Lizenzschlüssel für den Chat anlegen

1. `https://license.nooky.cc` öffnen, mit dem Login aus A2 anmelden.
2. **Neuen Key erzeugen**:
   - **Domain:** die Chat-Domain, z. B. `chat.nooky.cc` (ohne `https://`, ohne `www.`)
   - **Max. Instanzen:** `1`
   - **Laufzeit:** `0` = unbegrenzt
3. Der erzeugte **LIZENZSCHLÜSSEL** (`XXXX-XXXX-XXXX-XXXX`)
   👉 **Notieren** – kommt in B3 in die `config.php` des Chats.

### A7. Prüfen, ob die API erreichbar ist

```bash
curl -s -X POST https://license.nooky.cc/api/verify.php -H 'Content-Type: application/json' -d '{}'
```

Erwartet: `{"valid":false,"reason":"bad_request",…}` – das ist **richtig**
(leere Anfrage), es zeigt nur, dass der Server antwortet.

✅ **Teil A fertig.** Du hast jetzt: PUBLIC KEY, LIZENZSCHLÜSSEL, Prüf-URL.

---

## Teil B: Chat

### B1. Code holen

```bash
git clone https://github.com/EinfachDevYT/yapp-chat /var/www/nooky-chat
```

### B2. Config anlegen

```bash
cp /var/www/nooky-chat/config.example.php /var/www/nooky-chat/config.php
```

### B3. Config ausfüllen – hier kommen die Werte aus Teil A rein

```bash
nano /var/www/nooky-chat/config.php
```

```php
return [
    'license' => [
        'key'        => 'XXXX-XXXX-XXXX-XXXX',                      // ← LIZENZSCHLÜSSEL aus A6
        'verify_url' => 'https://license.nooky.cc/api/verify.php', // ← deine Lizenz-Domain
        'public_key' => 'AbCd…==',                                  // ← PUBLIC KEY aus A2
        'domain'     => 'chat.nooky.cc',                          // ← exakt die Domain aus A6
    ],
];
```

### B4. Datenbank + ersten Chat-Admin anlegen

```bash
php /var/www/nooky-chat/bin/setup.php
```

Fragt Benutzername, Anzeigename und Passwort für den **Admin im Chat** ab.
(Das ist ein anderer Account als der Panel-Login des Lizenzservers.)

### B5. Rechte setzen

```bash
chown -R root:root /var/www/nooky-chat
```

```bash
chown -R www-data:www-data /var/www/nooky-chat/data
```

```bash
chown root:www-data /var/www/nooky-chat/config.php && chmod 640 /var/www/nooky-chat/config.php
```

### B6. nginx

```bash
cat > /etc/nginx/sites-available/nooky-chat <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name chat.nooky.cc;

    root /var/www/nooky-chat/public;
    index index.php;

    client_max_body_size 10m;   # Foto-Uploads

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
EOF
```

👉 In der Datei `server_name` auf deine Chat-Domain ändern.

```bash
ln -s /etc/nginx/sites-available/nooky-chat /etc/nginx/sites-enabled/
```

```bash
nginx -t && systemctl reload nginx
```

### B7. PHP-Upload-Limits für Fotos

Der Chat nimmt Bilder bis 10 MB an. PHP erlaubt ab Werk nur 2 MB, deshalb
die Limits anheben (`post_max_size` etwas größer als die Datei, weil die
Anfrage noch Formulardaten enthält):

```bash
cat > /etc/php/8.4/fpm/conf.d/99-nooky.ini <<'EOF'
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
EOF
```

```bash
systemctl restart php8.4-fpm
```

> Das nginx-Limit (`client_max_body_size 10m`) steht schon in B6. Fehlt einer
> der beiden Schritte, scheitern größere Uploads.

### B8. HTTPS

```bash
certbot --nginx -d chat.nooky.cc
```

### B9. Lizenz testen (erste Verbindung Chat → Lizenzserver)

```bash
sudo -u www-data php /var/www/nooky-chat/bin/license-check.php
```

Erwartet: `Lizenz: ok`. Im Panel des Lizenzservers steht der Key jetzt auf
`1 / 1` Instanzen, unter **Log** ist die Prüfung zu sehen.

Bei `blocked` siehe [Fehlersuche](#fehlersuche).

### B10. Stündliche Prüfung per Cron

```bash
echo '0 * * * * www-data php /var/www/nooky-chat/bin/license-check.php >/dev/null 2>&1' > /etc/cron.d/nooky-chat
```

### B11. Loslegen

`https://chat.nooky.cc` öffnen, mit dem Admin aus B4 anmelden, unter
**Admin** die Accounts anlegen.

✅ **Chat fertig.**

---

## Teil C: Landing Page (nooky.cc)

Statische Seite aus `landing/` im Chat-Repo: kein PHP, keine Cookies,
Schriften selbst gehostet. Sie sagt „Invite only“ und verlinkt auf den Chat.

### C1. Impressum und Datenschutz ausfüllen

In Deutschland Pflicht. Die pink markierten Platzhalter ersetzen:

```bash
nano /var/www/nooky-chat/landing/impressum.html
```

```bash
nano /var/www/nooky-chat/landing/datenschutz.html
```

### C2. nginx

```bash
cat > /etc/nginx/sites-available/nooky-landing <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name nooky.cc;

    root /var/www/nooky-chat/landing;
    index index.html;

    add_header Content-Security-Policy "default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;

    location / {
        try_files $uri $uri/ =404;
    }

    location /fonts/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\. {
        deny all;
    }
}
EOF
```

```bash
ln -s /etc/nginx/sites-available/nooky-landing /etc/nginx/sites-enabled/
```

```bash
nginx -t && systemctl reload nginx
```

### C3. HTTPS

```bash
certbot --nginx -d nooky.cc
```

`https://nooky.cc` zeigt jetzt die Landing Page, der Button führt zu
`https://chat.nooky.cc`.

✅ **Landing Page fertig.**

---

## Teil D: SSL-Zertifikate automatisch erneuern

Let's-Encrypt-Zertifikate laufen nach 90 Tagen ab. Ein Cron-Job erneuert
alle drei (`license.nooky.cc`, `chat.nooky.cc`, `nooky.cc`) rechtzeitig.
Läuft das Zertifikat des Lizenzservers ab, scheitert die Lizenzprüfung
(`never_verified`) und der Chat sperrt sich.

### D1. Cron-Datei anlegen

Zweimal täglich um 3:17 und 15:17 Uhr, als root. `certbot renew` erneuert
nur Zertifikate, die in weniger als 30 Tagen ablaufen – sonst passiert nichts.

```bash
cat > /etc/cron.d/nooky-certbot <<'EOF'
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 3,15 * * * root certbot renew --quiet --deploy-hook "systemctl reload nginx"
EOF
```

Der `--deploy-hook` läuft nur, wenn wirklich ein Zertifikat erneuert wurde,
und sorgt dafür, dass nginx die neuen Zertifikate lädt.

> Debians certbot-Paket bringt schon einen systemd-Timer (`certbot.timer`)
> mit. Beide parallel sind harmlos. Wer nur den Cron will:
> `systemctl disable --now certbot.timer`

### D2. Erneuerung testen

```bash
certbot renew --dry-run
```

Erwartet: `Congratulations, all simulated renewals succeeded` mit allen drei
Domains. Es wird nichts verändert.

Alle Zertifikate mit Ablaufdatum anzeigen:

```bash
certbot certificates
```

✅ **Alles fertig.**

---

## Was trage ich wo ein? (Übersicht)

| Wert | Woher | Wohin |
|---|---|---|
| Panel-Login Lizenzserver | du wählst ihn in A2 | nur zum Anmelden auf `license.nooky.cc` |
| **PUBLIC KEY** | Ausgabe von A2 | Chat `config.php` → `'public_key'` |
| **LIZENZSCHLÜSSEL** | Panel, A6 | Chat `config.php` → `'key'` |
| **Prüf-URL** | `https://<Lizenz-Domain>/api/verify.php` | Chat `config.php` → `'verify_url'` |
| **Chat-Domain** | deine Wahl | Panel A6 → *Domain* **und** Chat `config.php` → `'domain'` (identisch!) |
| Chat-Admin | du wählst ihn in B4 | zum Anmelden auf `chat.nooky.cc` |
| `server_name` | deine Domains | nginx-Dateien aus A4 und B6 |

## Checkliste

- [ ] Beide Domains lösen auf (Teil 0)
- [ ] Lizenzserver: Setup gelaufen, PUBLIC KEY notiert
- [ ] Lizenzserver: `data/` gehört `www-data`
- [ ] Lizenzserver: HTTPS aktiv, A7 antwortet
- [ ] Lizenzschlüssel mit Chat-Domain angelegt
- [ ] Chat: `config.php` mit allen vier Werten
- [ ] Chat: Setup gelaufen, `data/` gehört `www-data`
- [ ] Chat: PHP-Upload-Limits in `/etc/php/8.4/fpm/conf.d/99-nooky.ini`, PHP-FPM neu gestartet
- [ ] Chat: HTTPS aktiv
- [ ] `license-check.php` meldet `ok`
- [ ] Cron-Datei `/etc/cron.d/nooky-chat` angelegt
- [ ] Cron-Datei `/etc/cron.d/nooky-certbot` angelegt
- [ ] `certbot renew --dry-run` läuft für alle drei Domains durch
- [ ] Backup von `/var/www/nooky-license/data` gemacht

## Fehlersuche

| Meldung bei B9 | Ursache / Lösung |
|---|---|
| `never_verified` | Lizenzserver nicht erreichbar: `verify_url` prüfen, A7 testen, HTTPS-Zertifikat ok? |
| `bad_signature` | `public_key` in der Chat-Config passt nicht zum Lizenzserver → aus A2 neu kopieren |
| `bad_domain` / `domain_mismatch` | Domain im Panel ≠ `'domain'` in der Chat-Config |
| `unknown_key` | Tippfehler im `'key'` |
| `too_many` | Instanz schon belegt (z. B. nach Neuinstallation) → im Panel bei „Instanzen“ **reset** |
| `locked` / `expired` | Key im Panel gesperrt bzw. abgelaufen |
| PHP-Fehler „Permission denied“ | Rechte-Schritt (A3 / B5) vergessen |

## Backup

**Wichtigste Datei überhaupt:** `/var/www/nooky-license/data/keys.json`
(privater Schlüssel). Geht sie verloren, sperren sich nach spätestens
12 h alle Chats, bis überall ein neuer Public Key eingetragen ist.

```bash
tar czf /root/nooky-backup-$(date +%F).tar.gz /var/www/nooky-license/data /var/www/nooky-chat/data /var/www/nooky-chat/config.php
```

Backup vom Server herunterladen und sicher aufbewahren.
**Niemals** `bin/setup.php --force` auf dem Lizenzserver ausführen – das
erzeugt ein neues Schlüsselpaar und macht alle Lizenzen ungültig.

## Notfall: Passwort vergessen

Chat-Admin:

```bash
sudo -u www-data php /var/www/nooky-chat/bin/setup.php
```

Panel des Lizenzservers:

```bash
sudo -u www-data php /var/www/nooky-license/bin/setup.php
```

Jeweils denselben Benutzernamen eingeben → Passwort wird neu gesetzt.
Ab jetzt immer mit `sudo -u www-data` aufrufen, sonst gehören die Dateien
wieder root (dann A3 bzw. B5 wiederholen).

## Updates

```bash
git -C /var/www/nooky-license pull && git -C /var/www/nooky-chat pull
```

Datenbank-Änderungen werden beim nächsten Seitenaufruf automatisch
eingespielt.
