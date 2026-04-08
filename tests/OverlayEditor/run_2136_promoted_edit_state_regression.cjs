#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = 2136;
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1400 };
const TARGET_LINES = ['Bioplex', 'we love chemistry'];
const TARGET_ANNOTATION_ID = 'regression-bioplex-promoted-edit-state';
const POSITION_TOLERANCE_PX = 2.5;
const SIZE_TOLERANCE_PX = 3.5;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
}

function round(value, places = 3) {
    const factor = 10 ** places;
    return Math.round((Number(value) || 0) * factor) / factor;
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function buildCheck(item, pass, detail = {}) {
    return { item, pass: Boolean(pass), detail };
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function openEditor(page) {
    await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await waitForEditorReady(page);
}

async function injectTargetAnnotation(page) {
    await page.evaluate(({ annotationId, targetLines }) => {
        const normalizeText = (value) => String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
        const existing = typeof annotations !== 'undefined' && Array.isArray(annotations)
            ? annotations.find((annotation) => annotation?.id === annotationId)
            : null;

        if (existing?.element) {
            existing.element.remove();
        }
        if (existing && Array.isArray(annotations)) {
            const existingIndex = annotations.findIndex((annotation) => annotation?.id === annotationId);
            if (existingIndex >= 0) {
                annotations.splice(existingIndex, 1);
            }
        }
        if (typeof clearPromotedAnnotationSourceMeta === 'function') {
            clearPromotedAnnotationSourceMeta({ id: annotationId });
        }

        const wrapper = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
        const overlay = wrapper?.querySelector('.overlay');
        const canvas = wrapper?.querySelector('canvas');
        if (!wrapper || !overlay || !canvas) {
            throw new Error('Could not find page 1 overlay/canvas for injected regression annotation.');
        }

        const pageInfo = {
            scale: currentScale,
            canvasHeight: overlay.clientHeight || canvas.height,
        };

        const left = 193.029;
        const top = 181.843;
        const width = 242.307;
        const height = 76.2201;

        const annotation = {
            id: annotationId,
            promotedSourceKey: annotationId,
            type: 'text',
            text: targetLines.join('\n'),
            pageIndex: 0,
            fontFamily: 'Helvetica',
            fontSourceName: 'Helvetica',
            fontSize: 29.25 / Math.max(currentScale || 1, 0.0001),
            requestedFontSize: 29.25,
            textColor: '#595959',
            backgroundColor: 'transparent',
            fontWeight: '400',
            fontStyle: 'italic',
            underline: false,
            textAlign: 'left',
            opacity: 1,
            rotation: 0,
            promotedFromExtraction: true,
            promotedDirty: false,
            promotedReflowEnabled: false,
            keepBounds: true,
            lineHeight: 35.1 / Math.max(currentScale || 1, 0.0001),
        };

        if (typeof setTextAnnotationBounds !== 'function') {
            throw new Error('setTextAnnotationBounds is not available in page context.');
        }

        setTextAnnotationBounds(annotation, pageInfo, left, top, width, height);
        if (typeof normalizeTextAnnotation === 'function') {
            normalizeTextAnnotation(annotation);
        }

        if (typeof setPromotedAnnotationSourceMeta !== 'function') {
            throw new Error('setPromotedAnnotationSourceMeta is not available in page context.');
        }

        setPromotedAnnotationSourceMeta(annotation, {
            sourceBlockLeft: left,
            sourceBlockTop: top,
            sourceBlockWidth: width,
            sourceBlockHeight: height,
            sourcePageHeight: pageInfo.canvasHeight,
            sourceTextLines: targetLines,
            sourceLineBBoxes: [
                [left, top, left + 144.69, top + 39],
                [left, top + 46.9701, left + width, top + 46.9701 + 29.25],
            ],
            sourceSpans: [
                {
                    text: 'Bioplex',
                    rawText: 'Bioplex',
                    font: 'Helvetica',
                    embedded_font_name: '',
                    embedded_font_family: '',
                    fontSize: 39,
                    fontWeight: '700',
                    fontStyle: 'normal',
                    color: '#000000',
                    rotation: 0,
                    bbox: [left, top, left + 144.69, top + 39],
                },
                {
                    text: 'we love chemistry',
                    rawText: 'we love chemistry',
                    font: 'Helvetica',
                    embedded_font_name: '',
                    embedded_font_family: '',
                    fontSize: 29.25,
                    fontWeight: '400',
                    fontStyle: 'italic',
                    color: '#595959',
                    rotation: 0,
                    bbox: [left, top + 46.9701, left + width, top + 46.9701 + 29.25],
                },
            ],
        });

        annotations.push(annotation);
        addAnnotationElement(wrapper, annotation, pageInfo);
        const injected = annotations.find((entry) => entry?.id === annotationId);
        if (!injected?.element) {
            throw new Error('Injected regression annotation did not render.');
        }
        const renderedText = normalizeText(injected.element.innerText || injected.text || '');
        if (!targetLines.every((line) => renderedText.includes(normalizeText(line)))) {
            throw new Error(`Injected regression annotation rendered unexpected text: ${renderedText}`);
        }
    }, { annotationId: TARGET_ANNOTATION_ID, targetLines: TARGET_LINES });
    await page.waitForTimeout(400);
}

async function collectAnnotationState(page) {
    return page.evaluate(({ annotationId }) => {
        const normalizeText = (value) => String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
        const annotation = typeof annotations !== 'undefined' && Array.isArray(annotations)
            ? annotations.find((entry) => entry?.id === annotationId && entry?.element)
            : null;
        if (!annotation || !annotation.element) {
            return null;
        }

        const textEl = annotation.element.querySelector('.annotation-text');
        const exactLines = Array.from(textEl?.querySelectorAll('.annotation-exact-line') || []).map((line) => ({
            text: normalizeText(line.textContent || ''),
            rect: (() => {
                const rect = line.getBoundingClientRect();
                return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
            })(),
            firstSpanStyle: (() => {
                const firstSpan = line.querySelector('.annotation-exact-span');
                if (!firstSpan) {
                    return null;
                }
                const computed = window.getComputedStyle(firstSpan);
                return {
                    fontWeight: computed.fontWeight,
                    fontStyle: computed.fontStyle,
                    color: computed.color,
                };
            })(),
        }));
        const rect = annotation.element.getBoundingClientRect();

        return {
            text: normalizeText(annotation.text || annotation.element.innerText || ''),
            exactGeometry: textEl?.dataset?.exactPromotedGeometry === '1',
            lineTexts: exactLines.map((line) => line.text),
            lines: exactLines,
            rect: {
                left: rect.left,
                top: rect.top,
                width: rect.width,
                height: rect.height,
                centerX: rect.left + (rect.width / 2),
                centerY: rect.top + (rect.height / 2),
            },
        };
    }, { annotationId: TARGET_ANNOTATION_ID });
}

async function collectInlineEditorState(page) {
    return page.evaluate(() => {
        const normalizeText = (value) => String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
        const editor = document.querySelector('.text-box-creator.inline-annotation-editor.promoted-inline-editor');
        if (!editor) {
            return null;
        }

        const input = editor.querySelector('.tbc-input');
        const paragraphs = Array.from(input?.querySelectorAll('.para-sel') || []);
        const rect = editor.getBoundingClientRect();
        const computed = window.getComputedStyle(editor);

        return {
            rect: {
                left: rect.left,
                top: rect.top,
                width: rect.width,
                height: rect.height,
            },
            display: computed.display,
            alignItems: computed.alignItems,
            gap: computed.gap,
            hasSplitLayout: input?.dataset?.annotationSplitParagraphFully === '1',
            editLayout: input?.dataset?.editLayout || '',
            lineTexts: paragraphs.map((paragraph) => normalizeText(paragraph.textContent || '')),
            normalizedText: normalizeText(input?.innerText || input?.textContent || ''),
            lineStyles: paragraphs.map((paragraph) => {
                const firstChar = paragraph.querySelector('span');
                if (!firstChar) {
                    return null;
                }
                const charStyle = window.getComputedStyle(firstChar);
                return {
                    fontWeight: charStyle.fontWeight,
                    fontStyle: charStyle.fontStyle,
                    color: charStyle.color,
                };
            }),
        };
    });
}

async function enableEditTextMode(page) {
    const button = page.locator('#mode-edit-text');
    if (await button.count()) {
        await button.click();
        await page.waitForTimeout(300);
    }
}

async function writeArtifacts(runToken, payload, screenshotPage) {
    ensureOutputDir();
    const reportPath = path.join(OUTPUT_DIR, `doc2136_promoted_edit_state_${runToken}.json`);
    const screenshotPath = path.join(OUTPUT_DIR, `doc2136_promoted_edit_state_${runToken}.png`);
    fs.writeFileSync(reportPath, JSON.stringify(payload, null, 2));
    await screenshotPage.screenshot({ path: screenshotPath, fullPage: true });
    return { reportPath, screenshotPath };
}

async function main() {
    const runToken = buildRunToken();
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    const result = {
        status: 'fail',
        documentId: DOCUMENT_ID,
        checks: [],
    };

    try {
        await openEditor(page);
        await enableEditTextMode(page);
        await injectTargetAnnotation(page);

        const selectedState = await collectAnnotationState(page);
        if (!selectedState) {
            throw new Error('Could not find the Bioplex promoted annotation before edit.');
        }

        await page.mouse.click(selectedState.rect.centerX, selectedState.rect.centerY);
        await page.waitForFunction(() => !!document.querySelector('.text-box-creator.inline-annotation-editor.promoted-inline-editor'), {
            timeout: 90000,
        });
        await page.waitForTimeout(300);

        const editorState = await collectInlineEditorState(page);
        if (!editorState) {
            throw new Error('Could not find inline editor after opening edit mode.');
        }

        const leftDelta = Math.abs((editorState.rect?.left || 0) - (selectedState.rect?.left || 0));
        const topDelta = Math.abs((editorState.rect?.top || 0) - (selectedState.rect?.top || 0));
        const widthDelta = Math.abs((editorState.rect?.width || 0) - (selectedState.rect?.width || 0));
        const heightDelta = Math.abs((editorState.rect?.height || 0) - (selectedState.rect?.height || 0));

        result.actual = {
            selectedState: {
                text: selectedState.text,
                exactGeometry: selectedState.exactGeometry,
                lineTexts: selectedState.lineTexts,
                rect: Object.fromEntries(Object.entries(selectedState.rect).map(([key, value]) => [key, round(value)])),
                lineStyles: selectedState.lines.map((line) => line.firstSpanStyle),
            },
            editorState: {
                hasSplitLayout: editorState.hasSplitLayout,
                editLayout: editorState.editLayout,
                lineTexts: editorState.lineTexts,
                normalizedText: editorState.normalizedText,
                rect: Object.fromEntries(Object.entries(editorState.rect).map(([key, value]) => [key, round(value)])),
                display: editorState.display,
                alignItems: editorState.alignItems,
                gap: editorState.gap,
                lineStyles: editorState.lineStyles,
            },
            deltas: {
                left: round(leftDelta),
                top: round(topDelta),
                width: round(widthDelta),
                height: round(heightDelta),
            },
        };

        result.checks.push(buildCheck('selected_annotation_uses_exact_geometry', selectedState.exactGeometry === true, {
            exactGeometry: selectedState.exactGeometry,
        }));
        result.checks.push(buildCheck('selected_annotation_has_two_lines', JSON.stringify(selectedState.lineTexts) === JSON.stringify(TARGET_LINES), {
            expected: TARGET_LINES,
            actual: selectedState.lineTexts,
        }));
        result.checks.push(buildCheck('inline_editor_keeps_split_layout_on_open', editorState.hasSplitLayout === true, {
            hasSplitLayout: editorState.hasSplitLayout,
            editLayout: editorState.editLayout,
        }));
        result.checks.push(buildCheck('inline_editor_keeps_exact_line_texts', JSON.stringify(editorState.lineTexts) === JSON.stringify(TARGET_LINES), {
            expected: TARGET_LINES,
            actual: editorState.lineTexts,
        }));
        result.checks.push(buildCheck('inline_editor_does_not_concatenate_lines', !editorState.normalizedText.includes('Bioplexwe love chemistry'), {
            normalizedText: editorState.normalizedText,
        }));
        result.checks.push(buildCheck('inline_editor_matches_selected_position', leftDelta <= POSITION_TOLERANCE_PX && topDelta <= POSITION_TOLERANCE_PX, {
            leftDelta: round(leftDelta),
            topDelta: round(topDelta),
            tolerance: POSITION_TOLERANCE_PX,
        }));
        result.checks.push(buildCheck('inline_editor_matches_selected_size', widthDelta <= SIZE_TOLERANCE_PX && heightDelta <= SIZE_TOLERANCE_PX, {
            widthDelta: round(widthDelta),
            heightDelta: round(heightDelta),
            tolerance: SIZE_TOLERANCE_PX,
        }));
        result.checks.push(buildCheck('line_styles_survive_edit_open', (
            String(editorState.lineStyles?.[0]?.fontWeight || '') === '700'
            && String(editorState.lineStyles?.[0]?.fontStyle || '') === 'normal'
            && String(editorState.lineStyles?.[1]?.fontStyle || '') === 'italic'
        ), {
            lineStyles: editorState.lineStyles,
        }));

        result.status = result.checks.every((check) => check.pass) ? 'pass' : 'fail';
    } catch (error) {
        result.error = {
            message: error.message,
            stack: error.stack,
        };
    }

    const artifacts = await writeArtifacts(runToken, result, page);
    result.reportPath = artifacts.reportPath;
    result.screenshotPath = artifacts.screenshotPath;
    fs.writeFileSync(artifacts.reportPath, JSON.stringify(result, null, 2));
    await browser.close();
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    process.exit(result.status === 'pass' ? 0 : 1);
}

main().catch((error) => {
    process.stderr.write(`${error.stack || error.message}\n`);
    process.exit(1);
});
