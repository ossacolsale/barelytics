import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:http';
import { DatabaseSync } from 'node:sqlite';
import { Barelytics, createAdminHandler, createTrackHandler, classifyUserAgent, isBot, normalizePath } from '../src/index.js';

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
    assert.equal(raw.prepare('SELECT COUNT(*) AS count FROM schema_migrations').get().count, 3);
    raw.close();
    const migratedAgain = new Barelytics({ dataDirectory: directory });
    assert.equal(migratedAgain.audit().schema_version, 3);
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
    assert.equal((await fetch(base, { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{' })).status, 400);
    assert.equal((await fetch(base, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ path: '/article', visitor_id: 'blocked' }) })).status, 400);
    assert.equal((await fetch(base, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ path: '/article' }) })).status, 204);
    assert.equal((await fetch(base, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ path: '/article?q=1' }) })).status, 400);
    assert.equal((await fetch(base, { method: 'POST', body: 'x'.repeat(8193) })).status, 413);
    assert.equal((await fetch(base, { method: 'POST', body: '{"path":"/plain"}' })).status, 415);
    const padded = await fetch(base, { method: 'POST', headers: { 'content-type': 'Application/JSON; charset=utf-8' }, body: JSON.stringify({ path: '/padded' }) + ' '.repeat(4000) });
    assert.equal(padded.status, 204);
    assert.equal(padded.headers.get('cache-control'), 'no-store');
    assert.equal(padded.headers.get('set-cookie'), null);
    assert.equal(analytics.dashboard().total, 2);
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


test('configured exclusions, encoded private paths, failed transactions and resumable retention', () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-node-regression-'));
  const analytics = new Barelytics({ dataDirectory: directory, pathExclusions: ['/admin/*'] });
  const raw = new DatabaseSync(analytics.databasePath);
  try {
    for (const path of ['/admin', '/%61dmin/report']) assert.equal(analytics.trackPageView({ path }), false);
    analytics.updatePrivacy({ browser_collection: true }, 'yes');
    raw.exec("CREATE TRIGGER fail_dimension BEFORE INSERT ON dimensions_daily BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    assert.equal(analytics.trackPageView({ path: '/interrupted' }), false);
    assert.equal(raw.prepare("SELECT COUNT(*) AS n FROM pageviews_daily WHERE path='/interrupted'").get().n, 0);
    raw.exec('DROP TRIGGER fail_dimension');
    const insert = raw.prepare("INSERT INTO pageviews_daily VALUES ('2000-01-01',?,'XX',1)");
    for (let i = 0; i < 1001; i++) insert.run(`/expired-${i}`);
    analytics.trackPageView({ path: '/fresh' });
    assert.equal(raw.prepare("SELECT COUNT(*) AS n FROM pageviews_daily WHERE day='2000-01-01'").get().n, 1);
    analytics.trackPageView({ path: '/fresh' });
    assert.equal(raw.prepare("SELECT COUNT(*) AS n FROM pageviews_daily WHERE day='2000-01-01'").get().n, 0);
  } finally { raw.close(); analytics.close(); rmSync(directory, { recursive: true, force: true }); }
});


test('shared automation classification is finite, equivalent and never verified', () => {
  const vectors = JSON.parse(readFileSync(new URL('../../../spec/test-vectors/automation.json', import.meta.url)));
  for (const v of vectors) assert.deepEqual(classifyUserAgent(v.user_agent, v.additional_patterns), { category: v.category, family: v.family, identity: v.identity, automation: v.automation, verified: false });
});

test('automation is separate in Strict and participates in deletion and retention', () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-automation-'));
  const analytics = new Barelytics({ dataDirectory: directory });
  const raw = new DatabaseSync(analytics.databasePath);
  try {
    analytics.trackPageView({ path: '/article', userAgent: 'GPTBot/1' });
    analytics.trackPageView({ path: '/article', userAgent: 'a crawler/1' });
    analytics.trackPageView({ path: '/private/secret', userAgent: 'GPTBot/1' });
    const report = analytics.adminData('automation');
    assert.equal(report.total, 2); assert.equal(report.verified, false);
    assert.deepEqual(report.rows, [{ path: '/article', requests: 2 }]);
    assert.equal(analytics.dashboard().total, 0);
    assert.equal(analytics.audit().profile, 'strict');
    raw.exec("INSERT INTO automation_daily VALUES ('2000-01-01','/old','ai','openai',2)");
    raw.exec("UPDATE settings SET value='invalid-cleanup-fixture' WHERE key='last_cleanup_at'");
    assert.equal(analytics.trackPageView({ path: '/fresh' }), true);
    assert.equal(raw.prepare("SELECT COUNT(*) AS n FROM automation_daily WHERE day='2000-01-01'").get().n, 0);
    analytics.deleteAll();
    assert.equal(analytics.adminData('automation').total, 0);
  } finally { raw.close(); analytics.close(); rmSync(directory, { recursive: true, force: true }); }
});


