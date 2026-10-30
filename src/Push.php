<?php
declare(strict_types=1);

namespace Nooky\Chat;

/**
 * Web-Push ohne Inhalt.
 *
 * Der Push an Apple/Google/Mozilla enthält KEINE Nachricht, nur „wach auf“.
 * Der Service Worker (public/sw.js) holt danach Absender und Text direkt von
 * diesem Server (api.php?a=notify) und zeigt die Benachrichtigung an.
 * Deshalb braucht es keine Payload-Verschlüsselung – nur die VAPID-Signatur,
 * mit der sich der Server beim Push-Dienst ausweist (ES256-JWT).
 */
final class Push
{
    /** Wer in den letzten Sekunden aus einer offenen App abgefragt hat, bekommt keinen Push. */
    private const ACTIVE_WINDOW = 10;

    private static function keyFile(): string
    {
        return dirname(__DIR__) . '/data/vapid.json';
    }

    /** VAPID-Schlüsselpaar laden oder beim ersten Mal erzeugen. */
    private static function keys(): array
    {
        $file = self::keyFile();
        if (is_file($file)) {
            $k = json_decode((string) file_get_contents($file), true);
            if (is_array($k) && isset($k['private'], $k['public'])) {
                return $k;
            }
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('VAPID-Schlüssel konnte nicht erzeugt werden.');
        }
        openssl_pkey_export($key, $pem);
        $d   = openssl_pkey_get_details($key);
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $k   = ['private' => $pem, 'public' => self::b64url($pub)];

        $fh = fopen($file, 'x');
        if ($fh === false) {
            // Ein anderer Prozess war schneller.
            return json_decode((string) file_get_contents($file), true);
        }
        chmod($file, 0600);
        fwrite($fh, (string) json_encode($k, JSON_UNESCAPED_SLASHES));
        fclose($fh);
        return $k;
    }

    /** Öffentlicher Schlüssel für pushManager.subscribe (applicationServerKey). */
    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    public static function subscribe(int $userId, string $endpoint): bool
    {
        if (!preg_match('#^https://[^\s]{10,2000}$#', $endpoint)) {
            return false;
        }
        Db::pdo()->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, created_at) VALUES (?, ?, ?)
             ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id'
        )->execute([$userId, $endpoint, time()]);
        return true;
    }

    public static function unsubscribe(int $userId, string $endpoint): void
    {
        Db::pdo()->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?')->execute([$userId, $endpoint]);
    }

    /** Merkt sich, dass der User gerade eine offene App hat (gedrosselt). */
    public static function markActive(array $user): void
    {
        $now = time();
        if ($now - (int) ($user['active_at'] ?? 0) >= 5) {
            Db::pdo()->prepare('UPDATE users SET active_at = ? WHERE id = ?')->execute([$now, $user['id']]);
        }
    }

    /** Weckt alle Geräte eines Users – außer er schaut gerade sowieso rein. */
    public static function notify(int $userId, bool $force = false): void
    {
        $user = Users::find($userId);
        if ($user === null || $user['status'] !== 'active'
            || (!$force && time() - (int) ($user['active_at'] ?? 0) < self::ACTIVE_WINDOW)) {
            return;
        }
        $stmt = Db::pdo()->prepare('SELECT id, endpoint FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $subs = $stmt->fetchAll();
        if ($subs === [] || !function_exists('curl_multi_init')) {
            return;
        }

        $keys = self::keys();
        $mh   = curl_multi_init();
        $hs   = [];
        foreach ($subs as $s) {
            $p   = parse_url($s['endpoint']);
            $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            $jwt = self::jwt($aud, $keys['private']);
            if ($jwt === null) {
                continue;
            }
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
                    'TTL: 86400',
                    'Urgency: high',
                    'Content-Length: 0',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[(int) $s['id']] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $pdo = Db::pdo();
        foreach ($hs as $id => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code === 404 || $code === 410) {
                // Abo gibt es nicht mehr (App gelöscht, Rechte entzogen).
                $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$id]);
            } elseif ($code >= 200 && $code < 300) {
                $pdo->prepare('UPDATE push_subscriptions SET last_ok_at = ? WHERE id = ?')->execute([time(), $id]);
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    /** VAPID-JWT (ES256), 12 h gültig. */
    private static function jwt(string $aud, string $privatePem): ?string
    {
        global $config;
        $sub = (string) ($config['push']['subject'] ?? '');
        if ($sub === '') {
            $sub = 'https://' . ((string) ($config['license']['domain'] ?? '') ?: 'localhost');
        }
        $head = self::b64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $body = self::b64url((string) json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
        $data = $head . '.' . $body;

        if (!openssl_sign($data, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $raw = self::derToRaw($der);
        return $raw === null ? null : $data . '.' . self::b64url($raw);
    }

    /** OpenSSL liefert ECDSA-Signaturen als DER, JWT will r||s (je 32 Byte). */
    private static function derToRaw(string $der): ?string
    {
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) {
            return null;
        }
        $len = ord($der[$pos++]);
        if ($len & 0x80) {
            $pos += $len & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos++]) !== 0x02) {
                return null;
            }
            $l = ord($der[$pos++]);
            $int = substr($der, $pos, $l);
            $pos += $l;
            $int = ltrim($int, "\0");
            if (strlen($int) > 32) {
                return null;
            }
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    public static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
