// Contrast, target size, names, overflow, tab order and touch sizes, measured in the browser on the rich board.
export const seed = 'rich';

// Runs inside the page. Every visible text node's colour against the first opaque background above it.
function contrastSweep(threshold) {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const rgba = (css) => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = '#000';
        ctx.fillStyle = css;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2], d[3] / 255];
    };
    const over = (top, bottom) => {
        const a = top[3] + bottom[3] * (1 - top[3]);
        return a === 0 ? [0, 0, 0, 0] : [0, 1, 2].map((i) => (top[i] * top[3] + bottom[i] * bottom[3] * (1 - top[3])) / a).concat(a);
    };
    const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
    const ratio = (a, b) => { const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
    const page = rgba(getComputedStyle(document.documentElement).backgroundColor);
    const base = page[3] === 1 ? page : over(page, [255, 255, 255, 1]);

    const background = (el) => {
        let stack = [];
        for (let node = el; node; node = node.parentElement) {
            const color = rgba(getComputedStyle(node).backgroundColor);
            if (color[3] > 0) stack.push(color);
            if (color[3] === 1) break;
        }
        return stack.reduceRight((below, color) => over(color, below), base);
    };
    const opacity = (el) => { let value = 1; for (let node = el; node; node = node.parentElement) value *= Number(getComputedStyle(node).opacity); return value; };

    const offenders = [];
    let checked = 0;
    for (const el of document.querySelectorAll('body *')) {
        if (!(el instanceof HTMLElement) || el.matches('option, optgroup, script, style, noscript') || !el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
        if (el.matches(':disabled, [aria-disabled=true]') || el.closest('[aria-disabled=true]')) continue;
        if (![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue;
        const style = getComputedStyle(el);
        const bg = background(el);
        const fg = rgba(style.color);
        const alpha = fg[3] * opacity(el);
        const text = over([fg[0], fg[1], fg[2], alpha], [bg[0], bg[1], bg[2], 1]);
        const value = ratio(text, bg);
        checked++;
        if (value < threshold) offenders.push(`${value.toFixed(2)} ${el.tagName.toLowerCase()}${el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''} "${el.textContent.trim().slice(0, 30)}"`);
    }
    return { checked, offenders };
}


// Runs inside the page. Every icon shown is opaque enough and 3:1 against what is behind it (the card's move button that shows on hover, the pencil that steps aside while the name has the focus, and disabled or decorative ones are left out).
function iconSweep() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const rgba = (css) => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = '#000';
        ctx.fillStyle = css;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2], d[3] / 255];
    };
    const over = (top, bottom) => {
        const a = top[3] + bottom[3] * (1 - top[3]);
        return a === 0 ? [0, 0, 0, 0] : [0, 1, 2].map((i) => (top[i] * top[3] + bottom[i] * bottom[3] * (1 - top[3])) / a).concat(a);
    };
    const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
    const ratio = (a, b) => { const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
    const page = rgba(getComputedStyle(document.documentElement).backgroundColor);
    const base = page[3] === 1 ? page : over(page, [255, 255, 255, 1]);
    const background = (el) => {
        const stack = [];
        for (let node = el; node; node = node.parentElement) {
            const color = rgba(getComputedStyle(node).backgroundColor);
            if (color[3] > 0) stack.push(color);
            if (color[3] === 1) break;
        }
        return stack.reduceRight((below, color) => over(color, below), base);
    };
    const opacity = (el) => { let value = 1; for (let node = el; node; node = node.parentElement) value *= Number(getComputedStyle(node).opacity); return value; };

    const offenders = [];
    let checked = 0;
    for (const el of document.querySelectorAll('svg.i')) {
        if (!el.checkVisibility({ checkVisibilityCSS: true }) || el.closest('.c-more, [aria-disabled=true], :disabled, .d-title-box:focus-within')) continue;
        const bg = background(el.parentElement);
        const fg = rgba(getComputedStyle(el).color);
        const seen = over([fg[0], fg[1], fg[2], fg[3] * opacity(el)], [bg[0], bg[1], bg[2], 1]);
        checked++;
        const value = ratio(seen, bg);
        if (value < 3) offenders.push(`${value.toFixed(2)} in ${el.parentElement.tagName.toLowerCase()}${el.parentElement.className ? '.' + String(el.parentElement.className).trim().split(/\s+/).join('.') : ''} "${(el.parentElement.getAttribute('aria-label') || el.parentElement.textContent || '').trim().slice(0, 24)}"`);
    }
    return { checked, offenders };
}

