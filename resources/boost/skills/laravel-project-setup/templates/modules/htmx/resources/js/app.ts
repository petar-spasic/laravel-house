import htmx from 'htmx.org';
import './htmx-global';
import 'htmx-ext-preload';
<!-- if:islands -->
import { mountIslands, unmountIslands } from './islands';
<!-- endif -->

// Strict CSP (no inline script, no eval): no hx-on:*, no hx-vals='js:…'.
htmx.config.allowEval = false;
htmx.config.selfRequestsOnly = true;
// The indicator <style> htmx injects would be blocked by the CSP; the rules live in app.css instead.
htmx.config.includeIndicatorStyles = false;
// No View Transitions: a cross-fade between two unrelated pages reads as a flash of the old one.
htmx.config.globalViewTransitions = false;

const csrfToken = (): string =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

// CSRF once, globally — never per-form hx-headers.
document.body.addEventListener('htmx:configRequest', (event) => {
    (event as CustomEvent<{ headers: Record<string, string> }>).detail.headers['X-CSRF-TOKEN'] = csrfToken();
});

// A 422 carries the re-rendered form fragment; htmx ignores 4xx by default.
document.body.addEventListener('htmx:beforeSwap', (event) => {
    const detail = (event as CustomEvent<{ xhr: XMLHttpRequest; shouldSwap: boolean; isError: boolean }>).detail;
    if (detail.xhr.status === 422) {
        detail.shouldSwap = true;
        detail.isError = false;
    }
});
<!-- if:islands -->

// Islands mount on the initial page and on every fragment htmx swaps in, and unmount
// before htmx removes their element, so nothing leaks.
mountIslands(document.body);
document.body.addEventListener('htmx:load', (event) => {
    mountIslands((event as CustomEvent<{ elt: Node }>).detail.elt);
});
document.body.addEventListener('htmx:beforeCleanupElement', (event) => {
    unmountIslands((event as CustomEvent<{ elt: Node }>).detail.elt);
});
<!-- endif -->
