import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import * as pdfjsLib from 'pdfjs-dist/legacy/build/pdf.mjs';

import {
    pdfRectToViewportRect,
    rotationSnapshotTransform,
    viewportPdfDimensions,
    viewportRectToPdfRect,
    viewportRotatedContentFrame,
} from './page-geometry.js';

const TEST_DIR = path.dirname(fileURLToPath(import.meta.url));
const SS5_PATH = path.resolve(TEST_DIR, '../../../public/ss-5.pdf');

async function loadSs5PageOne() {
    const bytes = new Uint8Array(await fs.readFile(SS5_PATH));
    const document = await pdfjsLib.getDocument({ data: bytes, isEvalSupported: false }).promise;
    return { document, page: await document.getPage(1) };
}

function assertRectClose(actual, expected, tolerance = 1e-6) {
    for (const key of ['x', 'y', 'w', 'h']) {
        assert.ok(
            Math.abs(Number(actual[key]) - Number(expected[key])) <= tolerance,
            `${key}: expected ${expected[key]}, received ${actual[key]}`,
        );
    }
}

test('ss-5 page 1 annotation rectangles round-trip at every page rotation', async () => {
    const { document, page } = await loadSs5PageOne();
    try {
        const fixtures = [
            // Existing PDF text annotation geometry.
            { type: 'text', x: 53.672, y: 655.262, w: 198.645, h: 14.063 },
            // Representative custom shape/image geometry.
            { type: 'shape', x: 120, y: 180, w: 96, h: 42 },
            { type: 'image', x: 330, y: 95, w: 144, h: 72 },
        ];
        for (const rotation of [0, 90, 180, 270]) {
            const viewport = page.getViewport({ scale: 1.75, rotation });
            for (const annotation of fixtures) {
                const canvasRect = pdfRectToViewportRect(annotation, viewport);
                assert.ok(canvasRect.width > 0 && canvasRect.height > 0, `${annotation.type} renders at ${rotation}°`);
                const roundTrip = viewportRectToPdfRect(canvasRect, viewport);
                assertRectClose(roundTrip, annotation);
                if (rotation === 90 || rotation === 270) {
                    assert.ok(Math.abs(canvasRect.width - (annotation.h * viewport.scale)) < 1e-6);
                    assert.ok(Math.abs(canvasRect.height - (annotation.w * viewport.scale)) < 1e-6);
                }
            }
        }
    } finally {
        await document.destroy();
    }
});

test('a flattened zero-degree page rotates as one scaled surface', () => {
    assert.equal(
        rotationSnapshotTransform(90, 612, 792, 1584, 1224),
        'matrix(0, 2, -2, 0, 1584, 0)',
    );
    assert.equal(
        rotationSnapshotTransform(180, 612, 792, 1224, 1584),
        'matrix(-2, 0, 0, -2, 1224, 1584)',
    );
    assert.equal(
        rotationSnapshotTransform(270, 612, 792, 1584, 1224),
        'matrix(0, -2, 2, 0, 0, 1224)',
    );
    assert.equal(
        rotationSnapshotTransform(0, 612, 792, 1224, 1584),
        'matrix(2, 0, 0, 2, 0, 0)',
    );
});

test('ss-5 page dimensions and annotation content frames remain in canonical PDF space', async () => {
    const { document, page } = await loadSs5PageOne();
    try {
        const baseViewport = page.getViewport({ scale: 1, rotation: 0 });
        const baseSize = viewportPdfDimensions(baseViewport);
        assert.equal(baseSize.width, 612);
        assert.equal(baseSize.height, 792);

        const rightViewport = page.getViewport({ scale: 1, rotation: 90 });
        assert.deepEqual(viewportPdfDimensions(rightViewport), baseSize);
        const frame = viewportRotatedContentFrame(rightViewport, 24, 180);
        assert.deepEqual(frame, {
            rotation: 90,
            width: 180,
            height: 24,
            transform: 'translate(24px, 0px) rotate(90deg)',
        });
        assert.deepEqual(viewportRotatedContentFrame(page.getViewport({ scale: 1, rotation: 180 }), 180, 24), {
            rotation: 180,
            width: 180,
            height: 24,
            transform: 'translate(180px, 24px) rotate(180deg)',
        });
        assert.deepEqual(viewportRotatedContentFrame(page.getViewport({ scale: 1, rotation: 270 }), 24, 180), {
            rotation: 270,
            width: 180,
            height: 24,
            transform: 'translate(0px, 180px) rotate(-90deg)',
        });
    } finally {
        await document.destroy();
    }
});
