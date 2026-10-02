// Links, history, the keyboard on links and buttons, and what survives a reload.
import fs from 'node:fs';
import { choices, pick, switchTo } from '../lib.mjs';

export default async (t) => {
    const { a, c, d, f, g } = t.seed.ids;
    t.cli(['board', 'platform/infra', 'Infra']);
    const page = await t.open();
    await page.addInitScript(() => { if (!sessionStorage.getItem('seeded')) { localStorage.setItem('kanban.collapsed', '{broken'); sessionStorage.setItem('seeded', '1'); } });
    await page.goto(t.url + '/cards/' + g, { waitUntil: 'networkidle' });
    await page.waitForSelector('.drawer:not([hidden]) button[data-field="stage"]');
    await page.waitForTimeout(500);
    t.ok('the page starts with damaged stored preferences', (await page.locator('.col').count()) === 6);
    const options = await choices(page, 'stage');
    t.ok('a dropped card can only go back to backlog', JSON.stringify(options) === JSON.stringify(['backlog']));
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    await page.dragAndDrop(`.card[data-id="${c}"]`, '.col[data-stage=doing]');
    await page.waitForTimeout(600);
    t.ok('a stage a card cannot move to is no drop target', (await page.locator(`.col[data-stage=backlog] .card[data-id="${c}"]`).count()) === 1 && (await page.locator('.toast.err').count()) === 0);

    await page.goto(t.url, { waitUntil: 'networkidle' });
    await page.focus('.board-tile[href$="/project/work"]');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(500);
    t.ok('Enter follows a board link', page.url().includes('/project/'));
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.focus(`.card[data-id="${a}"] .c-title`);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(600);
    t.ok('Enter follows a card link', page.url().endsWith('/cards/' + a));
    await page.focus('.drawer button[aria-label="Close"]');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(400);
    t.ok('Enter presses the Close button', (await page.locator('.drawer[hidden]').count()) === 1);
    await switchTo(page, 'All boards');
    t.ok('"All boards" in the switcher goes to the boards', page.url().endsWith('/kanban') && (await page.locator('.board-tile').count()) === 2);

    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.route('**/_api/cards/' + a, async (route) => { await new Promise((r) => setTimeout(r, 1200)); await route.continue(); });
    await page.click(`.card[data-id="${a}"] .c-title`);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(2200);
    t.ok('a card that loads after it was closed does not reopen', (await page.locator('.drawer[hidden]').count()) === 1 && !page.url().includes('/cards/'));
    await page.unroute('**/_api/cards/' + a);

    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    const width = (stage) => page.locator(`.col[data-stage=${stage}]`).evaluate((el) => Math.round(el.getBoundingClientRect().width));
    t.ok('a folded column is a narrow strip', (await width('dropped')) <= 50);
    await page.click('.col[data-stage=dropped] .col-h');
    await page.waitForTimeout(300);
    t.ok('and a click opens it', (await width('dropped')) > 150);
    await page.click('.col[data-stage=dropped] .col-h');
    await page.waitForTimeout(300);
    t.ok('and another folds it again', (await width('dropped')) <= 50);

    await page.goto(t.url + '/project/work?p=high', { waitUntil: 'networkidle' });
    await page.click(`.card[data-id="${a}"] .c-title`);
    await page.waitForSelector('.drawer:not([hidden])');
    t.ok('opening a card keeps the filters', page.url().includes('p=high') && (await page.locator('.tgl[data-filter=priority].is-active').count()) === 1);
    await page.keyboard.press('Escape');

    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.click('.col[data-stage=backlog] .add');
    await switchTo(page, 'Infra');
    t.ok('a new-card box does not follow to another board', (await page.locator('.composer').count()) === 0);

    await page.goto(t.url + '/project/nothing', { waitUntil: 'networkidle' });
    await page.waitForTimeout(7000);
    t.ok('an unknown board is not shown as an outage', (await page.locator('.live.is-off').count()) === 0);

    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.click(`.card[data-id="${f}"] .c-title`);
    await page.waitForSelector('.drawer:not([hidden]) .chip a');
    await page.click('.drawer .chip a');
    await page.waitForTimeout(600);
    t.ok('a dependency link opens that card on top of the first', page.url().includes('/cards/' + d) && page.url().includes('from=') && (await page.locator('.panel').count()) === 2);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(600);
    t.ok('Esc closes the one on top and leaves the first', page.url().includes('/cards/' + f) && !page.url().includes('from=') && (await page.locator('.panel').count()) === 1);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(600);
    t.ok('Esc again ends on the board, not the first card', page.url().endsWith('/project/work') && (await page.locator('.drawer[hidden]').count()) === 1);

    await page.click(`.card[data-id="${a}"] .c-title`);
    await page.waitForSelector('.drawer:not([hidden]) .md');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    t.cli(['set', a, 'note=added while closed', 'priority=urgent']);
    await page.waitForTimeout(4500);
    await page.click(`.card[data-id="${a}"] .c-title`);
    await page.waitForSelector('.drawer:not([hidden]) .log q');
    await page.waitForTimeout(500);
    t.ok('a reopened card shows what changed meanwhile', (await page.locator('.drawer .log q', { hasText: 'added while closed' }).count()) === 1);
    await pick(page, 'type', 'bug');
    await page.waitForTimeout(800);
    t.ok('and its first edit does not conflict', (await page.locator('.toast.err').count()) === 0);
    await page.keyboard.press('Escape');

    const file = `${t.seed.root}/docs/kanban/project/work/${c}.json`;
    const good = fs.readFileSync(file, 'utf8');
    fs.writeFileSync(file, '{not json');
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    t.ok('a broken board file is announced', (await page.locator('.notice', { hasText: 'files have problems' }).count()) === 1);
    fs.writeFileSync(file, good);
    await page.waitForTimeout(4500);
    t.ok('and the notice goes once it is fixed', (await page.locator('.notice', { hasText: 'files have problems' }).count()) === 0);

    const boardFile = `${t.seed.root}/docs/kanban/project/work/board.json`;
    fs.renameSync(boardFile, boardFile + '.off');
    await page.goto(t.url + '/cards/' + a, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    t.ok('a card whose board file is missing still opens', (await page.locator('.drawer:not([hidden]) .d-title').inputValue().then((v) => v.length > 0)) && (await page.locator('.empty', { hasText: 'cannot be read' }).count()) === 1);
    fs.renameSync(boardFile + '.off', boardFile);

    // a first load that fails leaves a way to try again, not a blank page
    const broken = await t.open();
    await broken.route('**/_api/boards', (route) => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'The board is busy', details: [] }) }));
    await broken.goto(t.url, { waitUntil: 'networkidle' });
    t.ok('a page that cannot load says so and offers to try again', (await broken.locator('.empty', { hasText: 'Cannot load' }).count()) === 1 && (await broken.locator('.empty button:has-text("Retry")').count()) === 1);
    await broken.unroute('**/_api/boards');
    await broken.click('.empty button:has-text("Retry")');
    await broken.waitForSelector('.board-tile');
    t.ok('and Retry draws it', (await broken.locator('.board-tile').count()) === 2);
};
