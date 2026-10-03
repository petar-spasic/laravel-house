// Two people on one board: what the other pushes reaches the open page by itself, a remote that goes away is said out loud,
// and a tab nobody looks at stays quiet.
import fs from 'node:fs';

export const seed = 'team';

export default async (t) => {
    const { a, b } = t.seed.ids;
    const ben = { root: t.seed.peer, env: { KANBAN_USER: 'Ben' } };
    const page = await t.open({ w: 1440, h: 900 });
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });

    t.cli(['set', a, 'title=Renamed by Ben'], ben);
    t.cli(['sync'], ben);
    await page.waitForFunction((id) => (document.querySelector(`.card[data-id="${id}"] .c-title`) || {}).textContent === 'Renamed by Ben', a, { timeout: 40000 });
    t.ok('what a teammate pushed shows on the open board with no reload and no write of our own', true);

    // the remote goes away: the page says so, and stops saying so when it is back
    fs.renameSync(t.seed.origin, `${t.seed.origin}.away`);
    await page.waitForSelector('.notice', { timeout: 60000 });
    t.ok('an unreachable remote shows as a notice', (await page.locator('.notice').innerText()).includes('Not synced'));
    await t.shot(page, 'team-notice');
    fs.renameSync(`${t.seed.origin}.away`, t.seed.origin);
    await page.waitForFunction(() => !document.querySelector('.notice'), null, { timeout: 60000 });
    t.ok('and it goes away with the next sync that works', (await page.locator('.notice').count()) === 0);

    // a change made here is pushed without anyone asking, and Ben receives it
    await page.locator(`.card[data-id="${b}"] .c-title`).click();
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    await page.fill('.panel.is-top textarea[aria-label="Note"]', 'Note from Ana');
    await page.press('.panel.is-top textarea[aria-label="Note"]', 'Control+Enter');
    await page.waitForSelector('.panel.is-top .log q:has-text("Note from Ana")');
    let arrived = false;
    for (let i = 0; i < 60 && !arrived; i++) {
        await t.sleep(500);
        t.cli(['sync'], ben);
        arrived = JSON.parse(fs.readFileSync(fs.readdirSync(`${t.seed.peer}/docs/kanban/work`).map((f) => `${t.seed.peer}/docs/kanban/work/${f}`).find((f) => f.includes(b)), 'utf8')).log.some((e) => e.text === 'Note from Ana');
    }
    t.ok('and what is written here reaches the other clone by itself', arrived);
    await page.keyboard.press('Escape');

    // a tab nobody looks at sends nothing, and catches up the moment it is shown
    await page.evaluate(() => Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }));
    let requests = 0;
    page.on('request', (request) => { if (request.url().includes('/_api/')) requests++; });
    await page.waitForTimeout(7000);
    t.ok('a hidden tab sends no board request', requests === 0);
    const shown = page.waitForRequest((request) => request.url().includes('/_api/'), { timeout: 5000 });
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange')); });
    await shown;
    t.ok('and asks at once when it is shown again', true);
};
