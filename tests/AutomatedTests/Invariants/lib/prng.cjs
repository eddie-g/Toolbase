/** Seeded PRNG (mulberry32) with small helpers. Same seed -> same choices. */
function mulberry32(seed) {
    let a = seed >>> 0;
    return function next() {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function rng(seed, stream = 0) {
    const next = mulberry32((Number(seed) * 2654435761 + stream * 97531) >>> 0);
    const r = {
        next,
        float: () => next(),
        int: (lo, hi) => lo + Math.floor(next() * (hi - lo + 1)),
        pick: (list) => list[Math.floor(next() * list.length)],
        chance: (p) => next() < p,
        frac: () => Math.round(next() * 1000) / 1000,
        weighted: (entries) => {
            const total = entries.reduce((s, [, w]) => s + w, 0);
            let x = next() * total;
            for (const [v, w] of entries) { if ((x -= w) < 0) return v; }
            return entries[entries.length - 1][0];
        },
    };
    return r;
}

module.exports = { rng };
