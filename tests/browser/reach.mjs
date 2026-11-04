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
let state = { optin: true };
const configure = values => { state = { optin: true, ...values }; writeFileSync(control, JSON.stringify(state)); };
const php = code => execFileSync('php', ['-r', `require $argv[1]; ${code}`, resolve(root, 'public/barelytics/src/Barelytics.php')], { env: environment, encoding: 'utf8' });
const enabled = value => php(`$db=\\Barelytics\\openDatabase(); \\Barelytics\\saveDailyReachSetting($db, ${value ? 'true' : 'false'}, true);`);
const reset = () => php("$db=\\Barelytics\\connectDatabase(); $db->exec('DELETE FROM daily_reach'); $db->exec('DELETE FROM pageviews_daily');");
const reports = () => Number(php("echo \\Barelytics\\queryColumn(\\Barelytics\\connectDatabase(), 'SELECT COALESCE(SUM(reports),0) FROM daily_reach');"));
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
      configure({}); enabled(true); reset();
      const context = await browser.newContext({userAgent:ordinaryUserAgent}); const attempts = [];
      context.on('request', request => { if (new URL(request.url()).pathname.endsWith('/reach-count.php')) attempts.push(request); });
      try { await run(context, attempts); passed++; console.log(`  PASS ${label}`); }
      finally { await context.close(); }
    };
    try {
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
        configure({optin:false}); const first=await visit(context);
        assert.equal(await first.evaluate(()=>window.__storageReads),0); assert.equal(attempts.length,0);
        enabled(false); configure({optin:true}); let prepares=0;context.on('request',r=>{if(r.url().endsWith('reach-prepare.php'))prepares++;});
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
      await caseRun('host/CMP denies permission before prepare and storage', async (context, attempts) => {
        let prepares=0;context.on('request',r=>{if(r.url().endsWith('reach-prepare.php'))prepares++;});
        await context.addInitScript(()=>{window.BARELYTICS_REACH_PERMISSION=async()=>false;Object.defineProperty(window,'indexedDB',{get(){throw new Error('Denied storage accessed');}});});
        await visit(context);assert.equal(prepares,0);assert.equal(attempts.length,0);assert.equal(views(),1);
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
    configure({});enabled(true);reset();const profile=resolve(directory,`profile-${name}`);let attempts=0;
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
