<?php
declare(strict_types=1);

/**
 * Sperrseite, wenn die Lizenz ungültig ist oder der Lizenzserver länger
 * als 12 h nicht erreichbar war. Wird aus bootstrap.php eingebunden.
 *
 * @var ?string $licenseReason
 */

use Nooky\Chat\View;

View::head('Nicht verfügbar', ['bar' => false]);
?>
<main class="auth">
  <div class="card">
    <div class="brand-big"><?= View::mark() ?><span>nooky</span></div>
    <h1>Gerade nicht verfügbar</h1>
    <p class="muted">Dieser Chat ist im Moment gesperrt. Deine Nachrichten sind nicht weg – sobald alles wieder passt, geht es genau da weiter, wo du aufgehört hast.</p>
    <p class="muted small">Wenn du den Chat betreibst: Lizenz im Lizenzserver prüfen und <code>bin/license-check.php</code> ausführen.<br>Grund: <code><?= View::e($licenseReason ?? 'unbekannt') ?></code></p>
  </div>
</main>
</body></html>
