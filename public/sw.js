// Abschalt-Service-Worker: Die Web-App-Version von nooky ist vorerst wieder raus.
// Geräte, die den alten Service Worker noch haben, laden diese Datei beim nächsten
// Update-Check, löschen den Cache und melden sich selbst ab.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (ev) => {
  ev.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map((k) => caches.delete(k)));
    await self.registration.unregister();
    const wins = await self.clients.matchAll({ type: 'window' });
    wins.forEach((w) => w.navigate(w.url));
  })());
});
