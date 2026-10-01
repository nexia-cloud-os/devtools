import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));
const metadata = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'));
const peers = Object.keys(metadata.peerDependencies || {});

// Build App code only. The Nexia host supplies the declared React/SDK peers.
export default {
    root,
    publicDir: false,
    envDir: false,
    esbuild: { jsx: 'automatic' },
    resolve: {
        alias: {
            '#app': fileURLToPath(new URL('./resources/js', import.meta.url)),
            '#lang': fileURLToPath(new URL('./resources/lang', import.meta.url)),
        },
    },
    build: {
        outDir: 'dist/frontend',
        emptyOutDir: true,
        sourcemap: false,
        manifest: true,
        lib: {
            entry: fileURLToPath(new URL('./resources/js/index.ts', import.meta.url)),
            formats: ['es'],
            fileName: () => 'index.js',
        },
        rollupOptions: {
            external: id => peers.some(peer => id === peer || id.startsWith(`${peer}/`)),
        },
    },
};
