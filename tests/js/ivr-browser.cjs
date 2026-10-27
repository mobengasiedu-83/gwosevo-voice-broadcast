// Usage: PLAYWRIGHT_MODULE=/path/to/playwright node tests/js/ivr-browser.cjs
const {createServer}=require('node:http');
const {readFileSync,existsSync}=require('node:fs');
const {resolve,extname}=require('node:path');
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {tmpdir}=require('node:os');
const {join}=require('node:path');
const fixture=join(tmpdir(),'gwosevo-ivr-browser.html');
const php=process.env.TEST_PHP||'php';
execFileSync(php,['vendor/bin/phpunit','--filter=IvrBrowserFixtureTest'],{env:{...process.env,APP_URL:'http://127.0.0.1:4319',IVR_BROWSER_FIXTURE:fixture},stdio:'inherit'});
const root=resolve('public');
const server=createServer((req,res)=>{const path=req.url==='/'?fixture:resolve(root,'.'+req.url.split('?')[0]);if(path!==fixture&&!path.startsWith(root+'/')){res.writeHead(403).end();return;}if(!existsSync(path)){res.writeHead(404).end();return;}res.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[extname(path)]||'text/html');res.end(readFileSync(path));});
(async()=>{await new Promise(r=>server.listen(4319,'127.0.0.1',r));let browser;try{browser=await chromium.launch({channel:'chrome',headless:true});for(const mobile of [false,true]){const context=await browser.newContext({viewport:mobile?{width:390,height:844}:{width:1440,height:1000},hasTouch:mobile,isMobile:mobile});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('http://127.0.0.1:4319');await page.locator('.ivr-card').first().waitFor();
assert.equal(await page.locator('.ivr-card').count(),3);
assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'page should not overflow horizontally');
const card=page.locator('.ivr-card').first(),handle=card.locator('button');const x=await card.evaluate(e=>e.offsetLeft);await handle.focus();await page.keyboard.press('ArrowRight');assert.equal(await card.evaluate(e=>e.offsetLeft),x+20);
await handle.click();const key=page.locator('.ivr-branch input').first();await key.fill('x');await key.press('Tab');assert.equal(await key.inputValue(),'1');assert(await key.evaluate(e=>e.checkValidity()),'restored digit must be valid');
// Native required-field validation should reject an empty flow name.
assert.equal(await page.locator('#flow-name').evaluate(e=>e.checkValidity()),false);
if(!mobile){const box=await handle.boundingBox(),before=await card.evaluate(e=>e.offsetLeft);await page.mouse.move(box.x+15,box.y+10);await page.mouse.down();await page.mouse.move(box.x+65,box.y+35,{steps:6});await page.mouse.up();assert((await card.evaluate(e=>e.offsetLeft))>before,'mouse dragging must move card');}
else{await handle.scrollIntoViewIfNeeded();const box=await handle.boundingBox(),before=await card.evaluate(e=>e.offsetLeft),cdp=await context.newCDPSession(page);await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:box.x+15,y:box.y+10}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:box.x+60,y:box.y+35}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});assert((await card.evaluate(e=>e.offsetLeft))>before,'touch dragging must move card');}
await page.emulateMedia({reducedMotion:'reduce'});await page.screenshot({path:join(tmpdir(),`ivr-${mobile?'mobile':'desktop'}.png`),fullPage:true});assert.deepEqual(errors,[]);console.log((mobile?'Mobile touch':'Desktop')+' browser checks passed');await context.close();}}finally{await browser?.close();await new Promise(r=>server.close(r));}})().catch(e=>{console.error(e);process.exitCode=1;});
