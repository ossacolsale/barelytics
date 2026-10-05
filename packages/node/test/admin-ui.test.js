import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const ui = new URL('../../../public/barelytics/admin-ui/', import.meta.url);
const html = readFileSync(new URL('index.html', ui), 'utf8');
const app = readFileSync(new URL('app.js', ui), 'utf8');
const css = readFileSync(new URL('admin.css', ui), 'utf8');
const brandMark = readFileSync(new URL('brand-mark.png', ui));

test('shared admin UI covers the dashboard sections and can run without external assets', () => {
  for (const id of ['overview', 'pages', 'daily', 'page-detail', 'dimensions', 'privacy', 'maintenance', 'system', 'audit', 'integration']) assert.match(html, new RegExp(`id="${id}"`));
  assert.match(html, /admin-ui\/app\.js/);
  assert.match(html, /admin-ui\/brand-mark\.png/);
  assert.equal(brandMark.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
  assert.doesNotMatch(`${html}\n${app}\n${css}`, /https?:\/\//i);
  assert.doesNotMatch(app, /localStorage|sessionStorage|indexedDB|document\.cookie/i);
});

test('shared admin UI confirms strict mode and destructive deletion and renders disabled dimensions', () => {
  assert.match(app, /confirm\('Disable all optional dimensions/);
  assert.match(app, /confirm\('Permanently delete all aggregate statistics/);
  assert.match(app, /collection is disabled in the current privacy configuration/);
  assert.match(app, /textContent/);
});
