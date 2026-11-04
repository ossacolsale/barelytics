import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../../public/barelytics/track.js', import.meta.url), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
function execute({ referrer = '', attributes = {}, fetchAvailable = true, failure, status = 204, headers = {}, permission } = {}) {
  const calls = [], beacons = [];
  let storageReads = 0;
  const document = { currentScript: { src: 'https://site.test/sub/track.js', getAttribute: key => attributes[key] ?? null } };
  Object.defineProperty(document, 'referrer', { get: () => { if (referrer instanceof Error) throw referrer; return referrer; } });
  Object.defineProperty(document, 'cookie', { get: () => { throw new Error('Cookie access'); }, set: () => { throw new Error('Cookie write'); } });
  const navigator = { sendBeacon: (url, blob) => { beacons.push({ url, blob }); return true; } };
  const fetch = !fetchAvailable ? undefined : (url, options) => {
    calls.push({ url: String(url), options });
    if (failure === 'throw') throw new Error('Restricted fetch');
    if (failure === 'reject') return Promise.reject(new Error('Ambiguous network failure'));
    return Promise.resolve({ status, headers: { get: name => headers[name] ?? null } });
  };
  const window = { location: { origin: 'https://site.test', pathname: '/article' }, BARELYTICS_REACH_PERMISSION: permission };
  Object.defineProperty(window, 'indexedDB', { get() { storageReads++; throw new Error('Storage accessed'); } });
  assert.doesNotThrow(() => runInNewContext(source, { document, navigator, fetch, URL, Blob, window, setTimeout, clearTimeout }));
  return { calls, beacons, get storageReads() { return storageReads; } };
}

test('Strict tracker fetches only the path without consulting referrer, cookies or storage', async () => {
  const result = execute({ referrer: new Error('Strict must not read document.referrer') });
  await settle();
  assert.equal(result.calls.length, 1); const [call] = result.calls;
  assert.equal(call.url, 'https://site.test/sub/track.php');
  assert.deepEqual(JSON.parse(call.options.body), { path: '/article' });
  assert.equal(call.options.keepalive, true); assert.equal(call.options.credentials, 'same-origin');
  assert.equal(result.beacons.length, 0); assert.equal(result.storageReads, 0);
});

test('Extended tracker minimizes navigation source before transmission', () => {
  const {calls:[call]} = execute({ referrer: 'https://Example.test/private?email=secret#fragment', attributes: { 'data-referrer': 'hostname' } });
  assert.deepEqual(JSON.parse(call.options.body), { path: '/article', version: 2, referrer_host: 'example.test' });
  for (const referrer of ['', 'https://127.0.0.1/private', 'https://user:pass@example.test/private', 'data:text/plain,secret']) {
    const {calls:[invalid]} = execute({ referrer, attributes: { 'data-referrer': 'hostname' } });
    assert.equal(JSON.parse(invalid.options.body).referrer_host, null);
  }
});

test('tracker rejects cross-origin endpoints and supports same-origin native collectors', async () => {
  assert.equal(execute({ attributes: { 'data-endpoint': 'https://external.test/track' } }).calls.length, 0);
  const result = execute({ attributes: { 'data-endpoint': '/native/track', 'data-daily-reach':'true' } });
  await settle();
  assert.equal(result.calls.length, 1); assert.equal(result.calls[0].url, 'https://site.test/native/track');
  assert.deepEqual(JSON.parse(result.calls[0].options.body), { path: '/article' });
  assert.equal(result.storageReads, 0);
});

test('a rejected Extended request retries once as Strict without duplicating accepted views', async () => {
  const {calls} = execute({ attributes: { 'data-referrer': 'hostname' }, status: 400 });
  await settle();
  assert.equal(calls.length, 2);
  assert.deepEqual(JSON.parse(calls[1].options.body), { path: '/article' });
  const accepted = execute({ attributes: { 'data-referrer': 'hostname' }, status: 204 });
  await settle(); assert.equal(accepted.calls.length, 1);
});

test('missing fetch uses a single path-only beacon even with referrer enabled', async () => {
  const result = execute({ fetchAvailable:false, attributes:{'data-referrer':'hostname'} });
  assert.equal(result.calls.length, 0); assert.equal(result.beacons.length, 1);
  assert.deepEqual(JSON.parse(await result.beacons[0].blob.text()), {path:'/article'});
  assert.equal(result.storageReads, 0);
});

test('restricted or failed fetch never retries through beacon or fetch', async () => {
  for (const failure of ['throw', 'reject']) {
    const result = execute({failure}); await settle();
    assert.equal(result.calls.length, 1); assert.equal(result.beacons.length, 0); assert.equal(result.storageReads, 0);
  }
});

test('malformed or unsafe reach response paths never authorize prepare or storage', async () => {
  for (const path of ['', 'https://other.test/a', '//other.test/a', '/a?secret', '/a#secret', '/a\\secret', '/a\nsecret', '/a@b', '/%2fprivate', '/%61dmin/..', '/%252fprivate', '/bad%', '/'+'a'.repeat(512)]) {
    const encoded = encodeURIComponent(path);
    const result = execute({headers:{'Barelytics-Reach-Enabled':'1', 'Barelytics-Reach-Path':encoded}});
    await settle(); assert.equal(result.calls.length, 1, path); assert.equal(result.storageReads, 0, path);
  }
  const malformed = execute({headers:{'Barelytics-Reach-Enabled':'1', 'Barelytics-Reach-Path':'%zz'}});
  await settle(); assert.equal(malformed.calls.length, 1);
});

test('server authorization still requires host/CMP permission and respects per-page false', async () => {
  const headers = {'Barelytics-Reach-Enabled':'1', 'Barelytics-Reach-Path':'%2Farticle'};
  for (const permission of [() => false, () => {throw new Error('Denied');}, () => Promise.reject(new Error('Denied'))]) {
    const result = execute({headers, permission}); await settle();
    assert.equal(result.calls.length, 1); assert.equal(result.storageReads, 0);
  }
  const optedOut = execute({headers, attributes:{'data-daily-reach':'false'}});
  await settle(); assert.equal(optedOut.calls.length, 1); assert.equal(optedOut.storageReads, 0);
});
