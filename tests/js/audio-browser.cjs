// Uses an isolated SQLite fixture; does not log in to or modify the development database.
const {readFileSync, existsSync} = require('node:fs');
const {resolve, extname, join} = require('node:path');
const {tmpdir} = require('node:os');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const fixture = join(tmpdir(), 'voicecast-audio-browser.html');
execFileSync(process.env.TEST_PHP || 'php', ['vendor/bin/phpunit', '--filter=test_processing_rows_show_progress_and_no_media_links'], {
    env: {...process.env, AUDIO_BROWSER_FIXTURE: fixture}, stdio: 'inherit',
});
const root = resolve('public');
(async () => {
    const browser = await chromium.launch({channel: 'chrome', headless: true});
    try {
        for (const mobile of [false, true]) {
            const context = await browser.newContext({viewport: mobile ? {width: 390, height: 844} : {width: 1440, height: 1000}, isMobile: mobile, hasTouch: mobile});
            const page = await context.newPage();
            let requests = 0;
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://localhost/**', async route => {
                const url = new URL(route.request().url());
                if (url.pathname === '/audio') {
                    await route.fulfill({contentType: 'text/html', body: readFileSync(requests++ ? fixture + '.complete' : fixture, 'utf8')});
                    return;
                }
                const path = resolve(root, '.' + url.pathname);
                if (!path.startsWith(root + '/') || !existsSync(path)) {
                    await route.fulfill({status: 404, body: ''});
                    return;
                }
                await route.fulfill({contentType: ({'.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2'})[extname(path)] || 'application/octet-stream', body: readFileSync(path)});
            });
            await page.goto('http://localhost/audio');
            await page.locator('[data-audio-processing]').waitFor();
            assert.equal(await page.locator('audio').count(), 0);
            assert.equal(await page.getByRole('button', {name: 'Approve audio', exact: true}).count(), 0);
            const accepts = await page.locator('input[type=file]').getAttribute('accept');
            assert(accepts.includes('.mp3') && accepts.includes('.m4a') && accepts.includes('.wav'));
            await page.getByRole('button', {name: 'Approve audio', exact: true}).waitFor({timeout: 12000});
            assert.equal(await page.locator('[data-audio-processing]').count(), 0);
            assert.equal(await page.locator('audio').count(), 1);
            assert.equal(await page.locator('audio').getAttribute('preload'), 'none');
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'page must fit the viewport');
            assert.deepEqual(errors, []);
            await page.screenshot({path: join(tmpdir(), 'voicecast-audio-' + (mobile ? 'mobile' : 'desktop') + '.png'), fullPage: true});
            console.log((mobile ? 'Mobile' : 'Desktop') + ' audio processing and automatic status refresh passed');
            await context.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
