// Quick successive edits, edits that race another writer, and the fields' own rules.
import { openSwitcher, pick, switchTo } from '../lib.mjs';

export default async (t) => {
    const { a, b } = t.seed.ids;
    t.cli(['board', 'infra', 'Infra']);
    t.cli(['new', 'infra', 'Move the queue to Redis']);
    const page = await t.open();
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    t.ok('no card animates on first paint', (await page.locator('.card.is-new').count()) === 0);
    await switchTo(page, 'Infra');
    t.ok('nor after a board switch', (await page.locator('.card.is-new').count()) === 0);

    await page.goto(t.url + '/cards/' + a, { waitUntil: 'networkidle' });
    await page.waitForSelector('.drawer .check li');
    await page.evaluate(() => { for (const box of document.querySelectorAll('.drawer .check input[type=checkbox]')) box.click(); });
    await page.waitForTimeout(1500);
    t.ok('three ticks in a row are all kept', (await page.locator('.drawer .check li.is-done').count()) === 3 && (await page.locator('.toast.err').count()) === 0);

    const label = page.locator('input[aria-label="New label"]');
    for (const name of ['one', 'two', 'three']) { await label.fill(name); await label.press('Enter'); }
    await page.waitForTimeout(1500);
    t.ok('three labels in a row are all kept', (await page.locator('.drawer .chips .chip', { hasText: /^(one|two|three)/ }).count()) === 3 && (await page.locator('.toast.err').count()) === 0);
    await label.fill('Bad Label');
    await label.press('Enter');
    await page.waitForTimeout(300);
    t.ok('a label with capitals is refused with the rule', (await page.locator('.toast.err', { hasText: 'lowercase' }).count()) === 1);
    await label.fill('');

    await page.locator('.drawer .check .text').first().click();
    await page.fill('.drawer .check input.text', 'Form renders well');
    await page.press('.drawer .check input.text', 'Enter');
    await page.waitForTimeout(900);
    t.ok('Enter saves a criterion and closes its editor', (await page.locator('.drawer .check input.text').count()) === 0 && (await page.locator('.drawer .check .text', { hasText: 'Form renders well' }).count()) === 1);
    await page.locator('.drawer .check .text').first().click();
    await page.fill('.drawer .check input.text', 'Should not save');
    await page.press('.drawer .check input.text', 'Escape');
    await page.waitForTimeout(400);
    t.ok('Esc restores it and keeps the drawer open', (await page.locator('.drawer .check .text', { hasText: 'Form renders well' }).count()) === 1 && (await page.locator('.drawer:not([hidden])').count()) === 1);
    t.ok('titles stop at the 120 characters the board allows', (await page.getAttribute('.d-title', 'maxlength')) === '120');

    await page.focus('.drawer .editable');
    await page.keyboard.press('Enter');
    await page.waitForSelector('.drawer textarea[aria-label="Description"]');
    const before = await page.inputValue('.drawer textarea[aria-label="Description"]');
    t.ok('Enter opens the description without typing into it', !before.endsWith('\n\n') && (await page.evaluate(() => document.activeElement.tagName)) === 'TEXTAREA');
    const description = '.drawer textarea[aria-label="Description"]';
    const conflict = '.drawer .conflict';
    const md = (text) => page.locator('.drawer .md', { hasText: text }).count();
    // the refusal toast of the label step above may still be on screen
    await page.evaluate(() => document.querySelectorAll('.toast').forEach((el) => el.remove()));
    await page.fill(description, before + '\nMy edit');
    t.cli(['set', a, 'title=Renamed on the CLI meanwhile']);
    await page.waitForTimeout(4500);
    await page.press(description, 'Control+Enter');
    await page.waitForTimeout(900);
    t.ok('a change to another field while typing does not stop the save, and asks nothing', (await md('My edit')) === 1 && (await page.locator('.toast.err').count()) === 0 && (await page.locator(conflict).count()) === 0);

    // a change to the same text is shown beside the typed text, and the user chooses
    await page.locator('.drawer .editable').first().click();
    await page.fill(description, 'Mine, typed before the CLI changed it');
    t.cli(['set', a, 'body=Theirs, set on the CLI']);
    await page.waitForTimeout(4500);
    await page.press(description, 'Control+Enter');
    await page.waitForSelector(conflict);
    t.ok('a change to the same text is shown next to the typed text, not written over', (await page.locator(conflict).innerText()).includes('Theirs, set on the CLI') && (await page.inputValue(description)).startsWith('Mine, typed') && (await page.locator('.toast.err').count()) === 0);
    await page.press(description, 'Control+Enter');
    await page.waitForTimeout(600);
    t.ok('saving again does nothing until a choice is made', (await md('Mine, typed')) === 0 && (await page.locator(conflict).count()) === 1);
    t.cli(['set', a, 'body=Theirs again']);
    await page.waitForTimeout(4500);
    await page.click(`${conflict} button:has-text("Keep mine")`);
    await page.waitForTimeout(600);
    t.ok('if it changed once more, Keep mine shows the newest version and waits for a new click', (await page.locator(conflict).innerText()).includes('Theirs again') && (await md('Mine, typed')) === 0);
    await page.click(`${conflict} button:has-text("Keep mine")`);
    await page.waitForTimeout(900);
    t.ok('Keep mine writes the typed text and closes the editor', (await md('Mine, typed')) === 1 && (await page.locator(description).count()) === 0 && (await page.locator(conflict).count()) === 0);
    await page.locator('.drawer .editable').first().click();
    await page.fill(description, 'Mine, second try');
    t.cli(['set', a, 'body=Theirs, second']);
    await page.waitForTimeout(4500);
    await page.press(description, 'Control+Enter');
    await page.waitForSelector(conflict);
    await page.click(`${conflict} button:has-text("Use theirs")`);
    await page.waitForTimeout(600);
    t.ok('Use theirs closes the editor with the other version', (await md('Theirs, second')) === 1 && (await page.locator(description).count()) === 0);

    // a draft that outlived somebody else's change opens beside it, not over it
    await page.locator('.drawer .editable').first().click();
    await page.fill(description, 'Typed and left behind');
    await page.locator(`.card[data-id="${b}"] .c-title`).click();
    await page.waitForFunction((id) => location.pathname.endsWith('/cards/' + id), b);
    t.cli(['set', a, 'body=Changed while the draft was away']);
    await page.waitForTimeout(4500);
    await page.locator(`.card[data-id="${a}"] .c-title`).click();
    await page.waitForSelector(conflict);
    t.ok('the draft reopens next to the version that changed meanwhile', (await page.locator(conflict).innerText()).includes('Changed while the draft was away') && (await page.inputValue(description)).startsWith('Typed and left behind'));
    await page.click(`${conflict} button:has-text("Use theirs")`);
    await page.waitForTimeout(400);

    // an own write while typing does not hide somebody else's change of the text
    await page.locator('.drawer .editable').first().click();
    await page.fill(description, 'Typed before the second CLI change');
    t.cli(['set', a, 'body=Set on the CLI again']);
    await page.waitForTimeout(4500);
    await pick(page, 'type', 'chore');
    await page.waitForTimeout(900);
    await page.press(description, 'Control+Enter');
    await page.waitForSelector(conflict);
    t.ok('picking something else meanwhile does not hide it either', (await page.locator(conflict).innerText()).includes('Set on the CLI again'));
    await page.click(`${conflict} button:has-text("Use theirs")`);
    await page.waitForTimeout(400);

    // only one text editor is open at a time: starting a block does not swallow a description being typed
    await page.locator('.drawer .editable').first().click();
    await page.fill(description, 'Typed and not saved yet');
    await page.click('.drawer button:has-text("Block…")');
    await page.waitForTimeout(400);
    t.ok('a second editor does not replace the first, and the typed text stays', (await page.inputValue(description)) === 'Typed and not saved yet' && (await page.locator('input[aria-label="Blocked reason"]').count()) === 0 && (await page.locator('.toast', { hasText: 'other edit' }).count()) === 1);
    await page.press(description, 'Escape');
    await page.waitForTimeout(300);

    // the name: a change elsewhere to anything but the name still saves; a different name is not lost
    await page.click('.drawer .d-title');
    t.cli(['set', a, 'note=A note meanwhile']);
    await page.waitForTimeout(4500);
    await page.fill('.drawer .d-title', 'A name typed meanwhile');
    await page.keyboard.press('Tab');
    await page.waitForTimeout(900);
    t.ok('a note added elsewhere does not stop a new name from saving', (await page.inputValue('.drawer .d-title')) === 'A name typed meanwhile' && (await page.locator('.toast.err').count()) === 0);
    await page.click('.drawer .d-title');
    await page.fill('.drawer .d-title', 'My name');
    t.cli(['set', a, 'title=Their name']);
    await page.waitForTimeout(4500);
    await page.keyboard.press('Tab');
    await page.waitForTimeout(900);
    t.ok('a name changed elsewhere is shown, and the one typed is in the message', (await page.inputValue('.drawer .d-title')) === 'Their name' && (await page.locator('.toast.err', { hasText: 'My name' }).count()) === 1);

    // a criterion
    await page.locator('.drawer .check .text').first().click();
    t.cli(['set', a, 'note=Another note meanwhile']);
    await page.waitForTimeout(4500);
    await page.fill('.drawer .check input.text', 'Reworded here');
    await page.press('.drawer .check input.text', 'Enter');
    await page.waitForTimeout(900);
    t.ok('a note added elsewhere does not stop a criterion from saving', (await page.locator('.drawer .check .text', { hasText: 'Reworded here' }).count()) === 1);
    await page.locator('.drawer .check .text').first().click();
    await page.fill('.drawer .check input.text', 'Typed over their wording');
    t.cli(['set', a, 'accept[1]=Worded on the CLI']);
    await page.waitForTimeout(4500);
    await page.press('.drawer .check input.text', 'Enter');
    await page.waitForTimeout(900);
    t.ok('a criterion reworded elsewhere is shown, and the typed one stays in its box', (await page.locator('.drawer .check input.text').count()) === 1 && (await page.inputValue('.drawer .check input.text')) === 'Typed over their wording' && (await page.locator('.toast.err', { hasText: 'Worded on the CLI' }).count()) === 1);
    await page.press('.drawer .check input.text', 'Escape');
    await page.waitForTimeout(300);

    await page.locator('.drawer .editable').first().click();
    await page.fill('.drawer textarea[aria-label="Description"]', 'Typed while ticking');
    await page.locator('.drawer .check input[type=checkbox]').first().click();
    await page.waitForTimeout(900);
    await page.press('.drawer textarea[aria-label="Description"]', 'Control+Enter');
    await page.waitForTimeout(900);
    t.ok('the user\'s own writes while typing do not make the text stale', (await page.locator('.drawer .md', { hasText: 'Typed while ticking' }).count()) === 1);

    // a note posted, or a stage picked, while typing is the user's own write as well
    await page.locator('.drawer .editable').first().click();
    await page.fill('.drawer textarea[aria-label="Description"]', 'Typed while posting');
    await page.fill('.drawer textarea[aria-label="Note"]', 'A note on the way');
    await page.click('.drawer button:has-text("Post")');
    await page.waitForTimeout(900);
    await page.press('.drawer textarea[aria-label="Description"]', 'Control+Enter');
    await page.waitForTimeout(900);
    t.ok('a note posted while typing does not make the text stale', (await page.locator('.drawer .md', { hasText: 'Typed while posting' }).count()) === 1);
    await page.locator('.drawer .editable').first().click();
    await page.fill('.drawer textarea[aria-label="Description"]', 'Typed while moving');
    await pick(page, 'stage', 'planning');
    await page.waitForTimeout(900);
    await page.press('.drawer textarea[aria-label="Description"]', 'Control+Enter');
    await page.waitForTimeout(900);
    t.ok('and neither does a move to another stage', (await page.locator('.drawer .md', { hasText: 'Typed while moving' }).count()) === 1);

    await page.locator('.drawer .check li').first().hover();
    await page.locator('.drawer .check li .rm').first().click();
    await page.waitForTimeout(900);
    t.ok('the history names removed criteria', (await page.locator('.drawer .log', { hasText: 'removed criteria' }).count()) === 1);

    await page.goto(t.url + '/nothing', { waitUntil: 'networkidle' });
    for (const key of ['j', 'ArrowLeft', 'p']) await page.keyboard.press(key);
    await page.waitForTimeout(200);

    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    await openSwitcher(page);
    t.ok('two boards in the switcher, and all boards', (await page.locator('.menu [role=menuitem]').count()) === 3);
    await page.keyboard.press('Escape');
    t.cli(['board', 'extra', 'Extra board']);
    await page.waitForTimeout(500);
    t.cli(['set', b, 'priority=low']);
    await page.waitForTimeout(4500);
    await openSwitcher(page);
    t.ok('a board made elsewhere is in the switcher without a reload', (await page.locator('.menu [role=menuitem]', { hasText: 'Extra board' }).count()) === 1);
};
