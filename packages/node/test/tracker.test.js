import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../../public/barelytics/track.js', import.meta.url), 'utf8');
function execute({ referrer = '', attributes = {}, beacon = true, status = 204 } = {}) {
  const calls = [];
  const document = { currentScript: { src: 'https://site.test/sub/track.js', getAttribute: key => attributes[key] ?? null } };
  Object.defineProperty(document, 'referrer', { get: () => { if (referrer instanceof Error) throw referrer; return referrer; } });
  Object.defineProperty(document, 'cookie', { get: () => { throw new Error('Cookie access'); }, set: () => { throw new Error('Cookie write'); } });
  const navigator = { sendBeacon: (url, blob) => { if (beacon) calls.push({ url, blob }); return beacon; } };
  const fetch = (url, options) => { calls.push({ url: String(url), options }); return Promise.resolve({ status }); };
  assert.doesNotThrow(() => runInNewContext(source, { document, navigator, fetch, URL, Blob, window: { location: { origin: 'https://site.test', pathname: '/article' } } }));
  return calls;
}

test('Strict tracker sends only the path without consulting referrer or cookies', async () => {
  const [call] = execute({ referrer: new Error('Strict must not read document.referrer') });
  assert.equal(call.url, 'https://site.test/sub/track.php');
  assert.deepEqual(JSON.parse(await call.blob.text()), { path: '/article' });
});

test('Extended tracker minimizes navigation source before transmission', async () => {
  const [call] = execute({ referrer: 'https://Example.test/private?email=secret#fragment', attributes: { 'data-referrer': 'hostname' } });
  assert.deepEqual(JSON.parse(call.options.body), { path: '/article', version: 2, referrer_host: 'example.test' });
  for (const referrer of ['', 'https://127.0.0.1/private', 'https://user:pass@example.test/private', 'data:text/plain,secret']) {
    const [invalid] = execute({ referrer, attributes: { 'data-referrer': 'hostname' } });
    assert.equal(JSON.parse(invalid.options.body).referrer_host, null);
  }
});

test('tracker rejects cross-origin endpoints and falls back when beacon queuing fails', () => {
  assert.deepEqual(execute({ attributes: { 'data-endpoint': 'https://external.test/track' } }), []);
  const [call] = execute({ beacon: false, attributes: { 'data-endpoint': '/native/track' } });
  assert.equal(call.url, 'https://site.test/native/track');
  assert.deepEqual(JSON.parse(call.options.body), { path: '/article' });
});


test('a rejected Extended request retries once as Strict without duplicating accepted views', async () => {
  const calls = execute({ attributes: { 'data-referrer': 'hostname' }, status: 400 });
  await Promise.resolve();
  assert.equal(calls.length, 2);
  assert.deepEqual(JSON.parse(calls[1].options.body), { path: '/article' });
  const accepted = execute({ attributes: { 'data-referrer': 'hostname' }, status: 204 });
  await Promise.resolve();
  assert.equal(accepted.length, 1);
});
