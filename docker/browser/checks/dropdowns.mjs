// Dropdowns: short lists are plain, lists of more than five choices have a search box, and the keyboard works in both.
import { openSwitcher, pick, valueOf } from '../lib.mjs';

export const seed = 'rich';

export default async (t) => {
    const { a, b, c } = t.seed.ids;
    const page = await t.open();
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });

    // four choices: no search box, the arrows and Enter pick
    await page.click('.tgl[data-filter=priority]');
    t.ok('a filter chip says it opens a list of choices and that it is open', (await page.getAttribute('.tgl[data-filter=priority]', 'aria-haspopup')) === 'listbox' && (await page.getAttribute('.tgl[data-filter=priority]', 'aria-expanded')) === 'true');
    t.ok('a short list has no search box', (await page.locator('.menu .menu-search-input').count()) === 0);
    t.ok('and opens with the list focused', (await page.evaluate(() => document.activeElement.classList.contains('menu-list'))));
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    t.ok('arrows and Enter pick a row without closing a multiple choice', (await page.locator('.tgl[data-filter=priority].is-active').count()) === 1 && (await page.locator('.menu').count()) === 1);
    await page.keyboard.press('Escape');

    // more than five: a search box that has the focus, narrows the list and picks with Enter
    await page.click('.tgl[data-filter=label]');
    t.ok('a list of more than five choices has a search box', (await page.locator('.menu .menu-search-input').count()) === 1);
    t.ok('which has the focus', (await page.evaluate(() => document.activeElement.classList.contains('menu-search-input'))));
    const all = await page.locator('.menu [role=option]').count();
    await page.keyboard.type('bill');
    const left = await page.locator('.menu [role=option]:not([hidden])').allTextContents();
    t.ok('typing narrows the list', all > 5 && left.length === 1 && left[0].trim() === 'billing');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    t.ok('Enter picks the highlighted match', (await page.locator('.tgl[data-filter=label]').innerText()).includes('billing') && (await page.locator('.card:visible').count()) === 1);
    await page.fill('.menu .menu-search-input', 'zzzz');
    t.ok('a search without matches says so', (await page.locator('.menu-empty:visible').count()) === 1 && (await page.locator('.menu [role=option]:not([hidden])').count()) === 0);
    await page.keyboard.press('Escape');
    t.ok('Esc closes it and gives the focus back to its chip', (await page.locator('.menu').count()) === 0 && (await page.evaluate(() => document.activeElement.dataset.filter)) === 'label');
    await page.click('.toolbar .clear');

    // the board switcher searches once there are more than five boards
    await openSwitcher(page);
    t.ok('three boards need no search', (await page.locator('.menu .menu-search-input').count()) === 0);
    t.ok('the switcher is 280px wide at least', (await page.locator('.menu').evaluate((el) => el.offsetWidth)) >= 280);
    const kinds = await page.locator('.menu [role=menuitem]').evaluateAll((rows) => Object.fromEntries(rows.map((row) => [row.querySelector('.grow').firstChild.textContent.trim(), row.querySelector('.sub')?.textContent.trim() ?? null])));
    t.ok('a board is followed by its kind only when the title does not say it', kinds.Infra === 'Work' && kinds.Work === null && kinds.Decisions === null);
    const numbered = await page.locator('.menu [role=menuitem]').evaluateAll((rows) => rows.map((row) => [row.querySelector('.grow').firstChild.textContent.trim(), row.querySelector('kbd.num:not([hidden])')?.textContent ?? null]));
    t.ok('the boards are numbered in the order shown, and the row for all boards has its own key instead', numbered.slice(0, -1).every(([, number], i) => number === String(i + 1)) && numbered.at(-1)[1] === null);
    t.ok('the row for all boards shows its key', (await page.locator('.menu-item:has-text("All boards") kbd').innerText()).trim() === 'B');
    await page.keyboard.press('b');
    await page.waitForTimeout(500);
    t.ok('and b, pressed in the open switcher, goes there', new URL(page.url()).pathname === '/kanban' && (await page.locator('.board-tile').count()) === 3);
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await openSwitcher(page);
    await page.keyboard.press(numbered.find(([name]) => name === 'Decisions')[1]);
    await page.waitForTimeout(500);
    t.ok('the digit of a board goes to it', page.url().endsWith('/project/decisions'));
    await page.goto(t.url, { waitUntil: 'networkidle' });
    t.ok('a board of decisions has a check in a circle on its tile', ((await page.locator('.board-tile:has(h3:text-is("Decisions")) .tile-i path').getAttribute('d')) || '').startsWith('M8 13.5a5.5'));
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await openSwitcher(page);
    await page.keyboard.press('Escape');
    await page.goto(t.url, { waitUntil: 'networkidle' });
    const tiles = await page.locator('.board-tile').evaluateAll((all) => Object.fromEntries(all.map((tile) => [tile.querySelector('h3').textContent, tile.querySelector('.kind')?.textContent ?? null])));
    t.ok('and so is its tile on the index', tiles.Infra === 'Work' && tiles.Work === null && tiles.Decisions === null);
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    for (const name of ['one', 'two', 'three', 'four']) t.cli(['board', `project/${name}`, `Extra ${name}`]);
    await openSwitcher(page);
    t.ok('seven boards do', (await page.locator('.menu .menu-search-input').count()) === 1);
    t.ok('and then the letters are for the search: the row shows no key', (await page.locator('.menu-item:has-text("All boards") kbd').count()) === 0);
    const seventh = await page.locator('.menu [role=menuitem]').evaluateAll((rows) => rows.map((row) => [row.querySelector('.grow').firstChild.textContent.trim(), row.querySelector('kbd.num:not([hidden])')?.textContent ?? null]));
    t.ok('the boards are numbered here too, and the row for all boards is not', seventh.slice(0, -1).every(([, number], i) => number === String(i + 1)) && seventh.at(-1)[1] === null);
    await page.keyboard.type('e');
    await page.keyboard.press('2');
    t.ok('a digit typed after a letter belongs to the search', (await page.inputValue('.menu .menu-search-input')) === 'e2' && new URL(page.url()).pathname.endsWith('/project/work'));
    await page.fill('.menu .menu-search-input', '');
    await page.keyboard.press('3');
    await page.waitForTimeout(500);
    t.ok('but while the search is empty a digit picks the board with that number', (await page.locator('.switcher .sw-name').innerText()) === seventh[2][0] && (await page.locator('.menu').count()) === 0);
    await openSwitcher(page);
    await page.keyboard.type('infra');
    t.ok('and the search finds a board by its epic too', (await page.locator('.menu [role=menuitem]:not([hidden])').allTextContents()).length === 1);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(600);
    t.ok('Enter goes there', page.url().endsWith('/platform/infra'));

    // the drawer's dropdowns: keyboard from the button, values follow the choice
    await page.goto(t.url + `/cards/${a}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.drawer:not([hidden]) button[data-field=priority]');
    await page.focus('.drawer button[data-field=priority]');
    await page.keyboard.press('ArrowDown');
    t.ok('ArrowDown opens a dropdown on its button', (await page.locator('.menu [role=option]').count()) === 4 && (await page.getAttribute('.drawer button[data-field=priority]', 'aria-expanded')) === 'true');
    await page.keyboard.type('u');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(700);
    t.ok('typing a letter jumps to the row that starts with it, Enter chooses it', (await valueOf(page, 'priority')) === 'urgent' && (await page.locator('.menu').count()) === 0);
    t.ok('and the focus is back on the button', (await page.evaluate(() => document.activeElement.dataset.field)) === 'priority');
    await pick(page, 'priority', 'low');
    await page.waitForTimeout(700);
    t.ok('the mouse chooses too', (await valueOf(page, 'priority')) === 'low');
    await page.click('.drawer button[data-field=priority]');
    t.ok('the chosen value is marked in the list', (await page.locator('.menu [role=option][aria-selected=true]').innerText()).trim() === 'low');
    await t.shot(page, 'dropdown');

    // a letter typed in a short list belongs to the list: none of the keys is also a board shortcut
    await page.keyboard.press('Escape');
    const writes = [];
    page.on('request', (request) => { if (request.method() !== 'GET') writes.push(request.method() + ' ' + request.url()); });
    const selected = () => page.evaluate(() => document.querySelector('.card.is-selected')?.dataset.id ?? null);
    const theme = () => page.evaluate(() => document.documentElement.dataset.theme ?? '');
    const start = { selected: await selected(), theme: await theme() };
    await page.focus('.drawer button[data-field=priority]');
    await page.keyboard.press('ArrowDown');
    for (const [key, row] of [['h', 'high'], ['n', 'normal'], ['l', 'low']]) {
        await page.waitForTimeout(750);
        await page.keyboard.press(key);
        t.ok(`typing ${key} in the priority list highlights ${row}`, (await page.evaluate(() => document.querySelector('.menu [role=option].is-active')?.textContent.trim() ?? null)) === row);
    }
    for (const key of ['ArrowRight', 'ArrowLeft', 'j', 'k', 'p', 'b', 't', 'm', '?']) await page.keyboard.press(key);
    t.ok('the other keys leave the board alone: same selection, theme, no composer, help or second menu', (await selected()) === start.selected && (await theme()) === start.theme
        && (await page.locator('.composer').count()) === 0 && (await page.locator('dialog.help[open]').count()) === 0 && (await page.locator('.menu [role=option]').count()) === 4);
    t.ok('and nothing was written', writes.length === 0);
    await page.keyboard.press('Escape');
    t.ok('Esc still closes the list', (await page.locator('.menu').count()) === 0);

    // a board with many labels: the list shows the first twenty and says there are more; the search finds every one
    const tags = Array.from({ length: 25 }, (_, i) => `tag${String(i).padStart(2, '0')}`);
    t.cli(['set', a, `labels=${tags.slice(0, 8).map((tag) => '+' + tag).join(',')}`]);
    t.cli(['set', b, `labels=${tags.slice(8, 17).map((tag) => '+' + tag).join(',')}`]);
    t.cli(['set', c, `labels=${[...tags.slice(17), 'zz-last'].map((tag) => '+' + tag).join(',')}`]);
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.click('.tgl[data-filter=label]');
    const visible = () => page.locator('.menu [role=option]:not([hidden])').count();
    t.ok('the label list shows twenty labels', (await visible()) === 20);
    t.ok('and says how many more there are', /^\d+ more/.test(await page.locator('.menu .menu-more:visible').innerText()));
    await page.keyboard.type('zz-last');
    t.ok('the search reaches the ones beyond the twentieth', (await visible()) === 1 && (await page.locator('.menu-more:visible').count()) === 0);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    t.ok('and picks it', (await page.locator('.tgl[data-filter=label]').innerText()).includes('zz-last') && (await page.locator('.card:visible').count()) === 1);
    await page.fill('.menu .menu-search-input', '');
    t.ok('an empty search is back to twenty, and a label that is picked stays in view', (await visible()) === 21 && (await page.locator('.menu [role=option][aria-selected=true]:not([hidden])').innerText()).trim() === 'zz-last');
    await page.click('.tgl[data-filter=label]');
    t.ok('pressing the chip again closes the list, although picking redrew the chips', (await page.locator('.menu').count()) === 0);
    await page.click('.tgl[data-filter=label]');
    await page.fill('.menu .menu-search-input', 'zzzz');
    await page.click('.menu-empty');
    t.ok('pressing a part of the list that cannot take the focus leaves it open, with the search box in use', (await page.locator('.menu').count()) === 1 && (await page.evaluate(() => document.activeElement.classList.contains('menu-search-input'))));
    await page.keyboard.press('Escape');
    await page.click('.toolbar .clear');
};
