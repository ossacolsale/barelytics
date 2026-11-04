import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, symlinkSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { chromium, firefox, webkit } from 'playwright';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const directory = mkdtempSync(resolve(tmpdir(), 'barelytics-admin-'));
const screenshots = process.env.ADMIN_SCREENSHOTS || '/tmp/barelytics-admin-screenshots';
mkdirSync(screenshots, {recursive:true});
mkdirSync(resolve(directory, 'www/subdirectory'), {recursive:true});
for (const prefix of ['', 'subdirectory/']) symlinkSync(process.env.ADMIN_SOURCE || resolve(root, 'public/barelytics'), resolve(directory, 'www', prefix, 'barelytics'));
const env = {...process.env, BARELYTICS_DATABASE_PATH:resolve(directory,'analytics.sqlite')};
execFileSync('php',[resolve(root,'tests/browser/admin-fixture.php')],{env});
const port = await new Promise(done => {const s=createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>done(p));});});
const base=`http://127.0.0.1:${port}`;
const server=spawn('php',['-S',`127.0.0.1:${port}`,'-t',resolve(directory,'www')],{env,stdio:'ignore'});
const engines={chromium,firefox,webkit};
try {
  for(let i=0;i<50;i++){try{await fetch(base);break;}catch{}await new Promise(r=>setTimeout(r,100));}
  for(const name of (process.env.ADMIN_BROWSERS || process.env.REACH_BROWSERS || 'chromium,firefox,webkit').split(',')) {
    const browser=await engines[name].launch({headless:true,...(name==='chromium' && process.env.REACH_CHROME_EXECUTABLE?{executablePath:process.env.REACH_CHROME_EXECUTABLE}:{})});
    try {
      const context=await browser.newContext();const page=await context.newPage();const errors=[];const assets=[];const consoleErrors=[];let expectedNetworkFailure=false;
      await page.route('**/favicon.ico',route=>route.fulfill({status:204}));
      page.on('console',message=>{if(message.type()==='error' && !expectedNetworkFailure)consoleErrors.push(message.text());});
      page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.url().includes('.css'))assets.push({url:r.url(),status:r.status(),type:r.headers()['content-type']});});
      for(const prefix of ['/barelytics/','/subdirectory/barelytics/']) {
        await page.goto(base+prefix+'admin.php');
        if(await page.locator('[name=password]').count()){await page.locator('[name=password]').fill('synthetic-browser-admin-password');await page.getByRole('button',{name:'Sign in',exact:true}).click();}
        if(process.env.ADMIN_BASELINE){
          for(const target of ['admin.php','admin.php?view=pages','admin.php?view=daily','admin.php?view=dimensions','admin.php?ui=1','account.php','audit.php','diagnostics.php']){
            await page.goto(base+prefix+target,{waitUntil:'networkidle'});
            const evidence=await page.evaluate(()=>({url:location.href,base:document.baseURI,css:[...document.styleSheets].map(s=>s.href),font:getComputedStyle(document.body).font,overflow:document.documentElement.scrollWidth>innerWidth,nav:!!document.querySelector('nav')}));
            console.log(JSON.stringify(evidence));await page.screenshot({path:resolve(screenshots,'before-'+prefix.replaceAll('/','_')+target.replaceAll(/[^a-z]/g,'_')+'.png'),fullPage:true});
          }
          continue;
        }
        const requests=[];page.on('request',r=>{if(r.url().includes('?api='))requests.push(new URL(r.url()).searchParams.get('api'));});
        await page.goto(base+prefix+'admin.php',{waitUntil:'networkidle'});
        await page.waitForSelector('#metrics strong');
        assert.deepEqual([...new Set(requests)].sort(),['dashboard','privacy','session']);
        const check = async (section,width) => {
          await page.setViewportSize({width,height:1000});
          await page.goto(base+prefix+'admin.php#'+section+'?period=365&bucket=day',{waitUntil:'networkidle'});
          await page.waitForFunction(()=>document.querySelector('#loading')?.hidden===true);
          assert.equal(await page.locator('#'+section).isVisible(),true);
          assert.equal(await page.locator('.section-nav a[aria-current=page]').count(),1);
          assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`${section} overflow at ${width}`);
          const escaping=await page.locator('#'+section+' .card, #'+section+' .metric, #'+section+' .table-wrap').evaluateAll(nodes=>nodes.filter(n=>!n.closest('details:not([open])') && n.getBoundingClientRect().right>innerWidth+1).map(n=>({class:n.className,right:n.getBoundingClientRect().right,width:innerWidth})));
          if(escaping.length) await page.screenshot({path:resolve(screenshots,`${name}-overflow-${width}.png`),fullPage:true});
          assert.deepEqual(escaping,[],`${section}: escaping component at ${width}`);
          await page.screenshot({path:resolve(screenshots,`${name}-${prefix.includes('subdirectory')?'nested':'root'}-${section}-${width}.png`),fullPage:true});
        };
        for(const width of [1440,1280,1024,768,390,320]) await check('overview',width);
        for(const section of ['pages','daily','dimensions','automation','reach','privacy','maintenance','audit','integration']) for(const width of [1440,390,320]) await check(section,width);
        await page.setViewportSize({width:1280,height:900});
        await page.goto(base+prefix+'admin.php?view=pages&period=90',{waitUntil:'networkidle'});
        assert.equal(await page.locator('#page-title').textContent(),'Pages');
        assert.equal(await page.locator('#period').inputValue(),'90');
        assert.equal(await page.locator('[data-page=pages][data-step="-1"]').isDisabled(),true);
        await page.locator('[data-page=pages][data-step="1"]').click();await page.waitForFunction(()=>document.querySelector('#pages-page').textContent.includes('Page 2'));
        assert.equal(await page.locator('[data-page=pages][data-step="1"]').isDisabled(),true);
        await page.locator('[data-page=pages][data-step="-1"]').click();await page.waitForFunction(()=>document.querySelector('#pages-page').textContent.includes('Page 1'));
        const path=await page.locator('#pages-table a').first().textContent();await page.locator('#pages-table a').first().click();
        await page.waitForFunction(()=>document.querySelector('#selected-path').textContent.length>0);
        assert.equal(await page.locator('#page-detail').isVisible(),true);assert.equal(await page.locator('#selected-path').textContent(),path);
        assert.equal(await page.locator('#period').inputValue(),'90');
        assert.equal(await page.evaluate(()=>document.activeElement.id),'page-title');
        await page.screenshot({path:resolve(screenshots,`${name}-page-detail-desktop.png`),fullPage:true});
        await page.setViewportSize({width:320,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
        await page.screenshot({path:resolve(screenshots,`${name}-page-detail-mobile.png`),fullPage:true});
        await page.goBack({waitUntil:'networkidle'});assert.equal(await page.locator('#pages').isVisible(),true);
        await page.goForward({waitUntil:'networkidle'});assert.equal(await page.locator('#page-detail').isVisible(),true);
        for(const target of ['admin.php?view=daily','admin.php?view=dimensions','admin.php?ui=1']){await page.goto(base+prefix+target,{waitUntil:'networkidle'});assert.equal(await page.locator('.sidebar').count(),1);}
        for(const bucket of ['day','week','month']) {
          await page.goto(base+prefix+'admin.php#overview?period=365&bucket='+bucket,{waitUntil:'networkidle'});
          const dates=await page.locator('#timeline tbody tr td:first-child').allTextContents();assert.ok(dates.length>1);assert.deepEqual(dates,[...dates].sort().reverse());
          if(bucket!=='day')assert.ok(dates.some(d=>d.startsWith(String(new Date().getUTCFullYear()-1))),'includes year boundary');
        }
        for(const section of ['automation','reach','daily']){
          await page.goto(base+prefix+'admin.php#'+section+'?period=365',{waitUntil:'networkidle'});
          const container=section==='automation'?'automation-timeline':section==='reach'?'reach-timeline':'daily-table';
          const dates=await page.locator('#'+container+' tbody tr td:first-child').allTextContents();assert.deepEqual(dates,[...dates].sort().reverse());
        }
        await page.goto(base+prefix+'admin.php#privacy',{waitUntil:'networkidle'});
        await page.locator('[name=path_exclusions]').fill('/unsaved-test');
        await page.evaluate(()=>{location.hash='overview';});await page.waitForTimeout(200);
        await page.evaluate(()=>{location.hash='privacy';});await page.waitForTimeout(200);
        assert.equal(await page.locator('[name=path_exclusions]').inputValue(),'/unsaved-test');
        for(const target of ['account.php','audit.php','diagnostics.php'])for(const width of [1440,390,320]){
          await page.setViewportSize({width,height:900});await page.goto(base+prefix+target,{waitUntil:'networkidle'});
          assert.equal(await page.locator('nav').count(),1);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,target+' overflow');
          await page.screenshot({path:resolve(screenshots,`${name}-${prefix.includes('subdirectory')?'nested':'root'}-${target}-${width}.png`),fullPage:true});
        }
        const audit=await (await context.request.get(base+prefix+'audit.php?format=json')).json();
        assert.notEqual(audit.result,'FAIL');assert.equal(audit.tracker_source_capabilities.indexeddb,true);
        const csrf=(await (await context.request.get(base+prefix+'admin.php?api=session')).json()).data.csrf;
        const forbidden=await context.request.post(base+prefix+'admin.php?api=delete-all',{data:{confirmation:'DELETE'}});assert.equal(forbidden.status(),403);
        const strict=await context.request.post(base+prefix+'admin.php?api=strict',{data:{csrf}});assert.equal(strict.status(),200);
        const strictAudit=await (await context.request.get(base+prefix+'audit.php?format=json')).json();assert.notEqual(strictAudit.result,'FAIL');assert.equal(strictAudit.effective_configuration.indexeddb,false);
        await page.goto(base+prefix+'admin.php#dimensions',{waitUntil:'networkidle'});assert.match(await page.locator('#dimension-result').textContent(),/collection is disabled/);
        await page.goto(base+prefix+'admin.php#reach',{waitUntil:'networkidle'});await page.waitForFunction(()=>document.querySelector('#reach-state').textContent.length>0);assert.match(await page.locator('#reach-state').textContent(),/Collection is disabled/);assert.ok(await page.locator('#reach-metrics strong').textContent());
        await page.goto(base+prefix+'admin.php#privacy',{waitUntil:'networkidle'});
        await page.locator('[name=retention_days]').selectOption('365');await page.locator('[name=daily_reach_enabled]').check();await page.locator('[name=country_collection]').check();await page.locator('[name=confirmation]').check();await page.getByRole('button',{name:'Save settings',exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('#notice').textContent==='Privacy settings saved.');
        assert.deepEqual(consoleErrors,[]);
        // Failed reports stay local and can be retried; the shell remains available.
        expectedNetworkFailure=true;await page.route('**/*api=dashboard*',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,error:{message:'Synthetic report unavailable'}})}));
        await page.goto(base+prefix+'admin.php',{waitUntil:'networkidle'});assert.equal(await page.locator('#retry').isVisible(),true);assert.equal(await page.locator('.sidebar').isVisible(),true);
        await page.unroute('**/*api=dashboard*');await page.locator('#retry').click();await page.waitForFunction(()=>document.querySelector('#metrics strong'));expectedNetworkFailure=false;
        // Rapid changes cannot paint an older response over the final period.
        await page.route('**/*api=dashboard*period=7*',async route=>{await new Promise(r=>setTimeout(r,200));await route.continue().catch(()=>{});});
        const pending=page.waitForRequest(r=>r.url().includes('api=dashboard') && new URL(r.url()).searchParams.get('period')==='7');
        await page.locator('#period').selectOption('7');await pending;await page.locator('#period').selectOption('90');await page.waitForFunction(()=>document.querySelector('#metrics .metric span:last-child')?.textContent==='90 day period');
        await page.waitForTimeout(250);assert.equal(await page.locator('#metrics .metric span:last-child').first().textContent(),'90 day period');await page.unroute('**/*api=dashboard*period=7*');
        // Keyboard affordances and text resize retain an inspectable layout.
        await page.keyboard.press('Tab');assert.ok(await page.evaluate(()=>document.activeElement!==document.body));
        for(const scale of [2,4]) { await page.evaluate(scale=>document.documentElement.style.fontSize=`${16*scale}px`,scale);if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)) {console.log(await page.locator('body *').evaluateAll(nodes=>nodes.filter(n=>n.getBoundingClientRect().right>innerWidth+1 && !n.closest('[hidden],details:not([open])')).map(n=>({tag:n.tagName,id:n.id,class:n.className,right:n.getBoundingClientRect().right})).slice(0,12)));await page.screenshot({path:resolve(screenshots,`${name}-text-resize-${scale}.png`),fullPage:true});}assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`text resize ${scale}`); }
        await page.evaluate(()=>document.documentElement.style.fontSize='');
        // External PHP tools carry the current report context explicitly.
        await page.setViewportSize({width:1440,height:900});await page.goto(base+prefix+'admin.php#pages?period=90&bucket=day&page=2',{waitUntil:'networkidle'});
        const source=page.url();await page.locator('#account-links a').click();await page.waitForLoadState('networkidle');
        assert.equal(await page.locator('#return-link').getAttribute('href'),source);await page.locator('#return-link').click();await page.waitForLoadState('networkidle');assert.match(page.url(),/pages\?period=90/);
        const nojs=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});try{const fallback=await nojs.newPage();await fallback.goto(base+prefix+'admin.php?fallback=1');assert.match(await fallback.locator('main').textContent(),/Page views, last 30 UTC days/);}finally{await nojs.close();}
        assert.equal(assets.every(a=>a.status===200 && a.type.includes('text/css')),true);assert.deepEqual(errors,[]);
        const anonymous=await browser.newContext();try{const denied=await anonymous.request.get(base+prefix+'admin.php?api=dashboard');assert.equal(denied.status(),403);}finally{await anonymous.close();}
        console.log(`${name}: navigation, tables, history, 6 viewports, privacy, audit, CSRF and assets passed (${prefix})`);
      }
      if(!process.env.ADMIN_BASELINE) {
        const csrf=(await (await context.request.get(base+'/barelytics/admin.php?api=session')).json()).data.csrf;
        const result=await context.request.post(base+'/barelytics/admin.php?api=delete-all',{data:{csrf,confirmation:'DELETE'}});assert.equal(result.status(),200);
        for(const section of ['overview','pages','daily','automation','reach']){await page.goto(base+'/barelytics/admin.php?empty='+section+'#'+section,{waitUntil:'networkidle'});await page.waitForFunction(section=>document.querySelector('#'+section)?.hidden===false && document.querySelector('#loading')?.hidden===true,section);assert.equal(await page.locator('#retry').isVisible(),false,await page.locator('#notice').textContent());}
        await page.goto(base+'/barelytics/admin.php#pages',{waitUntil:'networkidle'});assert.equal(await page.locator('[data-page=pages][data-step="1"]').isDisabled(),true);assert.equal(await page.locator('[data-page=pages][data-step="-1"]').isDisabled(),true);
        // Restore the fixture before starting another browser engine.
        execFileSync('php',['-r',`$db=new PDO('sqlite:'.getenv('BARELYTICS_DATABASE_PATH'));$db->exec('DELETE FROM settings');`],{env});
        rmSync(resolve(directory,'analytics.sqlite'),{force:true});rmSync(resolve(directory,'analytics.sqlite-wal'),{force:true});rmSync(resolve(directory,'analytics.sqlite-shm'),{force:true});
        execFileSync('php',[resolve(root,'tests/browser/admin-fixture.php')],{env});
      }
      console.log(JSON.stringify({name,version:browser.version(),stylesheetsChecked:assets.length,errors,consoleErrors}));await context.close();
    }finally{await browser.close();}
  }
}finally{server.kill();rmSync(directory,{recursive:true,force:true});}
