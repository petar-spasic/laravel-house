// Verdict: the evaluator's last verdict reads as a section of the card, for the person who reviews it: the decision,
// the summary in plain words, each criterion with its result and evidence, the issues and the note. The Activity list
// says the same in words, never as raw fields. A verdict without a summary or passes reads from its failures and issues.
export const seed = 'rich';

export default async (t) => {
    const { m_merging: approved, w2: rejected, w1: older, a: plain } = t.seed.ids;
    const page = await t.open({ w: 1600, h: 1000 });
    const panel = '.panel.is-top';
    const open = async (id) => {
        await page.goto(t.url + `/cards/${id}`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    };
    const section = page.locator(`${panel} .d-body > section.verdict`);
    const checks = section.locator('.v-checks > li');
    const text = async (locator) => (await locator.count()) ? locator.first().innerText() : '';

    await open(approved);
    t.ok('an approved card shows a Verdict section', (await section.count()) === 1 && (await section.isVisible()));
    t.ok('that says approved', /approved/i.test(await text(section.locator('h3'))));
    t.ok('and leads with the evaluator\'s summary', /every note arrived with its tags/.test(await text(section.locator('.v-summary'))));
    t.ok('each criterion is listed with its own words', (await checks.count()) === 2
        && /A ZIP of notebooks is imported with its tags/.test(await checks.nth(0).innerText())
        && /A damaged ZIP is refused with a message/.test(await checks.nth(1).innerText()));
    t.ok('with its result as a word, not a colour alone', (await checks.count()) === 2 && (await checks.evaluateAll((items) => items.every((li) => li.dataset.result === 'pass' && /Passed/.test(li.innerText)))));
    t.ok('and the evidence the evaluator gave', /3 notebooks and 41 notes, tags kept/.test(await checks.nth(0).innerText())
        && /6 passed; a truncated ZIP answers 422 "The file is damaged"/.test(await checks.nth(1).innerText()));
    t.ok('it sits above the criteria', await page.evaluate(() => {
        const body = document.querySelector('.panel.is-top .d-body');
        const kids = [...body.children];
        return kids.indexOf(body.querySelector(':scope > section.verdict')) < kids.findIndex((el) => /Acceptance/.test(el.querySelector('h3')?.textContent || ''));
    }));
    const entry = page.locator(`${panel} .log > li`, { hasText: 'approved' }).first();
    t.ok('the Activity entry says approved in words', (await entry.count()) === 1 && /evaluator(\s*\([^)]*\))?\s+approved it at a{7}/.test(await entry.innerText()));
    t.ok('never as raw fields', !(/decision:|passed:|\["|\{"/.test(await page.locator(`${panel} .log`).innerText())));
    await section.scrollIntoViewIfNeeded();
    await t.shot(page, 'verdict-approved');

    await open(rejected);
    t.ok('a rejected card says rejected', /rejected/i.test(await text(section.locator('h3'))));
    t.ok('its summary says why', /does not show up until the cache expires/.test(await text(section.locator('.v-summary'))));
    t.ok('the failing criterion reads Failed with its evidence', (await checks.count()) === 2
        && (await checks.nth(1).getAttribute('data-result')) === 'fail' && /Failed/.test(await checks.nth(1).innerText())
        && /A new note appears in the index at once/.test(await checks.nth(1).innerText()) && /missing for 10 minutes/.test(await checks.nth(1).innerText()));
    t.ok('the passing one reads Passed', (await checks.nth(0).getAttribute('data-result')) === 'pass' && /Passed/.test(await checks.nth(0).innerText()));
    t.ok('the issues are listed', /keeps a debug dump\(\) call/.test(await text(section.locator('.v-issues'))));
    t.ok('and the note is shown', /The cache key ignores the team id/.test(await text(section.locator('.v-note'))));
    await section.scrollIntoViewIfNeeded();
    await t.shot(page, 'verdict-rejected');
    const folded = page.locator(`${panel} .log details.verdict-log`).first();
    await folded.locator('summary').click();
    t.ok('in Activity, the checks and issues unfold under the entry', /Failed/.test(await folded.innerText()) && /keeps a debug dump\(\) call/.test(await folded.innerText()));
    t.ok('without the timeline line or its spacing', await folded.evaluate((d) => [...d.querySelectorAll('li')].every((li) => getComputedStyle(li, '::before').content === 'none' && getComputedStyle(li).paddingBottom === '0px')));
    t.ok('and the issues keep their bullets', await folded.evaluate((d) => getComputedStyle(d.querySelector('.v-issues li')).display === 'list-item'));
    await folded.scrollIntoViewIfNeeded();
    await t.shot(page, 'verdict-activity');

    await open(older);
    t.ok('a verdict without a summary or passes still shows', (await section.count()) === 1 && /rejected/i.test(await text(section.locator('h3'))));
    t.ok('without an empty summary', (await section.locator('.v-summary').count()) === 0);
    t.ok('with its failing criterion and evidence', (await checks.count()) === 1 && /It works/.test(await checks.nth(0).innerText()) && /the ledger table is missing/.test(await checks.nth(0).innerText()));
    t.ok('and its issue', /No test covers a duplicate webhook/.test(await text(section.locator('.v-issues'))));
    t.ok('and nothing reads undefined', !/undefined|null/.test(await section.innerText()));

    await open(plain);
    t.ok('a card without a verdict has no Verdict section', (await section.count()) === 0 || !(await section.isVisible()));
};
