// nooky Service Worker
// - macht nooky als App installierbar und zeigt ohne Netz eine Offline-Seite
// - zeigt Push-Benachrichtigungen: Der Push selbst ist leer, Absender und Text
//   holt der Worker direkt vom eigenen Server (nichts davon geht über Apple/Google).

const CACHE = 'nooky-v1';
const SHELL = [
  'offline.html', 'app.css', 'app.js',
  'fonts/figtree.woff2', 'fonts/unbounded.woff2',
  'brand/icon.svg', 'brand/icon-192.png', 'brand/mark.svg',
];

self.addEventListener('install', (ev) => {
  ev.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // Seiten: immer frisch vom Server, ohne Netz die Offline-Seite.
  if (req.mode === 'navigate') {
    ev.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  // API und Fotos nie cachen (privat, ändert sich ständig).
  if (/\/(api|media)\.php$/.test(url.pathname)) return;

  // Statische Dateien: erst Netz (damit Updates sofort da sind), sonst Cache.
  ev.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok && SHELL.some((p) => url.pathname.endsWith('/' + p))) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req))
  );
});

// ---- Push ------------------------------------------------------------------
self.addEventListener('push', (ev) => {
  ev.waitUntil((async () => {
    let n = { title: 'nooky', body: 'Neue Nachricht', chat: 0, icon: 'brand/icon-192.png', unread: 0 };
    try {
      const res = await fetch('api.php?a=notify', { credentials: 'same-origin', cache: 'no-store' });
      if (res.ok) n = await res.json();
    } catch (e) { /* ohne Netz: allgemeine Meldung */ }

    if (self.navigator.setAppBadge) {
      try { n.unread ? await self.navigator.setAppBadge(n.unread) : await self.navigator.clearAppBadge(); } catch (e) { /* egal */ }
    }
    await self.registration.showNotification(n.title, {
      body: n.body,
      icon: n.icon,
      badge: 'brand/icon-192.png',
      tag: 'chat-' + n.chat,      // pro Chat nur eine Benachrichtigung, die neueste ersetzt die alte
      renotify: true,
      data: { url: n.chat ? '?page=chat&id=' + n.chat : '?page=home' },
    });
  })());
});

self.addEventListener('notificationclick', (ev) => {
  ev.notification.close();
  const target = new URL(ev.notification.data && ev.notification.data.url || '?page=home', self.registration.scope).href;
  ev.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if ('focus' in w) {
        await w.focus();
        if ('navigate' in w) await w.navigate(target);
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});