// Runs inside the page. Interactive elements smaller than 24x24 CSS px, except links inside cards and prose (the card is their target).
function targetSweep() {
    const small = [];
    for (const el of document.querySelectorAll('button, a[href], input:not([type=hidden]), select, summary, textarea, [role=button]')) {
        if (!el.checkVisibility({ checkVisibilityCSS: true }) || (el.matches('a[href]') && el.closest('.card, .md, .log'))) continue;
        const box = el.getBoundingClientRect();
        if (box.width < 24 || box.height < 24) small.push(`${Math.round(box.width)}x${Math.round(box.height)} ${el.tagName.toLowerCase()}${el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''} "${(el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 24)}"`);
    }
    return small;
}

// Runs inside the page, on a touch device. Text boxes are 16px so that iOS does not zoom into them, and the controls that take their height from the scale are 40px (a chip and its remove button are 24px, 40px on touch).
function touchSweep() {
    const seen = new Map();
    const found = { push: (line) => seen.set(line, (seen.get(line) || 0) + 1) };
    const name = (el) => `${el.tagName.toLowerCase()}${el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''} "${(el.getAttribute('aria-label') || el.textContent || el.placeholder || '').trim().slice(0, 24)}"`;
    for (const el of document.querySelectorAll('input:not([type=checkbox]):not([type=hidden]), textarea')) {
        if (!el.checkVisibility({ checkVisibilityCSS: true })) continue;
        const size = parseFloat(getComputedStyle(el).fontSize);
        if (size < 16) found.push(`${size}px text in ${name(el)}`);
    }
    for (const el of document.querySelectorAll('.btn, .icon-btn, .pill, .tgl, .chip, .chip button, .menu-item, .menu-search input, input.field, .search input, .composer input[type=text]')) {
        if (!el.checkVisibility({ checkVisibilityCSS: true })) continue;
        const height = el.getBoundingClientRect().height;
        if (height < 39.5) found.push(`${Math.round(height)}px tall ${name(el)}`);
    }
    return [...seen].map(([line, count]) => (count > 1 ? `${line} x${count}` : line));
}

// Runs inside the page. Buttons and links without an accessible name.
function nameSweep() {
    const unnamed = [];
    for (const el of document.querySelectorAll('button, a[href], input:not([type=hidden]), select, textarea')) {
        if (!el.checkVisibility({ checkVisibilityCSS: true })) continue;
        const name = (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || el.getAttribute('placeholder') || '').trim() || (el.labels && el.labels.length ? 'label' : '');
        if (!name) unnamed.push(`${el.tagName.toLowerCase()}${el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''}`);
    }
    return unnamed;
}

