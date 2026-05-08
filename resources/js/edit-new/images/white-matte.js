/*
 * White-matte (background) removal helpers (Phase 7o).
 *
 * Pure pixel/image utilities lifted out of main.js. Used by the
 * "remove background" path on image annotations to flood-fill near-white
 * border pixels into transparency. No editor or main.js deps.
 */

export function loadImageSource(src) {
    return new Promise((resolve, reject) => {
        if (!src) {
            reject(new Error('Missing image source.'));
            return;
        }
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Failed to load drawing image.'));
        img.src = src;
    });
}

export function toTransparentPngFileName(fileName, fallback = 'image.png') {
    const raw = String(fileName || '').trim() || fallback;
    const clean = raw.split(/[?#]/)[0] || fallback;
    if (!/\.[^./\\]+$/.test(clean)) return `${clean}.png`;
    return clean.replace(/\.[^./\\]+$/, '.png');
}

export function buildWhiteMatteCandidateMask(imageData) {
    const { data, width, height } = imageData || {};
    const pixelCount = Math.max(0, (Number(width) || 0) * (Number(height) || 0));
    const mask = new Uint8Array(pixelCount);
    for (let idx = 0; idx < pixelCount; idx += 1) {
        const offset = idx * 4;
        const alpha = Number(data[offset + 3]) || 0;
        if (alpha < 8) continue;
        const r = Number(data[offset]) || 0;
        const g = Number(data[offset + 1]) || 0;
        const b = Number(data[offset + 2]) || 0;
        const min = Math.min(r, g, b);
        const max = Math.max(r, g, b);
        const avg = (r + g + b) / 3;
        if (min >= 232 && avg >= 238 && (max - min) <= 26) {
            mask[idx] = 1;
        }
    }
    return mask;
}

export function removeWhiteMatteFromImageData(imageData) {
    const width = Number(imageData?.width) || 0;
    const height = Number(imageData?.height) || 0;
    if (!width || !height) return { changed: false, affectedPixels: 0 };

    const { data } = imageData;
    const candidateMask = buildWhiteMatteCandidateMask(imageData);
    const pixelCount = width * height;
    const visited = new Uint8Array(pixelCount);
    const queue = new Uint32Array(pixelCount);
    let head = 0;
    let tail = 0;

    const enqueue = (index) => {
        if (index < 0 || index >= pixelCount) return;
        if (!candidateMask[index] || visited[index]) return;
        visited[index] = 1;
        queue[tail] = index;
        tail += 1;
    };

    for (let x = 0; x < width; x += 1) {
        enqueue(x);
        enqueue(((height - 1) * width) + x);
    }
    for (let y = 1; y < height - 1; y += 1) {
        enqueue(y * width);
        enqueue((y * width) + (width - 1));
    }

    while (head < tail) {
        const index = queue[head];
        head += 1;
        const x = index % width;
        const y = Math.floor(index / width);
        if (x > 0) enqueue(index - 1);
        if (x + 1 < width) enqueue(index + 1);
        if (y > 0) enqueue(index - width);
        if (y + 1 < height) enqueue(index + width);
    }

    let changed = false;
    let affectedPixels = 0;
    for (let index = 0; index < pixelCount; index += 1) {
        if (!visited[index]) continue;
        const x = index % width;
        const y = Math.floor(index / width);
        const offset = index * 4;
        const currentAlpha = Number(data[offset + 3]) || 0;
        if (!currentAlpha) continue;

        const isBorderPixel = x === 0 || y === 0 || x === (width - 1) || y === (height - 1);
        let visitedNeighbors = 0;
        for (let ny = Math.max(0, y - 1); ny <= Math.min(height - 1, y + 1); ny += 1) {
            for (let nx = Math.max(0, x - 1); nx <= Math.min(width - 1, x + 1); nx += 1) {
                if (nx === x && ny === y) continue;
                if (visited[(ny * width) + nx]) visitedNeighbors += 1;
            }
        }

        let nextAlpha = currentAlpha;
        if (isBorderPixel || visitedNeighbors >= 5) {
            nextAlpha = 0;
        } else if (visitedNeighbors >= 3) {
            nextAlpha = Math.min(currentAlpha, 48);
        }
        if (nextAlpha === currentAlpha) continue;

        if (nextAlpha > 0) {
            const alphaRatio = nextAlpha / 255;
            for (let channel = 0; channel < 3; channel += 1) {
                const composited = (Number(data[offset + channel]) || 0) / 255;
                const unblended = (composited - (1 - alphaRatio)) / Math.max(alphaRatio, 0.001);
                data[offset + channel] = Math.max(0, Math.min(255, Math.round(unblended * 255)));
            }
        } else {
            data[offset] = 0;
            data[offset + 1] = 0;
            data[offset + 2] = 0;
        }
        data[offset + 3] = nextAlpha;
        changed = true;
        affectedPixels += 1;
    }

    return { changed, affectedPixels };
}
