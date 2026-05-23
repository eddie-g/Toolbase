#!/usr/bin/env node

'use strict';

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const OUTPUT_DIR = path.join(ROOT, 'storage', 'app', 'overlay_regression_artifacts');
const SOURCE_PDF = path.join(ROOT, 'tests', 'OverlayEditor', 'fixtures', 'fss4.pdf');
const SCRIPT = path.join(ROOT, 'python', 'pdf-editor', 'apply_annotations_direct_new.py');
const OUT_PDF = path.join(OUTPUT_DIR, 'promoted_single_span_title_regression.pdf');
const ANN_JSON = path.join(OUTPUT_DIR, 'promoted_single_span_title_regression.json');

fs.mkdirSync(OUTPUT_DIR, { recursive: true });
fs.copyFileSync(SOURCE_PDF, OUT_PDF);

const sourceBox = [54.28400039672851, 33.7283935546875, 101.37199401855467, 57.7283935546875];
const pageHeight = 791.968017578125;
const sourceWidth = sourceBox[2] - sourceBox[0];
const sourceHeight = sourceBox[3] - sourceBox[1];

fs.writeFileSync(ANN_JSON, JSON.stringify([
    {
        id: 'promoted_1_22',
        type: 'text',
        pageIndex: 0,
        text: 'SS-5',
        originalText: 'SS-4',
        promotedFromExtraction: true,
        promotedDirty: true,
        savedTextOverlay: true,
        preserveSourceTypography: true,
        pdfjsEditorMode: 'source',
        pdfX: sourceBox[0],
        pdfY: pageHeight - sourceBox[3],
        pdfWidth: sourceWidth,
        pdfHeight: sourceHeight,
        fontSize: 24,
        lineHeight: 24,
        fontFamily: 'HelveticaNeueLTStd',
        fontWeight: '900',
        textColor: '#000000',
        backgroundColor: 'transparent',
        sourceBlockLeft: sourceBox[0],
        sourceBlockTop: sourceBox[1],
        sourceBlockWidth: sourceWidth,
        sourceBlockHeight: sourceHeight,
        sourceTextLines: ['SS-4'],
        sourceLineBBoxes: [sourceBox],
        sourceSpans: [
            {
                bbox: sourceBox,
                font: 'HelveticaNeueLTStd-BlkCn',
                embedded_font_name: 'HelveticaNeueLTStd-BlkCn',
                text: 'SS-4',
                rawText: 'SS-4',
                origin: [54.28400039672851, 53.13604736328125],
                fontSize: 24,
                font_size: 24,
                fontWeight: '900',
                font_weight: '900',
                hex_color: '#000000',
                direction: [1, 0],
            },
        ],
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
title_spans = []
for block in page.get_text("rawdict").get("blocks", []):
    for line in block.get("lines", []):
        for span in line.get("spans", []):
            text = "".join(char.get("c", "") for char in span.get("chars", []))
            normalized = text.replace("\u00ad", "-")
            bbox = span.get("bbox")
            if normalized == "SS-5" and bbox and bbox[1] < 80:
                title_spans.append(fitz.Rect(bbox))
if not title_spans:
    raise SystemExit("promoted title replacement SS-5 was not found near the title")
title = min(title_spans, key=lambda rect: abs(rect.x0 - ${sourceBox[0]}))
if title.width > ${sourceWidth} + 0.75:
    raise SystemExit(f"promoted title replacement was not fitted to source width: width={title.width}, source=${sourceWidth}")
if abs(title.x0 - ${sourceBox[0]}) > 0.75:
    raise SystemExit(f"promoted title replacement shifted horizontally: x0={title.x0}, source=${sourceBox[0]}")
print({"title_x0": round(title.x0, 3), "title_width": round(title.width, 3), "source_width": round(${sourceWidth}, 3), "output": ${JSON.stringify(OUT_PDF)}})
`,
});
if (check.status !== 0) {
    throw new Error(`promoted title regression check failed:\n${check.stdout}\n${check.stderr}`);
}

process.stdout.write(check.stdout);