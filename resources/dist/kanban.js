/* Kanban board UI. Vanilla JS, no build step, no inline code: the page is one shell this script draws. */
(() => {
    'use strict';

    const body = document.body;
    const BASE = body.dataset.base ?? '/kanban';
    const POLL = Math.max(1000, Number(body.dataset.pollMs) || 3000);
    const root = document.getElementById('app');
    const SUMMARY = ['id', 'short', 'title', 'stage', 'priority', 'type', 'labels', 'epic', 'blocked', 'question', 'blocks', 'deps', 'progress', 'agent', 'url', 'merge', 'since', 'rev'];
    const PRIORITIES = ['urgent', 'high', 'normal', 'low'];
    const MAX = { body: 20000, criteria: Number(body.dataset.maxCriteria), criterion: Number(body.dataset.maxCriterion), labels: 10 };
    // what an empty lane says
    const EMPTY = {
        backlog: 'No cards yet. Press N to add one.', planning: 'Cards that pass the ready checks wait here for their plan.', ready: 'Planned cards wait here for a worker.',
        doing: 'Started by agents: kanban start.', review: 'Reported work waits here for its evaluator, then for the merge queue.', done: 'Completed cards collect here.', dropped: 'Nothing dropped.',
    };
    // lanes a card cannot be dropped into, and how a card gets there; and how one gets out
    const CLI_HINT = {
        ready: "A plan moves cards here: their planner's, or vendor/bin/kanban plan ID", doing: 'Cards move here from the command line: vendor/bin/kanban start ID',
        review: "A worker's report moves cards here: vendor/bin/kanban apply ID", done: 'The merge queue moves approved cards here: kanban run, or vendor/bin/kanban finish ID',
    };
    const CLI_ONWARD = {
        doing: 'It moves to review when a worker reports: vendor/bin/kanban apply ID', review: 'The merge queue moves it to done once an evaluator approves it: kanban run, or vendor/bin/kanban finish ID',
        done: 'Finished cards stay done', held: 'A planner is working on it: it moves to ready with its plan; vendor/bin/kanban stop ID --to=backlog takes the planner off',
    };
    const TYPES = ['feature', 'bug', 'chore', 'spike'];

    /* ---------- helpers ---------- */

    const h = (tag, attrs, ...kids) => {
        const el = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs || {})) {
            if (value === undefined || value === null || value === false) continue;
            if (key === 'class') el.className = value;
            else if (key === 'text') el.textContent = value;
            else if (key === 'data') Object.assign(el.dataset, value);
            else if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
            else el.setAttribute(key, value === true ? '' : value);
        }
        for (const kid of kids.flat(Infinity)) if (kid !== undefined && kid !== null && kid !== false) el.append(kid);
        return el;
    };
    const $ = (selector, scope = document) => scope.querySelector(selector);

    /* icons:start */
    const ICONS = {
        'chevron-down': 'M4 6.25l4 4 4-4',
        'chevron-right': 'M6.25 4l4 4-4 4',
        plus: 'M8 3.25v9.5M3.25 8h9.5',
        x: 'M4 4l8 8M12 4l-8 8',
        check: 'M3.5 8.5l3 3 6-6.5',
        search: 'M6.75 10.75a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM9.75 9.75l3.5 3.5',
        lock: 'M4.5 7.25h7a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-.75.75h-7a.75.75 0 0 1-.75-.75V8a.75.75 0 0 1 .75-.75zM5.75 7.25V5.5a2.25 2.25 0 0 1 4.5 0v1.75',
        clock: 'M8 13.5a5.5 5.5 0 1 0 0-11 5.5 5.5 0 0 0 0 11zM8 5v3.25l2 1.25',
        external: 'M9 3h4v4M13 3L7.5 8.5M11.5 9.5v2.75a1 1 0 0 1-1 1H3.75a1 1 0 0 1-1-1V5.5a1 1 0 0 1 1-1H6.5',
        copy: 'M6.25 6.25h6a1 1 0 0 1 1 1v5.5a1 1 0 0 1-1 1h-6a1 1 0 0 1-1-1v-5.5a1 1 0 0 1 1-1zM10.75 6.25V4.25a1 1 0 0 0-1-1h-6a1 1 0 0 0-1 1v5.5a1 1 0 0 0 1 1h1.5',
        sun: 'M8 10.75a2.75 2.75 0 1 0 0-5.5 2.75 2.75 0 0 0 0 5.5zM8 1.5v1.25M8 13.25v1.25M1.5 8h1.25M13.25 8h1.25M3.4 3.4l.9.9M11.7 11.7l.9.9M3.4 12.6l.9-.9M11.7 4.3l.9-.9',
        moon: 'M13.25 9.25A5.5 5.5 0 0 1 6.75 2.75a5.5 5.5 0 1 0 6.5 6.5z',
        help: 'M8 13.5a5.5 5.5 0 1 0 0-11 5.5 5.5 0 0 0 0 11zM6.4 6.4a1.7 1.7 0 0 1 3.3.55c0 1.15-1.7 1.35-1.7 2.3M8 11.25h.01',
        alert: 'M8 2.5l5.75 10h-11.5zM8 6.5v2M8 10.5h.01',
        note: 'M3 3.5h10v7H8l-3 2.5v-2.5H3z',
        'arrow-right': 'M3.5 8h9M9 4.5L12.5 8 9 11.5',
        pencil: 'M10.5 3.5l2 2M4 12l.5-2.5 6-6 2 2-6 6L4 12z',
        flag: 'M4.5 13.5V2.75M4.5 3h7l-1.75 2.75L11.5 8.5h-7',
        more: { d: 'M3 8a1 1 0 1 0 2 0 1 1 0 0 0-2 0zM7 8a1 1 0 1 0 2 0 1 1 0 0 0-2 0zM11 8a1 1 0 1 0 2 0 1 1 0 0 0-2 0z' },
        board: { d: 'M3.2 2h1.1c.66 0 1.2.54 1.2 1.2v9.6c0 .66-.54 1.2-1.2 1.2H3.2c-.66 0-1.2-.54-1.2-1.2V3.2C2 2.54 2.54 2 3.2 2zM7.45 2h1.1c.66 0 1.2.54 1.2 1.2v5.6c0 .66-.54 1.2-1.2 1.2h-1.1c-.66 0-1.2-.54-1.2-1.2V3.2C6.25 2.54 6.79 2 7.45 2zM11.7 2h1.1c.66 0 1.2.54 1.2 1.2v7.6c0 .66-.54 1.2-1.2 1.2h-1.1c-.66 0-1.2-.54-1.2-1.2V3.2c0-.66.54-1.2 1.2-1.2z' },
    };
    /* icons:end */
    /** A small ring showing how much of $total is $done. */
    const ring = (done, total) => {
        const el = h('span', { class: 'ring', role: 'img', 'aria-label': done + ' of ' + total + ' done' });
        el.style.setProperty('--p', String(Math.round((100 * done) / total)));
        return el;
    };
    const SVG_NS = 'http://www.w3.org/2000/svg';
    /** An empty 16x16 drawing and a function that adds shapes to it. */
    function drawing(cls, attrs = {}) {
        const el = document.createElementNS(SVG_NS, 'svg');
        el.setAttribute('viewBox', '0 0 16 16');
        el.setAttribute('class', cls);
        el.setAttribute('focusable', 'false');
        for (const [key, value] of Object.entries({ 'aria-hidden': 'true', ...attrs })) el.setAttribute(key, value);
        const draw = (tag, shape) => {
            const node = document.createElementNS(SVG_NS, tag);
            for (const [key, value] of Object.entries(shape)) node.setAttribute(key, value);
            el.append(node);
        };
        return [el, draw];
    }
    /**
     * The mark of a stage: a dashed circle for backlog, a dashed ring inside an outline for planning, an outline for ready, half
     * filled for doing, a dot inside for review, ticked for done, crossed for dropped.
     */
    function stageIcon(stage) {
        const [el, draw] = drawing('i stage-i');
        const circle = { cx: 8, cy: 8, r: 5.25 };
        if (stage === 'backlog') draw('circle', { ...circle, pathLength: 24, 'stroke-dasharray': '1.5 1.5' });
        else if (stage === 'done') { draw('circle', { ...circle, class: 'solid' }); draw('path', { d: 'M5.5 8.25l1.9 1.9 3.2-3.6', class: 'tick' }); }
        else {
            draw('circle', circle);
            if (stage === 'planning') draw('circle', { cx: 8, cy: 8, r: 2.25, pathLength: 12, 'stroke-dasharray': '1.5 1.5' });
            if (stage === 'doing') draw('path', { d: 'M8 2.75a5.25 5.25 0 0 1 0 10.5z', class: 'solid' });
            if (stage === 'review') draw('circle', { cx: 8, cy: 8, r: 2, class: 'solid' });
            if (stage === 'dropped') draw('path', { d: 'M5.75 10.25l4.5-4.5' });
        }
        return el;
    }
    /** The priority as signal bars: three for high, two for normal, one for low; urgent is a square with an exclamation mark. */
    function prioIcon(level, decorative = false) {
        const [el, draw] = drawing('i prio-i prio-' + level, decorative ? {} : { role: 'img', 'aria-label': 'Priority: ' + level, 'aria-hidden': 'false' });
        if (level === 'urgent') {
            draw('rect', { x: 2, y: 2, width: 12, height: 12, rx: 3, class: 'solid' });
            draw('path', { d: 'M8 5.25v3.5M8 11h.01', class: 'mark' });
        } else {
            const lit = { high: 3, normal: 2, low: 1 }[level] ?? 2;
            [4, 7, 10].forEach((height, i) => draw('rect', { x: 3 + i * 4, y: 13 - height, width: 2, height, rx: 0.6, class: i < lit ? 'solid' : 'dim' }));
        }
        return el;
    }
    /** The marks a dropdown shows beside a stage or a priority; the words say the same, so a screen reader skips them. */
    const stageMark = (stage) => h('span', { class: 'mark', data: { stage } }, stageIcon(stage));
    const priorityMark = (level) => prioIcon(level, true);
    const svg = (name, cls = '') => {
        const shape = ICONS[name];
        const el = document.createElementNS(SVG_NS, 'svg');
        el.setAttribute('viewBox', '0 0 16 16');
        el.setAttribute('class', 'i' + (typeof shape === 'string' ? '' : ' i-fill') + (cls ? ' ' + cls : ''));
        el.setAttribute('aria-hidden', 'true');
        el.setAttribute('focusable', 'false');
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', typeof shape === 'string' ? shape : shape.d);
        el.append(path);
        return el;
    };
    const now = () => Math.floor(Date.now() / 1000);
    const age = (seconds) => {
        seconds = Math.max(0, Math.floor(seconds));
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
        if (seconds < 172800) return Math.floor(seconds / 3600) + 'h';
        return Math.floor(seconds / 86400) + 'd';
    };
    const store = {
        get(key) { try { return localStorage.getItem('kanban.' + key); } catch { return null; } },
        set(key, value) { try { localStorage.setItem('kanban.' + key, value); } catch { /* storage may be blocked */ } },
    };
    const storedList = (key, fallback) => { try { const list = JSON.parse(store.get(key)); return Array.isArray(list) ? list : fallback; } catch { return fallback; } };
    /** The quick boards: slot 1-9 => board ref, kept in this browser. */
    const storedPins = () => {
        try {
            const pins = JSON.parse(store.get('pins'));
            // a pin from before boards left their epic directories (`project/work`) is the board alone now
            return pins && typeof pins === 'object' && !Array.isArray(pins) ? Object.fromEntries(Object.entries(pins).filter(([slot, ref]) => /^[1-9]$/.test(slot) && typeof ref === 'string').map(([slot, ref]) => [slot, ref.split('/').pop()])) : {};
        } catch { return {}; }
    };
    const hostOf = (url) => { try { return new URL(url).host; } catch { return url; } };
    const text = (value) => (value === undefined || value === null ? '' : String(value));

    /* ---------- api ---------- */

    async function api(path, { method = 'GET', body: payload, etag } = {}) {
        const headers = { Accept: 'application/json', 'X-Kanban': '1' };
        if (payload !== undefined) headers['Content-Type'] = 'application/json';
        if (etag) headers['If-None-Match'] = etag;
        const response = await fetch(BASE + '/_api' + path, {
            method, headers, cache: 'no-store', credentials: 'same-origin',
            body: payload === undefined ? undefined : JSON.stringify(payload),
        });
        if (response.status === 304) return { status: 304 };
        // the UI token stopped matching: the page itself asks for it
        if (response.status === 401) { location.reload(); return new Promise(() => {}); }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw Object.assign(new Error(data.message || 'HTTP ' + response.status), { status: response.status, data });
        return { status: response.status, data, etag: response.headers.get('ETag') };
    }

    /* ---------- state ---------- */

    const S = {
        route: { name: 'boards' },
        landed: false,
        boards: null,
        board: null,
        ref: null,
        all: false,
        stack: [],
        sel: null,
        etags: {},
        busy: 0,
        seq: 0,
        failures: 0,
        collapsed: new Set(storedList('collapsed', ['dropped'])),
        pins: storedPins(),
        filter: { q: '', priority: new Set(), type: new Set(), label: new Set(), epic: new Set(), flag: new Set() },
        composer: null,
    };

    let serial = Promise.resolve();
    const inOrder = (task) => {
        const run = serial.then(task);
        serial = run.catch(() => {});
        return run;
    };
    let reconcileTimer = 0;
    async function writing(task, id = null) {
        S.busy++;
        if (id) flashSaved(id, 'saving');
        try {
            const result = await inOrder(task);
            if (id) flashSaved(id, 'saved');
            return result;
        } catch (e) {
            if (id) flashSaved(id, 'error');
            throw e;
        } finally {
            S.busy--;
            S.seq++;
            // the server orders a lane by policy: ask for its order once the writes are done
            if (!S.busy) { clearTimeout(reconcileTimer); reconcileTimer = setTimeout(poll, 250); }
        }
    }

    /* ---------- shell ---------- */

    const ui = {};

    function buildShell() {
        ui.q = h('input', { type: 'search', placeholder: 'Search cards', 'aria-label': 'Search cards', autocomplete: 'off', oninput: () => { S.filter.q = ui.q.value.trim(); filtersChanged(); } });
        // the phone's bar has room for one word
        const narrow = matchMedia('(width <= 760px)');
        const hint = () => { ui.q.placeholder = narrow.matches ? 'Search' : 'Search cards'; };
        narrow.addEventListener('change', hint);
        hint();
        ui.search = h('div', { class: 'search' }, svg('search'), ui.q, h('kbd', { text: '/' }));
        ui.switcher = h('button', { class: 'switcher', type: 'button', 'aria-haspopup': 'menu', 'aria-expanded': 'false', onclick: toggleSwitcher });
        ui.newBtn = h('button', { class: 'btn primary new', type: 'button', 'aria-label': 'New card', title: 'New card (n)', onclick: () => openComposer(S.board && S.board.stages[0].stage) },
            svg('plus'), h('span', { class: 'new-text', text: 'New' }), h('kbd', { text: 'N' }));
        ui.liveText = h('span', { class: 'live-text', text: 'Live' });
        ui.live = h('span', { class: 'live', role: 'status', title: 'Connected' }, h('i'), ui.liveText);
        ui.themeBtn = h('button', { class: 'icon-btn', title: 'Theme (t)', 'aria-label': 'Change theme', onclick: cycleTheme });
        ui.pins = h('nav', { class: 'pins', 'aria-label': 'Quick boards' });
        // two sides of equal width keep the quick boards in the middle, whatever the board is called
        ui.bar = h('header', { class: 'bar' },
            h('div', { class: 'bar-start' },
                h('a', { class: 'brand', href: BASE, onclick: nav }, svg('board'), h('span', { class: 'brand-text', text: 'Kanban' })),
                ui.switcher),
            ui.pins,
            h('div', { class: 'bar-end' }, ui.search, ui.newBtn, ui.live, ui.themeBtn,
                h('button', { class: 'icon-btn help-btn', title: 'Keyboard shortcuts (?)', 'aria-label': 'Keyboard shortcuts', onclick: () => ui.help.showModal() }, svg('help'))));
        ui.notices = h('div', { class: 'notices', 'aria-live': 'polite' });
        ui.chips = h('div', { class: 'toolbar-chips' });
        ui.result = h('span', { class: 'result', 'aria-live': 'polite' });
        ui.clear = h('button', { class: 'btn ghost small clear', type: 'button', text: 'Clear', hidden: true, onclick: clearFilters });
        ui.toolbar = h('div', { class: 'toolbar', role: 'group', 'aria-label': 'Filters', hidden: true }, ui.chips, h('span', { class: 'spacer' }), ui.result, ui.clear);
        ui.main = h('main', { class: 'main' });
        ui.toasts = h('div', { class: 'toasts', 'aria-live': 'polite' });
        const KEYS = [
            ['Navigate', [['/', 'Search'], ['j k', 'Next / previous card'], ['h l', 'Column left / right'], ['Enter', 'Open the card'], ['b', 'Switch board (again: all boards)'], ['Alt+1…9', 'Go to a quick board'], ['Shift+1…9', 'Put this board on that key']]],
            ['Cards', [['n', 'New card in the first column'], ['m', 'Move the card…'], ['p', 'Cycle priority'], ['Alt+W', 'Close the card on top'], ['Esc', 'Close / clear']]],
            ['View', [['t', 'Switch light / dark'], ['?', 'This help']]],
        ];
        ui.help = h('dialog', { class: 'help', 'aria-label': 'Keyboard shortcuts' },
            h('h2', { text: 'Keyboard shortcuts' }),
            h('div', { class: 'keys' }, KEYS.map(([group, rows]) => h('section', {}, h('h3', { text: group }),
                h('dl', {}, rows.flatMap(([keys, what]) => [h('dt', {}, ...keys.split(' ').map((k) => h('kbd', { text: k })), ' '), h('dd', { text: what })]))))),
            h('form', { method: 'dialog', class: 'dialog-actions' }, h('button', { class: 'btn', text: 'Close' })));
        ui.help.addEventListener('click', (e) => e.target === ui.help && ui.help.close());
        buildDrawer();
        ui.workspace = h('div', { class: 'workspace' }, ui.main, ui.drawer);
        root.replaceChildren(ui.bar, ui.notices, ui.toolbar, ui.workspace, ui.scrim, ui.toasts, ui.help);
        applyTheme();
    }

    /* ---------- theme ---------- */

    const osDark = matchMedia('(prefers-color-scheme: dark)');
    const shownTheme = () => { const theme = store.get('theme') || 'auto'; return theme === 'auto' ? (osDark.matches ? 'dark' : 'light') : theme; };

    function applyTheme() {
        const theme = store.get('theme') || 'auto';
        if (theme === 'auto') delete document.documentElement.dataset.theme;
        else document.documentElement.dataset.theme = theme;
        const shown = shownTheme();
        ui.themeBtn.replaceChildren(svg(shown === 'dark' ? 'sun' : 'moon'));
        ui.themeBtn.title = 'Switch to the ' + (shown === 'dark' ? 'light' : 'dark') + ' theme (t)';
    }
    /** Always flips what is on screen; choosing the system's own look goes back to following the system. */
    function cycleTheme() {
        const next = shownTheme() === 'dark' ? 'light' : 'dark';
        store.set('theme', next === (osDark.matches ? 'dark' : 'light') ? 'auto' : next);
        applyTheme();
    }
    osDark.addEventListener('change', () => ui.themeBtn && applyTheme());

    /* ---------- routing ---------- */

    function parse(pathname) {
        const path = pathname.slice(BASE.length).replace(/^\/+|\/+$/g, '');
        if (path === '') return { name: 'boards' };
        const parts = path.split('/');
        if (parts[0] === 'cards' && parts[1]) {
            // php -S answers 400 to bad percent sequences, but a reverse proxy may pass them on: a throw here would leave the page blank and unpolled
            try { return { name: 'card', id: decodeURIComponent(parts[1]) }; } catch { return { name: 'missing' }; }
        }
        if (parts.length === 1 && parts[0] !== 'cards') return { name: 'board', ref: path };
        // a link from before boards left their epic directories: /project/work is /work now
        if (parts.length === 2 && parts[0] !== 'cards') return { name: 'moved', ref: parts[1] };
        return { name: 'missing' };
    }
    function go(url, replace = false) {
        const prev = location.pathname + location.search;
        if (replace) history.replaceState({}, '', url); else history.pushState({ push: true, prev }, '', url);
        return route();
    }
    function nav(e) {
        if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const link = e.currentTarget;
        if (link.origin !== location.origin) return;
        e.preventDefault();
        go(link.pathname + link.search);
    }

    let routeSeq = 0;
    async function route() {
        const mine = ++routeSeq;
        const current = () => mine === routeSeq;
        const r = parse(location.pathname);
        if (r.name === 'moved') return go(BASE + '/' + r.ref + location.search, true);
        // a page that opens on the boards of a one-board project shows that board (a failed first load opens it on Retry); asked for later, the boards stay
        const landing = !S.landed;
        S.landed = true;
        S.route = r;
        if (r.name !== 'card') { S.stack = []; syncPanels(); }
        readFilterFromUrl();
        try {
            if (r.name === 'boards') {
                await loadBoards();
                if (!current()) return;
                if (landing && S.boards && flatBoards().length === 1) return go(BASE + '/' + flatBoards()[0].ref, true);
                S.ref = null;
                S.board = null;
                renderView();
            } else if (r.name === 'board') {
                await showBoard(r.ref);
            } else if (r.name === 'card') {
                const under = (new URLSearchParams(location.search).get('from') || '').split(',').filter((id, at, all) => id && id !== r.id && all.indexOf(id) === at);
                S.stack = [...under, r.id];
                syncPanels();
                const gone = await loadPanels();
                if (!current()) return;
                if (gone.includes(topId())) throw Object.assign(new Error('No such card.'), { status: 404 });
                if (gone.length) { S.stack = S.stack.filter((id) => !gone.includes(id)); syncPanels(); }
                const root = detailOf(S.stack[0]);
                // the card is readable even when its board is not (board.json missing): the panels show it either way
                try {
                    if (!S.board || S.ref !== root.board.ref) await showBoard(root.board.ref); else renderView();
                } catch (e) {
                    if (e.status !== 404 || !current()) throw e;
                    missing(r, 'This board cannot be read.');
                }
                if (current() && S.board && S.ref === root.board.ref) revealCard(S.stack[0]);
            } else missing(r, 'No such page.');
        } catch (e) {
            if (!current()) return;
            if (e.status === 404) { S.stack = []; syncPanels(); missing(r, r.name === 'card' ? 'No such card.' : 'No such board.'); } else {
                toastError(e);
                if (!(r.name === 'boards' ? S.boards : S.board)) { S.landed = !landing; failedLoad(); }
            }
        }
        if (!current()) return;
        const top = panels.get(topId());
        if (top && top.detail && !top.focused) { top.focused = true; focusCard(top); }
        document.title = top && top.detail ? top.detail.id + ' · ' + top.detail.title : (S.board ? S.board.title + ' · Kanban' : 'Kanban');
    }
    /** Marks the card the stack started from on the board and brings its lane into view beside the panels. */
    function revealCard(id) {
        select(id);
        requestAnimationFrame(() => {
            const el = document.querySelector('.card[data-id="' + CSS.escape(id) + '"]');
            if (el) el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        });
    }

    /** The page could not be drawn and there is nothing older to show instead. */
    function failedLoad() {
        ui.main.dataset.view = '';
        ui.main.replaceChildren(h('div', { class: 'empty' }, h('div', {}, svg('alert', 'empty-i'), h('p', { text: 'Cannot load this page.' }),
            h('button', { class: 'btn', type: 'button', text: 'Retry', onclick: () => route() }))));
    }

    function missing(r, message) {
        S.board = null;
        S.ref = null;
        S.etags.board = null;
        ui.main.replaceChildren(h('div', { class: 'empty' }, h('div', {}, svg('alert', 'empty-i'), h('p', { text: message }), h('p', {}, h('a', { class: 'btn', href: BASE, onclick: nav, text: 'All boards' })))));
    }

    /* ---------- loading and polling ---------- */

    async function loadBoards(force = false) {
        const result = await api('/boards', { etag: force ? null : S.etags.boards });
        if (result.status === 200) { S.boards = result.data; S.etags.boards = result.etag; }
        renderNotices();
        renderSwitcher();
    }
    const flatBoards = () => (S.boards ? S.boards.boards : []);
    const epicList = () => (S.boards ? S.boards.epics : []);

    async function showBoard(ref) {
        if (S.ref !== ref) { S.ref = ref; S.board = null; S.all = false; S.etags.board = null; ui.main.replaceChildren(); }
        if (!S.boards) await loadBoards().catch(() => {});
        await loadBoard(true);
        renderView();
    }
    async function fetchBoard(force) {
        const ref = S.ref;
        const result = await api('/' + ref + (S.all ? '?all=1' : ''), { etag: force ? null : S.etags.board });
        return result.status === 304 ? null : { ...result, ref };
    }
    /** Takes an answer only while it is for the board on screen. */
    function applyBoard(result) {
        if (result.ref !== S.ref) return false;
        S.board = result.data;
        S.etags.board = result.etag;
        renderNotices();
        return true;
    }
    async function loadBoard(force) {
        const result = await fetchBoard(force);
        return !!result && applyBoard(result);
    }

    async function poll() {
        if (document.hidden || S.busy > 0 || S.drag) return;
        const seq = S.seq;
        try {
            if (S.route.name === 'boards') {
                const before = S.etags.boards;
                await loadBoards();
                if (S.etags.boards !== before) renderView();
            } else if (S.ref) {
                const result = await fetchBoard(false);
                // an answer that may predate a write made meanwhile is dropped; the next poll asks again
                if (seq !== S.seq || S.busy > 0) return;
                if (result && applyBoard(result)) { S.fromPoll = true; try { renderView(); } finally { S.fromPoll = false; } refreshPanels(); if (S.boards && S.board.layout !== S.boards.layout) loadBoards(true).catch(() => {}); }
            }
            online(true);
        } catch (e) {
            online(false);
        }
    }
    function online(ok) {
        S.failures = ok ? 0 : S.failures + 1;
        const text = ok ? 'Live' : 'Offline · retrying';
        const title = ok ? 'Connected' : 'Cannot reach the server';
        ui.live.classList.toggle('is-off', !ok);
        if (ui.live.title !== title) ui.live.title = title;
        if (ui.liveText.textContent !== text) ui.liveText.textContent = text;
        renderNotices();
    }
    function schedule() {
        setTimeout(async () => { await poll(); schedule(); }, S.failures ? Math.min(10000, POLL * 2 ** S.failures) : POLL);
    }
    async function refresh() {
        S.etags.board = null;
        try { if (S.ref) await loadBoard(true); renderView(); } catch { online(false); }
    }

    /* ---------- notices, board switcher ---------- */

    let noticesSig = '';
    function renderNotices() {
        const own = S.route.name === 'boards' ? (S.boards ? S.boards.notices : []) : (S.board ? S.board.notices : []);
        const notices = [...new Set([...own, ...(S.failures >= 3 ? ['Cannot reach the server. Retrying…'] : [])])];
        const sig = notices.join('\n');
        if (sig === noticesSig) return;
        noticesSig = sig;
        ui.notices.replaceChildren(...notices.map((n) => h('div', { class: 'notice' }, svg('alert'), h('span', { text: n }))));
    }
    function renderSwitcher() {
        ui.switcher.hidden = !flatBoards().length;
        const named = S.route.name !== 'boards' && S.board;
        ui.switcher.replaceChildren(h('span', { class: 'sw-name', text: named ? S.board.title : 'Boards' }), svg('chevron-down'));
    }
    let pinsSig = '';
    /** The quick boards, in slot order, in the middle of the bar. */
    function renderPins() {
        const boards = new Map(flatBoards().map((board) => [board.ref, board]));
        const slots = Object.entries(S.pins).filter(([, ref]) => boards.has(ref)).sort(([a], [b]) => a - b);
        const current = S.route.name === 'boards' ? null : S.ref;
        const sig = JSON.stringify([slots, slots.map(([, ref]) => boards.get(ref).title), current]);
        if (sig === pinsSig) return;
        pinsSig = sig;
        ui.pins.hidden = !slots.length;
        const held = ui.pins.contains(document.activeElement) ? document.activeElement.querySelector('kbd').textContent : null;
        ui.pins.replaceChildren(...slots.map(([slot, ref]) => h('a', { class: 'pin', href: BASE + '/' + ref, onclick: nav, title: 'Alt+' + slot, 'aria-keyshortcuts': 'Alt+' + slot, 'aria-current': ref === current ? 'page' : null },
            h('kbd', { text: slot }), h('span', { class: 'pin-name', text: boards.get(ref).title }))));
        if (held) { const again = [...ui.pins.children].find((pin) => pin.querySelector('kbd').textContent === held); if (again) again.focus({ preventScroll: true }); }
    }
    /** Shift+digit: the board you are on goes in that slot (a board is in one slot only); the same slot again takes it off. */
    function pinBoard(slot) {
        if (S.route.name === 'boards' || !S.board) { toast('Open a board first, then press Shift+' + slot + ' to keep it on Alt+' + slot); return; }
        const pins = { ...S.pins };
        const off = pins[slot] === S.ref;
        for (const [key, ref] of Object.entries(pins)) if (ref === S.ref) delete pins[key];
        if (!off) pins[slot] = S.ref;
        S.pins = pins;
        store.set('pins', JSON.stringify(pins));
        renderPins();
        toast(S.board.title + (off ? ' is off Alt+' : ' is on Alt+') + slot, 'ok');
    }
    /** Alt+digit: to the board in that slot, from anywhere. */
    function goPin(slot) {
        const ref = S.pins[slot];
        if (!ref) { toast('Nothing on Alt+' + slot + '. Open a board and press Shift+' + slot + '.'); return; }
        closeMenu();
        if (ref !== S.ref || S.route.name === 'boards') go(BASE + '/' + ref);
    }

    /** Open cards (not done or dropped) on a board of the boards response. */
    const openCount = (board) => Object.entries(board.counts).filter(([stage]) => !QUIET.includes(stage)).reduce((sum, [, n]) => sum + n, 0);
    async function toggleSwitcher() {
        if (menu && menu.trigger === ui.switcher) { closeMenu(); return; }
        try { await loadBoards(true); } catch { /* the list on hand will do */ }
        const boards = flatBoards();
        openList(ui.switcher, [
            ...boards.map((board) => ({ value: BASE + '/' + board.ref, label: board.title, hint: openCount(board), checked: board.ref === S.ref, current: board.ref === S.ref, search: board.title })),
            { separator: true },
            { value: BASE, label: 'All boards and epics', keys: 'B' },
        ], { kind: 'menu', title: 'Boards', trigger: ui.switcher, numbered: true, minWidth: 280, search: boards.length > SEARCH_ABOVE, onPick: (item) => go(item.value) });
    }

    /* ---------- view ---------- */

    function renderView() {
        renderSwitcher();
        renderPins();
        const index = S.route.name === 'boards';
        // the boards page has nothing to search or add to; their room stays, so the bar looks the same on every page
        ui.bar.classList.toggle('is-index', index);
        if (index) renderBoards(); else if (S.board) renderBoard();
        renderToolbar();
    }

    function renderBoards() {
        ui.main.dataset.view = 'boards';
        const boards = flatBoards();
        if (!boards.length) {
            const command = S.boards && S.boards.key ? 'vendor/bin/kanban board work "Work"' : 'vendor/bin/kanban attach';
            ui.main.replaceChildren(h('div', { class: 'empty' }, h('div', {}, svg('board', 'empty-i'), h('p', { text: S.boards && S.boards.key ? 'No boards yet.' : 'No board on this machine.' }),
                h('div', { class: 'command' }, h('code', { text: command }), h('button', { class: 'btn small', type: 'button', onclick: () => copyText(command) }, svg('copy'), 'Copy')))));
            return;
        }
        const epics = epicList();
        ui.main.replaceChildren(h('div', { class: 'index' },
            h('header', { class: 'index-h' }, h('h1', { text: 'Boards' }), h('span', { class: 'muted', text: boards.length + (boards.length === 1 ? ' board' : ' boards') + ' · ' + epics.length + (epics.length === 1 ? ' epic' : ' epics') })),
            h('section', { class: 'index-sec' }, h('div', { class: 'tiles' }, ...boards.map(boardTile))),
            epics.length ? h('section', { class: 'index-sec' }, h('h2', { text: 'Epics' }), h('div', { class: 'tiles' }, ...epics.map((epic) => epicTile(epic, boards[0])))) : null));
    }
    /** An epic on the index: its goal and how many of its cards are done; it opens the board showing only its cards. */
    function epicTile(epic, board) {
        const bar = h('div', { class: 'dist' + (epic.total ? '' : ' is-empty'), 'aria-hidden': 'true' });
        if (epic.done) { const seg = h('span', { class: 'seg', data: { stage: 'done' } }); seg.style.flexGrow = String(epic.done); bar.append(seg); }
        if (epic.total - epic.done) { const seg = h('span', { class: 'seg', data: { stage: 'backlog' } }); seg.style.flexGrow = String(epic.total - epic.done); bar.append(seg); }
        return h('a', { class: 'board-tile epic-tile', href: BASE + '/' + board.ref + '?e=' + encodeURIComponent(epic.slug), onclick: nav },
            h('div', { class: 'tile-top' }, svg('flag', 'tile-i'), h('h3', { text: epic.title })),
            epic.goal ? h('p', { class: 'tile-goal', text: epic.goal }) : null,
            bar,
            h('div', { class: 'tile-foot' }, h('span', {}, h('b', { text: epic.done }), ' of ' + epic.total + ' done'), h('span', { text: epic.done_when.length ? epic.done_when.length + ' done-when' : '' })));
    }
    /** A board on the index: what it is, how its cards are spread over the stages, and the numbers. */
    function boardTile(board) {
        const stages = Object.entries(board.counts).filter(([, n]) => n > 0);
        const total = stages.reduce((sum, [, n]) => sum + n, 0);
        const bar = h('div', { class: 'dist' + (total ? '' : ' is-empty'), 'aria-hidden': 'true' }, stages.filter(([stage]) => stage !== 'dropped').map(([stage, n]) => {
            const seg = h('span', { class: 'seg', data: { stage } });
            seg.style.flexGrow = String(n);
            return seg;
        }));
        return h('a', { class: 'board-tile', href: BASE + '/' + board.ref, onclick: nav },
            h('div', { class: 'tile-top' }, svg('board', 'tile-i'), h('h3', { text: board.title })),
            bar,
            h('div', { class: 'tile-foot' }, h('span', {}, stages.flatMap(([stage, n], i) => [i ? ' · ' : '', h('b', { text: n }), ' ' + stage])), h('span', { text: total + (total === 1 ? ' card' : ' cards') })));
    }

    /* ---------- board ---------- */

    const columns = new Map();

    function renderBoard() {
        let el = $('.board', ui.main);
        if (!el || ui.main.dataset.view !== 'board:' + S.ref) {
            closeComposer();
            columns.clear();
            S.loaded = false;
            el = h('div', { class: 'board' });
            bindBoard(el);
            ui.main.replaceChildren(el);
            ui.main.dataset.view = 'board:' + S.ref;
        }
        flipFrom = S.loaded && !S.drag ? cardRects() : null;
        const seen = new Set();
        let previous = null;
        for (const stage of S.board.stages) {
            let col = columns.get(stage.stage);
            if (!col) { col = buildColumn(stage); columns.set(stage.stage, col); }
            seen.add(stage.stage);
            syncColumn(col, stage);
            if (previous ? previous.nextElementSibling !== col.el : el.firstElementChild !== col.el) el.insertBefore(col.el, previous ? previous.nextElementSibling : el.firstElementChild);
            previous = col.el;
        }
        for (const [name, col] of columns) if (!seen.has(name)) { col.el.remove(); columns.delete(name); }
        S.loaded = true;
        if (!S.drag) clearDrag();
        selectionRestore();
        if (flipFrom) glide(flipFrom);
        flipFrom = null;
    }

    /* ---------- motion: cards glide to where they now are, and flash when someone else changed them ---------- */

    let flipFrom = null;
    const calm = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
    /** Where every shown card is, unless there are too many to be worth following. */
    function cardRects() {
        const shown = [...document.querySelectorAll('.card:not([hidden])')];
        return shown.length > 150 || calm() ? null : new Map(shown.map((el) => [el.dataset.id, el.getBoundingClientRect()]));
    }
    /** A card that moved (in its lane, or to another) slides from its old place to the new one. */
    function glide(from) {
        for (const el of document.querySelectorAll('.card:not([hidden])')) {
            const was = from.get(el.dataset.id);
            if (!was || !was.width) continue;
            const now_ = el.getBoundingClientRect();
            const dx = was.left - now_.left;
            const dy = was.top - now_.top;
            if (Math.abs(dx) > 1 || Math.abs(dy) > 1) el.animate([{ transform: 'translate(' + dx + 'px, ' + dy + 'px)' }, { transform: 'none' }], { duration: 200, easing: 'cubic-bezier(0.2, 0.7, 0.2, 1)' });
        }
    }
    function flash(el) {
        if (calm()) return;
        el.animate([{ backgroundColor: 'var(--accent-soft)' }, {}], { duration: 900, easing: 'ease-out' });
    }

    function buildColumn(stage) {
        const col = { stage: stage.stage };
        col.icon = stageIcon(stage.stage);
        col.title = h('h2', { text: stage.stage });
        col.n = h('span', { class: 'n' });
        col.wip = h('span', { class: 'wip', title: 'Work-in-progress limit', hidden: true });
        col.cli = h('span', { class: 'cli', role: 'img', hidden: true }, svg('lock'));
        col.add = h('button', { class: 'icon-btn add', type: 'button', title: 'New card (n)', 'aria-label': 'New card', onclick: (e) => { e.stopPropagation(); openComposer(stage.stage); } }, svg('plus'));
        col.body = h('div', { class: 'col-b', data: { empty: EMPTY[stage.stage] || 'Nothing here' } });
        col.more = h('button', { class: 'btn ghost small more', type: 'button', hidden: true, onclick: () => { S.all = true; S.etags.board = null; refresh(); } });
        col.head = h('div', { class: 'col-h', onclick: () => toggleCollapsed(col) }, col.icon, col.title, col.n, col.wip, h('span', { class: 'grow' }), col.cli, col.add);
        col.el = h('section', { class: 'col', data: { stage: stage.stage } }, col.head, col.body, col.more);
        const accept = (e) => {
            if (!dragAllowed(col.stage)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            col.el.classList.add('is-over');
        };
        col.el.addEventListener('dragenter', accept);
        col.el.addEventListener('dragover', accept);
        col.el.addEventListener('dragleave', (e) => { if (!col.el.contains(e.relatedTarget)) col.el.classList.remove('is-over'); });
        col.el.addEventListener('drop', (e) => {
            const id = S.drag;
            clearDrag();
            if (!id || !movesOf(findCard(id)).includes(col.stage)) return;
            e.preventDefault();
            moveCard(id, col.stage, col.el);
        });
        col.cards = new Map();
        return col;
    }

    function syncColumn(col, stage) {
        const creatable = stage.stage === S.board.stages[0].stage;
        const collapsible = stage.collapsed;
        const collapsed = collapsible && S.collapsed.has(stage.stage);
        const noDrop = !Object.values(S.board.moves).some((to) => to.includes(stage.stage));
        col.el.classList.toggle('is-collapsed', collapsed);
        col.el.setAttribute('aria-label', stage.stage.charAt(0).toUpperCase() + stage.stage.slice(1) + ', ' + stage.total + (stage.total === 1 ? ' card' : ' cards'));
        col.add.hidden = !creatable;
        col.cli.hidden = !noDrop;
        col.cli.setAttribute('aria-label', CLI_HINT[stage.stage] || 'Cards cannot be dropped here');
        col.cli.title = col.cli.getAttribute('aria-label');
        col.head.style.cursor = collapsible ? 'pointer' : '';
        const shown = stage.cards.filter(matches).length;
        const filtering = filterOn();
        const full = !!stage.limit && stage.total >= stage.limit;
        col.n.textContent = filtering ? shown + ' of ' + stage.cards.length + (stage.older ? '+' : '') : stage.total;
        col.n.classList.toggle('is-full', full);
        col.wip.hidden = !stage.limit;
        col.wip.textContent = '/' + stage.limit;
        col.wip.classList.toggle('is-full', full);
        col.body.classList.toggle('is-nomatch', filtering && stage.cards.length > 0 && shown === 0);
        col.more.hidden = !stage.older;
        col.more.textContent = 'Show ' + stage.older + ' older';

        const existing = col.cards;
        const next = new Map();
        let index = 0;
        const anchor = col.body.querySelector('.composer');
        for (const summary of stage.cards) {
            let el = existing.get(summary.id);
            if (!el) { el = cardEl(summary); if (S.loaded && !(flipFrom && flipFrom.has(summary.id))) el.classList.add('is-new'); } else fillCard(el, summary);
            el.hidden = !matches(summary);
            next.set(summary.id, el);
            const wanted = col.body.children[index + (anchor ? 1 : 0)];
            if (wanted !== el) col.body.insertBefore(el, wanted || null);
            index++;
        }
        for (const [id, el] of existing) if (!next.has(id)) el.remove();
        col.cards = next;
        if (S.composer && S.composer.stage === stage.stage && !col.body.contains(S.composer.el)) col.body.prepend(S.composer.el);
    }

    function toggleCollapsed(col) {
        const stage = S.board.stages.find((s) => s.stage === col.stage);
        if (!stage || !stage.collapsed) return;
        if (S.collapsed.has(col.stage)) S.collapsed.delete(col.stage); else S.collapsed.add(col.stage);
        store.set('collapsed', JSON.stringify([...S.collapsed]));
        renderBoard();
    }

    /* ---------- cards ---------- */

    function cardEl(summary) {
        const el = h('article', { class: 'card', data: { id: summary.id }, draggable: 'true', tabindex: '-1' });
        fillCard(el, summary);
        return el;
    }

    /** A UTC timestamp as `2026-09-29 18:02`. */
    const when = (seconds) => new Date(seconds * 1000).toISOString().slice(0, 16).replace('T', ' ');
    /** Where the board can move a card: none while a planner holds it. */
    const movesOf = (c) => (S.board && c && !c.held && S.board.moves[c.stage]) || [];
    const hasMoves = (c) => movesOf(c).length > 0;
    const isLocked = (c) => !!S.board && (S.board.locked || []).includes(c.stage);

    /** Whether the card waits on the owner's answer (its block is a question). */
    const asks = (c) => typeof c.question === 'string';

    function fillCard(el, c) {
        const moves = hasMoves(c);
        const signature = JSON.stringify([c.rev, c.deps, c.blocks, c.agent && [c.agent.state, c.agent.since, c.agent.beat], c.merge, moves, [...S.filter.epic], [...S.filter.label]]);
        if (el._sig === signature) return;
        el._sig = signature;
        if (el._rev !== undefined && el._rev !== c.rev && S.fromPoll && S.loaded) flash(el);
        el._rev = c.rev;
        const working = !!c.agent && c.agent.state === 'working';
        const stale = !!c.agent && c.agent.state === 'stale';
        el.className = 'card p-' + c.priority + (asks(c) ? ' is-question' : c.blocked ? ' is-blocked' : '') + (c.deps.open ? ' is-waiting' : '') + (working ? ' is-working' : '') + (stale ? ' is-stale' : '') + (S.sel === c.id ? ' is-selected' : '');
        el.setAttribute('aria-label', c.id + ' ' + c.title);

        // what needs attention comes first, as tags; the rest is quiet
        const tags = [];
        if (asks(c)) tags.push(h('span', { class: 'tag purple', title: 'Waits on the owner\'s answer: ' + c.question }, svg('help'), 'Question'));
        else if (c.blocked) tags.push(h('span', { class: 'tag red', title: c.blocked }, svg('lock'), 'Blocked'));
        if (c.deps.open) tags.push(h('span', { class: 'tag amber', title: c.deps.open + ' of ' + c.deps.total + ' dependencies not done' }, svg('clock'), 'Waits on ' + c.deps.open));
        if (c.blocks) tags.push(h('span', { class: 'tag', title: c.blocks + ' open cards wait on this one; if they are one piece of work, fold them into it' }, svg('arrow-right'), 'Blocks ' + c.blocks));
        if (c.agent) tags.push(agentTag(c.agent));
        if (c.merge) tags.push(mergeTag(c.merge));
        const facts = [];
        const reason = c.question ?? c.blocked;
        if (reason) facts.push(h('span', { class: 'fact reason', title: reason, text: reason }));
        if (c.progress.total) facts.push(h('span', { class: 'fact', title: 'Acceptance criteria' }, ring(c.progress.done, c.progress.total), c.progress.done + '/' + c.progress.total));
        if (c.type === 'bug' || c.type === 'spike') facts.push(h('span', { class: 'fact type-' + c.type }, h('i', { class: 'dot' }), c.type));
        // the epic and the area come first: they say what the card is about, and a click shows only their cards
        if (c.epic) facts.push(filterChip('epic', c.epic.slug, c.epic.title, 'fact epic', svg('flag')));
        const areas = c.labels.filter((l) => l.startsWith('area:'));
        for (const area of areas) facts.push(filterChip('label', area, area.slice(5), 'fact area ' + areaClass(area), h('i', { class: 'dot' })));
        const labels = c.labels.filter((l) => !l.startsWith('area:'));
        for (const label of labels.slice(0, 1)) facts.push(h('span', { class: 'fact plain', text: label }));
        if (labels.length > 1) facts.push(h('span', { class: 'fact plain', title: labels.slice(1).join(', '), text: '+' + (labels.length - 1) }));
        if (c.url && /^https?:\/\//i.test(c.url)) facts.push(h('a', { class: 'fact link', href: c.url, target: '_blank', rel: 'noopener noreferrer', draggable: 'false', title: c.url }, svg('external'), hostOf(c.url)));
        el.replaceChildren(
            h('div', { class: 'c-top' },
                c.priority === 'normal' ? null : prioIcon(c.priority),
                h('span', { class: 'c-id', text: c.id }), h('span', { class: 'grow' }),
                h('span', { class: 'c-age', title: 'since ' + when(c.since), data: { since: c.since }, text: age(now() - c.since) }),
                moves ? (el._more ||= h('button', { class: 'icon-btn c-more', type: 'button', draggable: 'false', 'aria-label': 'Move card…', 'aria-haspopup': 'menu', 'aria-expanded': 'false', title: 'Move… (m)', onclick: (e) => { e.stopPropagation(); select(el.dataset.id); moveMenu(el.dataset.id, e.currentTarget); } }, svg('more'))) : null),
            h('a', { class: 'c-title', href: BASE + '/cards/' + c.id, draggable: 'false', title: c.title, text: c.title }),
            ...(tags.length || facts.length ? [h('div', { class: 'c-meta' }, tags, facts)] : []));
    }

    /** The colour slot the board gave an area: the order areas first appeared in, so the first ten never share one. */
    const areaClass = (area) => 'area-' + ((S.board && S.board.areas && S.board.areas[area]) ?? 0);
    /** A chip on a card that toggles the filter it names; it stays a button, so the card under it does not open. */
    function filterChip(key, value, text, cls, mark) {
        const on = S.filter[key].has(value);
        return h('button', { class: cls + (on ? ' is-on' : ''), type: 'button', draggable: 'false', 'aria-pressed': String(on),
            title: (on ? 'Show all cards again' : 'Show only these cards') + ' (' + (key === 'epic' ? 'epic ' : '') + value + ')',
            onclick: (e) => { e.stopPropagation(); toggleFilter(key, value); } }, mark, h('span', { text }));
    }

    /** Where an approved card stands in the merge queue: being merged, waiting for a red main, or at its place. */
    function mergeTag(merge) {
        if (merge.state === 'merging') return h('span', { class: 'tag green', title: 'Being merged into main by ' + merge.by + ' since ' + when(merge.since) }, svg('arrow-right'), 'Merging');
        if (merge.state === 'held') return h('span', { class: 'tag amber', title: 'It ' + merge.waits }, svg('clock'), 'Waits on main');
        return h('span', { class: 'tag', title: 'Approved: number ' + merge.position + ' in the merge queue' }, svg('clock'), 'Queued ' + merge.position);
    }

    /** An agent on a card: working (with how long), stale (no heartbeat) or stopped. */
    function agentTag(agent) {
        if (agent.state === 'working') return h('span', { class: 'tag green', title: 'An agent is working on this' }, h('span', { class: 'pulse' }), h('span', { data: { since: agent.since, prefix: 'Working ' }, text: 'Working ' + age(now() - agent.since) }));
        if (agent.state === 'stale') return h('span', { class: 'tag amber', title: 'No heartbeat from the agent' }, svg('alert'), h('span', { data: { since: agent.beat, prefix: 'Stale ' }, text: 'Stale ' + age(now() - agent.beat) }));
        return h('span', { class: 'tag', title: 'The agent has stopped', text: 'Stopped' });
    }

    function refreshAges() {
        for (const el of document.querySelectorAll('[data-since]')) {
            const seconds = now() - Number(el.dataset.since);
            el.textContent = (el.dataset.prefix || '') + age(seconds) + (el.dataset.suffix || '');
        }
    }

    function findCard(id) {
        if (!S.board) return null;
        for (const stage of S.board.stages) for (const c of stage.cards) if (c.id === id) return c;
        return null;
    }

    /** Puts a card the server returned into the board data (replaced in place, or moved to the top of its stage). */
    function place(card) {
        const summary = {};
        for (const key of SUMMARY) summary[key] = card[key];
        if (!S.board) return;
        for (const stage of S.board.stages) {
            const index = stage.cards.findIndex((c) => c.id === summary.id);
            if (index === -1) continue;
            if (stage.stage === summary.stage) { stage.cards[index] = summary; return; }
            stage.cards.splice(index, 1);
            stage.total--;
        }
        // a capped column (older done cards) may hold the card unseen: the next poll shows the truth
        const target = S.board.stages.find((s) => s.stage === summary.stage);
        if (target && !target.older) { target.cards.unshift(summary); target.total++; }
    }

    /* ---------- selection and keyboard ---------- */

    function select(id) {
        S.sel = id;
        for (const el of document.querySelectorAll('.card')) {
            const on = el.dataset.id === id;
            el.classList.toggle('is-selected', on);
            el.tabIndex = on ? 0 : -1;
        }
    }
    function selectionRestore() {
        if (S.sel && !findCard(S.sel)) S.sel = null;
        select(S.sel);
        // with nothing selected the first card is the one Tab reaches
        const first = !S.sel && ui.main.querySelector('.card:not([hidden])');
        if (first) first.tabIndex = 0;
    }
    function visibleColumns() {
        return S.board.stages.map((s) => ({ stage: s, cards: s.cards.filter(matches) })).filter((c) => !(c.stage.collapsed && S.collapsed.has(c.stage.stage)));
    }
    function moveSel(dx, dy) {
        const cols = visibleColumns();
        if (!cols.length) return;
        let ci = cols.findIndex((c) => c.cards.some((x) => x.id === S.sel));
        let index = ci === -1 ? -1 : cols[ci].cards.findIndex((x) => x.id === S.sel);
        if (ci === -1) { ci = Math.max(0, cols.findIndex((c) => c.cards.length)); index = -1; dy = dy || 1; dx = 0; }
        if (dx) {
            let n = ci;
            do { n += dx; } while (cols[n] && !cols[n].cards.length);
            if (!cols[n]) return;
            ci = n;
            index = Math.min(Math.max(index, 0), cols[n].cards.length - 1);
        } else index = Math.min(Math.max(index + dy, 0), cols[ci].cards.length - 1);
        const card = cols[ci].cards[index];
        if (!card) return;
        select(card.id);
        const el = document.querySelector('.card[data-id="' + CSS.escape(card.id) + '"]');
        if (el) { el.scrollIntoView({ block: 'nearest', inline: 'nearest' }); el.focus({ preventScroll: true }); }
    }

    document.addEventListener('keydown', (e) => {
        if (e.defaultPrevented) return;
        const target = e.target;
        const typing = target.matches && target.matches('input, textarea, select, [contenteditable]');
        if (e.altKey && !e.ctrlKey && !e.metaKey && e.code === 'KeyW' && S.stack.length) { e.preventDefault(); closeTop(); return; }
        const slot = /^Digit([1-9])$/.exec(e.code);
        if (slot && !e.ctrlKey && !e.metaKey && !ui.help.open) {
            if (e.altKey && !e.shiftKey) { e.preventDefault(); goPin(slot[1]); return; }
            if (e.shiftKey && !e.altKey && !typing) { e.preventDefault(); pinBoard(slot[1]); return; }
        }
        if (e.key === 'Escape') {
            if (ui.help.open) return;
            if (closeMenu()) return e.preventDefault();
            if (typing && target !== ui.q && !(S.composer && S.composer.el.contains(target))) { target.blur(); return; }
            if (S.composer) { closeComposer(); return e.preventDefault(); }
            if (target === ui.q) { if (ui.q.value || filterOn()) clearFilters(); ui.q.blur(); return; }
            if (S.stack.length) { closeTop(); return e.preventDefault(); }
            if (ui.q.value || filterOn()) clearFilters();
            return;
        }
        if (typing || e.metaKey || e.ctrlKey || e.altKey || ui.help.open) return;
        const keys = {
            '/': () => ui.q.focus(),
            n: () => openComposer(S.board && S.board.stages[0].stage),
            j: () => moveSel(0, 1), ArrowDown: () => moveSel(0, 1),
            k: () => moveSel(0, -1), ArrowUp: () => moveSel(0, -1),
            h: () => moveSel(-1, 0), ArrowLeft: () => moveSel(-1, 0),
            l: () => moveSel(1, 0), ArrowRight: () => moveSel(1, 0),
            Enter: () => openCard(target.dataset.id, { fresh: true }),
            m: () => S.sel && moveMenu(S.sel),
            p: () => S.sel && cyclePriority(S.sel),
            t: cycleTheme,
            b: toggleSwitcher,
            '?': () => ui.help.showModal(),
        };
        if (e.key === 'Enter' && !(target.classList && target.classList.contains('card'))) return;
        const action = keys[e.key];
        if (!action) return;
        if (['n', 'j', 'k', 'h', 'l', 'm', 'p', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key) && (S.route.name === 'boards' || !S.board)) return;
        e.preventDefault();
        action();
    });

    /* ---------- board events: open, drag ---------- */

    function bindBoard(el) {
        el.addEventListener('click', (e) => {
            const card = e.target.closest('.card');
            if (!card || e.target.closest('a[target]') || e.metaKey || e.ctrlKey || e.shiftKey) return;
            e.preventDefault();
            select(card.dataset.id);
            openCard(card.dataset.id, { fresh: true });
        });
        el.addEventListener('dragstart', (e) => {
            const card = e.target.closest && e.target.closest('.card');
            if (!card) return;
            S.drag = card.dataset.id;
            select(S.drag);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', S.drag);
            // after the browser has taken the picture it drags
            setTimeout(() => {
                if (!S.drag) return;
                card.classList.add('is-dragging');
                el.classList.add('is-dragging');
                for (const col of columns.values()) {
                    col.el.classList.toggle('can-drop', dragAllowed(col.stage));
                    col.el.classList.toggle('is-source', col.cards.has(S.drag));
                }
            }, 0);
        });
        el.addEventListener('dragend', clearDrag);
    }
    function clearDrag() {
        S.drag = null;
        for (const node of document.querySelectorAll('.is-dragging, .is-over, .can-drop, .is-source')) node.classList.remove('is-dragging', 'is-over', 'can-drop', 'is-source');
    }
    function dragAllowed(stage) {
        const card = S.drag && findCard(S.drag);
        return !!card && movesOf(card).includes(stage);
    }

    /* ---------- moving cards ---------- */

    async function moveCard(id, to, anchor) {
        const card = cardOf(id);
        if (!card || card.stage === to) return;
        let reason = null;
        if (to === 'dropped') {
            reason = await askReason(anchor || document.querySelector('.card[data-id="' + CSS.escape(id) + '"]') || ui.main, 'Why drop ' + id + '?');
            if (reason === null) { renderDrawer(); return; }
        }
        const before = JSON.parse(JSON.stringify(S.board));
        const optimistic = { ...card, stage: to, since: now() };
        place(optimistic);
        renderView();
        try {
            await writing(async () => {
                const sent = rev(id);
                const { data } = await api('/cards/' + id + '/stage', { method: 'POST', body: { to, reason, rev: sent } });
                adoptOwn(data.card, sent);
            }, id);
        } catch (e) {
            S.board = before;
            handleError(e);
            await refresh();
        }
        renderDrawer();
    }
    async function cyclePriority(id) {
        const card = findCard(id);
        if (!card) return;
        if (isLocked(card)) { toast(`${card.id} is in ${card.stage}, a locked stage: its priority cannot change`); return; }
        const next = PRIORITIES[(PRIORITIES.indexOf(card.priority) + 1) % PRIORITIES.length];
        await save(id, { priority: next });
    }

    /**
     * Changes a card's fields; the answer replaces what the board and drawer know of it. $patch is an object, or a function
     * of the card as it is when the write's turn comes, so two quick edits build on each other instead of on a stale copy.
     * $base is the rev an in-place text edit works from (the panel's `base`: taken when the edit starts, moved on by the user's own writes):
     * if the card changed elsewhere while the user typed, the server answers 409 instead of taking the text over the change.
     * With $settle a 409 is not shown: the card the answer carries is taken over and null comes back, so the caller can decide
     * from the content what happens to the typed text (true saved, false refused).
     */
    async function save(id, patch, base = null, settle = false) {
        try {
            await writing(async () => {
                const card = cardOf(id);
                const sent = base || card.rev;
                const { data } = await api('/cards/' + id, { method: 'PATCH', body: { ...(typeof patch === 'function' ? patch(card) : patch), rev: sent } });
                adoptOwn(data.card, sent);
            }, id);
            return true;
        } catch (e) {
            if (settle && e.status === 409) {
                if (e.data && e.data.card) adopt(e.data.card, true);

                return null;
            }
            handleError(e);
            return false;
        } finally {
            renderDrawer();
        }
    }

    /**
     * The card as the user's own write left it. That write was sent against rev $sent: an edit in progress that started from the
     * same rev carries on from the new one instead of finding the card changed elsewhere; one that started earlier keeps its
     * guard, because somebody else's change came between.
     */
    function adoptOwn(card, sent) {
        const panel = panels.get(card.id);
        if (panel) clearMark(panel);
        const carried = panel ? [...panel.guards].filter((guard) => guard.base === sent) : [];
        adopt(card);
        for (const guard of carried) guard.base = card.rev;
    }
    function adopt(card, elsewhere = false) {
        const panel = panels.get(card.id);
        if (panel && elsewhere) noteChange(panel, panel.detail, card);
        if (panel) panel.detail = card;
        place(card);
        renderView();
        if (panel) renderPanel(panel);
        if (topId() === card.id) document.title = card.id + ' · ' + card.title;
    }

    function handleError(e) {
        if (e.status === 409) {
            if (e.data && e.data.card) adopt(e.data.card, true);
            toast('This card changed elsewhere. Showing the latest version.', 'err');
        } else toastError(e);
    }
    function toastError(e) {
        const details = (e.data && e.data.details) || [];
        toast(details.length && e.status === 422 && e.data.message.startsWith('refused') ? 'Not allowed' : (e.data && e.data.message) || e.message, 'err', details);
    }

    /* ---------- toasts and menus ---------- */

    /** A message that goes by itself (longer for errors) and stays while the pointer is on it; $kind is '', 'ok' or 'err'. */
    function toast(message, kind = '', details = []) {
        let timer = 0;
        const close = () => { clearTimeout(timer); el.remove(); };
        const arm = () => { clearTimeout(timer); timer = setTimeout(close, kind === 'err' ? 8000 : 3500); };
        const icon = kind === 'err' ? 'alert' : kind === 'ok' ? 'check' : null;
        const el = h('div', { class: 'toast ' + kind, role: kind === 'err' ? 'alert' : 'status', onmouseenter: () => clearTimeout(timer), onmouseleave: arm },
            icon ? svg(icon) : null,
            h('div', { class: 'toast-b' }, h('div', { class: 'toast-m', text: message }), details.length ? h('ul', {}, details.map((d) => h('li', { text: d }))) : null),
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Dismiss', onclick: close }, svg('x')));
        ui.toasts.append(el);
        arm();
    }

    let menu = null;
    function closeMenu() {
        if (!menu) return false;
        const { el, done, trigger, opener } = menu;
        menu = null;
        el.remove();
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
        if (opener && opener.isConnected && (!document.activeElement || document.activeElement === document.body)) opener.focus({ preventScroll: true });
        if (done) done(null);
        return true;
    }
    /**
     * A popover under (or, short of room, above) $anchor. $trigger is the button that opened it: pressing it again closes the
     * menu instead of reopening it. $focus is the element that takes the focus.
     */
    function openMenu(anchor, content, done, { trigger = null, role = 'menu', focus = null, minWidth = 220 } = {}) {
        closeMenu();
        const el = h('div', { class: 'menu', role }, content);
        document.body.append(el);
        const box = anchor.getBoundingClientRect();
        const room = innerHeight - 8;
        const below = box.bottom + 4;
        el.style.minWidth = Math.max(minWidth, Math.min(box.width, 340)) + 'px';
        el.style.left = Math.max(8, Math.min(box.left, innerWidth - el.offsetWidth - 8)) + 'px';
        el.style.top = Math.max(8, below + el.offsetHeight > room && box.top - 4 - el.offsetHeight > 8 ? box.top - 4 - el.offsetHeight : Math.min(below, room - el.offsetHeight)) + 'px';
        menu = { el, done, anchor, trigger, opener: document.activeElement };
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
        const first = focus || el.querySelector('input, button');
        if (first) first.focus({ preventScroll: true });
        return el;
    }
    // a press outside closes the menu; a press on its own trigger keeps the focus where it is, and the click that follows closes it
    document.addEventListener('mousedown', (e) => {
        if (!menu || menu.el.contains(e.target)) return;
        if (menu.trigger && menu.trigger.contains(e.target)) e.preventDefault(); else closeMenu();
    });

    /* ---------- lists: choices and actions in a popover ---------- */

    const SEARCH_ABOVE = 5;
    let listSeq = 0;

    /**
     * A popover list. $items are { value, label, hint?, icon?, checked?, current?, disabled?, keys?, search? } rows plus
     * { heading } and { separator: true }. $kind is 'listbox' for choices, 'menu' for actions; $multi rows toggle and the list
     * stays open; $numbered lets the digits pick a row. With more than SEARCH_ABOVE choices the list has a search box
     * (override with $search). The focus stays in the search box, or on the list when there is none: the arrows move a
     * highlight, Enter picks it, typing on a short list jumps to the row that starts with it.
     */
    function openList(anchor, items, { kind = 'listbox', multi = false, title = null, trigger = null, search = null, numbered = false, minWidth = 220, limit = Infinity, onPick }) {
        const listId = 'list-' + ++listSeq;
        const searchable = search ?? items.filter((item) => !item.heading && !item.separator).length > SEARCH_ABOVE;
        const role = kind === 'menu' ? (multi ? 'menuitemcheckbox' : 'menuitem') : 'option';
        const state = kind === 'menu' ? 'aria-checked' : 'aria-selected';
        const rows = [];
        let active = null;
        let typed = '';
        let typedAt = 0;

        const nodes = items.map((item, index) => {
            if (item.separator) return h('hr');
            if (item.heading) return h('div', { class: 'menu-h', role: 'presentation', text: item.heading });
            const num = numbered && !item.keys ? h('kbd', { class: 'num' }) : null;
            const row = h('button', { class: 'menu-item', type: 'button', role, id: listId + '-' + index, tabindex: '-1', disabled: !!item.disabled,
                'aria-current': item.current ? 'page' : null,
                onmousedown: (e) => e.preventDefault(), onmousemove: () => highlight(row), onclick: () => pick(row) },
                h('span', { class: multi ? 'box' : 'tick' }, svg('check')), item.icon ? item.icon() : null, h('span', { class: 'grow' }, item.label),
                item.hint !== undefined ? h('span', { class: 'n', text: item.hint }) : null,
                item.keys && !searchable ? h('kbd', { text: item.keys }) : null, num);
            row.item = item;
            row.num = num;
            row.classList.toggle('is-on', !!item.checked);
            if (multi || kind === 'listbox') row.setAttribute(state, String(!!item.checked));
            rows.push(row);
            return row;
        });
        const shown = () => rows.filter((row) => !row.hidden && !row.disabled);
        /** The digits go to the first nine rows in view, so they follow a search. */
        const renumber = () => {
            if (!numbered) return;
            for (const row of rows) if (row.num) row.num.hidden = true;
            shown().filter((row) => row.num).slice(0, 9).forEach((row, i) => { row.num.textContent = String(i + 1); row.num.hidden = false; });
        };
        const empty = h('div', { class: 'menu-empty', role: 'presentation', text: 'No matches', hidden: true });
        // past $limit rows the list shows the first ones and the picked ones, and says how many it keeps back; a search reaches all of them
        const more = rows.length > limit ? h('div', { class: 'menu-more', role: 'presentation' }) : null;
        const noteRest = (q) => {
            if (!more) return;
            const rest = rows.filter((row) => row.hidden).length;
            more.hidden = !!q || !rest;
            more.textContent = rest + ' more: type to find them';
        };
        rows.forEach((row, i) => { row.hidden = i >= limit && !row.classList.contains('is-on'); });
        noteRest('');
        const input = searchable ? h('input', { class: 'menu-search-input', type: 'text', role: 'combobox', 'aria-expanded': 'true', 'aria-controls': listId, 'aria-autocomplete': 'list', autocomplete: 'off', spellcheck: 'false',
            placeholder: title ? 'Search ' + title.toLowerCase() + '…' : 'Search…', 'aria-label': title ? 'Search ' + title.toLowerCase() : 'Search', oninput: () => filter(input.value) }) : null;
        const list = h('div', { class: 'menu-list', id: listId, role: kind === 'menu' ? 'menu' : 'listbox', 'aria-label': title, 'aria-multiselectable': multi ? 'true' : null, tabindex: input ? null : '-1' }, nodes, empty, more);

        function highlight(row, scroll = false) {
            const holder = input || list;
            if (active) active.classList.remove('is-active');
            active = row;
            if (!row) { holder.removeAttribute('aria-activedescendant'); return; }
            row.classList.add('is-active');
            holder.setAttribute('aria-activedescendant', row.id);
            if (scroll) row.scrollIntoView({ block: 'nearest' });
        }
        function pick(row) {
            if (row.disabled) return;
            if (multi) {
                const on = !row.classList.contains('is-on');
                row.classList.toggle('is-on', on);
                row.setAttribute(state, String(on));
                onPick(row.item, on);
                return;
            }
            closeMenu();
            onPick(row.item);
        }
        function filter(query) {
            const q = query.trim().toLowerCase();
            let heading = null;
            let index = 0;
            for (const node of nodes) {
                if (node.classList.contains('menu-h') || node.tagName === 'HR') { node.hidden = !!q; if (!q || node.tagName === 'HR') continue; heading = node; continue; }
                node.hidden = q ? !(node.item.search ?? node.item.label).toLowerCase().includes(q) : index >= limit && !node.classList.contains('is-on');
                index++;
                if (!node.hidden && heading) heading.hidden = false;
            }
            noteRest(q);
            renumber();
            empty.hidden = !q || shown().length > 0;
            highlight(shown()[0] || null, true);
        }
        function onKey(e) {
            const choices = shown();
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!choices.length) return;
                const at = choices.indexOf(active);
                const to = at === -1 ? (e.key === 'ArrowDown' ? 0 : choices.length - 1) : (at + (e.key === 'ArrowDown' ? 1 : -1) + choices.length) % choices.length;
                highlight(choices[to], true);
            } else if ((e.key === 'Home' || e.key === 'End') && !input) {
                e.preventDefault();
                highlight(choices[e.key === 'Home' ? 0 : choices.length - 1] || null, true);
            } else if (e.key === 'Enter' || (e.key === ' ' && !input)) {
                e.preventDefault();
                if (active) pick(active);
            } else if (numbered && /^[1-9]$/.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey && (!input || !input.value) && choices.filter((row) => row.num)[Number(e.key) - 1]) {
                e.preventDefault();
                pick(choices.filter((row) => row.num)[Number(e.key) - 1]);
            } else if (!input && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                const keyed = choices.find((row) => row.item.keys && row.item.keys.toLowerCase() === e.key.toLowerCase());
                if (keyed) { pick(keyed); return; }
                const now = Date.now();
                typed = now - typedAt > 700 ? e.key : typed + e.key;
                typedAt = now;
                const hit = choices.find((row) => row.item.label.toLowerCase().startsWith(typed.toLowerCase()));
                if (hit) highlight(hit, true);
            } else if (e.key === 'Tab') closeMenu();
            else if (!input && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) e.preventDefault();
        }

        const el = openMenu(anchor, [!input && title && kind === 'menu' ? h('div', { class: 'menu-h', role: 'presentation', text: title }) : null,
            input ? h('div', { class: 'menu-search' }, svg('search'), input) : null, list], null, { trigger, role: null, focus: input || list, minWidth });
        el.addEventListener('keydown', onKey);
        el.addEventListener('mousedown', (e) => { if (!e.target.closest('input') && e.target !== list) e.preventDefault(); });
        el.addEventListener('focusout', (e) => { if (menu && menu.el === el && !el.contains(e.relatedTarget) && !(menu.trigger && menu.trigger.contains(e.relatedTarget))) closeMenu(); });
        renumber();
        highlight(rows.find((row) => row.classList.contains('is-on') && !row.disabled && !row.hidden) || shown()[0] || null, true);
        return el;
    }

    /** A dropdown that shows its value: a button that opens a list of choices ({ value, label, disabled? }). $mark(value) draws the small mark beside a value, in the button and in the list. */
    function selectPill(name, choices, value, onPick, mark = null) {
        const text = h('span', { class: 'pill-value' });
        const slot = mark ? h('span', { class: 'pill-mark' }) : null;
        const el = h('button', { class: 'pill', type: 'button', 'aria-haspopup': 'listbox', 'aria-expanded': 'false', data: { field: name.toLowerCase() } },
            h('span', { class: 'vh', text: name + ': ' }), slot, text, svg('chevron-down'));
        const pill = {
            el, choices, value,
            set(next, options = pill.choices) {
                pill.choices = options;
                pill.value = next;
                text.textContent = (options.find((o) => o.value === next) || { label: next }).label;
                if (slot) slot.replaceChildren(mark(next));
            },
        };
        el.addEventListener('click', () => {
            if (menu && menu.trigger === el) { closeMenu(); return; }
            openList(el, pill.choices.map((o) => ({ ...o, checked: o.value === pill.value, icon: mark ? () => mark(o.value) : undefined })), { kind: 'listbox', title: name, trigger: el, onPick: (item) => { if (item.value !== pill.value) onPick(item.value); } });
        });
        el.addEventListener('keydown', (e) => { if (e.key === 'ArrowDown') { e.preventDefault(); el.click(); } });
        pill.set(value);
        return pill;
    }

    function askReason(anchor, title) {
        return new Promise((resolve) => {
            const input = h('input', { class: 'field', type: 'text', maxlength: '500', placeholder: 'Reason', 'aria-label': 'Reason', required: true });
            const form = h('form', { onsubmit: (e) => {
                e.preventDefault();
                if (!input.value.trim()) return;
                const value = input.value.trim();
                menu.done = null;
                closeMenu();
                resolve(value);
            } }, h('h4', { text: title }), input, h('div', { class: 'controls' }, h('button', { class: 'btn primary small', text: 'Drop it' }), h('button', { class: 'btn small', type: 'button', text: 'Cancel', onclick: () => closeMenu() })));
            openMenu(anchor, form, resolve, { role: 'dialog' });
        });
    }

    function moveMenu(id, trigger = null) {
        if (trigger && menu && menu.trigger === trigger) { closeMenu(); return; }
        const card = findCard(id);
        const el = document.querySelector('.card[data-id="' + CSS.escape(id) + '"]');
        if (!card || !el) return;
        const stages = movesOf(card);
        if (!stages.length) { toast(CLI_ONWARD[card.held ? 'held' : card.stage] || 'The command line moves this card'); return; }
        openList(el, [{ heading: 'Move ' + id + ' to' }, ...stages.map((stage) => ({ value: stage, label: stage }))], { kind: 'menu', numbered: true, search: false, trigger, onPick: (item) => moveCard(id, item.value, el) });
    }

    /* ---------- composer (new card) ---------- */

    function openComposer(stage) {
        if (!S.board || S.route.name === 'boards') return;
        closeComposer();
        const col = columns.get(stage || S.board.stages[0].stage);
        if (!col) return;
        const title = h('input', { type: 'text', placeholder: 'What needs doing?', maxlength: '120', 'aria-label': 'Title' });
        const type = selectPill('Type', TYPES.map((t) => ({ value: t, label: t })), 'feature', (next) => type.set(next));
        const priority = selectPill('Priority', PRIORITIES.map((p) => ({ value: p, label: p })), 'normal', (next) => priority.set(next), priorityMark);
        const submit = async (open) => {
            const value = title.value.trim();
            if (!value) return;
            title.disabled = true;
            try {
                const { data } = await writing(() => api('/' + S.ref + '/cards', { method: 'POST', body: { title: value, priority: priority.value, type: type.value, ...(S.filter.epic.size === 1 ? { epic: [...S.filter.epic][0] } : {}) } }));
                place(data.card);
                title.value = '';
                renderView();
                if (open) { closeComposer(); openCard(data.card.id, { fresh: true }); return; }
            } catch (e) { handleError(e); }
            title.disabled = false;
            title.focus();
        };
        const el = h('form', { class: 'composer', onsubmit: (e) => { e.preventDefault(); submit(false); } },
            title,
            h('div', { class: 'row' }, type.el, priority.el, h('span', { class: 'grow' }), h('button', { class: 'btn primary small', type: 'submit', text: 'Add' })),
            h('p', { class: 'hint' }, kbd('Enter'), ' adds, ', kbd('Shift'), '+', kbd('Enter'), ' adds and opens, ', kbd('Esc'), ' closes'));
        title.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.shiftKey) { e.preventDefault(); submit(true); } });
        S.composer = { el, stage: col.stage };
        col.body.prepend(el);
        col.el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        title.focus();
    }
    function closeComposer() {
        if (!S.composer) return;
        S.composer.el.remove();
        S.composer = null;
    }

    /* ---------- filters ---------- */

    const filterOn = () => !!(S.filter.q || S.filter.priority.size || S.filter.type.size || S.filter.label.size || S.filter.epic.size || S.filter.flag.size);
    function matches(c) {
        const f = S.filter;
        if (f.q) {
            const q = f.q.toLowerCase();
            if (!(c.title.toLowerCase().includes(q) || c.id.toLowerCase().includes(q) || c.labels.some((l) => l.toLowerCase().includes(q)))) return false;
        }
        if (f.priority.size && !f.priority.has(c.priority)) return false;
        if (f.type.size && !f.type.has(c.type)) return false;
        if (f.label.size && !c.labels.some((l) => f.label.has(l))) return false;
        if (f.epic.size && !(c.epic && f.epic.has(c.epic.slug))) return false;
        if (f.flag.has('blocked') && !c.blocked) return false;
        if (f.flag.has('agent') && !(c.agent && c.agent.state === 'working')) return false;
        if (f.flag.has('waiting') && !c.deps.open) return false;
        return true;
    }
    function filtersChanged() {
        const p = new URLSearchParams();
        if (S.filter.q) p.set('q', S.filter.q);
        for (const [key, short] of [['priority', 'p'], ['type', 't'], ['label', 'l'], ['epic', 'e'], ['flag', 'f']]) if (S.filter[key].size) p.set(short, [...S.filter[key]].join(','));
        const under = new URLSearchParams(location.search).get('from');
        if (under) p.set('from', under);
        history.replaceState(history.state, '', location.pathname + (p.toString() ? '?' + p : ''));
        if (S.board && S.route.name !== 'boards') renderBoard();
        renderToolbar();
    }
    function readFilterFromUrl() {
        const p = new URLSearchParams(location.search);
        const list = (key) => new Set((p.get(key) || '').split(',').filter(Boolean));
        S.filter = { q: p.get('q') || '', priority: list('p'), type: list('t'), label: list('l'), epic: list('e'), flag: list('f') };
        if (ui.q && document.activeElement !== ui.q) ui.q.value = S.filter.q;
    }
    function clearFilters() {
        S.filter = { q: '', priority: new Set(), type: new Set(), label: new Set(), epic: new Set(), flag: new Set() };
        ui.q.value = '';
        filtersChanged();
    }
    function toggleFilter(key, value) {
        const set = S.filter[key];
        if (set.has(value)) set.delete(value); else set.add(value);
        filtersChanged();
    }

    /* ---------- toolbar: quick filters that double as a board summary ---------- */

    const QUIET = ['done', 'dropped'];
    const FLAGS = [['blocked', 'lock', 'Blocked'], ['waiting', 'clock', 'Waiting'], ['agent', null, 'Working']];
    let toolbarSig = '';

    function renderToolbar() {
        const on = S.route.name !== 'boards' && !!S.board;
        ui.toolbar.hidden = !on;
        if (!on) { toolbarSig = ''; return; }
        const f = S.filter;
        const all = S.board.stages.flatMap((stage) => stage.cards);
        const live = S.board.stages.filter((stage) => !QUIET.includes(stage.stage)).flatMap((stage) => stage.cards);
        const counts = { blocked: live.filter((c) => c.blocked).length, waiting: live.filter((c) => c.deps.open).length, agent: live.filter((c) => c.agent && c.agent.state === 'working').length };
        const labels = [...new Set(all.flatMap((c) => c.labels))].sort();
        const types = [...new Set(all.map((c) => c.type))].sort();
        const titles = new Map(epicList().map((e) => [e.slug, e.title]));
        for (const c of all) if (c.epic) titles.set(c.epic.slug, c.epic.title);
        const epics = [...titles].filter(([slug]) => all.some((c) => c.epic && c.epic.slug === slug) || f.epic.has(slug)).map(([value, label]) => ({ value, label }));
        const shown = all.filter(matches).length;

        ui.result.textContent = filterOn() ? 'Showing ' + shown + ' of ' + all.length : '';
        ui.clear.hidden = !filterOn();
        const sig = JSON.stringify([counts, labels, types, epics, [...f.priority], [...f.type], [...f.label], [...f.epic], [...f.flag]]);
        if (sig === toolbarSig) return;
        toolbarSig = sig;

        const toggle = ([flag, icon, name]) => {
            const pressed = f.flag.has(flag);
            return h('button', { class: 'tgl', type: 'button', data: { flag }, 'aria-pressed': String(pressed), 'aria-disabled': !counts[flag] && !pressed ? 'true' : null,
                onclick: () => { if (counts[flag] || f.flag.has(flag)) toggleFilter('flag', flag); } },
                icon ? svg(icon) : h('span', { class: 'pulse' }), name, h('span', { class: 'n', text: counts[flag] }));
        };
        const dropdown = (key, name, values) => {
            const options = values.map((o) => (typeof o === 'string' ? { value: o, label: o } : o));
            const chosen = [...f[key]];
            const first = chosen.length ? (options.find((o) => o.value === chosen[0]) || { label: chosen[0] }).label : '';
            const chip = h('button', { class: 'tgl' + (chosen.length ? ' is-active' : ''), type: 'button', data: { filter: key }, 'aria-haspopup': 'listbox', 'aria-expanded': 'false', onclick: () => filterMenu(chip, key, name, options) },
                chosen.length ? name + ': ' + first + (chosen.length > 1 ? ' +' + (chosen.length - 1) : '') : name, svg('chevron-down'));
            return chip;
        };
        const held = ui.chips.contains(document.activeElement) ? document.activeElement : null;
        const heldKey = held && (held.dataset.flag ? '[data-flag="' + held.dataset.flag + '"]' : '[data-filter="' + held.dataset.filter + '"]');
        ui.chips.replaceChildren(...FLAGS.map(toggle), h('span', { class: 'divider' }),
            ...[epics.length ? dropdown('epic', 'Epic', epics) : null, dropdown('priority', 'Priority', PRIORITIES), types.length > 1 ? dropdown('type', 'Type', types) : null, labels.length ? dropdown('label', 'Label', labels) : null].filter(Boolean));
        if (heldKey) { const again = ui.chips.querySelector(heldKey); if (again) again.focus({ preventScroll: true }); }

        // an open filter menu keeps pointing at its chip, which was just rebuilt
        if (menu && menu.trigger && menu.trigger.dataset.filter) {
            const again = ui.chips.querySelector('[data-filter="' + menu.trigger.dataset.filter + '"]');
            if (again) { menu.trigger = menu.anchor = menu.opener = again; again.setAttribute('aria-expanded', 'true'); }
        }
    }

    function filterMenu(chip, key, name, options) {
        if (menu && menu.trigger === chip) { closeMenu(); return; }
        openList(chip, options.map((o) => ({ ...o, checked: S.filter[key].has(o.value) })), { kind: 'listbox', multi: true, title: name, trigger: chip, limit: 20, onPick: (item) => toggleFilter(key, item.value) });
    }

    /* ---------- cards: layered panels ---------- */

    // A card opens in a panel; a link to another card inside it opens that one on top, to the right, and so on. S.stack holds the
    // open cards bottom first, the URL holds the same (`/cards/TOP?from=BOTTOM,MIDDLE`), so Back, reload and sharing keep it.
    const panels = new Map();
    const drafts = new Map();
    let closeTimer = 0;
    let hintSeq = 0;
    const kbd = (key) => h('kbd', { text: key });

    const topId = () => S.stack[S.stack.length - 1] || null;
    const detailOf = (id) => (panels.get(id) || {}).detail || null;
    const cardOf = (id) => detailOf(id) || findCard(id);
    const rev = (id) => (cardOf(id) || {}).rev;
    const prop = (label, ...content) => h('div', { class: 'prop' }, h('span', { class: 'prop-k', text: label }), h('div', { class: 'prop-v' }, content));

    /** The address of a stack of cards: the top one in the path, the ones under it (bottom first) in `from`. */
    function stackUrl(ids) {
        const p = new URLSearchParams(location.search);
        p.delete('from');
        if (ids.length > 1) p.set('from', ids.slice(0, -1).join(','));
        return BASE + '/cards/' + encodeURIComponent(ids[ids.length - 1]) + (p.toString() ? '?' + p : '');
    }
    /** A board's address, with the filters but without a stack. */
    function boardUrl(ref) {
        const p = new URLSearchParams(location.search);
        p.delete('from');
        return BASE + '/' + ref + (p.toString() ? '?' + p : '');
    }

    /** Opens a card on its own (`fresh`, from the board) or on top of the ones already open (from a link inside a card). */
    function openCard(id, { fresh = false } = {}) {
        const at = S.stack.indexOf(id);
        const next = fresh || !S.stack.length ? [id] : at !== -1 ? S.stack.slice(0, at + 1) : [...S.stack, id];
        if (next.join() === S.stack.join()) { focusCard(panels.get(id)); return; }
        return go(stackUrl(next));
    }
    /** A link to a card: a plain click opens it in the stack, a modified click keeps the browser's way. */
    function cardLink(e, id) {
        if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        openCard(id);
    }
    /** Closes the card on top, the last one opened. */
    function closeTop() { popTo(S.stack.length - 2); }
    /** Keeps the cards up to index $at, closes the ones above them; -1 closes all. */
    function popTo(at) {
        if (!S.stack.length || at >= S.stack.length - 1) return;
        const closed = S.stack.length - (at + 1);
        S.stack = S.stack.slice(0, at + 1);
        syncPanels();
        const target = S.stack.length ? stackUrl(S.stack) : (S.ref ? boardUrl(S.ref) : BASE);
        if (closed === 1 && history.state && history.state.prev === target) history.back(); else go(target, true);
        if (S.stack.length) focusCard(panels.get(topId()));
        else {
            const el = S.sel && document.querySelector('.card[data-id="' + CSS.escape(S.sel) + '"]');
            if (el) el.focus({ preventScroll: true });
        }
    }

    /** Makes the panels match the stack: builds the new ones, drops the ones that are gone, lays them out. */
    function syncPanels() {
        clearTimeout(closeTimer);
        const open = S.stack.length > 0;
        ui.drawer.hidden = ui.scrim.hidden = !open;
        if (!open) {
            closeTimer = setTimeout(() => { for (const p of [...panels.values()]) destroyPanel(p); }, 400);
            return;
        }
        for (const p of [...panels.values()]) if (!S.stack.includes(p.id)) destroyPanel(p);
        S.stack.forEach((id, i) => {
            let p = panels.get(id);
            if (!p) {
                p = buildPanel(id);
                panels.set(id, p);
                p.el.classList.add(i > 0 ? 'is-entering' : 'is-opening');
                p.el.addEventListener('animationend', () => p.el.classList.remove('is-entering', 'is-opening'), { once: true });
                ui.drawer.append(p.el);
                renderPanel(p);
            }
            const top = i === S.stack.length - 1;
            p.el.style.setProperty('--i', i);
            p.el.classList.toggle('is-top', top);
            p.el.classList.toggle('is-under', !top);
            p.inner.inert = !top;
            p.backText.textContent = i ? S.stack[i - 1] : 'Board';
            p.back.setAttribute('aria-label', i ? 'Back to ' + S.stack[i - 1] : 'Back to the board');
        });
        ui.drawer.style.setProperty('--depth', S.stack.length - 1);
    }
    function destroyPanel(p) {
        if (p.editor && p.editor.area.value !== p.editor.raw) drafts.set(p.id + ':' + p.editor.field, { text: p.editor.area.value, raw: p.editor.raw, rev: p.editor.guard.base });
        clearTimeout(p.savedTimer);
        clearTimeout(p.depTimer);
        clearTimeout(p.markTimer);
        p.noteWatch.disconnect();
        p.el.remove();
        panels.delete(p.id);
    }
    /** Fetches the cards of the panels that have none yet; returns the ids that do not exist. */
    async function loadPanels() {
        const gone = [];
        await Promise.all(S.stack.map(async (id) => {
            const p = panels.get(id);
            if (!p || p.detail) return;
            try {
                const { data } = await api('/cards/' + encodeURIComponent(id));
                p.detail = data.card;
                if (data.card.id !== id) rekey(p, data.card.id);
                renderPanel(p);
            } catch (e) {
                if (e.status !== 404) throw e;
                gone.push(id);
            }
        }));
        return gone;
    }
    /** The address named the card by a prefix; from here on it goes by its full id. */
    function rekey(p, id) {
        const at = S.stack.indexOf(p.id);
        panels.delete(p.id);
        p.id = id;
        panels.set(id, p);
        if (at !== -1) S.stack[at] = id;
        history.replaceState(history.state, '', stackUrl(S.stack));
    }
    const agentKey = (agent) => (agent ? agent.state + ':' + agent.since : '');
    const sameCard = (a, b) => a.rev === b.rev && JSON.stringify(a.deps) === JSON.stringify(b.deps) && agentKey(a.agent) === agentKey(b.agent);
    /** After a poll: a panel whose card changed elsewhere (or is not on the board to tell) fetches it again. */
    async function refreshPanels() {
        if (!S.stack.length || S.busy) return;
        await Promise.all(S.stack.map(async (id) => {
            const p = panels.get(id);
            const summary = findCard(id);
            if (!p || !p.detail || (summary && sameCard(summary, p.detail))) return;
            try {
                const { data } = await api('/cards/' + encodeURIComponent(id));
                if (S.busy || panels.get(id) !== p) return;
                const changed = !sameCard(data.card, p.detail);
                if (changed) noteChange(p, p.detail, data.card);
                p.detail = data.card;
                if (changed) renderPanel(p);
            } catch { /* the next poll retries */ }
        }));
    }
    function renderDrawer() { for (const p of panels.values()) renderPanel(p); }

    /** The Clipboard API exists only on secure origins; worktree stacks are served over plain http on the LAN. */
    async function copyText(value) {
        const focused = document.activeElement;
        try {
            if (navigator.clipboard && isSecureContext) await navigator.clipboard.writeText(value);
            else {
                const field = h('textarea', { class: 'vh', 'aria-hidden': 'true', readonly: true });
                field.value = value;
                document.body.append(field);
                field.select();
                const ok = document.execCommand('copy');
                field.remove();
                if (focused && focused.focus) focused.focus({ preventScroll: true });
                if (!ok) throw new Error('copy refused');
            }
            toast('Copied ' + value, 'ok');
        } catch { toast(value, ''); }
    }

    /** A card that was just opened has the focus on itself, not in a field: the first Tab goes into its name, and Esc closes it. */
    function focusCard(p) {
        if (!p || !p.detail || p.editing) return;
        p.body.focus({ preventScroll: true });
    }
    /** The note box is two lines tall and grows with what is typed, up to six; then it scrolls. */
    function growNote(P) {
        const box = P.note;
        box.style.height = 'auto';
        const style = getComputedStyle(box);
        const limit = parseFloat(style.lineHeight) * 6 + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom) + 2;
        box.style.height = Math.min(box.scrollHeight + 2, limit) + 'px';
        box.style.overflowY = box.scrollHeight + 2 > limit ? 'auto' : 'hidden';
    }
    function grow(p) { p.title.style.height = 'auto'; p.title.style.height = p.title.scrollHeight + 2 + 'px'; p.title.scrollTop = 0; }

    function flashSaved(id, state) {
        const p = panels.get(id);
        if (!p) return;
        clearTimeout(p.savedTimer);
        p.saved.classList.toggle('is-saved', state === 'saved');
        p.saved.replaceChildren(...(state === 'saving' ? [h('span', { text: 'Saving…' })] : state === 'saved' ? [svg('check'), h('span', { text: 'Saved' })] : []));
        if (state === 'saved') p.savedTimer = setTimeout(() => p.saved.replaceChildren(), 1600);
    }

    function buildPanel(id) {
        // a guard is the rev an editor's text started from; the editor's save is refused if the card has moved on since
        const P = { id, detail: null, guards: new Set(), titleGuard: { base: null }, editing: null, editor: null, criterionEditing: false, shown: false, focused: false, savedTimer: 0, depTimer: 0 };

        P.guards.add(P.titleGuard);
        P.idText = h('span', { text: id });
        P.idBtn = h('button', { class: 'd-id', type: 'button', title: 'Copy the id', onclick: () => copyText(P.id) }, P.idText, svg('copy'));
        P.board = h('a', { class: 'd-board', href: BASE, onclick: nav });
        P.saved = h('span', { class: 'd-saved', 'aria-live': 'polite' });
        P.backText = h('span');
        P.back = h('button', { class: 'btn ghost small d-back', type: 'button', onclick: () => closeTop() }, svg('chevron-right'), P.backText);
        P.head = h('header', { class: 'd-head' }, P.back, P.idBtn, h('span', { class: 'muted', text: '·' }), P.board, h('span', { class: 'grow' }), P.saved,
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Close', title: 'Close (Alt+W)', onclick: () => closeTop() }, svg('x')));

        P.title = h('textarea', { class: 'd-title', rows: '1', 'aria-label': 'Title', maxlength: '120', placeholder: 'Card name', title: 'Click to rename',
            onkeydown: (e) => {
                if (e.key === 'Enter') { e.preventDefault(); P.stage.el.focus(); }
                if (e.key === 'Escape') {
                    e.stopPropagation();
                    const typed = P.title.value.trim() !== P.detail.title;
                    P.title.value = P.detail.title;
                    grow(P);
                    P.title.blur();
                    if (!typed) closeTop();
                }
            },
            oninput: () => grow(P),
            onfocus: () => { P.titleGuard.base = P.detail && P.detail.rev; P.titleAtFocus = P.detail && P.detail.title; },
            onblur: () => { if (!P.detail) return; const v = P.title.value.trim(); if (v && v !== P.detail.title) saveTitle(P, v); else P.title.value = P.detail.title; } });
        P.titleBox = h('div', { class: 'd-title-box' }, P.title, svg('pencil', 'd-edit'));
        P.lockText = h('span');
        P.lockNote = h('p', { class: 'lock-note', hidden: true }, svg('lock'), P.lockText);
        P.stage = selectPill('Stage', [], '', (to) => moveCard(P.id, to, P.stage.el), stageMark);
        P.priority = selectPill('Priority', PRIORITIES.map((p) => ({ value: p, label: p })), 'normal', (priority) => save(P.id, { priority }), priorityMark);
        P.type = selectPill('Type', TYPES.map((t) => ({ value: t, label: t })), 'feature', (type) => save(P.id, { type }));
        P.epic = selectPill('Epic', [], '', (epic) => save(P.id, { epic: epic || null }));
        P.block = h('button', { class: 'btn danger', type: 'button', text: 'Block…', onclick: () => editBlocked(P) });
        P.controls = h('div', { class: 'controls' }, P.stage.el, P.priority.el, P.type.el, P.epic.el, P.block);
        P.blocked = h('div');

        P.labelList = h('span', { class: 'chips' });
        const labelHint = 'hint-' + ++hintSeq;
        P.labelInput = h('input', { class: 'field label-input', type: 'text', maxlength: '60', placeholder: 'Add a label', 'aria-label': 'New label', 'aria-describedby': labelHint,
            onkeydown: (e) => {
                if (e.key === ',') e.preventDefault();
                if ((e.key === 'Enter' || e.key === ',') && !e.isComposing) { e.preventDefault(); addLabel(P); }
            } });
        P.labelRow = h('div', { class: 'chips' }, P.labelList, P.labelInput);
        P.labelHint = h('p', { class: 'hint', id: labelHint }, 'Press ', kbd('Enter'), ' (or a comma) to add a label: lowercase words joined by - or :, like area:billing. Up to ' + MAX.labels + '.');
        P.labels = prop('Labels', P.labelRow, P.labelHint);
        P.depList = h('div', { class: 'chips' });
        P.depPick = h('div', { class: 'list', hidden: true });
        P.depInput = h('input', { class: 'field', type: 'text', placeholder: 'Depends on… (id or title)', 'aria-label': 'Add dependency', autocomplete: 'off', oninput: () => searchDeps(P),
            onkeydown: (e) => { if (e.key === 'Escape' && !P.depPick.hidden) { e.stopPropagation(); P.depPick.hidden = true; } } });
        P.depPicker = h('div', { class: 'picker' }, P.depInput, P.depPick);
        P.depHint = h('p', { class: 'hint', text: 'Type an id or a title and pick a card. This card waits until those are done.' });
        P.deps = prop('Depends on', P.depList, P.depPicker, P.depHint);
        P.workList = h('div', { class: 'chips' });
        P.work = prop('Work', P.workList);
        P.props = h('div', { class: 'props' }, P.labels, P.deps, P.work);

        P.desc = h('section', { class: 'sec' });
        P.accHead = h('h3');
        P.accList = h('ul', { class: 'check' });
        const accHint = 'hint-' + ++hintSeq;
        P.accAdd = h('input', { class: 'field', type: 'text', maxlength: String(MAX.criterion), placeholder: 'Add a criterion…', 'aria-label': 'New criterion', 'aria-describedby': accHint,
            onkeydown: (e) => { if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); addCriterion(P); } } });
        P.accAddBtn = h('button', { class: 'btn small', type: 'button', text: 'Add', onclick: () => addCriterion(P) });
        P.accEmpty = h('p', { class: 'empty-note' });
        P.accNote = h('p', { class: 'hint', text: 'Criteria can still be added, but not removed once work has started.' });
        P.accRow = h('div', { class: 'add-line' }, P.accAdd, P.accAddBtn);
        P.accHint = h('p', { class: 'hint', id: accHint }, 'Type a criterion and press ', kbd('Enter'), ' to add it; the box stays ready for the next one. A card can have up to ' + MAX.criteria + '. Tick each one when it is true.');
        P.acc = h('section', { class: 'sec' }, P.accHead, P.accEmpty, P.accList, P.accRow, P.accHint, P.accNote);
        P.plan = h('details', { class: 'sec plan', hidden: true });
        P.logHead = h('h3');
        P.log = h('ol', { class: 'log' });
        P.activity = h('section', { class: 'sec' }, P.logHead, P.log);
        P.facts = h('details', { class: 'sec' });
        P.mark = h('div', { class: 'd-mark', role: 'status', hidden: true });
        P.body = h('div', { class: 'd-body', tabindex: '-1', role: 'group', 'aria-label': 'Card details' }, P.mark, P.titleBox, P.lockNote, P.controls, P.blocked, P.props, P.desc, P.acc, P.plan, P.activity, P.facts);

        P.note = h('textarea', { class: 'field', rows: '2', maxlength: '5000', placeholder: 'Add a note…', 'aria-label': 'Note',
            oninput: () => { if (P.note.value) drafts.set(P.id + ':note', P.note.value); else drafts.delete(P.id + ':note'); growNote(P); },
            onkeydown: (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); postNote(P); } } });
        // the box is fitted again when its width changes (a resize) or when it first has one (a panel that was hidden when the text was set)
        let noteWidth = 0;
        P.noteWatch = new ResizeObserver(([entry]) => {
            const width = Math.round(entry.contentRect.width);
            if (width === noteWidth) return;
            noteWidth = width;
            if (width) growNote(P);
        });
        P.noteWatch.observe(P.note);
        P.foot = h('footer', { class: 'd-foot' }, P.note,
            h('div', { class: 'd-foot-row' }, h('span', { class: 'hint' }, kbd('Ctrl'), ' + ', kbd('Enter'), ' to post'),
                h('button', { class: 'btn primary small', type: 'button', text: 'Post', title: 'Post (Ctrl+Enter)', onclick: () => postNote(P) })));

        P.inner = h('div', { class: 'panel-inner' }, P.head, P.body, P.foot);
        P.strip = h('button', { class: 'panel-strip', type: 'button', onclick: () => popTo(S.stack.indexOf(P.id)) }, h('span', { class: 'strip-text' }));
        P.el = h('section', { class: 'panel', 'aria-label': 'Card ' + id, data: { id } }, P.strip, P.inner);
        return P;
    }

    function renderPanel(P) {
        const c = P.detail;
        const summary = findCard(P.id);
        const title = c ? c.title : summary ? summary.title : '';
        P.body.inert = P.foot.inert = !c;
        P.el.classList.toggle('is-loading', !c);
        P.strip.firstChild.textContent = P.id + (title ? '   ' + title : '');
        P.strip.setAttribute('aria-label', 'Back to ' + P.id + (title ? ': ' + title : ''));
        P.idText.textContent = P.id;
        if (!c) {
            if (!P.shown && document.activeElement !== P.title) { P.title.value = title; grow(P); }
            return;
        }
        if (!P.shown) { P.shown = true; P.note.value = drafts.get(P.id + ':note') || ''; growNote(P); }
        P.board.textContent = c.board.title;
        P.board.href = BASE + '/' + c.board.ref;
        if (document.activeElement !== P.title) P.title.value = c.title;
        grow(P);
        P.stage.set(c.stage, [...new Set([c.stage, ...c.targets])].map((s) => ({ value: s, label: s, disabled: !c.targets.includes(s) })));
        P.priority.set(c.priority);
        P.type.set(c.type);
        const epics = epicList().map((e) => ({ value: e.slug, label: e.title }));
        if (c.epic && !epics.some((e) => e.value === c.epic.slug)) epics.push({ value: c.epic.slug, label: c.epic.title });
        P.epic.set(c.epic ? c.epic.slug : '', [{ value: '', label: 'No epic' }, ...epics]);
        P.epic.el.hidden = !epics.length;
        // a card in a locked stage takes a note, a block and ticks: the rest is shown, not offered
        P.el.classList.toggle('is-locked', !!c.locked);
        P.lockNote.hidden = !c.locked;
        if (c.locked) P.lockText.replaceChildren(...lockText(c));
        P.title.readOnly = !!c.locked;
        P.title.title = c.locked ? '' : 'Click to rename';
        P.titleBox.classList.toggle('is-locked', !!c.locked);
        P.priority.el.disabled = P.type.el.disabled = !!c.locked;
        P.stage.el.disabled = c.targets.length === 0;
        renderBlocked(P, c);
        renderLabels(P, c);
        renderDeps(P, c);
        renderWork(P, c);
        renderDescription(P, P.desc, 'Description', 'body', c.body, c.body_html, 'Add a description…');
        renderAcceptance(P, c);
        renderPlan(P, c);
        renderFacts(P, c);
        renderLog(P, c);
    }

    /** The plan written for the card's worker: folded, rendered, read-only; who wrote it and on which commit of main. */
    function renderPlan(P, c) {
        P.plan.hidden = !c.plan_html;
        if (!c.plan_html) { P.plan.replaceChildren(); return; }
        const p = c.planned || {};
        const view = h('div', { class: 'read md' });
        view.innerHTML = c.plan_html; /* md-sink: server-rendered Markdown, HTML escaped */
        for (const a of view.querySelectorAll('a')) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
        const wasOpen = P.plan.open;
        P.plan.replaceChildren(h('summary', {}, h('span', { text: 'Plan' }),
            h('span', { class: 'muted', text: [p.by, p.base && '@' + p.base, p.at && text(p.at).slice(0, 16).replace('T', ' ')].filter(Boolean).join(' · ') }),
            p.current === false ? h('span', { class: 'tag amber', text: 'Outdated', title: 'The criteria or the description changed since it was written: '
                + (['backlog', 'planning', 'ready'].includes(c.stage) ? 'it is planned again' : 'where they differ, the card holds') }) : null), view);
        P.plan.open = wasOpen;
    }

    /** Why a card is locked, what stays open, and how to reopen it. */
    function lockText(c) {
        const why = { doing: 'an agent is working on it', review: 'it is in review', done: 'it is done' }[c.stage] || 'it is in ' + c.stage;
        return ['Locked while ' + why + '. A note, a block and ticks stay open.', ...(c.stage === 'doing' || c.stage === 'review' ? [' ', h('code', { text: 'kanban stop ' + c.id + ' --to=ready' }), ' reopens it.'] : [])];
    }

    function renderBlocked(P, c) {
        P.block.hidden = !!c.blocked || P.editing === 'blocked';
        if (P.editing === 'blocked') return;
        const question = asks(c);
        P.blocked.replaceChildren(...(c.blocked ? [h('div', { class: 'banner' + (question ? ' question' : '') }, svg(question ? 'help' : 'lock'), h('strong', { text: question ? 'Question for the owner' : 'Blocked' }), h('span', { class: 'grow', text: question ? c.question : c.blocked }),
            h('button', { class: 'btn small', type: 'button', text: 'Edit', onclick: () => editBlocked(P, c.blocked) }), h('button', { class: 'btn small', type: 'button', text: 'Unblock', onclick: () => save(c.id, { blocked: null }) }))] : []));
    }
    /**
     * Saves $value into a text $field for an editor opened on $editor.raw at $editor.guard.base, and settles a 409 by what
     * the field says now: still what the editor started from (only the revision moved: a note, a tick, a pull) -> written
     * again on the fresh revision, without asking; already what was typed -> nothing to write; anything else -> 'conflict'
     * with the other version in $editor.theirs. Returns 'saved', 'same', 'conflict' or 'failed'.
     */
    async function saveText(P, field, editor, value) {
        for (let attempt = 0; attempt < 3; attempt++) {
            const saved = await save(P.id, { [field]: value }, editor.guard.base, true);
            if (saved !== null) return saved ? 'saved' : 'failed';
            const theirs = P.detail[field] || '';
            if (theirs === value) return 'same';
            if (theirs !== editor.raw) { editor.theirs = theirs; return 'conflict'; }
            editor.guard.base = P.detail.rev;
        }
        toast('This card keeps changing. Try again in a moment.', 'err');

        return 'failed';
    }

    /** The other version of a text that changed while it was being edited, with the two ways out. */
    function conflictBlock(theirs, keepMine, useTheirs) {
        return h('div', { class: 'conflict', role: 'group', 'aria-label': 'Changed elsewhere', tabindex: '-1' },
            h('p', { class: 'conflict-h' }, svg('alert'), 'Changed elsewhere. The latest version:'),
            h('pre', { class: 'conflict-text', text: theirs || '(empty)' }),
            h('div', { class: 'controls' },
                h('button', { class: 'btn primary small', type: 'button', text: 'Keep mine', onclick: keepMine }),
                h('button', { class: 'btn small', type: 'button', text: 'Use theirs', onclick: useTheirs })));
    }

    /** The name: a change elsewhere to anything but the name still saves; a different name is shown and the typed one kept in the message. */
    async function saveTitle(P, value) {
        let saved = await save(P.id, { title: value }, P.titleGuard.base, true);
        if (saved === null) {
            if (P.detail.title === P.titleAtFocus) saved = await save(P.id, { title: value }, P.detail.rev, true);
            else if (P.detail.title !== value) { toast('The name changed elsewhere to “' + P.detail.title + '”. You typed “' + value + '”.', 'err'); return; }
        }
        if (saved === null) toast('This card changed elsewhere. Showing the latest version.', 'err');
    }

    /** Only one inline text editor is open in a panel: a second one would swallow what is typed in the first. */
    function busyEditor(P, field) {
        if (!P.editing || P.editing === field) return false;
        toast('Finish or cancel the other edit first');
        const open = P.editing === 'blocked' ? P.blocked.querySelector('input') : P.editor && P.editor.area;
        if (open) open.focus();

        return true;
    }

    function editBlocked(P, current = '') {
        if (busyEditor(P, 'blocked')) return;
        P.editing = 'blocked';
        const editor = { raw: current, guard: { base: P.detail.rev }, theirs: null, block: null };
        P.guards.add(editor.guard);
        P.block.hidden = true;
        const input = h('input', { class: 'field', type: 'text', maxlength: '500', value: current, placeholder: 'What is it waiting for?', 'aria-label': 'Blocked reason' });
        const line = h('div', { class: 'add-line' }, input, h('button', { class: 'btn primary', type: 'button', text: 'Block', onclick: () => !input.disabled && finish(true) }), h('button', { class: 'btn', type: 'button', text: 'Cancel', onclick: () => finish(false) }));
        const leave = () => { P.guards.delete(editor.guard); P.editing = null; };
        const showConflict = () => {
            if (editor.block) editor.block.remove();
            editor.block = conflictBlock(editor.theirs, keepMine, useTheirs);
            P.blocked.append(editor.block);
            editor.block.focus();
        };
        const keepMine = () => {
            const now = P.detail.blocked || '';
            if (now !== editor.theirs) { editor.theirs = now; showConflict(); return; }
            editor.raw = now;
            editor.guard.base = P.detail.rev;
            editor.block.remove();
            editor.block = null;
            finish(true);
        };
        const useTheirs = () => { leave(); renderPanel(P); };
        const finish = async (commit) => {
            const value = input.value.trim();
            if (!commit || !value) { leave(); renderPanel(P); return; }
            if (editor.block) { toast('Choose Keep mine or Use theirs first'); editor.block.focus(); return; }
            input.disabled = true;
            const outcome = await saveText(P, 'blocked', editor, value);
            input.disabled = false;
            if (outcome === 'saved' || outcome === 'same') leave();
            else if (outcome === 'conflict') showConflict();
            else input.focus();
            renderPanel(P);
        };
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !input.disabled) { e.preventDefault(); finish(true); } if (e.key === 'Escape') { e.stopPropagation(); finish(false); } });
        P.blocked.replaceChildren(line);
        input.focus();
    }

    /** Where the card is worked on: the agent, the branch, the stack. */
    function renderWork(P, c) {
        const items = [];
        if (c.agent) items.push(agentTag(c.agent));
        if (c.facts.branch) items.push(h('span', { class: 'fact mono', text: c.facts.branch }));
        if (c.url && /^https?:\/\//i.test(c.url)) items.push(h('a', { class: 'fact link', href: c.url, target: '_blank', rel: 'noopener noreferrer', title: c.url }, svg('external'), hostOf(c.url)));
        P.workList.replaceChildren(...items);
        P.work.hidden = !items.length;
    }

    /** A link to another card: opens it on top of this one. */
    const cardChip = (r, red = false) => h('span', { class: 'chip' + (red ? ' red' : ''), title: r.title },
        h('a', { href: BASE + '/cards/' + r.id, onclick: (e) => cardLink(e, r.id), text: r.id }), h('span', { class: 'muted', text: r.stage }));

    /** A Markdown field: rendered, click to edit as text. */
    function renderDescription(P, box, title, field, raw, html, placeholder) {
        if (P.editing === field) return;
        const locked = !!P.detail.locked;
        const view = locked ? h('div', { class: 'read' })
            : h('div', { class: 'editable', tabindex: '0', role: 'button', 'aria-label': 'Edit ' + title.toLowerCase(), onclick: (e) => { if (!e.target.closest('a')) edit(); }, onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); edit(); } } });
        if (html) { view.className += ' md'; view.innerHTML = html; /* md-sink: server-rendered Markdown, HTML escaped */ for (const a of view.querySelectorAll('a')) { a.target = '_blank'; a.rel = 'noopener noreferrer'; } } else view.append(h('span', { class: 'placeholder', text: locked ? 'No ' + title.toLowerCase() + '.' : placeholder }));
        box.replaceChildren(h('h3', {}, title), view);
        const draft = drafts.get(P.id + ':' + field);
        if (!locked && draft && draft.text !== raw) edit(draft);
        /** $draft: text typed earlier, with the text and the revision it was typed against. */
        function edit(draft = null) {
            if (busyEditor(P, field)) return;
            P.editing = field;
            const guard = { base: draft ? draft.rev : P.detail.rev };
            P.guards.add(guard);
            const area = h('textarea', { class: 'field', rows: '8', maxlength: String(MAX.body), 'aria-label': title });
            area.value = draft ? draft.text : raw;
            const editor = { field, area, raw: draft ? draft.raw : raw, guard, theirs: null, block: null };
            P.editor = editor;
            const leave = () => { P.guards.delete(guard); P.editing = null; P.editor = null; drafts.delete(P.id + ':' + field); };
            const saveButton = h('button', { class: 'btn primary small', type: 'button', text: 'Save', onclick: () => done(true) });
            const showConflict = () => {
                if (editor.block) editor.block.remove();
                editor.block = conflictBlock(editor.theirs, keepMine, useTheirs);
                area.after(editor.block);
                saveButton.disabled = true;
                editor.block.focus();
            };
            const done = async (commit) => {
                if (!commit || area.value === editor.raw) { leave(); renderPanel(P); return; }
                if (editor.block) { toast('Choose Keep mine or Use theirs first'); editor.block.focus(); return; }
                if (area.disabled) return;
                area.disabled = true;
                // a refused save keeps the editor and the typed text; only a saved one leaves edit mode
                const outcome = await saveText(P, field, editor, area.value);
                area.disabled = false;
                if (outcome === 'saved' || outcome === 'same') leave();
                else if (outcome === 'conflict') showConflict();
                else area.focus();
                renderPanel(P);
            };
            const keepMine = () => {
                const now = P.detail[field] || '';
                if (now !== editor.theirs) { editor.theirs = now; showConflict(); return; }
                editor.raw = now;
                guard.base = P.detail.rev;
                editor.block.remove();
                editor.block = null;
                saveButton.disabled = false;
                done(true);
            };
            const useTheirs = () => { leave(); renderPanel(P); };
            area.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); done(true); }
                if (e.key === 'Escape') { e.stopPropagation(); done(false); }
            });
            box.replaceChildren(h('h3', {}, title), area, h('div', { class: 'controls mt' }, saveButton, h('button', { class: 'btn small', type: 'button', text: 'Cancel', onclick: () => done(false) }), h('span', { class: 'muted', text: 'Markdown · Ctrl+Enter saves' })));
            area.focus();
            // a draft that outlived a change of the text opens beside the new version, not over it
            if (draft && (P.detail[field] || '') !== editor.raw) {
                editor.theirs = P.detail[field] || '';
                if (editor.theirs === area.value) { leave(); renderPanel(P); return; }
                showConflict();
            }
        }
    }

    function renderAcceptance(P, c) {
        const frozen = !!c.locked;
        const editable = !frozen && ['backlog', 'planning', 'ready'].includes(c.stage);
        const list = (card) => card.acceptance.map((i) => ({ id: i.id, text: i.text, done: i.done }));
        P.accHead.replaceChildren(...['Acceptance', c.progress.total ? h('span', { class: 'ring-count' }, ring(c.progress.done, c.progress.total), c.progress.done + '/' + c.progress.total) : null].filter(Boolean));
        const full = c.acceptance.length >= MAX.criteria;
        P.accEmpty.hidden = c.acceptance.length > 0;
        P.accEmpty.textContent = frozen ? 'No acceptance criteria.' : 'What has to be true for this card to be done. It needs at least one before it can be planned.';
        P.accNote.hidden = editable || frozen;
        P.accRow.hidden = P.accHint.hidden = frozen;
        P.accAdd.disabled = P.accAddBtn.disabled = full;
        P.accAdd.placeholder = full ? 'That is the limit of ' + MAX.criteria : 'Add a criterion…';
        if (P.criterionEditing) return;
        P.accList.replaceChildren(...c.acceptance.map((item) => {
            const check = h('input', { type: 'checkbox', checked: item.done, 'aria-label': 'Done: ' + item.text, onchange: () => save(c.id, (card) => ({ acceptance: list(card).map((a) => (a.id === item.id ? { ...a, done: check.checked } : a)) })) });
            const label = frozen ? h('span', { class: 'text', text: item.text })
                : h('span', { class: 'text', tabindex: '0', role: 'button', text: item.text, title: 'Click to edit', onclick: startEdit, onkeydown: (e) => e.key === 'Enter' && startEdit() });
            function startEdit() {
                const input = h('input', { class: 'text field', type: 'text', value: item.text, maxlength: String(MAX.criterion), 'aria-label': 'Criterion' });
                let finished = false;
                const guard = { base: P.detail.rev };
                P.guards.add(guard);
                P.criterionEditing = true;
                const finish = async (commit) => {
                    if (finished) return;
                    finished = true;
                    const v = input.value.trim();
                    if (!commit || !v || v === item.text) { P.guards.delete(guard); P.criterionEditing = false; renderPanel(P); return; }
                    const write = (base) => save(c.id, (card) => ({ acceptance: list(card).map((a) => (a.id === item.id ? { ...a, text: v } : a)) }), base, true);
                    let saved = await write(guard.base);
                    if (saved === null) {
                        const now = (P.detail.acceptance.find((a) => a.id === item.id) || {}).text;
                        // only the revision moved -> once more on the fresh one; reworded elsewhere -> the box keeps what was typed
                        if (now === item.text) saved = await write(P.detail.rev);
                        else if (now === v) saved = true;
                    }
                    if (saved === null) {
                        const now = (P.detail.acceptance.find((a) => a.id === item.id) || {}).text;
                        guard.base = P.detail.rev;
                        finished = false;
                        input.focus();
                        toast(now === undefined ? 'This criterion was removed elsewhere.' : 'This criterion now says “' + now + '”. Press Enter again to replace it with yours.', 'err');
                        return;
                    }
                    P.guards.delete(guard);
                    P.criterionEditing = false;
                    renderPanel(P);
                };
                input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); finish(true); } if (e.key === 'Escape') { e.stopPropagation(); finish(false); } });
                input.addEventListener('blur', () => finish(true));
                label.replaceWith(input);
                input.focus();
            }
            return h('li', { class: item.done ? 'is-done' : '' }, check, label,
                editable
                    ? h('button', { class: 'icon-btn rm', type: 'button', title: 'Remove', 'aria-label': 'Remove criterion', onclick: () => save(c.id, (card) => ({ acceptance: list(card).filter((a) => a.id !== item.id) })) }, svg('x'))
                    : h('span', { class: 'locked', role: 'img', title: 'Cannot be removed once work has started', 'aria-label': 'Cannot be removed once work has started' }, svg('lock')));
        }));
    }

    async function addCriterion(P) {
        const value = P.accAdd.value.trim();
        if (!value || !P.detail || P.accAdd.disabled) return;
        P.accAdd.value = '';
        P.accAdd.focus();
        if (!await save(P.id, (card) => ({ acceptance: [...card.acceptance.map((i) => ({ id: i.id, text: i.text, done: i.done })), { text: value }] })) && !P.accAdd.value) P.accAdd.value = value;
    }

    function renderLabels(P, c) {
        const full = c.labels.length >= MAX.labels;
        P.labelInput.disabled = full;
        P.labelInput.placeholder = full ? 'That is the limit of ' + MAX.labels : 'Add a label';
        P.labelInput.hidden = P.labelHint.hidden = !!c.locked;
        P.labels.hidden = !!c.locked && !c.labels.length;
        P.labelList.replaceChildren(...c.labels.map((l) => h('span', { class: 'chip' }, l, c.locked ? null : h('button', { type: 'button', 'aria-label': 'Remove ' + l, onclick: () => save(c.id, (card) => ({ labels: card.labels.filter((x) => x !== l) })) }, svg('x')))));
    }
    async function addLabel(P) {
        const value = P.labelInput.value.trim();
        if (!value || !P.detail || P.labelInput.disabled) return;
        if (!/^[a-z0-9]+([-:][a-z0-9]+)*$/.test(value)) { toast('Labels are lowercase words joined by - or :, like area:billing', 'err'); return; }
        P.labelInput.value = '';
        if (!await save(P.id, (card) => ({ labels: card.labels.includes(value) ? card.labels : [...card.labels, value] })) && !P.labelInput.value) P.labelInput.value = value;
    }

    function renderDeps(P, c) {
        P.depPicker.hidden = P.depHint.hidden = !!c.locked;
        P.deps.hidden = !!c.locked && !c.depends_on.length;
        P.depList.replaceChildren(...c.depends_on.map((d) => {
            const chip = cardChip(d, !d.satisfied);
            if (!c.locked) chip.append(h('button', { type: 'button', 'aria-label': 'Remove ' + d.id, onclick: () => save(c.id, (card) => ({ depends_on: card.depends_on.filter((x) => x.id !== d.id).map((x) => x.id) })) }, svg('x')));
            return chip;
        }));
    }
    function searchDeps(P) {
        clearTimeout(P.depTimer);
        P.depTimer = setTimeout(async () => {
            const q = P.depInput.value.trim();
            if (!q || !P.detail) { P.depPick.hidden = true; return; }
            try {
                const c = P.detail;
                const { data } = await api('/cards?q=' + encodeURIComponent(q) + '&for=' + encodeURIComponent(c.id));
                const found = data.cards;
                P.depPick.replaceChildren(...found.map((x) => h('button', { type: 'button', onclick: () => { P.depPick.hidden = true; P.depInput.value = ''; save(c.id, (card) => ({ depends_on: [...card.depends_on.map((d) => d.id).filter((id) => id !== x.id), x.id] })); } },
                    h('span', { class: 'mono', text: x.id }), h('span', { text: x.title }), h('span', { class: 'muted', text: x.stage }))));
                P.depPick.hidden = !found.length;
            } catch { P.depPick.hidden = true; }
        }, 150);
    }

    function renderFacts(P, c) {
        const f = c.facts;
        const at = (value) => text(value).slice(0, 16).replace('T', ' ');
        const rows = [['In stage', age(now() - c.since) + ' (since ' + at(f.stage_since) + ')'], ['Branch', f.branch], ['Worktree', f.worktree], ['Merge queue', f.queue], ['Merge', f.merge], ['Parked branch', f.parked_branch],
            ['Claimed by', f.claim && f.claim.by + ' · ' + at(f.claim.at)], ['Host', f.host], ['Created', at(f.created)], ['Updated', at(f.updated)]].filter(([, v]) => v);
        const wasOpen = P.facts.open;
        P.facts.replaceChildren(h('summary', { text: 'Details' }), h('dl', { class: 'facts' }, rows.flatMap(([k, v]) => [h('dt', { text: k }), h('dd', { text: v })])));
        P.facts.open = wasOpen;
    }

    /** How long the line about a change made elsewhere stays on an open card. */
    const MARK_MS = 60000;

    /**
     * When somebody else changes a card that is open, one line at the top says who did what: people's own edits, merges,
     * and anything that touches the text being edited. An agent's housekeeping on the rest of the card stays quiet. The
     * entries that are new are found by id, so no clock is involved in who or what; the time is when this page noticed.
     */
    function noteChange(P, before, after) {
        if (!before || !after) return;
        const known = new Set(before.log.map((entry) => entry.id));
        const editing = P.editing;
        const worth = after.log.filter((entry) => !known.has(entry.id) && (entry.by === 'owner' || entry.by === 'main' || entry.event === 'conflict' || (editing && (entry.fields || []).includes(editing))));
        if (!worth.length) return;
        clearTimeout(P.markTimer);
        P.mark.replaceChildren(svg('pencil'), h('span', { text: logActor(worth[0]) + ' ' + logPhrase(worth[0]) + ' ' }), h('time', { class: 'when', data: { since: now(), suffix: ' ago' }, text: 'just now' }));
        P.mark.hidden = false;
        P.markTimer = setTimeout(() => clearMark(P), MARK_MS);
    }
    function clearMark(P) {
        clearTimeout(P.markTimer);
        P.mark.hidden = true;
        P.mark.replaceChildren();
    }

    /** Who did it: the person for the owner's own entries, the role with the person in brackets for an agent's or a hook's. */
    function logActor(entry) {
        if (!entry.who) return entry.by;
        return entry.by === 'owner' ? entry.who : entry.by + ' (' + entry.who + ')';
    }
    /** What an entry says happened, in words (the actor comes first: logActor). */
    function logPhrase(entry) {
        if (entry.event === 'note') return 'added a note';
        if (entry.event === 'stage') return 'moved ' + entry.from + ' → ' + entry.to + (entry.via ? ' (' + entry.via + ')' : '');
        if (entry.event === 'planned') return 'wrote the plan' + (entry.base ? ' on main @' + String(entry.base).slice(0, 7) : '');
        if (entry.event === 'claimed') return 'took it to plan';
        if (entry.event === 'plan') return 'could not plan it' + (entry.reason ? ': ' + entry.reason : '');
        if (entry.event === 'conflict') return 'kept the other version of ' + (entry.field === 'flow' ? 'the stage' : entry.field) + ' in a merge';
        if (entry.event === 'main_red') return 'found ' + entry.command + ' failing on main';
        if (entry.event === 'set') return 'changed ' + (entry.fields || []).join(', ') + (entry.acceptance_removed ? ' (removed criteria ' + entry.acceptance_removed.join(', ') + ')' : '');
        if (entry.event === 'merge') {
            return {
                conflict: 'met conflicts merging into main: ' + (entry.files || []).join(', '), red: 'went red merging into main: ' + entry.command,
                main: 'found ' + entry.command + ' failing on main too', resolved: 'resolved the conflicts of its merge', fixed: 'fixed what its merge turned red',
                back: 'sent it back from the merge queue', stale: 'took it out of the merge queue: its branch moved past the approval', landed: 'found it on main already',
                timeout: 'ran past ' + entry.seconds + ' s merging into main: ' + entry.command,
            }[entry.result] || 'merge ' + entry.result;
        }

        return entry.event + Object.entries(entry).filter(([key]) => !['id', 'at', 'by', 'who', 'event'].includes(key)).map(([key, value]) => ' ' + key + ': ' + (value !== null && typeof value === 'object' ? JSON.stringify(value) : value)).join('');
    }

    function renderLog(P, c) {
        P.logHead.replaceChildren('Activity', h('span', { class: 'muted', text: String(c.log_total) }));
        P.log.replaceChildren(...c.log.map((entry) => {
            const icon = { note: 'note', stage: 'arrow-right', set: 'pencil', conflict: 'alert' }[entry.event] || 'more';
            const since = Date.parse(entry.at) / 1000;
            const quote = entry.event === 'note' ? entry.text : entry.event === 'stage' ? entry.reason || null : entry.event === 'merge' ? entry.note || null : null;
            return h('li', {}, h('span', { class: 'log-i' }, svg(icon)),
                h('div', { class: 'log-b' },
                    h('div', { class: 'log-h' }, h('span', { class: 'who', text: logActor(entry) }), h('span', { text: ' ' + logPhrase(entry) }),
                        Number.isNaN(since) ? null : h('time', { class: 'when', datetime: entry.at, title: text(entry.at).slice(0, 16).replace('T', ' '), data: { since, suffix: ' ago' }, text: age(now() - since) + ' ago' })),
                    quote ? h('q', { text: quote }) : null,
                    entry.event === 'conflict' && entry.lost ? h('details', { class: 'lost' }, h('summary', { text: 'What was replaced' }), h('q', { text: entry.lost })) : null));
        }));
        if (c.log_total > c.log.length) P.log.append(h('li', { class: 'muted more-log', text: (c.log_total - c.log.length) + ' older entries: vendor/bin/kanban show ' + c.id }));
    }

    async function postNote(P) {
        const value = P.note.value.trim();
        if (!value || !P.detail) return;
        const id = P.id;
        try {
            await writing(async () => {
                const sent = rev(id);
                const { data } = await api('/cards/' + id + '/notes', { method: 'POST', body: { text: value, rev: sent } });
                P.note.value = '';
                growNote(P);
                drafts.delete(id + ':note');
                adoptOwn(data.card, sent);
            }, id);
        } catch (e) { handleError(e); }
        if (panels.get(id) === P) renderPanel(P);
    }

    function buildDrawer() {
        ui.drawer = h('aside', { class: 'drawer', hidden: true, 'aria-label': 'Cards' });
        ui.scrim = h('div', { class: 'scrim', hidden: true, onclick: () => popTo(-1) });
    }

    /* ---------- start ---------- */

    window.addEventListener('popstate', route);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    buildShell();
    route().then(() => { schedule(); setInterval(refreshAges, 15000); });
})();
