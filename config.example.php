<?php
/**
 * Konfiguration des Chats. Kopiere diese Datei nach config.php und trage
 * deine Werte ein. config.php liegt nicht im Repo (siehe .gitignore).
 */

return [
    'license' => [
        // Lizenzschlüssel aus dem Admin-Panel des Lizenzservers.
        'key'        => 'XXXX-XXXX-XXXX-XXXX',

        // Prüf-Endpunkt des Lizenzservers.
        'verify_url' => 'https://lizenz.example.de/api/verify.php',

        // Public Key, den bin/setup.php des Lizenzservers ausgegeben hat.
        'public_key' => 'BASE64-PUBLIC-KEY',

        // An diese Domain ist der Key gebunden. Leer lassen = aus dem
        // aktuellen Request (HTTP_HOST) nehmen. Für den Cron-Check (CLI)
        // muss die Domain hier gesetzt sein.
        'domain'     => 'chat.example.de',
    ],
];
