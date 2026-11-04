(() => {
  try {
    const current = document.currentScript;
    if (!current || !current.src) return;
    const endpoint = new URL(current.getAttribute('data-endpoint') || 'track.php', current.src);
    if (endpoint.origin !== window.location.origin) return;
    // Server response authorizes reach; retain an explicit per-page opt-out.
    const reachAllowed = current.getAttribute('data-daily-reach') !== 'false';
    const reachDatabase = 'barelytics-daily-reach';
    const permission = async () => {
      try {
        const callback = window.BARELYTICS_REACH_PERMISSION;
        return callback === undefined || (typeof callback === 'function' && await callback() === true);
      } catch { return false; }
    };
    // Explicit host/CMP cleanup only. Strict does not open, read or clear storage.
    window.BarelyticsDailyReach = {
      clear: () => new Promise(resolve => {
        try {
          const request = window.indexedDB.deleteDatabase(reachDatabase);
          request.onsuccess = () => resolve(true);
          request.onerror = request.onblocked = () => resolve(false);
        } catch { resolve(false); }
      })
    };
    const pageLimit = 512;
    const pageHash = async (day, path) => {
      try {
        if (!window.crypto?.subtle) return null;
        const bytes = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(day + '\n' + path));
        return [...new Uint8Array(bytes)].map(value => value.toString(16).padStart(2, '0')).join('');
      } catch { return null; }
    };
    const claimDay = (day, globalEnabled, hash, expiresAt) => new Promise(resolve => {
      let db, transaction, settled = false;
      const finish = claimed => {
        if (settled) return;
        settled = true; clearTimeout(timer); db?.close(); resolve(claimed || { global: false, page: false });
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
            const changed = { global: false, page: false };
            transaction.oncomplete = () => finish(changed);
            transaction.onabort = transaction.onerror = () => finish(false);
            const store = transaction.objectStore('state');
            const read = store.get('day');
            read.onsuccess = () => {
              try {
                const previous = read.result?.day;
                const pages = store.get('pages');
                pages.onsuccess = () => {
                  try {
                    let local = pages.result;
                    // Lazy expiry also runs on global-only storage access. Keep a
                    // day tombstone: expired hashes must not authorize same-day repeats.
                    if (local && (local.day < day || Date.now() >= local.expiresAt)) {
                      local = { day: local.day, expiresAt: local.expiresAt, hashes: [], closed: true };
                      store.put(local, 'pages');
                    }
                    if (globalEnabled && !(typeof previous === 'string' && previous >= day)) {
                      store.put({ day }, 'day'); changed.global = true;
                    }
                    if (!hash || Date.now() >= expiresAt || (typeof previous === 'string' && previous > day) || (local && local.day > day)) return;
                    if (!local || local.day < day) local = { day, expiresAt, hashes: [], closed: false };
                    if (local.day !== day || local.closed || !Number.isFinite(local.expiresAt)
                        || !Array.isArray(local.hashes) || local.hashes.length >= pageLimit
                        || local.hashes.some(value => !/^[a-f0-9]{64}$/.test(value)) || local.hashes.includes(hash)) return;
                    local.hashes.push(hash); store.put(local, 'pages'); changed.page = true;
                  } catch { try { transaction.abort(); } catch {} finish(false); }
                };
              } catch { try { transaction.abort(); } catch {} finish(false); }
            };
          } catch { finish(false); }
        };
      } catch { finish(false); }
    });
    const safePath = value => {
      if (typeof value !== 'string' || !value.startsWith('/') || value.includes('//')
          || /[\x00-\x1f\x7f?#\\]/.test(value) || new Blob([value]).size > 512) return false;
      try {
        const decoded = decodeURIComponent(value);
        return !/[\x00-\x1f\x7f@?#\\%]/.test(decoded)
          && decoded.split('/').length === value.split('/').length
          && !decoded.split('/').some(segment => segment === '.' || segment === '..');
      } catch { return false; }
    };
    const reportReach = async (normalizedPath, pageEnabled) => {
      if (!await permission()) return;
      const prepare = new URL(current.getAttribute('data-reach-prepare') || 'reach-prepare.php', endpoint);
      const count = new URL(current.getAttribute('data-reach-count') || 'reach-count.php', endpoint);
      if (prepare.origin !== window.location.origin || count.origin !== window.location.origin) return;
      const options = value => ({ method: 'POST', body: JSON.stringify(value), keepalive: true,
        credentials: 'omit', referrerPolicy: 'no-referrer', cache: 'no-store', headers: { 'Content-Type': 'application/json' } });
      const preparedAt = Date.now();
      const response = await fetch(prepare, options(pageEnabled ? { version: 2, path: normalizedPath } : { path: normalizedPath }));
      if (response.status !== 200) return;
      const data = await response.json();
      const v2 = pageEnabled && data.reporting_contract_version === 2;
      if (data.enabled !== true || data.eligible !== true || (pageEnabled ? !v2 : data.reporting_contract_version !== 1)
          || !/^\d{4}-\d{2}-\d{2}$/.test(data.day) || !safePath(data.path) || !await permission()) return;
      const globalEnabled = v2 ? data.global_enabled === true : true;
      let hash = null, expiresAt = 0;
      if (v2 && data.page_enabled === true) {
        const end = Date.parse(data.period_end), start = Date.parse(data.day + 'T00:00:00Z');
        if (Number.isInteger(data.server_time) && end === start + 86400000
            && data.server_time * 1000 >= start && data.server_time * 1000 < end) {
          expiresAt = preparedAt + Math.min(86400000, end - data.server_time * 1000);
          hash = await pageHash(data.day, data.path);
          if (!await permission()) return;
          // Response/hash/permission delays must not extend the prepared period.
          const elapsed = Date.now() - preparedAt;
          if (elapsed < 0 || Date.now() >= expiresAt) hash = null;
        }
      }
      if (!globalEnabled && !hash) return;
      const claim = await claimDay(data.day, globalEnabled, hash, expiresAt);
      if ((!claim.global && !claim.page) || !await permission()) return;
      // Only committed claims authorize a send; loss never rolls markers back.
      await fetch(count, options(v2 ? { version: 2, day: data.day, path: data.path, global: claim.global, page: claim.page }
        : { day: data.day, path: data.path }));
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
    if (typeof fetch !== 'function') {
      // No inspectable response: collect only v1, with no reach or v2 retry.
      if (navigator.sendBeacon) navigator.sendBeacon(endpoint.toString(), new Blob([JSON.stringify({ path })], { type: 'application/json' }));
      return;
    }
    const post = value => fetch(endpoint, {
      method: 'POST', body: JSON.stringify(value), keepalive: true, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }
    });
    // Keepalive permits collection across navigation. Never retry an ambiguous
    // fetch failure: the server may already have written the page view.
    // Only an explicit v2 rejection authorizes the existing path-only fallback.
    post(payload).then(response => response.status === 400 && payload.version === 2 ? post({ path }) : response)
      .then(response => {
        const pageEnabled = response.headers?.get('Barelytics-Page-Reach-Enabled') === '1';
        if (reachAllowed && response.status === 204 && (pageEnabled || response.headers?.get('Barelytics-Reach-Enabled') === '1')) {
          const normalizedPath = decodeURIComponent(response.headers.get('Barelytics-Reach-Path') || '');
          if (safePath(normalizedPath)) return reportReach(normalizedPath, pageEnabled);
        }
      }).catch(() => {});
  } catch { /* Analytics must never break the host page. */ }
})();
