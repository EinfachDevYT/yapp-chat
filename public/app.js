// nooky – kleines Frontend ohne Framework.
// Inline-JS ist per CSP gesperrt, deshalb hängt alles hier an Event-Listenern.
(function () {
  'use strict';

  const csrf = document.body.dataset.csrf || '';

  // Alte Web-App-Version (Service Worker) auf diesem Gerät abmelden.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then((rs) => rs.forEach((r) => r.unregister())).catch(() => {});
  }

  // Sicherheitsabfrage für Formulare mit data-confirm.
  document.addEventListener('submit', (ev) => {
    const msg = ev.target.dataset && ev.target.dataset.confirm;
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  // Datei gewählt → Formular abschicken (Profilbild).
  document.querySelectorAll('input[data-autosubmit]').forEach((inp) => {
    inp.addEventListener('change', () => { if (inp.files.length) inp.form.submit(); });
  });

  // Profil: Info-Vorschläge und Zeichenzähler.
  const bio = document.getElementById('bio');
  if (bio) {
    const count = document.getElementById('bio-count');
    const max = bio.maxLength;
    const upd = () => { count.textContent = `${[...bio.value].length} / ${max}`; };
    bio.addEventListener('input', upd);
    document.querySelectorAll('[data-bio]').forEach((chip) => chip.addEventListener('click', () => {
      bio.value = chip.dataset.bio; upd(); bio.focus();
    }));
  }

  // Neuer Chat: Kontakte filtern.
  const search = document.getElementById('contact-search');
  if (search) {
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      document.querySelectorAll('#contacts form').forEach((f) => { f.hidden = q !== '' && !f.dataset.name.includes(q); });
    });
  }

  async function api(action, params, body) {
    const url = 'api.php?a=' + action + (params ? '&' + new URLSearchParams(params) : '');
    const opts = body ? { method: 'POST', body, headers: { 'X-CSRF-Token': csrf } } : {};
    const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
    let data = {};
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (res.status === 401 || res.status === 503) { location.reload(); throw new Error('reload'); }
    if (!res.ok) throw new Error(data.error || 'Etwas ist schiefgelaufen.');
    return data;
  }

  // Wiederkehrende Abfrage: schnell, solange die Seite sichtbar ist,
  // langsamer im Hintergrund-Tab (läuft dort aber weiter).
  function poll(fn, ms, hiddenMs) {
    let timer = null;
    const tick = async () => {
      clearTimeout(timer);
      try { await fn(); } catch (e) { /* nächster Versuch */ }
      timer = setTimeout(tick, document.hidden ? hiddenMs : ms);
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    timer = setTimeout(tick, ms);
    return tick;
  }

  // ---- Startseite: Chat-Liste live halten ---------------------------------
  // ---- Chat-Liste + Desktop-Benachrichtigungen (auf allen Seiten) ----------
  const list = document.getElementById('chatlist');
  const currentChat = document.getElementById('chat') ? +document.getElementById('chat').dataset.chat : 0;
  const isDesktop = !matchMedia('(pointer: coarse)').matches;
  const canNotify = isDesktop && 'Notification' in window;

  // Erlaubnis automatisch beim ersten Klick erfragen (ohne Klick lassen Browser das nicht zu).
  if (canNotify && csrf && Notification.permission === 'default') {
    document.addEventListener('click', () => {
      if (Notification.permission === 'default') Notification.requestPermission().catch(() => {});
    }, { once: true, capture: true });
  }

  // Bis zu welcher Nachricht schon benachrichtigt wurde – geteilt zwischen Tabs.
  const NOTIFIED = 'nooky-notified';
  const getNotified = () => { try { return +(localStorage.getItem(NOTIFIED) || 0); } catch (e) { return 0; } };
  const setNotified = (id) => { try { localStorage.setItem(NOTIFIED, String(id)); } catch (e) { /* egal */ } };
  let baselineSet = false;

  function maybeNotify(latest) {
    // Beim ersten Abruf nur merken: alte ungelesene Nachrichten nicht nochmal melden.
    if (!baselineSet) { baselineSet = true; if (latest && latest.id > getNotified()) setNotified(latest.id); return; }
    if (!latest || latest.id <= getNotified()) return;
    setNotified(latest.id);
    // Wer gerade genau diesen Chat vor sich hat, braucht keine Meldung.
    if (latest.chat === currentChat && !document.hidden && document.hasFocus()) return;
    if (!canNotify || Notification.permission !== 'granted') return;
    try {
      const n = new Notification(latest.title, { body: latest.body, icon: latest.icon, tag: 'nooky-chat-' + latest.chat });
      n.onclick = () => { window.focus(); location.href = '?page=chat&id=' + latest.chat; n.close(); };
    } catch (e) { /* Browser ohne Notification-Konstruktor */ }
  }

  if (csrf) {
    poll(async () => {
      const d = await api('chats');
      if (list) list.innerHTML = d.html;
      setUnread(d.unread);
      maybeNotify(d.latest);
    }, 3000, 5000);
  }

  function setUnread(n) {
    const dot = document.querySelector('[data-unread]');
    if (dot) { dot.textContent = n; dot.hidden = !n; }
    document.title = (n ? `(${n}) ` : '') + document.title.replace(/^\(\d+\) /, '');
  }

  // ---- Chat-Ansicht --------------------------------------------------------
  const chatEl = document.getElementById('chat');
  if (!chatEl) return;

  const chatId = chatEl.dataset.chat;
  const maxUpload = +chatEl.dataset.maxUpload;
  const stream = document.getElementById('stream');
  const box = document.getElementById('messages');
  const olderBtn = document.getElementById('older');
  const presence = document.getElementById('presence');
  const tplTicks = document.getElementById('tpl-ticks').innerHTML;

  const msgs = new Map();   // id → {data, el}
  let lastId = 0, firstId = 0, since = 0, readByOther = 0, clockSkew = 0;

  const fmtTime = (ts) => new Date(ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  const dayKey = (ts) => new Date(ts * 1000).toDateString();
  function dayLabel(ts) {
    const d = new Date(ts * 1000), now = new Date();
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === now.toDateString()) return 'Heute';
    if (d.toDateString() === y.toDateString()) return 'Gestern';
    return d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
  }

  function render(m) {
    const el = document.createElement('div');
    el.className = 'msg ' + (m.mine ? 'out' : 'in');
    el.dataset.id = m.id;
    el.dataset.at = m.at;
    const b = document.createElement('div');
    b.className = 'bubble';
    b.tabIndex = 0;
    if (m.deleted) {
      b.classList.add('deleted');
      b.textContent = m.mine ? 'Du hast diese Nachricht gelöscht' : 'Diese Nachricht wurde gelöscht';
    } else {
      if (m.photo) {
        b.classList.add('photo');
        const img = document.createElement('img');
        img.src = m.photo; img.alt = 'Foto'; img.loading = 'lazy';
        if (m.w && m.h) { img.width = m.w; img.height = m.h; }
        b.appendChild(img);
      }
      if (m.body) {
        const t = document.createElement(m.photo ? 'div' : 'span');
        if (m.photo) t.className = 'cap';
        t.textContent = m.body;
        b.appendChild(t);
      }
    }
    const meta = document.createElement('span');
    meta.className = 'meta';
    meta.textContent = fmtTime(m.at);
    if (m.mine && !m.deleted) {
      const tick = document.createElement('span');
      tick.className = 'tick';
      meta.appendChild(tick);
    }
    b.appendChild(meta);
    el.appendChild(b);
    msgs.set(m.id, { data: m, el });
    updateTick(m.id);
    return el;
  }

  function updateTick(id) {
    const entry = msgs.get(id);
    if (!entry) return;
    const t = entry.el.querySelector('.tick');
    if (!t) return;
    const read = id <= readByOther;
    if (t.dataset.read === String(read)) return;
    t.dataset.read = String(read);
    t.classList.toggle('read', read);
    if (!t.innerHTML) t.innerHTML = tplTicks;
    t.title = read ? 'Gelesen' : 'Gesendet';
  }

  // Tages-Trenner neu setzen (einfach und robust: alle neu berechnen).
  function redoDays() {
    box.querySelectorAll('.day').forEach((d) => d.remove());
    let prev = null;
    box.querySelectorAll('.msg').forEach((el) => {
      const k = dayKey(+el.dataset.at);
      if (k !== prev) {
        const d = document.createElement('div');
        d.className = 'day';
        d.textContent = dayLabel(+el.dataset.at);
        el.before(d);
        prev = k;
      }
    });
  }

  const nearBottom = () => stream.scrollHeight - stream.scrollTop - stream.clientHeight < 120;
  const toBottom = () => { stream.scrollTop = stream.scrollHeight; };

  function append(list) {
    const stick = nearBottom();
    list.forEach((m) => {
      if (msgs.has(m.id)) return;
      box.appendChild(render(m));
      lastId = Math.max(lastId, m.id);
      if (!firstId || m.id < firstId) firstId = m.id;
    });
    redoDays();
    if (stick) toBottom();
  }

  function markDeleted(ids) {
    ids.forEach((id) => {
      const e = msgs.get(id);
      if (!e || e.data.deleted) return;
      const m = Object.assign({}, e.data, { deleted: true, body: null, photo: null, del_until: 0 });
      const fresh = render(m);
      e.el.replaceWith(fresh);
    });
  }

  function removeLocal(id) {
    const e = msgs.get(id);
    if (!e) return;
    e.el.remove();
    msgs.delete(id);
    redoDays();
  }

  function applyMeta(d) {
    clockSkew = d.now - Math.floor(Date.now() / 1000);
    since = d.now - 2;
    if (d.read_by_other !== readByOther) {
      readByOther = d.read_by_other;
      msgs.forEach((_, id) => updateTick(id));
    }
    presence.textContent = d.presence;
    presence.classList.toggle('on', !!d.online);
  }

  async function load() {
    const d = await api('messages', { chat: chatId, since: Math.floor(Date.now() / 1000) });
    applyMeta(d);
    append(d.messages);
    olderBtn.hidden = !d.more;
    toBottom();
    // Bilder laden nach → unten bleiben
    box.querySelectorAll('img').forEach((img) => img.addEventListener('load', () => { if (nearBottom()) toBottom(); }, { once: true }));
    await refresh();
  }

  async function refresh() {
    const params = { chat: chatId, after: lastId, since };
    // Nur als gelesen markieren, wenn das Fenster wirklich vorne ist.
    if (!document.hidden && document.hasFocus() && lastId) params.read = lastId;
    const d = await api('messages', params);
    applyMeta(d);
    append(d.messages);
    markDeleted(d.deleted);
  }

  olderBtn.addEventListener('click', async () => {
    const h = stream.scrollHeight;
    const d = await api('messages', { chat: chatId, before: firstId });
    const frag = document.createDocumentFragment();
    d.messages.forEach((m) => { if (!msgs.has(m.id)) frag.appendChild(render(m)); if (m.id < firstId) firstId = m.id; });
    olderBtn.after(frag);
    olderBtn.hidden = !d.more;
    redoDays();
    stream.scrollTop = stream.scrollHeight - h;
  });

  // ---- Senden ----
  const form = document.getElementById('composer');
  if (form) {
    const text = document.getElementById('text');
    const photo = document.getElementById('photo');
    const prev = document.getElementById('preview');
    const prevImg = document.getElementById('preview-img');
    const errEl = document.getElementById('send-error');
    let sending = false;

    const grow = () => {
      text.style.height = 'auto';
      text.style.height = Math.min(text.scrollHeight, 160) + 'px';
      text.style.overflowY = text.scrollHeight > 160 ? 'auto' : 'hidden';
    };
    text.addEventListener('input', grow);
    text.addEventListener('keydown', (ev) => {
      // Enter sendet am Rechner, Umschalt+Enter macht eine neue Zeile. Auf dem Handy bleibt Enter Zeilenumbruch.
      if (ev.key === 'Enter' && !ev.shiftKey && !('ontouchstart' in window)) { ev.preventDefault(); form.requestSubmit(); }
    });

    const showErr = (m) => { errEl.textContent = m; errEl.hidden = !m; };
    const clearPhoto = () => { photo.value = ''; prev.hidden = true; if (prevImg.src) URL.revokeObjectURL(prevImg.src); prevImg.removeAttribute('src'); };
    document.getElementById('preview-clear').addEventListener('click', clearPhoto);
    photo.addEventListener('change', () => {
      showErr('');
      const f = photo.files[0];
      if (!f) return clearPhoto();
      if (f.size > maxUpload) { clearPhoto(); return showErr('Das Bild ist zu groß (max. 10 MB).'); }
      prevImg.src = URL.createObjectURL(f);
      prev.hidden = false;
      text.focus();
    });

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (sending) return;
      const body = text.value.trim();
      const file = photo.files[0];
      if (!body && !file) return;
      sending = true; showErr('');
      const fd = new FormData();
      fd.append('chat', chatId);
      fd.append('text', body);
      if (file) fd.append('photo', file);
      try {
        const d = await api('send', null, fd);
        text.value = ''; grow(); clearPhoto();
        append([d.message]);
        toBottom();
      } catch (e) {
        if (e.message !== 'reload') showErr(e.message);
      } finally {
        sending = false;
        text.focus();
      }
    });
  }

  // ---- Nachricht antippen → Menü ----
  const menu = document.getElementById('msg-menu');
  const viewer = document.getElementById('viewer');
  const hint = document.getElementById('del-hint');
  let current = null;

  box.addEventListener('click', (ev) => {
    const img = ev.target.closest('.bubble.photo img');
    if (img) {
      document.getElementById('viewer-img').src = img.src;
      viewer.showModal();
      return;
    }
    const bubble = ev.target.closest('.bubble');
    if (!bubble || window.getSelection().toString()) return;
    openMenu(+bubble.parentElement.dataset.id);
  });
  box.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && ev.target.classList.contains('bubble')) openMenu(+ev.target.parentElement.dataset.id);
  });

  function openMenu(id) {
    const e = msgs.get(id);
    if (!e) return;
    current = id;
    const nowS = Math.floor(Date.now() / 1000) + clockSkew;
    const canAll = e.data.del_until > nowS;
    menu.querySelector('[data-del="all"]').hidden = !canAll;
    if (e.data.mine && !e.data.deleted && !canAll) {
      hint.textContent = 'Für alle löschen geht nur in den ersten 48 Stunden.';
    } else if (canAll) {
      const left = Math.max(1, Math.round((e.data.del_until - nowS) / 3600));
      hint.textContent = `Für alle löschen noch ${left} Std. möglich.`;
    } else {
      hint.textContent = '';
    }
    hint.hidden = !hint.textContent;
    menu.showModal();
  }

  menu.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button');
    if (ev.target === menu || (btn && btn.hasAttribute('data-close'))) { menu.close(); return; }
    if (!btn || !btn.dataset.del) return;
    const scope = btn.dataset.del;
    menu.close();
    const fd = new FormData();
    fd.append('id', current);
    fd.append('scope', scope);
    try {
      await api('delete', null, fd);
      if (scope === 'me') removeLocal(current); else markDeleted([current]);
    } catch (e) {
      if (e.message !== 'reload') alert(e.message);
    }
  });
  viewer.addEventListener('click', () => viewer.close());

  const start = () => { const tick = poll(refresh, 1000, 5000); window.addEventListener('focus', tick); };
  load().then(start).catch(start);
})();
