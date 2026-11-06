import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { chromium, firefox, webkit } from 'playwright';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const directory = mkdtempSync(resolve(tmpdir(), 'barelytics-reach-browser-'));
const control = resolve(directory, 'control.json');
const environment = { ...process.env, BARELYTICS_DATABASE_PATH: resolve(directory, 'analytics.sqlite'), REACH_TEST_CONTROL: control };
const configure = values => writeFileSync(control, JSON.stringify(values));
const php = code => execFileSync('php', ['-r', `require $argv[1]; ${code}`, resolve(root, 'public/barelytics/src/Barelytics.php')], { env: environment, encoding: 'utf8' });
const enabled = value => php(`$db=\\Barelytics\\openDatabase(); \\Barelytics\\saveDailyReachSetting($db, ${value ? 'true' : 'false'}, true);`);
const pageEnabled = value => php(`$db=\\Barelytics\\openDatabase(); \\Barelytics\\saveDailyPageReachSetting($db, ${value ? 'true' : 'false'}, true);`);
const reset = () => php("$db=\\Barelytics\\connectDatabase(); $db->exec('DELETE FROM daily_page_reach'); \\Barelytics\\setSetting($db,'daily_page_reach_limited_days','[]'); $db->exec('DELETE FROM daily_reach'); $db->exec('DELETE FROM pageviews_daily');");
const reports = () => Number(php("echo \\Barelytics\\queryColumn(\\Barelytics\\connectDatabase(), 'SELECT COALESCE(SUM(reports),0) FROM daily_reach');"));
const pageReports = () => Number(php("echo \\Barelytics\\queryColumn(\\Barelytics\\connectDatabase(), 'SELECT COALESCE(SUM(reports),0) FROM daily_page_reach');"));
const views = () => Number(php("echo \\Barelytics\\queryColumn(\\Barelytics\\connectDatabase(), 'SELECT COALESCE(SUM(views),0) FROM pageviews_daily');"));
configure({}); enabled(true);
php("$db=\\Barelytics\\connectDatabase(); \\Barelytics\\setSetting($db, 'setup_complete', '1'); \\Barelytics\\setSetting($db, 'admin_password_hash', password_hash('synthetic-browser-admin-password', PASSWORD_DEFAULT));");
const port = await new Promise(resolvePort => { const socket = createServer(); socket.listen(0, '127.0.0.1', () => { const value = socket.address().port; socket.close(() => resolvePort(value)); }); });
const base = `http://127.0.0.1:${port}`;
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', resolve(root, 'public'), resolve(root, 'tests/browser/router.php')], { env: environment, stdio: ['ignore','ignore','pipe'] });
let serverLog = ''; server.stderr.on('data', data => { serverLog = (serverLog + data).slice(-5000); });
const delay = ms => new Promise(resolveDelay => setTimeout(resolveDelay, ms));
for (let i=0; i<50; i++) { try { if ((await fetch(base)).ok) break; } catch {} await delay(100); }

