(() => {
  try {
    const current = document.currentScript;
    if (!current || !current.src) return;
    const endpoint = new URL(current.getAttribute('data-endpoint') || 'track.php', current.src);
    if (endpoint.origin !== window.location.origin) return;
    const path = window.location.pathname;
    const payload = { path };
    if (current.getAttribute('data-referrer') === 'hostname') {
      payload.version = 2;
      payload.referrer_host = null;
      try {
        const source = new URL(document.referrer);
        const host = source.hostname.toLowerCase().replace(/\.$/, '');
        if (['http:', 'https:'].includes(source.protocol) && !source.username && !source.password
            && !/^[0-9.]+$/.test(host)
            && /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(host)) payload.referrer_host = host;
      } catch { /* A missing or withheld referrer stays unknown. */ }
    }
    const body = JSON.stringify(payload);
    const post = value => fetch(endpoint, {
      method: 'POST', body: JSON.stringify(value), keepalive: true, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }
    });
    if (payload.version === 2) {
      // A rejected Extended request has not written a view. Preserve ordinary
      // collection after a server-side Strict reset, without retrying lost responses.
      post(payload).then(response => {
        if (response.status === 400) return post({ path });
      }).catch(() => {});
      return;
    }
    if (navigator.sendBeacon && navigator.sendBeacon(endpoint.toString(), new Blob([body], { type: 'application/json' }))) return;
    post(payload).catch(() => {});
  } catch { /* Analytics must never break the host page. */ }
})();
