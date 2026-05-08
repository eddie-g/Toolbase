// HTML helpers — pure, no DOM. Use `escapeHtml` whenever interpolating
// untrusted text into a string that will be assigned to .innerHTML.

export const escapeHtml = (s) =>
    String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
