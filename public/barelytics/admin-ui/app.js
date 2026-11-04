if (document.getElementById('return-link')) {
  const menu = document.querySelector('.mobile-navigation'); const mobile = matchMedia('(max-width: 800px)');
  if(menu) { menu.open = !mobile.matches; mobile.addEventListener('change', () => { menu.open = !mobile.matches; }); }
  try {
    const previous = new URL(document.referrer || location.href);
    const saved = new URL(new URLSearchParams(location.search).get('return') || previous.href, location.href);
    if (saved.origin === location.origin && saved.pathname === new URL('admin.php', location.href).pathname) document.getElementById('return-link').href = saved.href;
  } catch { /* Invalid return links keep the local administration default. */ }
}
if (document.getElementById('period')) {
const config = window.BARELYTICS_ADMIN_CONFIG || {};
const apiBase = new URL(config.apiBase || 'api/', window.location.href);
const $ = (id) => document.getElementById(id);
const stylesheetMissing = !getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
const state = { reachAvailable: false, csrf: '', retention: 180, pagesPage: 1, dailyPage: 1, automationPage: 1, pageRequest: 0, perPage: 50, route: 'overview', controller: null, ready: false, dirty: false, revision: 0, loaded: new Map() };

function text(value) { return document.createTextNode(String(value ?? '')); }
function table(container, headers, rows) {
  container.replaceChildren();
  if (!rows?.length) { const p = document.createElement('p'); p.textContent = 'No data for this selection.'; container.append(p); return; }
  const t = document.createElement('table'), head = t.createTHead().insertRow();
  for (const h of headers) { const th = document.createElement('th'); th.scope = 'col'; th.textContent = h; if(h === headers[0] && /^(Day|Period)/.test(h)) { th.textContent += ' ↓'; th.setAttribute('aria-sort','descending'); } if (h === 'Views' || h === 'Requests' || h === 'Reports') { th.className = 'numeric'; if (!headers[0].startsWith('Period') && !headers[0].startsWith('Day')) { th.textContent += ' ↓'; th.setAttribute('aria-sort', 'descending'); } } head.append(th); }
  const body = t.createTBody();
  for (const row of rows) { const tr = body.insertRow(); for (const value of row) { const td = tr.insertCell(); if (typeof value === 'number') { td.className = 'numeric'; } if (value instanceof Node) td.append(value); else td.append(text(typeof value === 'number' ? value.toLocaleString() : value)); } }
  container.append(t);
}
function link(path) { const a = document.createElement('a'); a.href = routeUrl('page-detail', { path, from: state.route, from_page: state[`${state.route}Page`] || 1 }); a.textContent = path; a.title = path; a.dataset.path = path; return a; }
async function request(resource, query = {}, method = 'GET', body = undefined) {
  const url = new URL(config.apiQuery ? '' : resource, apiBase);
  if (config.apiQuery) url.searchParams.set('api', resource);
  for (const [key, value] of Object.entries(query)) if (value !== undefined && value !== '') url.searchParams.set(key, value);
  const options = { method, headers: { Accept: 'application/json' }, cache: 'no-store', ...(method === 'GET' ? { signal: state.controller?.signal } : {}) };
  if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-Token'] = state.csrf; options.headers.RequestVerificationToken = state.csrf; options.body = JSON.stringify({ ...body, csrf: state.csrf }); }
  const response = await fetch(url, options);
  const payload = response.status === 204 ? { ok: true, data: {} } : await response.json().catch(error => { if (error.name === 'AbortError') throw error; return null; });
  if (!response.ok || !payload?.ok) throw new Error(payload?.error?.message || `Request failed (${response.status}).`);
  return payload.data;
}
function notice(message, error = false) { if (!message && stylesheetMissing) { message = 'The administration stylesheet could not be loaded. Check that admin-ui/admin.css is uploaded and served as text/css.'; error = true; } const box = $('notice'); box.textContent = message; box.classList.toggle('error', error); box.hidden = !message; }
function period() { return Math.min(Number($('period').value), state.retention); }
function query() { return { period: period(), bucket: $('bucket').value }; }
function metric(label, value, detail) { const card = document.createElement('article'); card.className = 'metric'; for (const [i, item] of [label, value, detail].entries()) { const el = document.createElement(i === 1 ? 'strong' : 'span'); el.textContent = item; card.append(el); } return card; }
async function loadDashboard() {
  const d = await request('dashboard', query());
  $('metrics').replaceChildren(metric('Page views', Number(d.total).toLocaleString(), `${period()} day period`), metric('Pages viewed', d.active_pages, 'distinct paths'), metric('Daily average', Number(d.daily_average).toLocaleString(), 'views per day'));
  chart(d.timeline || []);
  table($('timeline'), ['Period', 'Views'], newest(d.timeline).map(r => [r.period, r.views]));
  table($('top-pages'), ['Page', 'Views'], (d.top_pages || []).map(r => [link(r.path), r.views]));
}
async function loadReach() {
  $('reach-metrics').replaceChildren(); $('reach-timeline').replaceChildren();
  if (!state.reachAvailable) { $('reach-state').textContent = 'Daily browser reach reports are unavailable in this adapter.'; return; }
  const d = await request('reach', { period: period() });
  $('reach-state').textContent = d.enabled ? 'Optional Extended measurement is enabled. Reporting days use UTC.' : 'Collection is disabled. Historical aggregate reports remain subject to retention or deletion.';
  $('reach-metrics').replaceChildren(metric('Daily browser reach reports', Number(d.total).toLocaleString(), 'received reports · UTC days'));
  table($('reach-timeline'), ['Day (UTC)', 'Reports'], newest(d.timeline).map(r => [r.day, r.reports]));
}
async function loadAutomation(page = state.automationPage) {
  const d = await request('automation', { ...query(), page, per_page: state.perPage });
  $('automation-metrics').replaceChildren(metric('Classified automation', Number(d.total).toLocaleString(), 'observed requests, including uncertain automation'));
  table($('automation-timeline'), ['Period (UTC)', 'Requests'], newest(d.timeline).map(r => [r.period, r.requests]));
  table($('automation-categories'), ['Category', 'Requests'], (d.categories || []).map(r => [r.category, r.requests]));
  table($('automation-families'), ['Category', 'Declared family / heuristic', 'Requests'], (d.families || []).map(r => [r.category, r.family, r.requests]));
  table($('automation-pages'), ['Page', 'Requests'], (d.rows || []).map(r => [link(r.path), r.requests]));
  state.automationPage = d.page;
  pagination('automation', d);
}
async function dimensionBreakdown(container, dimension, path, page = 1) {
  const d = await request('dimensions', { ...query(), dimension, path, page, per_page: state.perPage });
  page = d.page || page;
  if (!d.enabled) { container.textContent = `${d.label || dimension} collection is disabled in the current privacy configuration. Historical aggregates may be retained.`; return; }
  if (!d.rows?.length) { container.textContent = 'No observed data for this selection. Values may be missing or withheld; they are not inferred.'; return; }
  table(container, [d.label || 'Value', 'Views'], d.rows.map(r => [r.value === 'XX' || r.value === 'Unknown' ? 'Unknown / unavailable' : r.value, r.views]));
  if (d.pages > 1) {
    const nav = document.createElement('div'); nav.className = 'pagination';
    for (const [label, next] of [['Previous', page - 1], ['Next', page + 1]]) {
      const button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.disabled = next < 1 || next > d.pages;
      button.addEventListener('click', () => dimensionBreakdown(container, dimension, path, next).catch(e => notice(e.message, true))); nav.append(button);
    }
    nav.append(text(`Page ${page} of ${d.pages} (${d.total_rows} values)`)); container.append(nav);
  }
}
async function loadPages(kind, page) {
  const d = await request(kind, { ...query(), page, per_page: state.perPage });
  const rows = (d.rows || []).map(r => kind === 'pages' ? [link(r.path), r.views] : [r.day, link(r.path), r.views]);
  table($(kind === 'pages' ? 'pages-table' : 'daily-table'), kind === 'pages' ? ['Page', 'Views'] : ['Day (UTC)', 'Page', 'Views'], rows);
  const pages = kind === 'pages' ? 'pages' : 'daily'; state[`${pages}Page`] = d.page || page;
  pagination(pages, d);
}
async function loadPage(path) {
  const generation = ++state.pageRequest;
  const d = await request('page', { ...query(), path });
  if (generation !== state.pageRequest) return;
  $('page-path').value = d.path;
  $('selected-path').textContent = d.path;
  $('copy-path').dataset.path = d.path;
  const rows = newest(d.timeline).map(r => [r.period, r.views]);
  table($('page-result'), ['Period', 'Views'], rows);
  $('page-result').prepend(Object.assign(document.createElement('p'), { textContent: `Total: ${Number(d.total).toLocaleString()} views` }));
  await dimensionBreakdown($('page-dimensions'), $('page-dimension').value, d.path);
}
async function loadDimensions() {
  $('dimension-scope').textContent = currentParams().has('path') ? `Page: ${currentParams().get('path')}` : 'All pages in the reporting period.';
  await dimensionBreakdown($('dimension-result'), $('dimension').value, currentParams().get('path') || undefined);
}
async function loadPrivacy() {
  const d = await request('privacy');
  state.reachAvailable = d.daily_reach_available === true;
  const reach = $('privacy-form').elements.daily_reach_enabled;
  reach.checked = d.daily_reach_enabled === true; reach.disabled = !state.reachAvailable;
  $('reach-capability').textContent = state.reachAvailable
    ? 'Requires explicit acknowledgement and data-daily-reach="true" on the tracker. Use the host/CMP permission callback when required.'
    : 'Unavailable in this adapter; it cannot be enabled here.';
  state.retention = d.retention_days;
  for (const option of $('period').options) option.disabled = Number(option.value) > state.retention;
  $('period').value = String(Math.min(Number($('period').value), state.retention));
  const form = $('privacy-form'); form.elements.retention_days.value = String(d.retention_days);
  for (const key of ['country_collection','referrer_collection','browser_collection','device_collection','os_collection']) form.elements[key].checked = Boolean(d[key]);
  form.elements.path_exclusions.value = (d.path_exclusions || []).join('\n'); form.elements.bot_patterns.value = (d.bot_patterns || []).join('\n');
  $('privacy-result').textContent = `Effective profile: ${String(d.profile).toUpperCase()}. Optional measurement is ${d.profile === 'strict' ? 'disabled' : 'enabled'}.`;
}
function newest(rows = []) { return [...(rows || [])].sort((a,b) => String(b.period || b.day).localeCompare(String(a.period || a.day))); }
let chartRows = [];
function drawChart() {
  const container = $('traffic-chart'), canvas = container.querySelector('canvas');
  if (!canvas || !container.clientWidth) return;
  const width = container.clientWidth, height = container.clientHeight, ratio = window.devicePixelRatio || 1;
  canvas.width = width * ratio; canvas.height = height * ratio;
  const ctx = canvas.getContext('2d'); if (!ctx) return; ctx.scale(ratio, ratio);
  const max = Math.max(1, ...chartRows.map(r => Number(r.views)));
  ctx.strokeStyle = '#e4eae1'; ctx.lineWidth = 1;
  for (let i = 0; i < 4; i++) { const y = 12 + (height - 28) * i / 3; ctx.beginPath(); ctx.moveTo(0,y); ctx.lineTo(width,y); ctx.stroke(); }
  const points = chartRows.map((r,i) => [8 + i / Math.max(1, chartRows.length - 1) * (width - 16), height - 12 - Number(r.views) / max * (height - 30)]);
  ctx.beginPath(); ctx.moveTo(points[0][0], height - 12); for (const [x,y] of points) ctx.lineTo(x,y); ctx.lineTo(points.at(-1)[0],height - 12); ctx.closePath(); ctx.fillStyle = '#e7efe2'; ctx.fill();
  ctx.beginPath(); for (const [i,[x,y]] of points.entries()) { if(i) ctx.lineTo(x,y); else ctx.moveTo(x,y); } ctx.strokeStyle = '#547c42'; ctx.lineWidth = 2; ctx.stroke();
  if(points.length === 1) { ctx.beginPath(); ctx.arc(...points[0],3,0,Math.PI*2); ctx.fillStyle = '#547c42'; ctx.fill(); }
}
function chart(rows) {
  const container = $('traffic-chart'); container.replaceChildren(); container.removeAttribute('role'); container.removeAttribute('aria-label'); $('chart-caption')?.remove();
  if (!rows.length) { container.textContent = 'No page views in this period yet.'; return; }
  chartRows = [...rows].sort((a,b) => a.period.localeCompare(b.period));
  const canvas = document.createElement('canvas'); canvas.setAttribute('aria-hidden','true'); container.append(canvas);
  container.setAttribute('role', 'img'); container.setAttribute('aria-label', 'Page views over time, oldest to newest. Exact values are in the data table below.');
  drawChart();
  let caption = $('chart-caption'); if (!caption) { caption = document.createElement('p'); caption.id = 'chart-caption'; caption.className = 'chart-caption'; container.after(caption); } caption.textContent = `${chartRows[0].period} — ${chartRows.at(-1).period} · oldest to newest`;
}
new ResizeObserver(drawChart).observe($('traffic-chart'));
function pagination(kind, d) {
  const page = d.page || 1, pages = Math.max(1, d.pages || 1);
  state[`${kind}Page`] = page;
  $(`${kind}-page`).textContent = `Page ${page} of ${pages} · ${Number(d.total_rows || 0).toLocaleString()} rows · ${state.perPage} per page`;
  for (const b of document.querySelectorAll(`[data-page="${kind}"]`)) b.disabled = Number(b.dataset.step) < 0 ? page <= 1 : page >= pages;
}
function summary(container, data) {
  container.replaceChildren();
  const rows = Object.entries(data).filter(([,v]) => v === null || typeof v !== 'object').map(([k,v]) => [k.replaceAll('_', ' '), String(v ?? 'Unavailable')]);
  table(container, ['Property', 'Value'], rows);
  const details = document.createElement('details'), title = document.createElement('summary'), raw = document.createElement('pre');
  title.textContent = 'Technical details · JSON'; raw.textContent = JSON.stringify(data, null, 2); details.append(title, raw); container.append(details);
}
async function loadSystem() {
  const d = await request('system'); summary($('system-result'), d);
  if (d.migration_required) { const button = document.createElement('button'); button.textContent = 'Apply database migrations'; button.addEventListener('click', () => mutate('migrate', {}, 'Database migrations applied.')); $('system-result').append(button); }
}
async function loadAudit() {
  const d = await request('audit'); summary($('audit-result'), d);
  if (d.checks) { const list = document.createElement('ul'); list.className = 'check-list'; for (const check of d.checks) { const li = document.createElement('li'), status = document.createElement('strong'); status.textContent = check.status; li.append(status, text(check.name)); list.append(li); } $('audit-result').prepend(list); }
}
async function loadIntegration() {
  const d = await request('integration'); const p = document.createElement('p'); p.textContent = d.instructions || 'See the runtime integration guide for setup instructions.'; $('integration-result').replaceChildren(p);
  for (const [key,label] of [['javascript_snippet','Browser tracker'],['php_snippet','Native PHP integration']]) if (d[key]) { const heading = document.createElement('h3'), code = document.createElement('pre'); heading.textContent = label; code.textContent = d[key]; $('integration-result').append(heading, code); }
}
const sections = {
  overview: ['Overview', 'A clear picture of your traffic. No personal profiles.', loadDashboard],
  pages: ['Pages', 'Your most viewed paths, ranked by page views.', () => loadPages('pages', state.pagesPage)],
  daily: ['Daily statistics', 'Every day and page, with the newest UTC dates first.', () => loadPages('daily', state.dailyPage)],
  'page-detail': ['Page detail', 'Explore a normalized path and its collected dimensions.', () => { const path = currentParams().get('path'); if (path) return loadPage(path); }],
  dimensions: ['Sources & dimensions', 'Breakdowns reflect only the information you chose to collect.', loadDimensions],
  automation: ['Automation & AI agents', 'Observed classified requests. Declared identities are unverified.', loadAutomation],
  reach: ['Daily Reach', 'Optional daily browser reach reports, with explicit limits.', loadReach],
  privacy: ['Privacy settings', 'Control collection, retention and exclusions.', () => { if (!state.dirty) return loadPrivacy(); }],
  maintenance: ['Maintenance', 'Storage health, retention cleanup and aggregate deletion.', loadSystem],
  audit: ['Privacy audit', 'Effective configuration, retained history and technical evidence.', loadAudit],
  integration: ['Integration', 'Connect your site using a first-party collector.', loadIntegration]
};
function currentParams() { return new URLSearchParams(location.hash.slice(1).split('?')[1] || ''); }
function routeUrl(section, overrides = {}) {
  const params = new URLSearchParams({ period: $('period').value, bucket: $('bucket').value });
  if (section === 'dimensions') {
    params.set('dimension', $('dimension').value);
    if (state.route === 'dimensions' && currentParams().has('path')) params.set('path',currentParams().get('path'));
  }
  if (['pages','daily','automation'].includes(section)) params.set('page', state[`${section}Page`]);
  if (section === 'page-detail') {
    for (const key of ['path','from','from_page']) if (currentParams().has(key)) params.set(key,currentParams().get(key));
    params.set('dimension', $('page-dimension').value);
  }
  for (const [key,value] of Object.entries(overrides)) params.set(key,value);
  return `#${Object.hasOwn(sections, section) ? section : 'overview'}?${params}`;
}
function updateLinks() {
  for (const a of document.querySelectorAll('a[href^="#"]:not([data-path]):not(.skip-link)')) {
    const section = a.getAttribute('href').slice(1).split('?')[0];
    if (Object.hasOwn(sections,section)) { a.href = routeUrl(section); if (a.closest('nav')) { if (section === state.route || state.route === 'page-detail' && section === 'pages') a.setAttribute('aria-current','page'); else a.removeAttribute('aria-current'); } }
  }
  for (const a of document.querySelectorAll('[data-secondary]')) { const url = new URL(a.dataset.secondary, location.href); url.searchParams.set('return', location.pathname + location.search + routeUrl(state.route)); a.href = url.href; }
  if (state.route === 'page-detail') {
    const previous = currentParams().get('from'), from = Object.hasOwn(sections,previous) && previous !== 'page-detail' ? previous : 'pages';
    $('page-back').href = routeUrl(from, {page: currentParams().get('from_page') || 1});
    $('page-back').textContent = `← Back to ${sections[from][0].toLowerCase()}`;
  }
}
async function navigate(force = false, focus = true) {
  if (!state.ready) return;
  const raw = location.hash.slice(1).split('?')[0], legacy = new URLSearchParams(location.search);
  const aliases = { page:'page-detail', statistics:'overview', explore:'pages', system:'maintenance' };
  const target = aliases[raw] || raw || aliases[legacy.get('view')] || legacy.get('view') || 'overview';
  state.route = Object.hasOwn(sections,target) ? target : 'overview';
  const params = raw ? currentParams() : legacy;
  if (['7','30','90','180','365'].includes(params.get('period'))) $('period').value = String(Math.min(Number(params.get('period')), state.retention));
  if (['day','week','month'].includes(params.get('bucket'))) $('bucket').value = params.get('bucket');
  if (['country','referrer','browser','device','os'].includes(params.get('dimension'))) { $('dimension').value = params.get('dimension'); if(state.route === 'page-detail') $('page-dimension').value = params.get('dimension'); }
  if (raw && ['pages','daily','automation'].includes(state.route)) state[`${state.route}Page`] = Math.max(1,Math.min(10000, Number(params.get('page')) || 1));
  if (!raw) {
    for (const kind of ['pages','daily','automation']) state[`${kind}Page`] = Math.max(1, Math.min(10000, Number(legacy.get('page_num')) || 1));
    history.replaceState(null,'',routeUrl(state.route,legacy.has('path') ? {path:legacy.get('path')} : {}));
  }
  for (const id of Object.keys(sections)) { $(id).hidden = id !== state.route; $(id).removeAttribute('aria-busy'); }
  $('page-title').textContent = sections[state.route][0]; document.title = `${sections[state.route][0]} · Barelytics`;
  $('page-description').textContent = sections[state.route][1]; $('section-label').textContent = ['privacy','maintenance','audit','integration'].includes(state.route) ? 'ADMINISTRATION' : 'ANALYTICS';
  $('report-controls').hidden = ['privacy','maintenance','audit','integration'].includes(state.route);
  $('bucket-control').hidden = !['overview','page-detail','automation'].includes(state.route); updateLinks();
  if (focus) { $('page-title').focus({preventScroll:true}); $('page-title').scrollIntoView({block:'start'}); }
  const route = state.route, key = `${route}:${location.hash}:${period()}:${$('bucket').value}:${state.revision}`;
  state.controller?.abort(); state.controller = null;
  $('loading').hidden = true; $('retry').hidden = true;
  if (!force && state.loaded.get(route) === key) return;
  state.controller = new AbortController(); const controller = state.controller;
  notice('');
  $('loading').hidden = false; $('retry').hidden = true; $(state.route).setAttribute('aria-busy','true');
  try { await sections[route][2](); if (!controller.signal.aborted) state.loaded.set(route,key); }
  catch(e) { if (!controller.signal.aborted && e.name !== 'AbortError') { notice(e.message,true); $('retry').hidden = false; } }
  finally { if (controller === state.controller) { $('loading').hidden = true; $(route).removeAttribute('aria-busy'); } }
}
async function refresh() { state.revision++; await navigate(true,false); }
async function mutate(resource, body, message) {
  try { await request(resource, {}, 'POST', body); state.dirty = false; await loadPrivacy(); state.loaded.clear(); state.revision++; await navigate(true,false); notice(message); }
  catch(e) { notice(e.message,true); }
}
async function initialize() {
  try {
    const session = await request('session');
    if (!session.authenticated) throw new Error('Sign in through the host application to view Barelytics administration.');
    state.csrf = session.csrf || '';
    await loadPrivacy();
    for (const [key,label] of [['account_url','Account'],['diagnostics_url','Diagnostics'],['audit_url','Full audit & exports']]) if (session.capabilities?.[key]) {
      const a = document.createElement('a'); a.href = session.capabilities[key]; a.dataset.secondary = session.capabilities[key]; a.textContent = label;
      (key === 'diagnostics_url' ? $('diagnostics-link') : key === 'audit_url' ? $('audit-result') : $('account-links')).append(a);
      if(key === 'audit_url') { a.id = 'full-audit-link'; $('audit').prepend(a); }
    }
    if (session.capabilities?.delete_all === false) $('delete-all').hidden = true;
    if (session.capabilities?.logout) { const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Sign out'; button.addEventListener('click', async () => { try { await request('logout', {}, 'POST', {}); location.href = config.loginUrl || location.pathname; } catch(e) { notice(e.message,true); } }); $('account-links').append(button); }
    state.ready = true; await navigate(false,false);
  } catch(e) { notice(e.message,true); }
}
$('refresh').addEventListener('click', refresh);
$('retry').addEventListener('click', () => navigate(true,false));
for (const id of ['period','bucket']) $(id).addEventListener('change', () => { state.pagesPage = state.dailyPage = state.automationPage = 1; state.revision++; const url = routeUrl(state.route); if (location.hash === url) navigate(true,false); else location.hash = url; });
window.addEventListener('hashchange', () => navigate());
$('page-form').addEventListener('submit', e => { e.preventDefault(); const url = routeUrl('page-detail', { path: $('page-path').value }); if(location.hash === url) navigate(true,false); else location.hash = url; });
$('dimension-form').addEventListener('submit', e => { e.preventDefault(); const url = routeUrl('dimensions'); if (location.hash === url) navigate(true,false); else location.hash = url; });
$('page-dimension').addEventListener('change', () => { location.hash = routeUrl('page-detail'); });
$('copy-path').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('copy-path').dataset.path || $('page-path').value); notice('Full path copied.'); } catch { const range = document.createRange(); range.selectNodeContents($('selected-path')); getSelection().removeAllRanges(); getSelection().addRange(range); notice('Full path selected. Use your browser’s Copy command.'); } });
$('privacy-form').addEventListener('input', () => { state.dirty = true; });
$('privacy-form').addEventListener('submit', async e => {
  e.preventDefault(); const form = e.currentTarget;
  const values = Object.fromEntries(['country_collection','referrer_collection','browser_collection','device_collection','os_collection'].map(k => [k, form.elements[k].checked]));
  await mutate('privacy', { ...values, ...(state.reachAvailable ? { daily_reach_enabled: form.elements.daily_reach_enabled.checked } : {}), retention_days: Number(form.elements.retention_days.value), path_exclusions: form.elements.path_exclusions.value, bot_patterns: form.elements.bot_patterns.value, confirmation: form.elements.confirmation.checked ? 'yes' : '' }, 'Privacy settings saved.');
});
$('strict').addEventListener('click', async () => { if (confirm('Disable all optional measurement, including Daily Reach? Historical aggregates will be retained.')) await mutate('strict', {}, 'Strict Mode is active.'); });
$('cleanup').addEventListener('click', async () => { try { const d = await request('cleanup', {}, 'POST', {}); $('maintenance-result').textContent = d.complete ? 'Expired data removed.' : 'A bounded cleanup batch ran; repeat to remove remaining expired data.'; } catch(e) { notice(e.message,true); } });
$('delete-all').addEventListener('click', async () => { if (confirm('Permanently delete all aggregate statistics? This cannot be undone.')) await mutate('delete-all', { confirmation: 'DELETE' }, 'All aggregate statistics deleted.'); });
for (const button of document.querySelectorAll('[data-page]')) button.addEventListener('click', async () => {
  if(button.disabled) return;
  const kind = button.dataset.page; state[`${kind}Page`] += Number(button.dataset.step); history.pushState(null,'',routeUrl(kind)); await navigate(true,false);
  const container = $(kind === 'automation' ? 'automation-pages' : `${kind}-table`); container.tabIndex = -1; container.focus({preventScroll:true}); container.scrollIntoView({block:'start'});
});
document.addEventListener('click', e => {
  const a = e.target.closest('a'); if(!a || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
  if (a.closest('.section-nav') && matchMedia('(max-width: 800px)').matches) document.querySelector('.mobile-navigation').open = false;
});
const mobile = matchMedia('(max-width: 800px)');
mobile.addEventListener('change', () => { document.querySelector('.mobile-navigation').open = !mobile.matches; });
if (mobile.matches) document.querySelector('.mobile-navigation').open = false;
initialize();
}
