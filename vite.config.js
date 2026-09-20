import fs from 'node:fs';
import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * Removes what the build just finished no longer references. The build keeps
 * emptyOutDir off (a file in public/build can belong to another user, and
 * emptying then fails), which left every old hashed bundle in place: 56 MB of
 * them, all of which would be deployed. A file that cannot be removed is
 * reported and skipped.
 */
function removeStaleBuildAssets() {
    let outDir = 'public/build';
    return {
        name: 'netkit-remove-stale-build-assets',
        apply: 'build',
        configResolved(config) {
            outDir = config.build.outDir;
        },
        closeBundle() {
            const manifestPath = path.join(outDir, 'manifest.json');
            const assetsDir = path.join(outDir, 'assets');
            if (!fs.existsSync(manifestPath) || !fs.existsSync(assetsDir)) return;

            const keep = new Set();
            for (const entry of Object.values(JSON.parse(fs.readFileSync(manifestPath, 'utf8')))) {
                for (const file of [entry.file, ...(entry.css || []), ...(entry.assets || [])]) {
                    if (file) keep.add(path.basename(file));
                }
            }
            let removed = 0;
            let bytes = 0;
            for (const name of fs.readdirSync(assetsDir)) {
                if (keep.has(name)) continue;
                const file = path.join(assetsDir, name);
                try {
                    bytes += fs.statSync(file).size;
                    fs.unlinkSync(file);
                    removed += 1;
                } catch (error) {
                    console.warn(`[stale-assets] could not remove ${name}: ${error.code || error.message}`);
                }
            }
            if (removed) console.log(`[stale-assets] removed ${removed} files from earlier builds (${(bytes / 1048576).toFixed(1)} MB)`);
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/user-portal.css',
                'resources/js/app.js',
                'resources/css/edit-new/index.css',
                'resources/js/edit-new/main.js',
                'resources/css/edit-pdfjs/index.css',
                'resources/js/edit-pdfjs/main.js',
                'resources/css/edit-new-pdfjs/index.css',
                'resources/js/edit-new-pdfjs/main.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
        removeStaleBuildAssets(),
    ],
    build: {
        rollupOptions: {
            output: {
                // pdf.js changes when the dependency is upgraded; the editor's
                // own code changes with every deploy. Apart, a deploy no longer
                // makes every visitor download pdf.js again.
                manualChunks(id) {
                    if (id.includes('node_modules/pdfjs-dist/')) return 'pdfjs';
                    return undefined;
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
