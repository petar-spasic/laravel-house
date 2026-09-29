import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

export default {
    // Lets <script lang="ts"> in islands go through Vite's esbuild.
    preprocess: vitePreprocess(),
};
