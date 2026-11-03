const config = window.BARELYTICS_ADMIN_CONFIG || {};
const apiBase = new URL(config.apiBase || 'api/', window.location.href);
const $ = (id) => document.getElementById(id);
const state = { csrf: '', retention: 180, pagesPage: 1, dailyPage: 1, automationPage: 1, pageRequest: 0, perPage: 50 };

function text(value) { return document.createTextNode(String(value ?? '')); }
function table(container, headers, rows) {
  container.replaceChildren();
  if (!rows?.length) { const p = document.createElement('p'); p.textContent = 'No data for this selection.'; container.append(p); return; }
  const t = document.createElement('table'), head = t.createTHead().insertRow();
  for (const h of headers) { const th = document.createElement('th'); th.scope = 'col'; th.textContent = h; head.append(th); }
  const body = t.createTBody();
  for (const row of rows) { const tr = body.insertRow(); for (const value of row) { const td = tr.insertCell(); if (value instanceof Node) td.append(value); else td.append(text(value)); } }
  container.append(t);
}
function link(path) { const a = document.createElement('a'); a.href = `#page-detail`; a.textContent = path; a.dataset.path = path; return a; }
async function request(resource, query = {}, method = 'GET', body = undefined) {
  const url = new URL(config.apiQuery ? '' : resource, apiBase);
  if (config.apiQuery) url.searchParams.set('api', resource);
  for (const [key, value] of Object.entries(query)) if (value !== undefined && value !== '') url.searchParams.set(key, value);
  const options = { method, headers: { Accept: 'application/json' }, cache: 'no-store' };
  if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-Token'] = state.csrf; options.headers.RequestVerificationToken = state.csrf; options.body = JSON.stringify({ ...body, csrf: state.csrf }); }
  const response = await fetch(url, options);
  const payload = response.status === 204 ? { ok: true, data: {} } : await response.json().catch(() => null);
  if (!response.ok || !payload?.ok) throw new Error(payload?.error?.message || `Request failed (${response.status}).`);
  return payload.data;
}
function notice(message, error = false) { const box = $('notice'); box.textContent = message; box.classList.toggle('error', error); box.hidden = !message; }
function period() { return Math.min(Number($('period').value), state.retention); }
function query() { return { period: period(), bucket: $('bucket').value }; }
function metric(label, value, detail) { const card = document.createElement('article'); card.className = 'metric'; for (const item of [label, value, detail]) { const el = document.createElement(item === value ? 'strong' : 'span'); el.textContent = item; card.append(el); } return card; }
async function loadDashboard() {
  const d = await request('dashboard', query());
  $('metrics').replaceChildren(metric('Page views', Number(d.total).toLocaleString(), `${period()} day period`), metric('Pages viewed', d.active_pages, 'distinct paths'), metric('Daily average', d.daily_average, 'views per day'));
  table($('timeline'), ['Period', 'Views'], (d.timeline || []).map(r => [r.period, r.views]));
  table($('top-pages'), ['Page', 'Views'], (d.top_pages || []).map(r => [link(r.path), r.views]));
}
async function loadAutomation(page = state.automationPage) {
  const d = await request('automation', { ...query(), page, per_page: state.perPage });
  $('automation-metrics').replaceChildren(metric('Classified automation', Number(d.total).toLocaleString(), 'observed requests, including uncertain automation'));
  table($('automation-timeline'), ['Period (UTC)', 'Requests'], (d.timeline || []).map(r => [r.period, r.requests]));
  table($('automation-categories'), ['Category', 'Requests'], (d.categories || []).map(r => [r.category, r.requests]));
  table($('automation-families'), ['Category', 'Declared family / heuristic', 'Requests'], (d.families || []).map(r => [r.category, r.family, r.requests]));
  table($('automation-pages'), ['Page', 'Requests'], (d.rows || []).map(r => [link(r.path), r.requests]));
  state.automationPage = d.page;
  $('automation-page').textContent = `Page ${d.page} of ${Math.max(1, d.pages || 1)} (${d.total_rows} paths)`;
}
async function dimensionBreakdown(container, dimension, path, page = 1) {
  const d = await request('dimensions', { ...query(), dimension, path, page, per_page: state.perPage });
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
  $(`${pages}-page`).textContent = `Page ${d.page || page} of ${Math.max(1, d.pages || 1)} (${d.total_rows || 0} rows)`;
}
async function loadPage(path) {
  const generation = ++state.pageRequest;
  const d = await request('page', { ...query(), path });
  if (generation !== state.pageRequest) return;
  $('page-path').value = d.path;
  const dimensions = $('page-dimensions'); dimensions.replaceChildren();
  await Promise.all(['referrer', 'country', 'browser', 'device', 'os'].map(async dimension => {
    const section = document.createElement('section'), title = document.createElement('h3'), result = document.createElement('div');
    title.textContent = `${dimension[0].toUpperCase() + dimension.slice(1)} for this page`; section.append(title, result); dimensions.append(section);
    await dimensionBreakdown(result, dimension, d.path);
  }));
  if (generation !== state.pageRequest) return;
  const rows = (d.timeline || []).map(r => [r.period, r.views]);
  table($('page-result'), ['Period', 'Views'], rows);
  $('page-result').prepend(Object.assign(document.createElement('p'), { textContent: `Total: ${Number(d.total).toLocaleString()} views` }));
}
async function loadDimensions() {
  await dimensionBreakdown($('dimension-result'), $('dimension').value);
}
async function loadPrivacy() {
  const d = await request('privacy'); state.retention = d.retention_days;
  for (const option of $('period').options) option.disabled = Number(option.value) > state.retention;
  $('period').value = String(Math.min(Number($('period').value), state.retention));
  const form = $('privacy-form'); form.elements.retention_days.value = String(d.retention_days);
  for (const key of ['country_collection','referrer_collection','browser_collection','device_collection','os_collection']) form.elements[key].checked = Boolean(d[key]);
  form.elements.path_exclusions.value = (d.path_exclusions || []).join('\n'); form.elements.bot_patterns.value = (d.bot_patterns || []).join('\n');
  $('privacy-result').textContent = `Effective profile: ${String(d.profile).toUpperCase()}. Optional dimensions are ${d.profile === 'strict' ? 'disabled' : 'enabled'}.`;
}
async function loadSystem() { const d = await request('system'); $('system-result').textContent = JSON.stringify(d, null, 2); let button = $('system-migrate'); if (d.migration_required) { if (!button) { button = document.createElement('button'); button.id = 'system-migrate'; button.textContent = 'Apply database migrations'; button.addEventListener('click', async () => { try { await request('migrate', {}, 'POST', {}); notice('Database migrations applied.'); await refresh(); } catch (e) { notice(e.message, true); } }); $('system-result').after(button); } } else button?.remove(); }
async function loadAudit() { $('audit-result').textContent = JSON.stringify(await request('audit'), null, 2); }
async function loadIntegration() { const d = await request('integration'); const p = document.createElement('p'); p.textContent = d.instructions || 'See the runtime integration guide for setup instructions.'; $('integration-result').replaceChildren(p); if (d.javascript_snippet) { const code = document.createElement('pre'); code.textContent = d.javascript_snippet; $('integration-result').append(code); } }
async function refresh() { try { notice(''); await loadPrivacy(); await Promise.all([loadDimensions(), loadAutomation(), ...($('page-path').value ? [loadPage($('page-path').value)] : []), loadDashboard(), loadPages('pages', state.pagesPage), loadPages('daily', state.dailyPage), loadSystem(), loadAudit(), loadIntegration()]); } catch (e) { notice(e.message, true); } }
async function initialize() {
  try {
    const session = await request('session');
    if (!session.authenticated) throw new Error('Sign in through the host application to view Barelytics administration.');
    state.csrf = session.csrf || '';
    if (session.retention_days) state.retention = session.retention_days;
    if (session.capabilities?.account_url) { const account = document.createElement('a'); account.href = session.capabilities.account_url; account.textContent = 'Account'; $('account-links').append(account); }
    if (session.capabilities?.logout) { const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary'; button.textContent = 'Sign out'; button.addEventListener('click', async () => { try { await request('logout', {}, 'POST', {}); window.location.href = config.loginUrl || window.location.pathname; } catch (e) { notice(e.message, true); } }); $('account-links').append(button); }
    await refresh();
  } catch (e) { notice(e.message, true); }
}
$('refresh').addEventListener('click', refresh);
$('period').addEventListener('change', refresh);
$('bucket').addEventListener('change', refresh);
$('page-form').addEventListener('submit', async e => { e.preventDefault(); try { await loadPage($('page-path').value); } catch (x) { notice(x.message, true); } });
$('dimension-form').addEventListener('submit', async e => { e.preventDefault(); try { await loadDimensions(); } catch (x) { notice(x.message, true); } });
$('privacy-form').addEventListener('submit', async e => { e.preventDefault(); const form = e.currentTarget; const values = Object.fromEntries(['country_collection','referrer_collection','browser_collection','device_collection','os_collection'].map(k => [k, form.elements[k].checked])); try { await request('privacy', {}, 'POST', { ...values, retention_days: Number(form.elements.retention_days.value), path_exclusions: form.elements.path_exclusions.value, bot_patterns: form.elements.bot_patterns.value, confirmation: form.elements.confirmation.checked ? 'yes' : '' }); notice('Privacy settings saved.'); await refresh(); } catch (x) { notice(x.message, true); } });
$('strict').addEventListener('click', async () => { if (!confirm('Disable all optional dimensions? Historical aggregates will be retained.')) return; try { await request('strict', {}, 'POST', {}); notice('Strict Mode is active.'); await refresh(); } catch (x) { notice(x.message, true); } });
$('cleanup').addEventListener('click', async () => { try { const d = await request('cleanup', {}, 'POST', {}); $('maintenance-result').textContent = d.complete ? 'Expired data removed.' : 'A bounded cleanup batch ran; repeat to remove remaining expired data.'; } catch (x) { notice(x.message, true); } });
$('delete-all').addEventListener('click', async () => { if (!confirm('Permanently delete all aggregate statistics? This cannot be undone.')) return; try { await request('delete-all', {}, 'POST', { confirmation: 'DELETE' }); notice('All aggregate statistics deleted.'); await refresh(); } catch (x) { notice(x.message, true); } });
for (const button of document.querySelectorAll('[data-page]')) button.addEventListener('click', async () => { const name = button.dataset.page, key = `${name}Page`, next = Math.max(1, state[key] + Number(button.dataset.step)); try { if (name === 'automation') await loadAutomation(next); else await loadPages(name, next); } catch (x) { notice(x.message, true); } });
document.addEventListener('click', e => { const a = e.target.closest('a[data-path]'); if (a) { e.preventDefault(); $('page-path').value = a.dataset.path; loadPage(a.dataset.path).catch(x => notice(x.message, true)); } });
initialize();
