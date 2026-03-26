#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'Addendum.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

const GEOMETRY_TOLERANCES = {
    waiverLead: 2.5,
    amountInline: 2.5,
    footerLines: 2.0,
};

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    await page.locator('#document-input').setInputFiles(PDF_PATH);
    await page.getByRole('button', { name: 'Upload PDF', exact: true }).click();
    await page.waitForURL(/\/documents\/\d+\/edit/);
    await page.waitForTimeout(5000);

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`Could not determine document id from URL: ${page.url()}`);
    }
    return Number(match[1]);
}

async function activateEditText(page) {
    await page.locator('#mode-edit-text').click();
    await page.waitForTimeout(2000);
}

function buildCropFromRects(rects, padding = 10) {
    const filtered = rects.filter(Boolean);
    if (!filtered.length) {
        return null;
    }
    const left = Math.min(...filtered.map((rect) => rect.left));
    const top = Math.min(...filtered.map((rect) => rect.top));
    const right = Math.max(...filtered.map((rect) => rect.left + rect.width));
    const bottom = Math.max(...filtered.map((rect) => rect.top + rect.height));
    return {
        left: Math.max(0, Math.floor(left - padding)),
        top: Math.max(0, Math.floor(top - padding)),
        width: Math.ceil((right - left) + (padding * 2)),
        height: Math.ceil((bottom - top) + (padding * 2)),
    };
}

async function captureState(page) {
    return page.evaluate(() => {
        const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageEl = document.querySelector('.page[data-page-index="0"]');
        if (!pageEl) {
            throw new Error('missing first rendered page');
        }
        const pageRect = pageEl.getBoundingClientRect();

        const annotationsState = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const buildPromotedAnnotationState = (annotation) => {
            if (!annotation?.element) {
                return null;
            }
            const rect = annotation.element.getBoundingClientRect();
            const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
            const sourceMeta = typeof getPromotedAnnotationSourceMeta === 'function'
                ? getPromotedAnnotationSourceMeta(annotation)
                : null;
            const exactLines = Array.from(annotation.element.querySelectorAll('.annotation-exact-line')).map((line) => {
                const lineRect = line.getBoundingClientRect();
                return {
                    index: Number(line.dataset.sourceLineIndex),
                    text: String(line.innerText || ''),
                    left: lineRect.left - pageRect.left,
                    top: lineRect.top - pageRect.top,
                    width: lineRect.width,
                    height: lineRect.height,
                };
            });
            const exactSpans = Array.from(annotation.element.querySelectorAll('.annotation-exact-span')).map((span) => {
                const spanRect = span.getBoundingClientRect();
                return {
                    text: String(span.textContent || ''),
                    left: spanRect.left - pageRect.left,
                    top: spanRect.top - pageRect.top,
                    width: spanRect.width,
                    height: spanRect.height,
                };
            });

            return {
                text: normalize(annotation.text || ''),
                rawText: String(annotation.element.innerText || ''),
                exactGeometry: textEl.dataset.exactPromotedGeometry || null,
                left: rect.left - pageRect.left,
                top: rect.top - pageRect.top,
                width: rect.width,
                height: rect.height,
                sourceMeta,
                exactLines,
                exactSpans,
            };
        };

        const promotedAnnotations = annotationsState.filter((annotation) => annotation?.promotedFromExtraction);
        const waiver1 = buildPromotedAnnotationState(promotedAnnotations.find((annotation) => (
            String(annotation.text || '').includes('WAIVER. Buyer waives')
        )));
        const waiver2 = buildPromotedAnnotationState(promotedAnnotations.find((annotation) => (
            String(annotation.text || '').includes('PARTIAL WAIVER. Buyer waives')
        )));
        const amountLine = buildPromotedAnnotationState(promotedAnnotations.find((annotation) => (
            String(annotation.text || '').includes('(i) the appraised value')
        )));
        const amountStandalone = buildPromotedAnnotationState(promotedAnnotations.find((annotation) => (
            normalize(annotation.text || '') === '157,500'
        )));
        const daysLine = buildPromotedAnnotationState(promotedAnnotations.find((annotation) => (
            String(annotation.text || '').includes('days after the Effective Date if:')
        )));
        const daysStandalone = buildPromotedAnnotationState(promotedAnnotations.find((annotation) => (
            normalize(annotation.text || '') === '24'
        )));
        const footer = buildPromotedAnnotationState(promotedAnnotations.find((annotation) => (
            String(annotation.text || '').includes('The form of this addendum')
        )));

        return {
            page: {
                width: Math.round(pageRect.width),
                height: Math.round(pageRect.height),
            },
            waiver1,
            waiver2,
            amountLine,
            amountStandalone,
            daysLine,
            daysStandalone,
            footer,
            checks: {
                waiver1Spacing: Boolean(waiver1 && /\(1\)\s+WAIVER\.\s+Buyer/.test(waiver1.rawText)),
                waiver2Spacing: Boolean(waiver2 && /\(2\)\s+PARTIAL WAIVER\.\s+Buyer/.test(waiver2.rawText)),
                amountAbsorbedIntoParent: Boolean(
                    amountLine
                    && amountLine.rawText.includes('157,500')
                    && !amountStandalone
                ),
                amountUnderlineGapPreserved: Boolean(
                    amountLine
                    && amountLine.exactSpans.some((span) => /\$ {3,}; and/.test(span.text))
                ),
                daysAbsorbedIntoParent: Boolean(
                    daysLine
                    && daysLine.rawText.includes('24')
                    && !daysStandalone
                ),
                daysUnderlineGapPreserved: Boolean(
                    daysLine
                    && daysLine.exactSpans.some((span) => /^ {3,}days\s+after\s+the\s+Effective Date if:/.test(span.text))
                ),
                footerLineCountMatchesSource: Boolean(
                    footer
                    && footer.sourceMeta
                    && Array.isArray(footer.sourceMeta.sourceTextLines)
                    && footer.exactLines.length === footer.sourceMeta.sourceTextLines.length
                ),
            },
        };
    });
}

