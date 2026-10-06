// What a card and a lane say at a glance: marks for priority, tags only for what needs attention, the way to move a card.
export const seed = 'rich';

export default async (t) => {
    const { blocked, w1, w2, r1, nightly, low, long, labels } = t.seed.ids;
    const page = await t.open();
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const card = (id) => page.locator(`.card[data-id="${id}"]`);

    // priority is a mark, colour is kept for what needs attention
    t.ok('an urgent card wears the urgent mark', (await card(blocked).locator('.prio-i[aria-label="Priority: urgent"]').count()) === 1);
    t.ok('a low one the low mark', (await card(low).locator('.prio-i[aria-label="Priority: low"]').count()) === 1);
    t.ok('a card of normal priority wears none', (await card(labels).locator('.prio-i').count()) === 0);

    t.ok('a blocked card says so, with the reason after it', (await card(blocked).locator('.tag.red').innerText()).trim() === 'Blocked' && (await card(blocked).locator('.fact.reason').innerText()).startsWith('Waiting for the security team'));
    t.ok('and is marked on its edge and tinted', await card(blocked).evaluate((el) => el.classList.contains('is-blocked') && getComputedStyle(el, '::before').backgroundColor !== 'rgba(0, 0, 0, 0)' && getComputedStyle(el).backgroundColor !== getComputedStyle(document.querySelector('.card:not(.is-blocked)')).backgroundColor));
    t.ok('a card waiting on another says on how many', (await card(nightly).locator('.tag.amber').innerText()).trim() === 'Waits on 1');
    t.ok('an agent at work shows for how long', /^Working \d+m$/.test((await card(w1).locator('.tag.green').innerText()).trim()));
    t.ok('a silent one shows as stale', /^Stale \d+m$/.test((await card(w2).locator('.tag.amber').innerText()).trim()));
    t.ok('and a stopped one as stopped', (await card(r1).locator('.tag', { hasText: 'Stopped' }).count()) === 1);
    t.ok('the stack of a working card is a link that opens elsewhere', (await card(w1).locator('a.fact.link').getAttribute('target')) === '_blank');

    t.ok('a bug and a spike carry their type, other types nothing', (await card(blocked).locator('.fact.type-bug').count()) === 1 && (await card(long).locator('.fact.type-spike').count()) === 1 && (await card(nightly).locator('.fact[class*=type-]').count()) === 0);
    t.ok('two labels show, the rest are counted', (await card(labels).locator('.fact:not(.type-bug)').allInnerTexts()).join('|').includes('+3') && (await card(labels).locator('.fact', { hasText: '+3' }).getAttribute('title')).split(', ').length === 3);
    const longLabel = 'label' + '-word'.repeat(11);
    t.cli(['set', low, `labels=+${longLabel}`]);
    await page.waitForFunction((label) => [...document.querySelectorAll('.fact')].some((el) => el.textContent === label), longLabel, { timeout: 8000 });
    t.ok('a label longer than the card is cut short with an ellipsis, not left to run over', await page.evaluate((label) => {
        const fact = [...document.querySelectorAll('.fact')].find((el) => el.textContent === label);
        const style = getComputedStyle(fact);
        return style.display === 'block' && style.textOverflow === 'ellipsis' && fact.scrollWidth > fact.clientWidth && fact.getBoundingClientRect().right <= fact.closest('.card').getBoundingClientRect().right;
    }, longLabel));
    t.ok('a long title stops at three lines', await card(long).locator('.c-title').evaluate((el) => el.scrollHeight <= 3 * 19 + 2 || getComputedStyle(el).webkitLineClamp === '3'));
    t.ok('the age says since when', /^since \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(await card(low).locator('.c-age').getAttribute('title')));

    // moving: the ⋯ button where the UI can move a card, a word about the command line where it cannot
    t.ok('a card the UI can move has a ⋯ button', (await card(low).locator('.c-more').count()) === 1);
    t.ok('one that is worked on does not', (await card(w1).locator('.c-more').count()) === 0);
    await card(low).hover();
    await card(low).locator('.c-more').click();
    await page.waitForSelector('.menu');
    t.ok('the button lists where it can go, numbered', (await page.locator('.menu [role=menuitem]').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').trim()).join('|') === 'planning 1|dropped 2');
    t.ok('the button says it opens a menu and that it is open', (await card(low).locator('.c-more').getAttribute('aria-haspopup')) === 'menu' && (await card(low).locator('.c-more').getAttribute('aria-expanded')) === 'true');
    await card(low).locator('.c-more').click();
    t.ok('pressing it again closes the menu', (await page.locator('.menu').count()) === 0 && (await card(low).locator('.c-more').getAttribute('aria-expanded')) === 'false');
    await card(low).locator('.c-more').click();
    await page.waitForSelector('.menu');
    t.cli(['set', low, 'title=Renamed while its menu was open']);
    await page.waitForFunction((id) => document.querySelector(`.card[data-id="${id}"] .c-title`).textContent.includes('Renamed while'), low, { timeout: 8000 });
    await card(low).locator('.c-more').click();
    t.ok('the button of a card that was changed elsewhere still closes its menu', (await page.locator('.menu').count()) === 0 && (await card(low).locator('.c-more').getAttribute('aria-expanded')) === 'false');
    await card(w1).locator('.c-title').click();
    await page.waitForSelector('.panel.is-top .d-title');
    await page.keyboard.press('Escape');
    await page.keyboard.press('m');
    await page.waitForSelector('.toast');
    t.ok('m on a card the UI cannot move says which command does', (await page.locator('.toast').innerText()).includes('kanban apply'));

    // lanes
    t.ok('a lane that cards cannot be dropped into says how they get there', (await page.locator('.col[data-stage=doing] .cli').getAttribute('aria-label')).includes('kanban start'));
    t.ok('a lane with a work-in-progress limit shows it next to its count', (await page.locator('.col[data-stage=review] .n').innerText()) === '1' && (await page.locator('.col[data-stage=review] .wip').innerText()) === '/6');
    t.ok('each lane has the mark of its stage', (await page.locator('.col .col-h .stage-i').count()) === (await page.locator('.col').count()));
    t.ok('a lane names its cards to a screen reader', (await page.locator('.col[data-stage=doing]').getAttribute('aria-label')) === 'Doing, 2 cards');
    t.ok('the limit sits against the count, like 1/6', Math.abs(await page.evaluate(() => document.querySelector('.col[data-stage=review] .wip').getBoundingClientRect().left - document.querySelector('.col[data-stage=review] .n').getBoundingClientRect().right)) <= 1);

    // criteria can be removed until work starts; after that a lock says why there is no X
    await card(w1).locator('.c-title').click();
    await page.waitForSelector('.panel.is-top .check li');
    t.ok('a card that is being worked on shows a lock where the remove button was', (await page.locator('.panel.is-top .check li .rm').count()) === 0 && (await page.locator('.panel.is-top .check li .locked').count()) === (await page.locator('.panel.is-top .check li').count()) && (await page.locator('.panel.is-top .check li .locked').first().getAttribute('title')).includes('work has started'));
    await page.keyboard.press('Escape');
    await card(low).locator('.c-title').click();
    await page.waitForFunction(() => document.activeElement === document.querySelector('.panel.is-top .d-body'));
    await page.fill('.panel.is-top input[aria-label="New criterion"]', 'One to remove');
    await page.keyboard.press('Enter');
    await page.waitForSelector('.panel.is-top .check li .rm');
    t.ok('and a card that has not started has the remove button', (await page.locator('.panel.is-top .check li .locked').count()) === 0);
};
