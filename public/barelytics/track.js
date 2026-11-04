(() => {
  try {
    const current = document.currentScript;
    if (!current || !current.src) return;
    const endpoint = new URL(current.getAttribute('data-endpoint') || 'track.php', current.src);
    if (endpoint.origin !== window.location.origin) return;
    const reachOptIn = current.getAttribute('data-daily-reach') === 'true';
    const reachDatabase = 'barelytics-daily-reach';
    const permission = async () => {
      try {
        const callback = window.BARELYTICS_REACH_PERMISSION;
        return callback === undefined || (typeof callback === 'function' && await callback() === true);
      } catch { return false; }
    };
    // Explicit host/CMP cleanup only. Strict does not open, read or clear storage.
    if (reachOptIn) window.BarelyticsDailyReach = {
      clear: () => new Promise(resolve => {
        try {
          const request = window.indexedDB.deleteDatabase(reachDatabase);
          request.onsuccess = () => resolve(true);
          request.onerror = request.onblocked = () => resolve(false);
        } catch { resolve(false); }
      })
    };
    const claimDay = day => new Promise(resolve => {
      let db, transaction, settled = false;
      const finish = claimed => {
        if (settled) return;
        settled = true; clearTimeout(timer); db?.close(); resolve(claimed);
      };
      const timer = setTimeout(() => { try { transaction?.abort(); } catch {} finish(false); }, 3000);
      try {
        const request = window.indexedDB.open(reachDatabase, 1);
        request.onerror = request.onblocked = () => finish(false);
        request.onupgradeneeded = () => {
          try {
            if (settled) { request.transaction.abort(); return; }
            request.result.createObjectStore('state');
          } catch { try { request.transaction?.abort(); } catch {} finish(false); }
        };
        request.onsuccess = () => {
          db = request.result;
          if (settled) { db.close(); return; }
          db.onversionchange = () => db.close();
          try {
            transaction = db.transaction('state', 'readwrite');
            let changed = false;
            transaction.oncomplete = () => finish(changed);
            transaction.onabort = transaction.onerror = () => finish(false);
            const store = transaction.objectStore('state');
            const read = store.get('day');
            read.onsuccess = () => {
              try {
                const previous = read.result?.day;
                // Do not roll the gate back when an older prepare arrives late.
                if (typeof previous === 'string' && previous >= day) return;
                store.put({ day }, 'day'); changed = true;
              } catch { try { transaction.abort(); } catch {} finish(false); }
            };
          } catch { finish(false); }
        };
      } catch { finish(false); }
    });
    const reportReach = async normalizedPath => {
      if (!await permission()) return;
      const prepare = new URL(current.getAttribute('data-reach-prepare') || 'reach-prepare.php', endpoint);
      const count = new URL(current.getAttribute('data-reach-count') || 'reach-count.php', endpoint);
      if (prepare.origin !== window.location.origin || count.origin !== window.location.origin) return;
      const options = value => ({ method: 'POST', body: JSON.stringify(value), keepalive: true,
        credentials: 'omit', referrerPolicy: 'no-referrer', cache: 'no-store', headers: { 'Content-Type': 'application/json' } });
      const response = await fetch(prepare, options({ path: normalizedPath }));
      if (response.status !== 200) return;
      const data = await response.json();
      if (data.enabled !== true || data.eligible !== true || data.reporting_contract_version !== 1
          || !/^\d{4}-\d{2}-\d{2}$/.test(data.day) || typeof data.path !== 'string'
          || !data.path.startsWith('/') || data.path.startsWith('//') || !await permission()) return;
      if (!await claimDay(data.day) || !await permission()) return;
      // A committed marker is retained on loss/failure. Exactly one application
      // send attempt; no retry, receipt, fallback or network inside the transaction.
      await fetch(count, options({ day: data.day, path: data.path }));
    };
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
    if (payload.version === 2 || reachOptIn) {
      // An explicit v2 rejection has not written a view. Preserve its existing
      // path-only fallback; reach itself never retries a lost response.
      post(payload).then(response => response.status === 400 && payload.version === 2 ? post({ path }) : response)
        .then(response => {
          if (reachOptIn && response.status === 204 && response.headers?.get('Barelytics-Reach-Enabled') === '1') {
            const normalizedPath = decodeURIComponent(response.headers.get('Barelytics-Reach-Path') || '');
            if (normalizedPath.startsWith('/') && !normalizedPath.startsWith('//')) return reportReach(normalizedPath);
          }
        }).catch(() => {});
      return;
    }
    if (navigator.sendBeacon && navigator.sendBeacon(endpoint.toString(), new Blob([body], { type: 'application/json' }))) return;
    post(payload).catch(() => {});
  } catch { /* Analytics must never break the host page. */ }
})();
