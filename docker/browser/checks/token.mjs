// With KANBAN_UI_TOKEN set: the page asks for the token, remembers it, keeps it out of the address bar, and asks again once it stops matching.
import { contrastSweep, nameSweep, targetSweep, touchSweep } from './a11y.mjs';

export const seed = 'token';

export default async (t) => {
    const { a, b } = t.seed.ids;
    t.allow(/status of 401/);
    const page = await t.open();
    await page.goto(t.url + '/work', { waitUntil: 'networkidle' });
    const input = page.locator('form input[name=token][type=password]');
    t.ok('without the token the page asks for it', (await input.count()) === 1 && (await page.locator('#app').count()) === 0);
    t.ok('the token box has a label and the focus', (await input.evaluate((el) => el.labels.length === 1 && document.activeElement === el)));

    await input.fill('wrong');
    await input.press('Enter');
    await page.waitForLoadState('networkidle');
    t.ok('a wrong token is said so, and the form stays', (await page.locator('[role=alert]').innerText()).includes('did not work') && (await input.count()) === 1);

    await input.fill(t.seed.token);
    await input.press('Enter');
    await page.waitForSelector('.col');
    t.ok('the right token opens the board it was asked on', new URL(page.url()).pathname.endsWith('/work'));
    t.ok('the token is not left in the address bar', !page.url().includes('token'));
    await page.reload({ waitUntil: 'networkidle' });
    t.ok('the browser remembers the token', (await page.locator('.col').count()) > 0);

    await page.context().clearCookies();
    await page.waitForSelector('form input[name=token]', { timeout: 10000 });
    t.ok('an open board asks again once the token stops matching', true);

    const deep = await t.open();
    await deep.goto(`${t.url}/cards/${a}?from=${b}`, { waitUntil: 'networkidle' });
    await deep.fill('input[name=token]', t.seed.token);
    await deep.press('input[name=token]', 'Enter');
    await deep.waitForSelector('.drawer:not([hidden])');
    const url = new URL(deep.url());
    t.ok('a card link keeps its path and stack through the form', url.pathname.endsWith('/cards/' + a) && url.searchParams.get('from') === b && !url.searchParams.has('token'));

    for (const scheme of ['light', 'dark']) {
        for (const touch of [false, true]) {
            const view = await t.open({ w: touch ? 390 : 1440, h: touch ? 844 : 900, scheme, touch });
            await view.goto(t.url, { waitUntil: 'networkidle' });
            await view.evaluate(() => document.fonts.ready);
            const label = `${scheme} ${touch ? 'phone' : 'desktop'}`;
            const { offenders } = await view.evaluate(contrastSweep, 4.5);
            t.ok(`the token form: contrast 4.5:1 (${label})`, offenders.length === 0);
            t.ok(`the token form: targets 24x24 and named (${label})`, (await view.evaluate(targetSweep)).length === 0 && (await view.evaluate(nameSweep)).length === 0);
            t.ok(`the token form: no sideways scroll (${label})`, await view.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            if (touch) t.ok(`the token form: sized for a finger (${label})`, (await view.evaluate(touchSweep)).length === 0);
            if (scheme === 'light') await t.shot(view, `token-${touch ? 'phone' : 'desktop'}`);
        }
    }
};
