// Cards in doing, review and done are locked: the panel shows them as they are, and only a note, a block and ticks change them.
export const seed = 'rich';

export default async (t) => {
    const { w1, r1, a } = t.seed.ids;
    const page = await t.open({ w: 1440, h: 900 });
    const panel = '.panel.is-top';
    const open = async (id) => {
        await page.goto(t.url + `/cards/${id}`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    };

    // a card that is being worked on
    await open(w1);
    t.ok('the panel says why it is locked and how to reopen it', /locked/i.test(await page.locator(`${panel} .lock-note`).innerText()) && (await page.locator(`${panel} .lock-note`).innerText()).includes(`kanban stop ${w1}`));
    t.ok('the name is text to read, not a field to change', await page.evaluate(() => { const title = document.querySelector('.panel.is-top .d-title'); return title.readOnly && title.title === '' && getComputedStyle(document.querySelector('.panel.is-top .d-edit')).display === 'none'; }));
    t.ok('priority, type and stage cannot be changed', (await page.locator(`${panel} button[data-field=priority]:disabled`).count()) === 1 && (await page.locator(`${panel} button[data-field=type]:disabled`).count()) === 1 && (await page.locator(`${panel} button[data-field=stage]:disabled`).count()) === 1);
    t.ok('labels are shown without a way to remove or add one', (await page.locator(`${panel} .prop:has-text("Labels") .chip`).count()) === 2 && (await page.locator(`${panel} .prop:has-text("Labels") .chip button`).count()) === 0 && (await page.locator(`${panel} input[aria-label="New label"]:visible`).count()) === 0);
    t.ok('the description is not an editor', (await page.locator(`${panel} .editable`).count()) === 0);
    await page.locator(`${panel} .md`).first().click().catch(() => {});
    t.ok('and clicking it opens nothing', (await page.locator(`${panel} textarea[aria-label="Description"]`).count()) === 0);
    t.ok('criteria cannot be added, reworded or removed', (await page.locator(`${panel} input[aria-label="New criterion"]:visible`).count()) === 0 && (await page.locator(`${panel} .check .text[role=button]`).count()) === 0 && (await page.locator(`${panel} .check .rm`).count()) === 0 && (await page.locator(`${panel} .check .locked`).count()) === (await page.locator(`${panel} .check li`).count()));
    await t.shot(page, 'locked-doing');

    // but they can be ticked, the card blocked and a note written
    await page.locator(`${panel} .check input[type=checkbox]`).first().check();
    await page.waitForSelector(`${panel} .check li.is-done`);
    t.ok('a tick is saved', (await page.locator(`${panel} .check li.is-done`).count()) === 1);
    await page.click(`${panel} button:has-text("Block…")`);
    await page.fill('input[aria-label="Blocked reason"]', 'waiting for the vendor');
    await page.press('input[aria-label="Blocked reason"]', 'Enter');
    await page.waitForSelector(`${panel} .banner`);
    t.ok('a block is saved', (await page.locator(`${panel} .banner`).innerText()).includes('waiting for the vendor'));
    await page.click(`${panel} button:has-text("Unblock")`);
    await page.waitForFunction(() => !document.querySelector('.panel.is-top .banner'));
    await page.fill(`${panel} textarea[aria-label="Note"]`, 'Vendor confirmed');
    await page.press(`${panel} textarea[aria-label="Note"]`, 'Control+Enter');
    await page.waitForSelector(`${panel} .log q:has-text("Vendor confirmed")`);
    t.ok('a note is saved', true);
    t.ok('and none of it unlocked the card', (await page.locator(`${panel} .lock-note:visible`).count()) === 1 && (await page.locator(`${panel} button[data-field=priority]:disabled`).count()) === 1);

    // a card in review: the board has no move out of it either (the command line sends it back)
    await open(r1);
    t.ok('a card in review is locked, and says so', (await page.locator(`${panel} .lock-note:visible`).innerText()).toLowerCase().includes('review') && (await page.locator(`${panel} button[data-field=stage]:disabled`).count()) === 1);

    // a card that has not been picked up is not
    await open(a);
    t.ok('a card in the backlog is not', (await page.locator(`${panel} .lock-note:visible`).count()) === 0 && await page.evaluate(() => !document.querySelector('.panel.is-top .d-title').readOnly) && (await page.locator(`${panel} button[data-field=priority]:not(:disabled)`).count()) === 1);
    await page.keyboard.press('Tab');
    t.ok('and the first Tab still goes into its name', await page.evaluate(() => document.activeElement.classList.contains('d-title')));

    // a finished card
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.locator('.col[data-stage=done] .c-title').first().click();
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    const finished = await page.locator(`${panel} .lock-note`).innerText();
    t.ok('a finished card is locked, and there is nothing to reopen', /done/i.test(finished) && !finished.includes('kanban stop'));
    await t.shot(page, 'locked-done');

    // the priority key follows the same rule: on a locked card it says why and sends nothing, on any other it cycles
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    let writes = 0;
    page.on('request', (request) => { if (request.method() !== 'GET') writes++; });
    const level = (id) => page.locator(`.card[data-id="${id}"]`).evaluate((el) => [...el.classList].find((c) => c.startsWith('p-')));
    const pick = async (id) => {
        await page.locator(`.card[data-id="${id}"] .c-title`).click();
        await page.waitForSelector('.panel.is-top .d-title');
        await page.keyboard.press('Escape');
    };
    const finishedId = await page.locator('.col[data-stage=done] .card').first().getAttribute('data-id');
    for (const [what, id] of [['being worked on', w1], ['in review', r1], ['done', finishedId]]) {
        const before = await level(id);
        await pick(id);
        await page.keyboard.press('p');
        await page.waitForSelector(`.toast:has-text("${id}")`);
        t.ok(`p on a card ${what} says why nothing changes, and sends nothing`, /locked/i.test(await page.locator(`.toast:has-text("${id}")`).last().innerText()) && (await page.locator('.toast.err').count()) === 0 && writes === 0 && (await level(id)) === before);
    }
    const before = await level(a);
    await pick(a);
    await page.keyboard.press('p');
    await page.waitForFunction(([id, was]) => !document.querySelector(`.card[data-id="${id}"]`).classList.contains(was), [a, before]);
    t.ok('p still cycles the priority of a card that has not been picked up', (await level(a)) !== before && writes === 1);
};
