// Cards that link to cards: a link opens the other card on top, to the right, at any depth; Alt+W closes the last one opened.
export const seed = 'rich';

export default async (t) => {
    const { nightly, job, schema } = t.seed.ids;
    const page = await t.open({ w: 1440, h: 900 });
    const panels = () => page.locator('.panel').count();
    const top = () => page.locator('.panel.is-top');
    const url = () => new URL(page.url());
    const settle = () => page.waitForTimeout(500);

    // a card that has just opened has the focus on itself: Esc closes it without first having to leave a field
    await page.goto(t.url + `/cards/${schema}`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    await page.keyboard.press('Escape');
    await settle();
    t.ok('Esc closes a card that has just opened', (await panels()) === 0 || (await page.locator('.drawer[hidden]').count()) === 1);

    // a dependency is a link to its card
    await page.goto(t.url + `/cards/${job}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.panel.is-top .d-title');
    t.ok('a dependency shows as a link to its card', (await top().locator('.prop:has-text("Depends on") .chip a').innerText()) === schema);
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    t.ok('following it opens that card on top of this one', (await panels()) === 2 && url().pathname.endsWith('/cards/' + schema) && url().searchParams.get('from') === job);
    await page.goto(t.url + `/cards/${job}?from=${schema}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.panel.is-top .d-title');
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    t.ok('a card that is already open is returned to, not opened twice', (await panels()) === 1 && url().pathname.endsWith('/cards/' + schema));

    // three deep
    await page.goto(t.url + `/cards/${nightly}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.panel.is-top .d-title');
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    t.ok('the stack goes as deep as the links do', (await panels()) === 3 && url().searchParams.get('from') === `${nightly},${job}` && url().pathname.endsWith('/cards/' + schema));
    t.ok('only the top one takes part: the ones under it are inert', (await page.locator('.panel.is-under .panel-inner[inert]').count()) === 2 && (await page.locator('.panel.is-top .panel-inner[inert]').count()) === 0);

    // the stack shows which one is on top
    const geometry = await page.evaluate(() => {
        const box = (el) => el.getBoundingClientRect();
        const panels = [...document.querySelectorAll('.panel')];
        const strips = [...document.querySelectorAll('.panel.is-under .panel-strip')].map((el) => Math.round(box(el).width));
        return {
            strips,
            top: panels.at(-1).getBoundingClientRect().right,
            shadow: getComputedStyle(panels.at(-1)).boxShadow !== 'none',
            lefts: panels.map((el) => Math.round(box(el).left)),
            board: box(document.querySelector('.board')).right,
            drawer: box(document.querySelector('.drawer')).left,
            width: innerWidth,
        };
    });
    t.ok('each card under the top one peeks out as a strip', geometry.strips.length === 2 && geometry.strips.every((w) => w === 32));
    t.ok('the panels are laid over each other, one strip apart', geometry.lefts[1] - geometry.lefts[0] === 32 && geometry.lefts[2] - geometry.lefts[1] === 32 && geometry.top === geometry.width);
    t.ok('the top one throws a shadow on the ones below', geometry.shadow);
    t.ok('the board keeps its own room beside the stack', geometry.board <= geometry.drawer + 1);
    t.ok('beside the board there is no scrim', (await page.locator('.scrim:visible').count()) === 0);
    t.ok('a strip names the card under it', (await page.locator('.panel.is-under .panel-strip').first().getAttribute('aria-label')).includes(nightly));
    await t.shot(page, 'stack');

    // closing: Alt+W closes the last one opened, first, even while typing
    await page.locator('.panel.is-top .d-title').focus();
    await page.keyboard.press('Alt+w');
    await settle();
    t.ok('Alt+W closes the card on top', (await panels()) === 2 && url().pathname.endsWith('/cards/' + job) && url().searchParams.get('from') === nightly);
    await page.keyboard.press('Alt+w');
    await settle();
    t.ok('and then the one below it', (await panels()) === 1 && url().pathname.endsWith('/cards/' + nightly) && !url().searchParams.has('from'));
    await page.keyboard.press('Alt+w');
    await settle();
    t.ok('the last one closes the drawer and lands on the board', url().pathname.endsWith('/project/work') && (await page.locator('.drawer[hidden]').count()) === 1);
    await page.keyboard.press('Alt+w');
    t.ok('Alt+W with nothing open does nothing', url().pathname.endsWith('/project/work'));

    // a strip goes back to its card and closes what was opened after it
    await page.goto(t.url + `/cards/${nightly}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.panel.is-top .d-title');
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    await page.locator('.panel.is-under .panel-strip').first().click();
    await settle();
    t.ok('a strip returns to its card', (await panels()) === 1 && url().pathname.endsWith('/cards/' + nightly));

    // the history: Back closes one level, Forward opens it again, a reload keeps the stack
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    await top().locator('.prop:has-text("Depends on") .chip a').click();
    await settle();
    await page.goBack();
    await settle();
    t.ok('Back closes the card on top', (await panels()) === 2 && url().pathname.endsWith('/cards/' + job));
    await page.goForward();
    await settle();
    t.ok('Forward opens it again', (await panels()) === 3);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForSelector('.panel.is-top .d-title');
    t.ok('a reload keeps the whole stack', (await panels()) === 3 && (await top().locator('.d-title').inputValue()).length > 0);

    // filters and the stack live in the same address
    await page.click('.tgl[data-filter=priority]');
    await page.click('.menu [role=option]:has-text("high")');
    await page.keyboard.press('Escape');
    t.ok('changing a filter keeps the stack in the address', url().searchParams.get('p') === 'high' && url().searchParams.get('from') === `${nightly},${job}`);
    await page.locator('.panel.is-top .d-id').focus();
    await page.keyboard.press('Escape');
    await settle();
    t.ok('Esc closes the card on top before it clears a filter', (await panels()) === 2 && url().searchParams.get('p') === 'high');

    // a card picked on the board starts over
    await page.click('.toolbar .clear');
    await page.click(`.card[data-id="${job}"] .c-title`);
    await settle();
    t.ok('a click on the board opens that card alone', (await panels()) === 1 && url().pathname.endsWith('/cards/' + job) && !url().searchParams.has('from'));

    // the first card fades in beside the board
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    const fades = page.waitForFunction(() => {
        const panel = document.querySelector('.panel.is-top');
        return !!panel && panel.getAnimations().some((a) => a.effect.getKeyframes().some((k) => String(k.opacity) === '0'));
    }, null, { timeout: 1500, polling: 16 }).then(() => true, () => false);
    await page.click(`.card[data-id="${job}"] .c-title`);
    t.ok('the first card fades in beside the board', await fades);

    // a deep stack gives each strip less room, but never less than 24px
    const ids = [...new Set(Object.values(t.seed.ids).filter((id) => typeof id === 'string'))].slice(0, 9);
    await page.goto(t.url + `/cards/${ids.at(-1)}?from=${ids.slice(0, -1).join(',')}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.panel.is-top .d-title');
    const deep = await page.evaluate(() => [...document.querySelectorAll('.panel.is-under .panel-strip')].map((el) => Math.round(el.getBoundingClientRect().width)));
    t.ok('nine cards deep the strips narrow to 24px and no further', deep.length === 8 && deep.every((w) => w === 24));

    // below 1400px the stack overlays the board: 520px wide, over a scrim, and it slides out instead of vanishing
    const narrow = await t.open({ w: 1200, h: 800 });
    await narrow.goto(t.url + `/cards/${nightly}`, { waitUntil: 'networkidle' });
    await narrow.waitForSelector('.panel.is-top .d-title');
    t.ok('below 1400px the card is 520px wide', (await narrow.locator('.panel.is-top').evaluate((el) => Math.round(el.getBoundingClientRect().width))) === 520);
    t.ok('and there is a scrim over the board', (await narrow.locator('.scrim:visible').count()) === 1);
    await narrow.keyboard.press('Alt+w');
    t.ok('closing it slides it out, the card is still there to be seen going', (await narrow.evaluate(() => getComputedStyle(document.querySelector('.drawer')).visibility)) === 'visible' && (await narrow.locator('.panel').count()) === 1);
    const gone = await narrow.waitForFunction(() => getComputedStyle(document.querySelector('.drawer')).visibility === 'hidden', null, { timeout: 2000 }).then(() => true, () => false);
    t.ok('and afterwards it is gone', gone && (await narrow.locator('.scrim:visible').count()) === 0);

    // the same on a narrow screen: full width, one at a time, a way back that names the card underneath
    const phone = await t.open({ w: 390, h: 844, touch: true });
    await phone.goto(t.url + `/cards/${nightly}`, { waitUntil: 'networkidle' });
    await phone.waitForSelector('.panel.is-top .d-title');
    await phone.locator('.panel.is-top .prop:has-text("Depends on") .chip a').click();
    await phone.waitForTimeout(500);
    t.ok('on a phone the top card covers the screen and no strips show', (await phone.locator('.panel.is-under .panel-strip:visible').count()) === 0 && (await phone.locator('.panel.is-top').evaluate((el) => Math.round(el.getBoundingClientRect().width))) === 390);
    t.ok('and a back button names the card underneath', (await phone.locator('.panel.is-top .d-back').innerText()).trim() === nightly);
    await t.shot(phone, 'stack-phone');
    await phone.locator('.panel.is-top .d-back').click();
    await phone.waitForTimeout(500);
    t.ok('which goes back to it', (await phone.locator('.panel').count()) === 1);

    // a single card on a phone has its way back too: to the board
    t.ok('a single card has a back button that says Board', (await phone.locator('.panel.is-top .d-back').innerText()).trim() === 'Board' && (await phone.locator('.panel.is-top .d-back').getAttribute('aria-label')) === 'Back to the board');
    await phone.locator('.panel.is-top .d-back').click();
    await phone.waitForTimeout(500);
    t.ok('and it leads there', new URL(phone.url()).pathname.endsWith('/project/work') && (await phone.locator('.drawer[hidden]').count()) === 1);
};
