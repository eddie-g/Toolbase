/*
 * ensureSignatureFontLoaded (Phase 7ch).
 *
 * Lazy-loads a Google Font for the typed-signature preview. Injects a
 * <link rel="stylesheet"> for the requested family (regular + bold)
 * the first time we see it, then resolves once the font has actually
 * loaded into `document.fonts`. Subsequent calls for the same family
 * return the same in-flight promise so we never inject the link twice
 * or fight for paint with multiple parallel awaiters.
 */

const signatureFontLoadPromises = new Map();

export function ensureSignatureFontLoaded(fontName) {
    const normalizedFontName = String(fontName || '').trim();
    if (!normalizedFontName) return Promise.resolve();
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
                resolve();
                return;
            }
            Promise.allSettled([
                document.fonts.load(`400 96px "${normalizedFontName}"`),
                document.fonts.load(`700 96px "${normalizedFontName}"`),
            ]).finally(resolve);
        };

        let link = document.getElementById(id);
        if (!(link instanceof HTMLLinkElement)) {
            link = document.createElement('link');
            link.id = id;
            link.rel = 'stylesheet';
            link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(normalizedFontName)}:wght@400;700&display=swap`;
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
