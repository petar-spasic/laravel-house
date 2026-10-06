// How the board settles after a change: server order at once, a flash when someone else changed a card, a glide when it moved, nothing when the user asks for none.
export const seed = 'rich';

export default async (t) => {
    const { cand, low, labels } = t.seed.ids;

    // dropped into planning, the card belongs below the higher priorities: the server's order arrives within a moment, not a poll later
    const page = await t.open({ reducedMotion: 'reduce' });
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    await page.dragAndDrop(`.card[data-id="${cand}"]`, '.col[data-stage=planning]');
    const settled = await page.waitForFunction((id) => {
        const cards = [...document.querySelectorAll('.col[data-stage=planning] .card')];
        return cards.length > 1 && cards[cards.length - 1].dataset.id === id;
    }, cand, { timeout: 900 }).then(() => true, () => false);
    t.ok('a dropped card takes its place in server order at once', settled);

    // someone else changes a card: it flashes, unless the user asked for no motion
    t.cli(['set', low, 'title=Renamed on the command line']);
    await page.waitForSelector('.card:has-text("Renamed on the command line")', { timeout: 6000 });
    await page.waitForTimeout(100);
    t.ok('with reduced motion nothing on the board animates', await page.evaluate(() => document.getAnimations().filter((a) => a.effect && a.effect.target && a.effect.target.closest && a.effect.target.closest('.card') && !a.effect.target.closest('.pulse')).length === 0));

    const lively = await t.open();
    await lively.goto(t.url + '/work', { waitUntil: 'networkidle' });
    t.cli(['set', labels, 'title=Changed by someone else']);
    const flashed = await lively.waitForFunction((id) => {
        const el = document.querySelector(`.card[data-id="${id}"]`);
        return !!el && el.textContent.includes('Changed by someone else') && el.getAnimations().some((a) => a.effect.getKeyframes().some((k) => k.backgroundColor));
    }, labels, { timeout: 7000, polling: 30 }).then(() => true, () => false);
    t.ok('a card someone else changed flashes', flashed);

    // a card whose priority rises moves up its lane: it slides there, unless the user asked for no motion
    const place = (id) => `[...document.querySelectorAll('.col[data-stage=backlog] .card')].findIndex((el) => el.dataset.id === '${id}')`;
    const started = await page.evaluate(place(low));
    // the page that asked for no motion looks from now on, frame by frame, for the moment the card takes its new place
    const calmMove = page.waitForFunction(({ id, from }) => {
        const at = [...document.querySelectorAll('.col[data-stage=backlog] .card')].findIndex((el) => el.dataset.id === id);
        if (at === -1 || at >= from) return false;
        return { slides: document.getAnimations().filter((a) => a.effect && a.effect.target && a.effect.target.matches && a.effect.target.matches('.card') && a.effect.getKeyframes().some((k) => k.transform && k.transform !== 'none')).length };
    }, { id: low, from: started }, { timeout: 12000, polling: 'raf' }).then((handle) => handle.jsonValue(), () => null);
    t.cli(['set', low, 'priority=urgent']);
    const glided = await lively.waitForFunction((id) => {
        const el = document.querySelector(`.card[data-id="${id}"]`);
        return !!el && el.getAnimations().some((a) => a.effect.getKeyframes().some((k) => k.transform && k.transform !== 'none'));
    }, low, { timeout: 7000, polling: 30 }).then(() => true, () => false);
    t.ok('a card that moves slides to its new place', glided);
    const moved = await calmMove;
    t.ok('with reduced motion the same card takes its new place without sliding', moved !== null && moved.slides === 0);

    // opening a lane that was folded away does not fly its cards in from the corner
    await lively.goto(t.url + '/work', { waitUntil: 'networkidle' });
    t.ok('the dropped lane starts folded and holds a card', (await lively.locator('.col[data-stage=dropped].is-collapsed').count()) === 1 && (await lively.locator('.col[data-stage=dropped] .card').count()) > 0);
    await lively.click('.col[data-stage=dropped] .col-h');
    const flown = await lively.evaluate(() => document.getAnimations().filter((a) => a.effect && a.effect.target && a.effect.target.closest && a.effect.target.closest('.col[data-stage=dropped] .card') && a.effect.getKeyframes().some((k) => k.transform && k.transform !== 'none')).length);
    t.ok('opening it moves no card', flown === 0);
};
