/*
 * ui/banner (Phase 7bd).
 *
 * Two trivial DOM-bound helpers extracted from main.js: render an
 * error message into the page-level error banner, or flash a toast
 * confirmation that auto-hides after 2s.
 *
 * The functions take their target element as an explicit argument so
 * main.js owns the DOM lookup; per the strangler-fig rebind pattern,
 * main.js then declares closure-bound zero-arg wrappers.
 */

export function showErrorBanner(errorBanner, msg) {
    if (!errorBanner) return;
    errorBanner.textContent = String(msg ?? '');
    errorBanner.style.display = 'block';
}

export function showSaveToast(saveToast, msg) {
    if (!saveToast) return;
    saveToast.textContent = String(msg ?? '');
    saveToast.classList.add('show');
    clearTimeout(saveToast._t);
    saveToast._t = setTimeout(() => saveToast.classList.remove('show'), 2000);
}
