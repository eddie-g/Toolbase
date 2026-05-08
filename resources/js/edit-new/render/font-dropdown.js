import { shouldBypassEmbeddedFont } from '../render/font-utils.js';

// Inject a "PDF Embedded Fonts" section at the top of the font-family picker.
// Embedded font names use the PDF's clean_name (e.g. "TahomaUnicode"). When the
// user selects one, `ann.fontFamily` is set to that name and `fallbackFontFamily`
// resolves it to the `PDF_<clean_name>` CSS family which is registered via
// @font-face by `loadEmbeddedFontFaces` — so glyphs render with the correct
// (embedded) outlines instead of a system substitute.
//
// Pure with respect to editor state — touches only the DOM and the supplied
// embedded-font registry.
export function populateFontDropdown(embeddedFontRegistry) {
    const fontSelect = document.getElementById('afb-font');
    if (!fontSelect) return;

    // Collect embedded font names from the loaded PDF. Exclude subset fonts
    // (pdf_font_name like "AAAAAA+Arial-BoldMT") because they only contain the
    // glyphs used in the original document — typing new text with them produces
    // garbage for missing characters.
    const embeddedSet = new Set();
    const SUBSET_PREFIX_RE = /^[A-Z]{6}\+/;
    const overlayEmbeddedFonts = embeddedFontRegistry.getEmbeddedFonts();
    if (overlayEmbeddedFonts && typeof overlayEmbeddedFonts === 'object') {
        Object.entries(overlayEmbeddedFonts).forEach(([fontKey, fontData]) => {
            const cleanName = String(fontData?.clean_name || fontKey || '').trim();
            if (!cleanName) return;
            if (shouldBypassEmbeddedFont(cleanName, String(fontData?.family || ''), fontData)) return;
            if (embeddedFontRegistry.isBroken(cleanName)) return;
            const pdfName = String(fontData?.pdf_font_name || '').trim();
            if (SUBSET_PREFIX_RE.test(pdfName)) return;
            embeddedSet.add(cleanName);
        });
    }
    const embeddedFonts = Array.from(embeddedSet).sort((a, b) => a.localeCompare(b));

    // Snapshot the static built-in options (anything not marked pdfDynamic) and
    // preserve the currently-selected value so we can restore it afterwards.
    const staticOptions = Array.from(fontSelect.options)
        .filter((o) => o.dataset.pdfDynamic !== '1')
        .map((o) => o.cloneNode(true));
    const currentValue = String(fontSelect.value || '').trim();

    fontSelect.replaceChildren();

    if (embeddedFonts.length > 0) {
        const header = document.createElement('option');
        header.disabled = true;
        header.dataset.pdfDynamic = '1';
        header.textContent = '───── PDF Embedded Fonts ─────';
        fontSelect.appendChild(header);

        embeddedFonts.forEach((name) => {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            opt.dataset.pdfDynamic = '1';
            opt.dataset.pdfFont = '1';
            // Do NOT preview option text in the embedded font — embedded PDF fonts
            // are usually subsets containing only the glyphs used in the original
            // document, so the font name itself would render as gibberish.
            fontSelect.appendChild(opt);
        });

        if (staticOptions.length > 0) {
            const builtinHeader = document.createElement('option');
            builtinHeader.disabled = true;
            builtinHeader.dataset.pdfDynamic = '1';
            builtinHeader.textContent = '───── Built-in Fonts ─────';
            fontSelect.appendChild(builtinHeader);
        }
    }

    staticOptions.forEach((o) => fontSelect.appendChild(o));

    // Restore selection if the previous value is still available.
    const available = Array.from(fontSelect.options)
        .filter((o) => !o.disabled && String(o.value || '').trim());
    const match = available.find((o) => o.value.toLowerCase() === currentValue.toLowerCase());
    fontSelect.value = match?.value || available[0]?.value || '';
}
