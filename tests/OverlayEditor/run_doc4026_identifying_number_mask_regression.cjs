#!/usr/bin/env node

'use strict';

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const OUTPUT_DIR = path.join(ROOT, 'storage', 'app', 'overlay_regression_artifacts');
const SOURCE_PDF = path.join(ROOT, 'public', 'f1040nr.pdf');
const SCRIPT = path.join(ROOT, 'python', 'pdf-editor', 'apply_annotations_direct_new.py');
const OUT_PDF = path.join(OUTPUT_DIR, 'doc4026_identifying_number_mask_regression.pdf');
const ANN_JSON = path.join(OUTPUT_DIR, 'doc4026_identifying_number_mask_regression.json');

fs.mkdirSync(OUTPUT_DIR, { recursive: true });
fs.copyFileSync(SOURCE_PDF, OUT_PDF);
fs.writeFileSync(ANN_JSON, JSON.stringify([
    {
        id: 'pdfjs_4026_0_0:56',
        type: 'text',
        pageIndex: 0,
        text: '(do not call)',
        originalText: '(see instructions)',
        savedTextOverlay: true,
        pdfX: 500.7960000000001,
        pdfY: 677.9072399999999,
        pdfWidth: 60.45060000000001,
        pdfHeight: 9.230760000000002,
        pdfjsSourceX: 471.99519230769226,
        pdfjsSourceY: 678.8419471153845,
        pdfjsSourceW: 60.45058030348557,
        pdfjsSourceH: 7.995793269230769,
        pdfjsSourceText: '(see instructions)',
        fontSize: 7.995793269230769,
        lineHeight: 7.995780000000001,
        fontFamily: 'sans-serif',
        fontWeight: '400',
        textColor: '#000000',
        backgroundColor: 'transparent',
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
heading_rects = page.search_for("Your identifying number")
if not heading_rects:
    raise SystemExit("heading text was not searchable in exported PDF")
heading = heading_rects[0]
mask_rects = []
for drawing in page.get_drawings():
    rect = drawing.get("rect") if isinstance(drawing, dict) else None
    fill = drawing.get("fill") if isinstance(drawing, dict) else None
    if not isinstance(rect, fitz.Rect) or fill is None:
        continue
    if rect.x0 < 472 and rect.x1 > 532 and rect.y0 > 100 and rect.y0 < 110:
        mask_rects.append(rect)
if not mask_rects:
    raise SystemExit("expected source mask rectangle was not found")
mask = min(mask_rects, key=lambda r: abs(r.y0 - heading.y1))
if mask.y0 < heading.y1 + 0.05:
    raise SystemExit(f"source mask overlaps heading descender: mask.y0={mask.y0}, heading.y1={heading.y1}")
print({"heading_y1": round(heading.y1, 3), "mask_y0": round(mask.y0, 3), "output": ${JSON.stringify(OUT_PDF)}})
`,
});
if (check.status !== 0) {
    throw new Error(`export mask check failed:\n${check.stdout}\n${check.stderr}`);
}

process.stdout.write(check.stdout);
