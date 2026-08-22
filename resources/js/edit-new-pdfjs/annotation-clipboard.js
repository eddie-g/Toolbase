const NATIVE_CLIPBOARD_INPUT_TYPES = new Set([
    'email',
    'number',
    'password',
    'search',
    'tel',
    'text',
    'url',
]);

export function elementAcceptsNativeClipboard(activeElement) {
    if (!activeElement) return false;
    if (activeElement.isContentEditable || activeElement.closest?.('[contenteditable="true"]')) {
        return true;
    }
    const tagName = String(activeElement.tagName || '').toUpperCase();
    if (tagName === 'TEXTAREA') return true;
    if (tagName !== 'INPUT') return false;
    return NATIVE_CLIPBOARD_INPUT_TYPES.has(String(activeElement.type || 'text').toLowerCase());
}
