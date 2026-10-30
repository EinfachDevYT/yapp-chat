<?php
declare(strict_types=1);

namespace Nooky\Chat;

/**
 * Lizenzprüfung des Chats.
 *
 * Ablauf:
 *   - Der Chat meldet sich stündlich beim Lizenzserver und holt ein frisch
 *     mit Ed25519 signiertes Token (Domain + Instanz + Ablauf).
 *   - Das Token wird lokal mit dem eingebauten Public Key verifiziert und
 *     zwischengespeichert.
 *   - Ist der Lizenzserver nicht erreichbar, läuft der Chat mit dem letzten
 *     gültigen Token bis zu 12 h weiter (Offline-Toleranz). Danach gesperrt.
 *   - Lehnt der Server aktiv ab (gesperrt/abgelaufen/…), wird sofort gesperrt;
 *     dafür gibt es keine Toleranz.
 *
 * Der Code ist bewusst offen und lesbar. Die Sicherheit liegt nicht darin,
 * dass die Prüfung versteckt wäre, sondern darin, dass gültige Tokens nur der
 * Lizenzserver mit seinem privaten Schlüssel ausstellen kann.
 */
final class License
{
    /** Abstand zwischen zwei Online-Prüfungen. */
    private const CHECK_INTERVAL = 3600;        // 1 h

    /** Wie lange der Chat ohne erreichbaren Server weiterläuft. */
    private const OFFLINE_GRACE  = 12 * 3600;   // 12 h

    // Mögliche Zustände.
    public const OK      = 'ok';       // gültig (frisch geprüft oder in Toleranz)
    public const BLOCKED = 'blocked';  // ungültig / gesperrt / Toleranz abgelaufen

    private string $dataDir;

    public function __construct(private array $cfg, ?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? dirname(__DIR__) . '/data';
    }

    /**
     * Prüft die Lizenz und gibt den Status zurück.
     * $reason enthält bei BLOCKED den Grund (locked, expired, …).
     */
    public function check(?string &$reason = null): string
    {
        $reason = null;
        $state  = $this->loadState();
        $now    = time();

        // Fällig für eine Online-Prüfung?
        $due = !isset($state['last_check']) || ($now - (int) $state['last_check']) >= self::CHECK_INTERVAL;

        // Nur ein Prozess fragt gleichzeitig beim Server nach; alle anderen
        // nutzen solange den gespeicherten Stand (kein Ansturm zur vollen Stunde).
        $lock = null;
        if ($due) {
            $lock = @fopen($this->dataDir . '/license.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                $due = false;
            } else {
                // Inzwischen von einem anderen Prozess erledigt?
                $state = $this->loadState();
                $due   = !isset($state['last_check']) || ($now - (int) $state['last_check']) >= self::CHECK_INTERVAL;
            }
        }

        if ($due) {
            $resp = $this->callServer();
            $state['last_check'] = $now;

            if ($resp['ok'] === true) {
                // Server erreichbar und hat geantwortet.
                if (($resp['data']['valid'] ?? false) === true
                    && $this->verifyToken((string) ($resp['data']['token'] ?? ''), $reason)) {
                    $state['token']          = $resp['data']['token'];
                    $state['last_online_ok'] = $now;
                    unset($state['rejected']);
                    $this->saveState($state);
                    return self::OK;
                }

                // Server erreichbar, aber Lizenz abgelehnt → sofort sperren.
                $reason = $resp['data']['reason'] ?? 'invalid';
                $state['token'] = null;
                $state['rejected'] = $reason; // Grund merken bis zur nächsten Prüfung
                $this->saveState($state);
                return self::BLOCKED;
            }

            // Server nicht erreichbar → Toleranz unten bewerten.
            $this->saveState($state);
        }

        // Keine (fällige) Online-Antwort: mit letztem Token in der Toleranz.
        return $this->evaluateGrace($state, $reason);
    }

    /** Bewertet, ob der Chat offline mit dem letzten Token weiterlaufen darf. */
    private function evaluateGrace(array $state, ?string &$reason): string
    {
        if (empty($state['token']) || empty($state['last_online_ok'])) {
            $reason = $state['rejected'] ?? 'never_verified';
            return self::BLOCKED;
        }
        if ((time() - (int) $state['last_online_ok']) > self::OFFLINE_GRACE) {
            $reason = 'offline_grace_expired';
            return self::BLOCKED;
        }
        if (!$this->verifyToken((string) $state['token'], $reason)) {
            return self::BLOCKED; // Token selbst abgelaufen/ungültig.
        }
        return self::OK;
    }

    /** Ruft den Lizenzserver auf. Gibt ['ok'=>bool, 'data'=>array] zurück. */
    private function callServer(): array
    {
        $payload = json_encode([
            'key'      => (string) ($this->cfg['key'] ?? ''),
            'domain'   => $this->domain(),
            'instance' => $this->instanceId(),
        ], JSON_UNESCAPED_SLASHES);

        $url = (string) ($this->cfg['verify_url'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'data' => []];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 8,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false || $code === 0) {
                return ['ok' => false, 'data' => []];
            }
            $data = json_decode((string) $body, true);
            return ['ok' => is_array($data), 'data' => is_array($data) ? $data : []];
        }

        // Fallback ohne curl.
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'data' => []];
        }
        $data = json_decode($body, true);
        return ['ok' => is_array($data), 'data' => is_array($data) ? $data : []];
    }

    /** Verifiziert ein Token gegen den eingebauten Public Key und die Bindung. */
    private function verifyToken(string $token, ?string &$reason): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            $reason = 'token_malformed';
            return false;
        }
        $json = $this->b64urlDecode($parts[0]);
        $sig  = $this->b64urlDecode($parts[1]);
        $pub  = base64_decode((string) ($this->cfg['public_key'] ?? ''), true);

        if ($json === false || $sig === false || $pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $reason = 'token_malformed';
            return false;
        }
        if (!sodium_crypto_sign_verify_detached($sig, $json, $pub)) {
            $reason = 'bad_signature';
            return false;
        }

        $p = json_decode($json, true);
        if (!is_array($p)) {
            $reason = 'token_malformed';
            return false;
        }
        if ((int) ($p['expires_at'] ?? 0) < time()) {
            $reason = 'token_expired';
            return false;
        }
        if (strtolower((string) ($p['domain'] ?? '')) !== $this->domain()) {
            $reason = 'domain_mismatch';
            return false;
        }
        if (($p['instance'] ?? '') !== $this->instanceId()) {
            $reason = 'instance_mismatch';
            return false;
        }
        return true;
    }

    /** Eigene Domain: aus Config, sonst aus dem aktuellen Request. */
    private function domain(): string
    {
        $d = strtolower(trim((string) ($this->cfg['domain'] ?? '')));
        if ($d === '') {
            $d = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        }
        $d = preg_replace('/:\d+$/', '', $d) ?? $d;
        return preg_replace('/^www\./', '', $d) ?? $d;
    }

    /** Pro Installation einmalig erzeugte, stabile Instanz-ID. */
    private function instanceId(): string
    {
        $file = $this->dataDir . '/instance.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data['id'])) {
                return (string) $data['id'];
            }
        }
        $id = bin2hex(random_bytes(16));
        @file_put_contents($file, json_encode(['id' => $id], JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0640);
        return $id;
    }

    private function loadState(): array
    {
        $file = $this->dataDir . '/license_state.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    private function saveState(array $state): void
    {
        $file = $this->dataDir . '/license_state.json';
        @file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0640);
    }

    private function b64urlDecode(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
