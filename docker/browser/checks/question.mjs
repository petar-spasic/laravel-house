// A card that waits on the owner's answer (blocked="question: …") looks different from one blocked on anything else; a card
// that many open cards wait on says so, and a card's area comes first among its labels.
export const seed = 'rich';

export default async function (t) {
    const { question, blocked, schema, job, labels } = t.seed.ids;
    const page = await t.open();
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const face = (id) => page.locator(`.card[data-id="${id}"]`);
    const spine = (id) => face(id).evaluate((el) => getComputedStyle(el, '::before').backgroundColor);

    t.ok('a question card is marked as one, not as blocked', await face(question).evaluate((el) => el.classList.contains('is-question') && !el.classList.contains('is-blocked')));
    t.ok('its tag says Question', (await face(question).locator('.tag').first().innerText()).trim() === 'Question');
    t.ok('its face shows the question without the prefix', (await face(question).locator('.fact.reason').innerText()).startsWith('headless browser'));
    t.ok('a plain blocked card stays blocked', (await face(blocked).locator('.tag').first().innerText()).trim() === 'Blocked');
    t.ok('the two have different spines', (await spine(question)) !== (await spine(blocked)));

    t.ok('a card three open cards wait on says so', (await face(schema).locator('.tag', { hasText: 'Blocks 3' }).count()) === 1 && (await face(job).locator('.tag', { hasText: 'Blocks' }).count()) === 0);
    t.ok('the area is a chip before the other labels, named without its prefix', (await face(labels).locator('.fact').filter({ hasText: /^sync$/ }).count()) === 1 && (await face(labels).locator('.fact.area + .fact.plain').count()) === 1 && (await face(question).locator('.fact.area').innerText()) === 'print');

    await face(question).locator('.c-title').click();
    await page.waitForSelector('.panel.is-top .banner');
    const banner = page.locator('.panel.is-top .banner');
    t.ok('its panel shows the question in a question banner', (await banner.evaluate((el) => el.classList.contains('question'))) && /Question for the owner/.test(await banner.innerText()) && (await banner.innerText()).includes('headless browser'));
    const background = await banner.evaluate((el) => getComputedStyle(el).backgroundColor);
    await t.shot(page, 'question');
    await page.goto(t.url + '/cards/' + blocked, { waitUntil: 'networkidle' });
    await page.waitForSelector('.panel.is-top .banner');
    t.ok('which looks unlike a blocked banner', (await page.locator('.panel.is-top .banner').evaluate((el) => getComputedStyle(el).backgroundColor)) !== background);
}
