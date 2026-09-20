/*
 * Phones and small touch screens get a notice before the editor: what they
 * can do here (download, look) and where editing works. One decision
 * function, so it can be tested without a browser.
 */

export const MIN_EDITOR_WIDTH = 820;

/** @returns {'touch'|'narrow'|null} why the notice is due, or null */
export function desktopNoticeReason({ coarsePointer, canHover, viewportWidth, dismissed, automated } = {}) {
    if (dismissed || automated) return null;
    // A phone or tablet: the primary pointer is a finger and nothing can hover.
    // A laptop with a touch screen still reports a fine primary pointer.
    if (coarsePointer && !canHover) return 'touch';
    if (Number.isFinite(viewportWidth) && viewportWidth > 0 && viewportWidth < MIN_EDITOR_WIDTH) return 'narrow';

    return null;
}

export function installTouchGuard({ dialog, continueButton, storageKey = 'enpv_touch_notice_dismissed', win = globalThis.window } = {}) {
    if (!dialog || !win) return null;

    let dismissed = false;
    try {
        dismissed = win.sessionStorage.getItem(storageKey) === '1';
    } catch (_) {
        // Private mode: ask again next time.
    }
    const reason = desktopNoticeReason({
        coarsePointer: win.matchMedia?.('(pointer: coarse)').matches === true,
        canHover: win.matchMedia?.('(hover: hover)').matches === true,
        viewportWidth: win.innerWidth,
        dismissed,
        automated: win.navigator?.webdriver === true && win.__enpvForceTouchGuard !== true,
    });
    if (!reason) return null;

    dialog.hidden = false;
    dialog.setAttribute('aria-hidden', 'false');
    dialog.dataset.reason = reason;
    continueButton?.addEventListener('click', () => {
        dialog.hidden = true;
        dialog.setAttribute('aria-hidden', 'true');
        try {
            win.sessionStorage.setItem(storageKey, '1');
        } catch (_) {
            // Nothing to remember it in.
        }
    }, { once: true });
    (dialog.querySelector('a, button') || continueButton)?.focus?.();

    return reason;
}