function buildExpectedRelativeRect(annotationState, sourceBBox) {
    if (!annotationState?.sourceMeta || !Array.isArray(sourceBBox) || sourceBBox.length < 4) {
        return null;
    }
    const sourceBlockWidth = Number(annotationState.sourceMeta.sourceBlockWidth) || 0;
    const sourceBlockHeight = Number(annotationState.sourceMeta.sourceBlockHeight) || 0;
    if (sourceBlockWidth <= 0 || sourceBlockHeight <= 0) {
        return null;
    }

    const scaleX = annotationState.width / sourceBlockWidth;
    const scaleY = annotationState.height / sourceBlockHeight;
    const sourceBlockLeft = Number(annotationState.sourceMeta.sourceBlockLeft) || 0;
    const sourceBlockTop = Number(annotationState.sourceMeta.sourceBlockTop) || 0;
    const left = annotationState.left + ((Number(sourceBBox[0]) || 0) - sourceBlockLeft) * scaleX;
    const top = annotationState.top + ((Number(sourceBBox[1]) || 0) - sourceBlockTop) * scaleY;
    const width = Math.max(1, ((Number(sourceBBox[2]) || 0) - (Number(sourceBBox[0]) || 0)) * scaleX);
    const height = Math.max(1, ((Number(sourceBBox[3]) || 0) - (Number(sourceBBox[1]) || 0)) * scaleY);
    return { left, top, width, height };
}

function rectDelta(actualRect, expectedRect) {
    if (!actualRect || !expectedRect) {
        return Number.POSITIVE_INFINITY;
    }
    return Math.max(
        Math.abs(actualRect.left - expectedRect.left),
        Math.abs(actualRect.top - expectedRect.top),
        Math.abs(actualRect.width - expectedRect.width),
        Math.abs(actualRect.height - expectedRect.height),
    );
}

function spanAnchorDelta(actualRect, expectedRect) {
    if (!actualRect || !expectedRect) {
        return Number.POSITIVE_INFINITY;
    }
    return Math.max(
        Math.abs(actualRect.left - expectedRect.left),
        Math.abs(actualRect.top - expectedRect.top),
        Math.abs(actualRect.height - expectedRect.height),
    );
}

function measureLineGeometry(annotationState) {
    if (!annotationState?.sourceMeta || !Array.isArray(annotationState.sourceMeta.sourceLineBBoxes)) {
        return { maxDelta: Number.POSITIVE_INFINITY, details: [] };
    }

    const details = annotationState.sourceMeta.sourceLineBBoxes.map((bbox, index) => {
        const actualLine = annotationState.exactLines.find((line) => line.index === index);
        const expectedLine = buildExpectedRelativeRect(annotationState, bbox);
        return {
            index,
            actual: actualLine,
            expected: expectedLine,
            delta: rectDelta(actualLine, expectedLine),
        };
    });

    return {
        maxDelta: details.reduce((maxDelta, detail) => Math.max(maxDelta, Number(detail.delta) || 0), 0),
        details,
    };
}

