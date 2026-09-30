// A long backlog: the first card is on screen quickly and the page stays responsive.
export const seed = 'perf';

export default async (t) => {
    const page = await t.open();
    const started = Date.now();
    await page.goto(t.url + '/project/work', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.card');
    const first = Date.now() - started;
    await page.waitForLoadState('networkidle');
    const cards = await page.locator('.card').count();
    console.log(`  ${cards} cards, first card after ${first} ms, idle after ${Date.now() - started} ms`);
    t.ok('the first card shows within 3s on a 300-card board', cards > 300 && first < 3000);

    const scrolled = await page.evaluate(() => new Promise((resolve) => {
        const lane = document.querySelector('.col[data-stage=backlog] .col-b');
        const at = performance.now();
        lane.scrollTop = lane.scrollHeight;
        requestAnimationFrame(() => requestAnimationFrame(() => resolve(performance.now() - at)));
    }));
    t.ok('scrolling the long lane paints within 250ms', scrolled < 250);
};
