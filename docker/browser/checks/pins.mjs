// Quick boards: Shift+1..9 puts the board you are on in a slot, Alt+1..9 goes to it from anywhere, and the slots sit in the middle of the bar.
export const seed = 'rich';

export default async (t) => {
    const page = await t.open({ w: 1440, h: 900 });
    const pins = () => page.locator('.pins .pin');
    const shown = () => pins().evaluateAll((all) => all.map((el) => `${el.querySelector('kbd').textContent} ${el.querySelector('.pin-name').textContent}`).join(', '));
    const path = () => new URL(page.url()).pathname;
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });

    t.ok('nothing is pinned to begin with', (await pins().count()) === 0);
    await page.keyboard.press('Shift+1');
    t.ok('Shift+1 puts the board you are on in slot 1, and the bar shows it', (await shown()) === '1 Work' && (await pins().first().getAttribute('aria-current')) === 'page');
    t.ok('with a message saying what happened', (await page.locator('.toast.ok').innerText()).includes('Work is on Alt+1'));

    await page.goto(t.url + '/project/decisions', { waitUntil: 'networkidle' });
    await page.keyboard.press('Shift+2');
    t.ok('another board goes in another slot, in slot order', (await shown()) === '1 Work, 2 Decisions' && (await pins().nth(1).getAttribute('aria-current')) === 'page' && (await pins().first().getAttribute('aria-current')) === null);

    await page.keyboard.press('Alt+1');
    await page.waitForTimeout(500);
    t.ok('Alt+1 goes to the board in slot 1', path().endsWith('/project/work'));
    await page.keyboard.press('Alt+2');
    await page.waitForTimeout(500);
    t.ok('Alt+2 to the one in slot 2', path().endsWith('/project/decisions'));
    await page.keyboard.press('/');
    await page.keyboard.press('Alt+1');
    await page.waitForTimeout(500);
    t.ok('also while typing in the search box, where Shift+2 is only a character', path().endsWith('/project/work'));
    await page.fill('input[type=search]', '');
    await page.keyboard.press('Shift+2');
    t.ok('Shift+2 in the search box types a character and pins nothing', (await page.inputValue('input[type=search]')).length === 1 && (await shown()) === '1 Work, 2 Decisions');
    await page.keyboard.press('Escape');

    await page.goto(t.url, { waitUntil: 'networkidle' });
    t.ok('the slots are on the boards page too, and survive a reload', (await shown()) === '1 Work, 2 Decisions');
    await page.keyboard.press('Alt+2');
    await page.waitForTimeout(500);
    t.ok('Alt+2 goes from the boards page to slot 2', path().endsWith('/project/decisions'));
    await page.goto(t.url, { waitUntil: 'networkidle' });
    await page.keyboard.press('Shift+3');
    t.ok('Shift+3 on the boards page has no board to pin and says so', (await page.locator('.toast').innerText()).includes('Open a board first') && (await shown()) === '1 Work, 2 Decisions');

    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.keyboard.press('Alt+5');
    t.ok('an empty slot says how to fill it and stays where it is', (await page.locator('.toast').last().innerText()).includes('Shift+5') && path().endsWith('/project/work'));
    await page.keyboard.press('Shift+3');
    t.ok('a board is in one slot only: pinning Work again moves it', (await shown()) === '2 Decisions, 3 Work');
    await page.keyboard.press('Shift+3');
    t.ok('the same key again takes the board off', (await shown()) === '2 Decisions');

    // in the middle of the bar, where they stay: whichever board is open, however long its name, whatever else the bar holds
    await page.keyboard.press('Shift+1');
    await page.goto(t.url + '/platform/infra', { waitUntil: 'networkidle' });
    await page.keyboard.press('Shift+3');
    t.cli(['board', 'platform/cost', 'Quarterly reliability and cost review across every region']);
    const spot = () => page.evaluate(async () => {
        await document.fonts.ready;
        const rect = (selector) => { const el = document.querySelector(selector); return el && el.checkVisibility() ? el.getBoundingClientRect() : null; };
        const chips = [...document.querySelectorAll('.pin')].map((el) => el.getBoundingClientRect());
        const [bar, brand, switcher, name, end, search] = ['.bar', '.brand', '.switcher', '.switcher .sw-name', '.bar-end', '.search'].map(rect);
        const button = document.querySelector('.switcher');
        const label = button.querySelector('.sw-name');
        return {
            count: chips.length, left: Math.min(...chips.map((c) => c.left)), right: Math.max(...chips.map((c) => c.right)), middle: (bar.left + bar.right) / 2,
            brandEnd: brand.right, nameStart: switcher.left, nameEnd: switcher.right, buttonWidth: switcher.width, spill: button.scrollWidth - button.clientWidth,
            // how much of the name shows when it is cut short (a name that fits is whole)
            label: label.scrollWidth > label.clientWidth + 1 ? name.width : Infinity,
            endStart: end.left, searchStart: search && search.left, wide: document.querySelector('.bar').scrollWidth - document.querySelector('.bar').clientWidth,
            icons: Math.min(...[...document.querySelectorAll('.bar-end .icon-btn')].map((el) => el.getBoundingClientRect().width)),
            thinnest: Math.min(...chips.map((c) => c.width)),
        };
    });
    const whole = (rows) => rows.every((s) => s.spill <= 0.5 && s.label >= 60 && s.icons >= 31.5 && s.wide <= 0.5 && s.brandEnd <= s.nameStart + 0.5);
    for (const width of [1180, 1000, 800, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const at = [];
        for (const path of ['/project/work', '/project/decisions', '/platform/infra', '/platform/cost', '']) {
            await page.goto(t.url + path, { waitUntil: 'networkidle' });
            at.push(await spot());
        }
        const names = at.filter((s) => s.searchStart !== null).map((s) => s.buttonWidth);
        t.ok(`the board names on the bar are of very different lengths (${width}px)`, Math.max(...names) - Math.min(...names) > 100);
        const same = at.every((s) => s.count === 3 && Math.abs(s.left - at[0].left) <= 0.5 && Math.abs(s.right - at[0].right) <= 0.5);
        t.ok(`the slots stay where they are on every board and on the boards page (${width}px)`, same);
        if (!same) console.log(`      slots at ${at.map((s) => `${s.left.toFixed(1)}-${s.right.toFixed(1)}`).join(', ')}`);
        const long = at[3];
        t.ok(`a long board name never runs into the slots or the search box (${width}px)`, long.nameEnd <= long.left + 1 && long.right <= long.searchStart + 1);
        t.ok(`the board name stays readable and the buttons on the right keep their size (${width}px)`, whole(at));
        if (width === 1440) t.ok('they sit in the middle of the bar', Math.abs((at[0].left + at[0].right) / 2 - at[0].middle) <= 2);
    }
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    const resting = await spot();
    const narrow = (await page.locator('.search input').boundingBox()).width;
    await page.keyboard.press('/');
    await page.waitForTimeout(400);
    const typing = await spot();
    t.ok('the search box opens wider when it is used, and the slots do not move for it', (await page.locator('.search input').boundingBox()).width > narrow + 20 && Math.abs(typing.left - resting.left) <= 0.5);
    await page.keyboard.press('Escape');

    // nine slots with long names are more than the bar can show: the slots give way, never the board name, the search box or the buttons
    const refs = ['project/work', 'project/decisions', 'platform/infra', 'platform/cost'];
    for (let i = 1; i <= 5; i++) { t.cli(['board', `platform/team${i}`, `Team ${i} planning and delivery`]); refs.push(`platform/team${i}`); }
    await page.evaluate((all) => localStorage.setItem('kanban.pins', JSON.stringify(Object.fromEntries(all.map((ref, i) => [i + 1, ref])))), refs);
    for (const width of [1000, 1280, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const crowd = [];
        for (const path of ['/platform/cost', '']) {
            await page.goto(t.url + path, { waitUntil: 'networkidle' });
            crowd.push(await spot());
        }
        const [board, index] = crowd;
        t.ok(`nine slots do not cover the board name, the search box or the buttons, nor push the bar wider (${width}px)`,
            crowd.every((s) => s.count === 9 && s.nameEnd <= s.left + 0.5 && s.right <= s.endStart + 0.5) && whole(crowd) && board.label >= 60);
        t.ok(`and they are where they were on the boards page (${width}px)`, Math.abs(board.left - index.left) <= 0.5 && Math.abs(board.right - index.right) <= 0.5);
        if (width >= 1100) t.ok(`and each still shows its number and the start of its name (${width}px)`, board.thinnest >= 60);
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.evaluate(() => localStorage.setItem('kanban.pins', JSON.stringify({ 1: 'project/work', 2: 'project/decisions', 3: 'platform/infra' })));
    await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    await page.locator('.pins .pin', { hasText: 'Decisions' }).click();
    await page.waitForTimeout(500);
    t.ok('a slot in the bar is a link to its board', path().endsWith('/project/decisions'));
    await page.locator('.pins .pin', { hasText: 'Work' }).focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(500);
    t.ok('used from the keyboard, a slot keeps the focus when the bar is drawn again', path().endsWith('/project/work') && (await page.evaluate(() => document.activeElement.classList.contains('pin') && document.activeElement.textContent.includes('Work'))));
    await t.shot(page, 'pins');

    // a phone has no keyboard for them, and the bar has no room
    const phone = await t.open({ w: 390, h: 844, touch: true });
    await phone.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
    t.ok('the bar of a phone does not show them', (await phone.locator('.pins .pin:visible').count()) === 0);
};
