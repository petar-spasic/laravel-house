// Windows high contrast (forced colours): fills and shadows are dropped, so boundaries, state and focus have to come from borders, outlines and system colours.
export const seed = 'rich';

export default async (t) => {
    const { blocked } = t.seed.ids;
    const page = await t.open({ forcedColors: 'active' });
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    t.ok('the browser is in forced colours', await page.evaluate(() => matchMedia('(forced-colors: active)').matches));
    const style = (selector, pseudo, ...props) => page.evaluate(([selector, pseudo, props]) => {
        const el = document.querySelector(selector);
        if (!el) return null;
        const computed = getComputedStyle(el, pseudo || null);
        return Object.fromEntries(props.map((prop) => [prop, computed[prop]]));
    }, [selector, pseudo, props]);
    const system = (name) => page.evaluate((n) => { const el = document.createElement('i'); el.style.color = n; document.body.append(el); const c = getComputedStyle(el).color; el.remove(); return c; }, name);

    const card = await style(`.card[data-id="${blocked}"]`, null, 'borderTopStyle', 'borderTopWidth');
    t.ok('a card has a border to be seen by', card.borderTopStyle === 'solid' && parseFloat(card.borderTopWidth) >= 1);
    const spine = await style(`.card[data-id="${blocked}"]`, '::before', 'borderLeftStyle', 'borderLeftWidth');
    t.ok('a card that needs attention has its edge as a border, not a fill', spine.borderLeftStyle === 'solid' && parseFloat(spine.borderLeftWidth) >= 3);
    const tag = await style('.tag', null, 'borderTopStyle', 'borderTopWidth');
    t.ok('a tag has a border, its fill is gone', tag.borderTopStyle === 'solid' && parseFloat(tag.borderTopWidth) >= 1);
    const lane = await style('.col', null, 'borderTopStyle', 'borderTopWidth');
    t.ok('a lane has a border', lane.borderTopStyle === 'solid' && parseFloat(lane.borderTopWidth) >= 1);

    await page.keyboard.press('j');
    const selected = await style('.card.is-selected', null, 'outlineStyle', 'outlineWidth', 'outlineColor');
    t.ok('the selected card is outlined in the highlight colour', selected && selected.outlineStyle === 'solid' && parseFloat(selected.outlineWidth) >= 2 && selected.outlineColor === (await system('Highlight')));

    const idle = await style('.tgl[data-flag=blocked]', null, 'borderTopColor', 'borderTopWidth');
    await page.click('.tgl[data-flag=blocked]');
    const pressed = await style('.tgl[data-flag=blocked]', null, 'borderTopColor', 'borderTopWidth');
    t.ok('a pressed chip differs from a resting one by more than its fill: its border is thicker', parseFloat(pressed.borderTopWidth) > parseFloat(idle.borderTopWidth));
    await page.click('.tgl[data-flag=blocked]');

    await page.evaluate(() => document.querySelector('.col').classList.add('is-over'));
    const over = await style('.col.is-over', null, 'outlineStyle', 'outlineWidth');
    t.ok('the lane a card is held over is outlined', over.outlineStyle === 'solid' && parseFloat(over.outlineWidth) >= 2);
    await page.evaluate(() => document.querySelector('.col').classList.remove('is-over'));

    await page.click('.btn.new');
    const composer = await style('.composer', null, 'outlineStyle', 'outlineWidth');
    t.ok('the new-card box is outlined', composer.outlineStyle === 'solid' && parseFloat(composer.outlineWidth) >= 2);
    await page.keyboard.press('Escape');

    await page.click('.tgl[data-filter=label]');
    const search = await style('.menu-search', null, 'outlineStyle', 'outlineWidth');
    t.ok('the search box of a list shows where the focus is', search.outlineStyle === 'solid' && parseFloat(search.outlineWidth) >= 2);
    await page.click('.menu [role=option]');
    const marks = await page.evaluate(() => [...document.querySelectorAll('.menu [role=option]')].slice(0, 3).map((row) => {
        const icon = row.querySelector('.box .i');
        const style = getComputedStyle(icon);
        return { on: row.classList.contains('is-on'), shown: style.visibility !== 'hidden' && style.opacity !== '0' };
    }));
    t.ok('a list shows a check only on the rows that are picked', marks[0].on && marks[0].shown && marks.slice(1).every((mark) => !mark.on && !mark.shown));
    await page.keyboard.press('Escape');
    await page.click('.toolbar .clear');

    const dot = await page.evaluate(() => {
        const pill = document.querySelector('.live');
        pill.classList.add('is-off');
        const colours = [getComputedStyle(pill.querySelector('i')).backgroundColor, getComputedStyle(document.querySelector('.bar')).backgroundColor];
        pill.classList.remove('is-off');
        return colours;
    });
    t.ok('the dot of the connection pill is drawn when the connection is lost too', dot[0] !== dot[1] && dot[0] !== 'rgba(0, 0, 0, 0)');
    await t.shot(page, 'forced-board');
    await page.locator(`.card[data-id="${blocked}"] .c-title`).click();
    await page.waitForSelector('.panel.is-top .d-title');
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    await page.keyboard.press('Tab');
    const title = await style('.panel.is-top .d-title', null, 'outlineStyle', 'outlineWidth');
    t.ok('the name of a card shows the focus', title.outlineStyle === 'solid' && parseFloat(title.outlineWidth) >= 2);
    await t.shot(page, 'forced-card');

    await page.goto(t.url, { waitUntil: 'networkidle' });
    const seg = await page.evaluate(() => {
        const el = document.querySelector('.dist .seg');
        const tile = document.querySelector('.board-tile');
        return el && tile ? { seg: getComputedStyle(el).backgroundColor, tile: getComputedStyle(tile).backgroundColor } : null;
    });
    t.ok('the bar of a board tile is drawn in a colour other than the tile\'s', !!seg && seg.seg !== seg.tile && seg.seg !== 'rgba(0, 0, 0, 0)');
    await t.shot(page, 'forced-boards');
};
