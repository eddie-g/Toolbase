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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'f1040nr.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1700 };
const TARGET_ROWS = ['Deceased', 'Spouse'];
const MIN_SLASH_GAP_PX = 12;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function round(value, places = 3) {
    const factor = 10 ** places;
    return Math.round((Number(value) || 0) * factor) / factor;
}

function parseRgbColor(value) {
    const match = String(value || '').match(/rgba?\(([^)]+)\)/i);
    if (!match) {
        return null;
    }
    const parts = match[1].split(',').map((part) => Number.parseFloat(part.trim()));
    if (parts.length < 3 || parts.slice(0, 3).some((part) => !Number.isFinite(part))) {
        return null;
    }
    return { r: parts[0], g: parts[1], b: parts[2] };
}

function isDarkRgbColor(value) {
    const rgb = parseRgbColor(value);
    if (!rgb) {
        return false;
    }
    const luminance = (0.2126 * rgb.r) + (0.7152 * rgb.g) + (0.0722 * rgb.b);
    return luminance <= 40;
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(1200);
    await page.locator('#document-input').setInputFiles(PDF_PATH);
    await page.getByRole('button', { name: 'Upload PDF', exact: true }).click();
    await page.waitForURL(/\/documents\/\d+\/edit/, { timeout: 90000 });
    await page.waitForTimeout(3000);

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`Could not determine document id from URL: ${page.url()}`);
    }

    return Number(match[1]);
}

async function postJson(page, url) {
    return page.evaluate(async (targetUrl) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch(targetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });
        let body = null;
        try {
            body = await response.json();
        } catch (_error) {
            body = await response.text();
        }
        return {
            ok: response.ok,
            status: response.status,
            body,
        };
    }, url);
}

