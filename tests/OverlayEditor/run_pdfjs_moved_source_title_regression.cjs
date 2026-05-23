#!/usr/bin/env node

'use strict';

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const OUTPUT_DIR = path.join(ROOT, 'storage', 'app', 'overlay_regression_artifacts');
const SOURCE_PDF = path.join(ROOT, 'tests', 'OverlayEditor', 'fixtures', 'fss4.pdf');
const SCRIPT = path.join(ROOT, 'python', 'pdf-editor', 'apply_annotations_direct_new.py');
const OUT_PDF = path.join(OUTPUT_DIR, 'pdfjs_moved_source_title_regression.pdf');
const OUT_CROP = path.join(OUTPUT_DIR, 'pdfjs_moved_source_title_regression.png');
const ANN_JSON = path.join(OUTPUT_DIR, 'pdfjs_moved_source_title_regression.json');

fs.mkdirSync(OUTPUT_DIR, { recursive: true });
fs.copyFileSync(SOURCE_PDF, OUT_PDF);

const pageHeight = 791.968017578125;
const sourceBox = {
    x: 54.2765625,
    y: 734.4711249999999,
    w: 47.09007568359375,
    h: 24.000000000000004,
};
const currentBox = {
    x: 59.0766,
    y: 737.7711999999999,
    w: 47.09010000000001,
    h: 24.000000000000004,
};

fs.writeFileSync(ANN_JSON, JSON.stringify([
    {
        id: 'pdfjs_4170_0_0:0',
        type: 'text',
        pageIndex: 0,
        text: 'SS-5',
        originalText: 'SS-4',
        pdfjsSourceText: 'SS-4',
        sourceTextLines: ['SS-5'],
        savedTextOverlay: true,
        movedTextOverlay: true,
        pdfjsSourceFidelity: true,
        pdfjsEditorMode: 'source',
        pdfX: currentBox.x,
        pdfY: currentBox.y,
        pdfWidth: currentBox.w,
        pdfHeight: currentBox.h,
        fontSize: currentBox.h,
        lineHeight: currentBox.h,
        fontFamily: 'sans-serif',
        fontWeight: '400',
        fontStyle: 'normal',
        textColor: '#000000',
        color: '#000000',
        backgroundColor: 'transparent',
        opacity: 1,
        pdfjsSourceX: sourceBox.x,
        pdfjsSourceY: sourceBox.y,
        pdfjsSourceW: sourceBox.w,
        pdfjsSourceH: sourceBox.h,
        pdfjsSourceMaskX: sourceBox.x,
        pdfjsSourceMaskY: sourceBox.y,
        pdfjsSourceMaskW: sourceBox.w,
        pdfjsSourceMaskH: sourceBox.h,
        pdfjsSourcePageHeight: pageHeight,
        sourcePageHeight: pageHeight,
        pdfjsSourceFontFamily: 'sans-serif',
        pdfjsSourceFontWeight: '400',
        pdfjsSourceFontStyle: 'normal',
        pdfjsSourceFontSizePx: '80',
        pdfjsSourceLineHeightPx: '80px',
        pdfjsSourceTextWidthPx: '177.85934473381477',
        pdfjsSourceTransform: 'matrix(0.882534, 0, 0, 1, 0, 0)',
        pdfjsSourceTransformScaleX: '0.882534',
        pdfjsSourceTransformRotation: '0',
        pdfjsSourceTransformOrigin: '0px 0px',
        pdfjsSourceLetterSpacing: 'normal',
        pdfjsSourceTextColor: '#000000',
    },
], null, 2));

const apply = spawnSync('python3', [SCRIPT, OUT_PDF, ANN_JSON], {
    cwd: ROOT,
    encoding: 'utf8',
    env: {
        ...process.env,
        PYTHONPATH: path.join(ROOT, 'python', 'pdf-editor'),
    },
});
if (apply.status !== 0) {
    throw new Error(`apply_annotations_direct_new failed:\n${apply.stdout}\n${apply.stderr}`);
}

const check = spawnSync('python3', ['-'], {
    cwd: ROOT,
    encoding: 'utf8',
    input: `
import fitz

doc = fitz.open(${JSON.stringify(OUT_PDF)})
page = doc[0]

def dark_ratio(rect):
    pix = page.get_pixmap(matrix=fitz.Matrix(6, 6), clip=rect, alpha=False)
    channels = max(1, int(getattr(pix, "n", 3) or 3))
    samples = pix.samples
    dark = 0
    total = max(1, pix.width * pix.height)
    for offset in range(0, len(samples), channels):
        if offset + 2 >= len(samples):
            continue
        luminance = (0.299 * samples[offset]) + (0.587 * samples[offset + 1]) + (0.114 * samples[offset + 2])
        if luminance < 160:
            dark += 1
    return dark / total

# This is the strip where the original SS-4 starts but the moved SS-5 does not.
# A nonzero black fragment here is the exact visible failure from pdfjs_4170_0_0:0.
old_left_ratio = dark_ratio(fitz.Rect(54.0, 29.5, 58.8, 58.9))
if old_left_ratio > 0.01:
    raise SystemExit(f"source title ink was not fully covered at the old left edge: ratio={old_left_ratio:.4f}")

replacement_ratio = dark_ratio(fitz.Rect(59.0, 24.0, 106.4, 58.0))
if replacement_ratio < 0.22:
    raise SystemExit(f"replacement title is too light/thin versus the frontend title: ratio={replacement_ratio:.4f}")

title_spans = []
for block in page.get_text("dict").get("blocks", []):
    if not isinstance(block, dict):
        continue
    for line in block.get("lines", []):
        for span in line.get("spans", []):
            if span.get("text") == "SS-5" and span.get("bbox") and span["bbox"][1] < 80:
                title_spans.append((span.get("font"), fitz.Rect(span["bbox"])))
if not title_spans:
    raise SystemExit("pdfjs moved source title replacement SS-5 was not found near the title")

title_font, title_rect = min(title_spans, key=lambda item: abs(item[1].x0 - ${currentBox.x}))
if title_rect.width > ${currentBox.w} + 0.75:
    raise SystemExit(f"pdfjs moved source title replacement exceeded its frontend box: width={title_rect.width:.3f}")

page.get_pixmap(matrix=fitz.Matrix(3, 3), clip=fitz.Rect(0, 0, 260, 95), alpha=False).save(${JSON.stringify(OUT_CROP)})
print({
    "old_left_dark_ratio": round(old_left_ratio, 4),
    "replacement_dark_ratio": round(replacement_ratio, 4),
    "title_font": title_font,
    "title_width": round(title_rect.width, 3),
    "output": ${JSON.stringify(OUT_PDF)},
    "crop": ${JSON.stringify(OUT_CROP)},
})
`,
});
if (check.status !== 0) {
    throw new Error(`pdfjs moved source title regression check failed:\n${check.stdout}\n${check.stderr}`);
}

process.stdout.write(check.stdout);