// localStorage helpers that swallow QuotaExceeded / SecurityError exceptions.

export const safeLocalStorageGet = (key) => {
    try {
        return localStorage.getItem(key);
    } catch (_error) {
        return null;
    }
};

export const safeLocalStorageSet = (key, value) => {
    try {
        localStorage.setItem(key, value);
        return true;
    } catch (_error) {
        return false;
    }
};
