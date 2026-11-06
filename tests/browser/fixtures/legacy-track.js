(() => {
  const current = document.currentScript;
  if (!current || !current.src) return;
  const endpoint = new URL('track.php', current.src).toString();
  const path = window.location.pathname;
  const body = JSON.stringify({ path });
  if (navigator.sendBeacon) {
    navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
    return;
  }
  fetch(endpoint, {
    method: 'POST', body, keepalive: true, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }
  }).catch(() => {});
})();
