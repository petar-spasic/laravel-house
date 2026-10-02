// The board: search, filters, new cards, keyboard, drag and drop, live updates, themes.
export default async (t) => {
    const { a, b, c, d } = t.seed.ids;
    const page = await t.open();
    const inColumn = (stage, id) => page.locator(`.col[data-stage=${stage}] .card[data-id="${id}"]`).count();
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    t.ok('an empty lane says what belongs in it', await page.evaluate(() => getComputedStyle(document.querySelector('.col[data-stage=doing] .col-b'), '::before').content.includes('Started by agents')));

    await page.keyboard.press('/');
    await page.keyboard.type('login');
    await page.waitForTimeout(200);
    t.ok('search narrows the cards', (await page.locator('.card:visible').count()) === 1 && page.url().includes('q=login'));
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    t.ok('Esc clears the search', (await page.locator('.card:visible').count()) > 5);

    await page.click('.tgl[data-filter=priority]');
    await page.click('.menu [role=option]:has-text("urgent")');
    await page.waitForTimeout(200);
    t.ok('the priority filter narrows the cards', (await page.locator('.card:visible').count()) === 1 && (await page.locator('.tgl[data-filter=priority].is-active').count()) === 1);
    t.ok('a lane whose cards are all filtered out says so', await page.evaluate(() => getComputedStyle(document.querySelector('.col[data-stage=ready] .col-b'), '::before').content.includes('No matches')));
    t.ok('and a lane counts what shows out of what it holds', /^1 of \d+$/.test(await page.locator('.col[data-stage=backlog] .n').innerText()));
    t.ok('and says how many cards it shows', /^Showing 1 of \d+$/.test(await page.locator('.toolbar .result').innerText()));
    await page.keyboard.press('ArrowDown');
    t.ok('arrow keys move through a menu', (await page.locator('.menu .is-active').count()) === 1 && (await page.locator('.menu .is-active').innerText()).trim() === 'high');
    await page.keyboard.press('Escape');
    t.ok('Esc closes the menu and gives the focus back to its chip', (await page.locator('.menu').count()) === 0 && (await page.evaluate(() => document.activeElement.dataset.filter)) === 'priority');
    await page.click('.toolbar .clear');
    t.ok('Clear removes the filter and its summary', (await page.locator('.tgl.is-active').count()) === 0 && (await page.locator('.toolbar .clear:visible').count()) === 0);

    t.ok('quick filters count what needs attention', (await page.locator('.tgl[data-flag=blocked] .n').innerText()) === '1' && (await page.locator('.tgl[data-flag=agent][aria-disabled=true]').count()) === 1);
    await page.click('.tgl[data-flag=blocked]');
    await page.waitForTimeout(200);
    t.ok('a quick filter narrows the board and is in the URL', (await page.locator('.card:visible').count()) === 1 && page.url().includes('f=blocked') && (await page.getAttribute('.tgl[data-flag=blocked]', 'aria-pressed')) === 'true');
    await page.click('.tgl[data-flag=blocked]');
    await page.waitForTimeout(200);
    await page.focus('.tgl[data-flag=blocked]');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    t.ok('a chip toggled from the keyboard keeps the focus although the toolbar is drawn again', (await page.getAttribute('.tgl[data-flag=blocked]', 'aria-pressed')) === 'true' && (await page.evaluate(() => document.activeElement?.dataset.flag)) === 'blocked');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    t.ok('and toggled back', (await page.getAttribute('.tgl[data-flag=blocked]', 'aria-pressed')) === 'false' && (await page.evaluate(() => document.activeElement?.dataset.flag)) === 'blocked');

    await page.click('.col[data-stage=backlog] .add');
    await page.fill('.composer input[type=text]', 'Quick added card');
    await page.press('.composer input[type=text]', 'Enter');
    await page.waitForTimeout(700);
    t.ok('a card is created and the composer stays open', (await page.locator('.col[data-stage=backlog] .card', { hasText: 'Quick added card' }).count()) === 1 && (await page.locator('.composer').count()) === 1);
    await page.fill('.composer input[type=text]', 'Second quick card');
    await page.click('.composer button:has-text("Add")');
    await page.waitForTimeout(700);
    t.ok('the Add button adds a card too', (await page.locator('.col[data-stage=backlog] .card', { hasText: 'Second quick card' }).count()) === 1 && (await page.locator('.composer').count()) === 1);
    t.ok('and the composer says how to add, add and open, and close', /Enter.*adds.*Shift.*Enter.*adds and opens.*Esc.*closes/s.test(await page.locator('.composer .hint').innerText()));
    await page.keyboard.press('Escape');
    t.ok('Esc closes the composer', (await page.locator('.composer').count()) === 0);
    await page.keyboard.press('/');
    await page.keyboard.press('Escape');
    t.ok('Esc leaves an empty search box', await page.evaluate(() => document.activeElement.type !== 'search'));
    await page.keyboard.press('n');
    await page.keyboard.type('Kept title');
    await page.locator('.col[data-stage=ready] .c-title').first().click();
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    await page.locator('.panel.is-top textarea[aria-label="Note"]').focus();
    t.ok('the note field has the focus while the new-card box is open', (await page.evaluate(() => document.activeElement.tagName)) === 'TEXTAREA' && (await page.locator('.composer').count()) === 1);
    await page.keyboard.press('Escape');
    t.ok('Esc in a field of the card puts the field down and leaves the new-card box alone', (await page.evaluate(() => document.activeElement.tagName)) !== 'TEXTAREA' && (await page.locator('.composer input[type=text]').inputValue()) === 'Kept title');
    await page.keyboard.press('Escape');
    t.ok('and the next Esc closes the new-card box', (await page.locator('.composer').count()) === 0);
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.click('.btn.new');
    t.ok('the New button opens the composer', (await page.locator('.composer').count()) === 1);
    await page.fill('.composer input[type=text]', 'Opened at once');
    await page.press('.composer input[type=text]', 'Shift+Enter');
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'), null, { timeout: 5000 });
    t.ok('Shift+Enter adds the card and opens it, with the focus on the card and not in a field', (await page.locator('.panel.is-top .d-title').inputValue()) === 'Opened at once' && (await page.evaluate(() => !document.activeElement.matches('input, textarea'))));
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    await page.keyboard.press('Escape');
    t.ok('the bar says the connection is live', (await page.locator('.live-text').innerText()) === 'Live');
    const touched = await page.evaluate(() => new Promise((resolve) => {
        let count = 0;
        const watch = new MutationObserver((list) => { count += list.length; });
        watch.observe(document.querySelector('.live'), { childList: true, characterData: true, subtree: true, attributes: true });
        setTimeout(() => { watch.disconnect(); resolve(count); }, 4000);
    }));
    t.ok('the pill is left alone while the connection stays the same, so a screen reader does not repeat it', touched === 0);

    for (let i = 0; i < 12; i++) {
        await page.keyboard.press('j');
        if ((await page.locator('.card.is-selected .c-title').innerText()).startsWith('Chore')) break;
    }
    t.ok('j walks down the column', (await page.locator('.card.is-selected').count()) === 1);
    const selected = await page.locator('.card.is-selected').getAttribute('data-id');
    await page.keyboard.press('m');
    await page.waitForSelector('.menu');
    await page.click('.menu button:has-text("ready")');
    await page.waitForTimeout(800);
    t.ok('a card that fails the ready policy stays put and says why', (await inColumn('backlog', selected)) === 1 && (await page.locator('.toast.err', { hasText: 'R3' }).count()) === 1);

    // while a card is held, the lanes that take it are marked and the others recede
    await page.locator(`.card[data-id="${b}"]`).scrollIntoViewIfNeeded();
    const held = await page.locator(`.card[data-id="${b}"]`).boundingBox();
    await page.mouse.move(held.x + 30, held.y + 20);
    await page.mouse.down();
    await page.mouse.move(held.x + 60, held.y + 60, { steps: 4 });
    const ready = await page.locator('.col[data-stage=ready]').boundingBox();
    await page.mouse.move(ready.x + 60, ready.y + 200, { steps: 6 });
    await page.waitForTimeout(700);
    const feedback = await page.evaluate(() => ({
        board: document.querySelector('.board').classList.contains('is-dragging'),
        takes: [...document.querySelectorAll('.col.can-drop')].map((el) => el.dataset.stage).sort().join(),
        receding: getComputedStyle(document.querySelector('.col[data-stage=doing]')).opacity,
        source: document.querySelector('.col[data-stage=backlog]').classList.contains('is-source'),
        over: document.querySelector('.col[data-stage=ready]').classList.contains('is-over'),
    }));
    t.ok('holding a card marks the board and the lanes that take it', feedback.board && feedback.takes === 'dropped,ready' && feedback.source);
    t.ok('the lane under it is highlighted and the ones that cannot take it recede', feedback.over && feedback.receding === '0.5');
    await page.mouse.move(400, 6, { steps: 4 });
    await page.mouse.up();
    await page.waitForTimeout(150);
    t.ok('letting go outside a lane moves nothing and clears the marks', (await page.locator(`.col[data-stage=backlog] .card[data-id="${b}"]`).count()) === 1 && await page.evaluate(() => document.querySelectorAll('.is-dragging, .can-drop, .is-over, .is-source').length === 0));

    await page.evaluate(() => { document.querySelector('.board').classList.add('is-dragging'); document.querySelector('.col').classList.add('is-over', 'can-drop'); });
    await page.click('.tgl[data-flag=blocked]');
    await page.click('.tgl[data-flag=blocked]');
    t.ok('a redraw clears drag marks that were left behind', await page.evaluate(() => document.querySelectorAll('.is-dragging, .is-over, .can-drop, .is-source').length === 0));

    await page.dragAndDrop(`.card[data-id="${c}"]`, '.col[data-stage=dropped]');
    await page.waitForSelector('.menu input');
    await t.shot(page, 'drop-reason');
    await page.fill('.menu input', 'not now');
    await page.press('.menu input', 'Enter');
    await page.waitForTimeout(800);
    t.ok('dropping asks for a reason, then drops', (await inColumn('dropped', c)) === 1);

    await page.dragAndDrop(`.card[data-id="${d}"]`, '.col[data-stage=backlog]');
    await page.waitForTimeout(800);
    t.ok('a ready card can be dragged back', (await inColumn('backlog', d)) === 1);

    await page.evaluate(() => { document.querySelector('.col[data-stage=backlog] .col-b').scrollTop = 120; });
    await page.focus('.tgl[data-filter=priority]');
    t.cli(['set', a, 'blocked=waiting for the design']);
    t.cli(['set', b, 'title=Changed on the CLI']);
    await page.waitForTimeout(4500);
    t.ok('a count that changes under a focused chip does not take the focus away', (await page.locator('.tgl[data-flag=blocked] .n').innerText()) === '2' && (await page.evaluate(() => document.activeElement?.dataset.filter)) === 'priority');
    t.ok('a change made on the CLI arrives by itself', (await page.locator('.card', { hasText: 'Changed on the CLI' }).count()) === 1);
    t.ok('and the scroll position is kept', (await page.evaluate(() => document.querySelector('.col[data-stage=backlog] .col-b').scrollTop)) === 120);

    await page.click('.col[data-stage=dropped] .col-h');
    await page.waitForTimeout(200);
    t.ok('the dropped column expands', (await page.locator('.col[data-stage=dropped].is-collapsed').count()) === 0);

    const theme = () => page.evaluate(() => document.documentElement.dataset.theme || 'auto');
    await page.keyboard.press('t');
    t.ok('t switches a light page to dark at once', (await theme()) === 'dark');
    await t.shot(page, 'board-dark');
    await page.keyboard.press('t');
    t.ok('and the next press goes back to light, following the system again', (await theme()) === 'auto');
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.keyboard.press('t');
    t.ok('on a dark system the first press gives light', (await theme()) === 'light');
    await page.keyboard.press('t');
    t.ok('and the next one dark again', (await theme()) === 'auto');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.keyboard.press('?');
    t.ok('? opens the shortcuts', (await page.locator('dialog.help[open]').count()) === 1);
    t.ok('grouped, with the close-the-top-card key among them', (await page.locator('dialog.help .keys h3').allInnerTexts()).join() === 'Navigate,Cards,View' && (await page.locator('dialog.help dt kbd', { hasText: 'Alt+W' }).count()) === 1);
    await page.keyboard.press('Escape');

    await page.keyboard.press('b');
    await page.waitForSelector('.menu [role=menuitem]');
    t.ok('b opens the board switcher', (await page.getAttribute('.switcher', 'aria-expanded')) === 'true');
    await page.click('.switcher');
    t.ok('pressing the switcher again closes it', (await page.locator('.menu').count()) === 0 && (await page.getAttribute('.switcher', 'aria-expanded')) === 'false');
    await page.keyboard.press('b');
    await page.click('.menu [role=menuitem]:has-text("All boards")');
    await page.waitForTimeout(500);
    t.ok('the switcher leads to all boards', (await page.locator('.board-tile').count()) === 1);
    t.ok('each board shows how its cards are spread and how many there are', /\d+ cards?$/.test(await page.locator('.board-tile').first().locator('.tile-foot').innerText()) && (await page.locator('.board-tile .dist .seg').count()) >= 2);
    await t.shot(page, 'boards');
    await page.goBack();
    await page.waitForTimeout(500);
    t.ok('Back returns to the board', (await page.locator('.col').count()) === 6);
    const landing = await t.open();
    await landing.goto(t.url, { waitUntil: 'networkidle' });
    await landing.waitForTimeout(500);
    t.ok('a page that opens on the boards of a one-board project shows that board', new URL(landing.url()).pathname.endsWith('/project/work'));
    const retried = await t.open();
    await retried.route('**/_api/boards', (route) => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'The board is busy', details: [] }) }));
    await retried.goto(t.url, { waitUntil: 'networkidle' });
    await retried.unroute('**/_api/boards');
    await retried.click('.empty button:has-text("Retry")');
    await retried.waitForTimeout(800);
    t.ok('and so does Retry after that first load failed', new URL(retried.url()).pathname.endsWith('/project/work'));
};
