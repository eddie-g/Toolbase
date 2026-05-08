/*
 * Pretty-printers used by the editor-HTML debug modal (Phase 7ap).
 *
 * Pure string helpers — no DOM, no globals. They prepare the
 * captured editor `innerHTML` / outer cssText for human-readable
 * display in the debug modal:
 *   - dbgEscape: minimal HTML entity escape for inline display.
 *   - dbgPrettyHtml: light pretty-print that inserts a newline
 *     before each top-level/inner block tag (div/p/br/span). Not a
 *     full formatter — just enough to scan visually.
 *   - dbgFormatStyle: split a `;`-joined cssText into one declaration
 *     per line.
 */

export function dbgEscape(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

export function dbgPrettyHtml(html) {
    // Light pretty-print: insert newline before each top-level/inner block tag.
    // Not a full formatter — readable enough to scan.
    const TAGS = ['div', 'p', 'br', 'span'];
    let out = String(html ?? '');
    TAGS.forEach((t) => {
        out = out.replace(new RegExp('<' + t + '\\b', 'gi'), '\n<' + t);
        out = out.replace(new RegExp('</' + t + '>', 'gi'), '</' + t + '>\n');
    });
    return out
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l.length > 0)
        .join('\n');
}

export function dbgFormatStyle(cssText) {
    return String(cssText || '')
        .split(';')
        .map((s) => s.trim())
        .filter((s) => s.length > 0)
        .join(';\n');
}
