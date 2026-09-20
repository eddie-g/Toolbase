/*
 * Delta saves. An autosave used to post the editor's whole state after every
 * change: 81 KB for a one-page invoice, 232 KB for 500 text annotations. The
 * tracker remembers a hash per annotation of the last state the server
 * acknowledged; the next save carries the annotations whose hash differs, the
 * ids that are gone, and how many annotations there are now. The server
 * rebuilds the full state from its rows and checks the count
 * (DocumentController::expandDeltaToFullState).
 *
 * Whenever the two sides might disagree the editor sends everything: the
 * first save after a load, after any failed save, after the server says
 * `delta_base_missing`, and when ids are missing or duplicated.
 */

/** A stable string for a value: object keys sorted, so key order never reads as a change. */
export function canonicalJson(value) {
    if (value === null || typeof value !== 'object') return JSON.stringify(value) ?? 'null';
    if (Array.isArray(value)) return `[${value.map((item) => canonicalJson(item)).join(',')}]`;

    return `{${Object.keys(value).sort()
        .filter((key) => value[key] !== undefined)
        .map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key])}`)
        .join(',')}}`;
}

/** FNV-1a over the canonical form, with its length: cheap, and a collision needs equal length too. */
export function hashAnnotation(annotation) {
    const text = canonicalJson(annotation);
    let hash = 0x811c9dc5;
    for (let index = 0; index < text.length; index += 1) {
        hash ^= text.charCodeAt(index);
        hash = Math.imul(hash, 0x01000193) >>> 0;
    }

    return `${text.length.toString(36)}.${hash.toString(36)}`;
}

function hashesById(annotations) {
    const hashes = new Map();
    for (const annotation of annotations) {
        const id = typeof annotation?.id === 'string' ? annotation.id.trim() : '';
        if (!id || hashes.has(id)) return null;   // no id, or the same id twice: only a full save is safe
        hashes.set(id, hashAnnotation(annotation));
    }

    return hashes;
}

export function createDeltaTracker() {
    let acknowledged = null;   // Map id -> hash of what the server has; null = unknown

    return {
        /**
         * What to send for this state. `annotations` are in the form they
         * travel in (images already slimmed to references).
         * @returns {{ delta: boolean, annotations: object[], removedIds: string[], expectedCount: number, hashes: Map<string, string>|null }}
         */
        plan(annotations) {
            const list = Array.isArray(annotations) ? annotations : [];
            // Hashed now, while this is what travels: the editor may change an
            // annotation again before the answer arrives.
            const hashes = hashesById(list);
            const full = { delta: false, annotations: list, removedIds: [], expectedCount: list.length, hashes };
            if (!acknowledged || !hashes) return full;

            const changed = list.filter((annotation) => acknowledged.get(annotation.id.trim()) !== hashes.get(annotation.id.trim()));
            // Everything changed (a bulk restyle): the full form says the same with less.
            if (changed.length === list.length && list.length > 0) return full;
            const removedIds = Array.from(acknowledged.keys()).filter((id) => !hashes.has(id));

            return { delta: true, annotations: changed, removedIds, expectedCount: hashes.size, hashes };
        },
        /** The server stored the state this plan described. */
        acknowledge(plan) {
            acknowledged = plan?.hashes instanceof Map ? plan.hashes : null;
        },
        /** The two sides may disagree: the next save is a full one. */
        reset() {
            acknowledged = null;
        },
        get hasBaseline() { return acknowledged !== null; },
    };
}

/**
 * Annotations whose image data has not been uploaded yet, in the form the
 * upload route takes. `storedAssets` is the editor's map of what the server
 * already has, keyed to the exact data that was sent.
 */
export function imagesToUpload(annotations, storedAssets) {
    const pending = [];
    for (const annotation of annotations || []) {
        const id = String(annotation?.id ?? '');
        const inline = [annotation?.dataUrl, annotation?.src].find((value) => typeof value === 'string' && value.startsWith('data:image/'));
        if (!id || !inline) continue;
        if (storedAssets?.get?.(id)?.data === inline) continue;
        pending.push({ id, dataUrl: inline, fileName: annotation.fileName || null, mimeType: annotation.mimeType || null });
    }

    return pending;
}
