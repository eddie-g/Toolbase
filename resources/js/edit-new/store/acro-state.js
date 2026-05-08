// Phase 5f store slice: AcroForm state. `acroFormEntries` is the
// flat list of fields parsed from the loaded PDF; `acroFieldLookup`
// is `key → entry` for O(1) updates; `acroWidgetsByPage` caches the
// per-page rendered widgets keyed by page number string.

export let acroFormEntries = [];
export let acroFieldLookup = {};
export let acroWidgetsByPage = {};

export function setAcroFormEntries(entries) {
    acroFormEntries = Array.isArray(entries) ? entries : [];
}

export function setAcroFieldLookup(lookup) {
    acroFieldLookup = lookup && typeof lookup === 'object' ? lookup : {};
}

export function setAcroWidgetsByPage(map) {
    acroWidgetsByPage = map && typeof map === 'object' ? map : {};
}

export function setAcroWidgetsForPage(pageKey, widgets) {
    acroWidgetsByPage = { ...acroWidgetsByPage, [pageKey]: widgets };
}
