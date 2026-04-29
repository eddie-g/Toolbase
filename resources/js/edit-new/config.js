/*
 * Edit-New PDF editor — server-injected configuration.
 *
 * Reads values from the `#edit-new-root` element's data-* attributes
 * (set by the blade view from Laravel route() / model fields). Every
 * future edit-new module imports its URLs and identifiers from here
 * instead of re-reading the dataset, so server-side coupling lives in
 * exactly one file.
 */

const root = document.getElementById('edit-new-root');
if (!root) {
    throw new Error('edit-new-root element missing');
}
const cfg = root.dataset;

export const CSRF =
    document.querySelector('meta[name="csrf-token"]')?.content || cfg.csrf || '';
export const INFO_URL = cfg.infoUrl;
export const SAVE_URL = cfg.saveUrl;
export const DOWNLOAD_URL = cfg.downloadUrl;
export const DOC_ID = cfg.docId;