export default async (t) => {
    const { w1, blocked } = t.seed.ids;
    for (const scheme of ['light', 'dark']) {
        for (const [name, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
            const touch = name === 'phone';
            const page = await t.open({ w: width, h: height, scheme, reducedMotion: 'reduce', touch });
            if (!touch) {
                // two quick boards in the bar, so that they are measured too
                await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
                await page.keyboard.press('Shift+1');
                await page.goto(t.url + '/project/decisions', { waitUntil: 'networkidle' });
                await page.keyboard.press('Shift+2');
            }
            for (const [view, path] of [['index', ''], ['work board', '/project/work'], ['drawer', `/cards/${w1}`], ['drawer with links', `/cards/${blocked}`]]) {
                await page.goto(t.url + path, { waitUntil: 'networkidle' });
                await page.evaluate(() => document.fonts.ready);
                await page.waitForTimeout(300);
                const label = `${scheme} ${name} ${view}`;

                if (!touch) t.ok(`the quick boards are in the bar (${label})`, (await page.locator('.pins .pin').count()) === 2);
                const { checked, offenders } = await page.evaluate(contrastSweep, 4.5);
                t.ok(`contrast: every text is 4.5:1 or better (${label}; ${checked} checked${offenders.length ? `, ${offenders.length} below` : ''})`, offenders.length === 0);
                if (offenders.length) for (const line of offenders.slice(0, 8)) console.log(`      ${line}`);

                const icons = await page.evaluate(iconSweep);
                t.ok(`icons: every icon shown is seen, 3:1 or better (${label}; ${icons.checked} checked${icons.offenders.length ? `, ${icons.offenders.length} not` : ''})`, icons.offenders.length === 0);
                for (const line of icons.offenders.slice(0, 8)) console.log(`      ${line}`);

                const small = await page.evaluate(targetSweep);
                t.ok(`targets: controls are 24x24 or larger (${label}${small.length ? `, ${small.length} smaller` : ''})`, small.length === 0);
                if (small.length) for (const line of small.slice(0, 8)) console.log(`      ${line}`);

                const unnamed = await page.evaluate(nameSweep);
                t.ok(`names: every control has an accessible name (${label}${unnamed.length ? `: ${unnamed.slice(0, 4).join(', ')}` : ''})`, unnamed.length === 0);

                t.ok(`the page does not scroll sideways (${label})`, await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));

                if (touch) {
                    const big = await page.evaluate(touchSweep);
                    t.ok(`touch: text boxes are 16px and controls 40px (${label}${big.length ? `, ${big.length} not` : ''})`, big.length === 0);
                    for (const line of big.slice(0, 8)) console.log(`      ${line}`);
                }
            }

            // keyboard: Tab reaches search, then New, then a filter chip, then a card, in that order
            await page.goto(t.url + '/project/work', { waitUntil: 'networkidle' });
            const seen = [];
            for (let i = 0; i < 30; i++) {
                await page.keyboard.press('Tab');
                seen.push(await page.evaluate(() => {
                    const el = document.activeElement;
                    return el.matches('input[type=search]') ? 'search' : el.matches('.btn.new') ? 'new' : el.matches('.tgl') ? 'chip' : el.matches('.card') ? 'card' : el.tagName.toLowerCase();
                }));
            }
            const order = ['search', 'new', 'chip', 'card'].map((what) => seen.indexOf(what));
            t.ok(`tab order: search, New, a filter chip, then a card (${scheme} ${name})`, order.every((at, i) => at !== -1 && (i === 0 || at > order[i - 1])));

            if (touch) {
                // what only opens on demand: the switcher, a filter list, the composer, the move button on every card
                const menus = [];
                await page.click('.switcher');
                await page.waitForSelector('.menu-item');
                menus.push(...(await page.evaluate(touchSweep)));
                await page.keyboard.press('Escape');
                await page.click('.tgl[data-filter=label]');
                await page.waitForSelector('.menu-search input');
                menus.push(...(await page.evaluate(touchSweep)));
                await page.keyboard.press('Escape');
                await page.click('.btn.new');
                await page.waitForSelector('.composer input[type=text]');
                menus.push(...(await page.evaluate(touchSweep)));
                await page.keyboard.press('Escape');
                t.ok(`touch: the lists and the composer are sized for a finger (${scheme}${menus.length ? `, ${menus.length} not` : ''})`, menus.length === 0);
                for (const line of menus.slice(0, 8)) console.log(`      ${line}`);
                const more = await page.evaluate(() => [...document.querySelectorAll('.c-more')].map((el) => getComputedStyle(el).opacity));
                t.ok(`touch: the move button of every card is shown without pointing at it (${scheme}; ${more.length} cards)`, more.length > 5 && more.every((opacity) => opacity === '1'));
            }
            await page.close();
        }
    }
};
