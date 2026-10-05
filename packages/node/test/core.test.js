import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:http';
import { DatabaseSync } from 'node:sqlite';
import { Barelytics, createAdminHandler, createTrackHandler, isBot, normalizePath } from '../src/index.js';

const pathVectors = JSON.parse(readFileSync(new URL('../../../spec/test-vectors/path-normalization.json', import.meta.url)));
const botVectors = JSON.parse(readFileSync(new URL('../../../spec/test-vectors/bot-filtering.json', import.meta.url)));

test('shared path vectors are implemented', () => {
  for (const vector of pathVectors) {
    let input = vector.input;
    if (typeof input === 'object') input = input.prefix + input.repeat.repeat(input.count);
    assert.equal(normalizePath(input), vector.expected, input);
  }
});

test('shared bot vectors are implemented', () => {
  for (const vector of botVectors) assert.equal(isBot(vector.user_agent, vector.additional_patterns), vector.bot, vector.user_agent);
});

test('strict is default, aggregates immediately, dimensions require confirmation, strict reset and retention work', () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-node-'));
  const analytics = new Barelytics({ dataDirectory: directory });
  try {
    assert.equal(analytics.audit().profile, 'strict');
    assert.equal(analytics.trackPageView({ path: '/article', userAgent: 'Mozilla/5.0 Chrome/124 Windows' }), true);
    assert.equal(analytics.trackPageView({ path: '/private/report' }), false);
    assert.equal(analytics.trackPageView({ path: '/article', userAgent: 'Googlebot' }), false);
    assert.deepEqual(analytics.dashboard().byPage.map(row => ({ path: row.path, views: row.views })), [{ path: '/article', views: 1 }]);
    assert.equal(analytics.adminData('dashboard', { period: '30', bucket: 'day' }).active_pages, 1);
    assert.deepEqual(analytics.adminData('pages', { period: '30', page: '1', per_page: '10' }).rows, [{ path: '/article', views: 1 }]);
    assert.equal(analytics.adminData('dimensions', { dimension: 'country' }).enabled, false);
    assert.throws(() => analytics.updatePrivacy({ country_collection: true }), /confirmation/i);
    analytics.updatePrivacy({ country_collection: true, referrer_collection: true, browser_collection: true, device_collection: true, os_collection: true }, 'yes');
    analytics.trackPageView({ path: '/extended', userAgent: 'Mozilla/5.0 Chrome/124 Windows', country: 'it', referrer: 'https://example.test/article' });
    assert.equal(analytics.audit().profile, 'extended');
    const dimensionDb = new DatabaseSync(analytics.databasePath);
    assert.equal(dimensionDb.prepare("SELECT COUNT(*) AS count FROM dimensions_daily WHERE path='/extended'").get().count, 3);
    assert.equal(dimensionDb.prepare("SELECT COUNT(*) AS count FROM referrers_daily WHERE path='/extended'").get().count, 1);
    assert.equal(dimensionDb.prepare("SELECT country FROM pageviews_daily WHERE path='/extended'").get().country, 'IT');
    dimensionDb.close();
    analytics.returnToStrictMode();
    assert.equal(analytics.audit().profile, 'strict');
    assert.equal(analytics.audit().result, 'PASS');
    analytics.setRetention(30);
    const raw = new DatabaseSync(analytics.databasePath);
    raw.prepare('INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,?)').run('2000-01-01', '/expired', 'XX', 4);
    assert.equal(analytics.cleanup(20), true);
    assert.equal(raw.prepare('SELECT COUNT(*) AS count FROM pageviews_daily WHERE path=?').get('/expired').count, 0);
    assert.equal(raw.prepare('SELECT COUNT(*) AS count FROM schema_migrations').get().count, 2);
    raw.close();
    const migratedAgain = new Barelytics({ dataDirectory: directory });
    assert.equal(migratedAgain.audit().schema_version, 2);
    migratedAgain.close();
  } finally { analytics.close(); rmSync(directory, { recursive: true, force: true }); }
});

test('HTTP collector returns canonical status codes and rejects extra fields', async () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-node-http-'));
  const analytics = new Barelytics({ dataDirectory: directory });
  const server = createServer(createTrackHandler(analytics));
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  const base = `http://127.0.0.1:${address.port}`;
  try {
    assert.equal((await fetch(base, { method: 'GET' })).status, 405);
    assert.equal((await fetch(base, { method: 'POST', body: '{' })).status, 400);
    assert.equal((await fetch(base, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ path: '/article', visitor_id: 'blocked' }) })).status, 400);
    assert.equal((await fetch(base, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ path: '/article' }) })).status, 204);
    assert.equal((await fetch(base, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ path: '/article?q=1' }) })).status, 400);
    assert.equal((await fetch(base, { method: 'POST', body: 'x'.repeat(8193) })).status, 413);
    assert.equal(analytics.dashboard().total, 1);
  } finally {
    await new Promise(resolve => server.close(resolve));
    analytics.close();
    rmSync(directory, { recursive: true, force: true });
  }
});

test('admin handler enforces host authorization and CSRF before settings changes', async () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-node-admin-'));
  const analytics = new Barelytics({ dataDirectory: directory });
  const server = createServer(createAdminHandler(analytics, {
    authorize: request => request.headers['x-admin'] === 'yes',
    verifyCsrf: (_request, token) => token === 'valid-token',
    csrfToken: () => 'valid-token'
  }));
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  try {
    assert.equal((await fetch(base)).status, 403);
    assert.equal((await fetch(base, { headers: { 'x-admin': 'yes' } })).status, 200);
    const session = await fetch(`${base}/api/session`, { headers: { 'x-admin': 'yes' } });
    assert.equal((await session.json()).data.csrf, 'valid-token');
    const dashboard = await fetch(`${base}/api/dashboard`, { headers: { 'x-admin': 'yes' } });
    assert.equal((await dashboard.json()).data.active_pages, 0);
    const ui = await fetch(`${base}/` , { headers: { 'x-admin': 'yes' } });
    assert.match(await ui.text(), /Analytics administration/);
    const logo = await fetch(`${base}/admin-ui/brand-mark.png`, { headers: { 'x-admin': 'yes' } });
    assert.equal(logo.headers.get('content-type'), 'image/png');
    assert.equal(Buffer.from(await logo.arrayBuffer()).subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
    assert.equal((await fetch(base, { method: 'POST', headers: { 'x-admin': 'yes', 'content-type': 'application/json' }, body: JSON.stringify({ action: 'strict', csrf: 'invalid' }) })).status, 403);
    assert.equal((await fetch(base, { method: 'POST', headers: { 'x-admin': 'yes', 'content-type': 'application/json' }, body: JSON.stringify({ action: 'privacy', values: { country_collection: true }, confirmation: 'yes', csrf: 'valid-token' }) })).status, 204);
    assert.equal(analytics.audit().profile, 'extended');
    assert.equal((await fetch(base, { method: 'POST', headers: { 'x-admin': 'yes', 'content-type': 'application/json' }, body: JSON.stringify({ action: 'strict', csrf: 'valid-token' }) })).status, 204);
    assert.equal(analytics.audit().profile, 'strict');
  } finally {
    await new Promise(resolve => server.close(resolve));
    analytics.close();
    rmSync(directory, { recursive: true, force: true });
  }
});
