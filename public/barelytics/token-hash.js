'use strict';

document.getElementById('download').addEventListener('click', async () => {
  const input = document.getElementById('token');
  const status = document.getElementById('status');
  const token = input.value;
  if (new TextEncoder().encode(token).length < 32) {
    status.textContent = 'Use a token containing at least 32 UTF-8 bytes.';
    return;
  }
  if (!globalThis.crypto?.subtle) {
    status.textContent = 'This browser does not provide secure local hashing. Open the helper over HTTPS or use another modern browser.';
    return;
  }
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(token));
  const hex = [...new Uint8Array(bytes)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  const url = URL.createObjectURL(new Blob([`<?php http_response_code(404); exit; ?>\nsha256:${hex}\n`], { type: 'application/octet-stream' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = 'setup-token.php';
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  input.value = '';
  status.textContent = 'Hash file created. Upload setup-token.php into the Barelytics package data/ folder, or rename it to setup.token in the private data directory.';
});
