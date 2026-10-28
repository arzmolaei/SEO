import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite-plus';

// Unit tests cover pure TypeScript; the app's Laravel/Wayfinder plugins are
// build-time concerns and refuse to run in CI, so they are not loaded here.
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
    },
});
