// What a sync says: a failing one shows as a notice on the open board within a poll and goes away when a sync succeeds; text that a merge replaced is kept in the card's activity, and entries name who did them.
import fs from 'node:fs';
import path from 'node:path';

export default async (t) => {
    const file = path.join(t.seed.root, '.git/laravel-house/sync.status.json');
    fs.mkdirSync(path.dirname(file), { recursive: true });
    const record = (state, extra = {}) => fs.writeFileSync(file, JSON.stringify({ state, kind: state === 'ok' ? null : 'remote', attempt_at: '2026-09-30T10:00:00.000+00:00', ok_at: null, error: state === 'ok' ? null : 'RemoteFailed: fetch failed', ahead: 0, behind: 0, failures: 0, ...extra }));
    const page = await t.open({ w: 1440, h: 900 });
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const notice = page.locator('.notice');

    t.ok('there is no notice while the last sync was fine', (await notice.count()) === 0);
    record('failed', { ahead: 3, behind: 2, failures: 1 });
    await page.waitForTimeout(3800);
    t.ok('one failed attempt is a blip and is not shown', (await notice.count()) === 0);

    record('failed', { ahead: 3, behind: 2, failures: 2 });
    await page.waitForSelector('.notice', { timeout: 8000 });
    const text = await notice.innerText();
    t.ok('a sync that keeps failing says what is not pushed and what is missing', text.includes('Not pushed: 3 commits') && text.includes('Behind by 2') && text.includes('fetch failed'));
    await t.shot(page, 'sync-notice');

    record('ok');
    await page.waitForFunction(() => !document.querySelector('.notice'), null, { timeout: 8000 });
    t.ok('and it goes away when a sync succeeds', (await notice.count()) === 0);

    record('failed', { kind: 'invalid', error: 'Invalid: the board is invalid after the pull: dependency cycle', ahead: 1, failures: 1 });
    await page.waitForSelector('.notice', { timeout: 8000 });
    t.ok('a failure that will not clear by itself is shown at once', (await notice.innerText()).includes('Sync stopped after a pull'));

    // a merge that kept the other person's version leaves what it replaced in the card's log
    const { a } = t.seed.ids;
    const cardFile = path.join(t.seed.root, 'docs/kanban/work', `${a}.json`);
    const card = JSON.parse(fs.readFileSync(cardFile, 'utf8'));
    card.log.push({ id: '7K2M9Q3X', at: new Date().toISOString().replace('Z', '+00:00'), by: 'hook', event: 'conflict', field: 'body', lost: 'Text the merge replaced <b>not markup</b>' });
    fs.writeFileSync(cardFile, JSON.stringify(card, null, 4) + '\n');
    await page.goto(t.url + `/cards/${a}`, { waitUntil: 'networkidle' });
    const entry = page.locator('.panel.is-top .log li', { hasText: 'other version' });
    t.ok('the activity says a merge kept the other version of the field', (await entry.count()) === 1 && (await entry.innerText()).includes('body'));
    t.ok('and what was replaced is behind a fold, not printed among the facts', (await entry.locator('details:not([open])').count()) === 1 && !(await entry.locator('.log-h').innerText()).includes('Text the merge'));
    await entry.locator('summary').click();
    t.ok('it opens as text, never as markup', (await entry.locator('q').innerText()) === 'Text the merge replaced <b>not markup</b>' && (await entry.locator('q b').count()) === 0);
    await t.shot(page, 'sync-conflict');

    // the activity names the person: alone for the owner's own entries, in brackets after the role for an agent's or a hook's
    const named = JSON.parse(fs.readFileSync(cardFile, 'utf8'));
    const at = new Date().toISOString().replace('Z', '+00:00');
    named.log.push({ id: '3F4G5H6J', at, by: 'owner', who: 'Ana', event: 'note', text: 'From Ana' },
        { id: '4G5H6J7K', at, by: 'worker', who: 'Ana', event: 'note', text: 'From her agent' },
        { id: '5H6J7K8M', at, by: 'hook', event: 'note', text: 'From nobody in particular' });
    fs.writeFileSync(cardFile, JSON.stringify(named, null, 4) + '\n');
    await page.goto(t.url + `/cards/${a}`, { waitUntil: 'networkidle' });
    const who = async (text) => (await page.locator('.panel.is-top .log li', { hasText: text }).locator('.who').innerText()).trim();
    t.ok('the owner\'s own entry shows the person', (await who('From Ana')) === 'Ana');
    t.ok('an agent\'s entry keeps the role and names the person beside it', (await who('From her agent')) === 'worker (Ana)');
    t.ok('an entry with no name shows the role', (await who('From nobody in particular')) === 'hook');
    t.ok('and the name is not repeated among the facts', !(await page.locator('.panel.is-top .log li', { hasText: 'From Ana' }).locator('.log-h').innerText()).includes('who:'));
};
