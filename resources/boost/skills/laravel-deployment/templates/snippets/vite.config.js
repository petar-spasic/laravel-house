# if:htmx
// Merge into vite.config.js: keep its imports and plugins; drop proxy, hmr (clientPort included) and
// host: '0.0.0.0' from an existing server. server.ws needs Vite 8.1 or later. The HMR client takes its scheme, host
// and port from the origin in public/hot, so no clientPort is set.
import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

// Nested worktrees and the board are other checkouts, vendor/ is ~15k inotify watches; anchored so only
// these top-level dirs are skipped (a glob such as **/docs/** would also skip resources/docs).
const unwatched = ['./.claude/worktrees', './docs', './vendor'].map((d) => fileURLToPath(new URL(d, import.meta.url)));
// A browser's Origin never ends in a slash.
const appUrl = process.env.APP_URL?.replace(/\/+$/, '');

export default defineConfig({
    plugins: [/* … */],
    server: {
        // Caddy dials 127.0.0.1:5173; the entrypoint passes the same host and port with --strictPort.
        host: '127.0.0.1',
        // Written into public/hot: assets and the HMR socket go through Caddy's port. Compose sets APP_URL.
        origin: appUrl,
        // IPs, localhost and the origin's host always pass; never `true` (DNS rebinding).
        allowedHosts: ['.test'],
        // A page opened under another name than public/hot's loads assets cross-origin.
        cors: {
            origin: [
                /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
                /^https?:\/\/.*\.test(:\d+)?$/,
                ...(appUrl ? [appUrl] : []),
            ],
        },
        // Its own path so Caddy can route it (at `/` it would reach php-fpm).
        ws: { path: '/__vite_hmr' },
        // Pair with Caddy's @vite matcher (docker/Caddyfile.local).
        fs: { strict: true, allow: ['resources', 'node_modules'] },
        watch: {
            ignored: ['**/storage/framework/views/**', (p) => unwatched.some((d) => p === d || p.startsWith(d + '/'))],
        },
    },
});
# endif
