/*
 * signature/saved-view (NK_Dev_4).
 *
 * The signature modal has three composer modes (draw / type / upload) plus a
 * fourth "Saved" tab that shows the saved-signature library. Saved is a VIEW,
 * not a composer mode: it must never reach signatureMode or
 * signatureSourceMode, because those are persisted onto the annotation and
 * only draw/type/upload are valid there.
 *
 * So the saved tab is tracked purely as a class on the modal element, and the
 * mode panels are hidden by CSS while it is active. Both the legacy
 * /edit-new editor and the pdf.js port share this helper.
 *
 * This module also owns the tablist semantics the markup promises: aria-selected,
 * a roving tabindex, and arrow/Home/End navigation.
 */

export const SAVED_VIEW_CLASS = 'is-saved-view';

/** Is the Saved tab currently showing? */
export function isSavedViewActive(signatureModal) {
    return !!signatureModal && signatureModal.classList.contains(SAVED_VIEW_CLASS);
}

/**
 * Sync ARIA + roving tabindex across the whole tablist from the `is-active`
 * class the mode painting already applies.
 *
 * Exactly one tab stays in the tab order; the rest are reachable with the
 * arrow keys, which is what role="tab" leads assistive tech to expect.
 */
export function paintSignatureTabs(allTabs) {
    const tabs = allTabs || [];
    const hasActive = tabs.some((tab) => tab.classList.contains('is-active'));

    tabs.forEach((tab, index) => {
        const active = tab.classList.contains('is-active');
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        // Fall back to the first tab so the list is never unreachable.
        tab.tabIndex = active || (!hasActive && index === 0) ? 0 : -1;
    });
}

/**
 * Show or hide the Saved view.
 *
 * When leaving it, `restoreModeUi` repaints the mode tabs so the previously
 * active composer mode is highlighted again.
 */
export function setSavedViewActive(active, { signatureModal, modeTabs, viewTabs, restoreModeUi } = {}) {
    if (!signatureModal) return;

    const showSaved = !!active;
    signatureModal.classList.toggle(SAVED_VIEW_CLASS, showSaved);

    (viewTabs || []).forEach((tab) => {
        tab.classList.toggle('is-active', showSaved && tab.dataset.signatureView === 'saved');
    });

    if (showSaved) {
        // The composer mode is unchanged underneath; just drop the highlight.
        (modeTabs || []).forEach((tab) => tab.classList.remove('is-active'));
        paintSignatureTabs([...(modeTabs || []), ...(viewTabs || [])]);
        return;
    }

    if (typeof restoreModeUi === 'function') restoreModeUi();
    paintSignatureTabs([...(modeTabs || []), ...(viewTabs || [])]);
}

/**
 * Wire arrow-key navigation across the tablist.
 *
 * `activate` receives the tab element that should become current; callers map
 * it to either a composer mode or the Saved view.
 */
export function installSignatureTabKeyboard(allTabs, activate) {
    const tabs = allTabs || [];
    if (tabs.length === 0 || typeof activate !== 'function') return;

    tabs.forEach((tab, index) => {
        tab.addEventListener('keydown', (event) => {
            let nextIndex = null;
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                nextIndex = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                nextIndex = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabs.length - 1;
            }
            if (nextIndex === null) return;

            event.preventDefault();
            const nextTab = tabs[nextIndex];
            activate(nextTab);
            nextTab.focus();
        });
    });
}
