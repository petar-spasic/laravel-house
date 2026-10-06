// Planning: a lane between backlog and ready where cards wait for their plan, a planner's card shows its agent, and a
// planned card shows its plan in the panel, rendered and read-only.
export const seed = 'rich';

export default async (t) => {
    const { to_plan: waiting, planning, planned, cand } = t.seed.ids;
    const page = await t.open({ w: 1600, h: 900 });
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const panel = '.panel.is-top';
    const open = async (id) => {
        await page.goto(t.url + `/cards/${id}`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    };

    const lanes = await page.locator('.col').evaluateAll((cols) => cols.map((col) => col.dataset.stage));
    t.ok('planning is the lane between backlog and ready', lanes.join(',') === 'backlog,planning,ready,doing,review,done,dropped');
    t.ok('it holds the cards waiting for a plan and the one being planned', (await page.locator(`.col[data-stage=planning] .card[data-id="${waiting}"]`).count()) === 1
        && (await page.locator(`.col[data-stage=planning] .card[data-id="${planning}"]`).count()) === 1);
    t.ok('the card a planner works on shows its agent at work', /Working/.test(await page.locator(`.card[data-id="${planning}"] .tag.green`).innerText()));
    t.ok('its lane mark differs from every other lane\'s', await page.evaluate(() => {
        const marks = [...document.querySelectorAll('.col .col-h .stage-i')].map((i) => i.innerHTML);
        return marks.length === 7 && new Set(marks).size === 7;
    }));
    await t.shot(page, 'planning-board');

    // the plan of a planned card: collapsed under the criteria, rendered when opened, never an editor
    await open(planned);
    const plan = page.locator(`${panel} details.plan`);
    t.ok('a planned card has a Plan section, folded', (await plan.count()) === 1 && !(await plan.evaluate((el) => el.open)));
    t.ok('which says who planned it and on which commit of main', /plan/i.test(await plan.locator('summary').innerText()) && /@[0-9a-f]{7}/.test(await plan.locator('summary').innerText()));
    await plan.locator('summary').click();
    t.ok('opened, the plan is rendered Markdown', (await plan.locator('.md h2', { hasText: 'Files' }).count()) === 1 && (await plan.locator('.md code', { hasText: 'README.md' }).count()) >= 1);
    await plan.locator('.md h2', { hasText: 'Steps' }).click();
    t.ok('and clicking it opens no editor', (await plan.locator('.editable').count()) === 0 && (await page.locator(`${panel} textarea[aria-label="Plan"]`).count()) === 0);
    await t.shot(page, 'planning-plan');

    // a card waiting for its plan has none to show; a card a planner holds moves with the command line only
    await open(waiting);
    t.ok('a card waiting in planning has no Plan section', (await page.locator(`${panel} details.plan:visible`).count()) === 0);
    await open(planning);
    t.ok('the stage of a card being planned cannot be changed here', (await page.locator(`${panel} button[data-field=stage]:disabled`).count()) === 1);

    // out of the backlog a card goes to planning: the move menu offers it, and the card lands there
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    await page.locator(`.card[data-id="${cand}"] .c-title`).click();
    await page.waitForSelector('.panel.is-top .d-title');
    await page.keyboard.press('Escape');
    await page.keyboard.press('m');
    const offered = (await page.locator('.menu [role=menuitem]').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').trim()).join('|');
    t.ok('the move menu of a backlog card offers planning, not ready', offered === 'planning 1|dropped 2');
    await page.click('.menu [role=menuitem]:has-text("planning")');
    await page.waitForSelector(`.col[data-stage=planning] .card[data-id="${cand}"]`);
    t.ok('the card lands in planning', (await page.locator(`.col[data-stage=planning] .card[data-id="${cand}"]`).count()) === 1);
};
