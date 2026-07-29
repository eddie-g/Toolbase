/*
 * Large editor-only values do not belong in data-* attributes.
 *
 * DOMStringMap serializes every value into the element's HTML, forcing arrays
 * to be JSON encoded and duplicating long source paragraphs in DevTools and
 * mutation snapshots. Keep that transient state attached to the element
 * without making it part of the DOM. The dataset fallback migrates boxes
 * created by older code or restored from legacy fixtures on first access.
 */

const runtimeStateByElement = new WeakMap();

function stateFor(element, create = false) {
    if (!element || (typeof element !== 'object' && typeof element !== 'function')) {
        return null;
    }
    let state = runtimeStateByElement.get(element);
    if (!state && create) {
        state = new Map();
        runtimeStateByElement.set(element, state);
    }
    return state || null;
}

function removeLegacyDatasetValue(element, key) {
    if (!element?.dataset) return;
    try {
        delete element.dataset[key];
    } catch (_) { /* non-DOM test doubles can expose a read-only dataset */ }
}

export function hasElementRuntimeState(element, key) {
    const state = stateFor(element);
    if (state?.has(key)) return true;
    return element?.dataset?.[key] !== undefined;
}

export function readElementRuntimeState(element, key) {
    const state = stateFor(element);
    if (state?.has(key)) return state.get(key);

    const legacyValue = element?.dataset?.[key];
    if (legacyValue === undefined) return undefined;

    const nextState = stateFor(element, true);
    nextState.set(key, legacyValue);
    removeLegacyDatasetValue(element, key);
    return legacyValue;
}

export function writeElementRuntimeState(element, key, value) {
    if (!element) return value;
    stateFor(element, true).set(key, value);
    removeLegacyDatasetValue(element, key);
    return value;
}

export function deleteElementRuntimeState(element, key) {
    const state = stateFor(element);
    const deleted = state?.delete(key) || false;
    if (state && state.size === 0) runtimeStateByElement.delete(element);
    const hadLegacyValue = element?.dataset?.[key] !== undefined;
    removeLegacyDatasetValue(element, key);
    return deleted || hadLegacyValue;
}

export function copyElementRuntimeState(source, target, keys = null) {
    if (!source || !target) return target;
    const sourceState = stateFor(source);
    const requestedKeys = Array.isArray(keys)
        ? keys
        : Array.from(sourceState?.keys() || []);
    for (const key of requestedKeys) {
        if (!hasElementRuntimeState(source, key)) continue;
        writeElementRuntimeState(target, key, readElementRuntimeState(source, key));
    }
    return target;
}