test('all shared collector vectors and opt-in hostname attribution', async () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-protocol-'));
  const analytics = new Barelytics({ dataDirectory: directory });
  const server = createServer(createTrackHandler(analytics));
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  try {
    const vectors = JSON.parse(readFileSync(new URL('../../../spec/test-vectors/protocol.json', import.meta.url)));
    for (const [index, v] of vectors.entries()) {
      analytics.updatePrivacy({ referrer_collection: v.referrer_collection === true }, 'yes');
      let raw = v.raw_hex ? Buffer.from(v.raw_hex, 'hex') : Buffer.from(v.body_bytes ? 'x'.repeat(v.body_bytes) : typeof v.body === 'string' ? v.body : JSON.stringify(v.body || {}));
      if (v.body_size) raw = Buffer.concat([raw, Buffer.alloc(v.body_size - raw.length, ' ')]);
      const headers = { 'user-agent': 'Mozilla/5.0', referer: 'https://beacon.test/current?private' };
      if (v.content_type !== '') headers['content-type'] = v.content_type ?? 'application/json';
      const response = await fetch(base, { method: v.method, headers, ...(v.method === 'POST' ? { body: raw } : {}) });
      assert.equal(response.status, v.status, `vector ${index}`);
      assert.equal(response.headers.get('cache-control'), 'no-store'); assert.equal(response.headers.get('set-cookie'), null);
    }
    analytics.updatePrivacy({ referrer_collection: true }, 'yes');
    assert.deepEqual(analytics.adminData('dimensions', { dimension: 'referrer', path: '/v2' }).rows, [{ value: 'example.test', views: 1 }]);
  } finally { await new Promise(resolve => server.close(resolve)); analytics.close(); rmSync(directory, { recursive: true, force: true }); }
});

test('page-filtered dimensions isolate values and expose retained history after reset', () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-dimensions-'));
  const analytics = new Barelytics({ dataDirectory: directory });
  try {
    analytics.updatePrivacy({ country_collection: true, referrer_collection: true, browser_collection: true, device_collection: true, os_collection: true }, 'yes');
    analytics.trackPageView({ path: '/one', userAgent: 'Mozilla/5.0 Firefox/1 Windows', country: 'it', referrer: 'https://one.test/private?q=secret' });
    analytics.trackPageView({ path: '/two', userAgent: 'Mozilla/5.0 Chrome/1 Android Mobile', country: 'us', referrer: 'https://two.test/private?q=secret' });
    for (const [dimension, expected] of Object.entries({ country: 'IT', referrer: 'one.test', browser: 'Firefox', device: 'Desktop', os: 'Windows' })) {
      assert.deepEqual(analytics.adminData('dimensions', { dimension, path: '/one' }).rows, [{ value: expected, views: 1 }]);
      assert.equal(analytics.adminData('dimensions', { dimension }).rows.length, 2);
      assert.throws(() => analytics.adminData('dimensions', { dimension, path: 'https://bad.test' }), /path/);
    }
    analytics.returnToStrictMode();
    assert.equal(analytics.audit().result, 'PASS');
    assert.deepEqual(analytics.audit().historical_dimensions, ['country', 'referrer', 'browser', 'device', 'os']);
    assert.equal(analytics.adminData('privacy').daily_reach_available, false);
  } finally { analytics.close(); rmSync(directory, { recursive: true, force: true }); }
});


test('schema-2 migration preserves aggregates/settings, audits extra tables and refuses future schemas', () => {
  const directory = mkdtempSync(join(tmpdir(), 'barelytics-legacy-'));
  const databasePath = join(directory, 'analytics.sqlite');
  const raw = new DatabaseSync(databasePath);
  raw.exec(readFileSync(new URL('../../../spec/test-vectors/legacy-v2.sql', import.meta.url), 'utf8'));
  const analytics = new Barelytics({ dataDirectory: directory });
  try {
    assert.equal(analytics.adminData('privacy').retention_days, 30);
    assert.equal(raw.prepare("SELECT views FROM pageviews_daily WHERE path='/legacy'").get().views, 7);
    assert.equal(raw.prepare("SELECT value FROM settings WHERE key='admin_password_hash'").get().value, 'fixture-unchanged-credential');
    assert.equal(raw.prepare('SELECT COUNT(*) AS n FROM schema_migrations').get().n, 3);
    raw.exec('CREATE TABLE request_log(user_agent TEXT)');
    assert.equal(analytics.audit().result, 'FAIL'); raw.exec('DROP TABLE request_log');
    assert.equal(analytics.audit().result, 'PASS');
    raw.exec("INSERT INTO schema_migrations VALUES (99,'fixture')");
    assert.throws(() => new Barelytics({ dataDirectory: directory }), /newer/);
    assert.equal(raw.prepare("SELECT views FROM pageviews_daily WHERE path='/legacy'").get().views, 7);
  } finally { analytics.close(); raw.close(); rmSync(directory, { recursive: true, force: true }); }
});
