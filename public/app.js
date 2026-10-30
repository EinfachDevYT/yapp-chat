// Sicherheitsabfrage für Formulare mit data-confirm (inline-JS ist per CSP gesperrt).
document.addEventListener('submit', (ev) => {
  const msg = ev.target.dataset && ev.target.dataset.confirm;
  if (msg && !window.confirm(msg)) {
    ev.preventDefault();
  }
});
