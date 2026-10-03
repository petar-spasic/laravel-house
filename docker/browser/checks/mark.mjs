// A card that is open and changes under the reader says so: who did what, for a little while. Agents' housekeeping and the
// reader's own writes stay quiet.
import fs from 'node:fs';
import path from 'node:path';

export default async (t) => {
    const { a } = t.seed.ids;
    const cardFile = path.join(t.seed.root, 'docs/kanban/work', `${a}.json`);
    const append = (...entries) => {
        const card = JSON.parse(fs.readFileSync(cardFile, 'utf8'));
        const at = new Date().toISOString().replace('Z', '+00:00');
        card.log.push(...entries.map((entry) => ({ at, ...entry })));
        fs.writeFileSync(cardFile, JSON.stringify(card, null, 4) + '\n');
    };
    const page = await t.open({ w: 1440, h: 900 });
    await page.goto(t.url + `/cards/${a}`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    const mark = page.locator('.panel.is-top .d-mark');
    const shown = async () => (await mark.count()) === 1 && (await mark.isVisible());

    t.ok('an open card shows no mark to begin with', !(await shown()));

    t.cli(['set', a, 'note=Ben looked at this'], { env: { KANBAN_USER: 'Ben' } });
    await mark.waitFor({ state: 'visible', timeout: 12000 });
    const text = await mark.innerText();
    t.ok('a note added elsewhere names who and what, and when', text.includes('Ben') && text.includes('added a note') && /just now|\d+s ago/.test(text));
    await t.shot(page, 'mark');

    await page.fill('.panel.is-top textarea[aria-label="Note"]', 'My own note');
    await page.press('.panel.is-top textarea[aria-label="Note"]', 'Control+Enter');
    await page.waitForSelector('.panel.is-top .log q:has-text("My own note")');
    t.ok('the reader\'s own write clears it', !(await shown()));

    append({ id: '6J7K8M9N', by: 'worker', who: 'Ben', event: 'set', fields: ['priority'] });
    await page.waitForTimeout(4500);
    t.ok('an agent changing something the reader is not editing says nothing', !(await shown()));

    await page.locator('.panel.is-top .editable').first().click();
    await page.waitForSelector('.panel.is-top textarea[aria-label="Description"]');
    append({ id: '7K8M9N0P', by: 'worker', who: 'Ben', event: 'set', fields: ['body'] });
    await mark.waitFor({ state: 'visible', timeout: 12000 });
    t.ok('but an agent changing the text being edited does', (await mark.innerText()).includes('worker (Ben) changed body'));
    await page.press('.panel.is-top textarea[aria-label="Description"]', 'Escape');

    append({ id: '8M9N0P1Q', by: 'hook', event: 'conflict', field: 'title', lost: 'The title that lost' });
    await page.waitForFunction(() => (document.querySelector('.panel.is-top .d-mark') || {}).textContent?.includes('kept the other version of title'), null, { timeout: 12000 });
    t.ok('a merge that kept the other version of something says so', true);
};
