// JSON-roundtrip clone used throughout the editor to copy annotation
// payloads, signature assets, and other plain-data structures without
// risking shared-reference mutation. Returns `fallback` if the input
// contains anything JSON cannot serialize (cycles, functions, etc.).

export function cloneSerializableValue(value, fallback) {
    try {
        return JSON.parse(JSON.stringify(value));
    } catch (_error) {
        return fallback;
    }
}
