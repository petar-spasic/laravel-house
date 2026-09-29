import type { Component } from 'svelte';

/**
 * Every `resources/js/islands/<Name>.svelte` mounts from Blade as `<x-island name="<Name>" :props="…">`.
 * The glob is lazy and Svelte itself is imported on the first mount, so a page without islands
 * ships htmx and nothing else.
 */
type IslandComponent = Component<Record<string, unknown>>;
type IslandLoader = () => Promise<{ default: IslandComponent }>;

const loaders = new Map<string, IslandLoader>(
    Object.entries(import.meta.glob<{ default: IslandComponent }>('./islands/*.svelte')).map(([path, load]) => [
        path.replace(/^.*\/(.+)\.svelte$/, '$1'),
        load,
    ]),
);

const instances = new WeakMap<Element, () => Promise<void>>();
const pending = new WeakSet<Element>();

const readProps = (element: HTMLElement): Record<string, unknown> => {
    const raw = element.dataset.props;
    if (!raw) {
        return {};
    }
    try {
        return JSON.parse(raw) as Record<string, unknown>;
    } catch (error) {
        console.error(`[islands] invalid data-props on <${element.dataset.island}>`, error);
        return {};
    }
};

const mountOne = async (element: HTMLElement): Promise<void> => {
    const name = element.dataset.island ?? '';
    const load = loaders.get(name);
    if (!load) {
        console.error(`[islands] unknown island "${name}" — expected resources/js/islands/${name}.svelte`);
        return;
    }
    pending.add(element);
    try {
        const [{ mount, unmount }, { default: component }] = await Promise.all([import('svelte'), load()]);
        // The element may have been swapped out by htmx while the chunk loaded.
        if (!element.isConnected || instances.has(element)) {
            return;
        }
        // The server-rendered slot is the same-sized placeholder until now.
        element.replaceChildren();
        const instance = mount(component, { target: element, props: readProps(element) });
        instances.set(element, () => unmount(instance));
        element.dataset.islandMounted = '';
    } finally {
        pending.delete(element);
    }
};

export const mountIslands = (root: Node): void => {
    // htmx load/cleanup events also fire for text nodes; only elements host islands.
    if (!(root instanceof Element)) {
        return;
    }
    const targets: HTMLElement[] = [];
    if (root instanceof HTMLElement && root.dataset.island !== undefined) {
        targets.push(root);
    }
    targets.push(...root.querySelectorAll<HTMLElement>('[data-island]'));

    for (const element of targets) {
        if (!instances.has(element) && !pending.has(element)) {
            void mountOne(element);
        }
    }
};

export const unmountIslands = (root: Node): void => {
    if (!(root instanceof Element)) {
        return;
    }
    const targets = [root, ...root.querySelectorAll('[data-island]')];
    for (const element of targets) {
        const dispose = instances.get(element);
        if (dispose) {
            void dispose();
            instances.delete(element);
        }
    }
};
