// Small helpers the checks share.

/** Opens the board switcher and waits for its list. */
export async function openSwitcher(page) {
    await page.click('.switcher');
    await page.waitForSelector('.menu [role=menuitem]');
}

/** Picks an entry of the board switcher by its text ("All boards", a board's title). */
export async function switchTo(page, name) {
    await openSwitcher(page);
    await page.click(`.menu [role=menuitem]:has-text("${name}")`);
    await page.waitForTimeout(500);
}

/** Chooses an entry of a drawer dropdown (stage, priority, type) by its text. */
export async function pick(page, field, text) {
    await page.click(`.drawer button[data-field="${field}"]`);
    await page.click(`.menu [role=option]:has-text("${text}")`);
}

/** The entries a drawer dropdown offers, those that can be chosen. */
export async function choices(page, field) {
    await page.click(`.drawer button[data-field="${field}"]`);
    const texts = await page.locator('.menu [role=option]:not([disabled])').allTextContents();
    await page.keyboard.press('Escape');
    return texts.map((text) => text.trim());
}

/** What a drawer dropdown shows as its value. */
export const valueOf = (page, field) => page.locator(`.drawer button[data-field="${field}"] .pill-value`).innerText();
