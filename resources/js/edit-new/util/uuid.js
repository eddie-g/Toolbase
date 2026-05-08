// Tiny RFC 4122 v4 UUID generator. Uses Math.random — fine for non-security
// identifiers (session IDs, annotation UIDs). Do not use for crypto.

export const generateUuidV4 = () =>
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
