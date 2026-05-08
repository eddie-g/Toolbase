// Embedded-PDF-font registry. Owns the @font-face injection, the
// per-family async health-check that flags faces whose outlines are
// empty, and the "broken" set that the renderer consults when
// resolving fallbacks. Pure of UI concerns aside from the <style>
// element it appends to <head>; depends only on font-utils helpers
// and a redraw callback.

const STYLE_ELEMENT_ID = 'edit-new-embedded-fonts';
const PROBE_TEXT = 'AHSx0';
const PROBE_SIZE = 24;
const PROBE_BASELINE_FONT = `${PROBE_SIZE}px Helvetica, Arial, sans-serif`;
const NO_FALLBACK_SENTINEL = '__pdf_no_such_family__';

/**
 * @param {object} deps
 * @param {(name: string, family: string, fontData: object) => boolean} deps.shouldBypassEmbeddedFont
 * @param {(ext: string) => string} deps.fontFileFormat
 * @param {() => void} [deps.onValidationChange]  Called when one or more
 *     newly-broken faces are flagged so the caller can request a redraw.
 */
export function createEmbeddedFontRegistry({ shouldBypassEmbeddedFont, fontFileFormat, onValidationChange }) {
    let overlayEmbeddedFonts = null;
    const brokenKeys = new Set();
    let healthCheckToken = 0;
    // Stage1 font diagnostics: per-family load/probe outcome captured by
    // validateHealth so it can be inspected from the console / probes via
    // `window.__editorTestState.getFontDiagnostics()`.
    let lastDiagnostics = null;

    function loadFaces(embeddedFonts) {
        overlayEmbeddedFonts = embeddedFonts && typeof embeddedFonts === 'object' ? embeddedFonts : null;
        // Reset broken-font set on every reload — must re-validate against the
        // freshly registered @font-face URLs.
        brokenKeys.clear();

        const existing = document.getElementById(STYLE_ELEMENT_ID);
        if (existing) existing.remove();
        if (!overlayEmbeddedFonts) return;

        let css = '';
        for (const [fontKey, fontData] of Object.entries(overlayEmbeddedFonts)) {
            const cleanName = String(fontData?.clean_name || fontKey || '').trim();
            const family = String(fontData?.family || fontKey || '').trim();
            if (!cleanName) continue;
            if (shouldBypassEmbeddedFont(cleanName, family, fontData)) continue;

            let filePath = String(fontData?.file_path || '').trim();
            if (!filePath) continue;

            let fileExt = String(fontData?.file_ext || 'ttf').toLowerCase();
            if (fileExt === 'cff') {
                filePath = filePath.replace(/\.cff$/i, '.otf');
                fileExt = 'otf';
            }
            if (fileExt === 'cid') continue;

            const format = fontFileFormat(fileExt);
            const weight = String(fontData?.css_weight || '400');
            const fontStyle = String(fontData?.css_style || 'normal');
            const fontStretch = String(fontData?.css_stretch || 'normal');
            const exactFamily = `PDF_${cleanName}`;
            const familyAlias = family ? `PDF_${family}` : '';

            css += `@font-face { font-family: '${exactFamily}'; src: url('${filePath}') format('${format}'); font-weight: ${weight}; font-style: ${fontStyle};${fontStretch !== 'normal' ? ` font-stretch: ${fontStretch};` : ''} font-display: block; }\n`;
            if (familyAlias && familyAlias !== exactFamily) {
                css += `@font-face { font-family: '${familyAlias}'; src: url('${filePath}') format('${format}'); font-weight: ${weight}; font-style: ${fontStyle};${fontStretch !== 'normal' ? ` font-stretch: ${fontStretch};` : ''} font-display: block; }\n`;
            }
        }

        if (!css) return;

        const style = document.createElement('style');
        style.id = STYLE_ELEMENT_ID;
        style.textContent = css;
        document.head.appendChild(style);

        // Kick off async health check: paint a sample with each registered
        // embedded family and flag any that produce zero ink pixels.
        validateHealth();
    }

    // Render-test every registered PDF_<cleanName> family. Any family that
    // measures correctly but draws nothing gets added to brokenKeys so the
    // caller's fallback resolver can route around it.
    function validateHealth() {
        if (!overlayEmbeddedFonts || typeof overlayEmbeddedFonts !== 'object') return;
        const token = ++healthCheckToken;

        const families = [];
        for (const [fontKey, fontData] of Object.entries(overlayEmbeddedFonts)) {
            const cleanName = String(fontData?.clean_name || fontKey || '').trim();
            if (!cleanName) continue;
            if (shouldBypassEmbeddedFont(cleanName, String(fontData?.family || ''), fontData)) continue;
            const cssWeight = String(fontData?.css_weight || '400');
            const cssStyle = String(fontData?.css_style || 'normal');
            families.push({ cleanName, cssWeight, cssStyle });
        }
        if (!families.length) return;

        const fontReady = (typeof document !== 'undefined' && document.fonts && document.fonts.ready)
            ? document.fonts.ready
            : Promise.resolve();

        fontReady.then(async () => {
            if (token !== healthCheckToken) return;

            // Force every PDF_<cleanName> face to actually load. document.fonts.ready
            // does NOT lazy-load @font-face rules added via <style> injection until
            // they are first *used*, so without this step every probe below would
            // paint with a system fallback while the real font hasn't started
            // downloading — yielding a false-pass.
            try {
                const loaders = families.map(({ cleanName, cssWeight, cssStyle }) => {
                    const face = `${cssStyle} ${cssWeight} ${PROBE_SIZE}px 'PDF_${cleanName}'`;
                    try { return document.fonts.load(face).catch(() => null); } catch (_) { return null; }
                }).filter(Boolean);
                if (loaders.length) await Promise.all(loaders);
            } catch (_) {}
            if (token !== healthCheckToken) return;

            const canvas = document.createElement('canvas');
            canvas.width = 96;
            canvas.height = 32;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            if (!ctx) return;

            // Establish a baseline ink count using a guaranteed-renderable font
            // so we don't get false positives from canvas APIs that report 0.
            ctx.fillStyle = '#000';
            ctx.textBaseline = 'top';
            ctx.font = PROBE_BASELINE_FONT;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillText(PROBE_TEXT, 2, 2);
            let baselineInk = 0;
            try {
                const img = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                for (let i = 3; i < img.length; i += 4) if (img[i] > 8) baselineInk++;
            } catch (_) { return; /* tainted canvas — skip the check entirely */ }
            if (baselineInk < 5) return; // canvas readback unreliable; bail

            let foundBroken = false;
            const newlyBroken = [];
            const perFamily = [];

            const probeOne = ({ cleanName, cssWeight, cssStyle }) => {
                const family = `PDF_${cleanName}`;
                const faceLoaded = (() => {
                    try {
                        return [...document.fonts].some((f) => f.family.replace(/['"]/g, '') === family
                            && (f.status === 'loaded' || f.status === 'loading'));
                    } catch (_) { return false; }
                })();
                // Render at the face's *registered* weight/style. The trailing
                // NO_FALLBACK_SENTINEL ensures that if the PDF_<name> face is
                // not loaded we don't fall back to a system font that *can*
                // render the glyphs — that would produce a false-pass.
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.font = `${cssStyle} ${cssWeight} ${PROBE_SIZE}px '${family}', ${NO_FALLBACK_SENTINEL}`;
                try { ctx.fillText(PROBE_TEXT, 2, 2); } catch (_) { return; }

                let ink = 0;
                try {
                    const img = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                    for (let i = 3; i < img.length; i += 4) if (img[i] > 8) ink++;
                } catch (_) { return; }

                const broken = ink < 3;
                if (broken) {
                    brokenKeys.add(cleanName);
                    newlyBroken.push(cleanName);
                    foundBroken = true;
                }
                perFamily.push({ cleanName, cssWeight, cssStyle, faceLoaded, ink, broken });
            };

            families.forEach(probeOne);

            // Stage1 diagnostics snapshot — exposed via getDiagnostics().
            lastDiagnostics = {
                timestamp: Date.now(),
                totalRegistered: families.length,
                brokenCount: newlyBroken.length,
                healthyCount: families.length - newlyBroken.length,
                broken: newlyBroken.slice(),
                perFamily,
            };

            if (foundBroken) {
                console.warn(
                    '[edit-new] Embedded PDF font(s) registered but render empty; '
                    + 'falling back to system fonts for: ' + newlyBroken.join(', ')
                );
                if (typeof onValidationChange === 'function') {
                    try { onValidationChange(newlyBroken); } catch (_) {}
                }
            }
            // Stage1: always log a summary so we can confirm during smoke
            // tests which embedded PDF faces actually loaded vs. fell back.
            try {
                console.info(
                    `[edit-new][fonts] embedded PDF faces: ${lastDiagnostics.healthyCount} healthy, `
                    + `${lastDiagnostics.brokenCount} broken (of ${lastDiagnostics.totalRegistered} registered). `
                    + (newlyBroken.length ? `Broken: ${newlyBroken.join(', ')}.` : '')
                );
            } catch (_) {}
        }).catch(() => {});
    }

    return {
        loadFaces,
        getEmbeddedFonts: () => overlayEmbeddedFonts,
        isBroken: (cleanName) => brokenKeys.has(cleanName),
        getDiagnostics: () => lastDiagnostics,
    };
}