function measureSpanGeometry(annotationState, matcher) {
    if (!annotationState?.sourceMeta || !Array.isArray(annotationState.sourceMeta.sourceSpans)) {
        return { delta: Number.POSITIVE_INFINITY, actual: null, expected: null, source: null };
    }

    const sourceSpan = annotationState.sourceMeta.sourceSpans.find((span) => matcher(String(span?.text || '')));
    const actualSpan = annotationState.exactSpans.find((span) => matcher(String(span?.text || '')));
    const expectedRect = sourceSpan ? buildExpectedRelativeRect(annotationState, sourceSpan.bbox) : null;
    return {
        actual: actualSpan,
        expected: expectedRect,
        source: sourceSpan || null,
        delta: spanAnchorDelta(actualSpan, expectedRect),
    };
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 2200 } });

    try {
        const documentId = await uploadPdf(page);
        await activateEditText(page);

        const state = await captureState(page);
        if (!state.waiver1 || !state.waiver2 || !state.amountLine || !state.daysLine || !state.footer) {
            throw new Error(`Missing target annotation(s): ${JSON.stringify(state, null, 2)}`);
        }

        const screenshotPath = path.join(OUTPUT_DIR, `addendum_edit_text_doc${documentId}_page0.png`);
        await page.locator('.page[data-page-index="0"]').first().screenshot({ path: screenshotPath });

        const waiver1LeadGeometry = measureSpanGeometry(state.waiver1, (text) => text.startsWith('(1)') || text.includes('WAIVER.') || text.startsWith('Buyer waives'));
        const waiver2LeadGeometry = measureSpanGeometry(state.waiver2, (text) => text.startsWith('(2)') || text.includes('PARTIAL WAIVER.') || text.startsWith('Buyer waives'));
        const amountInlineGeometry = measureSpanGeometry(state.amountLine, (text) => text.includes('157,500'));
        const daysInlineGeometry = measureSpanGeometry(state.daysLine, (text) => text.includes('24'));
        const footerLineGeometry = measureLineGeometry(state.footer);

        const checks = [
            {
                item: 'waiver_lead_spacing_preserved',
                pass: state.checks.waiver1Spacing && state.checks.waiver2Spacing,
                detail: {
                    waiver1: state.waiver1.rawText,
                    waiver2: state.waiver2.rawText,
                },
            },
            {
                item: 'waiver_lead_geometry_matches_source',
                pass: waiver1LeadGeometry.delta <= GEOMETRY_TOLERANCES.waiverLead
                    && waiver2LeadGeometry.delta <= GEOMETRY_TOLERANCES.waiverLead,
                detail: {
                    waiver1: waiver1LeadGeometry,
                    waiver2: waiver2LeadGeometry,
                },
            },
            {
                item: 'amount_not_split_into_standalone_annotation',
                pass: state.checks.amountAbsorbedIntoParent,
                detail: {
                    amountLine: state.amountLine,
                    amountStandalone: state.amountStandalone,
                },
            },
            {
                item: 'amount_inline_geometry_matches_source',
                pass: amountInlineGeometry.delta <= GEOMETRY_TOLERANCES.amountInline,
                detail: amountInlineGeometry,
            },
            {
                item: 'amount_underline_gap_preserved',
                pass: state.checks.amountUnderlineGapPreserved,
                detail: {
                    amountSpans: state.amountLine?.exactSpans || [],
                },
            },
            {
                item: 'days_not_split_into_standalone_annotation',
                pass: state.checks.daysAbsorbedIntoParent,
                detail: {
                    daysLine: state.daysLine,
                    daysStandalone: state.daysStandalone,
                },
            },
            {
                item: 'days_inline_geometry_matches_source',
                pass: daysInlineGeometry.delta <= GEOMETRY_TOLERANCES.amountInline,
                detail: daysInlineGeometry,
            },
            {
                item: 'days_underline_gap_preserved',
                pass: state.checks.daysUnderlineGapPreserved,
                detail: {
                    daysSpans: state.daysLine?.exactSpans || [],
                },
            },
            {
                item: 'footer_line_geometry_matches_source',
                pass: state.checks.footerLineCountMatchesSource
                    && footerLineGeometry.maxDelta <= GEOMETRY_TOLERANCES.footerLines,
                detail: {
                    maxDelta: footerLineGeometry.maxDelta,
                    footerLineCount: state.footer?.exactLines?.length || 0,
                    footerSourceLineCount: state.footer?.sourceMeta?.sourceTextLines?.length || 0,
                    details: footerLineGeometry.details,
                    footerSourceMeta: state.footer?.sourceMeta || null,
                    footerRawText: state.footer?.rawText || '',
                },
            },
        ];

        const failed = checks.filter((check) => !check.pass);
        const result = {
            status: failed.length ? 'fail' : 'pass',
            documentId,
            pdf: PDF_PATH,
            screenshot: screenshotPath,
            checks,
        };

        console.log(JSON.stringify(result, null, 2));

        if (failed.length) {
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
