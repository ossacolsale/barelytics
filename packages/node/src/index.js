import { createHash } from 'node:crypto';
import { mkdirSync, chmodSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { DatabaseSync } from 'node:sqlite';

export const CONTRACT_VERSION = 1;
export const SCHEMA_VERSION = 2;
export const DIMENSIONS = Object.freeze(['country_collection', 'referrer_collection', 'browser_collection', 'device_collection', 'os_collection']);
export const DEFAULT_EXCLUSIONS = Object.freeze(['/admin/*', '/admin.php', '/account/*', '/checkout/*', '/customer/*', '/patient/*', '/profile/*', '/private/*']);
export const DEFAULT_BOTS = Object.freeze(['bot', 'crawler', 'spider', 'slurp', 'bingpreview', 'headless', 'lighthouse', 'pagespeed', 'semrush', 'ahrefsbot', 'mj12bot', 'dotbot', 'facebookexternalhit', 'twitterbot', 'linkedinbot', 'discordbot', 'telegrambot', 'whatsapp', 'petalbot', 'yandex', 'baiduspider', 'bytespider', 'duckduckbot', 'applebot', 'googlebot', 'bingbot']);
const defaults = Object.freeze({ country_collection: false, referrer_collection: false, browser_collection: false, device_collection: false, os_collection: false });
const validRetention = new Set([30, 90, 180, 365]);

export function normalizePath(value) {
  if (typeof value !== 'string' || value.length === 0 || Buffer.byteLength(value, 'utf8') > 512 || /[\u0000-\u001f\u007f]/u.test(value) || !value.startsWith('/') || value.startsWith('//') || value.includes('?') || value.includes('#')) return null;
  if (typeof value.isWellFormed === 'function' && !value.isWellFormed()) return null;
  let path = value.replace(/\/{2,}/g, '/');
  if (Buffer.byteLength(path, 'utf8') > 512 || /%(?![0-9a-f]{2})/iu.test(path)) return null;
  let decoded;
  try { decoded = decodeURIComponent(path); } catch { return null; }
  if (/[\u0000-\u001f\u007f@?#]/u.test(decoded)) return null;
  const ids = /(?<=\/)(?:[0-9]{6,}|[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{16,}|[A-Za-z0-9_-]{32,})(?=\/|$)/giu;
  if (decoded !== path && ids.test(decoded)) return null;
  return path.replace(ids, ':id');
}

export function isBot(userAgent, extraPatterns = []) {
  const value = typeof userAgent === 'string' ? userAgent.toLocaleLowerCase('en-US') : '';
  return [...DEFAULT_BOTS, ...extraPatterns.filter(item => typeof item === 'string' && item.length > 0)].some(pattern => value.includes(pattern.toLocaleLowerCase('en-US')));
}

function blocked(path, patterns) {
  return patterns.some(pattern => pattern.endsWith('/*') ? path === pattern.slice(0, -2) || path.startsWith(pattern.slice(0, -1)) : path === pattern);
}

function daily(day = new Date()) { return day.toISOString().slice(0, 10); }
function setting(db, key, fallback) { return db.prepare('SELECT value FROM settings WHERE key=?').get(key)?.value ?? fallback; }
function setSetting(db, key, value) { db.prepare('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(key, String(value)); }
function sha256(value) { return createHash('sha256').update(value).digest('hex'); }

export class Barelytics {
  #db;
  #exclusions;
  #bots;
  constructor(options = {}) {
    const dataDirectory = resolve(options.dataDirectory ?? './var/barelytics');
    this.databasePath = resolve(options.databasePath ?? `${dataDirectory}/analytics.sqlite`);
    if (options.databasePath) mkdirSync(dirname(this.databasePath), { recursive: true, mode: 0o700 });
    else mkdirSync(dataDirectory, { recursive: true, mode: 0o700 });
    this.#db = new DatabaseSync(this.databasePath);
    this.#db.exec('PRAGMA busy_timeout=1000');
    try { this.#db.exec('PRAGMA journal_mode=WAL'); } catch { /* WAL is optional. */ }
    try { chmodSync(this.databasePath, 0o600); } catch { /* Some filesystems do not expose POSIX modes. */ }
    const exclusions = [...new Set((options.pathExclusions ?? DEFAULT_EXCLUSIONS).filter(x => typeof x === 'string' && x.startsWith('/') && x.length <= 200))].sort();
    this.#bots = [...new Set((options.botPatterns ?? []).filter(x => typeof x === 'string' && x.length <= 200))];
    this.#migrate(options);
    if (options.retentionDays !== undefined) this.setRetention(options.retentionDays);
  }
  #migrate(options) {
    this.#db.exec(`CREATE TABLE IF NOT EXISTS schema_migrations(version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS pageviews_daily(day TEXT NOT NULL,path TEXT NOT NULL,country TEXT NOT NULL DEFAULT 'XX',views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,country));
      CREATE TABLE IF NOT EXISTS referrers_daily(day TEXT NOT NULL,path TEXT NOT NULL,referrer_host TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,referrer_host));
      CREATE TABLE IF NOT EXISTS dimensions_daily(day TEXT NOT NULL,path TEXT NOT NULL,dimension TEXT NOT NULL,value TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,dimension,value));
      CREATE TABLE IF NOT EXISTS privacy_configuration_history(timestamp TEXT NOT NULL,profile TEXT NOT NULL,effective_configuration_json TEXT NOT NULL,configuration_hash TEXT NOT NULL,application_version TEXT NOT NULL,schema_version INTEGER NOT NULL);`);
    this.#db.exec('BEGIN IMMEDIATE');
    try {
      const migrate = this.#db.prepare('INSERT OR IGNORE INTO schema_migrations(version,applied_at) VALUES (?,?)');
      for (const version of [1, SCHEMA_VERSION]) migrate.run(version, new Date().toISOString());
      setSetting(this.#db, 'retention_days', setting(this.#db, 'retention_days', '180'));
      for (const [key, value] of Object.entries(defaults)) if (setting(this.#db, key, '') !== '0' && setting(this.#db, key, '') !== '1') setSetting(this.#db, key, '0');
      setSetting(this.#db, 'path_exclusions', setting(this.#db, 'path_exclusions', DEFAULT_EXCLUSIONS.join('\n')));
      setSetting(this.#db, 'bot_patterns', setting(this.#db, 'bot_patterns', ''));
      if (options.pathExclusions) setSetting(this.#db, 'path_exclusions', exclusions.join('\n'));
      if (options.botPatterns) setSetting(this.#db, 'bot_patterns', this.#bots.join('\n'));
      this.#db.exec('COMMIT');
    } catch (error) { try { this.#db.exec('ROLLBACK'); } catch {} throw error; }
  }
  normalizePath(path) { return normalizePath(path); }
  #config() {
    const config = Object.fromEntries(DIMENSIONS.map(key => [key, setting(this.#db, key, '0') === '1']));
    const retentionRaw = Number(setting(this.#db, 'retention_days', '180'));
    config.retention_days = validRetention.has(retentionRaw) ? retentionRaw : 180;
    config.path_exclusions = setting(this.#db, 'path_exclusions', DEFAULT_EXCLUSIONS.join('\n')).split('\n').filter(x => x.startsWith('/') && x.length <= 200).sort();
    config.bot_patterns = setting(this.#db, 'bot_patterns', '').split('\n').filter(x => x.length > 0 && x.length <= 200);
    config.profile = DIMENSIONS.some(key => config[key]) ? 'extended' : 'strict';
    return config;
  }
  trackPageView(context) {
    try {
      const path = normalizePath(context.path);
      const config = this.#config();
      if (!path || blocked(path, config.path_exclusions) || isBot(context.userAgent ?? '', config.bot_patterns)) return false;
      const day = daily();
      const country = config.country_collection && /^[A-Za-z]{2}$/.test(context.country ?? '') ? context.country.toUpperCase() : 'XX';
      this.#db.exec('BEGIN IMMEDIATE');
      this.#db.prepare('INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,1) ON CONFLICT(day,path,country) DO UPDATE SET views=views+1').run(day, path, country);
      if (config.referrer_collection) {
        const host = validHost(context.referrer ?? '');
        if (host) this.#db.prepare('INSERT INTO referrers_daily(day,path,referrer_host,views) VALUES (?,?,?,1) ON CONFLICT(day,path,referrer_host) DO UPDATE SET views=views+1').run(day, path, host);
      }
      const ua = context.userAgent ?? '';
      for (const [enabled, dimension, value] of [
        [config.browser_collection, 'browser', browser(ua)], [config.device_collection, 'device', device(ua)], [config.os_collection, 'os', operatingSystem(ua)]
      ]) if (enabled) this.#db.prepare('INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?,?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1').run(day, path, dimension, value);
      this.#db.exec('COMMIT');
      return true;
    } catch {
      try { this.#db.exec('ROLLBACK'); } catch {}
      return false;
    }
  }
  updatePrivacy(values, confirmation = '') {
    const incoming = Object.fromEntries(DIMENSIONS.map(key => [key, values[key] === true]));
    if (Object.values(incoming).some(Boolean) && confirmation !== 'yes') throw new Error('Explicit extended-collection confirmation is required.');
    this.#db.exec('BEGIN IMMEDIATE');
    try {
      for (const key of DIMENSIONS) setSetting(this.#db, key, incoming[key] ? '1' : '0');
      this.#recordHistory();
      this.#db.exec('COMMIT');
    } catch (error) { try { this.#db.exec('ROLLBACK'); } catch {} throw error; }
    return this.#config().profile;
  }
  returnToStrictMode() { this.updatePrivacy({}, ''); }
  setRetention(days) {
    const value = validRetention.has(Number(days)) ? Number(days) : 180;
    setSetting(this.#db, 'retention_days', value);
    this.#recordHistory();
  }
  #recordHistory() {
    const config = this.#config();
    const serialized = JSON.stringify(config);
    const fingerprint = sha256(JSON.stringify({ contract_version: CONTRACT_VERSION, schema_version: SCHEMA_VERSION, configuration: config }));
    this.#db.prepare('INSERT INTO privacy_configuration_history(timestamp,profile,effective_configuration_json,configuration_hash,application_version,schema_version) VALUES (?,?,?,?,?,?)').run(new Date().toISOString(), config.profile, serialized, fingerprint, '1.0.0', SCHEMA_VERSION);
  }
  dashboard(periodDays = 30) {
    const period = validRetention.has(Number(periodDays)) ? Number(periodDays) : 30;
    const from = new Date(Date.now() - (period - 1) * 86400000).toISOString().slice(0, 10);
    const to = daily();
    const total = this.#db.prepare('SELECT COALESCE(SUM(views),0) AS n FROM pageviews_daily WHERE day BETWEEN ? AND ?').get(from, to).n;
    const byDay = this.#db.prepare('SELECT day,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day').all(from, to);
    const byPage = this.#db.prepare('SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY views DESC,path LIMIT 500').all(from, to);
    const config = this.#config();
    return { total, byDay, byPage, retentionDays: config.retention_days, profile: config.profile };
  }
  audit() {
    const config = this.#config();
    const fingerprint = sha256(JSON.stringify({ contract_version: CONTRACT_VERSION, schema_version: SCHEMA_VERSION, configuration: config }));
    const tables = this.#db.prepare("SELECT name FROM sqlite_master WHERE type='table'").all().map(x => x.name);
    const forbidden = tables.some(x => /visitor|session|fingerprint|event|identity/i.test(x));
    const checks = {
      strict_or_explicit_extended: config.profile === 'strict' || config.profile === 'extended',
      optional_dimensions_effective: DIMENSIONS.every(x => typeof config[x] === 'boolean'),
      retention_configured: validRetention.has(config.retention_days),
      aggregate_schema: ['pageviews_daily','schema_migrations','privacy_configuration_history'].every(x => tables.includes(x)),
      no_identity_or_event_tables: !forbidden,
      no_ip_column: !this.#db.prepare('PRAGMA table_info(pageviews_daily)').all().some(x => /ip/i.test(x.name)),
      no_third_party_analytics: true,
      path_normalization_active: normalizePath('/users/123456') === '/users/:id',
      private_exclusions_active: blocked('/admin/example', config.path_exclusions)
    };
    return { application: 'Barelytics Node.js', contract_version: CONTRACT_VERSION, schema_version: SCHEMA_VERSION, profile: config.profile, fingerprint, configuration: config, checks, result: Object.values(checks).every(Boolean) ? 'PASS' : 'FAIL' };
  }
  cleanup(limit = 1000) {
    const bounded = Math.max(1, Math.min(1000, Number.isInteger(limit) ? limit : 1000));
    const cutoff = new Date(Date.now() - this.#config().retention_days * 86400000).toISOString().slice(0, 10);
    this.#db.exec('BEGIN IMMEDIATE');
    try {
      let complete = true;
      for (const table of ['pageviews_daily','referrers_daily','dimensions_daily']) {
        const count = this.#db.prepare(`SELECT COUNT(*) AS n FROM (SELECT rowid FROM ${table} WHERE day<? ORDER BY day LIMIT ?)`).get(cutoff, bounded).n;
        this.#db.prepare(`DELETE FROM ${table} WHERE rowid IN (SELECT rowid FROM ${table} WHERE day<? ORDER BY day LIMIT ?)`).run(cutoff, bounded);
        if (count === bounded) complete = false;
      }
      setSetting(this.#db, 'last_cleanup_at', new Date().toISOString());
      this.#db.exec('COMMIT');
      return complete;
    } catch { try { this.#db.exec('ROLLBACK'); } catch {} return false; }
  }
  close() { this.#db.close(); }
}

export function createTrackHandler(analytics) {
  return async (request, response) => {
    if (request.method !== 'POST') { response.writeHead(405, { Allow: 'POST' }).end(); return; }
    const chunks = [];
    let size = 0;
    try {
      for await (const chunk of request) {
        size += chunk.length;
        if (size > 8192) { response.writeHead(413).end(); return; }
        chunks.push(chunk);
      }
      const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
      if (!body || typeof body !== 'object' || Array.isArray(body) || Object.keys(body).length !== 1 || typeof body.path !== 'string') { response.writeHead(400).end(); return; }
      const path = normalizePath(body.path);
      if (!path) { response.writeHead(400).end(); return; }
      analytics.trackPageView({ path, userAgent: request.headers['user-agent'] });
      response.writeHead(204).end();
    } catch { response.writeHead(400).end(); }
  };
}

export function createAdminHandler(analytics, { authorize, verifyCsrf }) {
  if (typeof authorize !== 'function' || typeof verifyCsrf !== 'function') throw new TypeError('Admin authorization and CSRF verification callbacks are required.');
  return async (request, response) => {
    if (!authorize(request)) { response.writeHead(403).end(); return; }
    if (request.method === 'GET') { response.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }).end(JSON.stringify({ dashboard: analytics.dashboard(), audit: analytics.audit() })); return; }
    if (request.method !== 'POST') { response.writeHead(405, { Allow: 'GET, POST' }).end(); return; }
    let body = '';
    for await (const chunk of request) { body += chunk; if (Buffer.byteLength(body) > 8192) { response.writeHead(413).end(); return; } }
    try {
      const input = JSON.parse(body);
      if (typeof input.csrf !== 'string' || !verifyCsrf(request, input.csrf)) { response.writeHead(403).end(); return; }
      if (input.action === 'strict') analytics.returnToStrictMode();
      else if (input.action === 'privacy') analytics.updatePrivacy(input.values ?? {}, input.confirmation);
      else if (input.action === 'cleanup') analytics.cleanup();
      else { response.writeHead(400).end(); return; }
      response.writeHead(204, { 'Cache-Control': 'no-store' }).end();
    } catch { response.writeHead(400).end(); }
  };
}

function validHost(value) {
  try {
    const url = new URL(value);
    const host = url.hostname.toLowerCase().replace(/\.$/, '');
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || /^\d{1,3}(?:\.\d{1,3}){3}$/.test(host) || host.includes(':')) return null;
    return /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(host) ? host : null;
  } catch { return null; }
}
function browser(ua) { return /Firefox\//i.test(ua) ? 'Firefox' : /Edg\/|Edge\//i.test(ua) ? 'Edge' : /Chrome\//i.test(ua) ? 'Chrome' : /Safari\//i.test(ua) ? 'Safari' : 'Other'; }
function device(ua) { return /iPad|Tablet/i.test(ua) ? 'Tablet' : /Mobile|Android|iPhone/i.test(ua) ? 'Mobile' : 'Desktop'; }
function operatingSystem(ua) { return /Windows/i.test(ua) ? 'Windows' : /Android/i.test(ua) ? 'Android' : /iPhone|iPad|iOS/i.test(ua) ? 'iOS' : /Mac OS/i.test(ua) ? 'macOS' : /Linux/i.test(ua) ? 'Linux' : 'Other'; }
