/*
 * ensureSignatureFontLoaded (Phase 7ch).
 *
 * Lazy-loads a self-hosted font for the typed-signature preview. Injects a
 * <link rel="stylesheet"> for the requested family (regular + bold)
 * the first time we see it, then resolves once the font has actually
 * loaded into `document.fonts`. Subsequent calls for the same family
 * return the same in-flight promise so we never inject the link twice
 * or fight for paint with multiple parallel awaiters.
 *
 * Resolves true when a face of the family is really loaded, false when the
 * stylesheet or its files could not be fetched (NK_44): the canvas then draws
 * the `cursive` fallback, and the caller says so instead of showing every
 * font as the same face with no explanation.
 */

const signatureFontLoadPromises = new Map();

// document.fonts.check() answers true for a family with no @font-face at all,
// so look for a loaded face instead.
function signatureFontIsLoaded(fontName) {
    if (!document.fonts?.forEach) return true;
    let loaded = false;
    document.fonts.forEach((face) => {
        if (face.status === 'loaded' && face.family.replace(/["']/g, '') === fontName) loaded = true;
    });
    return loaded;
}

export function ensureSignatureFontLoaded(fontName) {
    const normalizedFontName = String(fontName || '').trim();
    if (!normalizedFontName) return Promise.resolve(false);
    if (signatureFontLoadPromises.has(normalizedFontName)) {
        return signatureFontLoadPromises.get(normalizedFontName);
    }
    const id = `signature-font-${normalizedFontName.replace(/\s+/g, '-')}`;
    const fontPromise = new Promise((resolve) => {
        let settled = false;
        const finish = () => {
            if (settled) return;
            settled = true;
            if (!document.fonts?.load) {
                resolve(true);
                return;
            }
            Promise.allSettled([
                document.fonts.load(`400 96px "${normalizedFontName}"`),
                document.fonts.load(`700 96px "${normalizedFontName}"`),
            ]).finally(() => resolve(signatureFontIsLoaded(normalizedFontName)));
        };

        let link = document.getElementById(id);
        if (!(link instanceof HTMLLinkElement)) {
            link = document.createElement('link');
            link.id = id;
            link.rel = 'stylesheet';
            // Hosted with the editor's other fonts (public/fonts/editor/signature,
            // written by scripts/fetch-editor-fonts.mjs): one small stylesheet per family.
            const slug = normalizedFontName.toLowerCase().replace(/[^a-z0-9]+/g, '-');
            const editorFonts = document.querySelector('link[data-editor-fonts]');
            link.href = new URL(`signature/${slug}.css`, editorFonts?.href || new URL('/fonts/editor/', window.location.origin)).href;
            document.head.appendChild(link);
        }

        if (link.dataset.loaded === '1') {
            finish();
            return;
        }

        const markLoaded = () => {
            link.dataset.loaded = '1';
            finish();
        };

        link.addEventListener('load', markLoaded, { once: true });
        link.addEventListener('error', markLoaded, { once: true });
        window.setTimeout(markLoaded, 1800);
    });

    signatureFontLoadPromises.set(normalizedFontName, fontPromise);
    return fontPromise;
}
