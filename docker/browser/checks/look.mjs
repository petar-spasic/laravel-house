// What the design looks like: a screenshot matrix for review, and the assertions that do not need an eye.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const seed = 'rich';

const here = path.dirname(fileURLToPath(import.meta.url));
const script = fs.readFileSync(path.join(here, '../../../resources/dist/kanban.js'), 'utf8');

export default async (t) => {
    const { w1, question, nightly, job, schema, blocked } = t.seed.ids;
    const views = [
        ['index', ''],
        ['work', '/work'],
        ['infra', '/infra'],
        ['drawer', `/cards/${w1}`],
        ['drawer-blocked', `/cards/${blocked}`],
        ['drawer-question', `/cards/${question}`],
        ['stack', `/cards/${schema}?from=${nightly},${job}`],
    ];

    for (const scheme of ['light', 'dark']) {
        for (const [name, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
            const page = await t.open({ w: width, h: height, scheme, touch: name === 'phone' });
            for (const [view, path] of views) {
                await page.goto(t.url + path, { waitUntil: 'networkidle' });
                await page.waitForTimeout(400);
                await t.shot(page, `look-${scheme}-${name}-${view}`);
                if (view === 'drawer-blocked' && scheme === 'light' && name === 'desktop') {
                    t.ok('a blocked card\'s panel shows why it is blocked', (await page.locator('.panel.is-top .banner').innerText()).includes('Waiting for the security team'));
                    t.ok('and what it depends on, as a link', (await page.locator('.panel.is-top .prop:has-text("Depends on") .chip a').count()) === 1);
                }
            }
            if (scheme === 'light' && name === 'desktop') await oneOffs(t, page);
            await page.close();
        }
    }

    // the font files are served and immutable
    const page = await t.open();
    await page.goto(t.url, { waitUntil: 'networkidle' });
    const fonts = fs.readdirSync(path.join(here, '../../../resources/dist')).filter((file) => /^inter-\d+-\d+\.woff2$/.test(file));
    t.ok('the package ships three cuts of the font', fonts.length === 3);
    for (const file of fonts) {
        const response = await page.request.get(`${t.url}/assets/${file}`);
        t.ok(`${file} is served as woff2, immutable`, response.status() === 200 && response.headers()['content-type'] === 'font/woff2' && /immutable/.test(response.headers()['cache-control']));
    }
    const icon = await page.request.get(`${t.url}/assets/kanban.svg`);
    t.ok('the favicon is served', icon.status() === 200 && icon.headers()['content-type'] === 'image/svg+xml');
    await page.evaluate(() => document.fonts.ready);
    t.ok('the page text is set in Inter', await page.evaluate(() => document.fonts.check('13px Inter') && [...document.fonts].some((f) => f.family === 'Inter' && f.status === 'loaded')));

    // the icon sheet: every icon at three sizes, extracted from the script between its markers
    const block = script.match(/\/\* icons:start \*\/([\s\S]*?)\/\* icons:end \*\//);
    t.ok('the script marks its icon table', block !== null);
    if (block) {
        const icons = new Function(`${block[1]}; return ICONS;`)();
        const cells = Object.entries(icons).map(([name, shape]) => {
            const d = typeof shape === 'string' ? shape : shape.d;
            const fill = typeof shape === 'string' ? 'none' : 'currentColor';
            return `<figure>${[16, 20, 32].map((size) => `<svg width="${size}" height="${size}" viewBox="0 0 16 16" fill="${fill}" stroke="${fill === 'none' ? 'currentColor' : 'none'}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="${d}"/></svg>`).join('')}<figcaption>${name}</figcaption></figure>`;
        });
        const sheet = await t.open({ w: 900, h: 700 });
        await sheet.setContent(`<style>body{font:12px sans-serif;display:flex;flex-wrap:wrap;gap:16px;padding:16px}figure{margin:0;width:120px}svg{margin-right:8px}</style>${cells.join('')}`);
        await t.shot(sheet, 'look-icons');
    }

    // lanes are one width, however long a card's text is
    const lanes = await t.open({ w: 1440, h: 900 });
    await lanes.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const widths = await lanes.evaluate(() => [...document.querySelectorAll('.col:not(.is-collapsed)')].map((el) => Math.round(el.getBoundingClientRect().width)));
    t.ok('every open lane is the same width', widths.length >= 5 && new Set(widths).size === 1);

    // docking: the board keeps its own space beside the drawer from 1400px, and is overlaid with a scrim below
    const wide = await t.open({ w: 1440, h: 900 });
    await wide.goto(t.url + `/cards/${w1}`, { waitUntil: 'networkidle' });
    await wide.waitForSelector('.drawer:not([hidden])');
    const docked = await wide.evaluate(() => {
        const board = document.querySelector('.board').getBoundingClientRect();
        const drawer = document.querySelector('.drawer').getBoundingClientRect();
        return board.right <= drawer.left + 1;
    });
    t.ok('at 1440px the drawer sits beside the board', docked);
    t.ok('and there is no scrim over the board', (await wide.locator('.scrim:visible').count()) === 0);
    const narrow = await t.open({ w: 1200, h: 800 });
    await narrow.goto(t.url + `/cards/${w1}`, { waitUntil: 'networkidle' });
    await narrow.waitForSelector('.drawer:not([hidden])');
    t.ok('below 1400px the drawer overlays the board with a scrim', (await narrow.locator('.scrim:visible').count()) === 1);
    await t.shot(narrow, 'look-overlay');
};

// menus, composer, toast, dialog, offline: light desktop only
async function oneOffs(t, page) {
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    await page.keyboard.press('j');
    await page.keyboard.press('m');
    await page.waitForSelector('.menu');
    await t.shot(page, 'look-menu-move');
    await page.keyboard.press('Escape');

    await page.keyboard.press('n');
    await page.waitForSelector('.composer');
    await t.shot(page, 'look-composer');
    await page.keyboard.press('Escape');

    await page.keyboard.press('?');
    await page.waitForSelector('dialog.help[open]');
    await t.shot(page, 'look-help');
    await page.keyboard.press('Escape');

    await page.click('.switcher');
    await page.waitForSelector('.menu');
    await t.shot(page, 'look-switcher');
    await page.keyboard.press('Escape');
    await page.click('.tgl[data-filter=priority]');
    await page.waitForSelector('.menu');
    await t.shot(page, 'look-menu-priority');
    await page.keyboard.press('Escape');

    // a refused move: a card without a body cannot enter ready
    await page.keyboard.press('j');
    await page.keyboard.press('m');
    await page.waitForSelector('.menu');
    await page.click('.menu button:has-text("ready")');
    await page.waitForSelector('.toast.err');
    await t.shot(page, 'look-toast-error');

    // the server goes away: three polls fail
    t.allow(/net::ERR_FAILED/);
    await page.route('**/_api/**', (route) => route.abort());
    for (let i = 0; i < 3; i++) {
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForTimeout(300);
    }
    await t.shot(page, 'look-offline');
    await page.unroute('**/_api/**');
}
