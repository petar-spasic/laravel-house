// A card shows its epic and its area as chips; a click on either shows only their cards and a second click shows all
// again. Each area keeps one colour, the index lists the epics with their progress, and the panel sets the epic.
export const seed = 'rich';

export default async function (t) {
    const { schema, csv, w1 } = t.seed.ids;
    const page = await t.open();
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const face = (id) => page.locator(`.card[data-id="${id}"]`);
    const shown = () => page.locator('.col .card:visible').count();
    const all = await shown();

    t.ok('a card in an epic shows the epic as a chip', (await face(csv).locator('.fact.epic').innerText()) === 'Exports' && (await face(w1).locator('.fact.epic').innerText()) === 'Billing');
    t.ok('the area is a chip with a coloured dot', (await face(schema).locator('.fact.area .dot').evaluate((el) => getComputedStyle(el).backgroundColor)) !== 'rgba(0, 0, 0, 0)');
    const changes = page.locator('.card .fact.area', { hasText: /^changes$/ });
    t.ok('an area has the same colour on every card', (await changes.count()) >= 2 && new Set(await changes.evaluateAll((els) => els.map((el) => getComputedStyle(el.querySelector('.dot')).backgroundColor))).size === 1);
    const colours = await page.locator('.card .fact.area').evaluateAll((els) => Object.entries(Object.fromEntries(els.map((el) => [el.textContent, getComputedStyle(el.querySelector('.dot')).backgroundColor]))));
    t.ok('and the areas on the board each have a colour of their own', colours.length >= 6 && new Set(colours.map(([, colour]) => colour)).size === colours.length);
    await t.shot(page, 'epics-chips');

    await face(csv).locator('.fact.epic').click();
    await page.waitForTimeout(300);
    t.ok('a click on the epic chip shows only its cards', (await shown()) === 4 && new URL(page.url()).searchParams.get('e') === 'exports');
    t.ok('the card under the chip did not open', (await page.locator('.panel.is-top').count()) === 0);
    t.ok('the chip says it is pressed, and the toolbar names the epic', (await face(csv).locator('.fact.epic').getAttribute('aria-pressed')) === 'true' && (await page.locator('.tgl[data-filter="epic"]').innerText()).includes('Exports'));
    await t.shot(page, 'epics-filtered');
    await face(csv).locator('.fact.epic').click();
    await page.waitForTimeout(300);
    t.ok('a second click shows every card again', (await shown()) === all && !new URL(page.url()).searchParams.has('e'));

    await face(schema).locator('.fact.area').click();
    await page.waitForTimeout(300);
    const areas = await page.locator('.col .card:visible').evaluateAll((els) => els.map((el) => [...el.querySelectorAll('.fact.area')].map((chip) => chip.textContent)));
    t.ok('a click on the area chip shows only that area', areas.length > 0 && areas.length < all && areas.every((chips) => chips.includes('export')) && new URL(page.url()).searchParams.get('l') === 'area:export');
    await page.click('.toolbar .clear');

    await page.goto(t.url, { waitUntil: 'networkidle' });
    const tile = page.locator('.epic-tile', { hasText: 'Exports' });
    t.ok('the index lists each epic with its goal and progress', (await page.locator('.epic-tile').count()) === 2 && (await tile.innerText()).includes('Notebooks leave the app') && (await tile.innerText()).includes('0 of 4 done'));
    await tile.click();
    await page.waitForTimeout(600);
    t.ok('an epic opens the board showing only its cards', new URL(page.url()).searchParams.get('e') === 'exports' && (await shown()) === 4);

    await page.goto(t.url + '/cards/' + csv, { waitUntil: 'networkidle' });
    const pill = page.locator('.panel.is-top .pill[data-field="epic"]');
    await page.locator('.panel.is-top .d-title').waitFor();
    await page.waitForFunction(() => document.querySelector('.panel.is-top .pill[data-field="epic"] .pill-value')?.textContent === 'Exports', null, { timeout: 5000 }).catch(() => {});
    t.ok('the panel shows the epic', (await pill.innerText()).includes('Exports'));
    await pill.click();
    await page.locator('.menu [role=option]', { hasText: 'No epic' }).click();
    await page.waitForTimeout(800);
    t.ok('and takes it off the card', (await pill.innerText()).includes('No epic') && (await face(csv).locator('.fact.epic').count()) === 0);
    await pill.click();
    await page.locator('.menu [role=option]', { hasText: 'Exports' }).click();
    await page.waitForTimeout(800);
    t.ok('and puts it back', (await face(csv).locator('.fact.epic').innerText()) === 'Exports');
}