async function forceRefreshOverlay(page, documentId) {
    const response = await postJson(page, `/documents/${documentId}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function clearAnnotationSessionState(page, documentId) {
    await page.evaluate((id) => {
        sessionStorage.removeItem(`pdf-annotations-${id}`);
        localStorage.removeItem('pdf_session_id');
    }, documentId);
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function waitForTargetRows(page) {
    await page.waitForFunction(
        (targets) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];
            const texts = promoted.map((annotation) => normalizeText(annotation.text || annotation.element?.innerText || ''));
            return targets.every((target) => texts.some((text) => text.startsWith(target)));
        },
        TARGET_ROWS,
        { timeout: 90000 }
    );
}

async function collectRowGeometry(page) {
    return page.evaluate((targets) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
        const pageRect = pageRoot?.getBoundingClientRect() || null;
        const relativeRect = (element) => {
            const rect = element.getBoundingClientRect();
            return {
                left: pageRect ? rect.left - pageRect.left : rect.left,
                top: pageRect ? rect.top - pageRect.top : rect.top,
                width: rect.width,
                height: rect.height,
                right: pageRect ? rect.right - pageRect.left : rect.right,
                bottom: pageRect ? rect.bottom - pageRect.top : rect.bottom,
            };
        };

        const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
            ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
            : [];

        const summarizeAnnotation = (annotation) => {
            const annotationEl = annotation?.element;
            if (!(annotationEl instanceof HTMLElement)) {
                return null;
            }
            const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
            const spanElements = Array.from(annotationEl.querySelectorAll('.annotation-exact-span'))
                .filter((element) => element instanceof HTMLElement);
            const spans = spanElements.map((spanEl) => {
                const style = window.getComputedStyle(spanEl);
                const display = style.display;
                const visibility = style.visibility;
                return {
                    text: normalizeText(spanEl.innerText || spanEl.textContent || ''),
                    transform: style.transform,
                    color: style.color,
                    display,
                    visibility,
                    ...relativeRect(spanEl),
                };
            }).filter((span) => span.display !== 'none' && span.visibility !== 'hidden');

            return {
                text: normalizeText(annotation.text || annotationEl.innerText || ''),
                exactGeometry: textEl.dataset.exactPromotedGeometry === '1',
                spans,
                ...relativeRect(annotationEl),
            };
        };

        const annotationsSummary = promoted.map((annotation) => summarizeAnnotation(annotation)).filter(Boolean);
        const rows = Object.fromEntries(targets.map((target) => {
            const match = annotationsSummary.find((annotation) => annotation.text.startsWith(target));
            return [target, match || null];
        }));

        const straySlashAnnotations = annotationsSummary.filter((annotation) => {
            const text = normalizeText(annotation.text);
            return text === '/' || text === '//' || text === '/ /';
        });

        const passiveAcroformPreviews = Array.from(document.querySelectorAll('.acroform-preview-field'))
            .filter((element) => element instanceof HTMLElement)
            .map((element) => ({
                className: element.className,
                text: normalizeText(element.innerText || element.textContent || ''),
                cellTexts: Array.from(element.querySelectorAll('.acroform-preview-comb-cell'))
                    .map((cell) => normalizeText(cell.textContent || ''))
                    .filter(Boolean),
                ...relativeRect(element),
            }));

        return {
            rows,
            straySlashAnnotations,
            passiveAcroformPreviews,
            candidates: annotationsSummary.slice(0, 40),
        };
    }, TARGET_ROWS);
}

function buildCheck(item, pass, detail) {
    return { item, pass, detail };
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    const browserConsole = [];
    page.on('console', (message) => {
        browserConsole.push(`[${message.type()}] ${message.text()}`);
        if (browserConsole.length > 60) browserConsole.shift();
    });
    page.on('pageerror', (error) => {
        browserConsole.push(`[pageerror] ${error?.stack || error?.message || String(error)}`);
        if (browserConsole.length > 60) browserConsole.shift();
    });

    let documentId = null;

    try {
        documentId = await uploadPdf(page);
        await forceRefreshOverlay(page, documentId);
        await clearAnnotationSessionState(page, documentId);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await waitForTargetRows(page);
        await page.waitForTimeout(1500);

        const actual = await collectRowGeometry(page);
        const checks = [];

        for (const target of TARGET_ROWS) {
            const row = actual.rows[target];
            if (!row) {
                checks.push(buildCheck(`${target}_present`, false, { available: actual.candidates.map((entry) => entry.text) }));
                continue;
            }

            const placeholderSpans = (row.spans || [])
                .filter((span) => ['MM', 'DD', 'YYYY'].includes(span.text))
                .sort((a, b) => a.left - b.left);
            const rowPreviews = (actual.passiveAcroformPreviews || [])
                .filter((preview) => {
                    const verticalOverlap = Math.max(0, Math.min(preview.bottom, row.bottom) - Math.max(preview.top, row.top));
                    return verticalOverlap > 0;
                })
                .sort((a, b) => a.left - b.left);
            const combPreviews = rowPreviews.filter((preview) => String(preview.className || '').includes('acroform-preview-comb'));
            const combPlaceholderTexts = combPreviews.map((preview) => (preview.cellTexts || []).join(''));
            const overlappingStray = (actual.straySlashAnnotations || []).filter((annotation) => {
                const verticalOverlap = Math.max(0, Math.min(annotation.bottom, row.bottom) - Math.max(annotation.top, row.top));
                const horizontalOverlap = Math.max(0, Math.min(annotation.right, row.right) - Math.max(annotation.left, row.left));
                return verticalOverlap > 0 && horizontalOverlap > 0;
            });

            checks.push(buildCheck(`${target}_exact_geometry`, row.exactGeometry === true, { exactGeometry: row.exactGeometry }));
            checks.push(buildCheck(`${target}_promoted_placeholders_hidden`, placeholderSpans.length === 0, {
                placeholderSpanCount: placeholderSpans.length,
                spans: row.spans,
            }));
            checks.push(buildCheck(`${target}_date_comb_field_count`, combPreviews.length === 3, {
                combPreviewCount: combPreviews.length,
                rowPreviews,
            }));
            checks.push(buildCheck(`${target}_date_comb_placeholders`, JSON.stringify(combPlaceholderTexts) === JSON.stringify(['MM', 'DD', 'YYYY']), {
                combPlaceholderTexts,
                combPreviews,
            }));
            checks.push(buildCheck(`${target}_slash_annotations_present`, overlappingStray.length === 2, {
                stray: overlappingStray,
            }));
            if (overlappingStray.length >= 2) {
                const sortedSlashAnnotations = overlappingStray.slice().sort((a, b) => a.left - b.left);
                const gap = sortedSlashAnnotations[1].left - sortedSlashAnnotations[0].left;
                checks.push(buildCheck(`${target}_slash_gap`, gap >= MIN_SLASH_GAP_PX, {
                    gap: round(gap),
                    minGap: MIN_SLASH_GAP_PX,
                    slashPositions: sortedSlashAnnotations.map((annotation) => round(annotation.left)),
                }));
                checks.push(buildCheck(`${target}_slash_color_dark`, sortedSlashAnnotations.every((annotation) => {
                    const firstSpan = Array.isArray(annotation.spans) ? annotation.spans[0] : null;
                    return isDarkRgbColor(firstSpan?.color || '');
                }), {
                    slashColors: sortedSlashAnnotations.map((annotation) => annotation.spans?.[0]?.color || ''),
                }));
            } else {
                checks.push(buildCheck(`${target}_slash_gap`, false, {
                    slashCount: overlappingStray.length,
                    slashPositions: overlappingStray.map((annotation) => round(annotation.left)),
                }));
                checks.push(buildCheck(`${target}_slash_color_dark`, false, {
                    slashColors: overlappingStray.map((annotation) => annotation.spans?.[0]?.color || ''),
                }));
            }
        }

        const screenshotPath = path.join(OUTPUT_DIR, `f1040nr_date_slash_grouping_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `f1040nr_date_slash_grouping_${runToken}.json`);
        const result = {
            status: checks.every((check) => check.pass) ? 'pass' : 'fail',
            documentId,
            actual,
            browserConsole,
            checks,
            reportPath,
            screenshotPath,
        };
        fs.writeFileSync(reportPath, JSON.stringify(result, null, 2));

        console.log(JSON.stringify(result, null, 2));
        if (result.status !== 'pass') {
            process.exitCode = 1;
        }
    } catch (error) {
        if (browserConsole.length > 0) {
            error.message = `${error.message}\nRecent browser logs:\n${browserConsole.join('\n')}`;
        }
        throw error;
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error?.stack || String(error));
    process.exitCode = 1;
});
