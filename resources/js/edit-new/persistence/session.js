// Per-document session id, persisted in localStorage so save/load survives a
// page reload. The id is generated once on first call for a given document
// and reused thereafter; it scopes the server-side
// "scratch" annotation buffer separate from the persisted document.

import { DOC_ID } from '../config.js';
import { safeLocalStorageGet, safeLocalStorageSet } from '../util/storage.js';
import { generateUuidV4 } from '../util/uuid.js';

const SESSION_KEY = `edit_new_session_${DOC_ID}`;

export const getSessionId = () => {
    let id = safeLocalStorageGet(SESSION_KEY);
    if (!id) {
        id = generateUuidV4();
        safeLocalStorageSet(SESSION_KEY, id);
    }
    return id;
};
