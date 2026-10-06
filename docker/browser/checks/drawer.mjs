// Everything editable in the card drawer saves as you go and shows on the board.
import { pick } from '../lib.mjs';

export default async (t) => {
    const { a } = t.seed.ids;
    const page = await t.open();
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const card = page.locator(`.card[data-id="${a}"]`);
    await card.locator('.c-title').click();
    await page.waitForSelector('.drawer:not([hidden]) .d-title');
    await page.waitForTimeout(400);
    t.ok('the card opens at its own URL', page.url().endsWith('/cards/' + a));
    t.ok('title shown', (await page.inputValue('.d-title')) === 'Add login page');
    t.ok('opening a card puts the focus on the card, not in a field', await page.evaluate(() => document.activeElement === document.querySelector('.panel.is-top .d-body')));
    await page.keyboard.press('Tab');
    t.ok('the first Tab moves into the name', await page.evaluate(() => document.activeElement.classList.contains('d-title')));
    await page.keyboard.press('Tab');
    t.ok('and the next one on to the stage', await page.evaluate(() => document.activeElement.dataset.field === 'stage'));
    t.ok('the name looks editable', await page.evaluate(() => {
        const style = getComputedStyle(document.querySelector('.d-title'));
        return style.borderTopWidth === '1px' && style.borderTopColor !== 'rgba(0, 0, 0, 0)' && document.querySelector('.d-title').title === 'Click to rename' && !!document.querySelector('.d-title-box .d-edit');
    }));
    const pillMarks = await page.evaluate(() => {
        const pill = (field) => document.querySelector(`.panel.is-top button[data-field=${field}]`);
        const bars = pill('priority')?.querySelector('.prio-i');
        const onCard = document.querySelector('.card .prio-i.prio-high');
        return {
            stage: !!pill('stage')?.querySelector('.pill-mark .stage-i'),
            lit: bars ? bars.querySelectorAll('.solid').length : 0,
            same: !!bars && !!onCard && getComputedStyle(bars).color === getComputedStyle(onCard).color,
            quiet: [pill('stage'), pill('priority')].every((el) => !!el && [...el.querySelectorAll('.pill-mark svg')].every((icon) => icon.getAttribute('aria-hidden') === 'true' && !icon.hasAttribute('aria-label'))),
            type: !!pill('type')?.querySelector('.pill-mark'),
        };
    });
    t.ok('the stage pill carries the mark of its stage', pillMarks.stage);
    const markGap = await page.evaluate(() => {
        const pill = document.querySelector('.panel.is-top button[data-field=stage]');
        const icon = pill.querySelector('.pill-mark svg').getBoundingClientRect();
        const words = pill.querySelector('.pill-value').getBoundingClientRect();
        return Math.abs(icon.top + icon.height / 2 - (words.top + words.height / 2));
    });
    t.ok('and the mark sits level with the words', markGap <= 1);
    t.ok('and the priority pill the bars of its level, in the colour the card gives them', pillMarks.lit === 3 && pillMarks.same);
    t.ok('the marks are for the eye only, the words already say it, and the type pill has none', pillMarks.quiet && !pillMarks.type);
    await page.click('.panel.is-top button[data-field=priority]');
    t.ok('the priority list shows the bars beside each level', (await page.locator('.menu [role=option] .prio-i').count()) === 4);
    await page.keyboard.press('Escape');
    await page.click('.panel.is-top button[data-field=stage]');
    t.ok('and the stage list the mark beside each stage', (await page.locator('.menu [role=option] .stage-i').count()) >= 2);
    await page.keyboard.press('Escape');
    await page.locator('.d-title').focus();
    await page.keyboard.press('Tab');
    t.ok('Tab goes on to the next field', await page.evaluate(() => document.activeElement.dataset.field === 'stage'));
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.type(' typed');
    await page.keyboard.press('Escape');
    t.ok('Esc drops what was typed in the name and keeps the card open', (await page.inputValue('.d-title')) === 'Add login page' && (await page.locator('.drawer:not([hidden])').count()) === 1);
    t.ok('Markdown rendered', (await page.locator('.drawer .md h2').count()) === 1);
    t.ok('acceptance criteria listed', (await page.locator('.drawer .check li').count()) === 3);
    t.ok('each has a remove button that can be seen without pointing at it', await page.evaluate(() => [...document.querySelectorAll('.panel.is-top .check li .rm')].length === 3 && [...document.querySelectorAll('.panel.is-top .check li .rm')].every((b) => getComputedStyle(b).opacity === '1' && b.getBoundingClientRect().width >= 24)));
    // every property's name sits level with the first control beside it, and the note's Post button is under its box
    const level = await page.evaluate(() => [...document.querySelectorAll('.panel.is-top .prop:not([hidden])')].map((row) => {
        const key = row.querySelector('.prop-k').getBoundingClientRect();
        const first = [...row.querySelectorAll('.prop-v input, .prop-v .chip, .prop-v .fact, .prop-v .tag, .prop-v button')].find((el) => el.getBoundingClientRect().height > 0);
        const box = first.getBoundingClientRect();
        return [row.querySelector('.prop-k').textContent, Math.abs(key.top + key.height / 2 - (box.top + box.height / 2))];
    }));
    t.ok('the property names are level with the controls beside them', level.length >= 2 && level.every(([, gap]) => gap <= 2));
    const foot = await page.evaluate(() => {
        const note = document.querySelector('.panel.is-top .d-foot textarea').getBoundingClientRect();
        const post = [...document.querySelectorAll('.panel.is-top .d-foot button')].find((b) => b.textContent === 'Post').getBoundingClientRect();
        return { below: post.top >= note.bottom, right: Math.abs(post.right - note.right) <= 1 };
    });
    t.ok('the Post button is under the note box, at its right edge', foot.below && foot.right);
    const noteBox = page.locator('.panel.is-top textarea[aria-label="Note"]');
    const resting = (await noteBox.boundingBox()).height;
    await noteBox.fill('one\ntwo\nthree\nfour');
    const four = (await noteBox.boundingBox()).height;
    await noteBox.fill('1\n2\n3\n4\n5\n6\n7\n8\n9\n10\n11\n12');
    const many = await noteBox.evaluate((el) => ({ height: el.getBoundingClientRect().height, scrolls: el.scrollHeight > el.clientHeight }));
    t.ok('the note box grows with what is typed', four > resting + 20);
    t.ok('and stops at six lines, then scrolls', many.height > four && many.height < resting * 3.5 && many.scrolls);
    await noteBox.fill('');
    t.ok('and shrinks back when it is emptied', Math.abs((await noteBox.boundingBox()).height - resting) <= 1);
    await page.setViewportSize({ width: 1200, height: 850 });
    await noteBox.fill(Array.from({ length: 40 }, () => 'word').join(' '));
    await page.setViewportSize({ width: 390, height: 850 });
    await page.waitForTimeout(200);
    t.ok('the note box follows its width: text that wraps further after a resize is not cut off', await noteBox.evaluate((el) => el.scrollHeight <= el.clientHeight + 1 || el.scrollHeight - el.clientHeight < 2));
    await page.setViewportSize({ width: 1400, height: 850 });
    await noteBox.fill('');
    const hint = (section) => page.locator(`.drawer .prop:has-text("${section}") .hint, .drawer .sec:has(h3:has-text("${section}")) .hint`).allInnerTexts().then((texts) => texts.join(' '));
    const acceptanceHint = await hint('Acceptance');
    t.ok('the acceptance box says Enter adds a criterion, that there can be several and how many', /press\s+Enter to add it/i.test(acceptanceHint) && /next one/.test(acceptanceHint) && /up to 24/.test(acceptanceHint) && /tick/i.test(acceptanceHint));
    const labelHint = await hint('Labels');
    t.ok('the label box says Enter or a comma adds one, and what a label looks like', /press\s+Enter/i.test(labelHint) && /comma/.test(labelHint) && /area:billing/.test(labelHint) && /up to 10/i.test(labelHint));
    t.ok('and the dependency box says what to type and what it means', /id or a title/.test(await hint('Depends on')) && /waits/.test(await hint('Depends on')));
    await page.focus('input[aria-label="New criterion"]');
    t.ok('a text box that has the focus gets a ring as well as its accent border', await page.evaluate(() => { const style = getComputedStyle(document.activeElement); return style.boxShadow !== 'none' && style.borderTopColor === getComputedStyle(document.querySelector('.btn.primary')).backgroundColor; }));
    const glides = await page.evaluate(() => ['.chip button', '.d-id', '.editable', '.icon-btn.rm'].map((selector) => [selector, document.querySelector(selector) ? getComputedStyle(document.querySelector(selector)).transitionDuration : null]));
    t.ok('the small controls answer the pointer over a moment, not at once', glides.every(([, duration]) => duration !== null && parseFloat(duration) > 0));
    await t.shot(page, 'drawer');

    // the header says when a write is under way and when it landed, in a live region, and stops saying so by itself
    t.ok('the save indicator is a polite live region', (await page.locator('.panel.is-top .d-saved').getAttribute('aria-live')) === 'polite');
    await page.evaluate(() => {
        window.__saved = [];
        const box = document.querySelector('.panel.is-top .d-saved');
        new MutationObserver(() => window.__saved.push(box.textContent.trim())).observe(box, { childList: true, subtree: true, characterData: true });
    });
    await page.locator('.drawer .check input[type=checkbox]').first().check();
    await page.waitForFunction(() => window.__saved.includes('Saved'), null, { timeout: 3000 });
    const said = await page.evaluate(() => window.__saved);
    t.ok('a write shows Saving… and then Saved', said.includes('Saving…') && said.indexOf('Saving…') < said.indexOf('Saved'));
    await page.waitForTimeout(2000);
    t.ok('and Saved goes away by itself', (await page.locator('.panel.is-top .d-saved').innerText()) === '');
    await page.waitForTimeout(600);
    t.ok('a tick is saved', (await page.locator('.drawer .check li.is-done').count()) === 1);
    t.ok('and shows on the tile', (await card.innerText()).includes('1/3'));

    await page.fill('input[aria-label="New criterion"]', 'Remember me works');
    await page.press('input[aria-label="New criterion"]', 'Enter');
    await page.waitForTimeout(600);
    t.ok('a criterion is added', (await page.locator('.drawer .check li').count()) === 4);

    await page.fill('.d-title', 'Add login page v2');
    await page.press('.d-title', 'Enter');
    await page.waitForTimeout(600);
    t.ok('the title is saved on the tile', (await card.locator('.c-title').innerText()) === 'Add login page v2');

    await pick(page, 'priority', 'urgent');
    await page.waitForTimeout(600);
    t.ok('priority is saved', (await page.locator(`.card[data-id="${a}"].p-urgent`).count()) === 1);

    await page.fill('input[aria-label="New label"]', 'qa');
    await page.press('input[aria-label="New label"]', 'Enter');
    await page.waitForTimeout(600);
    t.ok('a label is added', (await page.locator('.drawer .chip', { hasText: 'qa' }).count()) === 1);

    await page.click('.drawer button:has-text("Block…")');
    await page.fill('input[aria-label="Blocked reason"]', 'waiting for QA');
    await page.press('input[aria-label="Blocked reason"]', 'Enter');
    await page.waitForTimeout(600);
    t.ok('blocking shows the reason', (await page.locator('.drawer .banner').innerText()).includes('waiting for QA'));
    await page.click('.drawer button:has-text("Unblock")');
    await page.waitForTimeout(600);
    t.ok('unblocking clears it', (await page.locator('.drawer .banner').count()) === 0);

    await page.fill('.drawer textarea[aria-label="Note"]', 'Looks good <b>to me</b>');
    await page.press('.drawer textarea[aria-label="Note"]', 'Control+Enter');
    await page.waitForTimeout(600);
    t.ok('a note reaches the history, escaped', (await page.locator('.drawer .log q', { hasText: 'Looks good <b>to me</b>' }).count()) === 1);

    await page.click('.drawer .editable');
    await page.fill('.drawer textarea[aria-label="Description"]', '## Goal\n\nNew **body**');
    await page.press('.drawer textarea[aria-label="Description"]', 'Control+Enter');
    await page.waitForTimeout(600);
    t.ok('the description is saved', (await page.locator('.drawer .md strong', { hasText: 'body' }).count()) === 1);
    const times = await page.locator('.panel.is-top .log .when').evaluateAll((all) => all.map((el) => [el.textContent.trim(), el.getAttribute('title')]));
    t.ok('the history gives times as how long ago, with the date and time on hover', times.length > 0 && times.every(([text, title]) => /^(just now|\d+[smhd] ago)$/.test(text) && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(title)));
    await t.shot(page, 'drawer-edited');

    await pick(page, 'stage', 'planning');
    await page.waitForTimeout(700);
    t.ok('the stage select moves the card', (await page.locator(`.col[data-stage=planning] .card[data-id="${a}"]`).count()) === 1);
    await page.locator('.d-title').focus();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    t.ok('Esc closes the drawer', (await page.locator('.drawer[hidden]').count()) === 1 && page.url().endsWith('/work'));

    // on an address that is not a secure context (the LAN over http) there is no clipboard API: the id is copied through a selected field
    const lan = await t.open();
    await lan.addInitScript(() => Object.defineProperty(window, 'isSecureContext', { value: false }));
    await lan.goto(t.url + `/cards/${a}`, { waitUntil: 'networkidle' });
    await lan.waitForSelector('.panel.is-top .d-id');
    await lan.click('.panel.is-top .d-id');
    await lan.waitForSelector('.toast');
    t.ok('the id is copied where the clipboard API is missing', (await lan.locator('.toast.ok').innerText()) === 'Copied ' + a && (await lan.locator('textarea.vh').count()) === 0);

    // a card with no criteria says what they are for; Add and Enter both add, the box stays ready for the next
    const { c } = t.seed.ids;
    await page.click(`.card[data-id="${c}"] .c-title`);
    await page.waitForSelector('.drawer:not([hidden]) .d-title');
    await page.waitForTimeout(400);
    t.ok('a card without criteria says what a criterion is for', /done/.test(await page.locator('.drawer .empty-note').innerText()) && /planned/.test(await page.locator('.drawer .empty-note').innerText()));
    await page.fill('input[aria-label="New criterion"]', 'First check');
    await page.click('.drawer button:has-text("Add")');
    await page.waitForTimeout(700);
    t.ok('the Add button adds one', (await page.locator('.drawer .check li').count()) === 1 && (await page.locator('.drawer .empty-note:visible').count()) === 0);
    t.ok('and leaves the box ready for the next', await page.evaluate(() => document.activeElement.getAttribute('aria-label') === 'New criterion'));
    await page.keyboard.type('Second check');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(700);
    await page.keyboard.type('Third check');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(700);
    t.ok('Enter adds each one in turn', (await page.locator('.drawer .check li .text').allInnerTexts()).join('|') === 'First check|Second check|Third check');
    await page.fill('input[aria-label="New label"]', 'qa');
    await page.keyboard.press(',');
    await page.waitForTimeout(700);
    t.ok('a comma adds a label', (await page.locator('.drawer .chips .chip', { hasText: /^qa/ }).count()) === 1 && (await page.inputValue('input[aria-label="New label"]')) === '');
    await page.keyboard.type('needs-design');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(700);
    t.ok('and Enter adds the next', (await page.locator('.drawer .chips .chip', { hasText: /^needs-design/ }).count()) === 1);
    await page.locator('.drawer .sec:has(h3:has-text("Acceptance"))').scrollIntoViewIfNeeded();
    await t.shot(page, 'drawer-acceptance');
};
