// The merge queue on the board: the card being merged says so, and while main is red every other approved card says it
// waits for main to be fixed, and for what; a card's details carry the same, its activity says what was found failing on
// main, and the review lane and the move key say that the merge queue moves approved work to done.
export const seed = 'rich';

export default async (t) => {
    const { m_merging: merging, m_queued: queued, m_held: held, w1: doing } = t.seed.ids;
    const page = await t.open({ w: 1600, h: 900 });
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const tag = (id, text) => page.locator(`.card[data-id="${id}"] .tag`, { hasText: text });

    t.ok('the card being merged says so', (await tag(merging, 'Merging').count()) === 1
        && /Ana/.test(await tag(merging, 'Merging').getAttribute('title')));
    const waits = async (id) => (await tag(id, 'Waits on main').count()) === 1
        && /fails on main at [0-9a-f]{7}\)$/.test(await tag(id, 'Waits on main').getAttribute('title'))
        && !/[A-Z]+-[0-9A-Z]{4,}/.test(await tag(id, 'Waits on main').getAttribute('title'));
    t.ok('while main is red, every other approved card says it waits on main, and for what, naming no card', await waits(queued) && await waits(held));
    t.ok('the merging tag is green, the waiting one amber', await tag(merging, 'Merging').evaluate((el) => el.classList.contains('green'))
        && await tag(held, 'Waits on main').evaluate((el) => el.classList.contains('amber')));
    await t.shot(page, 'merge-board');

    await page.locator(`.card[data-id="${merging}"] .c-title`).click();
    await page.waitForSelector('.panel.is-top .d-title');
    await page.locator('.panel.is-top details.sec > summary', { hasText: 'Details' }).click();
    const row = page.locator('.panel.is-top dl.facts dt', { hasText: 'Merge queue' });
    t.ok('its details name who merges it, and since when', (await row.count()) === 1
        && /^merging on host-a \(Ana\) since \d\d:\d\d$/.test(await row.locator('xpath=following-sibling::dd[1]').innerText()));
    await t.shot(page, 'merge-details');
    await page.keyboard.press('Escape');

    const activity = async (id, text) => {
        await page.locator(`.card[data-id="${id}"] .c-title`).click();
        await page.waitForSelector('.panel.is-top .d-title');
        const found = page.locator('.panel.is-top .log li .log-h > span:not(.who)', { hasText: text });
        await found.first().waitFor({ timeout: 5000 }).catch(() => {});
        const count = await found.count();
        await page.keyboard.press('Escape');
        return count;
    };
    t.ok('its activity says, in words, that the merge found the failure on main too', (await activity(queued, /^\s*found php artisan test failing on main too$/)) === 1);
    t.ok('an agent\'s find on main is in its card\'s activity, in words', (await activity(doing, /^\s*found npm run lint failing on main$/)) === 1);

    await page.locator(`.card[data-id="${queued}"] .c-title`).click();
    await page.waitForSelector('.panel.is-top .d-title');
    await page.keyboard.press('Escape');
    await page.keyboard.press('m');
    await page.waitForSelector('.toast');
    t.ok('the move key of a review card says the merge queue moves it', /merge queue/.test(await page.locator('.toast').last().innerText()));

    await page.goto(t.url + '/infra', { waitUntil: 'networkidle' });
    t.ok('an empty review lane says reported work waits there for its evaluator, then for the merge queue',
        (await page.locator('.col[data-stage=review] .col-b').getAttribute('data-empty')) === 'Reported work waits here for its evaluator, then for the merge queue.');
    t.ok('and the done lane that the merge queue moves cards there',
        /merge queue/.test(await page.locator('.col[data-stage=done] .cli').getAttribute('aria-label')));
};
