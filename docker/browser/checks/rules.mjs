// What the server refuses, how the page says so, and the layouts around the board.
import { pick, valueOf } from '../lib.mjs';

export default async (t) => {
    const { a, b, c } = t.seed.ids;
    const page = await t.open();
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });

    await page.dragAndDrop(`.card[data-id="${c}"]`, '.col[data-stage=ready]');
    await page.waitForSelector('.toast.err');
    const toast = await page.locator('.toast.err').innerText();
    t.ok('a refused move lists the policy reasons', toast.includes('Not allowed') && toast.includes('R3'));
    await page.waitForTimeout(800);
    t.ok('and the card is back where it was', (await page.locator(`.col[data-stage=backlog] .card[data-id="${c}"]`).count()) === 1);
    await t.shot(page, 'refused');

    await page.click(`.card[data-id="${b}"] .c-title`);
    await page.waitForSelector('.drawer:not([hidden]) .md');
    t.cli(['set', b, 'priority=low']);
    await pick(page, 'priority', 'high');
    await page.waitForTimeout(900);
    t.ok('a stale edit says the card changed', (await page.locator('.toast.err').count()) >= 1);
    t.ok('and the drawer shows the latest version', (await valueOf(page, 'priority')) === 'low');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    t.ok('a card URL opens the drawer over its board', (await page.locator('.drawer:not([hidden]) .d-title').count()) === 1 && (await page.locator('.col').count()) === 6);

    const phone = await t.open({ w: 390, h: 800 });
    await phone.goto(t.url + '/work', { waitUntil: 'networkidle' });
    await t.shot(phone, 'phone-board');
    await phone.click(`.card[data-id="${a}"] .c-title`);
    await phone.waitForSelector('.drawer:not([hidden]) .md');
    await phone.waitForTimeout(400);
    await t.shot(phone, 'phone-drawer');
    t.ok('a phone-width page does not scroll sideways', await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth));

    // another board: n adds there, and a board whose cards carry no labels shows no stray text in its filters
    t.cli(['board', 'infra', 'Infra']);
    const other = await t.open();
    await other.goto(t.url + '/infra', { waitUntil: 'networkidle' });
    await other.keyboard.press('n');
    await other.keyboard.type('Move the queue to Redis');
    await other.keyboard.press('Enter');
    await other.waitForTimeout(800);
    t.ok('n adds a card on any board', (await other.locator('.col[data-stage=backlog] .card', { hasText: 'Move the queue to Redis' }).count()) === 1);
    t.ok('a board without labels shows no stray text in its filters', !(await other.locator('.toolbar-chips').innerText()).includes('null'));
};
