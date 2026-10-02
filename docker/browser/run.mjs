// Runs the UI checks in docker/browser/checks in a real browser against a freshly seeded board each.
//   node docker/browser/run.mjs [--prefix p] [name ...]   the checks (all when none is named); exit 1 on any failure;
//                                                          --prefix names the screenshots p<name>.png
//   node docker/browser/run.mjs --serve                    only serve the rich seeded UI on :8099
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

// ES modules ignore NODE_PATH; require honours it, and the image installs playwright-core outside the checkout
const { chromium } = createRequire(import.meta.url)('playwright-core');

const here = path.dirname(fileURLToPath(import.meta.url));
const pkg = path.resolve(here, '../..');
const shots = path.join(pkg, 'build/ui');
const args = process.argv.slice(2);
const prefixAt = args.indexOf('--prefix');
const prefix = prefixAt === -1 ? '' : args.splice(prefixAt, 2)[1] ?? '';

async function serve(port, mode = '') {
    const file = `/tmp/seed-${port}.json`;
    fs.rmSync(file, { force: true });
    const server = spawn('php', [path.join(here, 'seed.php'), String(port), file, ...(mode ? [`--${mode}`] : [])], { stdio: 'ignore' });
    for (let i = 0; i < 900 && !(fs.existsSync(file) && (await listening(port))); i++) await new Promise((r) => setTimeout(r, 100));
    if (!(await listening(port))) throw new Error('the seeded UI did not start');
    return { server, seed: JSON.parse(fs.readFileSync(file, 'utf8')) };
}
const listening = (port) => new Promise((resolve) => {
    const socket = net.connect(port, '127.0.0.1', () => { socket.destroy(); resolve(true); });
    socket.on('error', () => resolve(false));
});

if (args[0] === '--serve') {
    const { server, seed } = await serve(8099, 'rich');
    console.log(`Seeded UI: http://localhost:8099/kanban   (checkout ${seed.root}, Ctrl+C to stop)`);
    server.on('exit', () => process.exit(0));
    process.on('SIGINT', () => { server.kill(); process.exit(0); });
    await new Promise(() => {});
}

// A refusal the checks provoke on purpose is not a script error.
const expected = /Failed to load resource: the server responded with a status of (404|409|422|503)/;
const names = args.length ? args : fs.readdirSync(path.join(here, 'checks')).filter((f) => f.endsWith('.mjs')).map((f) => f.replace(/\.mjs$/, '')).sort();
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
fs.mkdirSync(shots, { recursive: true });
let failures = 0;
let port = 8100;

for (const name of names) {
    console.log(`\n${name}`);
    const check = await import(pathToFileURL(path.join(here, 'checks', `${name}.mjs`)));
    const { server, seed } = await serve(port++, check.seed);
    const contexts = [];
    const logs = [];
    const allowed = [];
    const t = {
        seed,
        url: `http://127.0.0.1:${port - 1}/kanban`,
        ok(what, condition) { console.log(`  ${condition ? 'PASS' : 'FAIL'} ${what}`); if (!condition) failures++; },
        // console errors a check provokes on purpose (a request it aborts, say)
        allow: (pattern) => allowed.push(pattern),
        sleep: (ms) => new Promise((r) => setTimeout(r, ms)),
        // { root, env } runs the command in another checkout, as another person (the team seed has a peer)
        cli: (cmd, { root = seed.root, env = {} } = {}) => execFileSync(path.join(pkg, 'bin/kanban'), cmd, { cwd: root, encoding: 'utf8', env: { ...process.env, KANBAN_SESSION: '', ...env } }),
        async open({ w = 1400, h = 850, scheme = 'light', reducedMotion = 'no-preference', forcedColors = 'none', touch = false } = {}) {
            const context = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme, reducedMotion, forcedColors, ...(touch ? { hasTouch: true, isMobile: true } : {}) });
            const page = await context.newPage();
            page.on('console', (m) => { if (m.type() === 'error' && !expected.test(m.text()) && !allowed.some((pattern) => pattern.test(m.text()))) logs.push(m.text()); });
            page.on('pageerror', (e) => logs.push(e.message));
            contexts.push(context);
            return page;
        },
        shot: (page, file) => page.screenshot({ path: path.join(shots, `${prefix}${file}.png`), animations: 'disabled' }),
    };
    try {
        await check.default(t);
    } catch (e) {
        console.log(`  FAIL ${e.message.split('\n').slice(0, 3).join(' ').replace(/\s+/g, ' ')}`);
        failures++;
    }
    for (const line of logs) { console.log(`  FAIL console: ${line}`); failures++; }
    for (const context of contexts) await context.close();
    server.kill();
}
await browser.close();
console.log(failures ? `\n${failures} failed` : '\nall passed');
process.exit(failures ? 1 : 0);