// HeadlessChrome is deliberately classified as automation by the production rules.
// Use an ordinary declared UA to exercise the eligible browser path.
const ordinaryUserAgent = 'Mozilla/5.0 Chrome/156.0.0.0 Safari/537.36';
const engines = { chromium, firefox, webkit };
const selected = (process.env.REACH_BROWSERS || 'chromium,firefox,webkit').split(',');
let passed = 0;
try {
  for (const name of selected) {
    const engine = engines[name]; assert.ok(engine, `Unknown browser ${name}`);
    const launch = { headless: true, ...(name === 'chromium' && process.env.REACH_CHROME_EXECUTABLE ? { executablePath: process.env.REACH_CHROME_EXECUTABLE } : {}) };
    const browser = await engine.launch(launch);
    console.log(`${name}: ${browser.version()}`);
    const visit = async (context, path='/article') => { const page=await context.newPage(); await page.goto(base+path, {waitUntil:'networkidle'}); return page; };
    const caseRun = async (label, run) => {
      if (process.env.REACH_CASE_FILTER && !label.includes(process.env.REACH_CASE_FILTER)) return;
      configure({}); enabled(true); pageEnabled(false); reset();
      const context = await browser.newContext({userAgent:ordinaryUserAgent}); const attempts = [];
      context.on('request', request => { if (new URL(request.url()).pathname.endsWith('/reach-count.php')) attempts.push(request); });
      try { await run(context, attempts); passed++; console.log(`  PASS ${label}`); }
      finally { await context.close(); }
    };
    const marker = page => page.evaluate(() => new Promise((done, reject) => {
      const request = indexedDB.open('barelytics-daily-reach', 1); request.onerror = reject;
      request.onsuccess = () => {
        const db = request.result, tx = db.transaction('state', 'readonly'), read = tx.objectStore('state').get('day');
        tx.oncomplete = () => { done(read.result); db.close(); };
      };
    }));
    try {
      const localPages = page => page.evaluate(() => new Promise((done, reject) => {
        const open=indexedDB.open('barelytics-daily-reach',1);open.onerror=reject;
        open.onsuccess=()=>{const db=open.result,tx=db.transaction('state','readonly'),read=tx.objectStore('state').get('pages');tx.oncomplete=()=>{done(read.result);db.close();};};
      }));
      const iphoneAgents = {
        safari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Mobile/15E148 Safari/604.1',
        webview: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'
      };
      // Count storage property access without reading any actual stored values.
      const observeStorage = async context => context.addInitScript(() => {
        const storage = window.indexedDB; window.__storageReads = 0;
        Object.defineProperty(window, 'indexedDB', {get() { window.__storageReads++; return storage; }});
      });
      for (const [client, userAgent] of Object.entries(iphoneAgents)) {
        for (const mode of ['allowed', 'strict', 'opt-out', 'false', 'throw', 'reject', 'storage', 'missing-storage', 'hash', 'prepare']) {
          await caseRun(`Mobile ${client}: ${mode}, independent transport and storage stages`, async () => {
            enabled(false); pageEnabled(mode !== 'strict');
            if (mode === 'opt-out') configure({reach_attribute:'false'});
            const mobile = await browser.newContext({userAgent, viewport:{width:390,height:844}, hasTouch:true});
            const requests = {view:0, prepare:0, count:0};
            mobile.on('request', request => {
              const path = new URL(request.url()).pathname;
              if (path.endsWith('/track.php')) requests.view++;
              if (path.endsWith('/reach-prepare.php')) requests.prepare++;
              if (path.endsWith('/reach-count.php')) requests.count++;
            });
            try {
              if (mode === 'prepare') await mobile.route('**/reach-prepare.php', route => route.abort('failed'));
              await mobile.addInitScript(mode => {
                const storage = window.indexedDB; window.__storageReads = 0;
                Object.defineProperty(window, 'indexedDB', {get() {
                  window.__storageReads++;
                  if (mode === 'storage') throw new DOMException('Unavailable', 'SecurityError');
                  if (mode === 'missing-storage') return undefined;
                  return storage;
                }});
                window.BARELYTICS_REACH_PERMISSION = () => {
                  if (mode === 'throw') throw new Error('Denied');
                  if (mode === 'reject') return Promise.reject(new Error('Denied'));
                  return mode !== 'false';
                };
                if (mode === 'hash') Object.defineProperty(window.crypto, 'subtle', {value:undefined});
              }, mode);
              const page = await visit(mobile, '/chat_utili_inutili_dannose.html');
              const prepares = ['allowed','storage','missing-storage','hash','prepare'].includes(mode) ? 1 : 0;
              const counts = mode === 'allowed' ? 1 : 0;
              assert.deepEqual(requests, {view:1,prepare:prepares,count:counts});
              assert.equal(await page.evaluate(() => window.__storageReads), ['allowed','storage','missing-storage'].includes(mode) ? 1 : 0);
              assert.equal(views(),1); assert.equal(reports(),0); assert.equal(pageReports(),counts);
            } finally { await mobile.close(); }
          });
        }
      }
      await caseRun('Pageview-only script: ordinary UA and 204 do not imply Reach execution', async(context,attempts)=>{
        pageEnabled(true);await observeStorage(context);let posts=0,prepares=0;
        context.on('request',r=>{if(r.url().endsWith('/track.php'))posts++;if(r.url().endsWith('/reach-prepare.php'))prepares++;});
        await context.route('**/scripted',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><title>Synthetic scripted client</title>'}));
        const page=await visit(context,'/scripted');
        const statuses=await page.evaluate(async()=>{
          const result=[];
          for(let i=0;i<5;i++)result.push((await fetch('/barelytics/track.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({path:'/scripted'})})).status);
          return result;
        });
        assert.deepEqual(statuses,[204,204,204,204,204]);
        assert.equal(posts,5);assert.equal(prepares,0);assert.equal(attempts.length,0);
        assert.equal(await page.evaluate(()=>window.__storageReads),0);
        assert.equal(views(),5);assert.equal(reports(),0);assert.equal(pageReports(),0);
      });
      await caseRun('Cache: fresh tracker revalidates and central Page Reach needs no HTML opt-in', async (context, attempts) => {
        enabled(false); pageEnabled(true); await observeStorage(context);
        let posts=0, prepares=0;
        context.on('request', r => { if(r.url().endsWith('/track.php'))posts++; if(r.url().endsWith('/reach-prepare.php'))prepares++; });
        const page=await context.newPage(); const script=page.waitForResponse(r=>r.url().endsWith('/track.js'));
        await page.goto(base+'/cache-first',{waitUntil:'networkidle'});
        const response=await script; assert.equal(response.status(),200);
        assert.equal(response.headers()['cache-control'],'no-cache, must-revalidate');
        const etag=response.headers().etag;
        const conditional=await context.request.get(base+'/barelytics/track.js',{headers:{'If-None-Match':etag}});
        assert.equal(conditional.status(),304); assert.equal(conditional.headers()['cache-control'],'no-cache, must-revalidate');
        // Engines may re-fetch unconditionally; conditional support is checked above.
        const validation=page.waitForResponse(r=>r.url().endsWith('/track.js'));
        await page.goto(base+'/cache-second',{waitUntil:'networkidle'});
        assert.equal((await validation).headers()['cache-control'],'no-cache, must-revalidate');
        assert.equal(posts,2); assert.equal(prepares,2); assert.equal(attempts.length,2);
        assert.equal(await page.evaluate(()=>window.__storageReads),1);
        assert.equal(views(),2); assert.equal(reports(),0); assert.equal(pageReports(),2);
      });
      await caseRun('Cache: fresh legacy copy survives deployment until URL changes', async (context, attempts) => {
        enabled(false); pageEnabled(true); configure({tracker_legacy:true}); await observeStorage(context);
        let posts=0, prepares=0;
        context.on('request',r=>{if(r.url().endsWith('/track.php'))posts++;if(r.url().endsWith('/reach-prepare.php'))prepares++;});
        const first=await visit(context,'/legacy-first');
        assert.equal(await first.evaluate(()=>window.__storageReads),0);
        configure({}); // FTP replacement cannot change a still-fresh browser copy.
        await first.goto(base+'/legacy-second',{waitUntil:'networkidle'});
        assert.equal(await first.evaluate(()=>window.__storageReads),0);
        assert.equal(posts,2);assert.equal(prepares,0);assert.equal(attempts.length,0);
        assert.equal(views(),2);assert.equal(reports(),0);assert.equal(pageReports(),0);
        configure({tracker_version:true});
        await first.goto(base+'/legacy-remediated',{waitUntil:'networkidle'});
        assert.equal(await first.evaluate(()=>window.__storageReads),1);
        assert.equal(posts,3);assert.equal(prepares,1);assert.equal(attempts.length,1);
        assert.equal(views(),3);assert.equal(reports(),0);assert.equal(pageReports(),1);
      });
      await caseRun('Authorization: missing headers are ambiguous and prevent prepare', async(context,attempts)=>{
        pageEnabled(true);await observeStorage(context);let posts=0,prepares=0;
        context.on('request',r=>{if(r.url().endsWith('/track.php'))posts++;if(r.url().endsWith('/reach-prepare.php'))prepares++;});
        await context.route('**/track.php',async route=>{
          const response=await route.fetch();const headers=response.headers();
          assert.equal(headers['barelytics-page-reach-enabled'],'1');
          for(const key of Object.keys(headers))if(key.startsWith('barelytics-'))delete headers[key];
          await route.fulfill({response,headers});
        });
        const page=await visit(context);assert.equal(await page.evaluate(()=>window.__storageReads),0);
        assert.equal(posts,1);assert.equal(prepares,0);assert.equal(attempts.length,0);
        assert.equal(views(),1);assert.equal(reports(),0);assert.equal(pageReports(),0);
      });
      await caseRun('Page Reach: four views, one combined attempt and only local hashes', async (context, attempts) => {
        pageEnabled(true);const page=await visit(context,'/article-a');
        const initial=await localPages(page);
        for(let i=0;i<3;i++)await page.reload({waitUntil:'networkidle'});
        assert.equal(views(),4);assert.equal(reports(),1);assert.equal(pageReports(),1);assert.equal(attempts.length,1);
        assert.deepEqual(JSON.parse(attempts[0].postData()),{version:2,day:new Date().toISOString().slice(0,10),path:'/article-a',global:true,page:true});
        assert.deepEqual(await localPages(page),initial);assert.equal(initial.hashes.length,1);assert.match(initial.hashes[0],/^[a-f0-9]{64}$/);
        assert.ok(initial.expiresAt-Date.now()<=86400000 && initial.expiresAt>Date.now());
        const expected=await page.evaluate(async()=>[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(new Date().toISOString().slice(0,10)+'\n/article-a')))].map(n=>n.toString(16).padStart(2,'0')).join(''));
        assert.equal(initial.hashes[0],expected);assert.equal(JSON.stringify(initial).includes('/article-a'),false);
      });
      await caseRun('Page Reach: normalized IDs share a gate and excluded pages do not participate', async(context,attempts)=>{
        pageEnabled(true);let prepares=0;context.on('request',r=>{if(r.url().endsWith('/reach-prepare.php'))prepares++;});
        const page=await visit(context,'/article/123456');await page.goto(base+'/article/654321',{waitUntil:'networkidle'});await page.goto(base+'/private/person',{waitUntil:'networkidle'});
        assert.equal(views(),2);assert.equal(pageReports(),1);assert.equal(reports(),1);assert.equal(attempts.length,1);assert.equal(prepares,2);
        assert.equal(JSON.parse(attempts[0].postData()).path,'/article/:id');
        const expected=await page.evaluate(async()=>[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(new Date().toISOString().slice(0,10)+'\n/article/:id')))].map(n=>n.toString(16).padStart(2,'0')).join(''));
        assert.equal((await localPages(page)).hashes[0],expected);
      });
      await caseRun('Page Reach: multiple pages keep global reach independent', async (context, attempts) => {
        pageEnabled(true);const page=await visit(context,'/');
        for(const path of ['/article-a','/article-b','/'])await page.goto(base+path,{waitUntil:'networkidle'});
        assert.equal(views(),4);assert.equal(reports(),1);assert.equal(pageReports(),3);assert.equal(attempts.length,3);
        assert.equal(attempts.map(r=>JSON.parse(r.postData())).filter(r=>r.global).length,1);
        assert.equal((await localPages(page)).hashes.length,3);
      });
      for(const tabs of [2,10]) await caseRun(`Page Reach: ${tabs} tabs on the same page coordinate`, async(context,attempts)=>{
        pageEnabled(true);await Promise.all(Array.from({length:tabs},()=>visit(context,'/same-page')));
        assert.equal(views(),tabs);assert.equal(reports(),1);assert.equal(pageReports(),1);assert.equal(attempts.length,1);
      });
      await caseRun('Page Reach: concurrent different pages have independent claims', async(context,attempts)=>{
        pageEnabled(true);await Promise.all(Array.from({length:10},(_,i)=>visit(context,'/different-'+(i%5))));
        assert.equal(views(),10);assert.equal(reports(),1);assert.equal(pageReports(),5);assert.equal(attempts.length,5);
      });
      await caseRun('Page Reach: page-only enablement and normal HTML', async(context,attempts)=>{
        enabled(false);pageEnabled(true);const page=await visit(context);await page.reload({waitUntil:'networkidle'});
        assert.equal(views(),2);assert.equal(reports(),0);assert.equal(pageReports(),1);assert.equal(attempts.length,1);
        assert.equal(JSON.parse(attempts[0].postData()).global,false);
      });
      await caseRun('Page Reach: administration toggles feature without HTML changes', async(context,attempts)=>{
        enabled(false);const page=await visit(context);const admin=await context.newPage();
        await admin.goto(base+'/barelytics/admin.php',{waitUntil:'networkidle'});
        await admin.locator('[name=password]').fill('synthetic-browser-admin-password');await admin.getByRole('button',{name:'Sign in',exact:true}).click();
        await admin.goto(base+'/barelytics/admin.php#privacy',{waitUntil:'networkidle'});
        await admin.locator('[name=daily_page_reach_enabled]').check();await admin.locator('[name=confirmation]').check();
        await admin.getByRole('button',{name:'Save settings',exact:true}).click();
        await admin.waitForFunction(()=>document.querySelector('#privacy-result').textContent.includes('EXTENDED'));
        await page.reload({waitUntil:'networkidle'});assert.equal(pageReports(),1);assert.equal(reports(),0);
        for(const section of ['pages','daily']) {
          await admin.goto(base+'/barelytics/admin.php#'+section,{waitUntil:'networkidle'});
          assert.match(await admin.locator('#'+(section==='pages'?'pages-table':'daily-table')+' thead').innerText(),/Daily Page Reach reports/);
        }
        await admin.goto(base+'/barelytics/admin.php#page-detail?path=%2Farticle',{waitUntil:'networkidle'});
        await admin.waitForFunction(()=>document.querySelector('#page-result').textContent.includes('Daily Page Reach reports: 1'));
        assert.match(await admin.locator('#page-result').innerText(),/Daily Page Reach reports: 1/);
        await admin.goto(base+'/barelytics/admin.php#privacy',{waitUntil:'networkidle'});
        await admin.locator('[name=daily_page_reach_enabled]').uncheck();await admin.getByRole('button',{name:'Save settings',exact:true}).click();
        await admin.waitForFunction(()=>document.querySelector('#privacy-result').textContent.includes('STRICT'));
        await context.addInitScript(()=>{window.__reads=0;Object.defineProperty(window,'indexedDB',{get(){window.__reads++;throw new Error('Strict');}});});
        await page.reload({waitUntil:'networkidle'});assert.equal(await page.evaluate(()=>window.__reads),0);
        assert.equal(attempts.length,1);assert.equal(views(),3);
      });
      for(const fault of ['storage','hash','abort','quota','read']) await caseRun(`Page Reach: ${fault} failure closes page gate and keeps views`,async(context,attempts)=>{
        pageEnabled(true);const errors=[];context.on('page',page=>page.on('pageerror',error=>errors.push(error.message)));
        await context.addInitScript(fault=>{
          if(fault==='storage')Object.defineProperty(window,'indexedDB',{get(){throw new Error('Denied');}});
          if(fault==='hash')Object.defineProperty(window.crypto,'subtle',{value:undefined});
          if(fault==='abort'){const original=IDBDatabase.prototype.transaction;IDBDatabase.prototype.transaction=function(...args){const tx=original.apply(this,args);if(args[1]==='readwrite')tx.abort();return tx;};}
          if(fault==='read'){const original=IDBObjectStore.prototype.get;IDBObjectStore.prototype.get=function(key){if(key==='pages')throw new DOMException('Read unavailable','InvalidStateError');return original.call(this,key);};}
          if(fault==='quota')IDBObjectStore.prototype.put=function(){throw new DOMException('Full','QuotaExceededError');};
        },fault);
        await visit(context);await visit(context);assert.equal(views(),2);assert.equal(pageReports(),0);
        assert.equal(reports(),fault==='hash'?1:0);assert.equal(attempts.length,fault==='hash'?1:0);assert.deepEqual(errors,[]);
      });
      for(const mode of ['false','throw','reject']) await caseRun(`Page Reach: CMP ${mode} prevents hashing and storage`,async(context,attempts)=>{
        pageEnabled(true);await context.addInitScript(mode=>{
          window.__reads=0;window.__hashes=0;const digest=crypto.subtle.digest.bind(crypto.subtle);crypto.subtle.digest=(...args)=>{window.__hashes++;return digest(...args);};
          Object.defineProperty(window,'indexedDB',{get(){window.__reads++;throw new Error('Denied');}});
          window.BARELYTICS_REACH_PERMISSION=()=>{if(mode==='throw')throw new Error('Denied');return mode==='reject'?Promise.reject(new Error('Denied')):false;};
        },mode);
        const page=await visit(context);assert.equal(await page.evaluate(()=>window.__reads+window.__hashes),0);
        assert.equal(attempts.length,0);assert.equal(pageReports(),0);assert.equal(views(),1);
      });
      await caseRun('Page Reach: withdrawal after commit prevents send and explicit clear deletes all state',async(context,attempts)=>{
        pageEnabled(true);await context.addInitScript(()=>{let calls=0;window.BARELYTICS_REACH_PERMISSION=()=>++calls<4;});
        const page=await visit(context);assert.equal(attempts.length,0);assert.equal((await localPages(page)).hashes.length,1);
        assert.equal(await page.evaluate(()=>window.BarelyticsDailyReach.clear()),true);assert.equal(views(),1);
      });
      for(const delivered of [false,true]) await caseRun(`Page Reach: ambiguous count loss delivered=${delivered} is never retried`,async(context,attempts)=>{
        pageEnabled(true);await context.route('**/reach-count.php',async route=>{if(delivered)await route.fetch();await route.abort('failed');});
        const page=await visit(context);await page.reload({waitUntil:'networkidle'});
        assert.equal(attempts.length,1);assert.equal(pageReports(),delivered?1:0);assert.equal(reports(),delivered?1:0);assert.equal(views(),2);
      });
      await caseRun('Page Reach: server disablement suppresses only disabled component in flight',async(context,attempts)=>{
        pageEnabled(true);await context.route('**/reach-count.php',async route=>{pageEnabled(false);await route.continue();});
        await visit(context);assert.equal(pageReports(),0);assert.equal(reports(),1);assert.equal(attempts.length,1);
      });
      await caseRun('Page Reach: local cap preserves markers and fails closed for new pages',async(context,attempts)=>{
        pageEnabled(true);const page=await visit(context,'/first');
        await page.evaluate(()=>new Promise(done=>{const open=indexedDB.open('barelytics-daily-reach',1);open.onsuccess=()=>{const db=open.result,tx=db.transaction('state','readwrite'),store=tx.objectStore('state'),r=store.get('pages');r.onsuccess=()=>{const value=r.result;for(let i=1;i<512;i++)value.hashes.push(i.toString(16).padStart(64,'0'));store.put(value,'pages');};tx.oncomplete=()=>{db.close();done();};};}));
        await page.goto(base+'/beyond-cap',{waitUntil:'networkidle'});await page.goto(base+'/first',{waitUntil:'networkidle'});
        assert.equal(attempts.length,1);assert.equal(pageReports(),1);assert.equal(views(),3);assert.equal((await localPages(page)).hashes.length,512);
      });
      await caseRun('Page Reach: expiry purges hashes without duplicate same-period authorization',async(context,attempts)=>{
        pageEnabled(true);const page=await visit(context);
        await page.evaluate(()=>new Promise(done=>{const open=indexedDB.open('barelytics-daily-reach',1);open.onsuccess=()=>{const db=open.result,tx=db.transaction('state','readwrite'),store=tx.objectStore('state'),r=store.get('pages');r.onsuccess=()=>store.put({...r.result,expiresAt:Date.now()-1},'pages');tx.oncomplete=()=>{db.close();done();};};}));
        pageEnabled(false);await page.reload({waitUntil:'networkidle'});const local=await localPages(page);
        assert.deepEqual(local.hashes,[]);assert.equal(local.closed,true);
        pageEnabled(true);await page.reload({waitUntil:'networkidle'});assert.equal(attempts.length,1);assert.equal(pageReports(),1);
      });
      await caseRun('Page Reach: UTC rollover purges old hashes and allows the next day',async(context,attempts)=>{
        pageEnabled(true);configure({clock:'2026-11-03T23:59:59Z'});const page=await visit(context);const initial=await localPages(page);
        configure({clock:'2026-11-04T00:00:01Z'});await page.reload({waitUntil:'networkidle'});const next=await localPages(page);
        assert.equal(next.day,'2026-11-04');assert.equal(next.hashes.length,1);assert.notEqual(next.hashes[0],initial.hashes[0]);
        assert.equal(pageReports(),2);assert.equal(reports(),2);assert.equal(attempts.length,2);
      });
      await caseRun('Page Reach: clock advance during storage cannot extend prepared expiry',async(context,attempts)=>{
        enabled(false);pageEnabled(true);configure({clock:'2026-11-03T23:59:59Z'});
        await context.addInitScript(()=>{const original=indexedDB.open.bind(indexedDB);indexedDB.open=(...args)=>{const before=Date.now;Date.now=()=>before()+2000;return original(...args);};});
        const page=await visit(context);assert.equal(attempts.length,0);assert.equal(pageReports(),0);assert.equal(views(),1);
        assert.equal(await localPages(page),undefined);
      });
      await caseRun('Page Reach: expired prepare cannot create a page marker',async(context,attempts)=>{
        enabled(false);pageEnabled(true);configure({clock:'2026-11-03T23:59:59Z'});
        await context.route('**/reach-prepare.php',async route=>{const response=await route.fetch();await delay(1200);await route.fulfill({response});});
        await visit(context);assert.equal(attempts.length,0);assert.equal(pageReports(),0);assert.equal(views(),1);
      });
      await caseRun('Page Reach: delayed old prepare cannot resurrect old hashes',async(context,attempts)=>{
        pageEnabled(true);configure({clock:'2026-11-03T23:59:59Z'});
        let release, captured;const hold=new Promise(done=>release=done),ready=new Promise(done=>captured=done);let seen=0;
        await context.route('**/reach-prepare.php',async route=>{if(++seen===1){const response=await route.fetch();captured();await hold;await route.fulfill({response});}else await route.continue();});
        const first=await context.newPage();await first.goto(base+'/delayed',{waitUntil:'load'});await ready;
        configure({clock:'2026-11-04T00:00:01Z'});const next=await visit(context,'/delayed');release();await first.waitForLoadState('networkidle');
        assert.equal((await localPages(next)).day,'2026-11-04');assert.equal(pageReports(),1);assert.equal(reports(),1);assert.equal(attempts.length,1);
      });
      await caseRun('Page Reach: persistent profile retains independent claims after restart',async()=>{
        pageEnabled(true);const profile=resolve(directory,`page-profile-${name}`);let sends=0;
        for(let i=0;i<2;i++){const persistent=await engine.launchPersistentContext(profile,{...launch,userAgent:ordinaryUserAgent});persistent.on('request',r=>{if(r.url().endsWith('/reach-count.php'))sends++;});try{await visit(persistent,'/restart-page');}finally{await persistent.close();}}
        assert.equal(sends,1);assert.equal(pageReports(),1);assert.equal(reports(),1);assert.equal(views(),2);
      });
      await caseRun('normal script: four pageviews, one report and unchanged day marker', async (context, attempts) => {
        const page = await visit(context);
        assert.ok((await (await context.request.get(base+'/article')).text()).includes('<script defer src="/barelytics/track.js"></script>'));
        assert.equal(await page.locator('script').evaluate(node => node.outerHTML), '<script defer="" src="/barelytics/track.js"></script>');
        const first = await marker(page);
        for (let i = 0; i < 3; i++) {
          await page.reload({waitUntil:'networkidle'});
          assert.deepEqual(await marker(page), first);
        }
        assert.equal(views(), 4); assert.equal(reports(), 1); assert.equal(attempts.length, 1);
      });
      await caseRun('normal unchanged script follows administration enablement and Strict reset', async (context, attempts) => {
        enabled(false);
        await context.addInitScript(() => {
          const storage = window.indexedDB; window.__storageReads = 0;
          Object.defineProperty(window, 'indexedDB', {get() { window.__storageReads++; return storage; }});
        });
        const prepares = []; context.on('request', r => { if (r.url().endsWith('/reach-prepare.php')) prepares.push(r); });
        const page = await visit(context), html = await page.locator('script').evaluate(node => node.outerHTML);
        assert.equal(await page.evaluate(() => window.__storageReads), 0); assert.equal(prepares.length, 0);
        const admin = await context.newPage();
        await admin.goto(base+'/barelytics/admin.php', {waitUntil:'networkidle'});
        await admin.locator('[name=password]').fill('synthetic-browser-admin-password');
        await admin.getByRole('button', {name:'Sign in', exact:true}).click();
        await admin.goto(base+'/barelytics/admin.php#privacy', {waitUntil:'networkidle'});
        await admin.locator('[name=daily_reach_enabled]').check(); await admin.locator('[name=confirmation]').check();
        await admin.getByRole('button', {name:'Save settings', exact:true}).click();
        await admin.waitForFunction(() => document.querySelector('#notice').textContent === 'Privacy settings saved.');
        await page.reload({waitUntil:'networkidle'});
        assert.equal(reports(), 1); assert.equal(attempts.length, 1); assert.equal(prepares.length, 1);
        assert.ok(await page.evaluate(() => window.__storageReads) > 0);
        admin.once('dialog', dialog => dialog.accept());
        await admin.getByRole('button', {name:'Return to Strict Mode', exact:true}).click();
        await admin.waitForFunction(() => document.querySelector('#privacy-result').textContent.includes('STRICT'));
        await page.reload({waitUntil:'networkidle'});
        assert.equal(await page.locator('script').evaluate(node => node.outerHTML), html);
        assert.equal(await page.evaluate(() => window.__storageReads), 0);
        assert.equal(prepares.length, 1); assert.equal(attempts.length, 1); assert.equal(views(), 3);
        // Cleanup remains callable after Strict, but defining it did not access storage.
        assert.equal(await page.evaluate(() => window.BarelyticsDailyReach.clear()), true);
      });
      for (const attribute of ['true', 'false']) await caseRun(`legacy explicit ${attribute} attribute`, async (context, attempts) => {
        configure({reach_attribute:attribute}); let prepares = 0;
        context.on('request', r => { if (r.url().endsWith('/reach-prepare.php')) prepares++; });
        if (attribute === 'false') await context.addInitScript(() => { window.__storageReads=0; Object.defineProperty(window, 'indexedDB', {get() { window.__storageReads++; throw new Error('Opt-out storage accessed'); }}); });
        const first=await visit(context), second=await visit(context);
        if (attribute === 'false') for (const page of [first,second]) assert.equal(await page.evaluate(() => window.__storageReads), 0);
        assert.equal(views(), 2); assert.equal(reports(), attribute === 'true' ? 1 : 0);
        assert.equal(attempts.length, attribute === 'true' ? 1 : 0); assert.equal(prepares, attribute === 'true' ? 2 : 0);
      });
      await caseRun('single visit, sequential navigation and one day-only local record', async (context, attempts) => {
        await context.addCookies([{name:'application_session',value:'synthetic',url:base}]);
        const prepares=[];context.on('request',r=>{if(r.url().endsWith('/reach-prepare.php'))prepares.push(r);});
        const page=await visit(context,'/article/123456'); await page.goto(base+'/another',{waitUntil:'networkidle'});
        assert.equal(attempts.length,1); assert.equal(reports(),1); assert.equal(views(),2);
        assert.deepEqual(JSON.parse(attempts[0].postData()), {day:new Date().toISOString().slice(0,10),path:'/article/:id'});
        assert.equal(JSON.parse(prepares[0].postData()).path,'/article/:id');
        const headers=await attempts[0].allHeaders(); assert.equal(headers.cookie,undefined); assert.equal(headers.referer,undefined);
        const stored=await page.evaluate(() => new Promise((resolveValue,reject) => {
          const request=indexedDB.open('barelytics-daily-reach',1); request.onerror=reject;
          request.onsuccess=()=>{const db=request.result; const tx=db.transaction('state','readonly'); const store=tx.objectStore('state'); const values=store.getAll(); const keys=store.getAllKeys(); tx.oncomplete=()=>{resolveValue({stores:[...db.objectStoreNames],values:values.result,keys:keys.result});db.close();};};
        }));
        assert.deepEqual(stored,{stores:['state'],values:[{day:new Date().toISOString().slice(0,10)}],keys:['day']});
      });
      await caseRun('administration shows the metric, limitations and retained history after Strict reset', async (context, attempts) => {
        const page=await visit(context);assert.equal(attempts.length,1);
        await page.goto(base+'/barelytics/admin.php',{waitUntil:'networkidle'});
        await page.locator('input[name="password"]').fill('synthetic-browser-admin-password');
        await page.getByRole('button',{name:'Sign in',exact:true}).click();
        await page.goto(base+'/barelytics/admin.php?ui=1',{waitUntil:'networkidle'});
        await page.locator('.section-nav a').filter({hasText:'Daily Reach'}).click();
        await page.waitForFunction(()=>document.querySelector('#reach-metrics strong')?.textContent==='1');
        assert.equal(await page.locator('[name="daily_reach_enabled"]').isChecked(),true);
        assert.match(await page.locator('#reach').innerText(),/not an exact count of people or all unique visitors/);
        await page.locator('.section-nav a').filter({hasText:'Privacy settings'}).click();
        page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Return to Strict Mode',exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('#privacy-result')?.textContent.includes('STRICT'));
        await page.locator('.section-nav a').filter({hasText:'Daily Reach'}).click();
        await page.waitForFunction(()=>document.querySelector('#reach-state')?.textContent.includes('Collection is disabled'));
        assert.equal(await page.locator('#reach-metrics strong').textContent(),'1');
        assert.equal(await page.locator('[name="daily_reach_enabled"]').isChecked(),false);
        if(process.env.REACH_SCREENSHOT_DIRECTORY)await page.locator('#reach').screenshot({path:resolve(process.env.REACH_SCREENSHOT_DIRECTORY,`barelytics-reach-${name}.png`)});
      });
      for (const tabs of [2,10]) await caseRun(`${tabs} concurrent tabs/windows and navigation`, async (context, attempts) => {
        const pages=await Promise.all(Array.from({length:tabs},(_,i)=>visit(context,`/parallel-${i}`)));
        await Promise.all(pages.map((page,i)=>page.goto(base+`/navigation-${i}`,{waitUntil:'networkidle'})));
        assert.equal(attempts.length,1); assert.equal(reports(),1); assert.equal(views(),tabs*2);
      });
      await caseRun('Strict default and server disablement never access IndexedDB or reach', async (context, attempts) => {
        await context.addInitScript(()=>{window.__storageReads=0;Object.defineProperty(window,'indexedDB',{get(){window.__storageReads++;throw new Error('Strict storage accessed');}});});
        let prepares=0;context.on('request',r=>{if(r.url().endsWith('reach-prepare.php'))prepares++;});
        enabled(false); const first=await visit(context);
        assert.equal(await first.evaluate(()=>window.__storageReads),0); assert.equal(attempts.length,0);
        assert.equal(prepares,0); configure({reach_attribute:'true'});
        const second=await visit(context); assert.equal(await second.evaluate(()=>window.__storageReads),0);
        assert.equal(prepares,0);assert.equal(attempts.length,0);assert.equal(views(),2);
      });
      await caseRun('unavailable storage sends no reach and page views continue', async (context, attempts) => {
        await context.addInitScript(()=>Object.defineProperty(window,'indexedDB',{get(){throw new DOMException('Unavailable','SecurityError');}}));
        await visit(context);await visit(context);assert.equal(attempts.length,0);assert.equal(reports(),0);assert.equal(views(),2);
      });
      await caseRun('aborted readwrite transaction sends no reach', async (context, attempts) => {
        await context.addInitScript(()=>{const original=IDBDatabase.prototype.transaction;IDBDatabase.prototype.transaction=function(...args){const tx=original.apply(this,args);if(args[1]==='readwrite')tx.abort();return tx;};});
        await visit(context);assert.equal(attempts.length,0);assert.equal(reports(),0);assert.equal(views(),1);
      });
      await caseRun('write failure sends no report and raises no host page error', async (context, attempts) => {
        await context.addInitScript(()=>{IDBObjectStore.prototype.put=function(){throw new DOMException('Storage full','QuotaExceededError');};});
        const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error));
        await page.goto(base+'/article',{waitUntil:'networkidle'});
        assert.equal(attempts.length,0);assert.equal(reports(),0);assert.equal(views(),1);assert.deepEqual(errors,[]);
      });
      for (const mode of ['false', 'throw', 'reject']) await caseRun(`host/CMP ${mode} denies permission before prepare and storage`, async (context, attempts) => {
        let prepares=0;context.on('request',r=>{if(r.url().endsWith('reach-prepare.php'))prepares++;});
        await context.addInitScript(mode=>{window.__storageReads=0;window.BARELYTICS_REACH_PERMISSION=()=>{if(mode==='throw')throw new Error('Denied');return mode==='reject'?Promise.reject(new Error('Denied')):false;};Object.defineProperty(window,'indexedDB',{get(){window.__storageReads++;throw new Error('Denied storage accessed');}});},mode);
        const page=await visit(context);assert.equal(await page.evaluate(()=>window.__storageReads),0);assert.equal(prepares,0);assert.equal(attempts.length,0);assert.equal(views(),1);
      });
      await caseRun('missing fetch falls back to one path-only beacon without reach', async (context, attempts) => {
        await context.addInitScript(() => { window.fetch = undefined; Object.defineProperty(window,'indexedDB',{get(){throw new Error('Fallback storage accessed');}}); });
        let prepares = 0; context.on('request', r => { if (r.url().endsWith('/reach-prepare.php')) prepares++; });
        await visit(context); assert.equal(views(), 1); assert.equal(reports(), 0); assert.equal(attempts.length, 0); assert.equal(prepares, 0);
      });
      await caseRun('CSP policy behavior never triggers a beacon fallback', async (context, attempts) => {
        configure({block_connections:true});
        await context.addInitScript(() => { window.__beacons=0; navigator.sendBeacon=()=>{window.__beacons++;return true;}; });
        const page=await visit(context); assert.equal(await page.evaluate(()=>window.__beacons),0);
        // The pinned Firefox has Mozilla bug 2012984: keepalive bypasses CSP.
        // Report its actual behavior; do not claim CSP enforcement passed there.
        const affected = name === 'firefox' && browser.version() === '146.0.1';
        if (affected) console.log('  LIMIT Firefox 146.0.1: keepalive CSP enforcement unavailable (Mozilla 2012984)');
        assert.equal(views(), affected ? 1 : 0); assert.equal(reports(), affected ? 1 : 0);
        assert.equal(attempts.length, affected ? 1 : 0);
      });
      for (const delivered of [false, true]) await caseRun(`pageview network failure after dispatch, delivered=${delivered}, never retries`, async (context, attempts) => {
        let posts = 0;
        await context.route('**/track.php', async route => { posts++; if (delivered) await route.fetch(); await route.abort('failed'); });
        await visit(context); assert.equal(posts, 1); assert.equal(views(), delivered ? 1 : 0);
        assert.equal(attempts.length, 0); assert.equal(reports(), 0);
      });
      for (const action of ['reload', 'navigate', 'close']) await caseRun(`keepalive pageviews survive fast ${action}`, async (context) => {
        enabled(false); configure({collector_delay_ms:250});
        const page = await context.newPage(); const dispatched = page.waitForRequest(r => r.url().endsWith('/track.php'));
        await page.goto(base+'/fast-first', {waitUntil:'load'}); await dispatched;
        if (action === 'close') await page.close();
        else if (action === 'reload') await page.reload({waitUntil:'networkidle'});
        else await page.goto(base+'/fast-next', {waitUntil:'networkidle'});
        for (let i = 0; i < 50 && views() < (action === 'close' ? 1 : 2); i++) await delay(100);
        assert.equal(views(), action === 'close' ? 1 : 2); assert.equal(reports(), 0);
      });
      await caseRun('protocol v2 rejection preserves one view and centralized reach', async (context, attempts) => {
        // Inject only the independent referrer attribute; Daily Reach has no opt-in.
        await context.route('**/article', async route => {
          const response = await route.fetch();
          await route.fulfill({response, body:(await response.text()).replace('src="/barelytics/track.js"', 'src="/barelytics/track.js" data-referrer="hostname"')});
        });
        const posts = []; context.on('request', r => { if (r.url().endsWith('/track.php')) posts.push(JSON.parse(r.postData())); });
        await visit(context); assert.equal(posts.length, 2); assert.equal(posts[0].version, 2); assert.deepEqual(posts[1], {path:'/article'});
        assert.equal(views(), 1); assert.equal(reports(), 1); assert.equal(attempts.length, 1);
      });
      await caseRun('lost network attempt is not retried after committed marker', async (context, attempts) => {
        await context.route('**/reach-count.php',route=>route.abort('failed'));
        await visit(context);await visit(context);assert.equal(attempts.length,1);assert.equal(reports(),0);assert.equal(views(),2);
      });
      await caseRun('lost response after SQL increment is not retried', async (context, attempts) => {
        await context.route('**/reach-count.php',async route=>{await route.fetch();await route.abort('failed');});
        await visit(context);await visit(context);assert.equal(attempts.length,1);assert.equal(reports(),1);
      });
      await caseRun('excluded and automated pages make no reach requests', async (context, attempts) => {
        let prepares=0;context.on('request',r=>{if(r.url().endsWith('reach-prepare.php'))prepares++;});
        await visit(context,'/private/secret');assert.equal(prepares,0);assert.equal(attempts.length,0);
        const bot=await browser.newContext();try{await bot.route('**/track.php',async route=>route.continue({headers:{...await route.request().allHeaders(),'user-agent':'GPTBot/1'}}));await visit(bot);}finally{await bot.close();}
        assert.equal(reports(),0);assert.equal(views(),0);
      });
      await caseRun('storage deletion permits another observation; explicit cleanup works', async (context, attempts) => {
        const page=await visit(context);assert.equal(await page.evaluate(()=>window.BarelyticsDailyReach.clear()),true);
        await page.reload({waitUntil:'networkidle'});assert.equal(attempts.length,2);assert.equal(reports(),2);
      });
      await caseRun('missing transaction cannot authorize a send', async (context, attempts) => {
        await context.addInitScript(()=>{IDBDatabase.prototype.transaction=function(){throw new Error('Transaction unavailable');};});
        await visit(context);assert.equal(attempts.length,0);assert.equal(reports(),0);assert.equal(views(),1);
      });
      await caseRun('permission withdrawal after commit prevents the send', async (context, attempts) => {
        await context.addInitScript(()=>{let calls=0;window.BARELYTICS_REACH_PERMISSION=async()=>++calls<3;});
        await visit(context);await visit(context);assert.equal(attempts.length,0);assert.equal(reports(),0);assert.equal(views(),2);
      });
      await caseRun('server disablement rejects an in-flight committed claim', async (context, attempts) => {
        await context.route('**/reach-count.php',async route=>{enabled(false);await route.continue();});
        const page=await context.newPage();const response=page.waitForResponse(r=>r.url().endsWith('/reach-count.php'));
        await page.goto(base+'/article',{waitUntil:'networkidle'});assert.equal((await response).status(),403);
        await page.reload({waitUntil:'networkidle'});assert.equal(attempts.length,1);assert.equal(reports(),0);assert.equal(views(),2);
      });
      await caseRun('popup windows in one profile share the gate', async (context, attempts) => {
        const page=await visit(context);const popup=context.waitForEvent('page');
        await page.evaluate(()=>window.open('/another-window','_blank'));
        const windowPage=await popup;await windowPage.waitForLoadState('networkidle');
        assert.equal(attempts.length,1);assert.equal(reports(),1);assert.equal(views(),2);
      });
      await caseRun('private contexts each have independent local gates', async (context, attempts) => {
        await visit(context);const second=await browser.newContext({userAgent:ordinaryUserAgent});try{await visit(second);await visit(second);}finally{await second.close();}
        assert.equal(attempts.length,1);assert.equal(reports(),2);
      });
      await caseRun('authoritative next UTC day replaces marker despite client clock', async (context, attempts) => {
        configure({clock:'2026-11-03T23:59:59Z'});const page=await visit(context);
        configure({clock:'2026-11-04T00:00:01Z'});await page.reload({waitUntil:'networkidle'});
        assert.deepEqual(attempts.map(r=>JSON.parse(r.postData()).day),['2026-11-03','2026-11-04']);assert.equal(reports(),2);
      });
      for(const [seconds,status] of [[119,204],[120,400]]) await caseRun(`late count at boundary +${seconds}s preserves day or rejects`, async (context, attempts) => {
        configure({clock:'2026-11-03T23:59:59Z'});
        await context.route('**/reach-count.php',async route=>{configure({clock:`2026-11-04T00:0${Math.floor(seconds/60)}:${String(seconds%60).padStart(2,'0')}Z`});await route.continue();});
        const page=await context.newPage();const observed=page.waitForResponse(r=>r.url().endsWith('/reach-count.php'));
        await page.goto(base+'/article',{waitUntil:'networkidle'});assert.equal((await observed).status(),status);assert.equal(attempts.length,1);assert.equal(reports(),status===204?1:0);
        if(status===204)assert.equal(php("echo \\Barelytics\\queryColumn(\\Barelytics\\connectDatabase(),'SELECT day FROM daily_reach');"),'2026-11-03');
      });
    } finally { await browser.close(); }
    // A persistent profile survives an actual browser close and relaunch.
    configure({});enabled(true);pageEnabled(false);reset();const profile=resolve(directory,`profile-${name}`);let attempts=0;
    for(let restart=0;restart<2;restart++){
      const context=await engine.launchPersistentContext(profile,{...launch,userAgent:ordinaryUserAgent});
      context.on('request',r=>{if(r.url().endsWith('/reach-count.php'))attempts++;});
      try{const page=await context.newPage();await page.goto(base+'/restart',{waitUntil:'networkidle'});}finally{await context.close();}
    }
    assert.equal(attempts,1);assert.equal(reports(),1);passed++;console.log('  PASS persistent profile across browser restart');
  }
  console.log(`Passed ${passed} real-browser Daily Reach scenarios.`);
} catch(error) { console.error(serverLog); throw error; }
finally { server.kill('SIGTERM');await new Promise(resolveExit=>server.once('exit',resolveExit));rmSync(directory,{recursive:true,force:true}); }
