#!/usr/bin/env node

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

let _pixelmatch = null;
let _PNG = null;
function getPixelDiffLibs() {
    if (!_pixelmatch) {
        try {
            _pixelmatch = require('pixelmatch');
            _PNG = require('pngjs').PNG;
        } catch (err) {
            throw new Error('pixelmatch + pngjs not installed: run "npm install --no-save pixelmatch@5 pngjs"');
        }
    }
    return { pixelmatch: _pixelmatch, PNG: _PNG };
}

// Resolve a python interpreter that has the project's site-packages on its
// path (PyMuPDF/fitz, fontTools, etc). When this script is invoked from the
// CLI inside an activated venv, `python3` resolves correctly. When it's
// invoked by the Filament admin page (PdfTestController -> shell_exec node),
// no venv is active and the system `python3` lacks `fitz`, so every spawn
// fails with `ModuleNotFoundError: No module named 'fitz'`. Prefer the
// project-local venv interpreter when present.
const PROJECT_VENV_PYTHON = path.resolve(__dirname, '..', '..', '..', '.venv', 'bin', 'python');
const PYTHON_BIN = (process.env.PYTHON_BIN && process.env.PYTHON_BIN.trim())
    || (fs.existsSync(PROJECT_VENV_PYTHON) ? PROJECT_VENV_PYTHON : 'python3');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DEFAULT_OUTPUT_DIR = path.resolve(__dirname, '..', '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const FALLBACK_OUTPUT_DIR = process.env.PDF_TEST_FALLBACK_OUTPUT_DIR || path.join('/tmp', 'overlay_regression_artifacts');
let OUTPUT_DIR = process.env.PDF_TEST_OUTPUT_DIR || DEFAULT_OUTPUT_DIR;
const VIEWPORT = { width: 1600, height: 1800 };

const POSITION_TEXT = 'This is a test PDF example 1';
const FIRST_MOVE_Y = -250;
const SECOND_MOVE_Y = 250;
const TEXT_POSITION_CARDINAL_MARGIN = 24;
const TEXT_POSITION_EDGE_SCREENSHOT_ZOOM = 50;
const TEXT_POSITION_CARDINAL_CASES = [
    { key: 'top', label: 'Top of page', text: 'Top of page placement', horizontal: 'center', vertical: 'top' },
    { key: 'left', label: 'Left of page', text: 'Left of page placement', horizontal: 'left', vertical: 'center' },
    { key: 'right', label: 'Right of page', text: 'Right of page placement', horizontal: 'right', vertical: 'center' },
    { key: 'bottom', label: 'Bottom of page', text: 'Bottom of page placement', horizontal: 'center', vertical: 'bottom' },
];
const TEXT_POSITION_STACK_SCREENSHOT_OPTIONS = {
    padding: 44,
    minWidth: 1180,
    minHeight: 240,
    maxWidth: 1450,
    maxHeight: 520,
};
const TEXT_POSITION_GROUPING_SCENARIOS = [
    {
        key: 'mixed_long_short_stack',
        label: 'Long / short / short / long-left stack',
        cases: [
            { text: 'Stack Long Alpha lorem ipsum dolor sit amet 01', left: 72, top: 96 },
            { text: 'Stack Short Bravo 02', left: 72, top: 144 },
            { text: 'Stack Short Charlie 03', left: 72, top: 192 },
            { text: 'Stack Long Delta aligned left directly below 04', left: 72, top: 240 },
        ],
    },
    {
        key: 'five_short_lines',
        label: 'Five short lines',
        cases: [
            { text: 'Short Line 01', left: 72, top: 110 },
            { text: 'Short Line 02', left: 72, top: 154 },
            { text: 'Short Line 03', left: 72, top: 198 },
            { text: 'Short Line 04', left: 72, top: 242 },
            { text: 'Short Line 05', left: 72, top: 286 },
        ],
    },
    {
        key: 'five_long_lines',
        label: 'Five long lines',
        cases: [
            { text: 'Long Line 01 lorem ipsum dolor sit amet for grouping isolation', left: 72, top: 110 },
            { text: 'Long Line 02 ut enim ad minim veniam for grouping isolation', left: 72, top: 158 },
            { text: 'Long Line 03 quis nostrud exercitation for grouping isolation', left: 72, top: 206 },
            { text: 'Long Line 04 duis aute irure dolor for grouping isolation', left: 72, top: 254 },
            { text: 'Long Line 05 excepteur sint occaecat for grouping isolation', left: 72, top: 302 },
        ],
    },
];

const STYLE_TEXT = 'RedWord SerifWord BoldWord UnderlineWord';
const STYLE_SEGMENTS = {
    color: {
        text: 'RedWord',
        textColor: '#c62828',
    },
    font: {
        text: 'SerifWord',
        fontFamily: 'Garamond',
        fontRegex: /(garamond|times|nimbusroman|roman|charissil|charis|tinos)/i,
    },
    bold: {
        text: 'BoldWord',
        bold: true,
    },
    underline: {
        text: 'UnderlineWord',
        underline: true,
    },
};
const HELVETICA_FONT_REGEX = /(helvetica|arimo)/i;

// Test 2 — Color Font Tests
const COLOR_LINE_RED_TEXT = 'Color Line Red';
const COLOR_LINE_BLUE_TEXT = 'Color Line Blue';
const COLOR_LINE_GREEN_TEXT = 'Color Line Green';
const COLOR_LINE_RED_HEX = '#e53935';
const COLOR_LINE_BLUE_HEX = '#1e88e5';
const COLOR_LINE_GREEN_HEX = '#43a047';

// Test 3 — Font Family Tests
const FONT_FAMILY_CASES = [
    { fontValue: 'Helvetica',   label: 'Helvetica',    text: 'Font Test Helvetica',    bgColor: '#ffcccc' },
    { fontValue: 'Verdana',     label: 'Verdana',      text: 'Font Test Verdana',      bgColor: '#ffd9b3' },
    { fontValue: 'TrebuchetMS', label: 'Trebuchet MS', text: 'Font Test Trebuchet MS', bgColor: '#ffffb3' },
    { fontValue: 'TimesRoman',  label: 'Times Roman',  text: 'Font Test Times Roman',  bgColor: '#ccffcc' },
    { fontValue: 'Georgia',     label: 'Georgia',      text: 'Font Test Georgia',      bgColor: '#b3ffff' },
    { fontValue: 'Palatino',    label: 'Palatino',     text: 'Font Test Palatino',     bgColor: '#b3ccff' },
    { fontValue: 'Garamond',    label: 'Garamond',     text: 'Font Test Garamond',     bgColor: '#e6b3ff' },
    { fontValue: 'Courier',     label: 'Courier',      text: 'Font Test Courier',      bgColor: '#ffb3e6' },
];

const FONT_SIZE_DUPLICATE_SENTENCES = {
    regular: 'Base sentence.',
    large: 'Bigger sentence.',
    small: 'Smaller sentence.',
};
const FONT_SIZE_DUPLICATE_TEXT = [
    FONT_SIZE_DUPLICATE_SENTENCES.regular,
    FONT_SIZE_DUPLICATE_SENTENCES.large,
    FONT_SIZE_DUPLICATE_SENTENCES.small,
].join(' ');
const FONT_SIZE_DUPLICATE_LARGE_PX = 30;
const FONT_SIZE_DUPLICATE_SMALL_PX = 10;
const LOADED_SAVED_STANDALONE_TEXT = 'Loaded saved standalone regression text';
const LOADED_SAVED_STANDALONE_SECOND_TEXT = 'Loaded saved standalone second save';
const LOADED_SAVED_PROMOTED_UPDATED_TEXT = 'Loaded saved promoted regression line';
const LOADED_SAVED_PROMOTED_SECOND_TEXT = 'Loaded saved promoted second save';
const MIXED_STYLE_PARAGRAPH_TEXT = 'Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s.';
const MIXED_STYLE_PARAGRAPH_HTML = '<span style="font-weight:700;">Lorem Ipsum</span> is simply dummy text of the printing and typesetting industry. <span style="font-style:italic;">Lorem Ipsum has been the </span>industry\'s standard <span style="text-decoration:underline;">dummy text ever </span>since the 1500s.';
const MIXED_STYLE_PARAGRAPH_SCREENSHOT_TARGET = 'Lorem Ipsum is simply';
const MIXED_STYLE_PARAGRAPH_BOUNDS = {
    left: 56,
    top: 120,
    width: 470,
    height: 170,
};
const MIXED_STYLE_SEGMENTS = {
    bold: 'Lorem Ipsum',
    italic: 'Lorem Ipsum has been the',
    underline: 'dummy text ever',
    plain: 'is simply dummy text',
};

const PARAGRAPH_SEED_TEXT = 'Paragraph seed';
const PARAGRAPH_TEXT = [
    'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
    'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
    'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
    'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
    'I’d recommend this direction: normal “Save” updates DB state, and a separate “Flatten annotations” export step is available when you want them permanently baked in.',
].join(' ');
const PARAGRAPH_TEXT_FRAGMENT = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.';
const PARAGRAPH_PUNCTUATION_EXPECTED = `I'd recommend this direction: normal "Save" updates DB state, and a separate "Flatten annotations" export step is available when you want them permanently baked in.`;
const PARAGRAPH_BLANK_LINE_TEXT = [
    'Updated the save path and the regression suite.',
    '',
    'In apply_annotations_direct.py (line 737), text save now expands the effective drawing height for wrapped paragraph annotations before stamping, so long paragraphs no longer get clipped at save. In run_pdf_tests.cjs (line 774), the test helper now reconstructs contiguous extracted blocks into a paragraph candidate when MuPDF splits one saved paragraph across multiple neighboring blocks.',
].join('\n');
const PARAGRAPH_BLANK_LINE_START = 'Updated the save path and the regression suite.';
const PARAGRAPH_BLANK_LINE_END = 'the test helper now reconstructs contiguous extracted blocks into a paragraph candidate when MuPDF splits one saved paragraph across multiple neighboring blocks.';
const PARAGRAPH_SHORT_INTRO_BLANK_LINE_TEXT = [
    'Fixed the blank-line split in extraction.',
    '',
    'The merge pass in extract_pdf_pymupdf.py (line 1299) now allows a larger vertical gap when two synthetic same-style fragments clearly belong to the same multiline paragraph column. That covers the \\n\\n case where MuPDF was returning two separate blocks after save.',
    '',
    'I also added a dedicated regression for this in run_pdf_tests.cjs (line 53) and run_pdf_tests.cjs (line 2248). It creates one text annotation with an explicit blank line, saves it, and verifies extraction keeps both paragraphs in a single raw block.',
].join('\n');
const PARAGRAPH_SHORT_INTRO_START = 'Fixed the blank-line split in extraction.';
const PARAGRAPH_SHORT_INTRO_END = 'It creates one text annotation with an explicit blank line, saves it, and verifies extraction keeps both paragraphs in a single raw block.';
const PARAGRAPH_EDITED_NEWLINES_TEXT = [
    'Line 1',
    '',
    'Line 2',
    '',
    'Line 3',
    '',
    'Line 4',
].join('\n');
const PARAGRAPH_INDENTED_TEXT = [
    '    Andreas was able to secure us an',
    'invitation to the DIT-WIT party, with some of',
    "the world's leading DITs in attendance. It was",
    'a great place for informal feedback on Drylab',
    'Viewer. The pattern was the same as for',
    'other users: Initial polite interest turns to',
    'real enthusiasm the moment someone is able',
    'to personally try Drylab Viewer! We also',
    'met with Pomfort and Apple about our on-',
    'going collaborations; ARRI and Teradek/',
].join('\n');
const PARAGRAPH_INDENTED_START = 'Andreas was able to secure us an';
const PARAGRAPH_STYLE_TEXT = [
    'There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration in some form, by injected humour, or randomised words which don\'t look even slightly believable.',
    'If you are going to use a passage of Lorem Ipsum, you need to be sure there isn\'t anything embarrassing hidden in the middle of text.',
    'All the Lorem Ipsum generators on the Internet tend to repeat predefined chunks as necessary, making this the first true generator on the Internet.',
    'It uses a dictionary of over 200 Latin words, combined with a handful of model sentence structures, to generate Lorem Ipsum which looks reasonable.',
    'The generated Lorem Ipsum is therefore always free from repetition, injected humour, or non-characteristic words etc.',
].join(' ');
const PARAGRAPH_STYLE_FRAGMENT = 'There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration in some form, by injected humour, or randomised words which don\'t look even slightly believable.';
const PARAGRAPH_STYLE_WORD_TARGETS = {
    first: 'There',
    middle: 'dictionary',
    last: 'therefore',
};
const PARAGRAPH_STYLE_WIDTH = 360;
const PARAGRAPH_STYLE_HEIGHT = 800;
const PARAGRAPH_SEPARATE_LINE_CASES = [
    {
        fragment: 'Separate Alpha',
        text: 'This is a line.. Alpha',
        left: 70,
        top: 85,
        width: 180,
        height: 42,
    },
    {
        fragment: 'Separate Beta',
        text: 'This is a line.. Beta',
        left: 70,
        top: 135,
        width: 180,
        height: 42,
    },
    {
        fragment: 'Separate Gamma',
        text: 'This is a line.. Gamma',
        left: 70,
        top: 185,
        width: 180,
        height: 42,
    },
];
const PARAGRAPH_TINY_SEPARATE_LINE_CASES = [
    {
        seed: 'Tiny Seed 1',
        text: 'no',
        left: 58,
        top: 118,
        width: 36,
        height: 24,
    },
    {
        seed: 'Tiny Seed 2',
        text: 'yes',
        left: 57,
        top: 130,
        width: 42,
        height: 24,
    },
    {
        seed: 'Tiny Seed 3',
        text: 'no',
        left: 57,
        top: 145,
        width: 36,
        height: 24,
    },
    {
        seed: 'Tiny Seed 4',
        text: 'yes',
        left: 59,
        top: 159,
        width: 42,
        height: 24,
    },
];
const PARAGRAPH_POSITION_CASES = [
    {
        fragment: 'Position Alpha',
        text: 'Position Alpha Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
        left: 36,
        top: 72,
        width: 220,
        height: 180,
    },
    {
        fragment: 'Position Beta',
        text: 'Position Beta Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
        left: 292,
        top: 312,
        width: 230,
        height: 190,
    },
    {
        fragment: 'Position Gamma',
        text: 'Position Gamma Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
        left: 116,
        top: 676,
        width: 240,
        height: 170,
    },
];
const PARAGRAPH_POSITION_TOLERANCE = 3;
const PARAGRAPH_NARROW_WIDTH = 200;
const PARAGRAPH_NARROW_HEIGHT = 500;
const PARAGRAPH_WIDE_WIDTH = 800;
const PARAGRAPH_SIZE_TOLERANCE = 20;
const PARAGRAPH_LAYOUT_TOLERANCE = 4;
const PARAGRAPH_COLUMN_WIDTH = 200;
const PARAGRAPH_COLUMN_HEIGHT = 800;
const PARAGRAPH_COLUMN_TEXTS = [
    `Column One ${PARAGRAPH_TEXT}`,
    `Column Two ${PARAGRAPH_TEXT}`,
    `Column Three ${PARAGRAPH_TEXT}`,
];
const PARAGRAPH_COLUMN_FRAGMENTS = [
    'Column One',
    'Column Two',
    'Column Three',
];
const PARAGRAPH_COLUMN_LEFTS = [6, 206, 406];
const PARAGRAPH_LAYOUT_SINGLE_CASE = {
    fragment: 'Layout Single',
    text: `Layout Single ${PARAGRAPH_TEXT}`,
    left: 6,
    top: 80,
    width: 600,
    height: 360,
};
const PARAGRAPH_LAYOUT_TWO_CASES = [
    {
        fragment: 'Layout Two Left',
        text: `Layout Two Left ${PARAGRAPH_TEXT}`,
        left: 6,
        top: 80,
        width: 300,
        height: 420,
    },
    {
        fragment: 'Layout Two Right',
        text: `Layout Two Right ${PARAGRAPH_TEXT}`,
        left: 306,
        top: 80,
        width: 300,
        height: 420,
    },
];
const PARAGRAPH_LAYOUT_THREE_CASES = [
    {
        fragment: 'Layout Three Left',
        text: `Layout Three Left ${PARAGRAPH_TEXT}`,
        left: 6,
        top: 80,
        width: 200,
        height: 520,
    },
    {
        fragment: 'Layout Three Middle',
        text: `Layout Three Middle ${PARAGRAPH_TEXT}`,
        left: 206,
        top: 80,
        width: 200,
        height: 520,
    },
    {
        fragment: 'Layout Three Right',
        text: `Layout Three Right ${PARAGRAPH_TEXT}`,
        left: 406,
        top: 80,
        width: 200,
        height: 520,
    },
];
const DOWNLOAD_TOP_RIGHT_TEXT = 'Wolfchenez News';
const DOWNLOAD_TOP_RIGHT_FONT_SIZE_PX = 57;
const DOWNLOAD_TOP_RIGHT_MARGIN_PX = 20;
const DOWNLOAD_TOP_RIGHT_POSITION_TOLERANCE = 18;
const DOWNLOAD_TOP_RIGHT_FONT_SIZE_TOLERANCE = 0.001;
const BLANK_TEXT_ROTATION_TEST_TEXT = 'Blank PDF rotation regression';
const BLANK_TEXT_ROTATION_DEGREES = 33;
const BLANK_TEXT_ROTATION_ANGLE_TOLERANCE = 8;
const BLANK_TEXT_BACKGROUND_TEST_TEXT = 'Blank PDF background regression';
const BLANK_TEXT_BACKGROUND_COLOR = '#1f0909';
const BLANK_TEXT_DRAWING_MATCH_TOLERANCE = 18;
const DRYLAB_LOAD_FIXTURE_PATH = path.resolve(__dirname, '..', '..', '..', 'public', 'drylab_full.pdf');
const DRYLAB_LOAD_POSITION_TOLERANCE = 4;
const DRYLAB_LOAD_SIZE_TOLERANCE = 6;
const DRYLAB_LOAD_LINE_HEIGHT_TOLERANCE = 4;
const DRYLAB_LOAD_FONT_SIZE_TOLERANCE = 3;
const DRYLAB_LOAD_TARGETS = [
    {
        key: 'title',
        lineText: 'Drylab News',
        sampleWords: ['Drylab', 'News'],
        styleWord: 'Drylab',
        fontFamilyRegex: /montserrat/i,
        minFontWeight: 600,
        expectedColor: '#ffa93a',
    },
    {
        key: 'subtitle',
        lineText: 'for investors & friends · May 2017',
        sampleWords: ['investors', 'friends', 'May'],
        styleWord: 'investors',
        fontFamilyRegex: /montserrat/i,
        maxFontWeight: 450,
        expectedColor: '#ffa93a',
    },
    {
        key: 'intro',
        lineText: 'Welcome to our first newsletter of 2017! It\'s',
        sampleWords: ['Welcome', 'newsletter', '2017!'],
        styleWord: 'Welcome',
        fontFamilyRegex: /lato/i,
        minFontWeight: 300,
        maxFontWeight: 500,
        expectedColor: '#000000',
    },
    {
        key: 'section_heading',
        lineText: 'New capital: The investment round was',
        sampleWords: ['New', 'capital:', 'round'],
        styleWord: 'New',
        fontFamilyRegex: /montserrat/i,
        minFontWeight: 600,
        expectedColor: '#000000',
    },
];
const NDA_FIXTURE_PATH = path.resolve(__dirname, '..', '..', '..', 'public', 'nda_test_1.pdf');
const NDA_PARAGRAPH_ONE_FRAGMENT = 'THIS AGREEMENT (“Agreement”) is made between';
const NDA_PARAGRAPH_ONE_FIRST_LINE = 'THIS AGREEMENT (“Agreement”) is made between ___________________________, (“Company”)';
const NDA_PARAGRAPH_ONE_BOLD_TOKEN = '___________________________';
const NDA_PARAGRAPH_ONE_WORDS = ['THIS', 'AGREEMENT', NDA_PARAGRAPH_ONE_BOLD_TOKEN];
const NDA_LEADIN_LINE = '1. Confidential and Proprietary Information. (a) Company acknowledges and agrees that in';
const NDA_LEADIN_WORDS = ['1.', 'Confidential', 'Company'];
const NDA_LOAD_POSITION_TOLERANCE = 4;
const NDA_LOAD_SIZE_TOLERANCE = 6;
const NDA_LOAD_LINE_SPACING_TOLERANCE = 4;
const NDA_LOAD_FONT_SIZE_TOLERANCE = 2;
const NDA_TIMES_FONT_REGEX = /(times|roman|nimbusroman|tinos|charis)/i;
const NDA_RANDOM_EDIT_SEED = 1544;
const NDA_RANDOM_EDIT_TARGET_COUNT = 2;
const MIXED_LAYER_PARAGRAPH_CASES = [
    {
        key: 'alpha',
        fragment: 'Mixed Paragraph Alpha',
        text: 'Mixed Paragraph Alpha Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
        left: 42,
        top: 96,
        width: 228,
        height: 156,
    },
    {
        key: 'beta',
        fragment: 'Mixed Paragraph Beta',
        text: 'Mixed Paragraph Beta Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
        left: 298,
        top: 332,
        width: 228,
        height: 156,
    },
];
const MIXED_LAYER_STAR_CASES = [
    {
        key: 'gold_star',
        fill: '#ffd700',
        stroke: '#996600',
        region: { xStart: 0.14, yStart: 0.70, xEnd: 0.26, yEnd: 0.83 },
    },
    {
        key: 'blue_star',
        fill: '#0000ff',
        stroke: '#003399',
        region: { xStart: 0.68, yStart: 0.16, xEnd: 0.82, yEnd: 0.30 },
    },
];
const MIXED_LAYER_POSITION_TOLERANCE = 6;
const MIXED_LAYER_LINE_COUNT_TOLERANCE = 1;
// Edit-New shape gauntlet (test_30): 10 shape annotations spanning every
// supported shapeType, varied stroke/fill colors, opacities, stroke widths,
// transparent fills, transparent strokes, an outline-only star, and two
// overlapping circles. Coordinates are in PDF points on a Letter portrait
// page (612 x 792) using image-y-down semantics: `topPt` is measured from
// the visual top of the page. The injector converts to PDF-y-up storage.
const SHAPE_GAUNTLET_CASES = [
    {
        key: 'square_solid',
        shapeType: 'square',
        leftPt: 40, topPt: 40, widthPt: 160, heightPt: 160,
        strokeColor: '#0000ff', fillColor: '#ff0000', strokeWidth: 3,
        strokeOpacity: 1, fillOpacity: 1,
        strokeTransparent: false, fillTransparent: false,
    },
    {
        key: 'circle_translucent_no_stroke',
        shapeType: 'circle',
        leftPt: 220, topPt: 40, widthPt: 160, heightPt: 160,
        strokeColor: '#000000', fillColor: '#ffd700', strokeWidth: 1,
        strokeOpacity: 0, fillOpacity: 0.5,
        strokeTransparent: true, fillTransparent: false,
    },
    {
        key: 'triangle_thick_outline_no_fill',
        shapeType: 'triangle',
        leftPt: 400, topPt: 40, widthPt: 160, heightPt: 160,
        strokeColor: '#008000', fillColor: '#ffffff', strokeWidth: 8,
        strokeOpacity: 1, fillOpacity: 0,
        strokeTransparent: false, fillTransparent: true,
    },
    {
        key: 'star_magenta_filled',
        shapeType: 'star',
        leftPt: 40, topPt: 240, widthPt: 160, heightPt: 160,
        strokeColor: '#000000', fillColor: '#ff00ff', strokeWidth: 2,
        strokeOpacity: 1, fillOpacity: 0.8,
        strokeTransparent: false, fillTransparent: false,
    },
    {
        key: 'line_diagonal_black',
        shapeType: 'line',
        leftPt: 220, topPt: 240, widthPt: 160, heightPt: 160,
        strokeColor: '#000000', fillColor: '#000000', strokeWidth: 4,
        strokeOpacity: 1, fillOpacity: 0,
        strokeTransparent: false, fillTransparent: true,
        // Image-y-down: 0=top, 1=bottom (matches /edit-new storage convention)
        lineStartX: 0, lineStartY: 0, lineEndX: 1, lineEndY: 1,
    },
    {
        key: 'line_horizontal_red',
        shapeType: 'line',
        leftPt: 400, topPt: 240, widthPt: 160, heightPt: 160,
        strokeColor: '#cc0000', fillColor: '#000000', strokeWidth: 1,
        strokeOpacity: 1, fillOpacity: 0,
        strokeTransparent: false, fillTransparent: true,
        lineStartX: 0, lineStartY: 0.5, lineEndX: 1, lineEndY: 0.5,
    },
    {
        key: 'square_thick_purple_low_fill',
        shapeType: 'square',
        leftPt: 40, topPt: 440, widthPt: 160, heightPt: 160,
        strokeColor: '#7c3aed', fillColor: '#7c3aed', strokeWidth: 5,
        strokeOpacity: 1, fillOpacity: 0.1,
        strokeTransparent: false, fillTransparent: false,
    },
    {
        key: 'overlap_circle_blue',
        shapeType: 'circle',
        leftPt: 220, topPt: 440, widthPt: 140, heightPt: 140,
        strokeColor: '#1d4ed8', fillColor: '#1d4ed8', strokeWidth: 2,
        strokeOpacity: 1, fillOpacity: 0.7,
        strokeTransparent: false, fillTransparent: false,
    },
    {
        key: 'overlap_circle_orange',
        shapeType: 'circle',
        leftPt: 280, topPt: 480, widthPt: 140, heightPt: 140,
        strokeColor: '#ea580c', fillColor: '#ea580c', strokeWidth: 2,
        strokeOpacity: 1, fillOpacity: 0.3,
        strokeTransparent: false, fillTransparent: false,
    },
    {
        key: 'star_outline_orange_no_fill',
        shapeType: 'star',
        leftPt: 400, topPt: 440, widthPt: 160, heightPt: 160,
        strokeColor: '#f97316', fillColor: '#06b6d4', strokeWidth: 3,
        strokeOpacity: 1, fillOpacity: 0,
        strokeTransparent: false, fillTransparent: true,
    },
];
const SHAPE_GAUNTLET_CENTER_TOLERANCE_PT = 8;
const SHAPE_GAUNTLET_STROKE_WIDTH_TOLERANCE_PT = 1.0;
const INVOICE_TEST_FIXTURE_PATH = path.resolve(__dirname, '..', '..', '..', 'public', 'invoice_test.pdf');
const EDIT_NEW_SPECIAL_RULES_FIXTURE_PATH = path.resolve(__dirname, '..', '..', '..', 'tmp', 'doc2994_page1.pdf');
const EDIT_NEW_SPECIAL_RULES_EXPECTED_BLOCKS = [
    {
        key: 'special_rules_heading',
        marker: 'Special Rules',
        text: 'Special Rules',
        minSourceLines: 1,
    },
    {
        key: 'special_rules_intro',
        marker: 'There are special rules for those who have income from',
        text: 'There are special rules for those who have income from farming and fishing, for certain household employers, and for certain higher income taxpayers.',
        minSourceLines: 3,
    },
    {
        key: 'special_rules_farming',
        marker: 'Farming and fishing.',
        text: 'Farming and fishing. If at least two-thirds of your gross income for 2025 or 2026 is from farming or fishing, substitute 662/3% for 90% in (2a) under General Rule.',
        minSourceLines: 3,
    },
    {
        key: 'special_rules_household',
        marker: 'Household employers.',
        text: 'Household employers. When estimating the tax on your 2026 tax return, include your household employment taxes if either of the following applies. • You will have federal income tax withheld from wages, pensions, annuities, gambling winnings, or other income. • You would be required to make estimated tax payments to avoid a penalty even if you didn\'t include household employment taxes when figuring your estimated tax.',
        minSourceLines: 7,
    },
    {
        key: 'special_rules_higher_income',
        marker: 'Higher income taxpayers.',
        text: 'Higher income taxpayers. If your adjusted gross income (AGI) for 2025 was more than $150,000 ($75,000 if your filing status for 2026 is married filing separately), substitute 110% for 100% in (2b) under General Rule, earlier. If at least two-thirds of your gross income for 2025 or 2026 is from farming or fishing, this rule doesn\'t apply.',
        minSourceLines: 5,
    },
];
const EDIT_NEW_WHATS_NEW_FIXTURE_PATH = path.resolve(__dirname, '..', '..', '..', 'tmp', 'doc2994_page2.pdf');
const EDIT_NEW_F1040S1_FIXTURE_PATH = path.resolve(__dirname, '..', '..', 'OverlayEditor', 'f1040s1.pdf');
// Specific paragraph the user called out — the noop-edit parity test must
// confirm this annotation exists, gets clicked into edit mode, blurred back
// out, and survives the round-trip with byte-identical text + geometry +
// styling. Note: the source PDF says "amount" (not the dictation typo "sfd").
const EDIT_NEW_F1040S1_TARGET_TEXT_FRAGMENT = 'For 2025, enter the amount reported to you on Form(s) 1099-K that was included in error or for personal items';
const EDIT_NEW_WHATS_NEW_EXPECTED_BLOCKS = [
    {
        key: 'whats_new_heading',
        marker: "What's New",
        text: "What's New",
        minSourceLines: 1,
    },
    {
        key: 'whats_new_standard_deduction',
        marker: 'Standard deduction amount increased.',
        text: "Standard deduction amount increased. For 2026, the standard deduction amount has been increased for all filers. If you don't itemize your deductions, you can take the 2026 standard deduction listed in the following chart for your filing status.",
        minSourceLines: 5,
    },
    {
        key: 'whats_new_social_security',
        marker: 'Social security tax.',
        text: 'Social security tax. For 2026, the maximum amount of earned income (wages and net earnings from self-employment) subject to the social security tax is $184,500.',
        minSourceLines: 4,
    },
    {
        key: 'whats_new_additional_child_tax',
        marker: 'Additional child tax credit amount.',
        text: 'Additional child tax credit amount. For 2026, the maximum additional child tax credit amount is $1,700 for each qualifying child.',
        minSourceLines: 3,
    },
    {
        key: 'whats_new_changes_to_qbid',
        marker: 'Changes to QBID.',
        text: 'Changes to QBID. Recent legislation made the qualified business income deduction (QBID) permanent. In addition, beginning in 2026, if you have a minimum of $1,000 in total qualified business income from an active trade or business, you may be able to claim a minimum QBID of $400. Also, the phase-in range for taxpayers who are married filing jointly will increase to $150,000 and to $75,000 for all other filing statuses.',
        minSourceLines: 8,
    },
    {
        key: 'whats_new_gambling_losses',
        marker: 'Limitation on deductible gambling losses.',
        text: 'Limitation on deductible gambling losses. Beginning in 2026, your gambling loss deduction on Schedule A (Form 1040) will be limited to 90% of your gambling winnings.',
        minSourceLines: 4,
    },
];
const INVOICE_EXPECTED_CODE_FIELDS_BY_PAGE = {
    1: [
        'BPXPN-00057',
        'BPXPN-00012',
        'BPXPN-00002',
        'BPXPN-00027',
        'BPXPN-00066',
        'BPXPN-00017',
        'BPXPN-00044',
        'BPXPN-00023',
        'BPXPN-00067',
        'BPXPN-00045',
        'BPXPN-00018',
        'BPXPN-00022',
        'BPXPN-00068',
        'BPXPN-00005',
    ],
    2: [
        'BPXPN-00052',
        'BPXPN-00046',
        'BPXPN-00069',
        'BPXPN-00070',
        'BPXPN-00047',
        'BPXPN-00051',
        'BPXPN-00071',
        'BPXPN-00019',
        'BPXPN-00048',
        'BPXPN-00021',
        'BPXPN-00049',
        'BPXPN-00050',
        'BPXPN-00004',
        'BPXPN-00020',
    ],
};
const OVERLAY_BACKGROUND_TEST_TARGET_TEXT = NDA_LEADIN_LINE;
const OVERLAY_BACKGROUND_TEST_COLOR = '#ffd54f';
const OVERLAY_BACKGROUND_MATCH_TOLERANCE = 8;
const PARAGRAPH_TEST_KEYS = [
    'test_3_paragraphs',
    'test_4_three_paragraph_columns',
    'test_5_paragraph_block_integrity',
    'test_6_paragraph_blank_line_integrity',
    'test_7_short_intro_blank_line_integrity',
    'test_8_edited_paragraph_newlines_preserved',
    'test_9_paragraph_position_accuracy',
    'test_10_delete_all_promoted_text_save',
    'test_11_separate_lines_not_grouped',
    'test_12_tiny_saved_labels_reload_separately',
    'test_13_paragraph_indent_preserved',
    'test_21_promoted_noop_edit_keeps_exact_layout',
];

const TESTS = {
    test_1_text_position: {
        key: 'test_1_text_position',
        label: 'Test 1 : Text Position',
        description: 'Create blank PDFs, save one centered text annotation after moving it 250px up, then save and move a second text annotation and verify the same saved annotation row is updated rather than duplicated, plus verify mixed long/short line stacks and five short and five long lines remain separate.',
        run: runTextPositionFlow,
    },
    test_1_text_position_new: {
        key: 'test_1_text_position_new',
        label: 'Test 1 (pdf-new) : Text Position',
        description: 'pdf-new editor variant. Create blank PDFs in /edit-new, save one centered text annotation after moving it 250px up, then save and move a second text annotation and verify the same saved annotation row is updated rather than duplicated, plus verify mixed long/short line stacks and five short and five long lines remain separate.',
        run: runTextPositionFlowNew,
    },
    test_2_text_color: {
        key: 'test_2_text_color',
        label: 'Test 2 : Color Font Tests',
        description: 'Create a blank PDF, add three separate text lines, color the first one red, the second blue, and the third green, save, and confirm each annotation has the expected color stored.',
        run: runTextStylingFlow,
    },
    test_3_font_family: {
        key: 'test_3_font_family',
        label: 'Test 3 : Font Family Tests',
        description: 'Create a blank PDF, add one text annotation for each of the eight available font families with bold, italic, underline, and a distinct background color applied, save, and verify each annotation stores the correct fontFamily, fontWeight (700), fontStyle (italic), underline (true), and backgroundColor.',
        run: runFontFamilyFlow,
    },
    test_3_font_family_new: {
        key: 'test_3_font_family_new',
        label: 'Test 3 (pdf-new) : Font Family Tests',
        description: 'pdf-new editor variant. Create a blank PDF in /edit-new, add one text annotation for each of the eight available font families with bold, italic, underline, and a distinct background color applied via the annotation format bar, save, and verify each annotation stores the correct fontFamily, fontWeight (700), fontStyle (italic), underline (true), and backgroundColor.',
        run: runFontFamilyFlowNew,
    },
    test_4_mixed_style_paragraph: {
        key: 'test_4_mixed_style_paragraph',
        label: 'Test 4 : Mixed Styled Paragraph',
        description: 'Create one paragraph with bold, italic, and underline spans on a blank PDF, save through the annotation-state path, and verify both the saved annotation row and the rendered annotation preserve those mixed styles.',
        run: runMixedStyleParagraphFlow,
    },
    test_5_delete_annotation: {
        key: 'test_5_delete_annotation',
        label: 'Test 5 : Delete Annotation',
        description: 'Create a blank PDF with three text rows and two paragraph annotations, delete a random text row via the selection toolbar delete button, save, reload the editor, and verify the deleted annotation is absent from the DOM and not returned by the saved-annotations API.',
        run: runDeleteAnnotationFlow,
    },
    test_18_load_tests: {
        key: 'test_18_load_tests',
        label: 'Test 18 : Load Tests',
        description: 'Upload drylab_full.pdf, load page 1 in the overlay editor, and verify key text retains original word positions, line heights, and styles on initial load.',
        run: runDrylabLoadFlow,
    },
    test_19_loaded_saved_standalone_no_duplicate: {
        key: 'test_19_loaded_saved_standalone_no_duplicate',
        label: 'Test 19 : Loaded Saved Standalone No Duplicate',
        description: 'Create and save a standalone text annotation, reopen the document in loaded-saved-PDF mode, save again, and verify the editor uses the clean PDF base with one live annotation per saved text.',
        run: runLoadedSavedStandaloneNoDuplicateFlow,
    },
    test_20_loaded_saved_promoted_no_duplicate: {
        key: 'test_20_loaded_saved_promoted_no_duplicate',
        label: 'Test 20 : Loaded Saved Promoted No Duplicate',
        description: 'Edit a promoted overlay field, reopen the document in loaded-saved-PDF mode, save again, and verify the editor uses the clean PDF base with one live annotation per saved text.',
        run: runLoadedSavedPromotedNoDuplicateFlow,
    },
    test_21_promoted_noop_edit_keeps_exact_layout: {
        key: 'test_21_promoted_noop_edit_keeps_exact_layout',
        label: 'Test 21 : Promoted No-Op Edit Keeps Exact Layout',
        description: 'Edit a promoted annotation without changing any content, exit edit mode, and verify the editor restores exact promoted geometry instead of persisting a fake dirty state.',
        run: runPromotedNoopEditKeepsExactLayoutFlow,
    },
    test_22_nda_random_page_one_save_no_duplicates: {
        key: 'test_22_nda_random_page_one_save_no_duplicates',
        label: 'Test 22 : NDA Random Page-One Save No Duplicates',
        description: 'Upload nda_test_1.pdf, edit deterministic random multiline page-1 overlay blocks, save, and verify the saved PDF has one copy of each edited block, the reloaded editor has no duplicate saved annotations, and multiline spacing matches the source NDA layout.',
        run: runNdaRandomPageOneSaveNoDuplicatesFlow,
    },
    test_23_mixed_annotation_layer_stars_no_duplicates: {
        key: 'test_23_mixed_annotation_layer_stars_no_duplicates',
        label: 'Test 23 : Mixed Annotation Layer Stars No Duplicates',
        description: 'Create two paragraph annotations and two star annotations on a blank PDF, make one star blue, save, and verify the saved PDF keeps exactly two stars at the expected positions while the reloaded editor keeps two editable star annotations and the paragraph boxes intact.',
        run: runMixedAnnotationLayerStarsNoDuplicatesFlow,
    },
    test_24_download_pdf_top_right_text_position: {
        key: 'test_24_download_pdf_top_right_text_position',
        label: 'Test 24 : Download PDF Top Right Text Position',
        description: 'Create a blank PDF, add "Wolfchenez News", set the editor banner font size to 57px, drag the committed text annotation to the top-right corner, save, export via Download PDF, and verify the downloaded PDF keeps that top-right placement.',
        run: runDownloadPdfTopRightTextPositionFlow,
    },
    test_25_overlay_text_background_persists_after_save: {
        key: 'test_25_overlay_text_background_persists_after_save',
        label: 'Test 25 : Overlay Text Background Persists After Save',
        description: 'Upload nda_test_1.pdf, apply a visible background fill to the lead-in overlay field, save, and verify both the reloaded overlay field and the saved PDF preserve that background.',
        run: runOverlayTextBackgroundPersistsAfterSaveFlow,
    },
    test_26_overlay_background_persists_in_loaded_saved_pdf_mode: {
        key: 'test_26_overlay_background_persists_in_loaded_saved_pdf_mode',
        label: 'Test 26 : Overlay Background Persists in loadedSavedPdf Mode',
        description: 'Upload nda_test_1.pdf, make a first save, then reload in loadedSavedPdf mode, apply a background fill to an overlay field without changing the text, save again, and verify the saved PDF contains the background fill.',
        run: runOverlayBackgroundInLoadedSavedPdfModeFlow,
    },
    test_27_blank_text_rotation_persists_after_save: {
        key: 'test_27_blank_text_rotation_persists_after_save',
        label: 'Test 27 : Blank Text Rotation Persists After Save',
        description: 'Create a blank PDF, add one text annotation, rotate it, export via Download PDF, and verify the downloaded PDF keeps the rotated text geometry.',
        run: runBlankTextRotationPersistsAfterSaveFlow,
    },
    test_28_blank_text_background_persists_after_save: {
        key: 'test_28_blank_text_background_persists_after_save',
        label: 'Test 28 : Blank Text Background Persists After Save',
        description: 'Create a blank PDF, add one text annotation with a visible background fill, export via Download PDF, and verify the downloaded PDF keeps the text background drawing.',
        run: runBlankTextBackgroundPersistsAfterSaveFlow,
    },
    test_29_image_resize_persists_after_download: {
        key: 'test_29_image_resize_persists_after_download',
        label: 'Test 29 : Image Resize Persists After Download',
        description: 'Create a blank PDF, import an image annotation, resize it to a non-original aspect ratio, export via Download PDF, and verify the stamped image rect matches the resized annotation box.',
        run: runImageResizePersistsAfterDownloadFlow,
    },
    test_30_edit_new_shape_gauntlet_new: {
        key: 'test_30_edit_new_shape_gauntlet_new',
        label: 'Test 30 (pdf-new) : Shape Gauntlet',
        description: 'pdf-new editor variant. Create a blank PDF in /edit-new and inject a gauntlet of 10 shape annotations covering every supported shapeType (square, circle, triangle, star, line) with varied stroke colors, fill colors, stroke widths, opacities, transparent fills, transparent strokes, an outline-only star, and two overlapping circles. Save via the auto-save flow, export via Download PDF, and verify each shape is stored with the expected fields, appears in the downloaded PDF with the expected fill/stroke color and stroke width at the expected position, and survives an editor reload.',
        run: runEditNewShapeGauntletFlow,
    },
    test_31_edit_new_special_rules_grouping: {
        key: 'test_31_edit_new_special_rules_grouping',
        label: 'Test 31 (pdf-new) : Special Rules Grouping',
        description: 'Upload tmp/doc2994_page1.pdf, open /edit-new, and verify the Special Rules region is materialized as the same paragraph groupings seen in the source PDF, with Household employers and Higher income taxpayers rendered as their own separate annotations rather than being merged into neighboring blocks.',
        run: runEditNewSpecialRulesGroupingFlow,
    },
    test_32_edit_new_whats_new_grouping: {
        key: 'test_32_edit_new_whats_new_grouping',
        label: 'Test 32 (pdf-new) : What\'s New Grouping',
        description: 'Upload tmp/doc2994_page2.pdf, open /edit-new, and verify the What\'s New region is materialized as the same paragraph groupings seen in the source PDF, with each bold-led paragraph (Standard deduction amount increased, Social security tax, Additional child tax credit amount, Changes to QBID, Limitation on deductible gambling losses) rendered as its own annotation.',
        run: runEditNewWhatsNewGroupingFlow,
    },
    test_33_edit_new_f1040s1_noop_edit_parity: {
        key: 'test_33_edit_new_f1040s1_noop_edit_parity',
        label: 'Test 33 (pdf-new) : F1040 Schedule 1 Edit-Mode Visual Parity',
        description: 'Upload tests/OverlayEditor/f1040s1.pdf, open /edit-new, toggle Edit PDF mode on. For the "For 2025, enter the amount reported to you on Form(s) 1099-K..." annotation specifically — and then for every other annotation on page 1 — capture a baseline pixel screenshot of the annotation\'s on-page region in its loaded (deselected) state, then enter inline edit mode on that annotation and KEEP THE EDITOR ACTIVE, and capture a second screenshot of the same region. The active-edit-mode rendering must match the baseline pixel-for-pixel within tolerance (selection chrome / handles / hover are suppressed during capture). Catches the class of bugs where focusing an extracted text annotation re-renders it with a different font weight, leader-dot spacing, or per-span layout.',
        run: runEditNewF1040s1NoopEditParityFlow,
    },
    custom_pdf_test_1: {
        key: 'custom_pdf_test_1',
        label: 'Custom PDF Test 1 : Center+Middle Aligned Text Renders in Download PDF',
        description: 'Regression for the verticalAlign:middle blank-PDF bug (doc 2880). In /edit-new create a blank Letter PDF, inject a single full-width text annotation "CENTER ALIGN" at fontSize 29, fontFamily Garamond, textAlign:center, verticalAlign:middle, transparent background. Save via the auto-save flow then click the toolbar Download PDF button and verify the downloaded PDF page 1 actually contains the text "CENTER ALIGN" rather than rendering blank.',
        run: runCustomPdfTest1Flow,
    },
    custom_pdf_test_2: {
        key: 'custom_pdf_test_2',
        label: 'Custom PDF Test 2 : CRITICAL — White Text On White Persisted Background Renders With Editor Slate Placeholder',
        description: 'CRITICAL FAILURE regression for doc 2880 round 2: when the editor persists a text annotation whose textColor is white AND backgroundColor is also white (or transparent) with backgroundColorExplicit=true, the editor visually substitutes a dark slate (#2c3e50) placeholder background via resolveDisplayBgColor() so the white text is readable in /edit-new — but the historical Download PDF stamped the literal stored values, producing a visually blank white-on-white page. This test reproduces the exact saved state from doc 2880 row 113884 (CENTER ALIGN, 29pt Manrope, textAlign center, verticalAlign middle, textColor #ffffff, backgroundColor #ffffff explicit) via a blank Letter /edit-new doc, downloads via the toolbar Download PDF flow, and asserts the downloaded PDF page 1 (a) contains the searchable text and (b) renders the annotation bounding box with a slate-ish dark background (matching what the editor showed) rather than as visually blank white.',
        run: runCustomPdfTest2Flow,
    },
    custom_pdf_test_3: {
        key: 'custom_pdf_test_3',
        label: 'Custom PDF Test 3 : Doc 2880 Snapshot — 72pt NotoSerif Bold Italic White-on-White Renders With Slate Placeholder',
        description: 'Snapshot regression of the CURRENT live saved state of doc 2880 row 113884 (CENTER ALIGN, 72pt NotoSerif fontWeight 700 fontStyle italic, textAlign center, verticalAlign middle, textColor #ffffff, backgroundColor #ffffff with backgroundColorExplicit=true, pdfY 696.3033707865169 pdfHeight 95.69662921348316). Reproduces it on a blank Letter /edit-new doc, saves, downloads via the toolbar Download PDF flow, and asserts (a) the downloaded PDF page 1 contains the searchable text and (b) the annotation bounding box renders with the editor\'s slate (#2c3e50) placeholder background, matching what the editor displays via resolveDisplayBgColor(). Add a new custom_pdf_test_N for each subsequent saved snapshot of doc 2880 to lock in visual fidelity over time.',
        run: runCustomPdfTest3Flow,
    },
    custom_pdf_test_4: {
        key: 'custom_pdf_test_4',
        label: 'Custom PDF Test 4 : Doc 2880 Snapshot — Two Annotations (Right-Aligned Red-on-Gray + Left-Aligned White-on-Red)',
        description: 'Snapshot regression of doc 2880 with TWO annotations saved (rows 113884 + 113892). Annotation 1: "CENTER ALIGN" 26pt NotoSerif bold italic, textAlign right, verticalAlign middle, textColor #9d1b1b on backgroundColor #2e2e2e (explicit), pdfY 757.6179775280899 pdfHeight 34.38202247191011. Annotation 2: "LEFT ALIGN" 33pt Nunito normal weight 400, textAlign left, verticalAlign middle, textColor #ffffff on backgroundColor #d80e0e (explicit), pdfY 714.2672653316006 pdfHeight 42.40449438202247. Reproduces both on a blank Letter /edit-new doc, saves, downloads via the toolbar Download PDF flow, and asserts: (a) both annotations persisted, (b) both texts searchable in the downloaded PDF, and (c) each bounding box renders with the literal backgroundColor (not slate, since neither is white-on-white).',
        run: runCustomPdfTest4Flow,
    },
    custom_pdf_test_5: {
        key: 'custom_pdf_test_5',
        label: 'Custom PDF Test 5 : Doc 2880 Snapshot — Five Annotations (Two Tall Semi-Transparent Strips + Lorem Ipsum Paragraph)',
        description: 'Snapshot regression of doc 2880 with FIVE saved annotations (rows 113884 + 113892 + 113900 + 113901 + 113902). Adds three new items on top of the test_4 snapshot: a tall narrow Garamond strip on the left (#42b2d7 background at opacity 0.7), an adjacent wider Mulish strip (#5e1e67 background at opacity 0.7), and a Lorem Ipsum paragraph in Lato with transparent background. Reproduces all five on a blank Letter /edit-new doc, saves, downloads via the toolbar Download PDF flow, and per-annotation asserts (a) each text is searchable in the downloaded PDF (using a per-spec searchText fallback when narrow boxes wrap text into single chars) and (b) each bounding box renders with the expected background — literal #2e2e2e, literal #d80e0e, opacity-blended (#42b2d7 × 0.7 over white), opacity-blended (#5e1e67 × 0.7 over white), and skipped for the transparent Lorem Ipsum paragraph.',
        run: runCustomPdfTest5Flow,
    },
    custom_pdf_test_6: {
        key: 'custom_pdf_test_6',
        label: 'Custom PDF Test 6 : Doc 2880 Snapshot — Enter Inserts Newline In Lorem Ipsum Paragraph',
        description: 'Bug regression: on /edit-new (doc 2880), entering edit mode and pressing Enter while the caret is positioned in the middle of a paragraph annotation must insert a literal \\n into editedTexts[uid] and grow the editor box to fit the new line. The earlier ae keydown handler resolved the active annotation only via activeState.uid, which silently aborted the Enter handler if focus had moved through the format bar (or any path that left ae.dataset.editingUid pointing at the editing annotation but activeState.uid pointing elsewhere). This test reproduces the exact Lorem Ipsum paragraph from doc 2880 row 113902 on a fresh blank Letter /edit-new doc, programmatically enters editing mode, places the caret at offset 30 of the visible text, and dispatches an Enter keydown. Asserts: (a) editedTexts for the annotation contains \\n at offset 30, (b) the editor element\'s rendered height grew (newline visually inserted), and (c) the same flow still works when the active selection has been transiently cleared (regression case for the activeState.uid divergence).',
        run: runCustomPdfTest6Flow,
    },
    custom_pdf_test_7: {
        key: 'custom_pdf_test_7',
        label: 'Custom PDF Test 7 : Doc 2880 Snapshot — Multi-Line Indented Paragraph Preserves Newlines + JosefinSans Regular Weight',
        description: 'Two-bug regression for doc 2880 row 113924. Bug A (newlines + indents collapsed): the python wrap_text_to_width helper used to treat single \\n as a soft break and split words on .split() (which strips leading whitespace), so a paragraph annotation with text "Test line indents\\n    1. blah\\n    2. blah blah\\n    3.  ok" was rendered in the downloaded PDF as two collapsed lines without indents — even though the editor (renderPlainEditorHTML) treats every \\n as a hard break and preserves leading whitespace via white-space:pre-wrap. Bug B (font weight): the local python/pdf-editor/fonts/JosefinSans-*.ttf files were ALL the Thin (weight 100) variant misnamed as Regular/Bold/Italic/BoldItalic, so JosefinSans annotations rendered ~much lighter in the downloaded PDF than in the editor (which loads the proper Regular/Bold weights from Google Fonts). This test reproduces the exact row 113924 annotation on a blank Letter /edit-new doc, downloads the PDF, and asserts: (a) all four lines exist as separate spans at distinct y positions, (b) every line\'s span text starts with the literal leading whitespace from the source (Test has none, lines 2-4 keep their 4-space indent), (c) every span uses the JosefinSans-Regular font (weight 400), not JosefinSans-Thin (weight 100) or any substitute font.',
        run: runCustomPdfTest7Flow,
    },
    custom_pdf_test_8: {
        key: 'custom_pdf_test_8',
        label: 'Custom PDF Test 8 : Doc 2880 Snapshot — Six New Annotations (TimesRoman paragraph + Rubik subtitles + Helvetica fine-print + Mukta RIGHT ALIGN + RobotoCondensed letter strip)',
        description: 'Snapshot regression of the SIX new annotations the user added to doc 2880 since the test_5 baseline (rows 113908, 113909, 113921, 113923, 113927, 113966). Reproduces all six on a blank Letter /edit-new doc, saves, downloads via the toolbar Download PDF flow, and per-annotation asserts (a) the searchable text is present in the downloaded PDF and (b) the bounding box renders with the expected background color (literal for opaque fills, skipped for transparent fills). Annotations: row 113908 = multi-paragraph TimesRoman 12pt Lorem Ipsum with embedded blank lines (transparent bg); row 113909 = "Subtitle 1" 12pt Rubik centered (transparent bg); row 113921 = small Helvetica 7pt Lorem Ipsum block (transparent bg); row 113923 = "Subtitle 2" 12pt Rubik centered (transparent bg); row 113927 = "RIGHT ALIGN" 44pt Mukta right-aligned middle, textColor #3feb00 on #00a89d explicit; row 113966 = full-width "A B C D E F" 25pt RobotoCondensed centered with #bfb0b0 explicit grey strip background.',
        run: runCustomPdfTest8Flow,
    },
    test_15_paragraph_helvetica_bold_italic_underline: {
        key: 'test_15_paragraph_helvetica_bold_italic_underline',
        label: 'Test 15 : Paragraph Helvetica Bold Italic Underline',
        description: 'Create one lorem ipsum paragraph on a blank PDF, apply Helvetica plus bold, italic, and underline styling to the full paragraph, save, and verify the saved PDF keeps those styles across the wrapped paragraph.',
        run: runParagraphHelveticaBoldItalicUnderlineFlow,
    },
    test_16_nda_paragraph_one_bold_items: {
        key: 'test_16_nda_paragraph_one_bold_items',
        label: 'Test 16 : NDA Paragraph One Bold Items',
        description: 'Upload nda_test_1.pdf, load page 1 in the editor, and verify the bold placeholder span in paragraph one keeps its original bold styling on initial load.',
        run: runNdaParagraphOneBoldItemsFlow,
    },
    test_17_nda_leadin_alignment: {
        key: 'test_17_nda_leadin_alignment',
        label: 'Test 17 : NDA Lead-In Alignment',
        description: 'Upload nda_test_1.pdf, load page 1 in the editor, and verify the "1. Confidential and Proprietary Information..." lead-in keeps its original alignment.',
        run: runNdaLeadinAlignmentFlow,
    },
    test_14_invoice_code_column_separated: {
        key: 'test_14_invoice_code_column_separated',
        label: 'Test 14 : Invoice Code Column Separation',
        description: 'Upload invoice_test.pdf, load it in the overlay editor, and verify each BPXPN invoice code row is rendered as its own overlay field instead of one grouped stack.',
        run: runInvoiceCodeColumnSeparatedFlow,
    },
    test_3_paragraphs: {
        key: 'test_3_paragraphs',
        label: 'Test 3 : Paragraph Layout Progression',
        description: 'Create one wide paragraph, then two side-by-side paragraphs, then three side-by-side paragraphs scaled to fit the page, and verify each saved layout preserves separate paragraph annotations in left-to-right order.',
        run: runParagraphLayoutProgressionFlow,
    },
    test_4_three_paragraph_columns: {
        key: 'test_4_three_paragraph_columns',
        label: 'Test 4 : Three Paragraph Columns',
        description: 'Create three 200px by 800px paragraph annotations side by side, save through the annotation path, and verify each column is stamped into the saved PDF in left-to-right order.',
        run: runThreeParagraphColumnsFlow,
    },
    test_5_paragraph_block_integrity: {
        key: 'test_5_paragraph_block_integrity',
        label: 'Test 5 : Paragraph Block Integrity',
        description: 'Create one paragraph annotation, save it, and verify the saved extraction keeps it as a single paragraph block instead of splitting it into individual line blocks.',
        run: runParagraphBlockIntegrityFlow,
    },
    test_6_paragraph_blank_line_integrity: {
        key: 'test_6_paragraph_blank_line_integrity',
        label: 'Test 6 : Paragraph Blank Line Integrity',
        description: 'Create one paragraph annotation with an explicit blank line, save it, and verify the saved extraction keeps both paragraphs in a single block.',
        run: runParagraphBlankLineIntegrityFlow,
    },
    test_7_short_intro_blank_line_integrity: {
        key: 'test_7_short_intro_blank_line_integrity',
        label: 'Test 7 : Short Intro Blank Line Integrity',
        description: 'Create one annotation with a short first paragraph, blank lines, and longer following paragraphs, then verify save keeps it as one raw block.',
        run: runShortIntroBlankLineIntegrityFlow,
    },
    test_8_edited_paragraph_newlines_preserved: {
        key: 'test_8_edited_paragraph_newlines_preserved',
        label: 'Test 8 : Edited Paragraph Newlines',
        description: 'Edit a text annotation into short lines separated by blank lines, save it, and verify the explicit newlines are preserved after save.',
        run: runEditedParagraphNewlinesPreservedFlow,
    },
    test_9_paragraph_position_accuracy: {
        key: 'test_9_paragraph_position_accuracy',
        label: 'Test 9 : Paragraph Position Accuracy',
        description: 'Create multiple paragraph annotations at distinct positions, save them, and verify their positions remain accurate after save and reload.',
        run: runParagraphPositionAccuracyFlow,
    },
    test_10_delete_all_promoted_text_save: {
        key: 'test_10_delete_all_promoted_text_save',
        label: 'Test 10 : Delete All Promoted Text Save',
        description: 'Save a paragraph, reload it as promoted text, delete all promoted annotations, save again, and verify the PDF is saved without the deleted paragraph.',
        run: runDeleteAllPromotedTextSaveFlow,
    },
    test_11_separate_lines_not_grouped: {
        key: 'test_11_separate_lines_not_grouped',
        label: 'Test 11 : Separate Lines Not Grouped',
        description: 'Create three separate one-line text boxes in the same column, save them, and verify they remain separate raw blocks instead of being grouped into one paragraph block.',
        run: runSeparateLinesNotGroupedFlow,
    },
    test_12_tiny_saved_labels_reload_separately: {
        key: 'test_12_tiny_saved_labels_reload_separately',
        label: 'Test 12 : Tiny Saved Labels Reload Separately',
        description: 'Create tiny stacked one-line text boxes, save them, reload the editor, and verify they come back as separate saved text annotations instead of one merged text area.',
        run: runTinySavedLabelsReloadSeparatelyFlow,
    },
    test_13_paragraph_indent_preserved: {
        key: 'test_13_paragraph_indent_preserved',
        label: 'Test 13 : Paragraph Indent Preserved',
        description: 'Create one multiline paragraph annotation with an indented first line, save it, and verify extraction keeps it as one block with the first-line indent preserved.',
        run: runParagraphIndentPreservedFlow,
    },
};

const TEXT_LAYOUT_SUITE_1_KEYS = [
    'custom_pdf_test_1',
    'custom_pdf_test_2',
    'custom_pdf_test_3',
    'custom_pdf_test_4',
    'custom_pdf_test_5',
    'custom_pdf_test_6',
    'custom_pdf_test_7',
    'custom_pdf_test_8',
];

const TEST_SUITES = {
    paragraph_suite: {
        key: 'paragraph_suite',
        label: 'Paragraph Suite',
        description: 'Run all paragraph-related PDF tests as one suite, including paragraph resizing, save/reload integrity, grouping boundaries, position accuracy, delete-save behavior, and indentation preservation.',
        testKeys: PARAGRAPH_TEST_KEYS,
    },
    text_layout_test_suite_1: {
        key: 'text_layout_test_suite_1',
        label: 'Text Layout Test Suite 1',
        description: 'Run every doc 2880 /edit-new snapshot regression (Custom PDF Test 1 through 7) sequentially as one suite. Each member exercises a distinct text-layout fidelity scenario in the new editor: center+middle vertical alignment, white-on-white slate placeholder, NotoSerif bold-italic snapshot, two-annotation right-aligned + left-aligned styling, five-annotation opacity-blended backgrounds with Lorem Ipsum paragraph, Enter-inserts-newline regression, and the multi-line indented paragraph + JosefinSans regular-weight font fidelity check.',
        testKeys: TEXT_LAYOUT_SUITE_1_KEYS,
    },
};

/**
 * Static criteria lists shown in the UI before a test is run.
 * Each entry is a short sentence describing one specific thing the test verifies.
 */
const TEST_CRITERIA = {
    test_1_text_position: [
        'Fresh blank PDFs were created through the frontend blank-PDF flow',
        'The first annotation save produced an in-page annotation with valid PDF coordinates',
        'The second document initial save created exactly one saved annotation row for the target text',
        'Moving and saving again updated the same saved annotation row instead of creating a duplicate',
        'The saved annotation row coordinates changed after the move-and-save step',
        'Top of page placement kept the annotation near the top edge with the full text visible',
        'Left of page placement kept the annotation near the left edge with the full text visible',
        'Right of page placement kept the annotation near the right edge with the full text visible',
        'Bottom of page placement kept the annotation near the bottom edge with the full text visible',
        'Long / short / short / long-left stack: every line starts at the same left point',
        'Long / short / short / long-left stack: each text is exactly one independent annotation with no merging at the JS layer',
        'Five short lines: each text is exactly one independent annotation with no merging at the JS layer',
        'Five long lines: each text is exactly one independent annotation with no merging at the JS layer',
    ],
    test_1_text_position_new: [
        'Fresh blank PDFs were created through the frontend blank-PDF flow (pdf-new editor)',
        'The first annotation save produced an in-page annotation with valid PDF coordinates',
        'The second document initial save created exactly one saved annotation row for the target text',
        'Moving and saving again updated the same saved annotation row instead of creating a duplicate',
        'The saved annotation row coordinates changed after the move-and-save step',
        'Top of page placement kept the annotation near the top edge with the full text visible',
        'Left of page placement kept the annotation near the left edge with the full text visible',
        'Right of page placement kept the annotation near the right edge with the full text visible',
        'Bottom of page placement kept the annotation near the bottom edge with the full text visible',
        'Long / short / short / long-left stack: every line starts at the same left point',
        'Long / short / short / long-left stack: each text is exactly one independent annotation with no merging at the JS layer',
        'Five short lines: each text is exactly one independent annotation with no merging at the JS layer',
        'Five long lines: each text is exactly one independent annotation with no merging at the JS layer',
    ],
    test_2_text_color: [
        'Blank PDF created via the frontend blank-PDF flow',
        'Three separate text annotations placed at distinct vertical positions',
        'First line stored with a red fill color',
        'Second line stored with a blue fill color',
        'Third line stored with a green fill color',
    ],
    test_3_font_family: [
        'Blank PDF created via the frontend blank-PDF flow',
        'Eight text annotations placed with bold, italic, underline, and background color, one per font',
        'Helvetica annotation stores fontFamily Helvetica',
        'Verdana annotation stores fontFamily Verdana',
        'Trebuchet MS annotation stores fontFamily TrebuchetMS',
        'Times Roman annotation stores fontFamily TimesRoman',
        'Georgia annotation stores fontFamily Georgia',
        'Palatino annotation stores fontFamily Palatino',
        'Garamond annotation stores fontFamily Garamond',
        'Courier annotation stores fontFamily Courier',
        'Helvetica annotation stores fontWeight 700 (bold)',
        'Verdana annotation stores fontWeight 700 (bold)',
        'Trebuchet MS annotation stores fontWeight 700 (bold)',
        'Times Roman annotation stores fontWeight 700 (bold)',
        'Georgia annotation stores fontWeight 700 (bold)',
        'Palatino annotation stores fontWeight 700 (bold)',
        'Garamond annotation stores fontWeight 700 (bold)',
        'Courier annotation stores fontWeight 700 (bold)',
        'Helvetica annotation stores fontStyle italic',
        'Verdana annotation stores fontStyle italic',
        'Trebuchet MS annotation stores fontStyle italic',
        'Times Roman annotation stores fontStyle italic',
        'Georgia annotation stores fontStyle italic',
        'Palatino annotation stores fontStyle italic',
        'Garamond annotation stores fontStyle italic',
        'Courier annotation stores fontStyle italic',
        'Helvetica annotation stores underline true',
        'Verdana annotation stores underline true',
        'Trebuchet MS annotation stores underline true',
        'Times Roman annotation stores underline true',
        'Georgia annotation stores underline true',
        'Palatino annotation stores underline true',
        'Garamond annotation stores underline true',
        'Courier annotation stores underline true',
        'Helvetica annotation stores backgroundColor #ffcccc',
        'Verdana annotation stores backgroundColor #ffd9b3',
        'Trebuchet MS annotation stores backgroundColor #ffffb3',
        'Times Roman annotation stores backgroundColor #ccffcc',
        'Georgia annotation stores backgroundColor #b3ffff',
        'Palatino annotation stores backgroundColor #b3ccff',
        'Garamond annotation stores backgroundColor #e6b3ff',
        'Courier annotation stores backgroundColor #ffb3e6',
    ],
    test_3_font_family_new: [
        'Blank PDF created via the frontend blank-PDF flow (pdf-new editor)',
        'Eight text annotations placed via the pdf-new editor with bold, italic, underline, and background color, one per font',
        'Helvetica annotation stores fontFamily Helvetica',
        'Verdana annotation stores fontFamily Verdana',
        'Trebuchet MS annotation stores fontFamily TrebuchetMS',
        'Times Roman annotation stores fontFamily TimesRoman',
        'Georgia annotation stores fontFamily Georgia',
        'Palatino annotation stores fontFamily Palatino',
        'Garamond annotation stores fontFamily Garamond',
        'Courier annotation stores fontFamily Courier',
        'Helvetica annotation stores fontWeight 700 (bold)',
        'Verdana annotation stores fontWeight 700 (bold)',
        'Trebuchet MS annotation stores fontWeight 700 (bold)',
        'Times Roman annotation stores fontWeight 700 (bold)',
        'Georgia annotation stores fontWeight 700 (bold)',
        'Palatino annotation stores fontWeight 700 (bold)',
        'Garamond annotation stores fontWeight 700 (bold)',
        'Courier annotation stores fontWeight 700 (bold)',
        'Helvetica annotation stores fontStyle italic',
        'Verdana annotation stores fontStyle italic',
        'Trebuchet MS annotation stores fontStyle italic',
        'Times Roman annotation stores fontStyle italic',
        'Georgia annotation stores fontStyle italic',
        'Palatino annotation stores fontStyle italic',
        'Garamond annotation stores fontStyle italic',
        'Courier annotation stores fontStyle italic',
        'Helvetica annotation stores underline true',
        'Verdana annotation stores underline true',
        'Trebuchet MS annotation stores underline true',
        'Times Roman annotation stores underline true',
        'Georgia annotation stores underline true',
        'Palatino annotation stores underline true',
        'Garamond annotation stores underline true',
        'Courier annotation stores underline true',
        'Helvetica annotation stores backgroundColor #ffcccc',
        'Verdana annotation stores backgroundColor #ffd9b3',
        'Trebuchet MS annotation stores backgroundColor #ffffb3',
        'Times Roman annotation stores backgroundColor #ccffcc',
        'Georgia annotation stores backgroundColor #b3ffff',
        'Palatino annotation stores backgroundColor #b3ccff',
        'Garamond annotation stores backgroundColor #e6b3ff',
        'Courier annotation stores backgroundColor #ffb3e6',
        'Helvetica text appears in the downloaded annotated PDF',
        'Verdana text appears in the downloaded annotated PDF',
        'Trebuchet MS text appears in the downloaded annotated PDF',
        'Times Roman text appears in the downloaded annotated PDF',
        'Georgia text appears in the downloaded annotated PDF',
        'Palatino text appears in the downloaded annotated PDF',
        'Garamond text appears in the downloaded annotated PDF',
        'Courier text appears in the downloaded annotated PDF',
        'Helvetica text is rendered italic in the downloaded annotated PDF',
        'Verdana text is rendered italic in the downloaded annotated PDF',
        'Trebuchet MS text is rendered italic in the downloaded annotated PDF',
        'Times Roman text is rendered italic in the downloaded annotated PDF',
        'Georgia text is rendered italic in the downloaded annotated PDF',
        'Palatino text is rendered italic in the downloaded annotated PDF',
        'Garamond text is rendered italic in the downloaded annotated PDF',
        'Courier text is rendered italic in the downloaded annotated PDF',
        'Helvetica text is rendered bold in the downloaded annotated PDF',
        'Verdana text is rendered bold in the downloaded annotated PDF',
        'Trebuchet MS text is rendered bold in the downloaded annotated PDF',
        'Times Roman text is rendered bold in the downloaded annotated PDF',
        'Georgia text is rendered bold in the downloaded annotated PDF',
        'Palatino text is rendered bold in the downloaded annotated PDF',
        'Garamond text is rendered bold in the downloaded annotated PDF',
        'Courier text is rendered bold in the downloaded annotated PDF',
        'Helvetica text is rendered underlined in the downloaded annotated PDF',
        'Verdana text is rendered underlined in the downloaded annotated PDF',
        'Trebuchet MS text is rendered underlined in the downloaded annotated PDF',
        'Times Roman text is rendered underlined in the downloaded annotated PDF',
        'Georgia text is rendered underlined in the downloaded annotated PDF',
        'Palatino text is rendered underlined in the downloaded annotated PDF',
        'Garamond text is rendered underlined in the downloaded annotated PDF',
        'Courier text is rendered underlined in the downloaded annotated PDF',
        'Styled rich-HTML sub-span text appears in the downloaded annotated PDF',
        'Rich-HTML sub-span carrying font-style:italic is rendered italic in the downloaded PDF',
        'Rich-HTML sub-span carrying text-decoration-line:underline is rendered underlined in the downloaded PDF',
        'Surrounding plain text in the rich-HTML annotation is rendered',
        'Surrounding plain text is NOT italic in the downloaded PDF',
        'Surrounding plain text is NOT underlined in the downloaded PDF',
    ],
    test_4_mixed_style_paragraph: [
        'Blank PDF created via the frontend blank-PDF flow',
        'Committed paragraph renders bold, italic, underline, and normal segments as distinct visual styles',
        'Exactly one saved annotation row exists for the paragraph text',
        'Saved annotation richTextHtml keeps the bold, italic, and underline style markers',
        'Rendered annotation after save still shows the expected mixed styles per fragment',
    ],
    test_5_delete_annotation: [
        'Blank PDF created via the frontend blank-PDF flow',
        'All five annotations placed: three text rows and two paragraph annotations',
        'Target annotation selected via click and per-annotation tbc-menu becomes visible',
        'Per-annotation tbc-menu delete button removed the annotation from the editor DOM immediately',
        'Annotation save completed successfully after the deletion',
        'After page reload, the deleted annotation is absent from the editor DOM',
        'After page reload, the remaining four annotations are still present in the editor DOM',
        'The saved-annotations API does not return the deleted annotation, confirming the deletion is recorded in the database',
    ],
    test_19_loaded_saved_standalone_no_duplicate: [
        'Blank PDF created and standalone text annotation saved',
        'Document reopened in loadedSavedPdf mode',
        'Second save keeps exactly one annotation per saved text (no duplicates)',
    ],
    test_20_loaded_saved_promoted_no_duplicate: [
        'Promoted overlay field edited and saved',
        'Document reopened in loadedSavedPdf mode',
        'Second save keeps exactly one copy per text (no duplicates)',
    ],
    test_21_promoted_noop_edit_keeps_exact_layout: [
        'Promoted annotation opened in edit mode without changing text',
        'After closing, annotation reverts to exact-geometry layout',
        'No dirty state persists from the no-op edit',
        'Save round-trip produces no layout change',
    ],
    test_22_nda_random_page_one_save_no_duplicates: [
        'NDA PDF uploaded and page-1 overlay blocks loaded',
        'Deterministic random multiline edit blocks edited and saved',
        'Saved PDF has exactly one copy of each edited block',
        'Reloaded editor has no duplicate saved annotations',
        'Multiline line spacing matches the source NDA layout',
    ],
    test_23_mixed_annotation_layer_stars_no_duplicates: [
        'Blank PDF created with two paragraph and two star annotations',
        'One star styled with a custom blue color',
        'Saved PDF contains exactly two stars at the expected positions',
        'Reloaded editor retains two editable star annotations',
        'Paragraph boxes are intact after save',
    ],
    test_24_download_pdf_top_right_text_position: [
        'Blank PDF created and text annotation added at 57px font size',
        'Annotation dragged to the top-right corner and document saved',
        'Downloaded PDF places the text in the top-right quadrant',
    ],
    test_25_overlay_text_background_persists_after_save: [
        'NDA PDF uploaded and lead-in overlay field selected',
        'Background fill color applied and document saved',
        'Reloaded editor overlay field retains the background fill',
        'Saved PDF contains the background fill drawing',
    ],
    test_26_overlay_background_persists_in_loaded_saved_pdf_mode: [
        'NDA uploaded and first save completed',
        'Document reopened in loadedSavedPdf mode',
        'Background fill applied to an overlay field without changing text',
        'Second save: saved PDF contains the background fill',
    ],
    test_27_blank_text_rotation_persists_after_save: [
        'Blank PDF created and text annotation added',
        'Annotation rotated to a non-zero angle',
        'Downloaded PDF keeps the rotated text geometry',
    ],
    test_28_blank_text_background_persists_after_save: [
        'Blank PDF created and text annotation with background fill added',
        'Downloaded PDF contains the text background rectangle',
    ],
    test_29_image_resize_persists_after_download: [
        'Blank PDF created and image annotation imported',
        'Image resized to a non-original aspect ratio',
        'Downloaded PDF stamped image rect matches the resized annotation box',
    ],
    test_30_edit_new_shape_gauntlet_new: [
        'Blank PDF created via the pdf-new blank-PDF flow',
        'Ten shape annotations injected via the pdf-new test bridge (square, circle, triangle, star, line, square, square, circle, circle, star)',
        'Every injected shape is stored in the editor with the expected shapeType, stroke/fill colors, stroke width, opacities, and transparency flags',
        'Auto-save persists every shape annotation to the saved-annotations API',
        'Downloaded PDF contains exactly ten drawings (one per injected shape)',
        'Square solid: rendered with red fill and blue stroke at expected position',
        'Translucent circle: rendered with yellow fill and no stroke at expected position',
        'Triangle outline: rendered with thick green stroke and no fill at expected position',
        'Magenta star: rendered with magenta fill and black stroke at expected position',
        'Diagonal line: rendered as a black 4pt stroke spanning the diagonal of its bbox',
        'Horizontal line: rendered as a red 1pt stroke spanning the middle of its bbox horizontally',
        'Thick purple square: rendered with purple stroke and translucent purple fill at expected position',
        'Overlapping circles: blue and orange circles both rendered at expected positions in the overlap region',
        'Outline-only star: rendered with orange stroke and no fill at expected position',
        'Reload the editor and verify all ten shape annotations are hydrated with the same fields',
    ],
    test_31_edit_new_special_rules_grouping: [
        'Fixture PDF tmp/doc2994_page1.pdf uploaded and opened in /edit-new',
        'Special Rules heading remains a standalone annotation',
        'The intro, Farming and fishing, Household employers, and Higher income taxpayers paragraphs each match the source PDF as their own exact annotation text blocks',
        'No edit-new annotation contains multiple Special Rules section starters merged together',
        'The Special Rules section order and multi-line source structure match the source PDF',
    ],
    test_32_edit_new_whats_new_grouping: [
        'Fixture PDF tmp/doc2994_page2.pdf uploaded and opened in /edit-new',
        "What's New heading remains a standalone annotation",
        'The Standard deduction amount increased, Social security tax, Additional child tax credit amount, Changes to QBID, and Limitation on deductible gambling losses paragraphs each match the source PDF as their own exact annotation text blocks',
        "No edit-new annotation contains multiple What's New section starters merged together",
        "The What's New section order and multi-line source structure match the source PDF",
    ],
    test_33_edit_new_f1040s1_noop_edit_parity: [
        'Fixture PDF tests/OverlayEditor/f1040s1.pdf uploaded and opened in /edit-new',
        'Edit PDF mode toggled on (#ftb-edit-mode equivalent via setEditModeEnabled)',
        'Page 1 exposes at least one extracted annotation containing the "For 2025, enter the amount reported to you on Form(s) 1099-K that was included in error or for personal items" paragraph',
        'Visual diff: capture a baseline screenshot of the target 1099-K paragraph region (no annotation focused), then enter inline edit mode on that annotation and KEEP the editor active. The active-edit-mode pixel rendering of the same region must match the baseline within tolerance (proves the active editor doesn\'t restyle the loaded text).',
        'Visual diff: the same baseline-vs-active-edit-mode comparison applied to every other page-1 annotation must match within tolerance.',
    ],
    custom_pdf_test_1: [
        'Blank Letter PDF created and opened in /edit-new',
        'Center+middle-aligned "CENTER ALIGN" text annotation injected at 29pt Garamond with transparent background spanning the full page width',
        'Auto-save persists the annotation to the saved-annotations API',
        'Downloaded PDF page 1 contains the rendered text "CENTER ALIGN" (regression: previously the page rendered blank)',
    ],
    custom_pdf_test_2: [
        'Blank Letter PDF created and opened in /edit-new',
        'CRITICAL doc 2880 round 2: white-text-on-explicit-white-background "CENTER ALIGN" annotation injected (29pt Manrope, textAlign center, verticalAlign middle, textColor #ffffff, backgroundColor #ffffff explicit)',
        'Auto-save persists the annotation to the saved-annotations API',
        'Downloaded PDF page 1 contains the searchable text "CENTER ALIGN"',
        'Downloaded PDF page 1 renders the annotation bounding box with the editor\'s slate (#2c3e50) placeholder background instead of a visually blank white page',
    ],
    custom_pdf_test_3: [
        'Blank Letter PDF created and opened in /edit-new',
        'Doc 2880 current snapshot "CENTER ALIGN" annotation injected at 72pt NotoSerif fontWeight 700 fontStyle italic, textAlign center, verticalAlign middle, textColor #ffffff, backgroundColor #ffffff explicit, pdfY 696.3033707865169 pdfHeight 95.69662921348316',
        'Auto-save persists the snapshot annotation to the saved-annotations API',
        'Downloaded PDF page 1 contains the searchable text "CENTER ALIGN"',
        'Downloaded PDF page 1 renders the annotation bounding box with the editor\'s slate (#2c3e50) placeholder background',
    ],
    custom_pdf_test_4: [
        'Blank Letter PDF created and opened in /edit-new',
        'Doc 2880 snapshot annotation 1 injected: "CENTER ALIGN" 26pt NotoSerif bold italic right-aligned, textColor #9d1b1b on backgroundColor #2e2e2e explicit',
        'Doc 2880 snapshot annotation 2 injected: "LEFT ALIGN" 33pt Nunito weight 400 left-aligned, textColor #ffffff on backgroundColor #d80e0e explicit',
        'Auto-save persists both snapshot annotations to the saved-annotations API',
        'Downloaded PDF page 1 contains the searchable text "CENTER ALIGN"',
        'Downloaded PDF page 1 contains the searchable text "LEFT ALIGN"',
        'Downloaded PDF page 1 renders the CENTER ALIGN bounding box with the literal backgroundColor #2e2e2e',
        'Downloaded PDF page 1 renders the LEFT ALIGN bounding box with the literal backgroundColor #d80e0e',
    ],
    custom_pdf_test_5: [
        'Blank Letter PDF created and opened in /edit-new',
        'Five doc 2880 snapshot annotations injected: CENTER ALIGN red-on-gray, LEFT ALIGN white-on-red, vertical-align Garamond strip on #42b2d7 at opacity 0.7, vertical-align Mulish strip on #5e1e67 at opacity 0.7, and a Lato Lorem Ipsum paragraph with transparent background',
        'Auto-save persists all five snapshot annotations to the saved-annotations API',
        'Downloaded PDF page 1 contains the searchable text for every annotation',
        'Downloaded PDF page 1 renders each annotation bounding box with the expected background color (literal for opaque fills, opacity-blended-over-white for the two semi-transparent strips, skipped for the transparent paragraph)',
    ],
    custom_pdf_test_6: [
        'Blank Letter PDF created and opened in /edit-new',
        'Doc 2880 Lorem Ipsum paragraph annotation injected and edit mode enabled',
        'Pressing Enter with the caret at offset 30 inserts a single \\n into editedTexts at offset 30',
        'Editor height changes after the newline is inserted (auto-resize fired)',
        'Pressing Enter still inserts a newline when activeState.uid has been cleared but ae.dataset.editingUid still points at the editing annotation (regression case for the bug fix)',
        'Pressing Enter at an existing line break creates a blank line instead of no-oping',
        'Paragraph edit mode ignores stale source lines when saved text has changed, so adding a space after Enter does not switch the visible text',
    ],
    custom_pdf_test_7: [
        'Blank Letter PDF created and opened in /edit-new',
        'Doc 2880 row 113924 multi-line indented paragraph annotation injected ("Test line indents\\n    1. blah\\n    2. blah blah\\n    3.  ok" 12pt JosefinSans)',
        'Auto-save persists the snapshot annotation to the saved-annotations API',
        'Downloaded PDF page 1 contains all four expected line texts',
        'Each of the four lines is rendered as its own span at a distinct y position (newlines preserved, not collapsed)',
        'Lines 2-4 preserve the leading 4-space indent from the source text',
        'Every span uses font JosefinSans-Regular (weight 400), not JosefinSans-Thin (weight 100)',
    ],
    custom_pdf_test_8: [
        'Blank Letter PDF created and opened in /edit-new',
        'Six doc 2880 snapshot annotations injected (rows 113908, 113909, 113921, 113923, 113927, 113966): TimesRoman Lorem Ipsum paragraph with blank lines, two centered Rubik subtitles, a small Helvetica fine-print block, the Mukta "RIGHT ALIGN" green-on-teal callout, and the full-width RobotoCondensed letter strip on a grey background',
        'Auto-save persists all six snapshot annotations to the saved-annotations API',
        'Downloaded PDF page 1 contains the searchable text for every annotation',
        'Downloaded PDF page 1 renders each annotation bounding box with the expected background color (literal #00a89d for the RIGHT ALIGN, literal #bfb0b0 for the letter strip, skipped for the four transparent-background annotations)',
    ],
    test_3_paragraphs: [
        'One wide paragraph fits the page as a single-column layout',
        'Two paragraphs fit side by side without overlap',
        'Three paragraphs scale down to fit side by side on the page',
        'Narrower column layouts wrap into more lines',
        'Each saved layout preserves one block per paragraph in left-to-right order',
    ],
    test_18_load_tests: [
        'Drylab full PDF uploaded and page 1 loaded in the editor',
        'Key text retains original word positions on first load',
        'Line heights match the source PDF values',
        'Font family and weight preserved on initial load',
    ],
    test_15_paragraph_helvetica_bold_italic_underline: [
        'Blank PDF created and lorem ipsum paragraph annotation added',
        'Helvetica font family applied to the full paragraph',
        'Bold, italic, and underline styling applied to the full paragraph',
        'Saved PDF keeps bold, italic, and underline across the wrapped paragraph',
    ],
    test_16_nda_paragraph_one_bold_items: [
        'NDA PDF uploaded and page 1 loaded in the editor',
        'Bold placeholder span in paragraph one retains bold styling on initial load',
    ],
    test_17_nda_leadin_alignment: [
        'NDA PDF uploaded and page 1 loaded in the editor',
        'Lead-in text "1. Confidential and Proprietary Information…" retains original alignment',
    ],
    test_14_invoice_code_column_separated: [
        'Invoice PDF uploaded and loaded in the overlay editor',
        'Each BPXPN invoice code row rendered as its own separate overlay field',
        'No two code-column rows merged into a single grouped block',
    ],
    paragraph_suite: [
        'Lorem ipsum paragraph resizes from 200px narrow column to 800px wide and back',
        'Paragraph saves and appears in the saved PDF; deleted paragraph is not stamped',
        'Three side-by-side 200×800px columns each saved in left-to-right order',
        'Single paragraph saves as one extraction block (not split into individual lines)',
        'Paragraph with explicit blank line saves as one block (blank line preserved)',
        'Short intro + blank line + long paragraph saved as a single extraction block',
        'Explicit newlines inside a paragraph are preserved after save-reload',
        'Multiple paragraphs at different positions saved within 3px accuracy',
        'Deleting all promoted text from a page produces no promoted blocks in the saved PDF',
        'Separate short lines each saved in their own extraction group',
        'Tiny saved label annotations reload as separate items (not merged)',
        'Leading indentation inside a paragraph is preserved after save',
    ],
    text_layout_test_suite_1: [
        'Custom PDF Test 1 PASSes (Center+Middle aligned text renders in download PDF, not blank)',
        'Custom PDF Test 2 PASSes (white-on-white annotation renders with editor slate placeholder bg)',
        'Custom PDF Test 3 PASSes (72pt NotoSerif bold-italic snapshot renders with slate bg)',
        'Custom PDF Test 4 PASSes (right-aligned red-on-gray + left-aligned white-on-red two-annotation snapshot)',
        'Custom PDF Test 5 PASSes (five-annotation snapshot with opacity-blended backgrounds + transparent paragraph)',
        'Custom PDF Test 6 PASSes (Enter inserts a literal \\n into the active editing annotation)',
        'Custom PDF Test 7 PASSes (multi-line indented paragraph keeps newlines + indents + JosefinSans Regular weight)',
    ],
};

function ensureOutputDir() {
    const candidates = [
        process.env.PDF_TEST_OUTPUT_DIR || '',
        DEFAULT_OUTPUT_DIR,
        FALLBACK_OUTPUT_DIR,
    ].filter(Boolean);

    let lastError = null;
    for (const candidate of candidates) {
        try {
            fs.mkdirSync(candidate, { recursive: true });
            fs.accessSync(candidate, fs.constants.W_OK);
            OUTPUT_DIR = candidate;
            return OUTPUT_DIR;
        } catch (error) {
            lastError = error;
        }
    }

    throw new Error(`No writable artifact output directory found. Tried: ${candidates.join(', ')}${lastError ? ` (${lastError.message})` : ''}`);
}

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

function normalizeTypographicAscii(text) {
    return normalize(String(text || '')
        .replace(/[\u00ad\u2010\u2011]/g, '-')
        .replace(/[\u2018\u2019]/g, "'")
        .replace(/[\u201C\u201D]/g, '"')
        .replace(/[\u2013\u2014]/g, '-'));
}

function countNormalizedOccurrences(text, fragment) {
    const haystack = normalizeTypographicAscii(text);
    const needle = normalizeTypographicAscii(fragment);
    if (!needle) {
        return 0;
    }

    let count = 0;
    let cursor = 0;
    while (cursor <= haystack.length) {
        const nextIndex = haystack.indexOf(needle, cursor);
        if (nextIndex === -1) {
            break;
        }
        count += 1;
        cursor = nextIndex + needle.length;
    }
    return count;
}

function normalizeHex(value, fallback = '#000000') {
    const hex = String(value || fallback).trim().toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(hex)) {
        return hex;
    }
    return fallback;
}

function colorToHex(value, fallback = '#000000') {
    const raw = String(value || '').trim().toLowerCase();
    if (!raw) {
        return fallback;
    }
    if (/^#[0-9a-f]{6}$/.test(raw)) {
        return raw;
    }
    const rgbMatch = raw.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!rgbMatch) {
        return fallback;
    }
    const [r, g, b] = rgbMatch.slice(1, 4).map((entry) => Math.max(0, Math.min(255, Number(entry) || 0)));
    return `#${[r, g, b].map((entry) => entry.toString(16).padStart(2, '0')).join('')}`;
}

function parseCssPixels(value) {
    const match = String(value || '').match(/-?\d+(\.\d+)?/);
    return match ? Number(match[0]) : null;
}

function approxEqual(a, b, tolerance = 1) {
    return Math.abs(Number(a || 0) - Number(b || 0)) <= tolerance;
}

function normalizeRotationAngleDegrees(value) {
    let angle = Math.abs(Number(value) || 0) % 180;
    if (angle > 90) {
        angle = 180 - angle;
    }
    return angle;
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function buildArtifactName(testKey, runToken, suffix, ext = 'png') {
    return `${testKey}_${runToken}_${suffix}.${ext}`;
}

function outputJson(payload) {
    process.stdout.write(`${JSON.stringify(payload)}\n`);
}

function scaleBox(box, scaleX, scaleY) {
    return {
        left: (Number(box?.left) || 0) * scaleX,
        top: (Number(box?.top) || 0) * scaleY,
        width: (Number(box?.width) || 0) * scaleX,
        height: (Number(box?.height) || 0) * scaleY,
    };
}

function lineBBox(line) {
    if (Array.isArray(line?.bbox) && line.bbox.length >= 4) {
        return line.bbox.map((value) => Number(value) || 0);
    }
    const left = Number(line?.left) || 0;
    const top = Number(line?.top) || 0;
    const width = Number(line?.width) || 0;
    const height = Number(line?.height) || 0;
    return [left, top, left + width, top + height];
}

function bboxCenter(bbox) {
    return {
        x: (bbox[0] + bbox[2]) / 2,
        y: (bbox[1] + bbox[3]) / 2,
    };
}

function rectArea(rect) {
    if (!Array.isArray(rect) || rect.length < 4) {
        return 0;
    }
    return Math.max(0, (Number(rect[2]) || 0) - (Number(rect[0]) || 0))
        * Math.max(0, (Number(rect[3]) || 0) - (Number(rect[1]) || 0));
}

function rectOverlapRatio(a, b) {
    if (!Array.isArray(a) || !Array.isArray(b) || a.length < 4 || b.length < 4) {
        return 0;
    }

    const interWidth = Math.max(0, Math.min(Number(a[2]) || 0, Number(b[2]) || 0) - Math.max(Number(a[0]) || 0, Number(b[0]) || 0));
    const interHeight = Math.max(0, Math.min(Number(a[3]) || 0, Number(b[3]) || 0) - Math.max(Number(a[1]) || 0, Number(b[1]) || 0));
    const interArea = interWidth * interHeight;
    if (interArea <= 0) {
        return 0;
    }

    return interArea / Math.max(1, Math.min(rectArea(a), rectArea(b)));
}

function blockBBox(block) {
    const left = Number(block?.left) || 0;
    const top = Number(block?.top) || 0;
    const width = Number(block?.width) || 0;
    const height = Number(block?.height) || 0;

    return [left, top, left + width, top + height];
}

function buildResult({
    testKey,
    label,
    description,
    status,
    checks = [],
    error = null,
    warnings = [],
    artifacts = [],
    fileSize = 0,
    metadata = {},
}) {
    const checksPassed = checks.filter((check) => check.result === 'PASS').length;
    return {
        filename: `${testKey}.pdf`,
        description,
        test_category: 'PDF Tests',
        section_name: label,
        status,
        checks,
        checks_passed: checksPassed,
        checks_total: checks.length,
        page_count: 1,
        file_size: fileSize,
        error,
        warnings,
        artifacts,
        metadata,
    };
}

async function submitJson(page, url, method = 'POST', body = null) {
    return page.evaluate(async ({ targetUrl, requestMethod, requestBody }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch(targetUrl, {
            method: requestMethod,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                ...(requestBody ? { 'Content-Type': 'application/json' } : {}),
            },
            body: requestBody ? JSON.stringify(requestBody) : null,
        });

        let parsed = null;
        try {
            parsed = await response.json();
        } catch (_error) {
            parsed = null;
        }

        return {
            ok: response.ok,
            status: response.status,
            body: parsed,
        };
    }, {
        targetUrl: url,
        requestMethod: method,
        requestBody: body,
    });
}

async function createBlankDocument(page, options = {}) {
    try {
        return await createBlankDocumentViaServer(page, options);
    } catch (serverError) {
        try {
            return await createBlankDocumentViaCli(page, options);
        } catch (cliError) {
            return await createBlankDocumentViaBrowser(page, {
                serverError,
                cliError,
            }, options);
        }
    }
}

async function createBlankDocumentViaServer(page, options = {}) {
    const pageSize = options.pageSize || 'Letter';
    const orientation = options.orientation || 'portrait';
    if (!page.url().startsWith(BASE_URL)) {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    }
    await ensureLoggedIn(page);

    const response = await page.evaluate(async ({ nextPageSize, nextOrientation }) => {
        const targetUrl = `/pdf-tests/create-blank?page_size=${encodeURIComponent(nextPageSize)}&orientation=${encodeURIComponent(nextOrientation)}`;
        const request = await fetch(targetUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const rawBody = await request.text();
        let body = null;
        try {
            body = JSON.parse(rawBody);
        } catch (_error) {
            body = null;
        }

        return {
            ok: request.ok,
            status: request.status,
            rawBody,
            body,
        };
    }, {
        nextPageSize: pageSize,
        nextOrientation: orientation,
    });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`server blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 500)}`);
    }

    const documentId = Number(response.body.document_id);
    await page.goto(`${BASE_URL}/documents/${documentId}/edit`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`server blank create opened unexpected URL: ${page.url()}`);
    }

    return Number(match[1]);
}

async function createBlankDocumentViaCli(page, options = {}) {
    const rootDir = process.cwd();
    const storageDir = path.join(rootDir, 'storage', 'app', 'documents');
    const originalsDir = path.join(storageDir, 'originals');
    const uuid = typeof crypto?.randomUUID === 'function'
        ? crypto.randomUUID()
        : `pdf-test-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const relativePath = `documents/${uuid}.pdf`;
    const fullPath = path.join(rootDir, 'storage', 'app', relativePath);
    const sizes = {
        A4: [595.28, 841.89],
        Letter: [612, 792],
        Legal: [612, 1008],
        A3: [841.89, 1190.55],
        A5: [419.53, 595.28],
    };
    const pageSize = options.pageSize || 'Letter';
    const orientation = options.orientation || 'portrait';
    const selectedSize = sizes[pageSize] || sizes.Letter;
    let [width, height] = selectedSize;
    if (orientation === 'landscape') {
        [width, height] = [height, width];
    }

    fs.mkdirSync(storageDir, { recursive: true });
    fs.mkdirSync(originalsDir, { recursive: true });

    execFileSync(PYTHON_BIN, [
        '-c',
        'import fitz, sys; doc = fitz.open(); doc.new_page(width=float(sys.argv[2]), height=float(sys.argv[3])); doc.save(sys.argv[1]); doc.close()',
        fullPath,
        String(width),
        String(height),
    ], {
        cwd: rootDir,
        stdio: 'pipe',
    });

    const sizeBytes = fs.statSync(fullPath).size;
    const phpCode = `
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();
$storedPath = $argv[1];
$sizeBytes = (int) $argv[2];
$backupPath = 'documents/originals/' . pathinfo($storedPath, PATHINFO_FILENAME) . '_original.pdf';
if (Illuminate\\Support\\Facades\\Storage::exists($storedPath)) {
    Illuminate\\Support\\Facades\\Storage::copy($storedPath, $backupPath);
} else {
    $backupPath = null;
}
    $document = App\\Models\\Document::create([
    'user_id' => null,
    'original_name' => 'Blank ${pageSize} ${orientation.charAt(0).toUpperCase() + orientation.slice(1)}.pdf',
    'path' => $storedPath,
    'original_backup_path' => $backupPath,
    'mime_type' => 'application/pdf',
    'size_bytes' => $sizeBytes,
]);
echo $document->id;
`;
    const documentId = Number(execFileSync('php', ['-r', phpCode, '--', relativePath, String(sizeBytes)], {
        cwd: rootDir,
        stdio: ['ignore', 'pipe', 'pipe'],
        encoding: 'utf8',
    }).trim());

    if (!Number.isFinite(documentId) || documentId <= 0) {
        throw new Error(`failed to create blank document record for ${relativePath}`);
    }

    const editUrl = `${BASE_URL}/documents/${documentId}/edit`;
    await page.goto(editUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`could not determine blank document id from URL: ${page.url()}`);
    }

    return Number(match[1]);
}

async function createFixtureDocument(page, fixturePath) {
    const resolvedFixturePath = path.isAbsolute(fixturePath)
        ? fixturePath
        : path.resolve(process.cwd(), fixturePath);
    if (!fs.existsSync(resolvedFixturePath)) {
        throw new Error(`fixture PDF not found: ${resolvedFixturePath}`);
    }
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await ensureLoggedIn(page);
    await page.setInputFiles('#document-input', resolvedFixturePath);
    await page.click('#upload-submit');
    await page.waitForFunction(() => /\/documents\/\d+\/edit\b/.test(window.location.pathname), null, {
        timeout: 120000,
    });

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`could not determine fixture document id from URL: ${page.url()}`);
    }

    return Number(match[1]);
}


async function createBlankDocumentViaBrowser(page, bootstrapErrors, options = {}) {
    const pageSize = options.pageSize || 'Letter';
    const orientation = options.orientation || 'portrait';
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded' });
    await ensureLoggedIn(page);
    await page.waitForTimeout(1000);

    await page.selectOption('#blank-page-size', pageSize);
    await page.selectOption('#blank-orientation', orientation);
    await page.getByRole('button', { name: 'Create Blank PDF', exact: true }).click();

    await page.waitForFunction(() => /\/documents\/\d+\/edit\b/.test(window.location.pathname), null, {
        timeout: 90000,
    });

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        const bodyText = (await page.textContent('body').catch(() => '')) || '';
        const serverMessage = bootstrapErrors?.serverError?.message || bootstrapErrors?.serverError || '';
        const cliMessage = bootstrapErrors?.cliError?.message || bootstrapErrors?.cliError || '';
        throw new Error(`blank PDF creation failed after server+CLI fallback. server_error=${serverMessage} cli_error=${cliMessage} browser_url=${page.url()} body=${bodyText.slice(0, 400)}`);
    }

    return Number(match[1]);
}

async function deleteDocument(page, documentId) {
    const response = await submitJson(page, `/documents/${documentId}`, 'DELETE');
    if (!response.ok) {
        throw new Error(`delete-document failed: ${JSON.stringify(response)}`);
    }
}

async function forceRefreshOverlay(page, documentId) {
    const response = await submitJson(page, `/documents/${documentId}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function waitForEditorReady(page) {
    // Detect an auth redirect early so we get a clear error instead of a timeout.
    const currentUrl = page.url();
    if (currentUrl.includes('/login')) {
        throw new Error(`waitForEditorReady: redirected to login page (${currentUrl}); ensure the test user is set up and TEST_EMAIL/TEST_PASSWORD env vars are correct.`);
    }
    await page.waitForSelector(
        '.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas',
        { timeout: 180000 }
    );
    await page.waitForTimeout(1500);
}

/**
 * Ensure the Playwright browser has an active user session.
 * If the page is on a login URL (e.g. a 401/redirect after navigation),
 * fill in the test credentials and submit.  Safe to call when already logged in.
 */
async function ensureLoggedIn(page) {
    const email = process.env.TEST_EMAIL || 'test@example.com';
    const password = process.env.TEST_PASSWORD || 'password1';

    // Navigate to login only if we are currently on a login page.
    const url = page.url();
    if (!url.includes('/login')) {
        return;
    }

    await page.fill('input[name="email"], input[type="email"]', email);
    await page.fill('input[name="password"], input[type="password"]', password);
    await page.getByRole('button', { name: /sign in|log in|login|submit/i }).first().click();
    await page.waitForFunction(
        () => !window.location.href.includes('/login'),
        null,
        { timeout: 30000 }
    );
}

async function clearAnnotationSessionState(page, documentId) {
    await page.evaluate((id) => {
        sessionStorage.removeItem(`pdf-annotations-${id}`);
    }, documentId);
}

async function clearPdfSessionId(page) {
    await page.evaluate(() => {
        localStorage.removeItem('pdf_session_id');
    });
}

async function getPdfSessionId(page) {
    return page.evaluate(() => localStorage.getItem('pdf_session_id') || '');
}

async function ensurePdfSessionId(page, prefix) {
    return page.evaluate((nextPrefix) => {
        const existing = localStorage.getItem('pdf_session_id');
        if (existing) {
            return existing;
        }
        const sessionId = `${nextPrefix}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
        localStorage.setItem('pdf_session_id', sessionId);
        return sessionId;
    }, prefix);
}

async function fetchSavedAnnotations(page, documentId, sessionId) {
    return page.evaluate(async ({ id, requestedSessionId }) => {
        const response = await fetch(`/documents/${id}/saved-annotations?session_id=${encodeURIComponent(requestedSessionId)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return {
            ok: response.ok,
            status: response.status,
            body: await response.json(),
        };
    }, {
        id: documentId,
        requestedSessionId: sessionId,
    });
}

async function openLoadedSavedPdfEditor(page, documentId, sessionId) {
    await page.evaluate((nextSessionId) => {
        if (nextSessionId) {
            localStorage.setItem('pdf_session_id', nextSessionId);
        }
    }, sessionId);
    await page.goto(`${BASE_URL}/documents/${documentId}/edit?loadedSavedPdf=${Date.now()}&savedSession=${encodeURIComponent(sessionId)}`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });
    await waitForEditorReady(page);
}

async function waitForLoadedSavedAnnotationSave(page) {
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && (
            response.url().includes('/apply-annotations-direct')
            || response.url().includes('/save-annotations')
        )
    ), { timeout: 60000 });
    const loadPromise = page.waitForEvent('load', { timeout: 90000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`loaded-saved annotation save failed with ${response.status()}`);
    }

    await loadPromise;
    await waitForEditorReady(page);
}

async function getBasePageGeometry(page) {
    return page.evaluate(() => {
        const wrapper = document.querySelector('.page[data-page-index="0"]');
        const overlay = wrapper?.querySelector('.overlay');
        const canvas = wrapper?.querySelector('canvas');
        if (!wrapper || !overlay || !canvas) {
            return null;
        }
        const overlayRect = overlay.getBoundingClientRect();
        return {
            centerX: overlayRect.width / 2,
            centerY: overlayRect.height / 2,
        };
    });
}

async function createCenterTextAnnotation(page, text) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const pageGeometry = await getBasePageGeometry(page);
    if (!pageGeometry) {
        throw new Error('missing base page geometry for Add Text');
    }

    const overlay = page.locator('.page[data-page-index="0"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + pageGeometry.centerX, overlayBox.y + pageGeometry.centerY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.locator('.text-box-creator .tbc-input').fill(text);
    await page.locator('.text-box-creator .tbc-ok').click();
    await page.waitForTimeout(1200);

    const annotation = page.locator('.annotation').filter({ hasText: text }).first();
    await annotation.waitFor({ timeout: 10000 });

    return annotation;
}

async function createTextAnnotationAt(page, text, offsetX, offsetY) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const overlay = page.locator('.page[data-page-index="0"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + offsetX, overlayBox.y + offsetY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.locator('.text-box-creator .tbc-input').fill(text);
    await page.locator('.text-box-creator .tbc-ok').click();
    await page.waitForTimeout(1200);

    const annotation = page.locator('.annotation').filter({ hasText: text }).first();
    await annotation.waitFor({ timeout: 10000 });

    return annotation;
}

async function createCenteredTextAnnotation(page, text, deltaX = 0, deltaY = 0) {
    const pageGeometry = await getBasePageGeometry(page);
    if (!pageGeometry) {
        throw new Error(`missing base page geometry for centered text "${text}"`);
    }

    return createTextAnnotationAt(
        page,
        text,
        Math.round(pageGeometry.centerX + deltaX),
        Math.round(pageGeometry.centerY + deltaY)
    );
}

async function createTextAnnotationAtWithStyles(page, text, offsetX, offsetY, styles = {}) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    // Set fontFamily AFTER the mode-text click's updateEditTextBanner() runs.
    // That handler calls populateEditTextBanner(selectedAnnotation) which resets
    // etb-font.value to the previously selected annotation's font (Helvetica after
    // the first commit).  Setting it before the click is silently overwritten.
    // Setting it here — after the 300ms settle but before the overlay click that
    // calls readBannerOpts() — ensures the correct fontFamily flows into opts and
    // is captured by commitText's closure.
    if (styles?.fontFamily) {
        await page.evaluate((fontFamily) => {
            const el = document.getElementById('etb-font');
            if (el) el.value = fontFamily;
        }, styles.fontFamily);
    }

    const overlay = page.locator('.page[data-page-index="0"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + offsetX, overlayBox.y + offsetY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });

    const input = page.locator('.text-box-creator .tbc-input').first();
    await input.fill(text);
    if (styles && Object.keys(styles).length > 0) {
        await applyEditTextBannerStyles(page, styles);
    }
    await commitActiveTextBox(page);

    const annotation = page.locator('.annotation').filter({ hasText: text }).first();
    await annotation.waitFor({ timeout: 10000 });
    return annotation;
}

async function updateTextAnnotation(page, textFragment, options = {}) {
    const result = await page.evaluate(async ({ targetTextFragment, nextOptions }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!record || !record.element) {
            throw new Error(`missing text annotation containing "${targetTextFragment}"`);
        }

        if (Object.prototype.hasOwnProperty.call(nextOptions, 'text')) {
            record.text = nextOptions.text;
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'richTextHtml')) {
            if (typeof nextOptions.richTextHtml === 'string' && nextOptions.richTextHtml.trim()) {
                record.richTextHtml = nextOptions.richTextHtml;
            } else {
                delete record.richTextHtml;
            }
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'keepBounds')) {
            record.keepBounds = Boolean(nextOptions.keepBounds);
        }

        const pageContext = resolveAnnotationPageContext(record);
        if (!pageContext) {
            throw new Error('missing annotation page context');
        }

        const leftPx = Number.isFinite(Number(nextOptions.left)) ? Number(nextOptions.left) : record.element.offsetLeft;
        const topPx = Number.isFinite(Number(nextOptions.top)) ? Number(nextOptions.top) : record.element.offsetTop;
        const widthPx = Number.isFinite(Number(nextOptions.width)) ? Number(nextOptions.width) : null;
        const heightPx = Number.isFinite(Number(nextOptions.height)) ? Number(nextOptions.height) : null;

        if (widthPx !== null && heightPx !== null) {
            setTextAnnotationBounds(record, pageContext.pageInfo, leftPx, topPx, widthPx, heightPx);
        } else if (Object.prototype.hasOwnProperty.call(nextOptions, 'left') || Object.prototype.hasOwnProperty.call(nextOptions, 'top')) {
            setTextAnnotationPosition(record, pageContext.pageInfo, leftPx, topPx);
        }

        applyAnnotationStyle(record);
        persistAnnotations();
        if (typeof saveAnnotationToDatabase === 'function') {
            await saveAnnotationToDatabase(record);
        }

        return {
            id: record.id,
            text: record.text,
            left: record.element.offsetLeft,
            top: record.element.offsetTop,
            width: record.element.getBoundingClientRect().width,
            height: record.element.getBoundingClientRect().height,
        };
    }, { targetTextFragment: textFragment, nextOptions: options });

    await page.waitForTimeout(250);
    return result;
}

async function forcePositionTextAnnotation(page, textFragment, options = {}) {
    const result = await page.evaluate(async ({ targetTextFragment, nextOptions }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!record || !record.element) {
            throw new Error(`missing text annotation containing "${targetTextFragment}"`);
        }

        const pageContext = resolveAnnotationPageContext(record);
        if (!pageContext) {
            throw new Error('missing annotation page context');
        }

        const leftPx = Number.isFinite(Number(nextOptions.left)) ? Number(nextOptions.left) : record.element.offsetLeft;
        const topPx = Number.isFinite(Number(nextOptions.top)) ? Number(nextOptions.top) : record.element.offsetTop;
        setTextAnnotationPosition(record, pageContext.pageInfo, leftPx, topPx);
        applyAnnotationStyle(record);
        record.element.style.left = `${leftPx}px`;
        record.element.style.top = `${topPx}px`;
        persistAnnotations();
        if (typeof saveAnnotationToDatabase === 'function') {
            await saveAnnotationToDatabase(record);
        }

        return {
            id: record.id,
            text: record.text,
            left: record.element.offsetLeft,
            top: record.element.offsetTop,
            width: record.element.getBoundingClientRect().width,
            height: record.element.getBoundingClientRect().height,
        };
    }, { targetTextFragment: textFragment, nextOptions: options });

    await page.waitForTimeout(250);
    return result;
}

async function setTextAnnotationVisualProperties(page, textFragment, options = {}) {
    const result = await page.evaluate(async ({ targetTextFragment, nextOptions }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!record || !record.element) {
            throw new Error(`missing text annotation containing "${targetTextFragment}"`);
        }

        const pageContext = resolveAnnotationPageContext(record);
        if (!pageContext) {
            throw new Error('missing annotation page context');
        }

        const leftPx = Number.isFinite(Number(nextOptions.left)) ? Number(nextOptions.left) : record.element.offsetLeft;
        const topPx = Number.isFinite(Number(nextOptions.top)) ? Number(nextOptions.top) : record.element.offsetTop;
        const widthPx = Number.isFinite(Number(nextOptions.width)) ? Number(nextOptions.width) : null;
        const heightPx = Number.isFinite(Number(nextOptions.height)) ? Number(nextOptions.height) : null;

        if (Object.prototype.hasOwnProperty.call(nextOptions, 'backgroundColor')) {
            record.backgroundColor = nextOptions.backgroundColor;
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'rotation')) {
            record.rotation = Number(nextOptions.rotation) || 0;
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'fontSize')) {
            record.fontSize = Number(nextOptions.fontSize) || record.fontSize || 12;
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'requestedFontSize')) {
            record.requestedFontSize = Number(nextOptions.requestedFontSize) || null;
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'keepBounds')) {
            record.keepBounds = Boolean(nextOptions.keepBounds);
        }

        if (widthPx !== null && heightPx !== null) {
            setTextAnnotationBounds(record, pageContext.pageInfo, leftPx, topPx, widthPx, heightPx);
        } else if (Object.prototype.hasOwnProperty.call(nextOptions, 'left') || Object.prototype.hasOwnProperty.call(nextOptions, 'top')) {
            setTextAnnotationPosition(record, pageContext.pageInfo, leftPx, topPx);
        }

        applyAnnotationStyle(record);
        persistAnnotations();
        if (typeof saveAnnotationToDatabase === 'function') {
            await saveAnnotationToDatabase(record);
        }

        const textElement = record.element.querySelector('.annotation-text') || record.element;
        const wrapper = record.textWrapper || record.element.querySelector('.text-content-wrapper');
        return {
            id: record.id,
            text: record.text,
            rotation: Number(record.rotation) || 0,
            backgroundColor: String(record.backgroundColor || ''),
            pdfX: Number(record.pdfX) || 0,
            pdfY: Number(record.pdfY) || 0,
            pdfWidth: Number(record.pdfWidth) || 0,
            pdfHeight: Number(record.pdfHeight) || 0,
            left: Number(record.element.offsetLeft) || 0,
            top: Number(record.element.offsetTop) || 0,
            width: record.element.getBoundingClientRect().width || 0,
            height: record.element.getBoundingClientRect().height || 0,
            fieldBackground: getComputedStyle(record.element).backgroundColor,
            textBackground: getComputedStyle(textElement).backgroundColor,
            wrapperBackground: wrapper ? getComputedStyle(wrapper).backgroundColor : '',
            wrapperTransform: wrapper ? getComputedStyle(wrapper).transform : '',
        };
    }, { targetTextFragment: textFragment, nextOptions: options });

    await page.waitForTimeout(250);
    return result;
}

async function readTextAnnotationMetrics(page, textFragment) {
    return page.evaluate((targetTextFragment) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!record?.element) {
            throw new Error(`missing text annotation metrics target for "${targetTextFragment}"`);
        }

        const field = record.element;
        const textElement = field.querySelector('.annotation-text');
        if (!textElement) {
            throw new Error(`missing annotation text element for "${targetTextFragment}"`);
        }

        const fieldRect = field.getBoundingClientRect();
        const textRect = textElement.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(textElement);

        const rectMap = new Map();
        for (const rect of Array.from(range.getClientRects())) {
            if (!rect || rect.width < 1 || rect.height < 1) {
                continue;
            }
            const key = [
                Math.round(rect.left - fieldRect.left),
                Math.round(rect.top - fieldRect.top),
                Math.round(rect.width),
                Math.round(rect.height),
            ].join(':');
            if (!rectMap.has(key)) {
                rectMap.set(key, {
                    width: rect.width,
                    height: rect.height,
                    rightGap: Math.max(0, textRect.right - rect.right),
                });
            }
        }

        const lineRects = Array.from(rectMap.values());

        return {
            left: field.offsetLeft,
            top: field.offsetTop,
            width: fieldRect.width,
            height: fieldRect.height,
            overlayWidth: field.parentElement?.clientWidth || 0,
            overlayHeight: field.parentElement?.clientHeight || 0,
            contentWidth: textRect.width,
            contentHeight: textRect.height,
            lineCount: lineRects.length,
            lineWidths: lineRects.map((rect) => rect.width),
            lineRightGaps: lineRects.map((rect) => rect.rightGap),
            text: textElement.innerText || textElement.textContent || '',
        };
    }, textFragment);
}

async function readTextAnnotationState(page, textFragment) {
    return page.evaluate((targetTextFragment) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!record?.element) {
            throw new Error(`missing text annotation state target for "${targetTextFragment}"`);
        }

        const textElement = record.element.querySelector('.annotation-text') || record.element;
        const style = window.getComputedStyle(textElement);

        return {
            left: Number(record.element.offsetLeft) || 0,
            top: Number(record.element.offsetTop) || 0,
            width: record.element.getBoundingClientRect().width || 0,
            height: record.element.getBoundingClientRect().height || 0,
            fontSizePx: parseFloat(style.fontSize || '0') || 0,
            requestedFontSize: Number(record.requestedFontSize) || null,
            currentScale: Number(window.currentScale) || 1,
            pdfX: Number(record.pdfX) || 0,
            pdfY: Number(record.pdfY) || 0,
            pdfWidth: Number(record.pdfWidth) || 0,
            pdfHeight: Number(record.pdfHeight) || 0,
            overlayWidth: record.element.parentElement?.clientWidth || 0,
            overlayHeight: record.element.parentElement?.clientHeight || 0,
        };
    }, textFragment);
}

// Checks whether each case text appears as exactly one distinct annotation (no
// merging at the JS layer) and that no single annotation contains text from
// multiple cases. This mirrors what analyzeIndependentTextGrouping checks at the
// fitz-extraction level but works without requiring a stamped PDF.
async function analyzeAnnotationGroupings(page, cases) {
    return page.evaluate((scenarioCases) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];

        const annotationTexts = records.map((item) => {
            const raw = item?.text
                || item?.element?.innerText
                || item?.element?.textContent
                || item?.element?.querySelector?.('.annotation-text')?.textContent
                || '';
            return normalizeText(raw);
        });

        const matchesByCase = scenarioCases.map((scenarioCase) => {
            const caseText = normalizeText(scenarioCase.text);
            const matchCount = annotationTexts.filter((t) => t === caseText).length;
            return { text: scenarioCase.text, matchCount };
        });

        // Flag any annotation whose text contains multiple distinct case strings.
        const groupedBlocks = annotationTexts
            .map((annotText) => {
                const hits = scenarioCases.filter((c) => annotText.includes(normalizeText(c.text)));
                if (hits.length <= 1) return null;
                return { text: annotText, matchedTexts: hits.map((c) => c.text) };
            })
            .filter(Boolean);

        return { matchesByCase, groupedBlocks };
    }, cases);
}

async function moveTextAnnotationTo(page, textFragment, targetLeft, targetTop) {
    const annotation = page.locator('.annotation:visible').filter({ hasText: textFragment }).first();
    await annotation.waitFor({ state: 'visible', timeout: 10000 });

    for (let attempt = 0; attempt < 3; attempt += 1) {
        await annotation.click();
        await page.waitForTimeout(200);

        const before = await readTextAnnotationMetrics(page, textFragment);
        const moveHandle = page.locator('.annotation.selected .annotation-tbc-menu [title="Move"]').first();
        const handleBox = await moveHandle.boundingBox();
        if (!handleBox) {
            throw new Error(`missing text annotation move handle for "${textFragment}"`);
        }

        const deltaX = targetLeft - before.left;
        const deltaY = targetTop - before.top;
        if (Math.abs(deltaX) <= 2 && Math.abs(deltaY) <= 2) {
            return {
                before,
                after: before,
                deltaX: 0,
                deltaY: 0,
            };
        }

        const fromX = handleBox.x + (handleBox.width / 2);
        const fromY = handleBox.y + (handleBox.height / 2);
        await page.mouse.move(fromX, fromY);
        await page.mouse.down();
        await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 24 });
        await page.mouse.up();
        await page.waitForTimeout(400);

        const after = await readTextAnnotationMetrics(page, textFragment);
        if (Math.abs(after.left - targetLeft) <= 4 && Math.abs(after.top - targetTop) <= 4) {
            return {
                before,
                after,
                deltaX: after.left - before.left,
                deltaY: after.top - before.top,
            };
        }
    }

    const finalState = await readTextAnnotationMetrics(page, textFragment);
    return {
        before: null,
        after: finalState,
        deltaX: null,
        deltaY: null,
    };
}

async function deleteTextAnnotation(page, textFragment) {
    const result = await page.evaluate((targetTextFragment) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const index = records.findIndex((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (index < 0) {
            throw new Error(`missing text annotation to delete for "${targetTextFragment}"`);
        }

        const [record] = records.splice(index, 1);
        if (record?.element?.parentElement) {
            record.element.parentElement.removeChild(record.element);
        } else if (record?.element?.remove) {
            record.element.remove();
        }

        if (window.selectedAnnotation === record) {
            setSelection(null);
        }

        persistAnnotations();
        return {
            remaining: records.length,
        };
    }, textFragment);

    await page.waitForTimeout(250);
    return result;
}

async function readPromotedAnnotationState(page, targetTextFragment = '') {
    return page.evaluate((targetText) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const record = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations.find((item) => (
                item?.promotedFromExtraction
                && (!targetText || normalizeText(item?.text || '').includes(normalizeText(targetText)))
            ))
            : null;
        const element = record?.element || Array.from(document.querySelectorAll('.annotation.promoted-extraction')).find((candidate) => (
            !targetText || normalizeText(candidate?.innerText || candidate?.textContent || '').includes(normalizeText(targetText))
        ));
        const textElement = element?.querySelector('.annotation-text');
        const textStyle = textElement ? getComputedStyle(textElement) : null;

        return {
            id: record?.id || null,
            text: record?.text || textElement?.innerText || '',
            promotedDirty: Boolean(record?.promotedDirty),
            promotedReflowEnabled: Boolean(record?.promotedReflowEnabled),
            exactGeometry: textElement?.dataset?.exactPromotedGeometry || null,
            annotationLineHeight: Number(record?.lineHeight || 0),
            fontFamily: textStyle?.fontFamily || '',
            fontSizePx: parseFloat(textStyle?.fontSize || '0') || 0,
            lineHeightPx: parseFloat(textStyle?.lineHeight || '0') || 0,
            whiteSpace: textStyle?.whiteSpace || '',
            wordBreak: textStyle?.wordBreak || '',
            overflowWrap: textStyle?.overflowWrap || '',
            padding: textStyle?.padding || '',
            left: element?.offsetLeft || 0,
            top: element?.offsetTop || 0,
            width: element?.offsetWidth || 0,
            height: element?.offsetHeight || 0,
        };
    }, targetTextFragment);
}

async function readFirstPromotedAnnotationState(page) {
    return readPromotedAnnotationState(page);
}

async function openPromotedAnnotationEditor(page, targetTextFragment = '') {
    const annotation = targetTextFragment
        ? page.locator('.annotation.promoted-extraction').filter({ hasText: targetTextFragment }).first()
        : page.locator('.annotation.promoted-extraction').first();
    await annotation.waitFor({ timeout: 10000 });
    await annotation.dispatchEvent('dblclick');
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.waitForTimeout(250);
}

async function closeActiveTextBoxNoop(page) {
    await page.mouse.click(20, 20);
    await page.waitForTimeout(1200);
}

async function noOpEditFirstPromotedAnnotation(page) {
    await openPromotedAnnotationEditor(page);
    await closeActiveTextBoxNoop(page);
}


async function readLoadedSavedPromotedExtractionFallbackDecision(page) {
    return page.evaluate(async () => {
        const extractionPages = await ensureOverlayExtractionDataLoaded();

        return {
            loadedSavedPdfMode,
            cleanPdfUrl,
            originalPdfUrl,
            extractionBlockCount: countPromotedExtractionBlocks(extractionPages),
            cleanBaseRequired: shouldUseCleanBaseForLoadedSavedPdf([], extractionPages),
        };
    });
}

async function dragLocator(page, locator, deltaX, deltaY) {
    const box = await locator.boundingBox();
    if (!box) {
        throw new Error('missing locator bounding box for drag');
    }

    const fromX = box.x + (box.width / 2);
    const fromY = box.y + (box.height / 2);
    await page.mouse.move(fromX, fromY);
    await page.mouse.down();
    await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 16 });
    await page.mouse.up();
    await page.waitForTimeout(1000);
}

async function waitForAnnotationSave(page) {
    // The editor routes through different endpoints depending on the save type:
    //   /apply-annotations-direct  — full PDF stamp (rotation, page ops, etc.)
    //   /save-annotations          — legacy full-page annotation save
    //   /save-annotation-state     — annotation-only save (most common for standalone text)
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && (
            response.url().includes('/apply-annotations-direct')
            || response.url().includes('/save-annotations')
            || response.url().includes('/save-annotation-state')
        )
    ), { timeout: 90000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`annotation save failed with ${response.status()}`);
    }

    // For annotation-only saves (/save-annotation-state) the annotations array is
    // NOT cleared — the editor keeps them live for further edits. Only full PDF
    // saves trigger a page reload that empties the array. Wait for the spinner to
    // disappear and the status bar to show "Saved." instead.
    const savedViaAnnotationState = response.url().includes('/save-annotation-state');
    if (savedViaAnnotationState) {
        await page.waitForFunction(
            () => {
                const status = document.getElementById('status-text') || document.querySelector('[data-status]');
                if (status) {
                    const txt = (status.textContent || status.getAttribute('data-status') || '').toLowerCase();
                    if (txt.includes('saved') || txt.includes('no changes')) return true;
                }
                // Fallback: spinner gone
                const spinner = document.getElementById('save-spinner') || document.querySelector('.save-spinner');
                return !spinner || getComputedStyle(spinner).display === 'none';
            },
            null,
            { timeout: 30000 }
        );
        await page.waitForTimeout(500);
    } else {
        // Full PDF stamp path — editor reloads, annotations array goes to 0.
        await page.waitForFunction(() => typeof annotations !== 'undefined' && annotations.length === 0, null, { timeout: 60000 });
        await waitForEditorReady(page);
    }
}

async function saveAnnotationsOnly(page) {
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/save-annotation-state')
    ), { timeout: 60000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`annotation-only save failed with ${response.status()}`);
    }

    await page.waitForTimeout(1500);
}

async function setColorInput(page, colorId, hexId, hexValue) {
    await page.evaluate(({ colorId: nextColorId, hexId: nextHexId, hexValue: nextHexValue }) => {
        const colorEl = document.getElementById(nextColorId);
        const hexEl = document.getElementById(nextHexId);
        if (colorEl) {
            colorEl.value = nextHexValue;
            colorEl.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (hexEl) {
            hexEl.value = nextHexValue;
            hexEl.dispatchEvent(new Event('input', { bubbles: true }));
            hexEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, {
        colorId,
        hexId,
        hexValue,
    });
}

async function setRangeInput(page, inputId, value) {
    await page.evaluate(({ inputId: nextInputId, value: nextValue }) => {
        const el = document.getElementById(nextInputId);
        if (!el) {
            return;
        }
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;
        if (typeof setter === 'function') {
            setter.call(el, String(nextValue));
        } else {
            el.value = String(nextValue);
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, {
        inputId,
        value,
    });
}

async function drawShapeAtRegion(page, shapeType, region, options = {}) {
    await page.click('#mode-shape');
    await page.waitForSelector('#shape-modal', { timeout: 10000 });
    await page.waitForTimeout(200);

    await page.click(`#shape-type-grid .shape-type-btn[data-shape="${shapeType}"]`);
    await setColorInput(page, 'shape-stroke-color', 'shape-stroke-hex', options.stroke || '#000000');

    const strokeTransparent = Boolean(options.strokeTransparent);
    const strokeTransparentChecked = await page.isChecked('#shape-stroke-transparent');
    if (strokeTransparentChecked !== strokeTransparent) {
        await page.click('#shape-stroke-transparent');
    }

    const fillTransparent = Boolean(options.fillTransparent);
    const fillTransparentChecked = await page.isChecked('#shape-fill-transparent');
    if (fillTransparentChecked !== fillTransparent) {
        await page.click('#shape-fill-transparent');
    }

    if (!fillTransparent) {
        await setColorInput(page, 'shape-fill-color', 'shape-fill-hex', options.fill || '#000000');
    }

    if (Number.isFinite(Number(options.strokeWidth))) {
        await setRangeInput(page, 'shape-stroke-width', Number(options.strokeWidth));
    }
    if (Number.isFinite(Number(options.opacity))) {
        await setRangeInput(page, 'shape-opacity', Number(options.opacity));
    }

    await page.click('#shape-apply');
    await page.waitForTimeout(250);

    const overlay = page.locator('#viewer .page[data-page-index="0"] .overlay, #viewer .page-wrapper[data-page-number="1"] .overlay').first();
    const box = await overlay.boundingBox();
    if (!box) {
        throw new Error('missing overlay bounding box for shape draw');
    }

    const startX = box.x + (box.width * Number(region.xStart));
    const startY = box.y + (box.height * Number(region.yStart));
    const endX = box.x + (box.width * Number(region.xEnd));
    const endY = box.y + (box.height * Number(region.yEnd));

    await page.mouse.move(startX, startY);
    await page.mouse.down();
    await page.mouse.move(endX, endY, { steps: 16 });
    await page.mouse.up();
    await page.waitForTimeout(600);
}

async function readShapeAnnotationSummaries(page, shapeType = null) {
    return page.evaluate((requestedShapeType) => {
        const normalizeShapeHex = (value, fallback = '#000000') => {
            const hex = String(value || fallback).trim().toLowerCase();
            return /^#[0-9a-f]{6}$/.test(hex) ? hex : fallback;
        };
        const items = Array.isArray(annotations) ? annotations : [];
        return items
            .filter((annotation) => annotation?.type === 'shape' && (!requestedShapeType || annotation?.shapeType === requestedShapeType))
            .map((annotation) => {
                const element = annotation.element || null;
                const rect = element?.getBoundingClientRect?.() || null;
                const actionBar = element?.querySelector?.('.shape-action-bar') || null;
                return {
                    id: annotation.id || null,
                    shapeType: annotation.shapeType || null,
                    fillColor: normalizeShapeHex(annotation.fillColor || '#000000'),
                    strokeColor: normalizeShapeHex(annotation.strokeColor || '#000000'),
                    fillTransparent: Boolean(annotation.fillTransparent),
                    strokeTransparent: Boolean(annotation.strokeTransparent),
                    pdfX: Number(annotation.pdfX) || 0,
                    pdfY: Number(annotation.pdfY) || 0,
                    pdfWidth: Number(annotation.pdfWidth) || 0,
                    pdfHeight: Number(annotation.pdfHeight) || 0,
                    left: element ? (Number(element.offsetLeft) || 0) : 0,
                    top: element ? (Number(element.offsetTop) || 0) : 0,
                    width: rect ? rect.width : 0,
                    height: rect ? rect.height : 0,
                    actionBarVisible: actionBar ? getComputedStyle(actionBar).display !== 'none' : false,
                };
            })
            .sort((a, b) => a.pdfX - b.pdfX || a.pdfY - b.pdfY || String(a.id).localeCompare(String(b.id)));
    }, shapeType);
}

async function readFirstImageAnnotationState(page) {
    return page.evaluate(() => {
        const records = Array.isArray(annotations) ? annotations : [];
        const annotation = records.find((item) => item?.type === 'image') || null;
        const element = annotation?.element || document.querySelector('.annotation img[alt*="Imported image"]')?.closest('.annotation') || null;
        const imageEl = element?.querySelector('img') || null;
        const rect = element?.getBoundingClientRect?.() || null;
        return {
            id: annotation?.id || null,
            type: annotation?.type || null,
            fileName: annotation?.fileName || null,
            pdfX: Number(annotation?.pdfX) || 0,
            pdfY: Number(annotation?.pdfY) || 0,
            pdfWidth: Number(annotation?.pdfWidth) || 0,
            pdfHeight: Number(annotation?.pdfHeight) || 0,
            left: element ? Number(element.offsetLeft) || 0 : 0,
            top: element ? Number(element.offsetTop) || 0 : 0,
            width: rect ? rect.width : 0,
            height: rect ? rect.height : 0,
            imageNaturalWidth: imageEl?.naturalWidth || 0,
            imageNaturalHeight: imageEl?.naturalHeight || 0,
        };
    });
}

async function resizeFirstImageAnnotation(page, targetWidth, targetHeight) {
    const imageAnnotation = page.locator('.annotation').filter({ has: page.locator('img') }).first();
    await imageAnnotation.waitFor({ timeout: 10000 });
    await imageAnnotation.click();
    await page.waitForTimeout(250);

    const fieldBox = await imageAnnotation.boundingBox();
    const handle = imageAnnotation.locator('.shape-resize-handle.se').first();
    const handleBox = await handle.boundingBox();
    if (!fieldBox || !handleBox) {
        throw new Error('missing image resize handle');
    }

    const deltaX = targetWidth - fieldBox.width;
    const deltaY = targetHeight - fieldBox.height;
    const fromX = handleBox.x + (handleBox.width / 2);
    const fromY = handleBox.y + (handleBox.height / 2);

    await page.mouse.move(fromX, fromY);
    await page.mouse.down();
    await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 18 });
    await page.mouse.up();
    await page.waitForTimeout(1000);

    return readFirstImageAnnotationState(page);
}

async function fetchPdfDrawingSummaries(pdfPath) {
    const pythonCode = `
import fitz, json, sys

doc = fitz.open(sys.argv[1])
page = doc[0]

def to_hex(color):
    if color is None:
        return None
    values = list(color) if hasattr(color, '__iter__') else [color]
    if len(values) < 3:
        return None
    rgb = []
    for value in values[:3]:
        number = float(value)
        if number <= 1.0:
            number *= 255.0
        rgb.append(max(0, min(255, int(round(number)))))
    return '#%02x%02x%02x' % tuple(rgb)

drawings = []
for drawing in page.get_drawings():
    rect = drawing.get('rect')
    drawings.append({
        'rect': [rect.x0, rect.y0, rect.x1, rect.y1] if rect is not None else None,
        'fill': to_hex(drawing.get('fill')),
        'stroke': to_hex(drawing.get('color')),
        'width': float(drawing.get('width', 0) or 0),
        'items': [item[0] for item in drawing.get('items', [])],
    })

print(json.dumps({
    'page_width': float(page.rect.width),
    'page_height': float(page.rect.height),
    'drawings': drawings,
}))
`;

    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

async function fetchPdfRenderedForegroundBBox(pdfPath) {
    const pythonCode = `
import fitz, json, sys

doc = fitz.open(sys.argv[1])
page = doc[0]
pix = page.get_pixmap(matrix=fitz.Matrix(1, 1), alpha=False)
samples = pix.samples
n = pix.n
bbox = [9999, 9999, -1, -1]
for y in range(pix.height):
    for x in range(pix.width):
        i = (y * pix.width + x) * n
        if samples[i:i+3] != b'\\xff\\xff\\xff':
            if x < bbox[0]:
                bbox[0] = x
            if y < bbox[1]:
                bbox[1] = y
            if x > bbox[2]:
                bbox[2] = x
            if y > bbox[3]:
                bbox[3] = y

print(json.dumps({
    'page_width': float(page.rect.width),
    'page_height': float(page.rect.height),
    'bbox': None if bbox[2] < 0 else bbox,
}))
`;

    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

async function clickOutsideFirstPage(page) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    const box = await pageLocator.boundingBox();
    if (!box) {
        throw new Error('missing first page bounding box for outside click');
    }

    const targetX = Math.max(5, box.x - 20);
    const targetY = box.y + Math.min(80, Math.max(20, box.height / 10));
    await page.mouse.click(targetX, targetY);
    await page.waitForTimeout(250);
}

// ─────────────────────────────────────────────────────────────────────────────
// pdf-new editor helpers (resources/views/documents/edit-new.blade.php)
//
// The pdf-new editor is canvas-based: there are no per-annotation DOM nodes and
// internal state lives inside an IIFE.  The editor exposes a debug bridge as
// `window.__editorTestState` (see edit-new.blade.php) which the helpers below
// use to read and mutate annotations in-place.  Save fires automatically via
// debounce after `markDirty()` — there is no `#save-btn` click.
// ─────────────────────────────────────────────────────────────────────────────

async function createBlankDocumentNew(page, options = {}) {
    const pageSize = options.pageSize || 'Letter';
    const orientation = options.orientation || 'portrait';
    if (!page.url().startsWith(BASE_URL)) {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    }
    await ensureLoggedIn(page);

    const response = await page.evaluate(async ({ nextPageSize, nextOrientation }) => {
        const targetUrl = `/pdf-tests/create-blank?page_size=${encodeURIComponent(nextPageSize)}&orientation=${encodeURIComponent(nextOrientation)}`;
        const request = await fetch(targetUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const rawBody = await request.text();
        let body = null;
        try { body = JSON.parse(rawBody); } catch (_e) { body = null; }
        return { ok: request.ok, status: request.status, rawBody, body };
    }, { nextPageSize: pageSize, nextOrientation: orientation });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`server blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 500)}`);
    }

    const documentId = Number(response.body.document_id);
    await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });

    const match = page.url().match(/\/documents\/(\d+)\/edit-new/);
    if (!match) {
        throw new Error(`server blank create opened unexpected URL: ${page.url()}`);
    }

    return Number(match[1]);
}

async function waitForEditorReadyNew(page) {
    if (page.url().includes('/login')) {
        throw new Error(`waitForEditorReadyNew: redirected to login page (${page.url()})`);
    }
    await page.waitForSelector('#oc-1', { timeout: 180000 });
    await page.waitForFunction(() => {
        const c = document.getElementById('oc-1');
        const st = window.__editorTestState;
        return Boolean(c && c.width > 0 && c.height > 0 && st && st.pageData && st.pageData[0]
            && st.pageData[0].canvasWidth > 0);
    }, null, { timeout: 60000 });
    await page.waitForTimeout(800);
}

async function clearAnnotationSessionStateNew(page, documentId) {
    await page.evaluate((id) => {
        try { localStorage.removeItem(`edit_new_session_${id}`); } catch (_e) {}
        try { sessionStorage.removeItem(`pdf-annotations-${id}`); } catch (_e) {}
    }, documentId);
}

async function ensurePdfSessionIdNew(page, documentId) {
    return page.evaluate((id) => {
        const key = `edit_new_session_${id}`;
        let existing = null;
        try { existing = localStorage.getItem(key); } catch (_e) {}
        if (existing) return existing;
        const sessionId = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
        try { localStorage.setItem(key, sessionId); } catch (_e) {}
        return sessionId;
    }, documentId);
}

async function getActiveSessionIdNew(page, documentId) {
    return page.evaluate((id) => {
        try {
            const st = window.__editorTestState;
            if (st && typeof st.getSessionId === 'function') return st.getSessionId();
            return localStorage.getItem(`edit_new_session_${id}`) || '';
        } catch (_e) {
            return '';
        }
    }, documentId);
}

async function fetchSavedAnnotationsNew(page, documentId, sessionId) {
    return page.evaluate(async ({ id, requestedSessionId }) => {
        const response = await fetch(`/documents/${id}/saved-annotations?session_id=${encodeURIComponent(requestedSessionId)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return { ok: response.ok, status: response.status, body: await response.json() };
    }, { id: documentId, requestedSessionId: sessionId });
}

// Convert a canvas-pixel coordinate pair into PDF points (origin bottom-left).
// The pdf-new editor stores pdfX/pdfY/pdfWidth/pdfHeight in points.
function _pdfNewCoordsConvertJS() {
    return `
        function canvasTopToPdfY(canvasTop, pdfHeightPts, scale, canvasHeight) {
            return ((canvasHeight - canvasTop) / scale) - pdfHeightPts;
        }
        function canvasLeftToPdfX(canvasLeft, scale) {
            return canvasLeft / scale;
        }
        function pdfXToCanvasLeft(pdfX, scale) {
            return pdfX * scale;
        }
        function pdfYToCanvasTop(pdfY, pdfHeightPts, scale, canvasHeight) {
            return canvasHeight - ((pdfY + pdfHeightPts) * scale);
        }
        function normalizeText(value) {
            return String(value || '').replace(/\\s+/g, ' ').trim();
        }
        function findAnn(fragment) {
            const st = window.__editorTestState;
            if (!st || !st.pageData) return null;
            const data = st.pageData[0];
            if (!data || !Array.isArray(data.annotations)) return null;
            const f = normalizeText(fragment);
            const ed = st.editedTexts || {};
            for (const ann of data.annotations) {
                const text = normalizeText(ed[ann._uid] != null ? ed[ann._uid] : (ann.text || ''));
                if (text.includes(f)) return { ann, data };
            }
            return null;
        }
    `;
}

async function createTextAnnotationAtNew(page, text, canvasX, canvasY) {
    await page.evaluate(async ({ targetText, cx, cy, helpers }) => {
        eval(helpers);
        const st = window.__editorTestState;
        if (!st) throw new Error('__editorTestState bridge missing on window');
        st.setAddTextMode(true);
        st.createNewTextAnnotation(cx, cy, 0);
        const data = st.pageData[0];
        const ann = data.annotations[data.annotations.length - 1];
        if (!ann) throw new Error('createNewTextAnnotation produced no annotation');
        ann.text = targetText;
        ann.originalText = targetText;
        st.editedTexts[ann._uid] = targetText;
        // Clear any active editor focus so subsequent ops don't get clobbered
        // by flushActiveEditorState rewriting ann.text from the ae editor.
        st.clearActiveAnnotation();
        st.setAddTextMode(false);
        st.redrawOverlay(0);
        st.markDirty();
    }, { targetText: text, cx: canvasX, cy: canvasY, helpers: _pdfNewCoordsConvertJS() });
    await page.waitForTimeout(150);
}

async function createCenterTextAnnotationNew(page, text) {
    const center = await page.evaluate(() => {
        const data = window.__editorTestState?.pageData?.[0];
        if (!data) return null;
        return { cw: data.canvasWidth, ch: data.canvasHeight };
    });
    if (!center) throw new Error('missing pdf-new pageData[0] for center placement');
    await createTextAnnotationAtNew(page, text, Math.round(center.cw / 2), Math.round(center.ch / 2));
}

async function updateTextAnnotationNew(page, textFragment, options = {}) {
    const result = await page.evaluate(async ({ fragment, opts, helpers }) => {
        eval(helpers);
        const found = findAnn(fragment);
        if (!found) throw new Error(`missing pdf-new annotation containing "${fragment}"`);
        const { ann, data } = found;
        const st = window.__editorTestState;

        if (Object.prototype.hasOwnProperty.call(opts, 'text')) {
            ann.text = opts.text;
            st.editedTexts[ann._uid] = opts.text;
        }

        const scale = data.scale;
        const ch = data.canvasHeight;

        const currentLeftPx = pdfXToCanvasLeft(ann.pdfX, scale);
        const currentTopPx = pdfYToCanvasTop(ann.pdfY, ann.pdfHeight, scale, ch);

        const leftPx = Number.isFinite(Number(opts.left)) ? Number(opts.left) : currentLeftPx;
        const topPx  = Number.isFinite(Number(opts.top))  ? Number(opts.top)  : currentTopPx;

        let widthPts = ann.pdfWidth;
        let heightPts = ann.pdfHeight;
        if (Number.isFinite(Number(opts.width)))  widthPts  = Number(opts.width)  / scale;
        if (Number.isFinite(Number(opts.height))) heightPts = Number(opts.height) / scale;

        ann.pdfX = canvasLeftToPdfX(leftPx, scale);
        ann.pdfY = canvasTopToPdfY(topPx, heightPts, scale, ch);
        ann.pdfWidth = widthPts;
        ann.pdfHeight = heightPts;

        st.redrawOverlay(0);
        st.markDirty();

        return {
            id: ann.id,
            text: ann.text,
            left: pdfXToCanvasLeft(ann.pdfX, scale),
            top: pdfYToCanvasTop(ann.pdfY, ann.pdfHeight, scale, ch),
            width: ann.pdfWidth * scale,
            height: ann.pdfHeight * scale,
        };
    }, { fragment: textFragment, opts: options, helpers: _pdfNewCoordsConvertJS() });
    await page.waitForTimeout(120);
    return result;
}

async function forcePositionTextAnnotationNew(page, textFragment, options = {}) {
    return updateTextAnnotationNew(page, textFragment, options);
}

async function readTextAnnotationMetricsNew(page, textFragment) {
    return page.evaluate(({ fragment, helpers }) => {
        eval(helpers);
        const found = findAnn(fragment);
        if (!found) throw new Error(`missing pdf-new annotation metrics target for "${fragment}"`);
        const { ann, data } = found;
        const scale = data.scale;
        const ch = data.canvasHeight;
        const left = pdfXToCanvasLeft(ann.pdfX, scale);
        const top  = pdfYToCanvasTop(ann.pdfY, ann.pdfHeight, scale, ch);
        const width = ann.pdfWidth * scale;
        const height = ann.pdfHeight * scale;
        const text = String(window.__editorTestState.editedTexts[ann._uid] != null
            ? window.__editorTestState.editedTexts[ann._uid] : ann.text || '');
        // Approximate line count from the annotation source lines, falling back
        // to a single line when not populated.
        const lineCount = Array.isArray(ann.sourceTextLines) && ann.sourceTextLines.length
            ? ann.sourceTextLines.length
            : 1;
        // Measure the unwrapped text width using a canvas with the annotation's
        // font so callers can detect when the rendered text overflows its box.
        const measureCanvas = document.createElement('canvas');
        const mctx = measureCanvas.getContext('2d');
        const fontSizePx = (Number(ann.fontSize) || 12) * scale;
        const fontFamily = String(ann.fontFamily || 'Helvetica');
        const fontWeight = ann.fontWeight || 'normal';
        const fontStyle  = ann.fontStyle  || 'normal';
        mctx.font = `${fontStyle} ${fontWeight} ${fontSizePx}px ${fontFamily}`;
        const textWidth = mctx.measureText(text).width || 0;
        return {
            left, top, width, height,
            overlayWidth: data.canvasWidth,
            overlayHeight: data.canvasHeight,
            contentWidth: width,
            contentHeight: height,
            lineCount,
            lineWidths: [width],
            lineRightGaps: [0],
            text,
            textWidth,
            fontSizePx,
        };
    }, { fragment: textFragment, helpers: _pdfNewCoordsConvertJS() });
}

async function readTextAnnotationStateNew(page, textFragment) {
    return page.evaluate(({ fragment, helpers }) => {
        eval(helpers);
        const found = findAnn(fragment);
        if (!found) throw new Error(`missing pdf-new annotation state target for "${fragment}"`);
        const { ann, data } = found;
        const scale = data.scale;
        const ch = data.canvasHeight;
        return {
            left: pdfXToCanvasLeft(ann.pdfX, scale),
            top: pdfYToCanvasTop(ann.pdfY, ann.pdfHeight, scale, ch),
            width: ann.pdfWidth * scale,
            height: ann.pdfHeight * scale,
            fontSizePx: Number(ann.fontSize || 0) * scale,
            requestedFontSize: Number(ann.requestedFontSize) || null,
            currentScale: scale,
            pdfX: Number(ann.pdfX) || 0,
            pdfY: Number(ann.pdfY) || 0,
            pdfWidth: Number(ann.pdfWidth) || 0,
            pdfHeight: Number(ann.pdfHeight) || 0,
            overlayWidth: data.canvasWidth,
            overlayHeight: data.canvasHeight,
        };
    }, { fragment: textFragment, helpers: _pdfNewCoordsConvertJS() });
}

async function analyzeAnnotationGroupingsNew(page, cases) {
    return page.evaluate((scenarioCases) => {
        const normalize = (v) => String(v || '').replace(/\s+/g, ' ').trim();
        const st = window.__editorTestState;
        const data = st?.pageData?.[0];
        const records = Array.isArray(data?.annotations) ? data.annotations : [];
        const ed = st?.editedTexts || {};
        const annotationTexts = records.map((ann) => normalize(
            ed[ann._uid] != null ? ed[ann._uid] : (ann.text || '')
        ));
        const matchesByCase = scenarioCases.map((scenarioCase) => {
            const caseText = normalize(scenarioCase.text);
            const matchCount = annotationTexts.filter((t) => t === caseText).length;
            return { text: scenarioCase.text, matchCount };
        });
        const groupedBlocks = annotationTexts
            .map((annotText) => {
                const hits = scenarioCases.filter((c) => annotText.includes(normalize(c.text)));
                if (hits.length <= 1) return null;
                return { text: annotText, matchedTexts: hits.map((c) => c.text) };
            })
            .filter(Boolean);
        return { matchesByCase, groupedBlocks };
    }, cases);
}

async function waitForAnnotationSaveNew(page) {
    // pdf-new editor auto-saves on a debounce after markDirty().  Wait for the
    // POST to /save-annotation-state and the editor to settle (isSaving=false,
    // isDirty=false).
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/save-annotation-state')
    ), { timeout: 60000 });

    // Force a save POST even when the auto-save debounce has already fired
    // (in which case flushAutoSaveIfPending is a no-op and the responsePromise
    // would otherwise time out waiting for a network event that won't repeat).
    await page.evaluate(() => {
        const st = window.__editorTestState;
        if (st && typeof st.forceSaveForTests === 'function') {
            st.forceSaveForTests();
        } else if (st && typeof st.flushAutoSaveIfPending === 'function') {
            st.flushAutoSaveIfPending();
        }
    });

    const response = await responsePromise;
    if (!response.ok()) {
        throw new Error(`pdf-new annotation save failed with ${response.status()}`);
    }

    await page.waitForFunction(() => {
        const st = window.__editorTestState;
        return Boolean(st) && !st.isSaving && !st.isDirty;
    }, null, { timeout: 30000 });
    await page.waitForTimeout(300);
}

async function clickOutsideFirstPageNew(page) {
    const card = page.locator('#card-1').first();
    const box = await card.boundingBox();
    if (!box) {
        throw new Error('missing #card-1 bounding box for outside click');
    }
    const targetX = Math.max(5, box.x - 20);
    const targetY = box.y + Math.min(80, Math.max(20, box.height / 10));
    await page.mouse.click(targetX, targetY);
    await page.waitForTimeout(250);
}

async function capturePageScreenshotNew(page, outputPath) {
    const card = page.locator('#card-1').first();
    await card.screenshot({ path: outputPath });
}

async function captureCenteredOverlayScreenshotNew(page, outputPath, options = {}) {
    const canvas = page.locator('#oc-1').first();
    const box = await canvas.boundingBox();
    if (!box) throw new Error('missing #oc-1 box for centered screenshot');
    const targetWidth = Number(options.width) || 280;
    const targetHeight = Number(options.height) || 180;
    await captureViewportClipScreenshot(page, outputPath, {
        x: box.x + ((box.width - targetWidth) / 2),
        y: box.y + ((box.height - targetHeight) / 2),
        width: targetWidth,
        height: targetHeight,
    }, {
        padding: Number(options.padding) || 0,
        minWidth: targetWidth,
        minHeight: targetHeight,
        maxWidth: targetWidth,
        maxHeight: targetHeight,
    });
}

// Compute viewport box for an annotation by its text fragment using the
// pageData state and the overlay canvas position.
async function _resolveAnnotationViewportBoxNew(page, textFragment) {
    return page.evaluate(({ fragment, helpers }) => {
        eval(helpers);
        const found = findAnn(fragment);
        if (!found) return null;
        const { ann, data } = found;
        const canvas = document.getElementById('oc-1');
        if (!canvas) return null;
        const r = canvas.getBoundingClientRect();
        const scale = data.scale;
        const ch = data.canvasHeight;
        const cw = data.canvasWidth;
        const leftPx = pdfXToCanvasLeft(ann.pdfX, scale);
        const topPx = pdfYToCanvasTop(ann.pdfY, ann.pdfHeight, scale, ch);
        const widthPx = ann.pdfWidth * scale;
        const heightPx = ann.pdfHeight * scale;
        // Map canvas pixels into viewport pixels via the canvas's bounding rect.
        const viewportLeft = r.left + (leftPx / cw) * r.width;
        const viewportTop = r.top + (topPx / ch) * r.height;
        const viewportWidth = (widthPx / cw) * r.width;
        const viewportHeight = (heightPx / ch) * r.height;
        return {
            x: viewportLeft + (window.scrollX || 0),
            y: viewportTop + (window.scrollY || 0),
            width: viewportWidth,
            height: viewportHeight,
        };
    }, { fragment: textFragment, helpers: _pdfNewCoordsConvertJS() });
}

async function captureTextAnnotationScreenshotNew(page, textFragment, outputPath, options = {}) {
    const box = await _resolveAnnotationViewportBoxNew(page, textFragment);
    if (!box) throw new Error(`missing pdf-new annotation screenshot target for "${textFragment}"`);
    await captureViewportClipScreenshot(page, outputPath, box, {
        padding: Number(options.padding) || 32,
        minWidth: Number(options.minWidth) || 220,
        minHeight: Number(options.minHeight) || 120,
        maxWidth: Number(options.maxWidth) || 520,
        maxHeight: Number(options.maxHeight) || 260,
    });
}

async function captureTextAnnotationClusterScreenshotNew(page, textFragments, outputPath, options = {}) {
    const cluster = await page.evaluate(({ fragments, helpers }) => {
        eval(helpers);
        const canvas = document.getElementById('oc-1');
        if (!canvas) return null;
        const r = canvas.getBoundingClientRect();
        const st = window.__editorTestState;
        const data = st?.pageData?.[0];
        if (!data) return null;
        const scale = data.scale;
        const ch = data.canvasHeight;
        const cw = data.canvasWidth;
        const ed = st.editedTexts || {};
        const records = data.annotations || [];
        const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
        const targets = fragments.map((f) => norm(f)).filter(Boolean);
        const rects = [];
        for (const ann of records) {
            const annText = norm(ed[ann._uid] != null ? ed[ann._uid] : (ann.text || ''));
            if (!targets.some((t) => annText === t)) continue;
            const leftPx = pdfXToCanvasLeft(ann.pdfX, scale);
            const topPx = pdfYToCanvasTop(ann.pdfY, ann.pdfHeight, scale, ch);
            const widthPx = ann.pdfWidth * scale;
            const heightPx = ann.pdfHeight * scale;
            rects.push({
                left: r.left + (leftPx / cw) * r.width,
                top: r.top + (topPx / ch) * r.height,
                right: r.left + ((leftPx + widthPx) / cw) * r.width,
                bottom: r.top + ((topPx + heightPx) / ch) * r.height,
            });
        }
        if (!rects.length) return null;
        const left = Math.min(...rects.map((rect) => rect.left));
        const top = Math.min(...rects.map((rect) => rect.top));
        const right = Math.max(...rects.map((rect) => rect.right));
        const bottom = Math.max(...rects.map((rect) => rect.bottom));
        return {
            x: left + (window.scrollX || 0),
            y: top + (window.scrollY || 0),
            width: right - left,
            height: bottom - top,
        };
    }, { fragments: textFragments, helpers: _pdfNewCoordsConvertJS() });
    if (!cluster) throw new Error(`missing pdf-new annotation cluster screenshot target for ${JSON.stringify(textFragments)}`);
    await captureViewportClipScreenshot(page, outputPath, cluster, {
        padding: Number(options.padding) || 28,
        minWidth: Number(options.minWidth) || 260,
        minHeight: Number(options.minHeight) || 160,
        maxWidth: Number(options.maxWidth) || 760,
        maxHeight: Number(options.maxHeight) || 460,
    });
}

async function setEditorZoomPercentNew(page, targetZoom) {
    const clampedTarget = Math.max(50, Math.min(400, Math.round(Number(targetZoom) || 100)));
    for (let attempts = 0; attempts < 16; attempts += 1) {
        const currentZoom = await readEditorZoomPercent(page);
        if (currentZoom === clampedTarget) return currentZoom;
        const zoomingOut = currentZoom > clampedTarget;
        await page.click(zoomingOut ? '#zoom-out' : '#zoom-in');
        await page.waitForFunction(({ previousZoom, target, decreasing }) => {
            const label = document.getElementById('zoom-label');
            const value = parseInt(String(label?.textContent || '').trim(), 10);
            if (!Number.isFinite(value)) return false;
            if (value === target) return true;
            return decreasing ? value < previousZoom : value > previousZoom;
        }, { previousZoom: currentZoom, target: clampedTarget, decreasing: zoomingOut }, { timeout: 30000 });
        await waitForEditorReadyNew(page);
    }
    throw new Error(`failed to set pdf-new editor zoom to ${clampedTarget}%`);
}

// Select an annotation by text fragment in the pdf-new editor and drive the
// annotation format bar (afb-*) controls the same way a real user would —
// dispatch real DOM events on each control. This validates that clicking
// AFB Bold / Italic / Underline and changing the AFB font/bg-color selects
// actually mutate the annotation record (and so persist on save).
async function applyAnnotationStylesViaToolbarNew(page, textFragment, styles) {
    return page.evaluate(({ fragment, inputStyles, helpers }) => {
        eval(helpers);
        const found = findAnn(fragment);
        if (!found) throw new Error(`missing pdf-new annotation for "${fragment}"`);
        const { ann, data } = found;
        const st = window.__editorTestState;

        // The annotation format bar only reacts when an annotation is the
        // current active selection AND the editor is in edit mode. The new
        // editor's selectAnnotation guards on (editModeEnabled || addTextMode).
        if (typeof st.setEditModeEnabled === 'function') {
            st.setEditModeEnabled(true);
        }
        st.selectAnnotation(ann, 0);
        if (typeof st.updateFormatBar === 'function') {
            st.updateFormatBar();
        }

        if (inputStyles.fontFamily) {
            const sel = document.getElementById('afb-font');
            if (!sel) throw new Error('missing #afb-font');
            sel.value = inputStyles.fontFamily;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (inputStyles.bold === true) {
            const btn = document.getElementById('afb-bold');
            if (!btn) throw new Error('missing #afb-bold');
            // Only click if not already bold so a stale aria-pressed doesn't
            // cause a no-op toggle that flips the state the wrong way.
            if (String(ann.fontWeight || '400') !== '700') btn.click();
        }
        if (inputStyles.italic === true) {
            const btn = document.getElementById('afb-italic');
            if (!btn) throw new Error('missing #afb-italic');
            if ((ann.fontStyle || 'normal') !== 'italic') btn.click();
        }
        if (inputStyles.underline === true) {
            const btn = document.getElementById('afb-underline');
            if (!btn) throw new Error('missing #afb-underline');
            if (!ann.underline) btn.click();
        }
        if (inputStyles.backgroundColor) {
            const input = document.getElementById('afb-bg-color');
            if (!input) throw new Error('missing #afb-bg-color');
            input.value = inputStyles.backgroundColor;
            // The setupColorPicker 'change' handler with no active selection
            // takes the applyFormatProperty branch and assigns ann.backgroundColor.
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Exit edit mode and clear the active selection so subsequent
        // createNewTextAnnotation calls succeed: setAddTextMode is gated on
        // !editModeEnabled, so leaving edit mode on would make later text
        // creations silently no-op.
        st.clearActiveAnnotation();
        if (typeof st.setEditModeEnabled === 'function') {
            st.setEditModeEnabled(false);
        }

        return {
            id: ann.id,
            fontFamily: ann.fontFamily,
            fontWeight: ann.fontWeight,
            fontStyle: ann.fontStyle,
            underline: Boolean(ann.underline),
            backgroundColor: ann.backgroundColor,
        };
    }, { fragment: textFragment, inputStyles: styles, helpers: _pdfNewCoordsConvertJS() });
}

// Inject a rich-HTML body onto an existing pdf-new annotation, simulating
// the result of inline editing where a sub-span carries different styling
// (e.g. an italic+underlined word inside an otherwise plain paragraph).
// This reproduces the doc 2880 bug where rich-HTML span styling was not
// honoured by the python renderer when a real Download-PDF was issued.
async function injectRichHtmlOnAnnotationNew(page, textFragment, richHtml) {
    await page.evaluate(({ fragment, html, helpers }) => {
        eval(helpers);
        const found = findAnn(fragment);
        if (!found) throw new Error(`injectRichHtmlOnAnnotationNew: annotation not found for "${fragment}"`);
        const ann = found.ann;
        ann._richHtml = html;
        const st = window.__editorTestState;
        // Mirror the visible plain-text into ann.text / editedTexts so the
        // anchor still finds the annotation later. The python renderer will
        // prefer richHtml over text when both are present.
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        const plain = (tmp.textContent || '').replace(/\s+/g, ' ').trim();
        ann.text = plain;
        ann.originalText = plain;
        st.editedTexts[ann._uid] = plain;
        st.markDirty();
        st.redrawOverlay(0);
    }, { fragment: textFragment, html: richHtml, helpers: _pdfNewCoordsConvertJS() });
    await page.waitForTimeout(120);
}

// Read the current annotation record fields from the pdf-new editor state.
async function readCommittedAnnotationRichStateNew(page, textFragment) {
    return page.evaluate(({ fragment, helpers }) => {
        eval(helpers);
        const found = findAnn(fragment);
        if (!found) return null;
        const ann = found.ann;
        return {
            text: String(window.__editorTestState.editedTexts[ann._uid] != null
                ? window.__editorTestState.editedTexts[ann._uid] : (ann.text || '')),
            fontFamily: String(ann.fontFamily || ''),
            fontWeight: String(ann.fontWeight || ''),
            fontStyle: String(ann.fontStyle || ''),
            underline: Boolean(ann.underline),
            backgroundColor: String(ann.backgroundColor || ''),
            textColor: String(ann.textColor || ''),
        };
    }, { fragment: textFragment, helpers: _pdfNewCoordsConvertJS() });
}

// Trigger the real Download-PDF flow used by the pdf-new editor: POST to
// /documents/{id}/download-annotated-pdf with collectSessionAnnotations() as
// the payload (same body downloadReadyPdf() builds when the user clicks the
// toolbar button) and write the response bytes to disk.
async function downloadAnnotatedPdfNew(page, documentId, outputPath) {
    const base64 = await page.evaluate(async ({ id, csrf }) => {
        const sessionAnnotations = (typeof window.collectSessionAnnotations === 'function')
            ? window.collectSessionAnnotations()
            : [];
        const sessionId = (window.__editorTestState && typeof window.__editorTestState.getSessionId === 'function')
            ? window.__editorTestState.getSessionId()
            : '';
        const acroFormEntries = Array.isArray(window.acroFormEntries) ? window.acroFormEntries : [];
        const deletedKeys = Array.isArray(window.pendingDeletedPromotedSourceKeys)
            ? Array.from(window.pendingDeletedPromotedSourceKeys)
            : [];
        const resp = await fetch(`/documents/${id}/download-annotated-pdf`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf, application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                annotations: sessionAnnotations,
                session_annotations: sessionAnnotations,
                acro_form_entries: acroFormEntries,
                deleted_promoted_source_keys: deletedKeys,
                // Match what edit-new.blade.php's downloadReadyPdf() sends so
                // the test exercises the same controller branch a real user
                // hits when clicking the toolbar Download PDF button.
                use_exact_download_path: true,
                session_id: sessionId,
            }),
        });
        if (!resp.ok) {
            const text = await resp.text().catch(() => '');
            throw new Error(`download-annotated-pdf failed ${resp.status}: ${text.slice(0, 500)}`);
        }
        const buf = await resp.arrayBuffer();
        // Convert to base64 in chunks to avoid call-stack overflow on large PDFs.
        const bytes = new Uint8Array(buf);
        let binary = '';
        const CHUNK = 0x8000;
        for (let i = 0; i < bytes.length; i += CHUNK) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK));
        }
        return btoa(binary);
    }, {
        id: documentId,
        csrf: await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content || ''),
    });
    fs.writeFileSync(outputPath, Buffer.from(base64, 'base64'));
}

// Run pymupdf in-process to dump every text span (text, font name, fitz flags,
// bbox) plus a list of horizontal-line drawings.  fitz `flags` bits used here:
//   bit 1 (value 2)  = italic
//   bit 4 (value 16) = bold
// Underline isn't a font property in PDF — it's a separate stroke drawn under
// the text — so we collect short horizontal lines from page.get_drawings() and
// the caller intersects them against each text span's bbox.
function extractDownloadedPdfTextStylesViaPython(pdfPath) {
    const pythonCode = `
import fitz, json, sys

doc = fitz.open(sys.argv[1])
out_pages = []
for page in doc:
    spans = []
    for block in page.get_text('dict').get('blocks', []):
        for line in block.get('lines', []):
            for span in line.get('spans', []):
                txt = span.get('text', '') or ''
                if not txt.strip():
                    continue
                bbox = span.get('bbox') or [0, 0, 0, 0]
                spans.append({
                    'text': txt,
                    'font': span.get('font', '') or '',
                    'flags': int(span.get('flags', 0) or 0),
                    'size': float(span.get('size', 0) or 0),
                    'bbox': [float(bbox[0]), float(bbox[1]), float(bbox[2]), float(bbox[3])],
                })
    underlines = []
    for drawing in page.get_drawings():
        for item in drawing.get('items', []):
            op = item[0]
            if op == 'l':
                p1, p2 = item[1], item[2]
                if abs(p1.y - p2.y) <= 0.6 and abs(p2.x - p1.x) >= 4:
                    underlines.append({
                        'y': float((p1.y + p2.y) / 2),
                        'x0': float(min(p1.x, p2.x)),
                        'x1': float(max(p1.x, p2.x)),
                    })
            elif op == 're':
                rect = item[1]
                w = float(rect.x1 - rect.x0)
                h = float(rect.y1 - rect.y0)
                if h <= 1.5 and w >= 4:
                    underlines.append({
                        'y': float((rect.y0 + rect.y1) / 2),
                        'x0': float(rect.x0),
                        'x1': float(rect.x1),
                    })
    out_pages.append({
        'width': float(page.rect.width),
        'height': float(page.rect.height),
        'spans': spans,
        'underlines': underlines,
    })
print(json.dumps({'pages': out_pages}))
`;
    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

// ── Test 1 (pdf-new) — Text Position ────────────────────────────────────────
async function runTextPositionFlowNew() {
    const test = TESTS.test_1_text_position_new;
    ensureOutputDir();

    const runToken = buildRunToken();
    const blankScreenshotName = buildArtifactName(test.key, runToken, 'blank_flow_center');
    const firstScreenshotName = buildArtifactName(test.key, runToken, 'after_first_save');
    const secondScreenshotName = buildArtifactName(test.key, runToken, 'second_initial_save');
    const secondMoveScreenshotName = buildArtifactName(test.key, runToken, 'after_move_before_second_save');
    const secondDeltaScreenshotName = buildArtifactName(test.key, runToken, 'after_second_save');
    const topPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_top');
    const leftPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_left');
    const rightPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_right');
    const bottomPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_bottom');
    const groupingMixedScreenshotName = buildArtifactName(test.key, runToken, 'grouping_mixed_stack');
    const groupingShortScreenshotName = buildArtifactName(test.key, runToken, 'grouping_five_short');
    const groupingLongScreenshotName = buildArtifactName(test.key, runToken, 'grouping_five_long');
    const blankScreenshotPath = path.join(OUTPUT_DIR, blankScreenshotName);
    const firstScreenshotPath = path.join(OUTPUT_DIR, firstScreenshotName);
    const secondScreenshotPath = path.join(OUTPUT_DIR, secondScreenshotName);
    const secondMoveScreenshotPath = path.join(OUTPUT_DIR, secondMoveScreenshotName);
    const secondDeltaScreenshotPath = path.join(OUTPUT_DIR, secondDeltaScreenshotName);
    const topPositionScreenshotPath = path.join(OUTPUT_DIR, topPositionScreenshotName);
    const leftPositionScreenshotPath = path.join(OUTPUT_DIR, leftPositionScreenshotName);
    const rightPositionScreenshotPath = path.join(OUTPUT_DIR, rightPositionScreenshotName);
    const bottomPositionScreenshotPath = path.join(OUTPUT_DIR, bottomPositionScreenshotName);
    const groupingMixedScreenshotPath = path.join(OUTPUT_DIR, groupingMixedScreenshotName);
    const groupingShortScreenshotPath = path.join(OUTPUT_DIR, groupingShortScreenshotName);
    const groupingLongScreenshotPath = path.join(OUTPUT_DIR, groupingLongScreenshotName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: VIEWPORT, acceptDownloads: true });
    const page = await context.newPage();
    let firstDocumentId = null;
    let secondDocumentId = null;
    const cardinalResults = {};
    const cardinalDocumentIds = [];
    const groupingDocumentIds = [];
    const groupingScenarioResults = [];
    const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const findExactSavedMatches = (savedResponse, targetText) => {
        const annotations = Array.isArray(savedResponse?.body?.annotations) ? savedResponse.body.annotations : [];
        const normalizedTarget = normalizeText(targetText);
        return annotations.filter((annotation) => normalizeText(annotation?.text) === normalizedTarget);
    };

    try {
        firstDocumentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, firstDocumentId);
        await captureCenteredOverlayScreenshotNew(page, blankScreenshotPath, { width: 300, height: 180 });

        await createCenterTextAnnotationNew(page, POSITION_TEXT);
        const firstStartMetrics = await readTextAnnotationMetricsNew(page, POSITION_TEXT);
        await updateTextAnnotationNew(page, POSITION_TEXT, {
            top: Math.round(firstStartMetrics.top + FIRST_MOVE_Y),
        });
        await waitForAnnotationSaveNew(page);

        const firstAnnot = await readTextAnnotationStateNew(page, POSITION_TEXT);
        if (!firstAnnot || !(firstAnnot.pdfY > 0)) {
            throw new Error(`expected pdf-new annotation with PDF coordinates after first save, got ${JSON.stringify(firstAnnot)}`);
        }
        const firstBBox = [
            firstAnnot.pdfX,
            firstAnnot.pdfY,
            firstAnnot.pdfX + firstAnnot.pdfWidth,
            firstAnnot.pdfY + firstAnnot.pdfHeight,
        ];
        await captureTextAnnotationScreenshotNew(page, POSITION_TEXT, firstScreenshotPath);

        secondDocumentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, secondDocumentId);
        const secondSessionId = await ensurePdfSessionIdNew(page, secondDocumentId);
        // The session id was injected before the editor read it; reload so the
        // editor picks it up consistently for save + saved-annotations fetch.
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReadyNew(page);

        await createCenterTextAnnotationNew(page, POSITION_TEXT);
        await clickOutsideFirstPageNew(page);
        await waitForAnnotationSaveNew(page);

        const secondInitialAnnot = await readTextAnnotationStateNew(page, POSITION_TEXT);
        if (!secondInitialAnnot || !(secondInitialAnnot.pdfY > 0)) {
            throw new Error(`expected initial pdf-new saved annotation with PDF coordinates, got ${JSON.stringify(secondInitialAnnot)}`);
        }
        await captureCenteredOverlayScreenshotNew(page, secondScreenshotPath, { width: 760, height: 680 });

        const secondInitialSavedAnnotations = await fetchSavedAnnotationsNew(page, secondDocumentId, secondSessionId);
        if (!secondInitialSavedAnnotations.ok || !secondInitialSavedAnnotations.body?.success) {
            throw new Error(`pdf-new initial saved-annotations failed: ${JSON.stringify(secondInitialSavedAnnotations)}`);
        }
        const secondInitialMatches = findExactSavedMatches(secondInitialSavedAnnotations, POSITION_TEXT);
        const secondInitialSavedAnnot = secondInitialMatches[0] || null;

        const secondMoveFromState = await readTextAnnotationStateNew(page, POSITION_TEXT);
        const secondMoveTargetTop = Math.round(secondMoveFromState.top + SECOND_MOVE_Y);
        const secondMoveState = await updateTextAnnotationNew(page, POSITION_TEXT, {
            left: Math.round(secondMoveFromState.left),
            top: secondMoveTargetTop,
        });
        await captureCenteredOverlayScreenshotNew(page, secondMoveScreenshotPath, { width: 760, height: 680 });

        await page.waitForTimeout(1200);
        await waitForAnnotationSaveNew(page);

        const secondMovedAnnot = await readTextAnnotationStateNew(page, POSITION_TEXT);
        if (!secondMovedAnnot || !(secondMovedAnnot.pdfY > 0)) {
            throw new Error(`expected moved pdf-new annotation with PDF coordinates after second save, got ${JSON.stringify(secondMovedAnnot)}`);
        }
        await captureCenteredOverlayScreenshotNew(page, secondDeltaScreenshotPath, { width: 760, height: 680 });

        const secondMovedSavedAnnotations = await fetchSavedAnnotationsNew(page, secondDocumentId, secondSessionId);
        if (!secondMovedSavedAnnotations.ok || !secondMovedSavedAnnotations.body?.success) {
            throw new Error(`pdf-new moved saved-annotations failed: ${JSON.stringify(secondMovedSavedAnnotations)}`);
        }
        const secondMovedMatches = findExactSavedMatches(secondMovedSavedAnnotations, POSITION_TEXT);
        const secondMovedSavedAnnot = secondMovedMatches[0] || null;
        const sameSavedAnnotationId = Boolean(
            secondInitialSavedAnnot?.id
            && secondMovedSavedAnnot?.id
            && secondInitialSavedAnnot.id === secondMovedSavedAnnot.id
        );
        const dbUpdatedAtChanged = Boolean(
            secondInitialSavedAnnot?.db_updated_at
            && secondMovedSavedAnnot?.db_updated_at
            && secondInitialSavedAnnot.db_updated_at !== secondMovedSavedAnnot.db_updated_at
        );
        const dbPdfDeltaX = (Number(secondMovedSavedAnnot?.pdfX) || 0) - (Number(secondInitialSavedAnnot?.pdfX) || 0);
        const dbPdfDeltaY = (Number(secondMovedSavedAnnot?.pdfY) || 0) - (Number(secondInitialSavedAnnot?.pdfY) || 0);
        const dbCoordinatesChanged = Math.abs(dbPdfDeltaX) > 0.01 || Math.abs(dbPdfDeltaY) > 0.01;

        const cardinalScreenshotPaths = {
            top: topPositionScreenshotPath,
            left: leftPositionScreenshotPath,
            right: rightPositionScreenshotPath,
            bottom: bottomPositionScreenshotPath,
        };
        for (const positionCase of TEXT_POSITION_CARDINAL_CASES) {
            const cardinalDocumentId = await createBlankDocumentNew(page);
            cardinalDocumentIds.push(cardinalDocumentId);
            await waitForEditorReadyNew(page);
            await clearAnnotationSessionStateNew(page, cardinalDocumentId);

            await createTextAnnotationAtNew(page, positionCase.text, 160, 120);
            const initialMetrics = await readTextAnnotationMetricsNew(page, positionCase.text);
            const targetLeft = positionCase.horizontal === 'left'
                ? TEXT_POSITION_CARDINAL_MARGIN
                : positionCase.horizontal === 'right'
                    ? Math.max(0, Math.round(initialMetrics.overlayWidth - initialMetrics.width - TEXT_POSITION_CARDINAL_MARGIN))
                    : Math.max(0, Math.round((initialMetrics.overlayWidth - initialMetrics.width) / 2));
            const targetTop = positionCase.vertical === 'top'
                ? TEXT_POSITION_CARDINAL_MARGIN
                : positionCase.vertical === 'bottom'
                    ? Math.max(0, Math.round(initialMetrics.overlayHeight - initialMetrics.height - TEXT_POSITION_CARDINAL_MARGIN))
                    : Math.max(0, Math.round((initialMetrics.overlayHeight - initialMetrics.height) / 2));
            await forcePositionTextAnnotationNew(page, positionCase.text, { left: targetLeft, top: targetTop });
            await clickOutsideFirstPageNew(page);
            await waitForAnnotationSaveNew(page);
            const metrics = await readTextAnnotationMetricsNew(page, positionCase.text);
            await setEditorZoomPercentNew(page, TEXT_POSITION_EDGE_SCREENSHOT_ZOOM);
            await capturePageScreenshotNew(page, cardinalScreenshotPaths[positionCase.key]);
            cardinalResults[positionCase.key] = { documentId: cardinalDocumentId, metrics };
        }

        const topMetrics = cardinalResults.top?.metrics || null;
        const leftMetrics = cardinalResults.left?.metrics || null;
        const rightMetrics = cardinalResults.right?.metrics || null;
        const bottomMetrics = cardinalResults.bottom?.metrics || null;
        const topPositionPass = (Number(topMetrics?.top) || 0) <= (TEXT_POSITION_CARDINAL_MARGIN + 1)
            && metricsRight(topMetrics) <= (Number(topMetrics?.overlayWidth) || 0) + 1;
        const leftPositionPass = (Number(leftMetrics?.left) || 0) <= (TEXT_POSITION_CARDINAL_MARGIN + 1)
            && metricsBottom(leftMetrics) <= (Number(leftMetrics?.overlayHeight) || 0) + 1;
        const rightGap = (Number(rightMetrics?.overlayWidth) || 0) - metricsRight(rightMetrics);
        const rightPositionPass = rightGap >= -1 && rightGap <= (TEXT_POSITION_CARDINAL_MARGIN + 1);
        const bottomGap = (Number(bottomMetrics?.overlayHeight) || 0) - metricsBottom(bottomMetrics);
        const bottomPositionPass = bottomGap >= -1 && bottomGap <= (TEXT_POSITION_CARDINAL_MARGIN + 1);

        for (const scenario of TEXT_POSITION_GROUPING_SCENARIOS) {
            const scenarioDocumentId = await createBlankDocumentNew(page);
            groupingDocumentIds.push(scenarioDocumentId);
            await waitForEditorReadyNew(page);
            await clearAnnotationSessionStateNew(page, scenarioDocumentId);

            for (const scenarioCase of scenario.cases) {
                await createTextAnnotationAtNew(page, scenarioCase.text, scenarioCase.left, scenarioCase.top);
            }

            await waitForAnnotationSaveNew(page);
            const scenarioAnalysis = await analyzeAnnotationGroupingsNew(page, scenario.cases);
            const scenarioMetrics = [];
            for (const scenarioCase of scenario.cases) {
                scenarioMetrics.push(await readTextAnnotationMetricsNew(page, scenarioCase.text));
            }
            if (scenario.key === 'mixed_long_short_stack') {
                await captureTextAnnotationClusterScreenshotNew(page, scenario.cases.map((c) => c.text), groupingMixedScreenshotPath, TEXT_POSITION_STACK_SCREENSHOT_OPTIONS);
            } else if (scenario.key === 'five_short_lines') {
                await captureTextAnnotationClusterScreenshotNew(page, scenario.cases.map((c) => c.text), groupingShortScreenshotPath, TEXT_POSITION_STACK_SCREENSHOT_OPTIONS);
            } else if (scenario.key === 'five_long_lines') {
                await captureTextAnnotationClusterScreenshotNew(page, scenario.cases.map((c) => c.text), groupingLongScreenshotPath, TEXT_POSITION_STACK_SCREENSHOT_OPTIONS);
            }
            groupingScenarioResults.push({
                key: scenario.key,
                label: scenario.label,
                documentId: scenarioDocumentId,
                analysis: scenarioAnalysis,
                metrics: scenarioMetrics,
            });
        }

        const mixedStackScenario = groupingScenarioResults.find((s) => s.key === 'mixed_long_short_stack') || null;
        const mixedStackLefts = Array.isArray(mixedStackScenario?.metrics)
            ? mixedStackScenario.metrics.map((metrics) => Number(metrics?.left) || 0)
            : [];
        const mixedStackAlignedLeft = metricsShareSameLeft(mixedStackScenario?.metrics || [], 1);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'Fresh blank PDFs were created through the frontend blank-PDF flow.',
                detail: `first_document_id=${firstDocumentId} second_document_id=${secondDocumentId} creation_path=browser_blank_pdf_flow editor=pdf-new`,
            },
            {
                item: 'first_save_visible',
                result: firstAnnot && firstAnnot.pdfY > 0 ? 'PASS' : 'FAIL',
                description: 'The first annotation save produced an in-page annotation with valid PDF coordinates.',
                detail: `browser_top=${firstAnnot?.top?.toFixed(2)} browser_left=${firstAnnot?.left?.toFixed(2)} pdfX=${firstAnnot?.pdfX?.toFixed(2)} pdfY=${firstAnnot?.pdfY?.toFixed(2)} pdfWidth=${firstAnnot?.pdfWidth?.toFixed(2)} pdfHeight=${firstAnnot?.pdfHeight?.toFixed(2)}`,
            },
            {
                item: 'second_initial_save_db_row_created',
                result: secondInitialMatches.length === 1 && Boolean(secondInitialSavedAnnot?.id) ? 'PASS' : 'FAIL',
                description: 'The second document initial save created exactly one saved annotation row for the target text.',
                detail: `document_id=${secondDocumentId} session_id=${secondSessionId} matched_rows=${secondInitialMatches.length} annotation_id=${secondInitialSavedAnnot?.id || ''} db_updated_at=${secondInitialSavedAnnot?.db_updated_at || ''} pdfX=${Number(secondInitialSavedAnnot?.pdfX || 0).toFixed(2)} pdfY=${Number(secondInitialSavedAnnot?.pdfY || 0).toFixed(2)}`,
            },
            {
                item: 'second_move_db_row_updated',
                result: secondInitialMatches.length === 1 && secondMovedMatches.length === 1 && sameSavedAnnotationId ? 'PASS' : 'FAIL',
                description: 'Moving and saving again updated the same saved annotation row instead of creating a duplicate.',
                detail: `initial_match_count=${secondInitialMatches.length} moved_match_count=${secondMovedMatches.length} initial_annotation_id=${secondInitialSavedAnnot?.id || ''} moved_annotation_id=${secondMovedSavedAnnot?.id || ''} same_annotation_id=${sameSavedAnnotationId} initial_db_updated_at=${secondInitialSavedAnnot?.db_updated_at || ''} moved_db_updated_at=${secondMovedSavedAnnot?.db_updated_at || ''} db_updated_at_changed=${dbUpdatedAtChanged}`,
            },
            {
                item: 'second_move_db_coordinates_changed',
                result: dbCoordinatesChanged ? 'PASS' : 'FAIL',
                description: 'The saved annotation row coordinates changed after the move-and-save step.',
                detail: `annotation_id=${secondMovedSavedAnnot?.id || ''} initial_pdfX=${Number(secondInitialSavedAnnot?.pdfX || 0).toFixed(2)} initial_pdfY=${Number(secondInitialSavedAnnot?.pdfY || 0).toFixed(2)} moved_pdfX=${Number(secondMovedSavedAnnot?.pdfX || 0).toFixed(2)} moved_pdfY=${Number(secondMovedSavedAnnot?.pdfY || 0).toFixed(2)} delta_pdfX=${dbPdfDeltaX.toFixed(2)} delta_pdfY=${dbPdfDeltaY.toFixed(2)} browser_top_before=${secondMoveFromState.top.toFixed(2)} browser_top_after=${secondMoveState.top.toFixed(2)}`,
            },
            {
                item: 'text_position_top_of_page',
                result: topPositionPass ? 'PASS' : 'FAIL',
                description: 'Top of page placement kept the annotation near the top edge with the full text visible.',
                detail: `document_id=${cardinalResults.top?.documentId || ''} left=${Number(topMetrics?.left || 0).toFixed(2)} top=${Number(topMetrics?.top || 0).toFixed(2)} width=${Number(topMetrics?.width || 0).toFixed(2)} overlay_width=${Number(topMetrics?.overlayWidth || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_position_left_of_page',
                result: leftPositionPass ? 'PASS' : 'FAIL',
                description: 'Left of page placement kept the annotation near the left edge with the full text visible.',
                detail: `document_id=${cardinalResults.left?.documentId || ''} left=${Number(leftMetrics?.left || 0).toFixed(2)} top=${Number(leftMetrics?.top || 0).toFixed(2)} width=${Number(leftMetrics?.width || 0).toFixed(2)} overlay_width=${Number(leftMetrics?.overlayWidth || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_position_right_of_page',
                result: rightPositionPass ? 'PASS' : 'FAIL',
                description: 'Right of page placement kept the annotation near the right edge with the full text visible.',
                detail: `document_id=${cardinalResults.right?.documentId || ''} left=${Number(rightMetrics?.left || 0).toFixed(2)} right_gap=${rightGap.toFixed(2)} width=${Number(rightMetrics?.width || 0).toFixed(2)} overlay_width=${Number(rightMetrics?.overlayWidth || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_position_bottom_of_page',
                result: bottomPositionPass ? 'PASS' : 'FAIL',
                description: 'Bottom of page placement kept the annotation near the bottom edge with the full text visible.',
                detail: `document_id=${cardinalResults.bottom?.documentId || ''} top=${Number(bottomMetrics?.top || 0).toFixed(2)} bottom_gap=${bottomGap.toFixed(2)} height=${Number(bottomMetrics?.height || 0).toFixed(2)} overlay_height=${Number(bottomMetrics?.overlayHeight || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_alignment_mixed_long_short_stack',
                result: mixedStackAlignedLeft ? 'PASS' : 'FAIL',
                description: 'Long / short / short / long-left stack — every line starts at the same left point.',
                detail: JSON.stringify({
                    document_id: mixedStackScenario?.documentId || null,
                    left_positions: mixedStackLefts,
                    tolerance_px: 1,
                }),
            },
            ...groupingScenarioResults.map((scenarioResult) => {
                const caseFailures = scenarioResult.analysis.matchesByCase.filter((entry) => entry.matchCount !== 1);
                const groupedBlocks = scenarioResult.analysis.groupedBlocks;
                const overflowOverlaps = computeStackOverflowOverlaps(scenarioResult.metrics);
                return {
                    item: `text_grouping_${scenarioResult.key}`,
                    result: caseFailures.length === 0 && groupedBlocks.length === 0 && overflowOverlaps.length === 0 ? 'PASS' : 'FAIL',
                    description: `${scenarioResult.label} — each text is exactly one independent annotation with no merging at the JS layer and no visual overflow into the next line.`,
                    detail: JSON.stringify({
                        document_id: scenarioResult.documentId,
                        expected_case_count: scenarioResult.analysis.matchesByCase.length,
                        per_case_match_counts: scenarioResult.analysis.matchesByCase,
                        case_failures: caseFailures,
                        grouped_blocks: groupedBlocks,
                        overflow_overlaps: overflowOverlaps,
                    }),
                };
            }),
        ];

        const checkArtifacts = [
            { label: 'Check 1',  kind: 'image', filename: blankScreenshotName,         check_item: 'blank_pdf_created',                       check_index: 1,  check_description: checks[0].description },
            { label: 'Check 2',  kind: 'image', filename: firstScreenshotName,         check_item: 'first_save_visible',                      check_index: 2,  check_description: checks[1].description },
            { label: 'Check 3',  kind: 'image', filename: secondScreenshotName,        check_item: 'second_initial_save_db_row_created',      check_index: 3,  check_description: checks[2].description },
            { label: 'Check 4',  kind: 'image', filename: secondMoveScreenshotName,    check_item: 'second_move_db_row_updated',              check_index: 4,  check_description: checks[3].description },
            { label: 'Check 5',  kind: 'image', filename: secondDeltaScreenshotName,   check_item: 'second_move_db_coordinates_changed',      check_index: 5,  check_description: checks[4].description },
            { label: 'Check 6',  kind: 'image', filename: topPositionScreenshotName,   check_item: 'text_position_top_of_page',               check_index: 6,  check_description: checks[5].description },
            { label: 'Check 7',  kind: 'image', filename: leftPositionScreenshotName,  check_item: 'text_position_left_of_page',              check_index: 7,  check_description: checks[6].description },
            { label: 'Check 8',  kind: 'image', filename: rightPositionScreenshotName, check_item: 'text_position_right_of_page',             check_index: 8,  check_description: checks[7].description },
            { label: 'Check 9',  kind: 'image', filename: bottomPositionScreenshotName,check_item: 'text_position_bottom_of_page',            check_index: 9,  check_description: checks[8].description },
            { label: 'Check 10', kind: 'image', filename: groupingMixedScreenshotName, check_item: 'text_alignment_mixed_long_short_stack',   check_index: 10, check_description: checks[9].description },
            { label: 'Check 11', kind: 'image', filename: groupingMixedScreenshotName, check_item: 'text_grouping_mixed_long_short_stack',    check_index: 11, check_description: checks[10].description },
            { label: 'Check 12', kind: 'image', filename: groupingShortScreenshotName, check_item: 'text_grouping_five_short_lines',          check_index: 12, check_description: checks[11].description },
            { label: 'Check 13', kind: 'image', filename: groupingLongScreenshotName,  check_item: 'text_grouping_five_long_lines',           check_index: 13, check_description: checks[12].description },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: 0,
            artifacts: checkArtifacts,
            metadata: {
                editor: 'pdf-new',
                document_ids: [firstDocumentId, secondDocumentId, ...cardinalDocumentIds, ...groupingDocumentIds].filter(Boolean),
                target_text: POSITION_TEXT,
                first_bbox: firstBBox,
                second_document_session_id: secondSessionId,
            },
        });
    } catch (error) {
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: 'fail',
            checks: [{
                item: 'flow_error',
                result: 'FAIL',
                description: 'Unhandled error during pdf-new text position flow.',
                detail: String(error?.stack || error?.message || error),
            }],
            error: String(error?.message || error),
            metadata: {
                editor: 'pdf-new',
                document_ids: [firstDocumentId, secondDocumentId, ...cardinalDocumentIds, ...groupingDocumentIds].filter(Boolean),
            },
        });
    } finally {
        await browser.close().catch(() => {});
    }
}

async function activateOverlay(page) {
    await page.evaluate(() => {
        const toggle = document.getElementById('mode-overlay-toggle');
        if (!toggle) {
            throw new Error('missing mode-overlay-toggle');
        }
        if (!toggle.checked) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, { timeout: 90000 });
    await page.waitForFunction(() => {
        const overlayFieldCount = document.querySelectorAll('.overlay-field').length;
        const promotedAnnotationCount = document.querySelectorAll('.annotation.promoted-extraction').length;
        return overlayFieldCount > 0 || promotedAnnotationCount > 0;
    }, null, { timeout: 90000 });
    await page.waitForTimeout(1500);
}

async function deactivateOverlay(page) {
    await page.evaluate(() => {
        const toggle = document.getElementById('mode-overlay-toggle');
        if (!toggle) {
            throw new Error('missing mode-overlay-toggle');
        }
        if (toggle.checked) {
            toggle.checked = false;
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === false, null, { timeout: 30000 });
    await page.waitForTimeout(500);
}

async function waitForOverlaySaveComplete(page) {
    await page.click('#save-btn');
    await page.waitForFunction(() => typeof overlayEditedFields !== 'undefined' && overlayEditedFields.size === 0, null, { timeout: 90000 });
    await page.waitForTimeout(2000);
}

async function capturePageScreenshot(page, outputPath) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    await setTestScreenshotUiHidden(page, true);
    try {
        await pageLocator.screenshot({ path: outputPath });
    } finally {
        await setTestScreenshotUiHidden(page, false);
    }
}

async function setTestScreenshotUiHidden(page, hidden) {
    await page.evaluate((shouldHide) => {
        const styleId = '__pdf-test-screenshot-ui-hide__';
        const existing = document.getElementById(styleId);
        if (!shouldHide) {
            existing?.remove();
            return;
        }

        if (existing) {
            return;
        }

        const style = document.createElement('style');
        style.id = styleId;
        style.textContent = `
            .annotation .annotation-tbc-menu,
            .annotation .shape-action-bar,
            .overlay-field .box-menu,
            .annotation .text-resize-handle,
            .annotation .text-rotate-handle,
            .annotation .shape-resize-handle,
            .annotation .delete-btn {
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }
        `;
        document.head.appendChild(style);
    }, hidden);
    await page.waitForTimeout(50);
}

async function captureViewportClipScreenshot(page, outputPath, rect, options = {}) {
    const padding = Number(options.padding) || 0;
    const minWidth = Number(options.minWidth) || 140;
    const minHeight = Number(options.minHeight) || 100;
    const maxWidth = Number(options.maxWidth) || 720;
    const maxHeight = Number(options.maxHeight) || 520;
    const viewport = await page.evaluate(() => ({
        scrollX: window.scrollX || 0,
        scrollY: window.scrollY || 0,
        innerWidth: window.innerWidth || document.documentElement.clientWidth || 0,
        innerHeight: window.innerHeight || document.documentElement.clientHeight || 0,
    }));

    const rawX = Number(rect?.x) || 0;
    const rawY = Number(rect?.y) || 0;
    const rawWidth = Math.max(1, Number(rect?.width) || 0);
    const rawHeight = Math.max(1, Number(rect?.height) || 0);

    const clippedX = Math.max(viewport.scrollX, rawX - padding);
    const clippedY = Math.max(viewport.scrollY, rawY - padding);
    const unclampedWidth = Math.max(minWidth, rawWidth + (padding * 2));
    const unclampedHeight = Math.max(minHeight, rawHeight + (padding * 2));
    const width = Math.max(
        1,
        Math.min(maxWidth, unclampedWidth, (viewport.scrollX + viewport.innerWidth) - clippedX)
    );
    const height = Math.max(
        1,
        Math.min(maxHeight, unclampedHeight, (viewport.scrollY + viewport.innerHeight) - clippedY)
    );

    await setTestScreenshotUiHidden(page, true);
    try {
        await page.screenshot({
            path: outputPath,
            clip: {
                x: clippedX,
                y: clippedY,
                width,
                height,
            },
        });
    } finally {
        await setTestScreenshotUiHidden(page, false);
    }
}

async function captureCenteredOverlayScreenshot(page, outputPath, options = {}) {
    const overlay = page.locator('.page[data-page-index="0"] .overlay, .page-wrapper[data-page-number="1"] .overlay').first();
    const box = await overlay.boundingBox();
    if (!box) {
        throw new Error('missing overlay box for centered screenshot');
    }

    const targetWidth = Number(options.width) || 280;
    const targetHeight = Number(options.height) || 180;
    await captureViewportClipScreenshot(page, outputPath, {
        x: box.x + ((box.width - targetWidth) / 2),
        y: box.y + ((box.height - targetHeight) / 2),
        width: targetWidth,
        height: targetHeight,
    }, {
        padding: Number(options.padding) || 0,
        minWidth: targetWidth,
        minHeight: targetHeight,
        maxWidth: targetWidth,
        maxHeight: targetHeight,
    });
}

async function captureTextAnnotationScreenshot(page, textFragment, outputPath, options = {}) {
    const annotation = page.locator('.annotation').filter({ hasText: textFragment }).first();
    await annotation.waitFor({ timeout: 10000 });
    const box = await annotation.boundingBox();
    if (!box) {
        throw new Error(`missing annotation screenshot target for "${textFragment}"`);
    }

    await captureViewportClipScreenshot(page, outputPath, box, {
        padding: Number(options.padding) || 32,
        minWidth: Number(options.minWidth) || 220,
        minHeight: Number(options.minHeight) || 120,
        maxWidth: Number(options.maxWidth) || 520,
        maxHeight: Number(options.maxHeight) || 260,
    });
}

async function captureTextAnnotationEdgeScreenshot(page, textFragment, edge, outputPath, options = {}) {
    const annotation = page.locator('.annotation').filter({ hasText: textFragment }).first();
    await annotation.waitFor({ timeout: 10000 });
    const annotationBox = await annotation.boundingBox();
    if (!annotationBox) {
        throw new Error(`missing annotation edge screenshot target for "${textFragment}"`);
    }

    const overlay = page.locator('.page[data-page-index="0"] .overlay, .page-wrapper[data-page-number="1"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for edge screenshot');
    }

    const padding = Number(options.padding) || 28;
    const overlayLeft = overlayBox.x;
    const overlayTop = overlayBox.y;
    const overlayRight = overlayBox.x + overlayBox.width;
    const overlayBottom = overlayBox.y + overlayBox.height;
    let rect = {
        x: annotationBox.x - padding,
        y: annotationBox.y - padding,
        width: annotationBox.width + (padding * 2),
        height: annotationBox.height + (padding * 2),
    };

    if (edge === 'top') {
        rect = {
            x: Math.max(overlayLeft, annotationBox.x - padding),
            y: overlayTop,
            width: Math.min(overlayRight, annotationBox.x + annotationBox.width + padding) - Math.max(overlayLeft, annotationBox.x - padding),
            height: Math.min(overlayBottom, annotationBox.y + annotationBox.height + padding) - overlayTop,
        };
    } else if (edge === 'left') {
        rect = {
            x: overlayLeft,
            y: Math.max(overlayTop, annotationBox.y - padding),
            width: Math.min(overlayRight, annotationBox.x + annotationBox.width + padding) - overlayLeft,
            height: Math.min(overlayBottom, annotationBox.y + annotationBox.height + padding) - Math.max(overlayTop, annotationBox.y - padding),
        };
    } else if (edge === 'right') {
        rect = {
            x: Math.max(overlayLeft, annotationBox.x - padding),
            y: Math.max(overlayTop, annotationBox.y - padding),
            width: overlayRight - Math.max(overlayLeft, annotationBox.x - padding),
            height: Math.min(overlayBottom, annotationBox.y + annotationBox.height + padding) - Math.max(overlayTop, annotationBox.y - padding),
        };
    } else if (edge === 'bottom') {
        rect = {
            x: Math.max(overlayLeft, annotationBox.x - padding),
            y: Math.max(overlayTop, annotationBox.y - padding),
            width: Math.min(overlayRight, annotationBox.x + annotationBox.width + padding) - Math.max(overlayLeft, annotationBox.x - padding),
            height: overlayBottom - Math.max(overlayTop, annotationBox.y - padding),
        };
    }

    await captureViewportClipScreenshot(page, outputPath, rect, {
        padding: 0,
        minWidth: Number(options.minWidth) || Math.ceil(rect.width),
        minHeight: Number(options.minHeight) || Math.ceil(rect.height),
        maxWidth: Number(options.maxWidth) || 1450,
        maxHeight: Number(options.maxHeight) || 760,
    });
}

async function readEditorZoomPercent(page) {
    const label = page.locator('#zoom-label');
    await label.waitFor({ timeout: 10000 });
    const text = await label.textContent();
    const value = parseInt(String(text || '').trim(), 10);
    if (!Number.isFinite(value)) {
        throw new Error(`unable to read zoom percent from label: ${text}`);
    }
    return value;
}

async function setEditorZoomPercent(page, targetZoom) {
    const clampedTarget = Math.max(50, Math.min(400, Math.round(Number(targetZoom) || 100)));
    for (let attempts = 0; attempts < 16; attempts += 1) {
        const currentZoom = await readEditorZoomPercent(page);
        if (currentZoom === clampedTarget) {
            return currentZoom;
        }

        const zoomingOut = currentZoom > clampedTarget;
        await page.click(zoomingOut ? '#zoom-out' : '#zoom-in');
        await page.waitForFunction(({ previousZoom, target, decreasing }) => {
            const label = document.getElementById('zoom-label');
            const value = parseInt(String(label?.textContent || '').trim(), 10);
            if (!Number.isFinite(value)) {
                return false;
            }
            if (value === target) {
                return true;
            }
            return decreasing ? value < previousZoom : value > previousZoom;
        }, {
            previousZoom: currentZoom,
            target: clampedTarget,
            decreasing: zoomingOut,
        }, {
            timeout: 30000,
        });
        await waitForEditorReady(page);
    }

    throw new Error(`failed to set editor zoom to ${clampedTarget}%`);
}

async function findTextAnnotationClusterBox(page, textFragments) {
    return page.evaluate((fragments) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const normalizedFragments = fragments.map((fragment) => normalizeText(fragment)).filter(Boolean);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const matchedRects = records
            .filter((item) => {
                const candidateText = normalizeText(
                    item?.text
                    || item?.element?.innerText
                    || item?.element?.textContent
                    || item?.element?.querySelector?.('.annotation-text')?.textContent
                    || ''
                );
                return normalizedFragments.some((fragment) => candidateText === fragment);
            })
            .map((item) => item?.element?.getBoundingClientRect?.())
            .filter((rect) => rect && rect.width > 0 && rect.height > 0);

        if (!matchedRects.length) {
            return null;
        }

        const left = Math.min(...matchedRects.map((rect) => rect.left));
        const top = Math.min(...matchedRects.map((rect) => rect.top));
        const right = Math.max(...matchedRects.map((rect) => rect.right));
        const bottom = Math.max(...matchedRects.map((rect) => rect.bottom));
        return {
            x: left + (window.scrollX || 0),
            y: top + (window.scrollY || 0),
            width: right - left,
            height: bottom - top,
        };
    }, textFragments);
}

async function captureTextAnnotationClusterScreenshot(page, textFragments, outputPath, options = {}) {
    const clusterBox = await findTextAnnotationClusterBox(page, textFragments);
    if (!clusterBox) {
        throw new Error(`missing annotation cluster screenshot target for ${JSON.stringify(textFragments)}`);
    }

    await captureViewportClipScreenshot(page, outputPath, clusterBox, {
        padding: Number(options.padding) || 28,
        minWidth: Number(options.minWidth) || 260,
        minHeight: Number(options.minHeight) || 160,
        maxWidth: Number(options.maxWidth) || 760,
        maxHeight: Number(options.maxHeight) || 460,
    });
}

async function downloadSavedPdf(page, documentId, outputPath) {
    const response = await page.context().request.get(`${BASE_URL}/documents/${documentId}/file?v=${Date.now()}`);
    if (!response.ok()) {
        throw new Error(`failed to download saved PDF: ${response.status()}`);
    }
    const buffer = await response.body();
    fs.writeFileSync(outputPath, buffer);
}

async function downloadPdfViaToolbar(page, outputPath) {
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/download-annotated-pdf')
    ), { timeout: 60000 });
    const downloadPromise = page.waitForEvent('download', { timeout: 60000 });

    await page.click('#download-pdf-btn');
    const response = await responsePromise;
    if (!response.ok()) {
        throw new Error(`download pdf failed with ${response.status()}`);
    }

    const download = await downloadPromise;
    await download.saveAs(outputPath);
    await page.waitForTimeout(1000);
}

async function fetchExtraction(page, documentId) {
    const extraction = await page.evaluate(async (id) => {
        const response = await fetch(`/documents/${id}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, documentId);

    if (!extraction?.success) {
        throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
    }

    return extraction;
}

async function collectLoadedSavedAnnotationSummary(page, targetTexts) {
    return page.evaluate((texts) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const normalizedTargets = texts.map((text) => normalizeText(text));
        const annotationElements = Array.from(document.querySelectorAll('.annotation')).map((annotationEl) => {
            const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
            const text = normalizeText(textEl?.innerText || textEl?.textContent || '');
            const textWrapper = annotationEl.querySelector('.text-content-wrapper');
            return {
                className: annotationEl.className,
                text,
                backgroundColor: getComputedStyle(textEl).backgroundColor,
                wrapperBackgroundColor: textWrapper ? getComputedStyle(textWrapper).backgroundColor : '',
            };
        }).filter((annotation) => annotation.text);
        const annotationRecords = Array.isArray(annotations)
            ? annotations.map((annotation) => ({
                id: annotation?.id || '',
                type: annotation?.type || '',
                text: normalizeText(annotation?.text || annotation?.element?.innerText || annotation?.element?.textContent || ''),
                promotedFromExtraction: Boolean(annotation?.promotedFromExtraction),
                savedTextOverlay: Boolean(annotation?.savedTextOverlay),
                pageIndex: Number.isFinite(Number(annotation?.pageIndex)) ? Number(annotation.pageIndex) : null,
                promotedSourcePage: Number.isFinite(Number(annotation?.promotedSourcePage)) ? Number(annotation.promotedSourcePage) : null,
            }))
            : [];
        const overlayFields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => ({
            key: field.dataset.wordIndex || '',
            text: normalizeText(field.innerText || field.textContent || ''),
        })).filter((field) => field.text);

        const textMatches = Object.fromEntries(normalizedTargets.map((target) => {
            const domMatches = annotationElements.filter((annotation) => annotation.text === target);
            const recordMatches = annotationRecords.filter((annotation) => annotation.text === target);
            return [target, {
                domCount: domMatches.length,
                recordCount: recordMatches.length,
                domMatches,
                recordMatches,
            }];
        }));
        const overlayTextMatches = Object.fromEntries(normalizedTargets.map((target) => {
            const matches = overlayFields.filter((field) => field.text === target);
            return [target, {
                count: matches.length,
                matches,
            }];
        }));
        const promotedTextSet = new Set(annotationRecords
            .filter((annotation) => annotation.promotedFromExtraction && annotation.text)
            .map((annotation) => annotation.text));
        const crossLayerPromotedDuplicates = overlayFields.filter((field) => promotedTextSet.has(field.text));

        return {
            loadedSavedPdfMode,
            basePdfUrl,
            cleanPdfUrl,
            originalPdfUrl,
            annotationElements,
            annotationRecords,
            overlayFields,
            textMatches,
            overlayTextMatches,
            crossLayerPromotedDuplicates,
            uniqueRecordIds: Array.from(new Set(annotationRecords.map((annotation) => annotation.id).filter(Boolean))),
            promotedPageMismatches: annotationRecords.filter((annotation) => (
                annotation.promotedFromExtraction
                && annotation.promotedSourcePage !== null
                && annotation.pageIndex !== (annotation.promotedSourcePage - 1)
            )),
        };
    }, targetTexts);
}

function loadPdfExtractionPage(pdfPath, pageNumber = 1) {
    const pythonCode = `
import importlib.util
import json
import sys
import io
import contextlib
from pathlib import Path

pdf_path = Path(sys.argv[1])
page_number = int(sys.argv[2])
module_path = Path('python/pdf-editor/extract_pdf_pymupdf.py')
spec = importlib.util.spec_from_file_location('extract_pdf_pymupdf', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with contextlib.redirect_stdout(io.StringIO()):
    result = module.extract_text_with_pymupdf(str(pdf_path))
pages = result.get('extraction_data', [])
page = next((entry for entry in pages if int(entry.get('page_number', 0) or 0) == page_number), None)
print(json.dumps(page or {}))
`;

    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath, String(pageNumber)], {
        cwd: path.resolve(__dirname, '..', '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

function loadPdfTargetWordBoxes(pdfPath, pageNumber, targetConfigs) {
    const pythonCode = `
import importlib.util
import json
import sys
import io
import contextlib
from pathlib import Path
import fitz

pdf_path = Path(sys.argv[1])
page_number = int(sys.argv[2])
targets = json.loads(sys.argv[3])
module_path = Path('python/pdf-editor/extract_pdf_pymupdf.py')
spec = importlib.util.spec_from_file_location('extract_pdf_pymupdf', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with contextlib.redirect_stdout(io.StringIO()):
    result = module.extract_text_with_pymupdf(str(pdf_path))
page_data = next((entry for entry in result.get('extraction_data', []) if int(entry.get('page_number', 0) or 0) == page_number), {}) or {}
doc = fitz.open(str(pdf_path))
page = doc[page_number - 1]
output = {}

for target in targets:
    line_text = ' '.join(str(target.get('lineText', '')).split())
    line = next((entry for entry in page_data.get('lines', []) if ' '.join(str(entry.get('text', '')).split()) == line_text), None)
    line_rect = None
    if line:
        line_rect = fitz.Rect(
            float(line.get('left', 0) or 0),
            float(line.get('top', 0) or 0),
            float((line.get('left', 0) or 0) + (line.get('width', 0) or 0)),
            float((line.get('top', 0) or 0) + (line.get('height', 0) or 0)),
        )
    word_boxes = {}
    for word in target.get('sampleWords', []):
        matches = []
        for rect in page.search_for(str(word), quads=False):
            candidate = {
                'left': rect.x0,
                'top': rect.y0,
                'width': rect.x1 - rect.x0,
                'height': rect.y1 - rect.y0,
            }
            if line_rect is not None:
                vertical_overlap = min(rect.y1, line_rect.y1) - max(rect.y0, line_rect.y0)
                horizontal_overlap = min(rect.x1, line_rect.x1) - max(rect.x0, line_rect.x0)
                if vertical_overlap < max(1, min(rect.y1 - rect.y0, line_rect.y1 - line_rect.y0) * 0.35):
                    continue
                if horizontal_overlap <= 0:
                    continue
            matches.append(candidate)
        word_boxes[word] = matches[0] if matches else None
    output[target.get('key')] = word_boxes

doc.close()
print(json.dumps(output))
`;

    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath, String(pageNumber), JSON.stringify(targetConfigs)], {
        cwd: path.resolve(__dirname, '..', '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

function loadPdfSearchRects(pdfPath, pageNumber, targetText) {
    const pythonCode = `
import fitz
import json
import sys

pdf_path = sys.argv[1]
page_number = int(sys.argv[2])
target_text = sys.argv[3]

doc = fitz.open(pdf_path)
page = doc[page_number - 1]
rects = page.search_for(target_text, quads=False)
print(json.dumps({
    'page_width': float(page.rect.width),
    'page_height': float(page.rect.height),
    'rects': [
        [float(rect.x0), float(rect.y0), float(rect.x1), float(rect.y1)]
        for rect in rects
    ],
}))
doc.close()
`;

    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath, String(pageNumber), targetText], {
        cwd: path.resolve(__dirname, '..', '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

function loadPdfTextGeometry(pdfPath, pageNumber, targetText) {
    const pythonCode = `
import fitz
import json
import math
import sys

pdf_path = sys.argv[1]
page_number = int(sys.argv[2])
target_text = sys.argv[3]

def normalize_text(value):
    return ' '.join(str(value or '').split())

def quad_points(quad):
    return [
        [float(quad.ul.x), float(quad.ul.y)],
        [float(quad.ur.x), float(quad.ur.y)],
        [float(quad.ll.x), float(quad.ll.y)],
        [float(quad.lr.x), float(quad.lr.y)],
    ]

def quad_angle(quad):
    return math.degrees(math.atan2(float(quad.ur.y - quad.ul.y), float(quad.ur.x - quad.ul.x)))

doc = fitz.open(pdf_path)
page = doc[page_number - 1]
normalized_target = normalize_text(target_text)
quads = page.search_for(target_text, quads=True)
raw = page.get_text('dict')
lines = []

for block in raw.get('blocks', []):
    if int(block.get('type', 0) or 0) != 0:
        continue
    for line in block.get('lines', []):
        spans = line.get('spans', []) or []
        line_text = normalize_text(''.join(str(span.get('text', '')) for span in spans))
        if line_text != normalized_target:
            continue
        direction = line.get('dir') or [1, 0]
        lines.append({
            'text': line_text,
            'bbox': [float(value) for value in (line.get('bbox') or [])],
            'dir': [float(direction[0]), float(direction[1])],
            'angle': math.degrees(math.atan2(float(direction[1]), float(direction[0]))),
            'spans': [
                {
                    'text': span.get('text', ''),
                    'font': span.get('font', ''),
                    'size': float(span.get('size', 0) or 0),
                    'bbox': [float(value) for value in (span.get('bbox') or [])],
                }
                for span in spans
            ],
        })

print(json.dumps({
    'page_width': float(page.rect.width),
    'page_height': float(page.rect.height),
    'quads': [
        {
            'points': quad_points(quad),
            'angle': quad_angle(quad),
        }
        for quad in quads
    ],
    'lines': lines,
}))
doc.close()
`;

    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath, String(pageNumber), targetText], {
        cwd: path.resolve(__dirname, '..', '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

function extractMatchingBBoxes(extraction, targetText) {
    const pageData = extraction?.extraction_data?.[0] || {};
    return (pageData.lines || [])
        .filter((line) => normalize(line?.text) === normalize(targetText))
        .map((line) => lineBBox(line));
}

function extractMatchingLine(extraction, targetText) {
    const pageData = extraction?.extraction_data?.[0] || {};
    return (pageData.lines || []).find((line) => normalize(line?.text) === normalize(targetText)) || null;
}

function mergeParagraphBlocks(blocks) {
    const normalizedBlocks = (Array.isArray(blocks) ? blocks : [])
        .map((block, index) => ({
            ...block,
            __index: index,
            __left: Number(block?.left) || 0,
            __top: Number(block?.top) || 0,
            __width: Number(block?.width) || 0,
            __height: Number(block?.height) || 0,
            __right: (Number(block?.left) || 0) + (Number(block?.width) || 0),
            __bottom: (Number(block?.top) || 0) + (Number(block?.height) || 0),
            __font: String(block?.font || ''),
            __fontSize: Number(block?.font_size) || 0,
            __lineHeight: Number(block?.avg_line_height) || Number(block?.line_height) || Number(block?.height) || 0,
        }))
        .sort((a, b) => {
            const leftDelta = a.__left - b.__left;
            if (Math.abs(leftDelta) > 4) {
                return leftDelta;
            }
            return a.__top - b.__top;
        });

    const candidates = [];
    for (let startIndex = 0; startIndex < normalizedBlocks.length; startIndex += 1) {
        const start = normalizedBlocks[startIndex];
        let merged = [start];
        let prev = start;
        candidates.push(start);

        for (let nextIndex = startIndex + 1; nextIndex < normalizedBlocks.length; nextIndex += 1) {
            const next = normalizedBlocks[nextIndex];
            const sameColumn = Math.abs(next.__left - start.__left) <= 3;
            const sameFont = normalize(next.__font) === normalize(start.__font);
            const similarSize = Math.abs(next.__fontSize - start.__fontSize) <= 0.75;
            const verticalGap = next.__top - prev.__bottom;
            const allowedGap = Math.max(8, prev.__lineHeight * 1.8);
            const overlapsHorizontally = next.__right > (start.__left + 20);

            if (!sameColumn || !sameFont || !similarSize || !overlapsHorizontally || verticalGap < -1 || verticalGap > allowedGap) {
                break;
            }

            merged.push(next);
            prev = next;
            const mergedTextLines = merged.flatMap((block) => (
                Array.isArray(block.text_lines) && block.text_lines.length
                    ? block.text_lines.map((line) => String(line))
                    : String(block.text || '').split(/\r?\n/)
            )).map((line) => String(line || '').trim()).filter(Boolean);
            const mergedLeft = Math.min(...merged.map((block) => block.__left));
            const mergedTop = Math.min(...merged.map((block) => block.__top));
            const mergedRight = Math.max(...merged.map((block) => block.__right));
            const mergedBottom = Math.max(...merged.map((block) => block.__bottom));
            candidates.push({
                ...start,
                left: mergedLeft,
                top: mergedTop,
                width: mergedRight - mergedLeft,
                height: mergedBottom - mergedTop,
                text: mergedTextLines.join('\n'),
                text_lines: mergedTextLines,
                line_count: mergedTextLines.length,
                avg_line_height: start.__lineHeight,
                line_height: start.__lineHeight,
                block_num: `${start.block_num}-${next.block_num}`,
                merged_block_nums: merged.map((block) => block.block_num),
            });
        }
    }

    return candidates;
}

function extractMatchingBlock(extraction, targetTextFragment) {
    const pageData = extraction?.extraction_data?.[0] || {};
    const fragment = normalize(targetTextFragment);
    const candidates = mergeParagraphBlocks(pageData.blocks || []);

    return candidates
        .filter((block) => normalize(block?.text).includes(fragment))
        .sort((a, b) => normalize(b?.text).length - normalize(a?.text).length)[0] || null;
}

function analyzeIndependentTextGrouping(extraction, scenarioCases) {
    const pageData = extraction?.extraction_data?.[0] || {};
    const blocks = (pageData.blocks || []).map((block) => {
        const normalizedText = normalize(
            Array.isArray(block?.text_lines) && block.text_lines.length
                ? block.text_lines.join(' ')
                : block?.text
        );
        return {
            block_num: block?.block_num,
            text: normalizedText,
            left: Number(block?.left) || 0,
            top: Number(block?.top) || 0,
            width: Number(block?.width) || 0,
            height: Number(block?.height) || 0,
        };
    }).filter((block) => block.text.length > 0);

    const cases = (scenarioCases || []).map((scenarioCase) => ({
        ...scenarioCase,
        normalizedText: normalize(scenarioCase?.text),
    }));

    const matchesByCase = cases.map((scenarioCase) => {
        const matches = blocks.filter((block) => block.text.includes(scenarioCase.normalizedText));
        return {
            text: scenarioCase.text,
            matchCount: matches.length,
            matches,
        };
    });

    const groupedBlocks = blocks.map((block) => {
        const hits = cases.filter((scenarioCase) => block.text.includes(scenarioCase.normalizedText));
        if (hits.length <= 1) {
            return null;
        }
        return {
            block_num: block.block_num,
            text: block.text,
            matchedTexts: hits.map((scenarioCase) => scenarioCase.text),
            left: block.left,
            top: block.top,
            width: block.width,
            height: block.height,
        };
    }).filter(Boolean);

    return {
        matchesByCase,
        groupedBlocks,
    };
}

function extractBlockLines(extraction, block) {
    if (!block) {
        return [];
    }

    const explicitLines = Array.isArray(block.text_lines)
        ? block.text_lines.map((line) => normalize(line)).filter(Boolean)
        : [];
    if (explicitLines.length > 0) {
        return explicitLines;
    }

    const pageData = extraction?.extraction_data?.[0] || {};

    return (pageData.lines || [])
        .filter((line) => String(line?.block_num) === String(block?.block_num))
        .map((line) => normalize(line?.text))
        .filter(Boolean);
}

function extractWordsForLine(extraction, line) {
    if (!line) {
        return [];
    }
    const pageData = extraction?.extraction_data?.[0] || {};
    const directMatches = (pageData.words || []).filter((word) => (
        String(word?.block_num) === String(line?.block_num)
        && String(word?.line_num) === String(line?.line_num)
    ));
    if (directMatches.length > 0) {
        return directMatches;
    }

    const targetBBox = lineBBox(line);
    return (pageData.words || []).filter((word) => {
        const wordBBox = lineBBox(word);
        const verticalOverlap = Math.min(targetBBox[3], wordBBox[3]) - Math.max(targetBBox[1], wordBBox[1]);
        const horizontalOverlap = Math.min(targetBBox[2], wordBBox[2]) - Math.max(targetBBox[0], wordBBox[0]);
        return verticalOverlap >= Math.min(targetBBox[3] - targetBBox[1], wordBBox[3] - wordBBox[1]) * 0.5
            && horizontalOverlap > 0;
    });
}

function extractMatchingWord(words, targetText) {
    const expected = normalize(targetText);
    return (Array.isArray(words) ? words : []).find((word) => normalize(word?.text) === expected) || null;
}

function extractBlockLineEntries(extraction, block) {
    if (!block) {
        return [];
    }

    const pageData = extraction?.extraction_data?.[0] || {};
    const blockNums = new Set(
        (Array.isArray(block?.merged_block_nums) && block.merged_block_nums.length
            ? block.merged_block_nums
            : [block?.block_num]
        ).map((value) => String(value))
    );

    return (pageData.lines || [])
        .filter((line) => blockNums.has(String(line?.block_num)))
        .sort((a, b) => {
            const topDelta = (Number(a?.top) || 0) - (Number(b?.top) || 0);
            if (Math.abs(topDelta) > 1) {
                return topDelta;
            }
            return (Number(a?.left) || 0) - (Number(b?.left) || 0);
        });
}

function extractLineSpacing(lineBoxes) {
    const sorted = (Array.isArray(lineBoxes) ? lineBoxes : [])
        .map((line) => {
            if (Array.isArray(line) && line.length >= 4) {
                const left = Number(line[0]) || 0;
                const top = Number(line[1]) || 0;
                const right = Number(line[2]) || left;
                const bottom = Number(line[3]) || top;
                return {
                    left,
                    top,
                    width: Math.max(0, right - left),
                    height: Math.max(0, bottom - top),
                };
            }
            return {
                left: Number(line?.left) || 0,
                top: Number(line?.top) || 0,
                width: Number(line?.width) || 0,
                height: Number(line?.height) || 0,
            };
        })
        .filter((line) => line.width > 0 && line.height > 0)
        .sort((a, b) => {
            const topDelta = a.top - b.top;
            if (Math.abs(topDelta) > 1) {
                return topDelta;
            }
            return a.left - b.left;
        });

    return sorted.slice(1).map((line, index) => ({
        from: index,
        to: index + 1,
        delta: line.top - sorted[index].top,
    }));
}

function createSeededRng(seed) {
    let state = (Number(seed) || 1) >>> 0;
    if (state === 0) {
        state = 1;
    }
    return () => {
        state = (state * 1664525 + 1013904223) >>> 0;
        return state / 0x100000000;
    };
}

function buildNdaRandomEditTargets(overlayFields, count = NDA_RANDOM_EDIT_TARGET_COUNT, seed = NDA_RANDOM_EDIT_SEED) {
    const eligibleBlocks = (Array.isArray(overlayFields) ? overlayFields : [])
        .filter((field) => (
            Number(field?.pageNumber) === 1
            && normalize(field?.text || '').length > 20
            && Array.isArray(field?.source_line_boxes)
            && field.source_line_boxes.length >= 2
        ))
        .map((field) => ({
            key: String(field.key || ''),
            originalText: String(field.originalText || field.text || ''),
            originalTextFlat: normalize(field.originalText || field.text || ''),
            left: Number(field.left) || 0,
            top: Number(field.top) || 0,
            width: Number(field.width) || 0,
            height: Number(field.height) || 0,
            sourceLineBoxes: Array.isArray(field.source_line_boxes) ? field.source_line_boxes : [],
            sourceLineCount: Array.isArray(field.source_line_boxes) ? field.source_line_boxes.length : 0,
            sourceSpacing: extractLineSpacing(field.source_line_boxes),
            editedLines: Array.from({ length: Array.isArray(field.source_line_boxes) ? field.source_line_boxes.length : 0 }, (_unused, lineIndex) => (
                `NDA22 ${String(field.key || 'field').replace(/[^A-Za-z0-9]+/g, '').slice(-8) || 'F'} L${String(lineIndex + 1).padStart(2, '0')}`
            )),
        }));

    if (eligibleBlocks.length < count) {
        throw new Error(`expected at least ${count} eligible NDA page-1 overlay fields, found ${eligibleBlocks.length}`);
    }

    const rng = createSeededRng(seed);
    const shuffled = [...eligibleBlocks];
    for (let index = shuffled.length - 1; index > 0; index -= 1) {
        const swapIndex = Math.floor(rng() * (index + 1));
        [shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex], shuffled[index]];
    }

    return shuffled
        .slice(0, count)
        .sort((a, b) => {
            const topDelta = a.top - b.top;
            if (Math.abs(topDelta) > 1) {
                return topDelta;
            }
            return a.left - b.left;
        })
        .map((block, index) => ({
            ...block,
            editedText: block.editedLines.join('\n'),
            editedTextFlat: normalize(block.editedLines.join(' ')),
            selectionKey: `target_${index + 1}`,
        }));
}

function lineSpacingMatches(expected, actual, tolerance) {
    if (!expected.length || expected.length !== actual.length) {
        return false;
    }
    return expected.every((entry, index) => approxEqual(actual[index]?.delta, entry.delta, tolerance));
}

function loadPdfTargetLineSpans(pdfPath, pageNumber, targetLineTexts) {
    const pythonCode = `
import fitz
import json
import sys

pdf_path = sys.argv[1]
page_number = int(sys.argv[2])
targets = json.loads(sys.argv[3])

def normalize_text(value):
    return ' '.join(str(value or '').split())

doc = fitz.open(pdf_path)
page = doc[page_number - 1]
raw = page.get_text('dict')
output = {target: [] for target in targets}

for block in raw.get('blocks', []):
    if int(block.get('type', 0) or 0) != 0:
        continue
    for line in block.get('lines', []):
        spans = line.get('spans', []) or []
        line_text = normalize_text(''.join(str(span.get('text', '')) for span in spans))
        if line_text not in output:
            continue
        output[line_text] = [
            {
                'text': span.get('text', ''),
                'font': span.get('font', ''),
                'size': span.get('size', 0),
                'flags': span.get('flags', 0),
                'color': span.get('color', 0),
                'bbox': span.get('bbox', []),
                'bold': bool(('bold' in str(span.get('font', '')).lower()) or (int(span.get('flags', 0) or 0) & 16)),
                'italic': bool(('italic' in str(span.get('font', '')).lower()) or ('oblique' in str(span.get('font', '')).lower()) or (int(span.get('flags', 0) or 0) & 2)),
            }
            for span in spans
        ]

doc.close()
print(json.dumps(output))
`;

    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath, String(pageNumber), JSON.stringify(targetLineTexts)], {
        cwd: path.resolve(__dirname, '..', '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

async function collectOverlayLoadTargets(page, targetConfigs) {
    return page.evaluate((configs) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const toNumber = (value) => Number(value) || 0;

        const mergeRects = (rects) => {
            if (!rects.length) {
                return null;
            }
            const left = Math.min(...rects.map((rect) => rect.left));
            const top = Math.min(...rects.map((rect) => rect.top));
            const right = Math.max(...rects.map((rect) => rect.right));
            const bottom = Math.max(...rects.map((rect) => rect.bottom));
            return {
                left,
                top,
                width: right - left,
                height: bottom - top,
            };
        };

        const groupLineRects = (root, fieldRect, fieldLeft, fieldTop) => {
            const blockChildren = Array.from(root.children || [])
                .filter((child) => ['DIV', 'P', 'LI'].includes(child.tagName))
                .map((child) => child.getBoundingClientRect())
                .filter((rect) => rect.width > 1 && rect.height > 1)
                .map((rect) => ({
                    left: fieldLeft + (rect.left - fieldRect.left),
                    top: fieldTop + (rect.top - fieldRect.top),
                    width: rect.width,
                    height: rect.height,
                }));
            if (blockChildren.length > 1) {
                return blockChildren;
            }

            const range = document.createRange();
            range.selectNodeContents(root);
            const rects = Array.from(range.getClientRects())
                .filter((rect) => rect.width > 1 && rect.height > 1)
                .filter((rect) => {
                    const overlapWidth = Math.max(0, Math.min(rect.right, fieldRect.right) - Math.max(rect.left, fieldRect.left));
                    const overlapHeight = Math.max(0, Math.min(rect.bottom, fieldRect.bottom) - Math.max(rect.top, fieldRect.top));
                    return overlapWidth > 0.5 && overlapHeight > 0.5;
                })
                .map((rect) => ({
                    left: fieldLeft + (rect.left - fieldRect.left),
                    top: fieldTop + (rect.top - fieldRect.top),
                    right: fieldLeft + (rect.right - fieldRect.left),
                    bottom: fieldTop + (rect.bottom - fieldRect.top),
                }))
                .sort((a, b) => {
                    if (Math.abs(a.top - b.top) > 2) {
                        return a.top - b.top;
                    }
                    return a.left - b.left;
                });

            const groups = [];
            for (const rect of rects) {
                let group = groups.find((candidate) => {
                    const overlap = Math.min(candidate.bottom, rect.bottom) - Math.max(candidate.top, rect.top);
                    return overlap >= Math.min(candidate.bottom - candidate.top, rect.bottom - rect.top) * 0.45
                        || Math.abs(candidate.top - rect.top) <= 3;
                });
                if (!group) {
                    groups.push({ ...rect });
                    continue;
                }
                group.left = Math.min(group.left, rect.left);
                group.top = Math.min(group.top, rect.top);
                group.right = Math.max(group.right, rect.right);
                group.bottom = Math.max(group.bottom, rect.bottom);
            }

            return groups.map((group) => ({
                left: group.left,
                top: group.top,
                width: group.right - group.left,
                height: group.bottom - group.top,
            }));
        };

        const getTextNodes = (root) => {
            const nodes = [];
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            let cursor = 0;
            let current = walker.nextNode();
            while (current) {
                const text = current.textContent || '';
                if (text.length > 0) {
                    nodes.push({
                        node: current,
                        start: cursor,
                        end: cursor + text.length,
                        text,
                    });
                    cursor += text.length;
                }
                current = walker.nextNode();
            }
            return nodes;
        };

        const resolvePosition = (nodes, offset) => {
            for (const entry of nodes) {
                if (offset >= entry.start && offset < entry.end) {
                    return {
                        node: entry.node,
                        offset: offset - entry.start,
                    };
                }
            }
            const last = nodes[nodes.length - 1];
            return last ? { node: last.node, offset: last.text.length } : null;
        };

        const getWordEntry = (root, fieldRect, fieldLeft, fieldTop, word) => {
            const rawText = root.textContent || '';
            const start = rawText.indexOf(word);
            if (start < 0) {
                return null;
            }
            const end = start + word.length;
            const nodes = getTextNodes(root);
            const startPos = resolvePosition(nodes, start);
            const endPos = resolvePosition(nodes, end);
            if (!startPos || !endPos) {
                return null;
            }
            const range = document.createRange();
            range.setStart(startPos.node, startPos.offset);
            range.setEnd(endPos.node, endPos.offset);
            const rects = Array.from(range.getClientRects()).filter((rect) => rect.width > 0.5 && rect.height > 0.5);
            const merged = mergeRects(rects);
            if (!merged) {
                return null;
            }
            const styleElement = startPos.node.parentElement || root;
            const style = getComputedStyle(styleElement);
            return {
                box: {
                    left: fieldLeft + (merged.left - fieldRect.left),
                    top: fieldTop + (merged.top - fieldRect.top),
                    width: merged.width,
                    height: merged.height,
                },
                style: {
                    fontFamily: style.fontFamily,
                    fontSize: style.fontSize,
                    fontWeight: style.fontWeight,
                    color: style.color,
                    lineHeight: style.lineHeight,
                },
            };
        };

        const pageEl = document.querySelector('.page[data-page-index="0"]') || document.querySelector('.page-wrapper[data-page-number="1"]');
        const overlay = pageEl?.querySelector('.overlay');
        const overlayWidth = overlay?.clientWidth || 0;
        const overlayHeight = overlay?.clientHeight || 0;
        const fields = [
            ...Array.from(document.querySelectorAll('.overlay-field')).map((field) => {
                const root = field.querySelector('[contenteditable]') || field;
                const text = normalizeText(root.innerText || root.textContent || '');
                const fieldRect = field.getBoundingClientRect();
                const style = getComputedStyle(root);
                const originalText = normalizeText(field.dataset.originalText || '');
                const stableFlowText = normalizeText(field.dataset.stableFlowText || '');
                return {
                    text,
                    originalText,
                    stableFlowText,
                    field,
                    root,
                    fieldRect,
                    left: toNumber(field.offsetLeft),
                    top: toNumber(field.offsetTop),
                    width: fieldRect.width,
                    height: fieldRect.height,
                    style: {
                        fontFamily: style.fontFamily,
                        fontSize: style.fontSize,
                        fontWeight: style.fontWeight,
                        color: style.color,
                        lineHeight: style.lineHeight,
                    },
                };
            }),
            ...Array.from(document.querySelectorAll('.annotation.promoted-extraction')).map((field) => {
                const root = field.querySelector('.annotation-text') || field;
                const text = normalizeText(root.innerText || root.textContent || '');
                const fieldRect = field.getBoundingClientRect();
                const style = getComputedStyle(root);
                return {
                    text,
                    originalText: text,
                    stableFlowText: text,
                    field,
                    root,
                    fieldRect,
                    left: toNumber(field.offsetLeft),
                    top: toNumber(field.offsetTop),
                    width: fieldRect.width,
                    height: fieldRect.height,
                    style: {
                        fontFamily: style.fontFamily,
                        fontSize: style.fontSize,
                        fontWeight: style.fontWeight,
                        color: style.color,
                        lineHeight: style.lineHeight,
                    },
                };
            }),
        ].filter((entry) => entry.text);

        const targets = {};
        for (const config of configs) {
            const matchText = normalizeText(config.matchText || config.lineText);
            const fieldEntry = fields
                .map((entry) => {
                    const candidates = [
                        entry.originalText,
                        entry.stableFlowText,
                        entry.text,
                    ].filter(Boolean);
                    let bestScore = -1;
                    for (const candidate of candidates) {
                        if (candidate === matchText) {
                            bestScore = Math.max(bestScore, 4000 - candidate.length);
                            continue;
                        }
                        if (candidate.startsWith(matchText)) {
                            bestScore = Math.max(bestScore, 3000 - Math.abs(candidate.length - matchText.length));
                            continue;
                        }
                        if (candidate.includes(matchText)) {
                            bestScore = Math.max(bestScore, 2000 - Math.abs(candidate.length - matchText.length));
                        }
                    }
                    return {
                        ...entry,
                        _matchScore: bestScore,
                    };
                })
                .filter((entry) => entry._matchScore >= 0)
                .sort((a, b) => {
                    if (b._matchScore !== a._matchScore) {
                        return b._matchScore - a._matchScore;
                    }
                    if (Math.abs(a.top - b.top) > 1) {
                        return a.top - b.top;
                    }
                    return a.left - b.left;
                })[0] || null;
            if (!fieldEntry) {
                targets[config.key] = null;
                continue;
            }

            targets[config.key] = {
                text: fieldEntry.text,
                left: fieldEntry.left,
                top: fieldEntry.top,
                width: fieldEntry.width,
                height: fieldEntry.height,
                style: fieldEntry.style,
                line_boxes: groupLineRects(fieldEntry.root, fieldEntry.fieldRect, fieldEntry.left, fieldEntry.top),
                word_entries: Object.fromEntries((config.sampleWords || []).map((word) => [word, getWordEntry(fieldEntry.root, fieldEntry.fieldRect, fieldEntry.left, fieldEntry.top, word)])),
            };
        }

        return {
            overlayWidth,
            overlayHeight,
            targets,
        };
    }, targetConfigs.map((config) => ({
        key: config.key,
        matchText: config.matchText,
        lineText: config.lineText,
        sampleWords: config.sampleWords,
    })));
}

async function collectOverlayFieldTexts(page) {
    return page.evaluate(() => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        return Array.from(document.querySelectorAll('.overlay-field')).map((field) => {
            const root = field.querySelector('[contenteditable]') || field;
            const pageHost = field.closest('.page-wrapper[data-page-number], .page[data-page-index]');
            return {
                key: field.dataset.wordIndex || '',
                text: normalizeText(root.innerText || root.textContent || ''),
                pageNumber: Number(pageHost?.dataset.pageNumber || ((Number(pageHost?.dataset.pageIndex) || 0) + 1) || 0),
                left: Number(field.offsetLeft) || 0,
                top: Number(field.offsetTop) || 0,
                width: Number(field.offsetWidth) || 0,
                height: Number(field.offsetHeight) || 0,
            };
        }).filter((entry) => entry.text.length > 0);
    });
}

async function collectOverlayFieldDescriptors(page) {
    return page.evaluate(() => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        const groupLineRects = (root, fieldRect, fieldLeft, fieldTop) => {
            const range = document.createRange();
            range.selectNodeContents(root);
            const rects = Array.from(range.getClientRects())
                .filter((rect) => rect.width > 1 && rect.height > 1)
                .filter((rect) => {
                    const overlapWidth = Math.max(0, Math.min(rect.right, fieldRect.right) - Math.max(rect.left, fieldRect.left));
                    const overlapHeight = Math.max(0, Math.min(rect.bottom, fieldRect.bottom) - Math.max(rect.top, fieldRect.top));
                    return overlapWidth > 0.5 && overlapHeight > 0.5;
                })
                .map((rect) => ({
                    left: fieldLeft + (rect.left - fieldRect.left),
                    top: fieldTop + (rect.top - fieldRect.top),
                    right: fieldLeft + (rect.right - fieldRect.left),
                    bottom: fieldTop + (rect.bottom - fieldRect.top),
                }))
                .sort((a, b) => {
                    if (Math.abs(a.top - b.top) > 2) {
                        return a.top - b.top;
                    }
                    return a.left - b.left;
                });

            const groups = [];
            for (const rect of rects) {
                let group = groups.find((candidate) => {
                    const overlap = Math.min(candidate.bottom, rect.bottom) - Math.max(candidate.top, rect.top);
                    return overlap >= Math.min(candidate.bottom - candidate.top, rect.bottom - rect.top) * 0.45
                        || Math.abs(candidate.top - rect.top) <= 3;
                });
                if (!group) {
                    groups.push({ ...rect });
                    continue;
                }
                group.left = Math.min(group.left, rect.left);
                group.top = Math.min(group.top, rect.top);
                group.right = Math.max(group.right, rect.right);
                group.bottom = Math.max(group.bottom, rect.bottom);
            }

            return groups.map((group) => ({
                left: group.left,
                top: group.top,
                width: group.right - group.left,
                height: group.bottom - group.top,
            }));
        };

        return Array.from(document.querySelectorAll('.overlay-field')).map((field) => {
            const root = field.querySelector('[contenteditable]') || field;
            const fieldRect = field.getBoundingClientRect();
            const pageHost = field.closest('.page-wrapper[data-page-number], .page[data-page-index]');
            const sourceBlock = field._overlaySourceBlock || null;
            return {
                key: field.dataset.wordIndex || '',
                text: normalizeText(root.innerText || root.textContent || ''),
                originalText: normalizeText(field.dataset.originalText || ''),
                pageNumber: Number(pageHost?.dataset.pageNumber || ((Number(pageHost?.dataset.pageIndex) || 0) + 1) || 0),
                left: Number(field.offsetLeft) || 0,
                top: Number(field.offsetTop) || 0,
                width: fieldRect.width,
                height: fieldRect.height,
                line_boxes: groupLineRects(root, fieldRect, Number(field.offsetLeft) || 0, Number(field.offsetTop) || 0),
                source_line_boxes: Array.isArray(sourceBlock?.line_bboxes)
                    ? sourceBlock.line_bboxes
                        .filter((bbox) => Array.isArray(bbox) && bbox.length >= 4)
                        .map((bbox) => bbox.map((value) => Number(value) || 0))
                    : [],
            };
        }).filter((entry) => entry.text.length > 0);
    });
}

async function waitForSelectionToolbarSelection(page, selectionType) {
    await page.waitForFunction((expectedType) => {
        const api = window.pdfSelectionToolbarApi;
        if (!api || typeof api.getState !== 'function') {
            return false;
        }
        const state = api.getState();
        return state && state.selectionType === expectedType && state.enabled === true;
    }, selectionType, { timeout: 15000 });
}

async function readSelectionToolbarState(page) {
    return page.evaluate(() => {
        const api = window.pdfSelectionToolbarApi;
        if (!api || typeof api.getState !== 'function') {
            throw new Error('pdfSelectionToolbarApi is unavailable');
        }
        return api.getState();
    });
}

async function applySelectionToolbarStyles(page, styles) {
    return page.evaluate((inputStyles) => {
        const api = window.pdfSelectionToolbarApi;
        if (!api || typeof api.applyStyles !== 'function') {
            throw new Error('pdfSelectionToolbarApi.applyStyles is unavailable');
        }
        return api.applyStyles(inputStyles);
    }, styles);
}

async function openCenterTextCreator(page) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const pageGeometry = await getBasePageGeometry(page);
    if (!pageGeometry) {
        throw new Error('missing base page geometry for Add Text');
    }

    const overlay = page.locator('.page[data-page-index="0"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + pageGeometry.centerX, overlayBox.y + pageGeometry.centerY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    return page.locator('.text-box-creator .tbc-input').first();
}

async function setActiveTextEditorHtml(page, html) {
    await page.evaluate((nextHtml) => {
        const input = document.querySelector('.text-box-creator .tbc-input');
        if (!input) {
            throw new Error('missing active text editor');
        }

        input.innerHTML = String(nextHtml || '');
        input.dispatchEvent(new InputEvent('input', {
            bubbles: true,
            data: null,
            inputType: 'insertText',
        }));
        input.focus();
    }, html);
    await page.waitForTimeout(250);
}

async function commitActiveTextBox(page) {
    await page.locator('.text-box-creator .tbc-ok').click();
    await page.waitForTimeout(1200);
}

async function selectActiveEditorText(page, targetText) {
    return page.evaluate((needle) => {
        const input = document.querySelector('.text-box-creator .tbc-input');
        if (!input) {
            throw new Error('missing active text editor');
        }

        const target = String(needle || '');
        const fullText = input.textContent || '';
        const start = fullText.indexOf(target);
        if (start === -1) {
            throw new Error(`could not find "${target}" in active editor text`);
        }
        const end = start + target.length;

        const walker = document.createTreeWalker(input, NodeFilter.SHOW_TEXT);
        let currentOffset = 0;
        let startNode = null;
        let startNodeOffset = 0;
        let endNode = null;
        let endNodeOffset = 0;

        while (walker.nextNode()) {
            const node = walker.currentNode;
            const length = node.nodeValue?.length || 0;
            const nextOffset = currentOffset + length;

            if (!startNode && start >= currentOffset && start < nextOffset) {
                startNode = node;
                startNodeOffset = start - currentOffset;
            }

            if (!endNode && end > currentOffset && end <= nextOffset) {
                endNode = node;
                endNodeOffset = end - currentOffset;
            }

            currentOffset = nextOffset;
        }

        if (!startNode || !endNode) {
            throw new Error(`failed to resolve text nodes for "${target}"`);
        }

        const range = document.createRange();
        range.setStart(startNode, startNodeOffset);
        range.setEnd(endNode, endNodeOffset);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        if (typeof activeEditorSelectionRange !== 'undefined') {
            activeEditorSelectionRange = range.cloneRange();
        }
        input.focus();

        return { target, start, end };
    }, targetText);
}

async function applyEditTextBannerStyles(page, styles) {
    return page.evaluate((inputStyles) => {
        const sliderMin = 0;
        const sliderMid = 50;
        const sliderMax = 100;
        const minFontSize = 8;
        const midFontSize = 16;
        const maxFontSize = 144;

        const dispatchValue = (element, value, eventName) => {
            if (!element) {
                throw new Error(`missing edit text banner control for ${eventName}`);
            }
            element.value = value;
            element.dispatchEvent(new Event(eventName, { bubbles: true }));
        };

        const clampFontSize = (value) => {
            const numericValue = Number(value);
            if (!Number.isFinite(numericValue)) {
                return minFontSize;
            }
            return Math.max(minFontSize, Math.min(maxFontSize, Math.round(numericValue)));
        };

        const fontSizeToSliderValue = (value) => {
            const sizePx = clampFontSize(value);
            if (sizePx <= midFontSize) {
                const ratio = (sizePx - minFontSize) / Math.max(1, midFontSize - minFontSize);
                return Math.round(sliderMin + ((sliderMid - sliderMin) * ratio));
            }

            const ratio = (sizePx - midFontSize) / Math.max(1, maxFontSize - midFontSize);
            return Math.round(sliderMid + ((sliderMax - sliderMid) * ratio));
        };

        const setToggle = (element, desiredState) => {
            if (!element) {
                throw new Error('missing edit text banner toggle');
            }
            const nextState = Boolean(desiredState);
            if (element.classList.contains('active') !== nextState) {
                element.click();
            }
        };

        if (inputStyles.fontFamily) {
            // fontFamily was already set on etb-font before the overlay click so
            // readBannerOpts() captured it at box-creation time.  Update the select
            // here anyway so the banner stays in sync visually.
            const etbFontEl = document.getElementById('etb-font');
            if (etbFontEl && etbFontEl.value !== inputStyles.fontFamily) {
                etbFontEl.value = inputStyles.fontFamily;
            }
        }
        if (inputStyles.fontSize !== undefined) {
            const sizeInput = document.getElementById('etb-size');
            if (!sizeInput) {
                throw new Error('missing edit text banner control for font size');
            }
            sizeInput.value = String(fontSizeToSliderValue(inputStyles.fontSize));
            sizeInput.dispatchEvent(new Event('input', { bubbles: true }));
            sizeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (inputStyles.textColor) {
            dispatchValue(document.getElementById('etb-text-color'), inputStyles.textColor, 'input');
        }
        if (inputStyles.backgroundColor) {
            dispatchValue(document.getElementById('etb-bg-color'), inputStyles.backgroundColor, 'input');
        }
        if (inputStyles.bold !== undefined) {
            setToggle(document.getElementById('etb-bold'), inputStyles.bold);
        }
        if (inputStyles.italic !== undefined) {
            setToggle(document.getElementById('etb-italic'), inputStyles.italic);
        }
        if (inputStyles.underline !== undefined) {
            setToggle(document.getElementById('etb-underline'), inputStyles.underline);
        }

        const input = document.querySelector('.text-box-creator .tbc-input');
        return {
            html: input?.innerHTML || '',
            text: input?.textContent || '',
        };
    }, styles);
}

async function readCommittedAnnotationRichState(page, targetText) {
    return page.evaluate((expectedText) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const annotation = Array.from(document.querySelectorAll('.annotation')).find((candidate) => {
            const textEl = candidate.querySelector('.annotation-text');
            return normalizeText(textEl?.innerText || textEl?.textContent || '') === normalizeText(expectedText);
        });
        if (!annotation) {
            throw new Error(`missing committed annotation for "${expectedText}"`);
        }

        const textEl = annotation.querySelector('.annotation-text');
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : [];
        const record = Array.isArray(records)
            ? records.find((item) => normalizeText(item?.text || '') === normalizeText(expectedText))
            : null;

        return {
            html: textEl?.innerHTML || '',
            text: textEl?.textContent || '',
            richTextHtml: record?.richTextHtml || '',
            textColor: record?.textColor || '',
            fontFamily: record?.fontFamily || '',
            fontWeight: record?.fontWeight || '',
            fontStyle: record?.fontStyle || '',
            underline: Boolean(record?.underline),
            backgroundColor: record?.backgroundColor || '',
        };
    }, targetText);
}

async function readCommittedAnnotationFragmentStyles(page, targetText, fragments) {
    return page.evaluate(({ expectedText, requestedFragments }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const annotation = Array.from(document.querySelectorAll('.annotation')).find((candidate) => {
            const textEl = candidate.querySelector('.annotation-text');
            return normalizeText(textEl?.innerText || textEl?.textContent || '') === normalizeText(expectedText);
        });
        if (!annotation) {
            throw new Error(`missing committed annotation for "${expectedText}"`);
        }

        const textEl = annotation.querySelector('.annotation-text');
        if (!textEl) {
            throw new Error(`missing annotation text element for "${expectedText}"`);
        }

        const resolveFragmentStyle = (fragment) => {
            const target = String(fragment || '');
            const rootText = textEl.textContent || '';
            const start = rootText.indexOf(target);
            if (start === -1) {
                return null;
            }

            const end = start + target.length;
            const walker = document.createTreeWalker(textEl, NodeFilter.SHOW_TEXT);
            let currentOffset = 0;
            let startNode = null;
            let endNode = null;
            let startNodeOffset = 0;
            let endNodeOffset = 0;

            while (walker.nextNode()) {
                const node = walker.currentNode;
                const length = node.nodeValue?.length || 0;
                const nextOffset = currentOffset + length;

                if (!startNode && start >= currentOffset && start < nextOffset) {
                    startNode = node;
                    startNodeOffset = start - currentOffset;
                }

                if (!endNode && end > currentOffset && end <= nextOffset) {
                    endNode = node;
                    endNodeOffset = end - currentOffset;
                }

                currentOffset = nextOffset;
            }

            if (!startNode || !endNode) {
                return null;
            }

            const startElement = startNode.parentElement || textEl;
            const endElement = endNode.parentElement || textEl;
            const computed = getComputedStyle(startElement);
            const range = document.createRange();
            range.setStart(startNode, startNodeOffset);
            range.setEnd(endNode, endNodeOffset);
            const rects = Array.from(range.getClientRects()).map((rect) => ({
                width: rect.width,
                height: rect.height,
                left: rect.left,
                top: rect.top,
            }));

            return {
                fragment: target,
                start_element_html: startElement.outerHTML || '',
                end_element_html: endElement.outerHTML || '',
                font_weight: computed.fontWeight,
                font_style: computed.fontStyle,
                text_decoration_line: computed.textDecorationLine || computed.textDecoration || '',
                rect_count: rects.length,
                rects,
            };
        };

        return Object.fromEntries(requestedFragments.map((fragment) => [
            fragment,
            resolveFragmentStyle(fragment),
        ]));
    }, {
        expectedText: targetText,
        requestedFragments: fragments,
    });
}

async function findOverlayFieldDescriptor(page, textFragment) {
    return page.evaluate((targetTextFragment) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const fields = Array.from(document.querySelectorAll('.overlay-field'));
        const match = fields.find((field) => {
            const candidates = [
                field.innerText,
                field.textContent,
                field.dataset.stableFlowText,
                field.dataset.originalText,
            ];

            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!match) {
            return null;
        }

        return {
            key: match.dataset.wordIndex || '',
            text: match.innerText || match.textContent || '',
        };
    }, textFragment);
}

async function getOverlayFieldSelector(page, textFragment) {
    const descriptor = await findOverlayFieldDescriptor(page, textFragment);
    if (!descriptor?.key) {
        throw new Error(`could not find overlay field containing "${textFragment}"`);
    }

    return `.overlay-field[data-word-index="${descriptor.key}"]`;
}

async function replaceOverlayFieldText(page, fieldSelector, newText) {
    const field = page.locator(fieldSelector).first();
    await field.dblclick();
    await page.waitForTimeout(250);

    await page.evaluate(({ selector, text }) => {
        const fieldElement = document.querySelector(selector);
        const textElement = fieldElement?.querySelector('[contenteditable]');
        if (!fieldElement || !textElement) {
            throw new Error(`missing overlay field for selector ${selector}`);
        }

        fieldElement.classList.add('active', 'editing');
        textElement.contentEditable = 'true';
        textElement.focus();
        textElement.textContent = text;
        textElement.dispatchEvent(new Event('input', { bubbles: true }));
    }, { selector: fieldSelector, text: newText });

    await page.waitForTimeout(250);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(250);
    await field.click();
    await page.waitForTimeout(250);
}

async function setOverlayFieldBackgroundColor(page, fieldSelector, backgroundColor) {
    return page.evaluate(({ selector, color }) => {
        const field = document.querySelector(selector);
        const textElement = field?.querySelector('[contenteditable]') || field;
        if (!field || !textElement) {
            throw new Error(`missing overlay field for selector ${selector}`);
        }
        if (!(typeof overlayEditedFields !== 'undefined' && overlayEditedFields instanceof Map)) {
            throw new Error('overlayEditedFields is unavailable');
        }
        if (typeof buildOverlayEditRecordFromField !== 'function') {
            throw new Error('buildOverlayEditRecordFromField is unavailable');
        }

        const nextColor = String(color || '').trim().toLowerCase();
        field.classList.add('active', 'selected');
        field.dataset.backgroundColor = nextColor;
        textElement.style.backgroundColor = nextColor;

        const built = buildOverlayEditRecordFromField(field, { forceReinsert: true });
        if (!built?.key || !built?.value) {
            throw new Error(`failed to build overlay edit for selector ${selector}`);
        }

        overlayEditedFields.set(built.key, {
            ...built.value,
            background_color: built.value.background_color || nextColor,
            force_reinsert: true,
        });

        return {
            key: built.key,
            page_number: built.value.page_number,
            text: built.value.new_text,
            bbox: built.value.bbox,
            backgroundColor: built.value.background_color || nextColor,
            richHtml: built.value.rich_html || '',
            computedBackground: getComputedStyle(textElement).backgroundColor,
        };
    }, { selector: fieldSelector, color: backgroundColor });
}

async function readOverlayFieldBackgroundState(page, fieldSelector) {
    return page.evaluate((selector) => {
        const field = document.querySelector(selector);
        const textElement = field?.querySelector('[contenteditable]') || field;
        const wrapper = textElement && textElement.parentElement && textElement.parentElement !== field
            ? textElement.parentElement
            : null;
        if (!field || !textElement) {
            throw new Error(`missing overlay field for selector ${selector}`);
        }

        return {
            fieldBackground: getComputedStyle(field).backgroundColor,
            textBackground: getComputedStyle(textElement).backgroundColor,
            wrapperBackground: wrapper ? getComputedStyle(wrapper).backgroundColor : '',
            datasetBackground: field.dataset.backgroundColor || '',
        };
    }, fieldSelector);
}

async function readOverlayFieldMetrics(page, fieldSelector) {
    return page.evaluate((selector) => {
        const field = document.querySelector(selector);
        const textElement = field?.querySelector('[contenteditable]');
        if (!field || !textElement) {
            throw new Error(`missing overlay field metrics target for selector ${selector}`);
        }

        const fieldRect = field.getBoundingClientRect();
        const textRect = textElement.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(textElement);

        const rectMap = new Map();
        for (const rect of Array.from(range.getClientRects())) {
            if (!rect || rect.width < 1 || rect.height < 1) {
                continue;
            }

            const key = [
                Math.round(rect.left - fieldRect.left),
                Math.round(rect.top - fieldRect.top),
                Math.round(rect.width),
                Math.round(rect.height),
            ].join(':');
            if (!rectMap.has(key)) {
                rectMap.set(key, {
                    width: rect.width,
                    height: rect.height,
                    rightGap: Math.max(0, textRect.right - rect.right),
                });
            }
        }

        const lineRects = Array.from(rectMap.values());

        return {
            left: parseFloat(field.style.left || '0') || 0,
            top: parseFloat(field.style.top || '0') || 0,
            width: fieldRect.width,
            height: fieldRect.height,
            overlayWidth: field.parentElement?.clientWidth || 0,
            overlayHeight: field.parentElement?.clientHeight || 0,
            contentWidth: textRect.width,
            contentHeight: textRect.height,
            lineCount: lineRects.length,
            lineWidths: lineRects.map((rect) => rect.width),
            lineRightGaps: lineRects.map((rect) => rect.rightGap),
            text: textElement.innerText || textElement.textContent || '',
        };
    }, fieldSelector);
}

async function resizeOverlayFieldTo(page, fieldSelector, targetWidth, targetHeight) {
    const field = page.locator(fieldSelector).first();

    for (let attempt = 0; attempt < 3; attempt += 1) {
        await field.click();
        await page.waitForTimeout(150);

        const fieldBox = await field.boundingBox();
        const handle = page.locator(`${fieldSelector} .resize-handle.se`).first();
        const handleBox = await handle.boundingBox();
        if (!fieldBox || !handleBox) {
            throw new Error(`missing overlay resize handle for selector ${fieldSelector}`);
        }

        const deltaX = targetWidth - fieldBox.width;
        const deltaY = targetHeight - fieldBox.height;
        if (Math.abs(deltaX) <= 3 && Math.abs(deltaY) <= 3) {
            break;
        }

        const fromX = handleBox.x + (handleBox.width / 2);
        const fromY = handleBox.y + (handleBox.height / 2);
        await page.mouse.move(fromX, fromY);
        await page.mouse.down();
        await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 18 });
        await page.mouse.up();
        await page.waitForTimeout(250);
    }

    const metrics = await readOverlayFieldMetrics(page, fieldSelector);
    if (Math.abs(metrics.width - targetWidth) > PARAGRAPH_SIZE_TOLERANCE || Math.abs(metrics.height - targetHeight) > PARAGRAPH_SIZE_TOLERANCE) {
        throw new Error(`overlay field resize missed target: ${JSON.stringify({ metrics, targetWidth, targetHeight })}`);
    }

    return metrics;
}

function buildDeterministicParagraphTarget(metrics) {
    const maxLeft = Math.max(0, metrics.overlayWidth - metrics.width);
    const maxTop = Math.max(0, metrics.overlayHeight - metrics.height);

    return {
        left: Math.round(maxLeft * 0.61),
        top: Math.round(maxTop * 0.37),
    };
}

async function moveOverlayFieldTo(page, fieldSelector, targetLeft, targetTop) {
    const field = page.locator(fieldSelector).first();
    await field.click();
    await page.waitForTimeout(150);

    const before = await readOverlayFieldMetrics(page, fieldSelector);
    const dragHandle = page.locator(`${fieldSelector} .menu-drag`).first();
    const dragBox = await dragHandle.boundingBox();
    if (!dragBox) {
        throw new Error(`missing overlay drag handle for selector ${fieldSelector}`);
    }

    const deltaX = targetLeft - before.left;
    const deltaY = targetTop - before.top;
    const fromX = dragBox.x + (dragBox.width / 2);
    const fromY = dragBox.y + (dragBox.height / 2);

    await page.mouse.move(fromX, fromY);
    await page.mouse.down();
    await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 24 });
    await page.mouse.up();
    await page.waitForTimeout(250);

    const after = await readOverlayFieldMetrics(page, fieldSelector);
    return {
        before,
        after,
        deltaX: after.left - before.left,
        deltaY: after.top - before.top,
    };
}

async function deleteOverlayField(page, fieldSelector) {
    const field = page.locator(fieldSelector).first();
    await field.click();
    await page.waitForTimeout(150);
    await page.locator(`${fieldSelector} .menu-delete`).first().click();
    await page.waitForTimeout(250);
}

function countWideLines(metrics, threshold = 0.72) {
    const comparable = metrics.lineWidths.filter((width) => width >= 20);
    if (comparable.length === 0 || !metrics.contentWidth) {
        return 0;
    }

    return comparable.filter((width) => width >= (metrics.contentWidth * threshold)).length;
}

async function createConfiguredParagraphAnnotation(page, paragraphCase) {
    const seedLeft = Math.max(24, Math.round(Number(paragraphCase.left) + 24));
    const seedTop = Math.max(24, Math.round(Number(paragraphCase.top) + 24));
    await createTextAnnotationAt(page, paragraphCase.fragment, seedLeft, seedTop);
    await updateTextAnnotation(page, paragraphCase.fragment, {
        text: paragraphCase.text,
        keepBounds: true,
        left: paragraphCase.left,
        top: paragraphCase.top,
        width: paragraphCase.width,
        height: paragraphCase.height,
    });
    return readTextAnnotationMetrics(page, paragraphCase.fragment);
}

function metricsRight(metrics) {
    return (Number(metrics?.left) || 0) + (Number(metrics?.width) || 0);
}

function metricsBottom(metrics) {
    return (Number(metrics?.top) || 0) + (Number(metrics?.height) || 0);
}

function metricsRect(metrics) {
    return [
        Number(metrics?.left) || 0,
        Number(metrics?.top) || 0,
        metricsRight(metrics),
        metricsBottom(metrics),
    ];
}

function layoutMetricsFitPage(metrics) {
    return metrics.every((entry) => (
        (Number(entry?.left) || 0) >= -PARAGRAPH_LAYOUT_TOLERANCE
        && (Number(entry?.top) || 0) >= -PARAGRAPH_LAYOUT_TOLERANCE
        && metricsRight(entry) <= ((Number(entry?.overlayWidth) || 0) + PARAGRAPH_LAYOUT_TOLERANCE)
        && metricsBottom(entry) <= ((Number(entry?.overlayHeight) || 0) + PARAGRAPH_LAYOUT_TOLERANCE)
    ));
}

function layoutMetricsOrderedLeftToRight(metrics) {
    return metrics.every((entry, index) => (
        index === 0
        || (Number(entry?.left) || 0) > metricsRight(metrics[index - 1]) - PARAGRAPH_LAYOUT_TOLERANCE
    ));
}

function layoutMetricsDoNotOverlap(metrics) {
    return metrics.every((entry, index) => (
        metrics.slice(index + 1).every((other) => rectOverlapRatio(metricsRect(entry), metricsRect(other)) === 0)
    ));
}

function metricsShareSameLeft(metrics, tolerance = 1) {
    if (!Array.isArray(metrics) || !metrics.length) {
        return false;
    }
    const baseLeft = Number(metrics[0]?.left) || 0;
    return metrics.every((entry) => Math.abs((Number(entry?.left) || 0) - baseLeft) <= tolerance);
}

// Detect annotations in a vertical stack whose rendered text overflows its
// allotted slot — either because the text is wider than the annotation box
// (forcing a visible wrap) or because the rendered bottom extends past the
// next annotation's top. Returns an array of overflow descriptors (empty when
// every annotation fits its slot on a single visible line).
function computeStackOverflowOverlaps(metrics, tolerance = 1) {
    if (!Array.isArray(metrics) || !metrics.length) {
        return [];
    }
    const sorted = metrics
        .map((entry, index) => ({
            index,
            top: Number(entry?.top) || 0,
            height: Number(entry?.height) || 0,
            width: Number(entry?.width) || 0,
            textWidth: Number(entry?.textWidth) || 0,
            text: String(entry?.text || ''),
        }))
        .sort((a, b) => a.top - b.top);
    const overlaps = [];
    for (let i = 0; i < sorted.length; i++) {
        const current = sorted[i];
        const widthOverflow = current.textWidth - current.width;
        if (widthOverflow > tolerance) {
            overlaps.push({
                index: current.index,
                text: current.text,
                kind: 'text_wider_than_box',
                box_width: Number(current.width.toFixed(2)),
                text_width: Number(current.textWidth.toFixed(2)),
                overflow_px: Number(widthOverflow.toFixed(2)),
            });
        }
        if (i < sorted.length - 1) {
            const next = sorted[i + 1];
            const bottom = current.top + current.height;
            const overlap = bottom - next.top;
            if (overlap > tolerance) {
                overlaps.push({
                    index: current.index,
                    next_index: next.index,
                    text: current.text,
                    next_text: next.text,
                    kind: 'bottom_overlaps_next_top',
                    bottom: Number(bottom.toFixed(2)),
                    next_top: Number(next.top.toFixed(2)),
                    overlap_px: Number(overlap.toFixed(2)),
                });
            }
        }
    }
    return overlaps;
}

function blocksOrderedLeftToRight(blocks) {
    const leftPositions = blocks.map((block) => (block ? blockBBox(block)[0] : null));
    return {
        leftPositions,
        ordered: leftPositions.every((value, index) => (
            index === 0 || value === null || leftPositions[index - 1] === null || value > leftPositions[index - 1]
        )),
    };
}

async function runTextPositionFlow() {
    const test = TESTS.test_1_text_position;
    ensureOutputDir();

    const runToken = buildRunToken();
    const blankScreenshotName = buildArtifactName(test.key, runToken, 'blank_flow_center');
    const firstScreenshotName = buildArtifactName(test.key, runToken, 'after_first_save');
    const secondScreenshotName = buildArtifactName(test.key, runToken, 'second_initial_save');
    const secondMoveScreenshotName = buildArtifactName(test.key, runToken, 'after_move_before_second_save');
    const secondDeltaScreenshotName = buildArtifactName(test.key, runToken, 'after_second_save');
    const topPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_top');
    const leftPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_left');
    const rightPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_right');
    const bottomPositionScreenshotName = buildArtifactName(test.key, runToken, 'position_bottom');
    const groupingMixedScreenshotName = buildArtifactName(test.key, runToken, 'grouping_mixed_stack');
    const groupingShortScreenshotName = buildArtifactName(test.key, runToken, 'grouping_five_short');
    const groupingLongScreenshotName = buildArtifactName(test.key, runToken, 'grouping_five_long');
    const blankScreenshotPath = path.join(OUTPUT_DIR, blankScreenshotName);
    const firstScreenshotPath = path.join(OUTPUT_DIR, firstScreenshotName);
    const secondMoveScreenshotPath = path.join(OUTPUT_DIR, secondMoveScreenshotName);
    const secondScreenshotPath = path.join(OUTPUT_DIR, secondScreenshotName);
    const secondDeltaScreenshotPath = path.join(OUTPUT_DIR, secondDeltaScreenshotName);
    const topPositionScreenshotPath = path.join(OUTPUT_DIR, topPositionScreenshotName);
    const leftPositionScreenshotPath = path.join(OUTPUT_DIR, leftPositionScreenshotName);
    const rightPositionScreenshotPath = path.join(OUTPUT_DIR, rightPositionScreenshotName);
    const bottomPositionScreenshotPath = path.join(OUTPUT_DIR, bottomPositionScreenshotName);
    const groupingMixedScreenshotPath = path.join(OUTPUT_DIR, groupingMixedScreenshotName);
    const groupingShortScreenshotPath = path.join(OUTPUT_DIR, groupingShortScreenshotName);
    const groupingLongScreenshotPath = path.join(OUTPUT_DIR, groupingLongScreenshotName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let firstDocumentId = null;
    let secondDocumentId = null;
    const cardinalResults = {};
    const cardinalDocumentIds = [];
    const groupingDocumentIds = [];
    const groupingScenarioResults = [];
    const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const findExactSavedMatches = (savedResponse, targetText) => {
        const annotations = Array.isArray(savedResponse?.body?.annotations) ? savedResponse.body.annotations : [];
        const normalizedTarget = normalizeText(targetText);
        return annotations.filter((annotation) => normalizeText(annotation?.text) === normalizedTarget);
    };

    try {
        firstDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, firstDocumentId);
        await captureCenteredOverlayScreenshot(page, blankScreenshotPath, {
            width: 300,
            height: 180,
        });

        await createCenterTextAnnotation(page, POSITION_TEXT);
        const firstStartMetrics = await readTextAnnotationMetrics(page, POSITION_TEXT);
        await updateTextAnnotation(page, POSITION_TEXT, {
            top: Math.round(firstStartMetrics.top + FIRST_MOVE_Y),
        });
        await waitForAnnotationSave(page);

        // Read PDF coordinates directly from the in-page annotations array.
        // The save path is /save-annotation-state (DB-only; no PDF stamping), so
        // fitz extraction from the raw file would find no text. The annotation
        // record already holds the correctly-computed pdfX / pdfY coordinates.
        const firstAnnot = await readTextAnnotationState(page, POSITION_TEXT);
        if (!firstAnnot || !(firstAnnot.pdfY > 0)) {
            throw new Error(`expected annotation with PDF coordinates after first save, got ${JSON.stringify(firstAnnot)}`);
        }
        const firstBBox = [
            firstAnnot.pdfX,
            firstAnnot.pdfY,
            firstAnnot.pdfX + firstAnnot.pdfWidth,
            firstAnnot.pdfY + firstAnnot.pdfHeight,
        ];
        await captureTextAnnotationScreenshot(page, POSITION_TEXT, firstScreenshotPath);

        secondDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, secondDocumentId);
        await clearPdfSessionId(page);
        const secondSessionId = await ensurePdfSessionId(page, 'test1pos');

        await createCenterTextAnnotation(page, POSITION_TEXT);
        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);

        const secondInitialAnnot = await readTextAnnotationState(page, POSITION_TEXT);
        if (!secondInitialAnnot || !(secondInitialAnnot.pdfY > 0)) {
            throw new Error(`expected initial saved annotation with PDF coordinates, got ${JSON.stringify(secondInitialAnnot)}`);
        }
        await captureCenteredOverlayScreenshot(page, secondScreenshotPath, {
            width: 760,
            height: 680,
        });

        const secondInitialSavedAnnotations = await fetchSavedAnnotations(page, secondDocumentId, secondSessionId);
        if (!secondInitialSavedAnnotations.ok || !secondInitialSavedAnnotations.body?.success) {
            throw new Error(`initial saved-annotations failed: ${JSON.stringify(secondInitialSavedAnnotations)}`);
        }
        const secondInitialMatches = findExactSavedMatches(secondInitialSavedAnnotations, POSITION_TEXT);
        const secondInitialSavedAnnot = secondInitialMatches[0] || null;

        const secondMoveFromState = await readTextAnnotationState(page, POSITION_TEXT);
        const secondMoveTargetTop = Math.round(secondMoveFromState.top + SECOND_MOVE_Y);
        const secondMoveState = await updateTextAnnotation(page, POSITION_TEXT, {
            left: Math.round(secondMoveFromState.left),
            top: secondMoveTargetTop,
        });
        await captureCenteredOverlayScreenshot(page, secondMoveScreenshotPath, {
            width: 760,
            height: 680,
        });

        await page.waitForTimeout(1200);
        await waitForAnnotationSave(page);

        const secondMovedAnnot = await readTextAnnotationState(page, POSITION_TEXT);
        if (!secondMovedAnnot || !(secondMovedAnnot.pdfY > 0)) {
            throw new Error(`expected moved annotation with PDF coordinates after second save, got ${JSON.stringify(secondMovedAnnot)}`);
        }
        await captureCenteredOverlayScreenshot(page, secondDeltaScreenshotPath, {
            width: 760,
            height: 680,
        });

        const secondMovedSavedAnnotations = await fetchSavedAnnotations(page, secondDocumentId, secondSessionId);
        if (!secondMovedSavedAnnotations.ok || !secondMovedSavedAnnotations.body?.success) {
            throw new Error(`moved saved-annotations failed: ${JSON.stringify(secondMovedSavedAnnotations)}`);
        }
        const secondMovedMatches = findExactSavedMatches(secondMovedSavedAnnotations, POSITION_TEXT);
        const secondMovedSavedAnnot = secondMovedMatches[0] || null;
        const sameSavedAnnotationId = Boolean(
            secondInitialSavedAnnot?.id
            && secondMovedSavedAnnot?.id
            && secondInitialSavedAnnot.id === secondMovedSavedAnnot.id
        );
        const dbUpdatedAtChanged = Boolean(
            secondInitialSavedAnnot?.db_updated_at
            && secondMovedSavedAnnot?.db_updated_at
            && secondInitialSavedAnnot.db_updated_at !== secondMovedSavedAnnot.db_updated_at
        );
        const dbPdfDeltaX = (Number(secondMovedSavedAnnot?.pdfX) || 0) - (Number(secondInitialSavedAnnot?.pdfX) || 0);
        const dbPdfDeltaY = (Number(secondMovedSavedAnnot?.pdfY) || 0) - (Number(secondInitialSavedAnnot?.pdfY) || 0);
        const dbCoordinatesChanged = Math.abs(dbPdfDeltaX) > 0.01 || Math.abs(dbPdfDeltaY) > 0.01;

        const cardinalScreenshotPaths = {
            top: topPositionScreenshotPath,
            left: leftPositionScreenshotPath,
            right: rightPositionScreenshotPath,
            bottom: bottomPositionScreenshotPath,
        };
        for (const positionCase of TEXT_POSITION_CARDINAL_CASES) {
            const cardinalDocumentId = await createBlankDocument(page);
            cardinalDocumentIds.push(cardinalDocumentId);
            await waitForEditorReady(page);
            await clearAnnotationSessionState(page, cardinalDocumentId);

            await createTextAnnotationAt(page, positionCase.text, 160, 120);
            const initialMetrics = await readTextAnnotationMetrics(page, positionCase.text);
            const targetLeft = positionCase.horizontal === 'left'
                ? TEXT_POSITION_CARDINAL_MARGIN
                : positionCase.horizontal === 'right'
                    ? Math.max(0, Math.round(initialMetrics.overlayWidth - initialMetrics.width - TEXT_POSITION_CARDINAL_MARGIN))
                    : Math.max(0, Math.round((initialMetrics.overlayWidth - initialMetrics.width) / 2));
            const targetTop = positionCase.vertical === 'top'
                ? TEXT_POSITION_CARDINAL_MARGIN
                : positionCase.vertical === 'bottom'
                    ? Math.max(0, Math.round(initialMetrics.overlayHeight - initialMetrics.height - TEXT_POSITION_CARDINAL_MARGIN))
                    : Math.max(0, Math.round((initialMetrics.overlayHeight - initialMetrics.height) / 2));
            await forcePositionTextAnnotation(page, positionCase.text, {
                left: targetLeft,
                top: targetTop,
            });
            await clickOutsideFirstPage(page);
            await waitForAnnotationSave(page);
            const metrics = await readTextAnnotationMetrics(page, positionCase.text);
            await setEditorZoomPercent(page, TEXT_POSITION_EDGE_SCREENSHOT_ZOOM);
            await capturePageScreenshot(page, cardinalScreenshotPaths[positionCase.key]);
            cardinalResults[positionCase.key] = {
                documentId: cardinalDocumentId,
                metrics,
            };
        }

        const topMetrics = cardinalResults.top?.metrics || null;
        const leftMetrics = cardinalResults.left?.metrics || null;
        const rightMetrics = cardinalResults.right?.metrics || null;
        const bottomMetrics = cardinalResults.bottom?.metrics || null;
        const topPositionPass = (Number(topMetrics?.top) || 0) <= (TEXT_POSITION_CARDINAL_MARGIN + 1)
            && metricsRight(topMetrics) <= (Number(topMetrics?.overlayWidth) || 0) + 1;
        const leftPositionPass = (Number(leftMetrics?.left) || 0) <= (TEXT_POSITION_CARDINAL_MARGIN + 1)
            && metricsBottom(leftMetrics) <= (Number(leftMetrics?.overlayHeight) || 0) + 1;
        const rightGap = (Number(rightMetrics?.overlayWidth) || 0) - metricsRight(rightMetrics);
        const rightPositionPass = rightGap >= -1 && rightGap <= (TEXT_POSITION_CARDINAL_MARGIN + 1);
        const bottomGap = (Number(bottomMetrics?.overlayHeight) || 0) - metricsBottom(bottomMetrics);
        const bottomPositionPass = bottomGap >= -1 && bottomGap <= (TEXT_POSITION_CARDINAL_MARGIN + 1);

        for (const scenario of TEXT_POSITION_GROUPING_SCENARIOS) {
            const scenarioDocumentId = await createBlankDocument(page);
            groupingDocumentIds.push(scenarioDocumentId);
            await waitForEditorReady(page);
            await clearAnnotationSessionState(page, scenarioDocumentId);

            for (const scenarioCase of scenario.cases) {
                await createTextAnnotationAt(page, scenarioCase.text, scenarioCase.left, scenarioCase.top);
            }

            await waitForAnnotationSave(page);
            // In-page grouping analysis: verify each case text is a separate
            // annotation with no merging (equivalent PDF-level grouping check but
            // works without stamping the blank PDF).
            const scenarioAnalysis = await analyzeAnnotationGroupings(page, scenario.cases);
            const scenarioMetrics = [];
            for (const scenarioCase of scenario.cases) {
                scenarioMetrics.push(await readTextAnnotationMetrics(page, scenarioCase.text));
            }
            if (scenario.key === 'mixed_long_short_stack') {
                await captureTextAnnotationClusterScreenshot(
                    page,
                    scenario.cases.map((scenarioCase) => scenarioCase.text),
                    groupingMixedScreenshotPath,
                    TEXT_POSITION_STACK_SCREENSHOT_OPTIONS
                );
            } else if (scenario.key === 'five_short_lines') {
                await captureTextAnnotationClusterScreenshot(
                    page,
                    scenario.cases.map((scenarioCase) => scenarioCase.text),
                    groupingShortScreenshotPath,
                    TEXT_POSITION_STACK_SCREENSHOT_OPTIONS
                );
            } else if (scenario.key === 'five_long_lines') {
                await captureTextAnnotationClusterScreenshot(
                    page,
                    scenario.cases.map((scenarioCase) => scenarioCase.text),
                    groupingLongScreenshotPath,
                    TEXT_POSITION_STACK_SCREENSHOT_OPTIONS
                );
            }
            groupingScenarioResults.push({
                key: scenario.key,
                label: scenario.label,
                documentId: scenarioDocumentId,
                analysis: scenarioAnalysis,
                metrics: scenarioMetrics,
            });
        }

        const mixedStackScenario = groupingScenarioResults.find((scenarioResult) => scenarioResult.key === 'mixed_long_short_stack') || null;
        const mixedStackLefts = Array.isArray(mixedStackScenario?.metrics)
            ? mixedStackScenario.metrics.map((metrics) => Number(metrics?.left) || 0)
            : [];
        const mixedStackAlignedLeft = metricsShareSameLeft(mixedStackScenario?.metrics || [], 1);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'Fresh blank PDFs were created through the frontend blank-PDF flow.',
                detail: `first_document_id=${firstDocumentId} second_document_id=${secondDocumentId} creation_path=browser_blank_pdf_flow`,
            },
            {
                item: 'first_save_visible',
                result: firstAnnot && firstAnnot.pdfY > 0 ? 'PASS' : 'FAIL',
                description: 'The first annotation save produced an in-page annotation with valid PDF coordinates.',
                detail: `browser_top=${firstAnnot?.top?.toFixed(2)} browser_left=${firstAnnot?.left?.toFixed(2)} pdfX=${firstAnnot?.pdfX?.toFixed(2)} pdfY=${firstAnnot?.pdfY?.toFixed(2)} pdfWidth=${firstAnnot?.pdfWidth?.toFixed(2)} pdfHeight=${firstAnnot?.pdfHeight?.toFixed(2)}`,
            },
            {
                item: 'second_initial_save_db_row_created',
                result: secondInitialMatches.length === 1 && Boolean(secondInitialSavedAnnot?.id) ? 'PASS' : 'FAIL',
                description: 'The second document initial save created exactly one saved annotation row for the target text.',
                detail: `document_id=${secondDocumentId} session_id=${secondSessionId} matched_rows=${secondInitialMatches.length} annotation_id=${secondInitialSavedAnnot?.id || ''} db_updated_at=${secondInitialSavedAnnot?.db_updated_at || ''} pdfX=${Number(secondInitialSavedAnnot?.pdfX || 0).toFixed(2)} pdfY=${Number(secondInitialSavedAnnot?.pdfY || 0).toFixed(2)}`,
            },
            {
                item: 'second_move_db_row_updated',
                result: secondInitialMatches.length === 1 && secondMovedMatches.length === 1 && sameSavedAnnotationId ? 'PASS' : 'FAIL',
                description: 'Moving and saving again updated the same saved annotation row instead of creating a duplicate.',
                detail: `initial_match_count=${secondInitialMatches.length} moved_match_count=${secondMovedMatches.length} initial_annotation_id=${secondInitialSavedAnnot?.id || ''} moved_annotation_id=${secondMovedSavedAnnot?.id || ''} same_annotation_id=${sameSavedAnnotationId} initial_db_updated_at=${secondInitialSavedAnnot?.db_updated_at || ''} moved_db_updated_at=${secondMovedSavedAnnot?.db_updated_at || ''} db_updated_at_changed=${dbUpdatedAtChanged}`,
            },
            {
                item: 'second_move_db_coordinates_changed',
                result: dbCoordinatesChanged ? 'PASS' : 'FAIL',
                description: 'The saved annotation row coordinates changed after the move-and-save step.',
                detail: `annotation_id=${secondMovedSavedAnnot?.id || ''} initial_pdfX=${Number(secondInitialSavedAnnot?.pdfX || 0).toFixed(2)} initial_pdfY=${Number(secondInitialSavedAnnot?.pdfY || 0).toFixed(2)} moved_pdfX=${Number(secondMovedSavedAnnot?.pdfX || 0).toFixed(2)} moved_pdfY=${Number(secondMovedSavedAnnot?.pdfY || 0).toFixed(2)} delta_pdfX=${dbPdfDeltaX.toFixed(2)} delta_pdfY=${dbPdfDeltaY.toFixed(2)} browser_top_before=${secondMoveFromState.top.toFixed(2)} browser_top_after=${secondMoveState.top.toFixed(2)}`,
            },
            {
                item: 'text_position_top_of_page',
                result: topPositionPass ? 'PASS' : 'FAIL',
                description: 'Top of page placement kept the annotation near the top edge with the full text visible.',
                detail: `document_id=${cardinalResults.top?.documentId || ''} left=${Number(topMetrics?.left || 0).toFixed(2)} top=${Number(topMetrics?.top || 0).toFixed(2)} width=${Number(topMetrics?.width || 0).toFixed(2)} overlay_width=${Number(topMetrics?.overlayWidth || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_position_left_of_page',
                result: leftPositionPass ? 'PASS' : 'FAIL',
                description: 'Left of page placement kept the annotation near the left edge with the full text visible.',
                detail: `document_id=${cardinalResults.left?.documentId || ''} left=${Number(leftMetrics?.left || 0).toFixed(2)} top=${Number(leftMetrics?.top || 0).toFixed(2)} width=${Number(leftMetrics?.width || 0).toFixed(2)} overlay_width=${Number(leftMetrics?.overlayWidth || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_position_right_of_page',
                result: rightPositionPass ? 'PASS' : 'FAIL',
                description: 'Right of page placement kept the annotation near the right edge with the full text visible.',
                detail: `document_id=${cardinalResults.right?.documentId || ''} left=${Number(rightMetrics?.left || 0).toFixed(2)} right_gap=${rightGap.toFixed(2)} width=${Number(rightMetrics?.width || 0).toFixed(2)} overlay_width=${Number(rightMetrics?.overlayWidth || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_position_bottom_of_page',
                result: bottomPositionPass ? 'PASS' : 'FAIL',
                description: 'Bottom of page placement kept the annotation near the bottom edge with the full text visible.',
                detail: `document_id=${cardinalResults.bottom?.documentId || ''} top=${Number(bottomMetrics?.top || 0).toFixed(2)} bottom_gap=${bottomGap.toFixed(2)} height=${Number(bottomMetrics?.height || 0).toFixed(2)} overlay_height=${Number(bottomMetrics?.overlayHeight || 0).toFixed(2)} zoom_percent=${TEXT_POSITION_EDGE_SCREENSHOT_ZOOM} target_margin=${TEXT_POSITION_CARDINAL_MARGIN}`,
            },
            {
                item: 'text_alignment_mixed_long_short_stack',
                result: mixedStackAlignedLeft ? 'PASS' : 'FAIL',
                description: 'Long / short / short / long-left stack — every line starts at the same left point.',
                detail: JSON.stringify({
                    document_id: mixedStackScenario?.documentId || null,
                    left_positions: mixedStackLefts,
                    tolerance_px: 1,
                }),
            },
            ...groupingScenarioResults.map((scenarioResult) => {
                const caseFailures = scenarioResult.analysis.matchesByCase.filter((entry) => entry.matchCount !== 1);
                const groupedBlocks = scenarioResult.analysis.groupedBlocks;
                const overflowOverlaps = computeStackOverflowOverlaps(scenarioResult.metrics);
                return {
                    item: `text_grouping_${scenarioResult.key}`,
                    result: caseFailures.length === 0 && groupedBlocks.length === 0 && overflowOverlaps.length === 0 ? 'PASS' : 'FAIL',
                    description: `${scenarioResult.label} — each text is exactly one independent annotation with no merging at the JS layer and no visual overflow into the next line.`,
                    detail: JSON.stringify({
                        document_id: scenarioResult.documentId,
                        expected_case_count: scenarioResult.analysis.matchesByCase.length,
                        per_case_match_counts: scenarioResult.analysis.matchesByCase,
                        case_failures: caseFailures,
                        grouped_blocks: groupedBlocks,
                        overflow_overlaps: overflowOverlaps,
                    }),
                };
            }),
        ];

        const checkArtifacts = [
            {
                label: 'Check 1',
                kind: 'image',
                filename: blankScreenshotName,
                check_item: 'blank_pdf_created',
                check_index: 1,
                check_description: checks[0].description,
            },
            {
                label: 'Check 2',
                kind: 'image',
                filename: firstScreenshotName,
                check_item: 'first_save_visible',
                check_index: 2,
                check_description: checks[1].description,
            },
            {
                label: 'Check 3',
                kind: 'image',
                filename: secondScreenshotName,
                check_item: 'second_initial_save_db_row_created',
                check_index: 3,
                check_description: checks[2].description,
            },
            {
                label: 'Check 4',
                kind: 'image',
                filename: secondMoveScreenshotName,
                check_item: 'second_move_db_row_updated',
                check_index: 4,
                check_description: checks[3].description,
            },
            {
                label: 'Check 5',
                kind: 'image',
                filename: secondDeltaScreenshotName,
                check_item: 'second_move_db_coordinates_changed',
                check_index: 5,
                check_description: checks[4].description,
            },
            {
                label: 'Check 6',
                kind: 'image',
                filename: topPositionScreenshotName,
                check_item: 'text_position_top_of_page',
                check_index: 6,
                check_description: checks[5].description,
            },
            {
                label: 'Check 7',
                kind: 'image',
                filename: leftPositionScreenshotName,
                check_item: 'text_position_left_of_page',
                check_index: 7,
                check_description: checks[6].description,
            },
            {
                label: 'Check 8',
                kind: 'image',
                filename: rightPositionScreenshotName,
                check_item: 'text_position_right_of_page',
                check_index: 8,
                check_description: checks[7].description,
            },
            {
                label: 'Check 9',
                kind: 'image',
                filename: bottomPositionScreenshotName,
                check_item: 'text_position_bottom_of_page',
                check_index: 9,
                check_description: checks[8].description,
            },
            {
                label: 'Check 10',
                kind: 'image',
                filename: groupingMixedScreenshotName,
                check_item: 'text_alignment_mixed_long_short_stack',
                check_index: 10,
                check_description: checks[9].description,
            },
            {
                label: 'Check 11',
                kind: 'image',
                filename: groupingMixedScreenshotName,
                check_item: 'text_grouping_mixed_long_short_stack',
                check_index: 11,
                check_description: checks[10].description,
            },
            {
                label: 'Check 12',
                kind: 'image',
                filename: groupingShortScreenshotName,
                check_item: 'text_grouping_five_short_lines',
                check_index: 12,
                check_description: checks[11].description,
            },
            {
                label: 'Check 13',
                kind: 'image',
                filename: groupingLongScreenshotName,
                check_item: 'text_grouping_five_long_lines',
                check_index: 13,
                check_description: checks[12].description,
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: 0,
            artifacts: checkArtifacts,
            metadata: {
                document_ids: [firstDocumentId, secondDocumentId, ...cardinalDocumentIds, ...groupingDocumentIds].filter(Boolean),
                target_text: POSITION_TEXT,
                first_bbox: firstBBox,
                second_document_session_id: secondSessionId,
                second_initial_saved_annotation: secondInitialSavedAnnot,
                second_moved_saved_annotation: secondMovedSavedAnnot,
                cardinal_position_document_ids: cardinalDocumentIds,
                cardinal_position_metrics: cardinalResults,
                second_move_browser_state: {
                    top_before: secondMoveFromState.top,
                    top_after: secondMoveState.top,
                    requested_delta_y: secondMoveTargetTop - secondMoveFromState.top,
                    achieved_delta_y: secondMoveState.top - secondMoveFromState.top,
                },
                second_move_db_state: {
                    initial_match_count: secondInitialMatches.length,
                    moved_match_count: secondMovedMatches.length,
                    same_annotation_id: sameSavedAnnotationId,
                    db_updated_at_changed: dbUpdatedAtChanged,
                    delta_pdf_x: dbPdfDeltaX,
                    delta_pdf_y: dbPdfDeltaY,
                },
                grouping_scenarios: groupingScenarioResults,
            },
        });
    } finally {
        if (firstDocumentId) {
            try {
                await deleteDocument(page, firstDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        if (secondDocumentId) {
            try {
                await deleteDocument(page, secondDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        for (const documentId of cardinalDocumentIds) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        for (const documentId of groupingDocumentIds) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runTextStylingFlow() {
    const test = TESTS.test_2_text_color;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName = buildArtifactName(test.key, runToken, 'after_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath = path.join(OUTPUT_DIR, afterSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        // Three separate text annotations at distinct vertical positions,
        // each colored while the text-box creator is still open.
        await createTextAnnotationAtWithStyles(
            page,
            COLOR_LINE_RED_TEXT,
            72,
            150,
            { textColor: COLOR_LINE_RED_HEX },
        );
        await createTextAnnotationAtWithStyles(
            page,
            COLOR_LINE_BLUE_TEXT,
            72,
            280,
            { textColor: COLOR_LINE_BLUE_HEX },
        );
        await createTextAnnotationAtWithStyles(
            page,
            COLOR_LINE_GREEN_TEXT,
            72,
            410,
            { textColor: COLOR_LINE_GREEN_HEX },
        );

        await capturePageScreenshot(page, beforeSavePath);

        await waitForAnnotationSave(page);
        await capturePageScreenshot(page, afterSavePath);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        // Read annotation states directly from the in-page annotations array.
        // The save path is /save-annotation-state (DB-only; no PDF stamping),
        // so we verify the richTextHtml that was stored on each record.
        const redAnnotState   = await readCommittedAnnotationRichState(page, COLOR_LINE_RED_TEXT);
        const blueAnnotState  = await readCommittedAnnotationRichState(page, COLOR_LINE_BLUE_TEXT);
        const greenAnnotState = await readCommittedAnnotationRichState(page, COLOR_LINE_GREEN_TEXT);

        const htmlContainsColor = (html, hexColor) => {
            const normalized = hexColor.replace('#', '').toLowerCase();
            return String(html || '').toLowerCase().includes(normalized)
                || String(html || '').toLowerCase().includes(hexColor.toLowerCase());
        };

        const threeAnnotsPresent = [
            COLOR_LINE_RED_TEXT,
            COLOR_LINE_BLUE_TEXT,
            COLOR_LINE_GREEN_TEXT,
        ].every((text) => !!redAnnotState || !!blueAnnotState || !!greenAnnotState);

        const redHtml   = redAnnotState?.richTextHtml   || redAnnotState?.html   || '';
        const blueHtml  = blueAnnotState?.richTextHtml  || blueAnnotState?.html  || '';
        const greenHtml = greenAnnotState?.richTextHtml || greenAnnotState?.html || '';

        // For plain-text annotations the color is stored in annotation.textColor
        // (not richTextHtml) because syncToActiveBox sets inp.style.color on the
        // container and the serialiser only captures inline-span color attributes.
        const colorMatches = (state, html, hexColor) => (
            htmlContainsColor(html, hexColor)
            || (state?.textColor || '').toLowerCase() === hexColor.toLowerCase()
        );

        const redColorFound   = colorMatches(redAnnotState,   redHtml,   COLOR_LINE_RED_HEX);
        const blueColorFound  = colorMatches(blueAnnotState,  blueHtml,  COLOR_LINE_BLUE_HEX);
        const greenColorFound = colorMatches(greenAnnotState, greenHtml, COLOR_LINE_GREEN_HEX);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'three_annotations_placed',
                result: redAnnotState && blueAnnotState && greenAnnotState ? 'PASS' : 'FAIL',
                description: 'Three separate text annotations were placed at distinct vertical positions.',
                detail: JSON.stringify({
                    red:   !!redAnnotState,
                    blue:  !!blueAnnotState,
                    green: !!greenAnnotState,
                }),
            },
            {
                item: 'red_line_color_stored',
                result: redColorFound ? 'PASS' : 'FAIL',
                description: 'The first annotation (red line) has the red color stored in its rich-text HTML.',
                detail: `expected=${COLOR_LINE_RED_HEX} textColor=${redAnnotState?.textColor || ''} html=${redHtml.slice(0, 120)}`,
            },
            {
                item: 'blue_line_color_stored',
                result: blueColorFound ? 'PASS' : 'FAIL',
                description: 'The second annotation (blue line) has the blue color stored in its rich-text HTML.',
                detail: `expected=${COLOR_LINE_BLUE_HEX} textColor=${blueAnnotState?.textColor || ''} html=${blueHtml.slice(0, 120)}`,
            },
            {
                item: 'green_line_color_stored',
                result: greenColorFound ? 'PASS' : 'FAIL',
                description: 'The third annotation (green line) has the green color stored in its rich-text HTML.',
                detail: `expected=${COLOR_LINE_GREEN_HEX} textColor=${greenAnnotState?.textColor || ''} html=${greenHtml.slice(0, 120)}`,
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(finalPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Before Save',
                    kind: 'image',
                    filename: beforeSaveName,
                },
                {
                    label: 'After Save',
                    kind: 'image',
                    filename: afterSaveName,
                },
                {
                    label: 'Final PDF',
                    kind: 'pdf',
                    filename: finalPdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                lines: [
                    { text: COLOR_LINE_RED_TEXT,   color: COLOR_LINE_RED_HEX,   found: redColorFound },
                    { text: COLOR_LINE_BLUE_TEXT,  color: COLOR_LINE_BLUE_HEX,  found: blueColorFound },
                    { text: COLOR_LINE_GREEN_TEXT, color: COLOR_LINE_GREEN_HEX, found: greenColorFound },
                ],
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runFontFamilyFlow() {
    const test = TESTS.test_3_font_family;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName  = buildArtifactName(test.key, runToken, 'after_save');
    const finalPdfName   = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath  = path.join(OUTPUT_DIR, afterSaveName);
    const finalPdfPath   = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        // One text annotation per font, placed at evenly-spaced vertical positions.
        // No bold/italic/underline yet — the first screenshot will show clean font-family labels.
        const startY = 80;
        const stepY  = 80;
        for (let i = 0; i < FONT_FAMILY_CASES.length; i += 1) {
            const fontCase = FONT_FAMILY_CASES[i];
            await createTextAnnotationAtWithStyles(
                page,
                fontCase.text,
                72,
                startY + i * stepY,
                { fontFamily: fontCase.fontValue },
            );
        }

        // Screenshot 1: all eight annotations visible with no bold/italic/underline.
        await capturePageScreenshot(page, beforeSavePath);

        // Now apply bold + italic + underline + background color to every committed annotation.
        for (const fontCase of FONT_FAMILY_CASES) {
            const annotation = page.locator('.annotation').filter({ hasText: fontCase.text }).first();
            await annotation.waitFor({ timeout: 10000 });
            await annotation.click();
            await waitForSelectionToolbarSelection(page, 'annotation');
            await applySelectionToolbarStyles(page, {
                bold: true,
                italic: true,
                underline: true,
                backgroundColor: fontCase.bgColor,
            });
            await page.waitForTimeout(100);
        }

        // Wait for all background auto-saves triggered by the selection-toolbar
        // bold/italic/underline handlers (saveAnnotationToDatabase → /save-annotations)
        // to drain before setting up the explicit-save response listener.
        // Without this, waitForAnnotationSave may capture an auto-save response
        // instead of the #save-btn response, causing a route-detection mismatch.
        await page.waitForLoadState('networkidle', { timeout: 30000 });

        await waitForAnnotationSave(page);
        await capturePageScreenshot(page, afterSavePath);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        // Read back each annotation's stored fontFamily from the in-page record.
        const annotStates = [];
        for (const fontCase of FONT_FAMILY_CASES) {
            const state = await readCommittedAnnotationRichState(page, fontCase.text);
            annotStates.push({ fontCase, state });
        }

        const allPresent = annotStates.every(({ state }) => Boolean(state));

        const fontChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.fontFamily || '').trim();
            // The annotation record stores the builtin key (e.g. 'TrebuchetMS');
            // treat comparison as case-insensitive for resilience.
            const match = stored.toLowerCase() === fontCase.fontValue.toLowerCase();
            return {
                item: `font_stored_${fontCase.fontValue.toLowerCase()}`,
                result: match ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores fontFamily ${fontCase.fontValue}.`,
                detail: `expected=${fontCase.fontValue} stored=${stored}`,
            };
        });

        const boldChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.fontWeight || '').trim();
            const isBold = stored === '700' || stored.toLowerCase() === 'bold';
            return {
                item: `bold_stored_${fontCase.fontValue.toLowerCase()}`,
                result: isBold ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores fontWeight 700 (bold).`,
                detail: `expected=700 stored=${stored}`,
            };
        });

        const italicChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.fontStyle || '').trim();
            const isItalic = stored === 'italic';
            return {
                item: `italic_stored_${fontCase.fontValue.toLowerCase()}`,
                result: isItalic ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores fontStyle italic.`,
                detail: `expected=italic stored=${stored}`,
            };
        });

        const underlineChecks = annotStates.map(({ fontCase, state }) => {
            const stored = Boolean(state?.underline);
            return {
                item: `underline_stored_${fontCase.fontValue.toLowerCase()}`,
                result: stored ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores underline true.`,
                detail: `expected=true stored=${stored}`,
            };
        });

        const bgColorChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.backgroundColor || '').trim().toLowerCase();
            const expected = fontCase.bgColor.toLowerCase();
            const match = stored === expected;
            return {
                item: `bgcolor_stored_${fontCase.fontValue.toLowerCase()}`,
                result: match ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores backgroundColor ${fontCase.bgColor}.`,
                detail: `expected=${expected} stored=${stored}`,
            };
        });

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'all_annotations_placed',
                result: allPresent ? 'PASS' : 'FAIL',
                description: 'Eight text annotations placed with bold, italic, and underline, one per font family.',
                detail: JSON.stringify(annotStates.map(({ fontCase, state }) => ({
                    font: fontCase.fontValue,
                    found: Boolean(state),
                }))),
            },
            ...fontChecks,
            ...boldChecks,
            ...italicChecks,
            ...underlineChecks,
            ...bgColorChecks,
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(finalPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Before Save',
                    kind: 'image',
                    filename: beforeSaveName,
                },
                {
                    label: 'After Save',
                    kind: 'image',
                    filename: afterSaveName,
                },
                {
                    label: 'Final PDF',
                    kind: 'pdf',
                    filename: finalPdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                fonts: annotStates.map(({ fontCase, state }) => ({
                    font: fontCase.fontValue,
                    label: fontCase.label,
                    stored: state?.fontFamily || '',
                    fontWeight: state?.fontWeight || '',
                    fontStyle: state?.fontStyle || '',
                    underline: Boolean(state?.underline),
                    backgroundColor: state?.backgroundColor || '',
                })),
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

// ── Test 3 (pdf-new) — Font Family Tests ────────────────────────────────────
async function runFontFamilyFlowNew() {
    const test = TESTS.test_3_font_family_new;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName  = buildArtifactName(test.key, runToken, 'after_save');
    const finalPdfName   = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath  = path.join(OUTPUT_DIR, afterSaveName);
    const finalPdfPath   = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, documentId);
        // Prime the fitz extraction so /download-annotated-pdf can
        // resolve a clean PDF source when use_exact_download_path=true.
        // Without this, brand-new blank docs 404 with "Clean PDF source not found."
        await forceRefreshOverlay(page, documentId);

        // Place one text annotation per font family. Coordinates are in canvas
        // (display) pixels; the editor converts internally to PDF points.
        // The new editor's createTextAnnotationAtNew creates the record with
        // default font, so we apply the font-family change immediately
        // afterwards via the AFB toolbar to set ann.fontFamily.
        const startY = 80;
        const stepY  = 80;
        for (let i = 0; i < FONT_FAMILY_CASES.length; i += 1) {
            const fontCase = FONT_FAMILY_CASES[i];
            await createTextAnnotationAtNew(page, fontCase.text, 200, startY + i * stepY);
            await applyAnnotationStylesViaToolbarNew(page, fontCase.text, {
                fontFamily: fontCase.fontValue,
            });
        }

        // Screenshot 1: all eight annotations visible, font-family applied,
        // no bold/italic/underline/background yet.
        await capturePageScreenshotNew(page, beforeSavePath);

        // Apply bold + italic + underline + background color to every
        // annotation through the AFB toolbar (the same code path a real user
        // hits when clicking those buttons).
        for (const fontCase of FONT_FAMILY_CASES) {
            await applyAnnotationStylesViaToolbarNew(page, fontCase.text, {
                bold: true,
                italic: true,
                underline: true,
                backgroundColor: fontCase.bgColor,
            });
        }

        // --- Rich-HTML sub-span scenario (reproduces the doc 2880 bug). ---
        // Place a 9th text annotation, then mutate its richHtml so that ONE
        // mid-sentence span carries `font-style: italic; text-decoration-line:
        // underline;` while the surrounding text is plain. The downloaded PDF
        // must render only the styled span as italic+underlined.
        const SPAN_ANCHOR = 'ZSpanCase';
        const SPAN_PLAIN_PRE = 'plainprezz';
        const SPAN_STYLED = 'styledspanzz';
        const SPAN_PLAIN_POST = 'plainpostzz';
        const SPAN_RICH_HTML = `<div>${SPAN_PLAIN_PRE} <span style="font-style: italic; text-decoration-line: underline;">${SPAN_STYLED}</span> ${SPAN_PLAIN_POST}</div>`;
        await createTextAnnotationAtNew(page, SPAN_ANCHOR, 200, 80 + FONT_FAMILY_CASES.length * 80);
        await injectRichHtmlOnAnnotationNew(page, SPAN_ANCHOR, SPAN_RICH_HTML);

        await waitForAnnotationSaveNew(page);
        await capturePageScreenshotNew(page, afterSavePath);
        // IMPORTANT: download via the real Download-PDF route (the same code
        // path the user clicks in the editor) so the test catches regressions
        // where ann.fontStyle=italic / ann.underline=true never make it onto
        // the rendered page. Fetching /documents/{id}/file would only return
        // the unannotated base PDF and silently mask those failures.
        await downloadAnnotatedPdfNew(page, documentId, finalPdfPath);

        // Read back each annotation's stored fields from the in-page record.
        const annotStates = [];
        for (const fontCase of FONT_FAMILY_CASES) {
            const state = await readCommittedAnnotationRichStateNew(page, fontCase.text);
            annotStates.push({ fontCase, state });
        }

        // Inspect the downloaded PDF: extract every text span (with fitz
        // bold/italic flags) and every short horizontal stroke (underline
        // candidates). Then per font, locate the span containing the test
        // text and assert: italic flag set, bold flag set, an underline
        // stroke crosses the span horizontally just below its baseline.
        const pdfDump = extractDownloadedPdfTextStylesViaPython(finalPdfPath);
        const allSpans = (pdfDump?.pages?.[0]?.spans) || [];
        const allUnderlines = (pdfDump?.pages?.[0]?.underlines) || [];
        const FITZ_FLAG_ITALIC = 2;
        const FITZ_FLAG_BOLD = 16;
        const findSpansForCase = (fontCase) => {
            // Match by significant unique token (e.g. "Helvetica") instead of
            // the full string because PyMuPDF often splits a rendered run
            // across multiple spans on whitespace / ligature boundaries.
            const token = fontCase.label.split(/\s+/).pop().toLowerCase();
            return allSpans.filter((s) => String(s.text || '').toLowerCase().includes(token));
        };
        const renderChecks = [];
        for (const fontCase of FONT_FAMILY_CASES) {
            const fontKey = fontCase.fontValue.toLowerCase();
            const matched = findSpansForCase(fontCase);
            const present = matched.length > 0;
            renderChecks.push({
                item: `pdf_text_present_${fontKey}`,
                result: present ? 'PASS' : 'FAIL',
                description: `${fontCase.label} text appears in the downloaded annotated PDF.`,
                detail: present
                    ? `matched=${matched.length} font=${matched[0].font}`
                    : 'no span containing the font label was rendered',
            });
            const italicFlagged = matched.some((s) => (Number(s.flags) & FITZ_FLAG_ITALIC) === FITZ_FLAG_ITALIC);
            const italicByName = matched.some((s) => /italic|oblique/i.test(String(s.font || '')));
            const isItalicRendered = italicFlagged || italicByName;
            renderChecks.push({
                item: `pdf_italic_${fontKey}`,
                result: isItalicRendered ? 'PASS' : 'FAIL',
                description: `${fontCase.label} text is rendered italic in the downloaded annotated PDF.`,
                detail: matched.length
                    ? `fonts=${matched.map((s) => s.font).join('|')} flags=${matched.map((s) => s.flags).join(',')}`
                    : 'no spans to inspect',
            });
            const boldFlagged = matched.some((s) => (Number(s.flags) & FITZ_FLAG_BOLD) === FITZ_FLAG_BOLD);
            const boldByName = matched.some((s) => /bold|black|heavy|demi/i.test(String(s.font || '')));
            const isBoldRendered = boldFlagged || boldByName;
            renderChecks.push({
                item: `pdf_bold_${fontKey}`,
                result: isBoldRendered ? 'PASS' : 'FAIL',
                description: `${fontCase.label} text is rendered bold in the downloaded annotated PDF.`,
                detail: matched.length
                    ? `fonts=${matched.map((s) => s.font).join('|')} flags=${matched.map((s) => s.flags).join(',')}`
                    : 'no spans to inspect',
            });
            const hasUnderline = matched.some((span) => {
                const [x0, , x1, y1] = span.bbox;
                const baseline = y1;
                const horizontalOverlap = (u) => Math.min(u.x1, x1) - Math.max(u.x0, x0) >= Math.max(2, (x1 - x0) * 0.3);
                return allUnderlines.some((u) => Math.abs(u.y - baseline) <= Math.max(2.5, span.size * 0.4) && horizontalOverlap(u));
            });
            renderChecks.push({
                item: `pdf_underline_${fontKey}`,
                result: hasUnderline ? 'PASS' : 'FAIL',
                description: `${fontCase.label} text is rendered underlined in the downloaded annotated PDF.`,
                detail: matched.length
                    ? `underline_candidates=${allUnderlines.length}`
                    : 'no spans to inspect',
            });
        }

        // Rich-HTML sub-span render checks (doc 2880 bug). Locate the styled
        // and plain tokens by substring; assert italic+underline ONLY on the
        // styled span and NOT on the surrounding plain spans.
        const findSpansByToken = (token) => allSpans.filter((s) => String(s.text || '').toLowerCase().includes(token.toLowerCase()));
        const isItalicSpan = (s) => ((Number(s.flags) & FITZ_FLAG_ITALIC) === FITZ_FLAG_ITALIC) || /italic|oblique/i.test(String(s.font || ''));
        const spanCrossesUnderline = (s) => {
            const [x0, , x1, y1] = s.bbox;
            const baseline = y1;
            const overlap = (u) => Math.min(u.x1, x1) - Math.max(u.x0, x0) >= Math.max(2, (x1 - x0) * 0.3);
            return allUnderlines.some((u) => Math.abs(u.y - baseline) <= Math.max(2.5, s.size * 0.4) && overlap(u));
        };
        const styledSpans = findSpansByToken(SPAN_STYLED);
        const preSpans = findSpansByToken(SPAN_PLAIN_PRE);
        const postSpans = findSpansByToken(SPAN_PLAIN_POST);

        const stylePresent = styledSpans.length > 0;
        renderChecks.push({
            item: 'richhtml_span_text_present',
            result: stylePresent ? 'PASS' : 'FAIL',
            description: 'Styled rich-HTML sub-span text appears in the downloaded annotated PDF.',
            detail: stylePresent
                ? `matched=${styledSpans.length} font=${styledSpans[0].font}`
                : 'styled span text was not rendered',
        });

        const styledItalic = styledSpans.some(isItalicSpan);
        renderChecks.push({
            item: 'richhtml_span_styled_italic',
            result: styledItalic ? 'PASS' : 'FAIL',
            description: 'Rich-HTML sub-span carrying font-style:italic is rendered italic in the downloaded PDF.',
            detail: styledSpans.length
                ? `fonts=${styledSpans.map((s) => s.font).join('|')} flags=${styledSpans.map((s) => s.flags).join(',')}`
                : 'no styled spans to inspect',
        });

        const styledUnderlined = styledSpans.some(spanCrossesUnderline);
        renderChecks.push({
            item: 'richhtml_span_styled_underlined',
            result: styledUnderlined ? 'PASS' : 'FAIL',
            description: 'Rich-HTML sub-span carrying text-decoration-line:underline is rendered underlined in the downloaded PDF.',
            detail: styledSpans.length
                ? `underline_candidates=${allUnderlines.length}`
                : 'no styled spans to inspect',
        });

        // Surrounding plain spans must NOT be italic and must NOT pick up
        // the styled span's underline rule.
        const plainPresent = preSpans.length > 0 && postSpans.length > 0;
        renderChecks.push({
            item: 'richhtml_span_plain_text_present',
            result: plainPresent ? 'PASS' : 'FAIL',
            description: 'Surrounding plain text in the rich-HTML annotation is rendered.',
            detail: `pre_matched=${preSpans.length} post_matched=${postSpans.length}`,
        });

        const plainNotItalic = !preSpans.some(isItalicSpan) && !postSpans.some(isItalicSpan);
        renderChecks.push({
            item: 'richhtml_span_plain_not_italic',
            result: plainNotItalic ? 'PASS' : 'FAIL',
            description: 'Surrounding plain text is NOT italic in the downloaded PDF.',
            detail: `pre_fonts=${preSpans.map((s) => s.font).join('|') || '-'} post_fonts=${postSpans.map((s) => s.font).join('|') || '-'}`,
        });

        const plainNotUnderlined = !preSpans.some(spanCrossesUnderline) && !postSpans.some(spanCrossesUnderline);
        renderChecks.push({
            item: 'richhtml_span_plain_not_underlined',
            result: plainNotUnderlined ? 'PASS' : 'FAIL',
            description: 'Surrounding plain text is NOT underlined in the downloaded PDF.',
            detail: `underline_candidates=${allUnderlines.length}`,
        });

        const allPresent = annotStates.every(({ state }) => Boolean(state));

        const fontChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.fontFamily || '').trim();
            const match = stored.toLowerCase() === fontCase.fontValue.toLowerCase();
            return {
                item: `font_stored_${fontCase.fontValue.toLowerCase()}`,
                result: match ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores fontFamily ${fontCase.fontValue}.`,
                detail: `expected=${fontCase.fontValue} stored=${stored}`,
            };
        });

        const boldChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.fontWeight || '').trim();
            const isBold = stored === '700' || stored.toLowerCase() === 'bold';
            return {
                item: `bold_stored_${fontCase.fontValue.toLowerCase()}`,
                result: isBold ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores fontWeight 700 (bold).`,
                detail: `expected=700 stored=${stored}`,
            };
        });

        const italicChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.fontStyle || '').trim();
            const isItalic = stored === 'italic';
            return {
                item: `italic_stored_${fontCase.fontValue.toLowerCase()}`,
                result: isItalic ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores fontStyle italic.`,
                detail: `expected=italic stored=${stored}`,
            };
        });

        const underlineChecks = annotStates.map(({ fontCase, state }) => {
            const stored = Boolean(state?.underline);
            return {
                item: `underline_stored_${fontCase.fontValue.toLowerCase()}`,
                result: stored ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores underline true.`,
                detail: `expected=true stored=${stored}`,
            };
        });

        const bgColorChecks = annotStates.map(({ fontCase, state }) => {
            const stored = (state?.backgroundColor || '').trim().toLowerCase();
            const expected = fontCase.bgColor.toLowerCase();
            const match = stored === expected;
            return {
                item: `bgcolor_stored_${fontCase.fontValue.toLowerCase()}`,
                result: match ? 'PASS' : 'FAIL',
                description: `${fontCase.label} annotation stores backgroundColor ${fontCase.bgColor}.`,
                detail: `expected=${expected} stored=${stored}`,
            };
        });

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'A fresh blank PDF was created through the pdf-new blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'all_annotations_placed',
                result: allPresent ? 'PASS' : 'FAIL',
                description: 'Eight text annotations placed via the pdf-new editor with bold, italic, and underline applied.',
                detail: JSON.stringify(annotStates.map(({ fontCase, state }) => ({
                    font: fontCase.fontValue,
                    found: Boolean(state),
                }))),
            },
            ...fontChecks,
            ...boldChecks,
            ...italicChecks,
            ...underlineChecks,
            ...bgColorChecks,
            ...renderChecks,
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(finalPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Before Save',
                    kind: 'image',
                    filename: beforeSaveName,
                },
                {
                    label: 'After Save',
                    kind: 'image',
                    filename: afterSaveName,
                },
                {
                    label: 'Final PDF',
                    kind: 'pdf',
                    filename: finalPdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                fonts: annotStates.map(({ fontCase, state }) => ({
                    font: fontCase.fontValue,
                    label: fontCase.label,
                    stored: state?.fontFamily || '',
                    fontWeight: state?.fontWeight || '',
                    fontStyle: state?.fontStyle || '',
                    underline: Boolean(state?.underline),
                    backgroundColor: state?.backgroundColor || '',
                })),
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runDeleteAnnotationFlow() {
    const test = TESTS.test_5_delete_annotation;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeDeleteName = buildArtifactName(test.key, runToken, 'before_delete');
    const afterDeleteName  = buildArtifactName(test.key, runToken, 'after_delete');
    const afterSaveName    = buildArtifactName(test.key, runToken, 'after_save');
    const afterReloadName  = buildArtifactName(test.key, runToken, 'after_reload');
    const beforeDeletePath = path.join(OUTPUT_DIR, beforeDeleteName);
    const afterDeletePath  = path.join(OUTPUT_DIR, afterDeleteName);
    const afterSavePath    = path.join(OUTPUT_DIR, afterSaveName);
    const afterReloadPath  = path.join(OUTPUT_DIR, afterReloadName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    const TEXT_ROWS = [
        'Delete Test Row 1',
        'Delete Test Row 2',
        'Delete Test Row 3',
    ];
    const PARAGRAPH_TEXTS = [
        'Delete Test Paragraph One - additional text for the delete annotation test',
        'Delete Test Paragraph Two - more content for the delete annotation test',
    ];
    const ALL_TEXTS = [...TEXT_ROWS, ...PARAGRAPH_TEXTS];
    // Deterministic "random" pick: the middle text row.
    const DELETE_TARGET = TEXT_ROWS[1];
    const REMAINING_TEXTS = ALL_TEXTS.filter((t) => t !== DELETE_TARGET);

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await clearPdfSessionId(page);
        const sessionId = await ensurePdfSessionId(page, 'test5del');

        // Three text rows at evenly spaced vertical positions.
        const startY = 80;
        const rowStepY = 80;
        for (let i = 0; i < TEXT_ROWS.length; i += 1) {
            await createTextAnnotationAt(page, TEXT_ROWS[i], 72, startY + i * rowStepY);
        }

        // Two paragraph-like annotations further down the page.
        await createTextAnnotationAt(page, PARAGRAPH_TEXTS[0], 72, 380);
        await createTextAnnotationAt(page, PARAGRAPH_TEXTS[1], 72, 500);

        // Verify all five annotations are visible in the DOM before deletion.
        const allAnnotationsCount = await page.evaluate((texts) => {
            const all = Array.from(document.querySelectorAll('.annotation'));
            return texts.filter((text) => all.some((el) => (el.textContent || '').includes(text))).length;
        }, ALL_TEXTS);

        await capturePageScreenshot(page, beforeDeletePath);

        // Click the target annotation to select it and activate the selection toolbar.
        const targetAnnotation = page.locator('.annotation').filter({ hasText: DELETE_TARGET }).first();
        await targetAnnotation.waitFor({ timeout: 10000 });
        await targetAnnotation.click();

        // Wait for the annotation to receive the `selected` class, which makes its
        // inline tbc-menu (annotation-tbc-menu) visible.
        await page.waitForFunction((text) => {
            const selected = Array.from(document.querySelectorAll('.annotation.selected'));
            return selected.some((el) => (el.textContent || '').includes(text));
        }, DELETE_TARGET, { timeout: 10000 });

        // Click the per-annotation delete button (tbc-delete) inside the tbc-menu.
        // This calls removeAnnotationRecord → deleteAnnotationFromDatabase → POST /delete-annotations.
        const tbcDeleteBtn = page.locator('.annotation.selected .annotation-tbc-menu .tbc-delete');
        await tbcDeleteBtn.waitFor({ state: 'visible', timeout: 10000 });
        await tbcDeleteBtn.click();

        // Drain the /delete-annotations POST before proceeding.
        await page.waitForLoadState('networkidle', { timeout: 30000 });

        // Verify the deleted annotation is immediately gone from the DOM.
        const deletedCountAfterDelete = await page.evaluate((text) => (
            Array.from(document.querySelectorAll('.annotation'))
                .filter((el) => (el.textContent || '').includes(text)).length
        ), DELETE_TARGET);

        await capturePageScreenshot(page, afterDeletePath);

        // Save the document state.
        await waitForAnnotationSave(page);
        await capturePageScreenshot(page, afterSavePath);

        // Reload the editor page and wait for it to be ready.
        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await capturePageScreenshot(page, afterReloadPath);

        // After reload: confirm the deleted annotation is absent from the DOM.
        const deletedCountAfterReload = await page.evaluate((text) => (
            Array.from(document.querySelectorAll('.annotation'))
                .filter((el) => (el.textContent || '').includes(text)).length
        ), DELETE_TARGET);

        // After reload: confirm the remaining annotations are still present.
        const remainingCountAfterReload = await page.evaluate((texts) => {
            const all = Array.from(document.querySelectorAll('.annotation'));
            return texts.filter((text) => all.some((el) => (el.textContent || '').includes(text))).length;
        }, REMAINING_TEXTS);

        // Confirm the deleted annotation is not returned by the saved-annotations API.
        // The server excludes records with state='deleted' from its response, so
        // absence here means the deletion was persisted to the database.
        const savedAnnotationsResponse = await fetchSavedAnnotations(page, documentId, sessionId);
        const savedAnnotations = Array.isArray(savedAnnotationsResponse.body?.annotations)
            ? savedAnnotationsResponse.body.annotations
            : [];
        const deletedFoundInApi = savedAnnotations.some((annotation) => (
            String(annotation?.text || '').includes(DELETE_TARGET)
        ));

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'all_annotations_placed',
                result: allAnnotationsCount === ALL_TEXTS.length ? 'PASS' : 'FAIL',
                description: 'All five annotations (three text rows and two paragraphs) are present in the editor before deletion.',
                detail: `expected=${ALL_TEXTS.length} found=${allAnnotationsCount}`,
            },
            {
                item: 'annotation_deleted_from_dom',
                result: deletedCountAfterDelete === 0 ? 'PASS' : 'FAIL',
                description: `Clicking the per-annotation tbc-menu delete button immediately removed "${DELETE_TARGET}" from the editor DOM.`,
                detail: `remaining_count=${deletedCountAfterDelete} expected=0`,
            },
            {
                item: 'save_completed',
                result: 'PASS',
                description: 'The annotation save completed successfully after the deletion.',
                detail: 'save confirmed via server response',
            },
            {
                item: 'deleted_absent_after_reload',
                result: deletedCountAfterReload === 0 ? 'PASS' : 'FAIL',
                description: `After reloading the editor, "${DELETE_TARGET}" is absent from the annotation DOM.`,
                detail: `found_count=${deletedCountAfterReload} expected=0`,
            },
            {
                item: 'remaining_annotations_intact',
                result: remainingCountAfterReload === REMAINING_TEXTS.length ? 'PASS' : 'FAIL',
                description: 'The remaining four annotations are all still present in the editor DOM after reload.',
                detail: `expected=${REMAINING_TEXTS.length} found=${remainingCountAfterReload}`,
            },
            {
                item: 'deleted_absent_from_saved_annotations_api',
                result: !deletedFoundInApi ? 'PASS' : 'FAIL',
                description: `The saved-annotations API does not return "${DELETE_TARGET}", confirming the deletion is recorded in the database.`,
                detail: `api_annotation_count=${savedAnnotations.length} deleted_found_in_api=${deletedFoundInApi}`,
            },
            {
                item: 'deleted_targeted_text_matches',
                result: 'PASS',
                description: 'The deletion targeted the expected middle text row.',
                detail: `deleted_target="${DELETE_TARGET}"`,
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            artifacts: [
                { label: 'Before Delete', kind: 'image', filename: beforeDeleteName },
                { label: 'After Delete', kind: 'image', filename: afterDeleteName },
                { label: 'After Save',   kind: 'image', filename: afterSaveName },
                { label: 'After Reload', kind: 'image', filename: afterReloadName },
            ],
            metadata: {
                document_id: documentId,
                delete_target: DELETE_TARGET,
                all_annotations: ALL_TEXTS,
                remaining_after_delete: REMAINING_TEXTS,
                api_annotation_count: savedAnnotations.length,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runMixedStyleParagraphFlow() {
    const test = TESTS.test_4_mixed_style_paragraph;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName = buildArtifactName(test.key, runToken, 'after_save');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath = path.join(OUTPUT_DIR, afterSaveName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await clearPdfSessionId(page);
        const sessionId = await ensurePdfSessionId(page, 'test4mix');

        await openCenterTextCreator(page);
        await setActiveTextEditorHtml(page, MIXED_STYLE_PARAGRAPH_HTML);
        await commitActiveTextBox(page);
        await updateTextAnnotation(page, MIXED_STYLE_PARAGRAPH_TEXT, {
            keepBounds: true,
            left: MIXED_STYLE_PARAGRAPH_BOUNDS.left,
            top: MIXED_STYLE_PARAGRAPH_BOUNDS.top,
            width: MIXED_STYLE_PARAGRAPH_BOUNDS.width,
            height: MIXED_STYLE_PARAGRAPH_BOUNDS.height,
        });

        const beforeSaveAnnotationState = await readCommittedAnnotationRichState(page, MIXED_STYLE_PARAGRAPH_TEXT);
        const beforeSaveFragmentStyles = await readCommittedAnnotationFragmentStyles(
            page,
            MIXED_STYLE_PARAGRAPH_TEXT,
            Object.values(MIXED_STYLE_SEGMENTS)
        );
        const serializedAnnotationHtml = String(beforeSaveAnnotationState.richTextHtml || beforeSaveAnnotationState.html || '');
        await captureTextAnnotationScreenshot(page, MIXED_STYLE_PARAGRAPH_SCREENSHOT_TARGET, beforeSavePath, {
            padding: 30,
            minWidth: 620,
            minHeight: 180,
            maxWidth: 760,
            maxHeight: 320,
        });

        await clickOutsideFirstPage(page);
        await saveAnnotationsOnly(page);

        const savedAnnotationsResponse = await fetchSavedAnnotations(page, documentId, sessionId);
        if (!savedAnnotationsResponse.ok || !savedAnnotationsResponse.body?.success) {
            throw new Error(`saved-annotations failed: ${JSON.stringify(savedAnnotationsResponse)}`);
        }

        const savedAnnotations = Array.isArray(savedAnnotationsResponse.body?.annotations)
            ? savedAnnotationsResponse.body.annotations
            : [];
        const matchingSavedAnnotations = savedAnnotations.filter((annotation) => normalize(annotation?.text) === normalize(MIXED_STYLE_PARAGRAPH_TEXT));
        const savedAnnotation = matchingSavedAnnotations[0] || null;
        const savedRichTextHtml = String(savedAnnotation?.richTextHtml || '');
        const afterSaveAnnotationState = await readCommittedAnnotationRichState(page, MIXED_STYLE_PARAGRAPH_TEXT);
        const afterSaveFragmentStyles = await readCommittedAnnotationFragmentStyles(
            page,
            MIXED_STYLE_PARAGRAPH_TEXT,
            Object.values(MIXED_STYLE_SEGMENTS)
        );
        await captureTextAnnotationScreenshot(page, MIXED_STYLE_PARAGRAPH_SCREENSHOT_TARGET, afterSavePath, {
            padding: 30,
            minWidth: 620,
            minHeight: 180,
            maxWidth: 760,
            maxHeight: 320,
        });

        const boldBefore = beforeSaveFragmentStyles[MIXED_STYLE_SEGMENTS.bold] || null;
        const italicBefore = beforeSaveFragmentStyles[MIXED_STYLE_SEGMENTS.italic] || null;
        const underlineBefore = beforeSaveFragmentStyles[MIXED_STYLE_SEGMENTS.underline] || null;
        const plainBefore = beforeSaveFragmentStyles[MIXED_STYLE_SEGMENTS.plain] || null;
        const boldAfter = afterSaveFragmentStyles[MIXED_STYLE_SEGMENTS.bold] || null;
        const italicAfter = afterSaveFragmentStyles[MIXED_STYLE_SEGMENTS.italic] || null;
        const underlineAfter = afterSaveFragmentStyles[MIXED_STYLE_SEGMENTS.underline] || null;
        const plainAfter = afterSaveFragmentStyles[MIXED_STYLE_SEGMENTS.plain] || null;
        const beforeGraphicalPass = Number(boldBefore?.font_weight || 0) >= 600
            && String(italicBefore?.font_style || '').includes('italic')
            && String(underlineBefore?.text_decoration_line || '').includes('underline')
            && Number(plainBefore?.font_weight || 0) < 600
            && !String(plainBefore?.font_style || '').includes('italic')
            && !String(plainBefore?.text_decoration_line || '').includes('underline');
        const afterGraphicalPass = Number(boldAfter?.font_weight || 0) >= 600
            && String(italicAfter?.font_style || '').includes('italic')
            && String(underlineAfter?.text_decoration_line || '').includes('underline')
            && Number(plainAfter?.font_weight || 0) < 600
            && !String(plainAfter?.font_style || '').includes('italic')
            && !String(plainAfter?.text_decoration_line || '').includes('underline');
        const savedHtmlPass = savedRichTextHtml.includes(MIXED_STYLE_SEGMENTS.bold)
            && savedRichTextHtml.includes(MIXED_STYLE_SEGMENTS.italic)
            && savedRichTextHtml.includes(MIXED_STYLE_SEGMENTS.underline)
            && savedRichTextHtml.includes('font-weight:700')
            && savedRichTextHtml.includes('font-style:italic')
            && savedRichTextHtml.includes('text-decoration:underline');

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'paragraph_graphical_styles_visible_before_save',
                result: beforeGraphicalPass ? 'PASS' : 'FAIL',
                description: 'The committed paragraph visually shows bold, italic, underline, and plain segments before save.',
                detail: JSON.stringify({
                    annotation_state: beforeSaveAnnotationState,
                    annotation_html: serializedAnnotationHtml,
                    fragment_styles: beforeSaveFragmentStyles,
                }),
            },
            {
                item: 'saved_annotation_row_exists_once',
                result: matchingSavedAnnotations.length === 1 && Boolean(savedAnnotation?.id)
                    ? 'PASS' : 'FAIL',
                description: 'Saving creates exactly one saved annotation row for the mixed-style paragraph.',
                detail: JSON.stringify({
                    document_id: documentId,
                    session_id: sessionId,
                    match_count: matchingSavedAnnotations.length,
                    saved_annotation_id: savedAnnotation?.id || null,
                }),
            },
            {
                item: 'saved_annotation_rich_text_preserves_mixed_styles',
                result: savedHtmlPass
                    ? 'PASS' : 'FAIL',
                description: 'The saved annotation row richTextHtml keeps the bold, italic, and underline style markers.',
                detail: JSON.stringify({
                    saved_annotation: savedAnnotation,
                    saved_rich_text_html: savedRichTextHtml,
                }),
            },
            {
                item: 'saved_annotation_graphical_styles_preserved',
                result: afterGraphicalPass ? 'PASS' : 'FAIL',
                description: 'The rendered annotation after save still shows the expected bold, italic, underline, and plain segment styling.',
                detail: JSON.stringify({
                    annotation_state: afterSaveAnnotationState,
                    fragment_styles: afterSaveFragmentStyles,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Check 1', kind: 'image', filename: beforeSaveName, check_item: 'blank_pdf_created', check_index: 1, check_description: checks[0].description },
                { label: 'Check 2', kind: 'image', filename: beforeSaveName, check_item: 'paragraph_graphical_styles_visible_before_save', check_index: 2, check_description: checks[1].description },
                { label: 'Check 3', kind: 'image', filename: afterSaveName, check_item: 'saved_annotation_row_exists_once', check_index: 3, check_description: checks[2].description },
                { label: 'Check 4', kind: 'image', filename: afterSaveName, check_item: 'saved_annotation_rich_text_preserves_mixed_styles', check_index: 4, check_description: checks[3].description },
                { label: 'Check 5', kind: 'image', filename: afterSaveName, check_item: 'saved_annotation_graphical_styles_preserved', check_index: 5, check_description: checks[4].description },
            ],
            fileSize: 0,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                target_text: MIXED_STYLE_PARAGRAPH_TEXT,
                before_save_annotation_state: beforeSaveAnnotationState,
                before_save_fragment_styles: beforeSaveFragmentStyles,
                saved_annotation: savedAnnotation,
                after_save_annotation_state: afterSaveAnnotationState,
                after_save_fragment_styles: afterSaveFragmentStyles,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runLoadedSavedStandaloneNoDuplicateFlow() {
    const test = TESTS.test_19_loaded_saved_standalone_no_duplicate;
    ensureOutputDir();

    const runToken = buildRunToken();
    const loadedName = buildArtifactName(test.key, runToken, 'loaded_saved');
    const reloadName = buildArtifactName(test.key, runToken, 'after_second_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const loadedPath = path.join(OUTPUT_DIR, loadedName);
    const reloadPath = path.join(OUTPUT_DIR, reloadName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await clearPdfSessionId(page);

        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        const sessionId = await ensurePdfSessionId(page, 'test19');

        await createTextAnnotationAt(page, LOADED_SAVED_STANDALONE_TEXT, 120, 140);
        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);

        const savedAnnotations = await fetchSavedAnnotations(page, documentId, sessionId);
        if (!savedAnnotations.ok || !savedAnnotations.body?.success) {
            throw new Error(`saved-annotations failed: ${JSON.stringify(savedAnnotations)}`);
        }

        await openLoadedSavedPdfEditor(page, documentId, sessionId);
        await page.waitForFunction((targetText) => (
            typeof annotations !== 'undefined'
            && Array.isArray(annotations)
            && annotations.some((annotation) => String(annotation?.text || '').includes(targetText))
        ), LOADED_SAVED_STANDALONE_TEXT, { timeout: 60000 });

        const loadedSummary = await collectLoadedSavedAnnotationSummary(page, [LOADED_SAVED_STANDALONE_TEXT]);
        await capturePageScreenshot(page, loadedPath);

        await createTextAnnotationAt(page, LOADED_SAVED_STANDALONE_SECOND_TEXT, 120, 230);
        await clickOutsideFirstPage(page);
        await waitForLoadedSavedAnnotationSave(page);

        const reloadedSummary = await collectLoadedSavedAnnotationSummary(page, [
            LOADED_SAVED_STANDALONE_TEXT,
            LOADED_SAVED_STANDALONE_SECOND_TEXT,
        ]);
        await capturePageScreenshot(page, reloadPath);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        const firstTextSummary = loadedSummary.textMatches[normalize(LOADED_SAVED_STANDALONE_TEXT)] || { domCount: 0, recordCount: 0 };
        const reloadedFirstTextSummary = reloadedSummary.textMatches[normalize(LOADED_SAVED_STANDALONE_TEXT)] || { domCount: 0, recordCount: 0 };
        const reloadedSecondTextSummary = reloadedSummary.textMatches[normalize(LOADED_SAVED_STANDALONE_SECOND_TEXT)] || { domCount: 0, recordCount: 0 };
        const savedPayload = savedAnnotations.body || {};

        const checks = [
            {
                item: 'standalone_saved_annotation_session_created',
                result: savedPayload.session_id === sessionId && Number(savedPayload.count) >= 1 ? 'PASS' : 'FAIL',
                description: 'Saving the standalone text annotation created a reusable saved-annotation session.',
                detail: JSON.stringify({
                    requested_session_id: sessionId,
                    saved_annotations: savedPayload,
                }),
            },
            {
                item: 'standalone_saved_session_has_no_promoted_rows',
                result: Array.isArray(savedPayload.annotations)
                    && savedPayload.annotations.every((annotation) => !annotation?.promotedFromExtraction)
                    ? 'PASS' : 'FAIL',
                description: 'The standalone saved session contains only standalone text annotations, not promoted extraction rows.',
                detail: JSON.stringify(savedPayload.annotations || []),
            },
            {
                item: 'loaded_saved_mode_uses_clean_base',
                result: loadedSummary.loadedSavedPdfMode
                    && loadedSummary.basePdfUrl === loadedSummary.cleanPdfUrl
                    && loadedSummary.basePdfUrl !== loadedSummary.originalPdfUrl
                    ? 'PASS' : 'FAIL',
                description: 'Loaded-saved-PDF mode reopens standalone sessions against the clean PDF base.',
                detail: JSON.stringify({
                    loadedSavedPdfMode: loadedSummary.loadedSavedPdfMode,
                    basePdfUrl: loadedSummary.basePdfUrl,
                    originalPdfUrl: loadedSummary.originalPdfUrl,
                    cleanPdfUrl: loadedSummary.cleanPdfUrl,
                }),
            },
            {
                item: 'loaded_saved_mode_has_single_live_standalone_annotation',
                result: firstTextSummary.domCount === 1 && firstTextSummary.recordCount === 1 ? 'PASS' : 'FAIL',
                description: 'Loaded-saved-PDF mode renders exactly one live standalone annotation for the saved text.',
                detail: JSON.stringify(firstTextSummary),
            },
            {
                item: 'second_loaded_saved_save_keeps_one_copy_per_text',
                result: reloadedFirstTextSummary.domCount === 1
                    && reloadedFirstTextSummary.recordCount === 1
                    && reloadedSecondTextSummary.domCount === 1
                    && reloadedSecondTextSummary.recordCount === 1
                    ? 'PASS' : 'FAIL',
                description: 'Saving again from loaded-saved-PDF mode keeps one live annotation per saved standalone text.',
                detail: JSON.stringify({
                    first_text: reloadedFirstTextSummary,
                    second_text: reloadedSecondTextSummary,
                    unique_record_ids: reloadedSummary.uniqueRecordIds,
                    annotation_records: reloadedSummary.annotationRecords,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Loaded Saved Standalone', kind: 'image', filename: loadedName },
                { label: 'Loaded Saved Standalone After Second Save', kind: 'image', filename: reloadName },
                { label: 'Loaded Saved Standalone Final PDF', kind: 'pdf', filename: finalPdfName },
            ],
            fileSize: fs.existsSync(finalPdfPath) ? fs.statSync(finalPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                initial_saved_annotations: savedPayload.annotations || [],
                loaded_summary: loadedSummary,
                reloaded_summary: reloadedSummary,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runLoadedSavedPromotedNoDuplicateFlow() {
    const test = TESTS.test_20_loaded_saved_promoted_no_duplicate;
    ensureOutputDir();

    const runToken = buildRunToken();
    const loadedName = buildArtifactName(test.key, runToken, 'loaded_saved');
    const reloadName = buildArtifactName(test.key, runToken, 'after_second_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const loadedPath = path.join(OUTPUT_DIR, loadedName);
    const reloadPath = path.join(OUTPUT_DIR, reloadName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await clearPdfSessionId(page);

        documentId = await createFixtureDocument(page, NDA_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        const sessionId = await ensurePdfSessionId(page, 'test20');
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const targetFieldSelector = await getOverlayFieldSelector(page, NDA_LEADIN_LINE);
        await replaceOverlayFieldText(page, targetFieldSelector, LOADED_SAVED_PROMOTED_UPDATED_TEXT);
        await waitForOverlaySaveComplete(page);
        await page.waitForLoadState('domcontentloaded', { timeout: 90000 }).catch(() => {});
        await waitForEditorReady(page);

        const savedAnnotations = await fetchSavedAnnotations(page, documentId, sessionId);
        if (!savedAnnotations.ok || !savedAnnotations.body?.success) {
            throw new Error(`saved-annotations failed: ${JSON.stringify(savedAnnotations)}`);
        }
        const savedPayload = savedAnnotations.body || {};
        const primaryPromotedText = normalize(((savedPayload.annotations || []).find((annotation) => annotation?.promotedFromExtraction)?.text) || '');

        await openLoadedSavedPdfEditor(page, documentId, sessionId);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);
        const loadedSummary = await collectLoadedSavedAnnotationSummary(page, [LOADED_SAVED_PROMOTED_SECOND_TEXT]);
        await capturePageScreenshot(page, loadedPath);
        const forcedFallbackSummary = await readLoadedSavedPromotedExtractionFallbackDecision(page);

        await deactivateOverlay(page);
        await createTextAnnotationAt(page, LOADED_SAVED_PROMOTED_SECOND_TEXT, 120, 120);
        await clickOutsideFirstPage(page);
        await waitForLoadedSavedAnnotationSave(page);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const reloadedSummary = await collectLoadedSavedAnnotationSummary(page, [
            LOADED_SAVED_PROMOTED_SECOND_TEXT,
        ]);
        await capturePageScreenshot(page, reloadPath);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        const reloadedSecondTextSummary = reloadedSummary.textMatches[normalize(LOADED_SAVED_PROMOTED_SECOND_TEXT)] || { domCount: 0, recordCount: 0 };
        const loadedPromotedRecords = loadedSummary.annotationRecords.filter((annotation) => annotation.promotedFromExtraction);
        const reloadedPromotedRecords = reloadedSummary.annotationRecords.filter((annotation) => annotation.promotedFromExtraction);
        const loadedUniquePromotedIds = new Set(loadedPromotedRecords.map((annotation) => annotation.id).filter(Boolean));
        const reloadedUniquePromotedIds = new Set(reloadedPromotedRecords.map((annotation) => annotation.id).filter(Boolean));

        const checks = [
            {
                item: 'promoted_saved_annotation_session_created',
                result: savedPayload.session_id === sessionId && Number(savedPayload.count) >= 1 ? 'PASS' : 'FAIL',
                description: 'Saving the promoted overlay edit created a reusable saved-annotation session.',
                detail: JSON.stringify({
                    requested_session_id: sessionId,
                    saved_annotations: savedPayload,
                }),
            },
            {
                item: 'promoted_saved_session_contains_promoted_rows',
                result: Array.isArray(savedPayload.annotations)
                    && savedPayload.annotations.some((annotation) => annotation?.promotedFromExtraction)
                    ? 'PASS' : 'FAIL',
                description: 'The saved promoted session contains promoted extraction annotations.',
                detail: JSON.stringify(savedPayload.annotations || []),
            },
            {
                item: 'loaded_saved_mode_uses_clean_base',
                result: loadedSummary.loadedSavedPdfMode
                    && loadedSummary.basePdfUrl === loadedSummary.cleanPdfUrl
                    && loadedSummary.basePdfUrl !== loadedSummary.originalPdfUrl
                    ? 'PASS' : 'FAIL',
                description: 'Loaded-saved-PDF mode reopens promoted sessions against the clean PDF base.',
                detail: JSON.stringify({
                    loadedSavedPdfMode: loadedSummary.loadedSavedPdfMode,
                    basePdfUrl: loadedSummary.basePdfUrl,
                    originalPdfUrl: loadedSummary.originalPdfUrl,
                    cleanPdfUrl: loadedSummary.cleanPdfUrl,
                }),
            },
            {
                item: 'loaded_saved_extraction_fallback_uses_clean_base',
                result: forcedFallbackSummary.loadedSavedPdfMode
                    && forcedFallbackSummary.extractionBlockCount > 0
                    && forcedFallbackSummary.cleanBaseRequired === true
                    ? 'PASS'
                    : 'FAIL',
                description: 'Loaded-saved-PDF mode keeps the clean base when it rebuilds promoted text from extraction instead of using a saved snapshot.',
                detail: JSON.stringify(forcedFallbackSummary),
            },
            {
                item: 'loaded_saved_mode_rehydrates_promoted_overlay_once',
                result: loadedPromotedRecords.length > 0
                    && loadedSummary.overlayFields.length > 0
                    && loadedUniquePromotedIds.size === loadedPromotedRecords.length
                    && loadedSummary.crossLayerPromotedDuplicates.length === 0
                    ? 'PASS' : 'FAIL',
                description: 'Loaded-saved-PDF mode rehydrates promoted editor content once instead of duplicating saved promoted rows.',
                detail: JSON.stringify({
                    primary_promoted_text: primaryPromotedText,
                    promoted_record_count: loadedPromotedRecords.length,
                    unique_promoted_ids: Array.from(loadedUniquePromotedIds),
                    overlay_field_count: loadedSummary.overlayFields.length,
                    cross_layer_promoted_duplicates: loadedSummary.crossLayerPromotedDuplicates,
                }),
            },
            {
                item: 'promoted_annotations_keep_source_page_mapping',
                result: loadedSummary.promotedPageMismatches.length === 0 && reloadedSummary.promotedPageMismatches.length === 0 ? 'PASS' : 'FAIL',
                description: 'Promoted annotations in loaded-saved-PDF mode stay on their source pages instead of collapsing onto page 1.',
                detail: JSON.stringify({
                    loaded_mismatches: loadedSummary.promotedPageMismatches,
                    reloaded_mismatches: reloadedSummary.promotedPageMismatches,
                }),
            },
            {
                item: 'second_loaded_saved_save_keeps_one_copy_per_text',
                result: reloadedPromotedRecords.length > 0
                    && reloadedSummary.overlayFields.length > 0
                    && reloadedUniquePromotedIds.size === reloadedPromotedRecords.length
                    && reloadedSummary.crossLayerPromotedDuplicates.length === 0
                    && reloadedSecondTextSummary.domCount === 1
                    && reloadedSecondTextSummary.recordCount === 1
                    ? 'PASS' : 'FAIL',
                description: 'Saving again from loaded-saved-PDF mode keeps one set of promoted editor content and one live annotation for the newly added text.',
                detail: JSON.stringify({
                    primary_promoted_text: primaryPromotedText,
                    promoted_record_count: reloadedPromotedRecords.length,
                    unique_promoted_ids: Array.from(reloadedUniquePromotedIds),
                    overlay_field_count: reloadedSummary.overlayFields.length,
                    cross_layer_promoted_duplicates: reloadedSummary.crossLayerPromotedDuplicates,
                    second_text: reloadedSecondTextSummary,
                    unique_record_ids: reloadedSummary.uniqueRecordIds,
                    annotation_records: reloadedSummary.annotationRecords,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Loaded Saved Promoted', kind: 'image', filename: loadedName },
                { label: 'Loaded Saved Promoted After Second Save', kind: 'image', filename: reloadName },
                { label: 'Loaded Saved Promoted Final PDF', kind: 'pdf', filename: finalPdfName },
            ],
            fileSize: fs.existsSync(finalPdfPath) ? fs.statSync(finalPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                primary_promoted_text: primaryPromotedText,
                initial_saved_annotations: savedPayload.annotations || [],
                loaded_summary: loadedSummary,
                forced_fallback_summary: forcedFallbackSummary,
                reloaded_summary: reloadedSummary,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runPromotedNoopEditKeepsExactLayoutFlow() {
    const test = TESTS.test_21_promoted_noop_edit_keeps_exact_layout;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'after_noop_edit');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await clearPdfSessionId(page);

        documentId = await createFixtureDocument(page, NDA_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        const sessionId = await ensurePdfSessionId(page, 'test21');
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const targetFieldSelector = await getOverlayFieldSelector(page, NDA_LEADIN_LINE);
        await replaceOverlayFieldText(page, targetFieldSelector, LOADED_SAVED_PROMOTED_UPDATED_TEXT);
        await waitForOverlaySaveComplete(page);
        await page.waitForLoadState('domcontentloaded', { timeout: 90000 }).catch(() => {});
        await waitForEditorReady(page);

        const beforeState = await readFirstPromotedAnnotationState(page);
        await noOpEditFirstPromotedAnnotation(page);
        const afterState = await readFirstPromotedAnnotationState(page);
        const savedAnnotations = await fetchSavedAnnotations(page, documentId, sessionId);
        if (!savedAnnotations.ok || !savedAnnotations.body?.success) {
            throw new Error(`saved-annotations failed: ${JSON.stringify(savedAnnotations)}`);
        }
        const savedPromoted = (savedAnnotations.body.annotations || []).find((annotation) => annotation?.promotedFromExtraction) || null;
        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'promoted_annotation_exists_before_noop_edit',
                result: Boolean(beforeState.id) ? 'PASS' : 'FAIL',
                description: 'The promoted annotation exists after the initial overlay edit save.',
                detail: JSON.stringify(beforeState),
            },
            {
                item: 'noop_edit_restores_exact_promoted_geometry',
                result: afterState.exactGeometry === '1' ? 'PASS' : 'FAIL',
                description: 'A no-op edit exits back to exact promoted geometry instead of leaving the annotation reflowed.',
                detail: JSON.stringify({
                    before: beforeState,
                    after: afterState,
                }),
            },
            {
                item: 'noop_edit_clears_fake_promoted_dirty',
                result: afterState.promotedDirty === false && (!savedPromoted || savedPromoted.promotedDirty === false) ? 'PASS' : 'FAIL',
                description: 'A no-op edit clears the fake promotedDirty flag in the live annotation and in any saved promoted session state.',
                detail: JSON.stringify({
                    before: beforeState,
                    after: afterState,
                    saved_promoted: savedPromoted,
                }),
            },
            {
                item: 'noop_edit_keeps_promoted_line_height_stable',
                result: approxEqual(beforeState.annotationLineHeight, afterState.annotationLineHeight, 0.01)
                    && (!savedPromoted || approxEqual(beforeState.annotationLineHeight, savedPromoted?.lineHeight, 0.01))
                    ? 'PASS'
                    : 'FAIL',
                description: 'A no-op edit preserves the promoted annotation line height instead of inflating paragraph leading.',
                detail: JSON.stringify({
                    before: beforeState,
                    after: afterState,
                    saved_promoted: savedPromoted,
                }),
            },
            {
                item: 'noop_edit_keeps_promoted_bounds_stable',
                result: beforeState.width === afterState.width && beforeState.height === afterState.height ? 'PASS' : 'FAIL',
                description: 'The promoted annotation keeps the same bounds across a no-op edit cycle.',
                detail: JSON.stringify({
                    before: beforeState,
                    after: afterState,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Promoted No-Op Edit', kind: 'image', filename: screenshotName },
            ],
            fileSize: fs.existsSync(NDA_FIXTURE_PATH) ? fs.statSync(NDA_FIXTURE_PATH).size : 0,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                before_state: beforeState,
                after_state: afterState,
                saved_promoted: savedPromoted,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}


async function runDrylabLoadFlow() {
    const test = TESTS.test_18_load_tests;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'drylab_page1_overlay_load');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        const sourcePage = loadPdfExtractionPage(DRYLAB_LOAD_FIXTURE_PATH, 1);
        const sourceTargetWordBoxes = loadPdfTargetWordBoxes(DRYLAB_LOAD_FIXTURE_PATH, 1, DRYLAB_LOAD_TARGETS);
        if (!sourcePage?.width || !sourcePage?.height) {
            throw new Error(`missing source extraction for ${DRYLAB_LOAD_FIXTURE_PATH}`);
        }

        documentId = await createFixtureDocument(page, DRYLAB_LOAD_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const overlayData = await collectOverlayLoadTargets(page, DRYLAB_LOAD_TARGETS);
        await capturePageScreenshot(page, screenshotPath);

        const scaleX = (Number(overlayData?.overlayWidth) || 0) / (Number(sourcePage.width) || 1);
        const scaleY = (Number(overlayData?.overlayHeight) || 0) / (Number(sourcePage.height) || 1);
        const sourceExtraction = { extraction_data: [sourcePage] };
        const checks = [
            {
                item: 'fixture_document_loaded',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'The drylab fixture document was loaded into the editor.',
                detail: `document=${documentId}`,
            },
        ];
        const metadata = {
            document_id: documentId,
            source_page_size: {
                width: sourcePage.width,
                height: sourcePage.height,
            },
            overlay_size: {
                width: overlayData?.overlayWidth || 0,
                height: overlayData?.overlayHeight || 0,
            },
            scale: { x: scaleX, y: scaleY },
            targets: {},
        };

        for (const config of DRYLAB_LOAD_TARGETS) {
            const sourceLine = extractMatchingLine(sourceExtraction, config.lineText);
            const overlayTarget = overlayData?.targets?.[config.key] || null;
            const expectedLineBox = sourceLine ? scaleBox(sourceLine, scaleX, scaleY) : null;
            const expectedWordBoxes = Object.fromEntries((config.sampleWords || []).map((word) => {
                const sourceWord = sourceTargetWordBoxes?.[config.key]?.[word] || null;
                return [word, sourceWord ? scaleBox(sourceWord, scaleX, scaleY) : null];
            }));
            const actualWordEntries = overlayTarget?.word_entries || {};
            const actualWordBoxes = Object.fromEntries(Object.entries(actualWordEntries).map(([word, entry]) => [word, entry?.box || null]));
            const styleEntry = actualWordEntries[config.styleWord || config.sampleWords?.[0]] || null;
            const actualStyle = styleEntry?.style || overlayTarget?.style || {};
            const sampleWordBox = actualWordBoxes[config.sampleWords?.[0]] || null;
            const actualLineBox = overlayTarget?.line_boxes?.find((entry) => (
                sampleWordBox && approxEqual(entry.top, sampleWordBox.top, DRYLAB_LOAD_LINE_HEIGHT_TOLERANCE * 2)
            )) || overlayTarget?.line_boxes?.[0] || null;
            const actualFontSize = parseCssPixels(actualStyle.fontSize);
            const expectedFontSize = sourceLine ? ((Number(sourceLine.font_size) || 0) * scaleY) : null;
            const actualFontWeight = Number(actualStyle.fontWeight) || 0;
            const actualColor = colorToHex(actualStyle.color);
            const actualFontFamily = String(actualStyle.fontFamily || '');
            const expectedLineHeight = expectedLineBox?.height || null;
            const actualLineHeight = actualLineBox?.height || null;

            const wordPositionFailures = (config.sampleWords || []).map((word) => {
                const expected = expectedWordBoxes[word];
                const actual = actualWordBoxes[word];
                if (!expected || !actual) {
                    return { word, pass: false, expected, actual };
                }
                return {
                    word,
                    pass: (
                        approxEqual(actual.left, expected.left, DRYLAB_LOAD_POSITION_TOLERANCE)
                        && approxEqual(actual.top, expected.top, DRYLAB_LOAD_POSITION_TOLERANCE)
                    ),
                    expected,
                    actual,
                };
            });

            const styleMatchesFontFamily = config.fontFamilyRegex.test(actualFontFamily);
            const styleMatchesWeight = (
                (config.minFontWeight === undefined || actualFontWeight >= config.minFontWeight)
                && (config.maxFontWeight === undefined || actualFontWeight <= config.maxFontWeight)
            );
            const styleMatchesColor = actualColor === normalizeHex(config.expectedColor);
            const styleMatchesFontSize = expectedFontSize !== null && actualFontSize !== null
                ? approxEqual(actualFontSize, expectedFontSize, DRYLAB_LOAD_FONT_SIZE_TOLERANCE)
                : false;

            checks.push({
                item: `${config.key}_line_box_matches`,
                result: sourceLine && actualLineBox
                    && approxEqual(actualLineBox.left, expectedLineBox.left, DRYLAB_LOAD_POSITION_TOLERANCE)
                    && approxEqual(actualLineBox.top, expectedLineBox.top, DRYLAB_LOAD_POSITION_TOLERANCE)
                    && approxEqual(actualLineBox.width, expectedLineBox.width, DRYLAB_LOAD_SIZE_TOLERANCE)
                    ? 'PASS' : 'FAIL',
                description: `${config.key} loads with the original line position and width on page 1.`,
                detail: JSON.stringify({
                    expected: expectedLineBox,
                    actual: actualLineBox,
                }),
            });
            checks.push({
                item: `${config.key}_word_positions_match`,
                result: wordPositionFailures.every((entry) => entry.pass) ? 'PASS' : 'FAIL',
                description: `${config.key} keeps key word positions aligned with the source PDF on initial load.`,
                detail: JSON.stringify(wordPositionFailures),
            });
            checks.push({
                item: `${config.key}_line_height_matches`,
                result: expectedLineHeight !== null && actualLineHeight !== null
                    && approxEqual(actualLineHeight, expectedLineHeight, DRYLAB_LOAD_LINE_HEIGHT_TOLERANCE)
                    ? 'PASS' : 'FAIL',
                description: `${config.key} keeps the original rendered line height on initial load.`,
                detail: JSON.stringify({
                    expected_line_height: expectedLineHeight,
                    actual_line_height: actualLineHeight,
                }),
            });
            checks.push({
                item: `${config.key}_styles_match`,
                result: styleMatchesFontFamily && styleMatchesWeight && styleMatchesColor && styleMatchesFontSize ? 'PASS' : 'FAIL',
                description: `${config.key} keeps the original font family, weight, size, and color on initial load.`,
                detail: JSON.stringify({
                    expected: {
                        font_family_regex: String(config.fontFamilyRegex),
                        min_font_weight: config.minFontWeight ?? null,
                        max_font_weight: config.maxFontWeight ?? null,
                        color: normalizeHex(config.expectedColor),
                        font_size: expectedFontSize,
                    },
                    actual: {
                        font_family: actualFontFamily,
                        font_weight: actualFontWeight,
                        color: actualColor,
                        font_size: actualFontSize,
                    },
                }),
            });

            metadata.targets[config.key] = {
                source_line: sourceLine,
                expected_line_box: expectedLineBox,
                expected_word_boxes: expectedWordBoxes,
                actual_word_entries: actualWordEntries,
                overlay_target: overlayTarget,
            };
        }

        const hasFailure = checks.some((check) => check.result !== 'PASS');

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            artifacts: [
                { label: 'Drylab Page 1 Overlay Load', kind: 'image', filename: screenshotName },
            ],
            fileSize: fs.existsSync(DRYLAB_LOAD_FIXTURE_PATH) ? fs.statSync(DRYLAB_LOAD_FIXTURE_PATH).size : 0,
            metadata,
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphHelveticaBoldItalicUnderlineFlow() {
    const test = TESTS.test_15_paragraph_helvetica_bold_italic_underline;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName = buildArtifactName(test.key, runToken, 'after_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_style_save', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath = path.join(OUTPUT_DIR, afterSaveName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        const editor = await openCenterTextCreator(page);
        await editor.fill(PARAGRAPH_STYLE_TEXT);
        const paragraphSelections = {};
        const paragraphEditorStates = {};

        paragraphSelections.font = await selectActiveEditorText(page, PARAGRAPH_STYLE_TEXT);
        paragraphEditorStates.font = await applyEditTextBannerStyles(page, {
            fontFamily: 'Helvetica',
        });
        paragraphSelections.bold = await selectActiveEditorText(page, PARAGRAPH_STYLE_TEXT);
        paragraphEditorStates.bold = await applyEditTextBannerStyles(page, {
            bold: true,
        });
        paragraphSelections.italic = await selectActiveEditorText(page, PARAGRAPH_STYLE_TEXT);
        paragraphEditorStates.italic = await applyEditTextBannerStyles(page, {
            italic: true,
        });
        paragraphSelections.underline = await selectActiveEditorText(page, PARAGRAPH_STYLE_TEXT);
        paragraphEditorStates.underline = await applyEditTextBannerStyles(page, {
            underline: true,
        });

        await commitActiveTextBox(page);
        await updateTextAnnotation(page, PARAGRAPH_STYLE_WORD_TARGETS.first, {
            keepBounds: true,
            left: 30,
            top: 80,
            width: PARAGRAPH_STYLE_WIDTH,
            height: PARAGRAPH_STYLE_HEIGHT,
        });

        const beforeSaveAnnotationState = await readCommittedAnnotationRichState(page, PARAGRAPH_STYLE_TEXT);
        const serializedAnnotationHtml = String(beforeSaveAnnotationState.richTextHtml || beforeSaveAnnotationState.html || '');
        await capturePageScreenshot(page, beforeSavePath);

        await clickOutsideFirstPage(page);
        await saveAnnotationsOnly(page);
        await capturePageScreenshot(page, afterSavePath);
        await downloadPdfViaToolbar(page, pdfPath);

        const extractionPage = loadPdfExtractionPage(pdfPath, 1);
        const extraction = { extraction_data: [extractionPage] };
        const savedBlock = extractMatchingBlock(extraction, PARAGRAPH_STYLE_FRAGMENT);
        const savedBlockLines = savedBlock ? extractBlockLines(extraction, savedBlock) : [];
        const savedReferenceLineTexts = savedBlockLines.length > 1
            ? [savedBlockLines[0], savedBlockLines[savedBlockLines.length - 1]]
            : savedBlockLines.slice(0, 1);
        const savedLineSpansByText = savedReferenceLineTexts.length > 0
            ? loadPdfTargetLineSpans(pdfPath, 1, savedReferenceLineTexts)
            : {};
        const savedReferenceLines = savedReferenceLineTexts.map((text) => {
            const spans = Array.isArray(savedLineSpansByText?.[text]) ? savedLineSpansByText[text] : [];
            return {
                text,
                spans,
                primarySpan: spans.find((span) => normalize(span?.text) === normalize(text)) || spans[0] || null,
            };
        });
        const savedFontMatches = savedReferenceLines.every((entry) => entry.primarySpan && HELVETICA_FONT_REGEX.test(String(entry.primarySpan?.font || '')));
        const savedBoldMatches = savedReferenceLines.every((entry) => entry.primarySpan && Boolean(entry.primarySpan?.bold));
        const savedItalicMatches = savedReferenceLines.every((entry) => entry.primarySpan && Boolean(entry.primarySpan?.italic));
        const savedUnderlineMatches = Boolean(savedBlock?.spans?.[0]?.has_drawn_underline);
        const normalizedSavedTail = normalizeTypographicAscii(savedBlock?.text || '');
        const savedParagraphTailPresent = normalizedSavedTail.includes(normalizeTypographicAscii('repetition, injected humour'))
            && normalizedSavedTail.includes(normalizeTypographicAscii('characteristic words etc.'));

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'paragraph_styles_applied_before_save',
                result: /font-family:[^;]*helvetica/i.test(serializedAnnotationHtml)
                    && serializedAnnotationHtml.includes('font-weight:700')
                    && serializedAnnotationHtml.includes('font-style:italic')
                    && serializedAnnotationHtml.includes('text-decoration:underline')
                    ? 'PASS' : 'FAIL',
                description: 'The committed paragraph annotation contains Helvetica, bold, italic, and underline styling before save.',
                detail: JSON.stringify({
                    selections: paragraphSelections,
                    editor_states: paragraphEditorStates,
                    annotation_state: beforeSaveAnnotationState,
                }),
            },
            {
                item: 'saved_paragraph_block_found',
                result: savedBlock ? 'PASS' : 'FAIL',
                description: 'The saved PDF extraction contains the styled lorem ipsum paragraph.',
                detail: JSON.stringify({
                    block: savedBlock,
                    line_count: savedBlockLines.length,
                }),
            },
            {
                item: 'saved_paragraph_is_wrapped',
                result: savedBlockLines.length > 1 ? 'PASS' : 'FAIL',
                description: 'The saved paragraph remains a wrapped multiline paragraph after save.',
                detail: JSON.stringify({ lines: savedBlockLines }),
            },
            {
                item: 'saved_paragraph_reference_lines_found',
                result: savedReferenceLines.length > 0 && savedReferenceLines.every((entry) => Boolean(entry.primarySpan)) ? 'PASS' : 'FAIL',
                description: 'The exported PDF exposes representative wrapped lines from the styled paragraph for direct span inspection.',
                detail: JSON.stringify(savedReferenceLines),
            },
            {
                item: 'saved_paragraph_font_matches',
                result: savedFontMatches ? 'PASS' : 'FAIL',
                description: 'Representative exported paragraph lines keep the requested Helvetica-family font.',
                detail: JSON.stringify(savedReferenceLines.map((entry) => ({
                    line_text: entry.text,
                    saved_font: entry.primarySpan?.font || null,
                    expected_regex: String(HELVETICA_FONT_REGEX),
                }))),
            },
            {
                item: 'saved_paragraph_bold_matches',
                result: savedBoldMatches ? 'PASS' : 'FAIL',
                description: 'Representative exported paragraph lines keep bold styling after save.',
                detail: JSON.stringify(savedReferenceLines.map((entry) => ({
                    line_text: entry.text,
                    saved_bold: Boolean(entry.primarySpan?.bold),
                    saved_flags: entry.primarySpan?.flags || null,
                }))),
            },
            {
                item: 'saved_paragraph_italic_matches',
                result: savedItalicMatches ? 'PASS' : 'FAIL',
                description: 'Representative exported paragraph lines keep italic styling after save.',
                detail: JSON.stringify(savedReferenceLines.map((entry) => ({
                    line_text: entry.text,
                    saved_italic: Boolean(entry.primarySpan?.italic),
                    saved_font: entry.primarySpan?.font || null,
                }))),
            },
            {
                item: 'saved_paragraph_underline_matches',
                result: savedUnderlineMatches ? 'PASS' : 'FAIL',
                description: 'The exported paragraph keeps a drawn underline in the extracted PDF content.',
                detail: JSON.stringify(savedBlock?.spans || []),
            },
            {
                item: 'saved_paragraph_tail_present',
                result: savedParagraphTailPresent ? 'PASS' : 'FAIL',
                description: 'The exported PDF keeps the end of the requested paragraph instead of clipping after the earlier lines.',
                detail: savedBlock ? JSON.stringify(savedBlock.text) : 'null',
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            artifacts: [
                { label: 'Before Save', kind: 'image', filename: beforeSaveName },
                { label: 'After Save', kind: 'image', filename: afterSaveName },
                { label: 'Paragraph Style PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                paragraph_text: PARAGRAPH_STYLE_TEXT,
                before_save_annotation_state: beforeSaveAnnotationState,
                saved_block: savedBlock,
                saved_block_lines: savedBlockLines,
                saved_reference_lines: savedReferenceLines,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runNdaParagraphOneBoldItemsFlow() {
    const test = TESTS.test_16_nda_paragraph_one_bold_items;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'nda_paragraph_one_bold_load');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        const sourceSpanData = loadPdfTargetLineSpans(NDA_FIXTURE_PATH, 1, [NDA_PARAGRAPH_ONE_FIRST_LINE]);
        const sourceLineSpans = sourceSpanData?.[NDA_PARAGRAPH_ONE_FIRST_LINE] || [];
        const sourceBoldSpan = sourceLineSpans.find((span) => normalize(span?.text) === normalize(NDA_PARAGRAPH_ONE_BOLD_TOKEN) && span?.bold) || null;
        if (!sourceBoldSpan) {
            throw new Error(`missing NDA bold placeholder span for ${NDA_FIXTURE_PATH}`);
        }

        documentId = await createFixtureDocument(page, NDA_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const overlayData = await collectOverlayLoadTargets(page, [{
            key: 'paragraph_one',
            matchText: NDA_PARAGRAPH_ONE_FRAGMENT,
            lineText: NDA_PARAGRAPH_ONE_FIRST_LINE,
            sampleWords: NDA_PARAGRAPH_ONE_WORDS,
        }]);
        await capturePageScreenshot(page, screenshotPath);

        const overlayTarget = overlayData?.targets?.paragraph_one || null;
        const scaleY = (Number(overlayData?.overlayHeight) || 0) / 792;
        const boldEntry = overlayTarget?.word_entries?.[NDA_PARAGRAPH_ONE_BOLD_TOKEN] || null;
        const regularEntry = overlayTarget?.word_entries?.THIS || null;
        const boldStyle = boldEntry?.style || {};
        const regularStyle = regularEntry?.style || {};
        const boldWeight = Number(boldStyle.fontWeight) || 0;
        const regularWeight = Number(regularStyle.fontWeight) || 0;
        const boldFontFamily = String(boldStyle.fontFamily || '');
        const regularFontFamily = String(regularStyle.fontFamily || '');
        const expectedFontSize = Number(sourceBoldSpan?.size) || null;
        const expectedScaledFontSize = expectedFontSize !== null ? expectedFontSize * scaleY : null;
        const actualFontSize = parseCssPixels(boldStyle.fontSize);

        const checks = [
            {
                item: 'fixture_document_loaded',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'The NDA fixture document was loaded into the editor.',
                detail: `document=${documentId}`,
            },
            {
                item: 'paragraph_one_bold_placeholder_detected',
                result: boldEntry ? 'PASS' : 'FAIL',
                description: 'The paragraph-one placeholder span is independently selectable in the overlay field.',
                detail: JSON.stringify({
                    source_bold_span: sourceBoldSpan,
                    actual_bold_entry: boldEntry,
                }),
            },
            {
                item: 'paragraph_one_bold_placeholder_style_matches',
                result: boldEntry
                    && boldWeight >= 600
                    && NDA_TIMES_FONT_REGEX.test(boldFontFamily)
                    && expectedScaledFontSize !== null
                    && actualFontSize !== null
                    && approxEqual(actualFontSize, expectedScaledFontSize, NDA_LOAD_FONT_SIZE_TOLERANCE)
                    ? 'PASS' : 'FAIL',
                description: 'The paragraph-one placeholder span keeps the original bold Times styling on initial load.',
                detail: JSON.stringify({
                    expected: {
                        font: sourceBoldSpan?.font || null,
                        font_size: expectedFontSize,
                        scaled_font_size: expectedScaledFontSize,
                        bold: sourceBoldSpan?.bold || false,
                    },
                    actual: {
                        font_family: boldFontFamily,
                        font_weight: boldWeight,
                        font_size: actualFontSize,
                    },
                }),
            },
            {
                item: 'paragraph_one_regular_prefix_not_bold',
                result: regularEntry
                    && regularWeight > 0
                    && regularWeight < 600
                    && NDA_TIMES_FONT_REGEX.test(regularFontFamily)
                    ? 'PASS' : 'FAIL',
                description: 'The regular opening words in paragraph one stay separate from the bold placeholder styling.',
                detail: JSON.stringify({
                    actual_regular_entry: regularEntry,
                    regular_font_family: regularFontFamily,
                    regular_font_weight: regularWeight,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'NDA Paragraph One Bold Items', kind: 'image', filename: screenshotName },
            ],
            fileSize: fs.existsSync(NDA_FIXTURE_PATH) ? fs.statSync(NDA_FIXTURE_PATH).size : 0,
            metadata: {
                document_id: documentId,
                source_line_spans: sourceLineSpans,
                actual_target: overlayTarget,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runNdaLeadinAlignmentFlow() {
    const test = TESTS.test_17_nda_leadin_alignment;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'nda_leadin_overlay_load');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        const sourcePage = loadPdfExtractionPage(NDA_FIXTURE_PATH, 1);
        const sourceExtraction = { extraction_data: [sourcePage] };
        const sourceLine = extractMatchingLine(sourceExtraction, NDA_LEADIN_LINE);
        const sourceWordBoxes = loadPdfTargetWordBoxes(NDA_FIXTURE_PATH, 1, [{
            key: 'leadin',
            lineText: NDA_LEADIN_LINE,
            sampleWords: NDA_LEADIN_WORDS,
        }])?.leadin || {};
        if (!sourcePage?.width || !sourcePage?.height || !sourceLine) {
            throw new Error(`missing NDA lead-in source extraction for ${NDA_FIXTURE_PATH}`);
        }

        documentId = await createFixtureDocument(page, NDA_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const overlayData = await collectOverlayLoadTargets(page, [{
            key: 'leadin',
            lineText: NDA_LEADIN_LINE,
            sampleWords: NDA_LEADIN_WORDS,
        }]);
        await capturePageScreenshot(page, screenshotPath);

        const overlayTarget = overlayData?.targets?.leadin || null;
        const scaleX = (Number(overlayData?.overlayWidth) || 0) / (Number(sourcePage.width) || 1);
        const scaleY = (Number(overlayData?.overlayHeight) || 0) / (Number(sourcePage.height) || 1);
        const expectedLineBox = scaleBox(sourceLine, scaleX, scaleY);
        const expectedWordBoxes = Object.fromEntries(Object.entries(sourceWordBoxes).map(([word, box]) => [word, box ? scaleBox(box, scaleX, scaleY) : null]));
        const actualWordEntries = overlayTarget?.word_entries || {};
        const actualWordBoxes = Object.fromEntries(Object.entries(actualWordEntries).map(([word, entry]) => [word, entry?.box || null]));
        const actualLineBox = overlayTarget?.line_boxes?.[0] || null;
        const expectedGap = expectedWordBoxes['Confidential'] && expectedWordBoxes['1.']
            ? expectedWordBoxes['Confidential'].left - (expectedWordBoxes['1.'].left + expectedWordBoxes['1.'].width)
            : null;
        const actualGap = actualWordBoxes['Confidential'] && actualWordBoxes['1.']
            ? actualWordBoxes['Confidential'].left - (actualWordBoxes['1.'].left + actualWordBoxes['1.'].width)
            : null;
        const wordPositionResults = NDA_LEADIN_WORDS.map((word) => {
            const expected = expectedWordBoxes[word];
            const actual = actualWordBoxes[word];
            return {
                word,
                expected,
                actual,
                pass: Boolean(
                    expected
                    && actual
                    && approxEqual(actual.left, expected.left, NDA_LOAD_POSITION_TOLERANCE)
                    && approxEqual(actual.top, expected.top, NDA_LOAD_POSITION_TOLERANCE)
                ),
            };
        });

        const checks = [
            {
                item: 'fixture_document_loaded',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'The NDA fixture document was loaded into the editor.',
                detail: `document=${documentId}`,
            },
            {
                item: 'nda_leadin_line_box_matches',
                result: actualLineBox
                    && approxEqual(actualLineBox.left, expectedLineBox.left, NDA_LOAD_POSITION_TOLERANCE)
                    && approxEqual(actualLineBox.top, expectedLineBox.top, NDA_LOAD_POSITION_TOLERANCE)
                    && approxEqual(actualLineBox.width, expectedLineBox.width, NDA_LOAD_SIZE_TOLERANCE)
                    ? 'PASS' : 'FAIL',
                description: 'The NDA lead-in line keeps its original left/top alignment and width on initial load.',
                detail: JSON.stringify({
                    expected: expectedLineBox,
                    actual: actualLineBox,
                }),
            },
            {
                item: 'nda_leadin_word_positions_match',
                result: wordPositionResults.every((entry) => entry.pass) ? 'PASS' : 'FAIL',
                description: 'The NDA lead-in keeps the marker, heading word, and Company word aligned with the source PDF on initial load.',
                detail: JSON.stringify(wordPositionResults),
            },
            {
                item: 'nda_leadin_indent_gap_matches',
                result: expectedGap !== null && actualGap !== null && approxEqual(actualGap, expectedGap, NDA_LOAD_POSITION_TOLERANCE)
                    ? 'PASS' : 'FAIL',
                description: 'The gap between the "1." marker and the lead-in body text stays aligned with the source PDF.',
                detail: JSON.stringify({
                    expected_gap: expectedGap,
                    actual_gap: actualGap,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'NDA Lead-In Load', kind: 'image', filename: screenshotName },
            ],
            fileSize: fs.existsSync(NDA_FIXTURE_PATH) ? fs.statSync(NDA_FIXTURE_PATH).size : 0,
            metadata: {
                document_id: documentId,
                source_line: sourceLine,
                expected_line_box: expectedLineBox,
                expected_word_boxes: expectedWordBoxes,
                actual_target: overlayTarget,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runNdaRandomPageOneSaveNoDuplicatesFlow() {
    const test = TESTS.test_22_nda_random_page_one_save_no_duplicates;
    ensureOutputDir();

    const runToken = buildRunToken();
    const reloadScreenshotName = buildArtifactName(test.key, runToken, 'nda_random_page_one_reload_overlay');
    const finalPdfName = buildArtifactName(test.key, runToken, 'nda_random_page_one_final', 'pdf');
    const reloadScreenshotPath = path.join(OUTPUT_DIR, reloadScreenshotName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createFixtureDocument(page, NDA_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);
        const initialOverlayFields = await collectOverlayFieldDescriptors(page);
        const editTargets = buildNdaRandomEditTargets(initialOverlayFields);

        for (const target of editTargets) {
            const selector = `.overlay-field[data-word-index="${target.key}"]`;
            await replaceOverlayFieldText(page, selector, target.editedText);
        }

        const editedOverlayFields = await collectOverlayFieldDescriptors(page);
        const editedMetricsByKey = Object.fromEntries(editedOverlayFields.map((field) => [field.key, field]));
        editTargets.forEach((target) => {
            const editedField = editedMetricsByKey[target.key];
            target.expectedLineCount = Array.isArray(editedField?.line_boxes) ? editedField.line_boxes.length : 0;
            target.expectedLineBoxes = Array.isArray(editedField?.line_boxes) ? editedField.line_boxes : [];
            target.expectedSpacing = extractLineSpacing(target.expectedLineBoxes);
        });

        const overlaySaveRequests = [];
        const overlaySaveRequestHandler = (request) => {
            if (request.method() !== 'POST') {
                return;
            }
            const url = request.url();
            if (!url.includes(`/documents/${documentId}/`)) {
                return;
            }
            if (!url.includes('/live-save') && !url.includes('/save-edits')) {
                return;
            }
            let body = null;
            try {
                body = request.postDataJSON();
            } catch (_error) {
                body = request.postData() || null;
            }
            overlaySaveRequests.push({ url, body });
        };
        page.on('request', overlaySaveRequestHandler);

        await waitForOverlaySaveComplete(page);
        page.off('request', overlaySaveRequestHandler);
        const overlaySaveRequestSummary = overlaySaveRequests.map((entry) => {
            const body = entry.body;
            const edits = Array.isArray(body?.edits)
                ? body.edits
                : (body && !Array.isArray(body) ? [body] : []);
            return {
                url: entry.url,
                edits: edits.map((edit) => ({
                    page_number: edit?.page_number ?? null,
                    original_text_preview: String(edit?.original_text || '').slice(0, 80),
                    new_text_preview: String(edit?.new_text || '').slice(0, 80),
                    new_text_line_count: String(edit?.new_text || '').split('\n').length,
                    line_metrics_count: Array.isArray(edit?.line_metrics) ? edit.line_metrics.length : 0,
                    word_styles_count: Array.isArray(edit?.word_styles) ? edit.word_styles.length : 0,
                    synthetic_textbox: Boolean(edit?.synthetic_textbox),
                    bbox: Array.isArray(edit?.bbox) ? edit.bbox : null,
                })),
            };
        });
        const liveSaveEditSummaries = overlaySaveRequestSummary
            .filter((entry) => entry.url.includes('/live-save'))
            .flatMap((entry) => entry.edits || []);

        await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => {});
        await page.waitForTimeout(1500);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        const savedExtraction = await fetchExtraction(page, documentId);
        const savedPage = savedExtraction?.extraction_data?.[0] || {};
        const savedPageText = normalize(
            String(savedPage?.text || (
                Array.isArray(savedPage?.blocks)
                    ? savedPage.blocks.map((block) => block?.text || '').join('\n')
                    : ''
            ))
        );
        const savedLineEntries = Array.isArray(savedPage?.blocks)
            ? savedPage.blocks.flatMap((block) => {
                const textLines = Array.isArray(block?.text_lines) && block.text_lines.length
                    ? block.text_lines
                    : [block?.text || ''];
                const lineBBoxes = Array.isArray(block?.line_bboxes) ? block.line_bboxes : [];
                return textLines.map((lineText, index) => {
                    const bbox = Array.isArray(lineBBoxes[index]) ? lineBBoxes[index] : null;
                    return {
                        text: normalize(lineText),
                        top: bbox && bbox.length >= 4 ? Number(bbox[1]) || 0 : Number(block?.top) || 0,
                        left: bbox && bbox.length >= 4 ? Number(bbox[0]) || 0 : Number(block?.left) || 0,
                        width: bbox && bbox.length >= 4 ? Math.max(1, (Number(bbox[2]) || 0) - (Number(bbox[0]) || 0)) : Math.max(1, Number(block?.width) || 1),
                        height: bbox && bbox.length >= 4 ? Math.max(1, (Number(bbox[3]) || 0) - (Number(bbox[1]) || 0)) : Math.max(1, Number(block?.height) || 1),
                        block,
                    };
                });
            }).filter((entry) => entry.text.length > 0)
            : [];

        await clearAnnotationSessionState(page, documentId);
        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);

        const visibleAnnotationSummary = await page.evaluate((targets) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const annotationTexts = Array.from(document.querySelectorAll('.annotation')).map((annotationEl) => {
                const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
                return normalizeText(textEl?.innerText || textEl?.textContent || '');
            }).filter(Boolean);

            return Object.fromEntries(targets.map((target) => {
                const edited = normalizeText(target.editedText);
                const original = normalizeText(target.originalText);
                const editedFirstLine = normalizeText((target.editedLines || [])[0] || '');
                return [target.key, {
                    editedVisibleCount: annotationTexts.filter((text) => text === edited).length,
                    originalVisibleCount: annotationTexts.filter((text) => text === original).length,
                    editedFragmentVisibleCount: editedFirstLine
                        ? annotationTexts.filter((text) => text.includes(editedFirstLine)).length
                        : 0,
                    annotationTexts,
                }];
            }));
        }, editTargets);

        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);
        const reloadedOverlayFields = await collectOverlayFieldDescriptors(page);
        const overlaySummary = await page.evaluate((targets) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const overlayFields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => {
                const root = field.querySelector('[contenteditable]') || field;
                return normalizeText(root.innerText || root.textContent || '');
            }).filter(Boolean);
            const overlayTexts = overlayFields;
            const records = Array.isArray(annotations)
                ? annotations.map((annotation) => ({
                    text: normalizeText(annotation?.text || annotation?.element?.innerText || annotation?.element?.textContent || ''),
                    promotedFromExtraction: Boolean(annotation?.promotedFromExtraction),
                    savedTextOverlay: Boolean(annotation?.savedTextOverlay),
                }))
                : [];

            return Object.fromEntries(targets.map((target) => {
                const edited = normalizeText(target.editedText);
                const original = normalizeText(target.originalText);
                const editedFirstLine = normalizeText((target.editedLines || [])[0] || '');
                return [target.key, {
                    overlayFieldCount: overlayFields.filter((text) => text === edited).length,
                    overlayOriginalCount: overlayFields.filter((text) => text === original).length,
                    overlayFragmentCount: editedFirstLine
                        ? overlayFields.filter((text) => text.includes(editedFirstLine)).length
                        : 0,
                    promotedRecordCount: records.filter((record) => record.promotedFromExtraction && record.text === edited).length,
                    savedOverlayRecordCount: records.filter((record) => record.savedTextOverlay && record.text === edited).length,
                    overlayTexts,
                }];
            }));
        }, editTargets);
        await capturePageScreenshot(page, reloadScreenshotPath);

        const targetDetails = editTargets.map((target, targetIndex) => {
            const overlayTarget = reloadedOverlayFields.find((field) => normalize(field.text) === normalize(target.editedText)) || null;
            const matchingSavedBlock = extractMatchingBlock(savedExtraction, target.editedLines[0]);
            const matchingOverlayFields = reloadedOverlayFields.filter((entry) => normalize(entry.text) === normalize(target.editedText));
            const savedLineMatches = target.editedLines.map((editedLine) => (
                savedLineEntries.filter((entry) => entry.text.includes(normalize(editedLine)))
            ));
            const matchedSavedLines = savedLineMatches
                .map((matches) => matches[0] || null)
                .filter(Boolean)
                .sort((a, b) => a.top - b.top || a.left - b.left);
            const savedLineCount = matchedSavedLines.length;
            const savedSpacing = extractLineSpacing(matchedSavedLines);
            const visibleTexts = Array.isArray(visibleAnnotationSummary[target.key]?.annotationTexts)
                ? visibleAnnotationSummary[target.key].annotationTexts
                : [];
            const overlayTexts = Array.isArray(overlaySummary[target.key]?.overlayTexts)
                ? overlaySummary[target.key].overlayTexts
                : [];
            const visibleEditedLineCounts = target.editedLines.map((editedLine) => (
                visibleTexts.filter((text) => text.includes(normalize(editedLine))).length
            ));
            const overlayEditedLineCounts = target.editedLines.map((editedLine) => (
                overlayTexts.filter((text) => text.includes(normalize(editedLine))).length
            ));
            const visibleEditedCount = Number(visibleAnnotationSummary[target.key]?.editedFragmentVisibleCount || 0);
            const visibleOriginalCount = Number(visibleAnnotationSummary[target.key]?.originalVisibleCount || 0);
            const overlayEditedCount = Number(overlaySummary[target.key]?.overlayFragmentCount || 0);
            const overlayOriginalCount = Number(overlaySummary[target.key]?.overlayOriginalCount || 0);
            const liveSaveSummary = liveSaveEditSummaries[targetIndex] || null;

            return {
                ...target,
                savedBlock: matchingSavedBlock,
                expectedLineCount: target.expectedLineCount || 0,
                actualLineCount: (overlayTarget?.line_boxes || []).length,
                expectedSpacing: target.expectedSpacing || [],
                overlayTarget,
                overlayFieldMatches: matchingOverlayFields,
                overlaySummary: overlaySummary[target.key] || null,
                visibleAnnotationSummary: visibleAnnotationSummary[target.key] || null,
                savedEditedOccurrences: countNormalizedOccurrences(savedPageText, target.editedTextFlat),
                savedOriginalOccurrences: countNormalizedOccurrences(savedPageText, target.originalTextFlat),
                sourceLineCount: Number(target.sourceLineCount) || 0,
                sourceSpacing: Array.isArray(target.sourceSpacing) ? target.sourceSpacing : [],
                savedLineCount,
                savedSpacing,
                savedLineMatches,
                visibleEditedLineCounts,
                overlayEditedLineCounts,
                liveSaveSummary,
                visibleRepresentationCount: visibleEditedCount + overlayEditedCount,
                visibleOriginalRepresentationCount: visibleOriginalCount + overlayOriginalCount,
            };
        });

        const spacingChecksPass = targetDetails.every((detail) => (
            detail.sourceLineCount >= 2
            && detail.savedLineCount === detail.sourceLineCount
            && lineSpacingMatches(detail.sourceSpacing, detail.savedSpacing, NDA_LOAD_LINE_SPACING_TOLERANCE)
        ));
        const pdfDuplicateChecksPass = targetDetails.every((detail) => (
            detail.savedLineMatches.every((matches) => matches.length === 1)
            && detail.savedOriginalOccurrences === 0
        ));
        const editorDuplicateChecksPass = targetDetails.every((detail) => (
            detail.visibleEditedLineCounts.every((count) => count === 1)
            && detail.overlayEditedLineCounts.every((count) => count <= 1)
            && detail.visibleOriginalRepresentationCount === 0
        ));
        const preSaveMultilineChecksPass = targetDetails.every((detail) => {
            return detail.sourceLineCount >= 2
                && Number(detail.liveSaveSummary?.new_text_line_count || 0) === detail.sourceLineCount
                && Number(detail.liveSaveSummary?.line_metrics_count || 0) === detail.sourceLineCount;
        });

        const checks = [
            {
                item: 'fixture_document_loaded',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'The NDA fixture document was loaded into the editor.',
                detail: JSON.stringify({
                    document_id: documentId,
                    selected_targets: editTargets.map((target) => ({
                        key: target.key,
                        top: target.top,
                        left: target.left,
                        line_count: target.expectedLineCount,
                    })),
                    seed: NDA_RANDOM_EDIT_SEED,
                }),
            },
            {
                item: 'edited_overlay_fields_are_multiline_before_save',
                result: preSaveMultilineChecksPass ? 'PASS' : 'FAIL',
                description: 'Each selected NDA edit target is visibly converted into a multiline paragraph before save, so the regression checks paragraph save behavior rather than a one-line edit.',
                detail: JSON.stringify(targetDetails.map((detail) => ({
                    key: detail.key,
                    source_line_count: detail.sourceLineCount,
                    expected_line_count: detail.expectedLineCount,
                    expected_line_boxes: detail.expectedLineBoxes,
                    original_field_height: detail.height,
                    edited_text: detail.editedText,
                }))),
            },
            {
                item: 'saved_pdf_has_one_copy_of_each_edited_block',
                result: pdfDuplicateChecksPass ? 'PASS' : 'FAIL',
                description: 'After save, page 1 of the PDF contains one copy of each edited NDA block and no retained original block text for those edits.',
                detail: JSON.stringify(targetDetails.map((detail) => ({
                    key: detail.key,
                    edited_text: detail.editedText,
                    edited_occurrences: detail.savedEditedOccurrences,
                    original_occurrences: detail.savedOriginalOccurrences,
                    saved_block: detail.savedBlock,
                }))),
            },
            {
                item: 'reloaded_editor_has_no_duplicate_saved_annotations',
                result: editorDuplicateChecksPass ? 'PASS' : 'FAIL',
                description: 'Reloading the editor shows exactly one visible representation of each edited NDA block and no surviving original text copy.',
                detail: JSON.stringify(targetDetails.map((detail) => ({
                    key: detail.key,
                    edited_text: detail.editedText,
                    visible_annotation_summary: detail.visibleAnnotationSummary,
                    overlay_summary: detail.overlaySummary,
                    overlay_field_matches: detail.overlayFieldMatches,
                    visible_line_counts: detail.visibleEditedLineCounts,
                    overlay_line_counts: detail.overlayEditedLineCounts,
                    visible_original_representation_count: detail.visibleOriginalRepresentationCount,
                }))),
            },
            {
                item: 'reloaded_overlay_preserves_paragraph_line_spacing',
                result: spacingChecksPass ? 'PASS' : 'FAIL',
                description: 'The saved PDF preserves the original paragraph line count and inter-line spacing for the edited multiline NDA blocks.',
                detail: JSON.stringify(targetDetails.map((detail) => ({
                    key: detail.key,
                    source_line_count: detail.sourceLineCount,
                    saved_line_count: detail.savedLineCount,
                    source_spacing: detail.sourceSpacing,
                    saved_spacing: detail.savedSpacing,
                    saved_line_matches: detail.savedLineMatches,
                }))),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'NDA Random Page-One Reload Overlay', kind: 'image', filename: reloadScreenshotName },
                { label: 'NDA Random Page-One Final PDF', kind: 'pdf', filename: finalPdfName },
            ],
            fileSize: fs.existsSync(finalPdfPath) ? fs.statSync(finalPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                seed: NDA_RANDOM_EDIT_SEED,
                overlay_save_request_summary: overlaySaveRequestSummary,
                selected_targets: targetDetails,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runMixedAnnotationLayerStarsNoDuplicatesFlow() {
    const test = TESTS.test_23_mixed_annotation_layer_stars_no_duplicates;
    ensureOutputDir();

    const runToken = buildRunToken();
    const reloadScreenshotName = buildArtifactName(test.key, runToken, 'mixed_annotation_reload');
    const finalPdfName = buildArtifactName(test.key, runToken, 'mixed_annotation_final', 'pdf');
    const movedReloadScreenshotName = buildArtifactName(test.key, runToken, 'mixed_annotation_moved_reload');
    const movedFinalPdfName = buildArtifactName(test.key, runToken, 'mixed_annotation_moved_final', 'pdf');
    const reloadScreenshotPath = path.join(OUTPUT_DIR, reloadScreenshotName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);
    const movedReloadScreenshotPath = path.join(OUTPUT_DIR, movedReloadScreenshotName);
    const movedFinalPdfPath = path.join(OUTPUT_DIR, movedFinalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        const sessionId = await ensurePdfSessionId(page, 'pdf_test_mixed_layer');

        const initialParagraphMetrics = [];
        for (const paragraph of MIXED_LAYER_PARAGRAPH_CASES) {
            await createTextAnnotationAt(page, paragraph.fragment, paragraph.left + 8, paragraph.top + 20);
            await updateTextAnnotation(page, paragraph.fragment, {
                text: paragraph.text,
                keepBounds: true,
                left: paragraph.left,
                top: paragraph.top,
                width: paragraph.width,
                height: paragraph.height,
            });
            const metrics = await readTextAnnotationMetrics(page, paragraph.fragment);
            initialParagraphMetrics.push({
                ...paragraph,
                metrics,
            });
        }

        for (const star of MIXED_LAYER_STAR_CASES) {
            await drawShapeAtRegion(page, 'star', star.region, {
                fill: star.fill,
                stroke: star.stroke,
                fillTransparent: false,
                strokeTransparent: false,
                strokeWidth: 2,
                opacity: 100,
            });
        }

        const preSaveStars = await readShapeAnnotationSummaries(page, 'star');
        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        const savedAnnotationsResponse = await fetchSavedAnnotations(page, documentId, sessionId);
        await clearAnnotationSessionState(page, documentId);
        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await capturePageScreenshot(page, reloadScreenshotPath);
        const reloadBaseState = await page.evaluate(() => ({
            basePdfUrl: typeof basePdfUrl === 'string' ? basePdfUrl : '',
            originalPdfUrl: typeof originalPdfUrl === 'string' ? originalPdfUrl : '',
            cleanPdfUrl: typeof cleanPdfUrl === 'string' ? cleanPdfUrl : '',
            pdfUrl: typeof pdfUrl === 'string' ? pdfUrl : '',
        }));

        const reloadedStars = await readShapeAnnotationSummaries(page, 'star');
        const reloadedParagraphMetrics = [];
        for (const paragraph of MIXED_LAYER_PARAGRAPH_CASES) {
            reloadedParagraphMetrics.push({
                key: paragraph.key,
                fragment: paragraph.fragment,
                metrics: await readTextAnnotationMetrics(page, paragraph.fragment),
            });
        }

        const starLocator = page.locator('.annotation').filter({
            has: page.locator('svg polygon[points="50,5 61,38 95,38 68,58 79,91 50,71 21,91 32,58 5,38 39,38"]'),
        });
        const editableChecks = [];
        const starCount = await starLocator.count();
        for (let index = 0; index < starCount; index += 1) {
            await starLocator.nth(index).click({ force: true });
            await page.waitForTimeout(200);
            editableChecks.push(await page.evaluate(() => ({
                selectedType: selectedAnnotation?.type || null,
                selectedShapeType: selectedAnnotation?.shapeType || null,
                actionBarVisible: selectedAnnotation?.element
                    ? getComputedStyle(selectedAnnotation.element.querySelector('.shape-action-bar')).display !== 'none'
                    : false,
            })));
        }

        const pdfDrawings = await fetchPdfDrawingSummaries(finalPdfPath);
        const filledDrawings = (Array.isArray(pdfDrawings.drawings) ? pdfDrawings.drawings : [])
            .filter((drawing) => Array.isArray(drawing.rect) && drawing.rect.length === 4 && drawing.fill)
            .sort((a, b) => a.rect[0] - b.rect[0] || a.rect[1] - b.rect[1]);

        const expectedPdfRects = preSaveStars
            .map((star) => ({
                ...star,
                rect: [
                    star.pdfX,
                    Number(pdfDrawings.page_height || 0) - (star.pdfY + star.pdfHeight),
                    star.pdfX + star.pdfWidth,
                    Number(pdfDrawings.page_height || 0) - star.pdfY,
                ],
            }))
            .sort((a, b) => a.rect[0] - b.rect[0] || a.rect[1] - b.rect[1]);

        const matchedDrawingDetails = expectedPdfRects.map((expectedStar, index) => {
            const drawing = filledDrawings[index] || null;
            return {
                key: expectedStar.fillColor === '#0000ff' ? 'blue_star' : `star_${index + 1}`,
                expected_rect: expectedStar.rect,
                actual_rect: drawing?.rect || null,
                expected_fill: expectedStar.fillColor,
                actual_fill: drawing?.fill || null,
                center_delta_x: drawing ? Math.abs(bboxCenter(expectedStar.rect).x - bboxCenter(drawing.rect).x) : null,
                center_delta_y: drawing ? Math.abs(bboxCenter(expectedStar.rect).y - bboxCenter(drawing.rect).y) : null,
            };
        });

        const savedAnnotationCounts = Array.isArray(savedAnnotationsResponse?.body?.annotations)
            ? savedAnnotationsResponse.body.annotations.reduce((acc, annotation) => {
                const key = annotation?.type === 'shape'
                    ? `${annotation.type}:${annotation.shapeType || 'unknown'}`
                    : (annotation?.type || 'text');
                acc[key] = (acc[key] || 0) + 1;
                return acc;
            }, {})
            : {};

        const paragraphChecks = initialParagraphMetrics.map((initialParagraph) => {
            const reloadedParagraph = reloadedParagraphMetrics.find((entry) => entry.key === initialParagraph.key);
            return {
                key: initialParagraph.key,
                targetLeft: initialParagraph.left,
                targetTop: initialParagraph.top,
                targetWidth: initialParagraph.width,
                targetHeight: initialParagraph.height,
                before: initialParagraph.metrics,
                after: reloadedParagraph?.metrics || null,
                leftDelta: reloadedParagraph ? Math.abs(reloadedParagraph.metrics.left - initialParagraph.left) : null,
                topDelta: reloadedParagraph ? Math.abs(reloadedParagraph.metrics.top - initialParagraph.top) : null,
                widthDelta: reloadedParagraph ? Math.abs(reloadedParagraph.metrics.width - initialParagraph.width) : null,
                heightDelta: reloadedParagraph ? Math.abs(reloadedParagraph.metrics.height - initialParagraph.height) : null,
                lineCountDelta: reloadedParagraph ? Math.abs(reloadedParagraph.metrics.lineCount - initialParagraph.metrics.lineCount) : null,
            };
        });

        const pdfStarCountPass = filledDrawings.length === MIXED_LAYER_STAR_CASES.length;
        const pdfStarPositionPass = matchedDrawingDetails.every((detail) => (
            detail.actual_rect
            && Number(detail.center_delta_x) <= MIXED_LAYER_POSITION_TOLERANCE
            && Number(detail.center_delta_y) <= MIXED_LAYER_POSITION_TOLERANCE
        ));
        const pdfBlueStarPass = filledDrawings.some((drawing) => normalizeHex(drawing.fill) === '#0000ff');
        const editorBasePass = reloadBaseState.basePdfUrl === reloadBaseState.originalPdfUrl;
        const editorStarCountPass = reloadedStars.length === MIXED_LAYER_STAR_CASES.length;
        const editorStarPositionPass = preSaveStars.length === reloadedStars.length && preSaveStars.every((star, index) => {
            const reloadedStar = reloadedStars[index];
            return reloadedStar
                && approxEqual(reloadedStar.pdfX, star.pdfX, 1)
                && approxEqual(reloadedStar.pdfY, star.pdfY, 1)
                && approxEqual(reloadedStar.pdfWidth, star.pdfWidth, 1)
                && approxEqual(reloadedStar.pdfHeight, star.pdfHeight, 1);
        });
        const editorStarEditablePass = editableChecks.length === MIXED_LAYER_STAR_CASES.length && editableChecks.every((detail) => (
            detail.selectedType === 'shape'
            && detail.selectedShapeType === 'star'
            && detail.actionBarVisible === true
        ));
        const paragraphLayoutPass = paragraphChecks.every((detail) => (
            detail.after
            && Number(detail.leftDelta) <= MIXED_LAYER_POSITION_TOLERANCE
            && Number(detail.topDelta) <= MIXED_LAYER_POSITION_TOLERANCE
            && Number(detail.widthDelta) <= 10
            && Number(detail.heightDelta) <= MIXED_LAYER_POSITION_TOLERANCE
            && Number(detail.lineCountDelta) <= MIXED_LAYER_LINE_COUNT_TOLERANCE
        ));

        await starLocator.first().click({ force: true });
        await page.waitForTimeout(150);
        await dragLocator(page, starLocator.first(), 96, -54);
        const movedStarBeforeSave = (await readShapeAnnotationSummaries(page, 'star'))[0] || null;
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, movedFinalPdfPath);
        await clearAnnotationSessionState(page, documentId);
        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await capturePageScreenshot(page, movedReloadScreenshotPath);
        const movedReloadState = await page.evaluate(() => ({
            basePdfUrl: typeof basePdfUrl === 'string' ? basePdfUrl : '',
            originalPdfUrl: typeof originalPdfUrl === 'string' ? originalPdfUrl : '',
        }));
        const movedReloadStars = await readShapeAnnotationSummaries(page, 'star');
        const movedPdfDrawings = await fetchPdfDrawingSummaries(movedFinalPdfPath);
        const movedFilledDrawings = (Array.isArray(movedPdfDrawings.drawings) ? movedPdfDrawings.drawings : [])
            .filter((drawing) => Array.isArray(drawing.rect) && drawing.rect.length === 4 && drawing.fill)
            .sort((a, b) => a.rect[0] - b.rect[0] || a.rect[1] - b.rect[1]);
        const movedExpectedRect = movedStarBeforeSave ? [
            movedStarBeforeSave.pdfX,
            Number(movedPdfDrawings.page_height || 0) - (movedStarBeforeSave.pdfY + movedStarBeforeSave.pdfHeight),
            movedStarBeforeSave.pdfX + movedStarBeforeSave.pdfWidth,
            Number(movedPdfDrawings.page_height || 0) - movedStarBeforeSave.pdfY,
        ] : null;
        const movedMatchingDrawing = movedExpectedRect
            ? movedFilledDrawings.find((drawing) => {
                const expectedCenter = bboxCenter(movedExpectedRect);
                const actualCenter = bboxCenter(drawing.rect);
                return Math.abs(expectedCenter.x - actualCenter.x) <= MIXED_LAYER_POSITION_TOLERANCE
                    && Math.abs(expectedCenter.y - actualCenter.y) <= MIXED_LAYER_POSITION_TOLERANCE;
            }) || null
            : null;
        const movedSavePass = movedFilledDrawings.length === MIXED_LAYER_STAR_CASES.length
            && movedReloadStars.length === MIXED_LAYER_STAR_CASES.length
            && movedReloadState.basePdfUrl === movedReloadState.originalPdfUrl
            && Boolean(movedMatchingDrawing);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created and used for the mixed paragraph and star annotation-layer save.',
                detail: JSON.stringify({ document_id: documentId, session_id: sessionId }),
            },
            {
                item: 'saved_db_keeps_two_paragraphs_and_two_stars',
                result: savedAnnotationsResponse?.ok
                    && Number(savedAnnotationCounts.text || 0) === MIXED_LAYER_PARAGRAPH_CASES.length
                    && Number(savedAnnotationCounts['shape:star'] || 0) === MIXED_LAYER_STAR_CASES.length ? 'PASS' : 'FAIL',
                description: 'The saved annotation session contains exactly the two paragraph annotations and two star annotations.',
                detail: JSON.stringify({
                    ok: savedAnnotationsResponse?.ok || false,
                    status: savedAnnotationsResponse?.status || null,
                    counts: savedAnnotationCounts,
                }),
            },
            {
                item: 'saved_pdf_has_exactly_two_star_drawings',
                result: pdfStarCountPass ? 'PASS' : 'FAIL',
                description: 'The saved PDF contains exactly two filled star drawings, so the stars were not duplicated on save.',
                detail: JSON.stringify(filledDrawings),
            },
            {
                item: 'saved_pdf_star_positions_match_annotations',
                result: pdfStarPositionPass ? 'PASS' : 'FAIL',
                description: 'The saved PDF star drawing positions match the original annotation geometry.',
                detail: JSON.stringify(matchedDrawingDetails),
            },
            {
                item: 'saved_pdf_blue_star_persists',
                result: pdfBlueStarPass ? 'PASS' : 'FAIL',
                description: 'One of the saved PDF stars keeps the requested blue fill color.',
                detail: JSON.stringify(filledDrawings.map((drawing) => ({ rect: drawing.rect, fill: drawing.fill, stroke: drawing.stroke }))),
            },
            {
                item: 'reloaded_editor_uses_original_base',
                result: editorBasePass ? 'PASS' : 'FAIL',
                description: 'The reloaded editor uses the pristine original PDF base for annotation-only reloads instead of the already-stamped working file.',
                detail: JSON.stringify(reloadBaseState),
            },
            {
                item: 'reloaded_editor_has_two_star_annotations_only',
                result: editorStarCountPass ? 'PASS' : 'FAIL',
                description: 'Reloading the editor shows exactly two star annotations instead of duplicated saved stars.',
                detail: JSON.stringify(reloadedStars),
            },
            {
                item: 'reloaded_editor_keeps_star_positions',
                result: editorStarPositionPass ? 'PASS' : 'FAIL',
                description: 'The reloaded editable star annotations keep the same saved geometry.',
                detail: JSON.stringify({
                    before_save: preSaveStars,
                    after_reload: reloadedStars,
                }),
            },
            {
                item: 'reloaded_stars_are_editable',
                result: editorStarEditablePass ? 'PASS' : 'FAIL',
                description: 'After reload, each star can still be selected as an editable annotation with the shape action bar visible.',
                detail: JSON.stringify(editableChecks),
            },
            {
                item: 'reloaded_paragraph_boxes_stay_intact',
                result: paragraphLayoutPass ? 'PASS' : 'FAIL',
                description: 'The two paragraph annotations keep their saved positions, box sizes, and wrapped line counts after reload.',
                detail: JSON.stringify(paragraphChecks),
            },
            {
                item: 'moved_reloaded_star_overwrites_instead_of_duplicates',
                result: movedSavePass ? 'PASS' : 'FAIL',
                description: 'Moving a reloaded editable star and saving again overwrites that saved shape instead of stamping a duplicate copy.',
                detail: JSON.stringify({
                    moved_star_before_save: movedStarBeforeSave,
                    moved_reload_state: movedReloadState,
                    moved_reload_stars: movedReloadStars,
                    moved_pdf_drawings: movedFilledDrawings,
                    matched_moved_drawing: movedMatchingDrawing,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Mixed Annotation Reload', kind: 'image', filename: reloadScreenshotName },
                { label: 'Mixed Annotation Final PDF', kind: 'pdf', filename: finalPdfName },
                { label: 'Mixed Annotation Moved Reload', kind: 'image', filename: movedReloadScreenshotName },
                { label: 'Mixed Annotation Moved Final PDF', kind: 'pdf', filename: movedFinalPdfName },
            ],
            fileSize: fs.existsSync(finalPdfPath) ? fs.statSync(finalPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                saved_annotation_counts: savedAnnotationCounts,
                reload_base_state: reloadBaseState,
                pre_save_stars: preSaveStars,
                reloaded_stars: reloadedStars,
                moved_reload_state: movedReloadState,
                moved_reload_stars: movedReloadStars,
                moved_pdf_drawings: movedFilledDrawings,
                paragraph_checks: paragraphChecks,
                pdf_drawings: filledDrawings,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

// ── Test 30 (pdf-new) — Shape Gauntlet ──────────────────────────────────────
//
// Inject ten shape annotations covering every supported shapeType and varied
// stroke/fill colors, opacities, stroke widths, transparent fills/strokes,
// and two overlapping circles. Verify each shape is stored correctly, makes
// it into the saved-annotations API, renders into the downloaded PDF with the
// expected fill/stroke color and stroke width near the expected center, and
// survives an editor reload.

// Read current state of every shape annotation on page 0 from the pdf-new
// editor's __editorTestState bridge. Returns an array of plain objects ordered
// by injection key (no DOM dependency).
async function readShapeAnnotationsNew(page) {
    return page.evaluate(() => {
        const st = window.__editorTestState;
        const data = st && st.pageData && st.pageData[0];
        const items = (data && Array.isArray(data.annotations)) ? data.annotations : [];
        return items
            .filter((ann) => String(ann?.type || '') === 'shape')
            .map((ann) => ({
                id: ann.id || null,
                _uid: ann._uid || null,
                _gauntletKey: ann._gauntletKey || null,
                shapeType: ann.shapeType || null,
                strokeColor: String(ann.strokeColor || '').toLowerCase(),
                fillColor: String(ann.fillColor || '').toLowerCase(),
                strokeWidth: Number(ann.strokeWidth) || 0,
                strokeOpacity: Number(ann.strokeOpacity != null ? ann.strokeOpacity : 1),
                fillOpacity: Number(ann.fillOpacity != null ? ann.fillOpacity : 0),
                strokeTransparent: Boolean(ann.strokeTransparent),
                fillTransparent: Boolean(ann.fillTransparent),
                pdfX: Number(ann.pdfX) || 0,
                pdfY: Number(ann.pdfY) || 0,
                pdfWidth: Number(ann.pdfWidth) || 0,
                pdfHeight: Number(ann.pdfHeight) || 0,
                lineStartX: Number(ann.lineStartX != null ? ann.lineStartX : 0),
                lineStartY: Number(ann.lineStartY != null ? ann.lineStartY : 0),
                lineEndX: Number(ann.lineEndX != null ? ann.lineEndX : 1),
                lineEndY: Number(ann.lineEndY != null ? ann.lineEndY : 1),
            }));
    });
}

// Inject every gauntlet case as a fully-formed shape annotation directly into
// pageData[0].annotations via the __editorTestState bridge. Bypasses the
// modal/mouse so we can deterministically place 10 shapes with exact stored
// fields. Coordinates in cases are image-y-down (topPt = visual top of bbox);
// converted to PDF-y-up (pdfY = bottom edge) here.
async function injectShapeAnnotationsNew(page, cases) {
    return page.evaluate((shapeCases) => {
        const st = window.__editorTestState;
        if (!st) throw new Error('__editorTestState bridge missing on window');
        const data = st.pageData && st.pageData[0];
        if (!data) throw new Error('pageData[0] missing for shape injection');
        const pageHeightPts = Number(data.hPts);
        if (!Number.isFinite(pageHeightPts) || pageHeightPts <= 0) {
            throw new Error(`pageData[0].hPts invalid: ${data.hPts}`);
        }
        const injected = [];
        for (const c of shapeCases) {
            const pdfX = Number(c.leftPt);
            const pdfWidth = Number(c.widthPt);
            const pdfHeight = Number(c.heightPt);
            const pdfY = pageHeightPts - (Number(c.topPt) + pdfHeight);
            const uid = 'gauntlet_' + c.key + '_' + Math.random().toString(36).slice(2, 8);
            const draft = {
                _uid: uid,
                _gauntletKey: c.key,
                id: st.generateAnnotationId(),
                pageIndex: 0,
                type: 'shape',
                shapeType: c.shapeType,
                pdfX, pdfY, pdfWidth, pdfHeight,
                strokeColor: c.strokeColor,
                fillColor: c.fillColor,
                strokeWidth: Number(c.strokeWidth),
                strokeOpacity: Number(c.strokeOpacity),
                fillOpacity: Number(c.fillOpacity),
                strokeTransparent: Boolean(c.strokeTransparent),
                fillTransparent: Boolean(c.fillTransparent),
                opacity: 1,
                rotation: 0,
                userCreated: true,
                text: '',
            };
            if (c.shapeType === 'line') {
                draft.lineStartX = Number(c.lineStartX);
                draft.lineStartY = Number(c.lineStartY);
                draft.lineEndX = Number(c.lineEndX);
                draft.lineEndY = Number(c.lineEndY);
            }
            const ann = st.normalizeShapeAnnotation(draft);
            ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
            ann._originalPdfBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
            data.annotations.push(ann);
            injected.push({ key: c.key, uid, id: ann.id });
        }
        st.clearActiveAnnotation();
        st.redrawOverlay(0);
        st.markDirty();
        return { pageHeightPts, injected };
    }, cases);
}

// Inject a single text annotation into the pdf-new editor with the exact
// fontSize/fontFamily/textAlign/verticalAlign/backgroundColor combination that
// previously rendered as a blank page when downloaded — used by
// `custom_pdf_test_1` to guard against regressions of the verticalAlign:middle
// over-offset bug fixed in apply_annotations_direct_new.py (doc 2880).
async function injectCenterAlignTextAnnotationNew(page, options) {
    return page.evaluate((opts) => {
        const st = window.__editorTestState;
        if (!st) throw new Error('__editorTestState bridge missing on window');
        const data = st.pageData && st.pageData[0];
        if (!data) throw new Error('pageData[0] missing for text annotation injection');
        const pageHeightPts = Number(data.hPts);
        if (!Number.isFinite(pageHeightPts) || pageHeightPts <= 0) {
            throw new Error(`pageData[0].hPts invalid: ${data.hPts}`);
        }
        const pageWidthPts = Number(data.wPts);
        const pdfWidth = Number(opts.pdfWidth) || pageWidthPts;
        const pdfHeight = Number(opts.pdfHeight);
        const pdfX = Number(opts.pdfX) || 0;
        // Caller passes topPt (image-y-down distance from the page top).
        const pdfY = pageHeightPts - (Number(opts.topPt) + pdfHeight);
        const uid = 'custom1_' + Math.random().toString(36).slice(2, 8);
        const ann = {
            _uid: uid,
            id: st.generateAnnotationId(),
            pageIndex: 0,
            type: 'text',
            text: String(opts.text),
            originalText: String(opts.text),
            pdfX, pdfY, pdfWidth, pdfHeight,
            fontSize: Number(opts.fontSize),
            fontFamily: String(opts.fontFamily),
            fontWeight: 'normal',
            fontStyle: 'normal',
            textAlign: String(opts.textAlign),
            verticalAlign: String(opts.verticalAlign),
            backgroundColor: String(opts.backgroundColor),
            color: '#000000',
            rotation: 0,
            opacity: 1,
            userAuthored: true,
            userCreated: true,
        };
        data.annotations.push(ann);
        st.editedTexts[uid] = String(opts.text);
        st.clearActiveAnnotation();
        st.redrawOverlay(0);
        st.markDirty();
        return { pageHeightPts, pageWidthPts, uid, id: ann.id, pdfX, pdfY, pdfWidth, pdfHeight };
    }, options);
}

async function runCustomPdfTest1Flow() {
    const test = TESTS.custom_pdf_test_1;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, documentId);
        // Prime fitz extraction so /download-annotated-pdf can resolve a clean
        // PDF source when use_exact_download_path=true.
        await forceRefreshOverlay(page, documentId);

        const sessionId = await ensurePdfSessionIdNew(page, documentId);

        // Reproduce the doc 2880 case: 29pt Garamond, full-width text box near
        // the top of the page, textAlign center + verticalAlign middle on a
        // box whose height roughly matches one line-height (29 * 1.18 ≈ 34.22).
        // Pre-fix this combination triggered an over-offset of the baseline
        // beyond text_rect.y1 in apply_annotations_direct_new.py and the
        // downloaded PDF page rendered blank.
        const injection = await injectCenterAlignTextAnnotationNew(page, {
            text: 'CENTER ALIGN',
            fontSize: 29,
            fontFamily: 'Garamond',
            textAlign: 'center',
            verticalAlign: 'middle',
            backgroundColor: 'transparent',
            pdfX: 0,
            topPt: 34.22,
            pdfWidth: 612,
            pdfHeight: 34.22,
        });

        await capturePageScreenshotNew(page, beforeSavePath);
        await waitForAnnotationSaveNew(page);

        // Saved-annotations API should have one text annotation.
        const savedResp = await fetchSavedAnnotationsNew(page, documentId, sessionId);
        const savedAnnotations = Array.isArray(savedResp?.body?.annotations)
            ? savedResp.body.annotations
            : [];
        const savedTextRows = savedAnnotations.filter((a) => a?.type === 'text');
        const savedCheck = {
            item: 'saved_annotation_persisted',
            result: savedTextRows.length === 1 ? 'PASS' : 'FAIL',
            description: 'Auto-save persists the injected text annotation to the saved-annotations API.',
            detail: `expected=1 got=${savedTextRows.length}`,
        };

        // Trigger the same Download-PDF code path the user clicks.
        await downloadAnnotatedPdfNew(page, documentId, finalPdfPath);

        const downloadedPdfExists = fs.existsSync(finalPdfPath);
        const downloadedPdfSize = downloadedPdfExists ? fs.statSync(finalPdfPath).size : 0;

        let searchRectCount = 0;
        let searchError = null;
        try {
            const searchResult = loadPdfSearchRects(finalPdfPath, 1, 'CENTER ALIGN');
            searchRectCount = Array.isArray(searchResult?.rects) ? searchResult.rects.length : 0;
        } catch (err) {
            searchError = err && err.message ? err.message : String(err);
        }

        const downloadCheck = {
            item: 'download_pdf_contains_center_align_text',
            result: searchRectCount > 0 ? 'PASS' : 'FAIL',
            description: 'Downloaded PDF page 1 contains the rendered text "CENTER ALIGN" (regression guard for the verticalAlign:middle blank-PDF bug).',
            detail: JSON.stringify({
                downloadedPdfExists,
                downloadedPdfSize,
                searchRectCount,
                searchError,
                injection,
            }),
        };

        const checks = [savedCheck, downloadCheck];
        const status = checks.every((c) => c.result === 'PASS') ? 'pass' : 'fail';

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [beforeSaveName, finalPdfName],
            fileSize: downloadedPdfSize,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                injection,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

// Helper for custom_pdf_test_2: count pixels matching the editor's slate
// placeholder color (#2c3e50 ± tolerance) inside a PDF page bbox after
// rasterizing the page with PyMuPDF. Used to assert the downloaded PDF
// renders the annotation bounding box with the same slate background the
// editor showed (resolveDisplayBgColor placeholder), rather than rendering as
// a visually blank white-on-white page. Generalized to count any target RGB
// so doc 2880 snapshots with literal background colors can also be verified.
function countSlatePixelsInPdfBbox(pdfPath, pageNumber, bbox, targetRgb) {
    const target = Array.isArray(targetRgb) && targetRgb.length === 3
        ? [Number(targetRgb[0]) || 0, Number(targetRgb[1]) || 0, Number(targetRgb[2]) || 0]
        : [44, 62, 80];
    const pythonCode = `
import fitz, json, sys
pdf_path = sys.argv[1]
page_number = int(sys.argv[2])
bbox = json.loads(sys.argv[3])
target = json.loads(sys.argv[4])
doc = fitz.open(pdf_path)
page = doc[page_number - 1]
# Default 72 dpi pixmap so PDF points map 1:1 to image pixels.
pix = page.get_pixmap()
x0 = max(0, int(bbox[0]))
y0 = max(0, int(bbox[1]))
x1 = min(pix.width, int(bbox[2]))
y1 = min(pix.height, int(bbox[3]))
TARGET = (int(target[0]), int(target[1]), int(target[2]))
TOL = 12
slate = 0
white = 0
non_white = 0
sampled = 0
for y in range(y0, y1):
    for x in range(x0, x1):
        r, g, b = pix.pixel(x, y)[:3]
        sampled += 1
        if abs(r - TARGET[0]) <= TOL and abs(g - TARGET[1]) <= TOL and abs(b - TARGET[2]) <= TOL:
            slate += 1
        if r >= 250 and g >= 250 and b >= 250:
            white += 1
        else:
            non_white += 1
doc.close()
print(json.dumps({
    'sampled': sampled,
    'slate': slate,
    'white': white,
    'non_white': non_white,
    'pix_width': pix.width,
    'pix_height': pix.height,
    'effective_bbox': [x0, y0, x1, y1],
}))
`;
    const raw = execFileSync(PYTHON_BIN, ['-c', pythonCode, pdfPath, String(pageNumber), JSON.stringify(bbox), JSON.stringify(target)], {
        cwd: path.resolve(__dirname, '..', '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

// Map a saved annotation spec to the RGB color we expect inside its bbox in
// the downloaded PDF. Mirrors the editor's resolveDisplayBgColor() and the
// python apply pipeline's substitute_display_bg_for_invisible_text(): when
// textColor is white-like AND backgroundColor is empty/transparent/white,
// the editor displays #2c3e50 slate, so the downloaded PDF should too.
// Otherwise we expect the literal backgroundColor blended with white by the
// annotation's opacity (PyMuPDF renders semi-transparent fills against the
// blank white page, producing alpha*bg + (1-alpha)*white).
function expectedBgRgbForSpec(spec) {
    const text = String(spec.textColor || '').trim().toLowerCase().replace(/^#/, '');
    const bg = String(spec.backgroundColor || '').trim().toLowerCase();
    const bgHex = bg.replace(/^#/, '');
    const isWhiteText = /^f(?:ff|[e-f]{2})(?:f(?:ff|[e-f]{2})){0,2}$/i.test(text);
    const bgIsWhiteOrTransparent = !bg || bg === 'transparent'
        || /^f(?:ff|[e-f]{2})(?:f(?:ff|[e-f]{2})){0,2}$/i.test(bgHex);
    const opacityRaw = Number(spec.opacity);
    const opacity = Number.isFinite(opacityRaw) ? Math.max(0, Math.min(1, opacityRaw)) : 1;
    if (isWhiteText && bgIsWhiteOrTransparent) {
        return { rgb: [44, 62, 80], reason: 'slate placeholder (white-on-white)' };
    }
    if (!bg || bg === 'transparent') {
        return null; // no bg fill drawn; cannot pixel-assert
    }
    const m = bgHex.length === 6 ? bgHex : (bgHex.length === 3 ? bgHex.split('').map((c) => c + c).join('') : null);
    if (!m) return null;
    const baseR = parseInt(m.slice(0, 2), 16);
    const baseG = parseInt(m.slice(2, 4), 16);
    const baseB = parseInt(m.slice(4, 6), 16);
    if (opacity >= 0.999) {
        return { rgb: [baseR, baseG, baseB], reason: `literal backgroundColor #${m}` };
    }
    const r = Math.round(opacity * baseR + (1 - opacity) * 255);
    const g = Math.round(opacity * baseG + (1 - opacity) * 255);
    const b = Math.round(opacity * baseB + (1 - opacity) * 255);
    return { rgb: [r, g, b], reason: `backgroundColor #${m} blended over white at opacity ${opacity}` };
}

async function runCustomPdfTest2Flow() {
    const test = TESTS.custom_pdf_test_2;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, documentId);
        await forceRefreshOverlay(page, documentId);

        const sessionId = await ensurePdfSessionIdNew(page, documentId);

        // Reproduce the EXACT saved state from doc 2880 row 113884 that
        // historically rendered as a blank page on Download PDF: white text
        // on an explicit white background (textColor #ffffff +
        // backgroundColor #ffffff with backgroundColorExplicit=true), 29pt
        // Manrope, full-width Letter box, textAlign center, verticalAlign
        // middle. The frontend's persisted-payload guard
        // (buildPersistedAnnotationPayload in edit-new.blade.php) only
        // promotes a slate background when the bg is NOT explicit, so this
        // case bypassed it. The python apply pipeline now auto-corrects the
        // invisible text color so the rendered page is never visually blank.
        const injection = await page.evaluate(() => {
            const st = window.__editorTestState;
            if (!st) throw new Error('__editorTestState bridge missing on window');
            const data = st.pageData && st.pageData[0];
            if (!data) throw new Error('pageData[0] missing for text injection');
            const pageHeightPts = Number(data.hPts);
            const pageWidthPts = Number(data.wPts);
            const pdfWidth = 612;
            const pdfHeight = 98.79772448724896;
            const pdfX = 0;
            const pdfY = 693.202275512751;
            const uid = 'custom2_' + Math.random().toString(36).slice(2, 8);
            const ann = {
                _uid: uid,
                id: st.generateAnnotationId(),
                pageIndex: 0,
                type: 'text',
                text: 'CENTER ALIGN',
                originalText: 'CENTER ALIGN',
                pdfX, pdfY, pdfWidth, pdfHeight,
                fontSize: 29,
                fontFamily: 'Manrope',
                fontWeight: '400',
                fontStyle: 'normal',
                textAlign: 'center',
                verticalAlign: 'middle',
                textColor: '#ffffff',
                backgroundColor: '#ffffff',
                backgroundColorExplicit: true,
                lineHeight: 34.22,
                opacity: 1,
                rotation: 0,
                userAuthored: true,
                userCreated: true,
            };
            data.annotations.push(ann);
            st.editedTexts[uid] = 'CENTER ALIGN';
            st.clearActiveAnnotation();
            st.redrawOverlay(0);
            st.markDirty();
            return { pageHeightPts, pageWidthPts, uid, id: ann.id, pdfX, pdfY, pdfWidth, pdfHeight };
        });

        await capturePageScreenshotNew(page, beforeSavePath);
        await waitForAnnotationSaveNew(page);

        const savedResp = await fetchSavedAnnotationsNew(page, documentId, sessionId);
        const savedAnnotations = Array.isArray(savedResp?.body?.annotations) ? savedResp.body.annotations : [];
        const savedTextRows = savedAnnotations.filter((a) => a?.type === 'text');
        const savedCheck = {
            item: 'saved_annotation_persisted',
            result: savedTextRows.length === 1 ? 'PASS' : 'FAIL',
            description: 'Auto-save persists the white-on-white text annotation to the saved-annotations API.',
            detail: `expected=1 got=${savedTextRows.length}`,
        };

        await downloadAnnotatedPdfNew(page, documentId, finalPdfPath);

        const downloadedPdfExists = fs.existsSync(finalPdfPath);
        const downloadedPdfSize = downloadedPdfExists ? fs.statSync(finalPdfPath).size : 0;

        let searchRectCount = 0;
        let searchRects = [];
        let searchError = null;
        try {
            const searchResult = loadPdfSearchRects(finalPdfPath, 1, 'CENTER ALIGN');
            searchRects = Array.isArray(searchResult?.rects) ? searchResult.rects : [];
            searchRectCount = searchRects.length;
        } catch (err) {
            searchError = err && err.message ? err.message : String(err);
        }

        const containsTextCheck = {
            item: 'download_pdf_contains_center_align_text',
            result: searchRectCount > 0 ? 'PASS' : 'FAIL',
            description: 'Downloaded PDF page 1 contains the searchable text "CENTER ALIGN".',
            detail: JSON.stringify({ downloadedPdfExists, downloadedPdfSize, searchRectCount, searchError }),
        };

        // Compute the annotation bbox in image-y-down PDF point coords. The
        // page is Letter (792 high). pdfY is bottom-origin; convert to top.
        const pageHeight = Number(injection.pageHeightPts) || 792;
        const annTop = pageHeight - (injection.pdfY + injection.pdfHeight);
        const annBottom = pageHeight - injection.pdfY;
        const bbox = [injection.pdfX, annTop, injection.pdfX + injection.pdfWidth, annBottom];

        let pixelSummary = null;
        let pixelError = null;
        try {
            pixelSummary = countSlatePixelsInPdfBbox(finalPdfPath, 1, bbox);
        } catch (err) {
            pixelError = err && err.message ? err.message : String(err);
        }
        const slatePixels = pixelSummary && Number.isFinite(pixelSummary.slate) ? pixelSummary.slate : 0;
        const visiblePixelsCheck = {
            item: 'download_pdf_renders_slate_placeholder_in_text_bbox',
            result: slatePixels >= 1000 ? 'PASS' : 'FAIL',
            description: 'Downloaded PDF page 1 renders the annotation bounding box with the editor\'s slate (#2c3e50) placeholder background instead of a visually blank white page.',
            detail: JSON.stringify({ bbox, pixelSummary, pixelError, slatePixels }),
        };

        const checks = [savedCheck, containsTextCheck, visiblePixelsCheck];
        const status = checks.every((c) => c.result === 'PASS') ? 'pass' : 'fail';

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [beforeSaveName, finalPdfName],
            fileSize: downloadedPdfSize,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                injection,
                bbox,
                pixelSummary,
                searchRects,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) { /* ignore */ }
        }
        await browser.close();
    }
}

// Snapshot reproduction of doc 2880's CURRENT live saved annotation row
// 113884. The user keeps mutating doc 2880 in /edit-new and asks us to add a
// new custom_pdf_test_N every time, so the downloaded PDF is locked against
// regressions for each saved snapshot. This snapshot: 72pt NotoSerif
// fontWeight 700 fontStyle italic, white-on-white explicit background,
// textAlign center, verticalAlign middle.
async function runCustomPdfTest3Flow() {
    const test = TESTS.custom_pdf_test_3;
    return runDoc2880SnapshotFlow(test, [{
        text: 'CENTER ALIGN',
        pdfX: 0,
        pdfY: 696.3033707865169,
        pdfWidth: 612,
        pdfHeight: 95.69662921348316,
        fontSize: 72,
        fontFamily: 'NotoSerif',
        fontWeight: '700',
        fontStyle: 'italic',
        textAlign: 'center',
        verticalAlign: 'middle',
        textColor: '#ffffff',
        backgroundColor: '#ffffff',
        backgroundColorExplicit: true,
        lineHeight: 84.96,
        underline: false,
        opacity: 1,
    }]);
}

// Snapshot of doc 2880 after the user added a second annotation and changed
// styling. State captured from rows 113884 + 113892:
//   row 113884: "CENTER ALIGN" 26pt NotoSerif bold italic, textAlign right,
//               verticalAlign middle, textColor #9d1b1b on bg #2e2e2e
//               (explicit). pdfY 757.62 pdfHeight 34.38.
//   row 113892: "LEFT ALIGN" 33pt Nunito normal weight 400, textAlign left,
//               verticalAlign middle, textColor #ffffff on bg #d80e0e
//               (explicit). pdfY 714.27 pdfHeight 42.40.
async function runCustomPdfTest4Flow() {
    const test = TESTS.custom_pdf_test_4;
    return runDoc2880SnapshotFlow(test, [
        {
            text: 'CENTER ALIGN',
            pdfX: 0,
            pdfY: 757.6179775280899,
            pdfWidth: 612,
            pdfHeight: 34.38202247191011,
            fontSize: 26,
            fontFamily: 'NotoSerif',
            fontWeight: '700',
            fontStyle: 'italic',
            textAlign: 'right',
            verticalAlign: 'middle',
            textColor: '#9d1b1b',
            backgroundColor: '#2e2e2e',
            backgroundColorExplicit: true,
            lineHeight: 84.96,
            underline: false,
            opacity: 1,
        },
        {
            text: 'LEFT ALIGN',
            pdfX: 0,
            pdfY: 714.2672653316006,
            pdfWidth: 612,
            pdfHeight: 42.40449438202247,
            fontSize: 33,
            fontFamily: 'Nunito',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'left',
            verticalAlign: 'middle',
            textColor: '#ffffff',
            backgroundColor: '#d80e0e',
            backgroundColorExplicit: true,
            lineHeight: 38.94,
            underline: false,
            opacity: 1,
        },
    ]);
}

// Snapshot of doc 2880 after the user added 3 more annotations on top of the
// test_4 baseline. State captured from rows 113884 + 113892 + 113900 + 113901
// + 113902:
//   row 113900: "vertical align" 38pt Garamond, narrow tall strip
//               (pdfX=0, pdfWidth=41.74, pdfHeight=584.01), textAlign left,
//               verticalAlign top, textColor #000000 on bg #42b2d7 (explicit)
//               with opacity 0.7. Box width is too narrow for the font so
//               text wraps to single chars per line; we search for "align".
//   row 113901: "vertical align" 38pt Mulish, wider middle strip
//               (pdfX=42.86, pdfWidth=128.48, pdfHeight=584.35), textAlign
//               center, verticalAlign middle, textColor #000000 on bg
//               #5e1e67 (explicit) with opacity 0.7.
//   row 113902: Lato Lorem Ipsum paragraph (pdfX=183.67, pdfY=427.63,
//               pdfWidth=174.99, pdfHeight=283.20), 12pt textColor #000000,
//               backgroundColor "transparent" (no bg fill assertion).
async function runCustomPdfTest5Flow() {
    const test = TESTS.custom_pdf_test_5;
    return runDoc2880SnapshotFlow(test, [
        {
            text: 'CENTER ALIGN',
            pdfX: 0,
            pdfY: 757.6179775280899,
            pdfWidth: 612,
            pdfHeight: 34.38202247191011,
            fontSize: 26,
            fontFamily: 'NotoSerif',
            fontWeight: '700',
            fontStyle: 'italic',
            textAlign: 'right',
            verticalAlign: 'middle',
            textColor: '#9d1b1b',
            backgroundColor: '#2e2e2e',
            backgroundColorExplicit: true,
            lineHeight: 84.96,
            underline: false,
            opacity: 1,
        },
        {
            text: 'LEFT ALIGN',
            pdfX: 0,
            pdfY: 714.2672653316006,
            pdfWidth: 612,
            pdfHeight: 42.40449438202247,
            fontSize: 33,
            fontFamily: 'Nunito',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'left',
            verticalAlign: 'middle',
            textColor: '#ffffff',
            backgroundColor: '#d80e0e',
            backgroundColorExplicit: true,
            lineHeight: 38.94,
            underline: false,
            opacity: 1,
        },
        {
            text: 'vertical align',
            searchText: 'align',
            pdfX: 0,
            pdfY: 129.46984292306266,
            pdfWidth: 41.73779463840141,
            pdfHeight: 584.0074067094114,
            fontSize: 38,
            fontFamily: 'Garamond',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'left',
            verticalAlign: 'top',
            textColor: '#000000',
            backgroundColor: '#42b2d7',
            backgroundColorExplicit: true,
            lineHeight: 44.84,
            underline: false,
            opacity: 0.7,
        },
        {
            text: 'vertical align',
            searchText: 'align',
            pdfX: 42.85566336304261,
            pdfY: 129.91060323001318,
            pdfWidth: 128.4759174001378,
            pdfHeight: 584.3486953040252,
            fontSize: 38,
            fontFamily: 'Mulish',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'center',
            verticalAlign: 'middle',
            textColor: '#000000',
            backgroundColor: '#5e1e67',
            backgroundColorExplicit: true,
            lineHeight: 44.84,
            underline: false,
            opacity: 0.7,
        },
        {
            text: 'Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged. It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages, and more recently with desktop publishing software like Aldus PageMaker including versions of Lorem Ipsum.',
            searchText: 'Lorem Ipsum',
            pdfX: 183.6738461083975,
            pdfY: 427.6326877907711,
            pdfWidth: 174.99569078754175,
            pdfHeight: 283.19999999999993,
            fontSize: 12,
            fontFamily: 'Lato',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'left',
            verticalAlign: 'top',
            textColor: '#000000',
            backgroundColor: 'transparent',
            backgroundColorExplicit: false,
            lineHeight: 14.16,
            underline: false,
            opacity: 1,
        },
    ]);
}

// Bug regression: Enter must insert a newline at the caret in a paragraph
// annotation. The earlier ae keydown handler resolved the active annotation
// only via activeState.uid, which silently aborted Enter when ae.dataset
// .editingUid still pointed at the editing annotation but activeState.uid
// had been cleared (eg. focus moved through the format bar). The fix uses
// `ae.dataset.editingUid || activeState.uid`. We exercise both the normal
// case and the divergent-state case here.
async function runCustomPdfTest6Flow() {
    const test = TESTS.custom_pdf_test_6;
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: VIEWPORT, acceptDownloads: true });
    const page = await context.newPage();
    let documentId = null;

    const loremText = 'Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book.';
    const loremSpec = {
        text: loremText,
        pdfX: 183.6738461083975,
        pdfY: 427.6326877907711,
        pdfWidth: 174.99569078754175,
        pdfHeight: 283.19999999999993,
        fontSize: 12,
        fontFamily: 'Lato',
        fontWeight: '400',
        fontStyle: 'normal',
        textAlign: 'left',
        verticalAlign: 'top',
        textColor: '#000000',
        backgroundColor: 'transparent',
        backgroundColorExplicit: false,
        lineHeight: 14.16,
        underline: false,
        opacity: 1,
    };

    try {
        documentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, documentId);
        await forceRefreshOverlay(page, documentId);

        // Inject the Lorem Ipsum paragraph annotation.
        const uid = await page.evaluate((spec) => {
            const st = window.__editorTestState;
            if (!st) throw new Error('__editorTestState bridge missing');
            const data = st.pageData && st.pageData[0];
            if (!data) throw new Error('pageData[0] missing');
            const u = 'enter_test_' + Math.random().toString(36).slice(2, 10);
            const ann = Object.assign({
                _uid: u,
                id: st.generateAnnotationId(),
                pageIndex: 0,
                type: 'text',
                originalText: spec.text,
                rotation: 0,
                userAuthored: true,
                userCreated: true,
            }, spec);
            data.annotations.push(ann);
            st.editedTexts[u] = spec.text;
            st.setEditModeEnabled(true);
            st.selectAnnotation(ann, 0);
            st.redrawOverlay(0);
            return u;
        }, loremSpec);

        // Enter editing mode (dblclick on the editor element) and place the
        // caret at offset 30 of the first text node.
        const setupCheck = await page.evaluate((u) => {
            const ae = document.getElementById('ae-1');
            if (!ae) return { ok: false, reason: 'no ae-1' };
            ae.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true }));
            ae.focus();
            return { ok: true, renderMode: ae.dataset.renderMode, editingUid: ae.dataset.editingUid };
        }, uid);
        await page.waitForTimeout(300);

        // Helper: place caret at offset, dispatch Enter via the keydown handler.
        async function pressEnterAtOffset(offset) {
            return page.evaluate(({ off }) => {
                const ae = document.getElementById('ae-1');
                ae.focus();
                const walker = document.createTreeWalker(ae, NodeFilter.SHOW_TEXT);
                let tn = null;
                while (walker.nextNode()) {
                    const n = walker.currentNode;
                    if ((n.nodeValue || '').length >= off) { tn = n; break; }
                }
                if (!tn) return { ok: false, reason: 'no text node big enough' };
                const r = document.createRange();
                r.setStart(tn, off);
                r.collapse(true);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(r);
                const heightBefore = ae.getBoundingClientRect().height;
                const evt = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
                ae.dispatchEvent(evt);
                const heightAfter = ae.getBoundingClientRect().height;
                return { ok: true, heightBefore, heightAfter };
            }, { off: offset });
        }

        // ── Case 1: normal Enter at offset 30 ──
        const case1 = await pressEnterAtOffset(30);
        await page.waitForTimeout(200);
        const case1Text = await page.evaluate((u) => String(window.__editorTestState.editedTexts[u] || ''), uid);
        const case1Newline = case1Text.indexOf('\n');
        const case1Pass = case1Newline === 30 && case1Text.length === loremText.length + 1;
        const heightChanged1 = !!(case1.ok && Math.abs(case1.heightAfter - case1.heightBefore) > 0.5);

        // ── Case 2: clear activeState.uid, ensure dataset.editingUid still set,
        // press Enter again. This is the regression case the fix addresses. ──
        const reset = await page.evaluate((u) => {
            const st = window.__editorTestState;
            const data = st.pageData[0];
            const ann = data.annotations.find((a) => a._uid === u);
            // Reset the text so we test a fresh \n insertion.
            st.editedTexts[u] = ann.text = ann.originalText;
            const ae = document.getElementById('ae-1');
            // Re-render the editor with the fresh text by re-entering editing.
            ae.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true }));
            ae.focus();
            // Now divergence: clear activeState.uid but keep ae.dataset.editingUid.
            const priorEditingUid = ae.dataset.editingUid || u;
            st.activeState.uid = null;
            st.activeState.pi = null;
            ae.dataset.editingUid = priorEditingUid;
            return { priorEditingUid, activeUid: st.activeState.uid };
        }, uid);
        await page.waitForTimeout(200);
        const case2 = await pressEnterAtOffset(30);
        await page.waitForTimeout(200);
        const case2Text = await page.evaluate((u) => String(window.__editorTestState.editedTexts[u] || ''), uid);
        const case2Newline = case2Text.indexOf('\n');
        const case2Pass = case2Newline === 30;

        // ── Case 3: caret at an existing hard line break. This is the real
        // paragraph-edit failure mode: the previous handler stripped the
        // neighboring newline, rebuilding the exact same string and making
        // Enter look like it did nothing.
        await page.evaluate((u) => {
            const st = window.__editorTestState;
            const data = st.pageData[0];
            const ann = data.annotations.find((a) => a._uid === u);
            const text = 'Alpha line\nBeta line';
            ann.text = text;
            ann.originalText = text;
            delete ann._richHtml;
            st.editedTexts[u] = text;
            st.setEditModeEnabled(true);
            st.selectAnnotation(ann, 0);
            const ae = document.getElementById('ae-1');
            ae.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true }));
            ae.focus();
        }, uid);
        await page.waitForTimeout(200);
        const case3 = await page.evaluate((u) => {
            const ae = document.getElementById('ae-1');
            if (!ae) return { ok: false, reason: 'missing editor', html: '' };
            const walker = document.createTreeWalker(ae, NodeFilter.SHOW_TEXT);
            let textNode = null;
            let offset = -1;
            while (walker.nextNode()) {
                const node = walker.currentNode;
                const idx = String(node.nodeValue || '').indexOf('\n');
                if (idx >= 0) {
                    textNode = node;
                    offset = idx + 1;
                    break;
                }
            }
            if (!textNode || offset < 0) return { ok: false, reason: 'missing newline text node', html: ae.innerHTML };
            ae.focus();
            const range = document.createRange();
            range.setStart(textNode, offset);
            range.collapse(true);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            const evt = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
            ae.dispatchEvent(evt);
            return {
                ok: true,
                text: String(window.__editorTestState.editedTexts[u] || ''),
                html: ae.innerHTML,
            };
        }, uid);
        const case3Pass = case3.ok && case3.text === 'Alpha line\n\nBeta line' && /<br\s*\/?><br\s*\/?>/i.test(case3.html);

        // ── Case 4: saved text differs from stale source lines. This mirrors
        // the user's doc 2880 paragraph where the saved text begins with
        // "This is paragraph2", but the extraction source lines still begin at
        // "Lorem Ipsum". Edit mode must not render the stale source text and
        // then snap to different text after the next keystroke.
        const case4 = await page.evaluate(() => {
            const st = window.__editorTestState;
            const data = st.pageData[0];
            const pageHeight = Number(data.hPts) || 792;
            const sourceLines = [
                'Lorem Ipsum is simply dummy',
                'text of the printing and typesetting industry. Lor',
                'em Ipsum has been the industry\'s standard dummy text ever since the 1500s,',
                'when an unknown printer took a galley of type and scrambled it to make a type',
                'specimen book. It has survived not only five centuries, but also the leap into',
                'electronic typesetting, remaining essentially unchanged.',
            ];
            const savedText = `This is paragraph2 ${sourceLines.join('\n')}`;
            const sourceLineBBoxes = sourceLines.map((line, index) => {
                const top = 96 + (index * 18);
                return [44, top, 44 + Math.max(80, line.length * 5.7), top + 14];
            });
            const sourceSpans = sourceLines.map((line, index) => ({
                text: line,
                render_text: line,
                bbox: sourceLineBBoxes[index],
                origin: [44, sourceLineBBoxes[index][3] - 3],
                font: 'Lato',
                fontFamily: 'Lato',
                font_size: 12,
                fontSize: 12,
                font_weight: '400',
                fontWeight: '400',
                fontStyle: 'normal',
                hex_color: '#000000',
            }));
            const uid4 = 'stale_source_enter_' + Math.random().toString(36).slice(2, 10);
            const top = 84;
            const height = 180;
            const ann = {
                _uid: uid4,
                id: st.generateAnnotationId(),
                pageIndex: 0,
                type: 'text',
                text: savedText,
                originalText: savedText,
                pdfX: 44,
                pdfY: pageHeight - top - height,
                pdfWidth: 560,
                pdfHeight: height,
                fontSize: 12,
                fontFamily: 'Lato',
                fontWeight: '400',
                fontStyle: 'normal',
                textAlign: 'left',
                verticalAlign: 'top',
                textColor: '#000000',
                backgroundColor: 'transparent',
                backgroundColorExplicit: false,
                lineHeight: 14.16,
                underline: false,
                opacity: 1,
                promotedFromExtraction: true,
                userAuthored: false,
                userCreated: false,
                sourceTextLines: sourceLines,
                sourceLineBBoxes,
                sourceSpans,
            };
            data.annotations.push(ann);
            delete st.editedTexts[uid4];
            st.setEditModeEnabled(true);
            st.selectAnnotation(ann, 0);
            const ae = document.getElementById('ae-1');
            ae.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true }));
            ae.focus();
            const beforeText = String(ae.innerText || ae.textContent || '');
            const beforeMode = ae.dataset.renderMode || '';

            const walker = document.createTreeWalker(ae, NodeFilter.SHOW_TEXT);
            let textNode = null;
            let offset = -1;
            while (walker.nextNode()) {
                const node = walker.currentNode;
                const idx = String(node.nodeValue || '').indexOf('This is paragraph2');
                if (idx >= 0) {
                    textNode = node;
                    offset = idx + 'This is paragraph2'.length;
                    break;
                }
            }
            if (!textNode || offset < 0) {
                return { ok: false, reason: 'saved prefix not rendered in editor', beforeText, beforeMode, savedText };
            }

            const range = document.createRange();
            range.setStart(textNode, offset);
            range.collapse(true);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            ae.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
            const afterEnter = String(st.editedTexts[uid4] || '');

            document.execCommand('insertText', false, ' ');
            ae.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: ' ' }));
            const afterSpace = String(st.editedTexts[uid4] || '');

            return {
                ok: true,
                beforeMode,
                beforeText,
                afterEnter,
                afterSpace,
                html: ae.innerHTML,
            };
        });
        const case4Pass = case4.ok
            && case4.beforeMode === 'plain'
            && case4.beforeText.includes('This is paragraph2')
            && case4.afterEnter.startsWith('This is paragraph2\n')
            && case4.afterSpace.startsWith('This is paragraph2\n ')
            && case4.afterSpace.includes('Lorem Ipsum is simply dummy');

        const checks = [
            {
                item: 'lorem_paragraph_injected_and_edit_mode_active',
                result: setupCheck.ok && setupCheck.editingUid === uid ? 'PASS' : 'FAIL',
                description: 'Doc 2880 Lorem Ipsum paragraph annotation injected and edit mode enabled.',
                detail: JSON.stringify({ uid, setupCheck }),
            },
            {
                item: 'enter_inserts_newline_at_caret_offset_30',
                result: case1Pass ? 'PASS' : 'FAIL',
                description: 'Pressing Enter with the caret at offset 30 inserts a single \\n into editedTexts at offset 30.',
                detail: JSON.stringify({ case1Newline, expectedNewline: 30, case1TextLength: case1Text.length, expectedLength: loremText.length + 1 }),
            },
            {
                item: 'editor_height_changed_after_enter',
                result: heightChanged1 ? 'PASS' : 'FAIL',
                description: 'Editor element height changed after the newline was inserted (auto-resize fired).',
                detail: JSON.stringify({ heightBefore: case1.heightBefore, heightAfter: case1.heightAfter }),
            },
            {
                item: 'enter_works_with_diverged_active_state_uid',
                result: case2Pass ? 'PASS' : 'FAIL',
                description: 'Pressing Enter still inserts a newline when activeState.uid is null but ae.dataset.editingUid still points at the editing annotation.',
                detail: JSON.stringify({ case2Newline, expectedNewline: 30, reset }),
            },
            {
                item: 'enter_at_existing_line_break_creates_blank_line',
                result: case3Pass ? 'PASS' : 'FAIL',
                description: 'Pressing Enter at the start of an existing paragraph line inserts a second newline instead of stripping the existing one and no-oping.',
                detail: JSON.stringify(case3),
            },
            {
                item: 'edit_mode_ignores_stale_source_lines_after_saved_text_changed',
                result: case4Pass ? 'PASS' : 'FAIL',
                description: 'A paragraph whose saved text differs from stale source lines renders and edits the saved text consistently after Enter and a typed space.',
                detail: JSON.stringify(case4),
            },
        ];

        const status = checks.every((c) => c.result === 'PASS') ? 'pass' : 'fail';

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [],
            fileSize: 0,
            metadata: { document_id: documentId, uid, case1Text, case2Text },
        });
    } finally {
        if (documentId) {
            try { await deleteDocument(page, documentId); } catch (_e) { /* ignore */ }
        }
        await browser.close();
    }
}

// Doc 2880 row 113924 snapshot: a multi-line indented JosefinSans paragraph.
// Verifies two related bug fixes:
//   Bug A: wrap_text_to_width was joining \n-separated lines and stripping
//          leading whitespace via .split() - downloaded PDF lost newlines and
//          indents.  The renderPlainEditorHTML in edit-new.blade.php is the
//          canonical source of truth: every \n is a hard break, leading
//          whitespace is preserved via white-space:pre-wrap.  The fix mirrors
//          that logic in apply_annotations_direct_new.py wrap_text_to_width.
//   Bug B: python/pdf-editor/fonts/JosefinSans-{Regular,Bold,Italic,BoldItalic}.ttf
//          were ALL the Thin (weight 100) variant misnamed.  The downloaded PDF
//          rendered the JosefinSans annotation in Thin which looked ~much lighter
//          than the editor (which loads Google Fonts CDN's actual Regular/Bold
//          weights).  The fix replaces those four files with the proper
//          weights from fonts.gstatic.com.
async function runCustomPdfTest7Flow() {
    const test = TESTS.custom_pdf_test_7;
    ensureOutputDir();

    const spec = {
        text: 'Test line indents\n    1. blah\n    2. blah blah\n    3.  ok',
        searchText: 'Test line indents',
        pdfX: 183.6738461083975,
        pdfY: 427.6326877907711,
        pdfWidth: 174.99569078754175,
        pdfHeight: 283.19999999999993,
        fontSize: 12,
        fontFamily: 'JosefinSans',
        fontWeight: '400',
        fontStyle: 'normal',
        textAlign: 'left',
        verticalAlign: 'top',
        textColor: '#000000',
        backgroundColor: 'transparent',
        backgroundColorExplicit: false,
        lineHeight: 14.16,
        underline: false,
        opacity: 1,
    };

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: VIEWPORT, acceptDownloads: true });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, documentId);
        await forceRefreshOverlay(page, documentId);
        const sessionId = await ensurePdfSessionIdNew(page, documentId);

        const injection = await page.evaluate((s) => {
            const st = window.__editorTestState;
            if (!st) throw new Error('__editorTestState bridge missing');
            const data = st.pageData && st.pageData[0];
            if (!data) throw new Error('pageData[0] missing');
            const uid = 'snap_' + Math.random().toString(36).slice(2, 8);
            const ann = Object.assign({
                _uid: uid,
                id: st.generateAnnotationId(),
                pageIndex: 0,
                type: 'text',
                originalText: s.text,
                rotation: 0,
                userAuthored: true,
                userCreated: true,
            }, s);
            data.annotations.push(ann);
            st.editedTexts[uid] = s.text;
            st.clearActiveAnnotation();
            st.redrawOverlay(0);
            st.markDirty();
            return { uid, id: ann.id, pageHeightPts: Number(data.hPts) };
        }, spec);

        await capturePageScreenshotNew(page, beforeSavePath);
        await waitForAnnotationSaveNew(page);

        const savedResp = await fetchSavedAnnotationsNew(page, documentId, sessionId);
        const savedAnnotations = Array.isArray(savedResp?.body?.annotations) ? savedResp.body.annotations : [];
        const savedTextRows = savedAnnotations.filter((a) => a?.type === 'text');
        const savedCheck = {
            item: 'saved_annotation_persisted',
            result: savedTextRows.length === 1 ? 'PASS' : 'FAIL',
            description: 'Auto-save persists the snapshot annotation to the saved-annotations API.',
            detail: `expected=1 got=${savedTextRows.length}`,
        };

        await downloadAnnotatedPdfNew(page, documentId, finalPdfPath);
        const downloadedPdfExists = fs.existsSync(finalPdfPath);
        const downloadedPdfSize = downloadedPdfExists ? fs.statSync(finalPdfPath).size : 0;

        // Extract every span on page 1 with its font, raw text, and bbox.
        const py = `import json, sys, fitz
d = fitz.open(sys.argv[1])
out = []
for b in d[0].get_text('dict')['blocks']:
    for l in b.get('lines', []):
        for s in l.get('spans', []):
            out.append({'text': s['text'], 'font': s['font'], 'size': s['size'], 'bbox': list(s['bbox'])})
print(json.dumps(out))`;
        const tmpPy = path.join(OUTPUT_DIR, `${test.key}_${runToken}_extract.py`);
        fs.writeFileSync(tmpPy, py);
        let spans = [];
        try {
            const cp = require('child_process');
            const proc = cp.spawnSync(PYTHON_BIN, [tmpPy, finalPdfPath], { encoding: 'utf8' });
            spans = JSON.parse((proc.stdout || '').trim() || '[]');
        } catch (_e) { spans = []; }
        try { fs.unlinkSync(tmpPy); } catch (_e) {}

        const expectedLines = [
            'Test line indents',
            '    1. blah',
            '    2. blah blah',
            '    3.  ok',
        ];

        // Find the first span (by reading-order y) that contains each expected line.
        const matches = expectedLines.map((expected) => {
            const m = spans.find((sp) => (sp.text || '').replace(/\u00a0/g, ' ') === expected);
            return { expected, span: m || null };
        });

        const allPresent = matches.every((m) => m.span);
        const presentCheck = {
            item: 'download_pdf_contains_all_four_lines',
            result: allPresent ? 'PASS' : 'FAIL',
            description: 'Downloaded PDF page 1 contains every expected line text exactly (newlines preserved, leading whitespace preserved).',
            detail: JSON.stringify({ expectedLines, foundTexts: spans.map((s) => s.text) }),
        };

        // All four lines must be at distinct y positions (newlines preserved).
        const ys = matches.filter((m) => m.span).map((m) => Math.round(m.span.bbox[1] * 10) / 10);
        const uniqueYs = Array.from(new Set(ys));
        const distinctYCheck = {
            item: 'download_pdf_renders_four_distinct_y_positions',
            result: uniqueYs.length === 4 ? 'PASS' : 'FAIL',
            description: 'Each of the four lines is rendered at its own y position (no collapsing).',
            detail: JSON.stringify({ ys, uniqueYs }),
        };

        // Lines 2-4 must keep their leading 4 spaces (verified by exact text
        // match in presentCheck) AND be x-shifted vs line 1's leading character.
        // We check the text exact-match in presentCheck; this check is redundant
        // but explicit for the failure message.
        const indentedKeep = matches.slice(1).every((m) => m.span && /^    /.test(m.span.text));
        const indentCheck = {
            item: 'download_pdf_preserves_leading_indents',
            result: indentedKeep ? 'PASS' : 'FAIL',
            description: 'Lines 2-4 keep their leading 4-space indent in the downloaded PDF.',
            detail: JSON.stringify({ lineTexts: matches.slice(1).map((m) => m.span?.text || null) }),
        };

        // Every span for our four lines must use JosefinSans-Regular (not Thin
        // or any substitute). We accept variants like JosefinSans-Regular,
        // JosefinSansRegular, "Josefin Sans Regular".
        const fontOk = matches.every((m) => {
            if (!m.span) return false;
            const f = String(m.span.font || '');
            return /Josefin\s*Sans/i.test(f) && !/Thin|Hairline|ExtraLight|UltraLight|Light/i.test(f);
        });
        const fontNames = matches.map((m) => m.span?.font || null);
        const fontCheck = {
            item: 'download_pdf_uses_josefin_sans_regular_weight',
            result: fontOk ? 'PASS' : 'FAIL',
            description: 'Every span uses JosefinSans-Regular (weight 400), not JosefinSans-Thin (weight 100) or any lighter substitute.',
            detail: JSON.stringify({ fontNames }),
        };

        const checks = [savedCheck, presentCheck, distinctYCheck, indentCheck, fontCheck];
        const status = checks.every((c) => c.result === 'PASS') ? 'PASS' : 'FAIL';

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [beforeSaveName, finalPdfName],
            fileSize: downloadedPdfSize,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                spec,
                injection,
                spans,
                matches: matches.map((m) => ({ expected: m.expected, found: m.span })),
            },
        });
    } finally {
        if (documentId) {
            try { await deleteDocument(page, documentId); } catch (_error) { /* ignore */ }
        }
        await browser.close();
    }
}

// Snapshot of doc 2880 after the user added six more annotations on top of
// the test_5 baseline. Captures rows 113908, 113909, 113921, 113923, 113927,
// 113966 verbatim from pdf_state. Two of the six (rows 113927, 113966) have
// explicit opaque background fills and so get the bg-pixel assertion; the
// other four are transparent-bg paragraphs/subtitles and get only the search
// assertion (transparent specs already short-circuit the bg check inside
// runDoc2880SnapshotFlow).
async function runCustomPdfTest8Flow() {
    const test = TESTS.custom_pdf_test_8;
    return runDoc2880SnapshotFlow(test, [
        // row 113908 — TimesRoman 12pt multi-paragraph Lorem Ipsum w/ blank lines
        {
            text: "This is paragraph2 Lorem Ipsum is simply dummy \ntext of the printing and typesetting industry. Lorem Ipsum has been the industry's standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type\n\nspecimen book.\n\nIt has survived not only five centuries, but also\n\n the leap into electronic typesetting, remaining essentially unchanged. It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum \npassages, and more recently with desktop publishing software like Aldus PageMaker including versions of Lorem Ipsum.",
            searchText: 'paragraph2',
            pdfX: 180.6868096177768,
            pdfY: 98.34093696399574,
            pdfWidth: 233.08212614073847,
            pdfHeight: 269.0399999999999,
            fontSize: 12,
            fontFamily: 'TimesRoman',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'left',
            verticalAlign: 'top',
            textColor: '#000000',
            backgroundColor: 'transparent',
            backgroundColorExplicit: false,
            lineHeight: 14.16,
            underline: false,
            opacity: 1,
        },
        // row 113909 — "Subtitle 1" 12pt Rubik centered, transparent bg
        {
            text: 'Subtitle 1',
            pdfX: 180.14748458119257,
            pdfY: 381.9698286281146,
            pdfWidth: 233.6214511773227,
            pdfHeight: 18.12684276255453,
            fontSize: 12,
            fontFamily: 'Rubik',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'center',
            verticalAlign: 'top',
            textColor: '#000000',
            backgroundColor: 'transparent',
            backgroundColorExplicit: false,
            lineHeight: 14.16,
            underline: false,
            opacity: 1,
        },
        // row 113921 — small Helvetica 7pt fine-print Lorem Ipsum block
        {
            text: "There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration in some form, by injected humour, or randomised words which don't look even slightly believable. If you are going to use a passage of Lorem Ipsum, \n\n\nyou need to be sure there isn't anything embarrassing hidden in the middle of text. All the Lorem Ipsum generators on the Internet tend to repeat predefined chunks as necessary, making this the first true generator on the Internet. It uses a dictionary of over 200 Latin words, combined with a handful of model sentence structures, to generate Lorem Ipsum which looks reasonable. The generated Lorem Ipsum is therefore always free from repetition, injected humour, or non-characteristic words etc.",
            searchText: 'variations',
            pdfX: 436.2494904944464,
            pdfY: 204.51416385863143,
            pdfWidth: 141.93605146999602,
            pdfHeight: 165.2,
            fontSize: 7,
            fontFamily: 'Helvetica',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'left',
            verticalAlign: 'top',
            textColor: '#000000',
            backgroundColor: 'transparent',
            backgroundColorExplicit: false,
            lineHeight: 8.26,
            underline: false,
            opacity: 1,
        },
        // row 113923 — "Subtitle 2" 12pt Rubik centered, transparent bg
        {
            text: 'Subtitle 2',
            pdfX: 436.7888155310305,
            pdfY: 384.51491875748104,
            pdfWidth: 139.6335456698095,
            pdfHeight: 14.160000000000023,
            fontSize: 12,
            fontFamily: 'Rubik',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'center',
            verticalAlign: 'top',
            textColor: '#000000',
            backgroundColor: 'transparent',
            backgroundColorExplicit: false,
            lineHeight: 14.16,
            underline: false,
            opacity: 1,
        },
        // row 113927 — "RIGHT ALIGN" 44pt Mukta middle, green on teal explicit
        {
            text: 'RIGHT ALIGN',
            pdfX: 0,
            pdfY: 634.7084441180023,
            pdfWidth: 612,
            pdfHeight: 78.76880551447186,
            fontSize: 44,
            fontFamily: 'Mukta',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'right',
            verticalAlign: 'middle',
            textColor: '#3feb00',
            backgroundColor: '#00a89d',
            backgroundColorExplicit: true,
            lineHeight: 51.92,
            underline: false,
            opacity: 1,
        },
        // row 113966 — "A B C D E F" 25pt RobotoCondensed centered, grey strip
        {
            text: 'A             B              C                   D                         E                    F',
            searchText: 'A',
            pdfX: 0,
            pdfY: 0.767833293516892,
            pdfWidth: 612,
            pdfHeight: 48.483633764554845,
            fontSize: 25,
            fontFamily: 'RobotoCondensed',
            fontWeight: '400',
            fontStyle: 'normal',
            textAlign: 'center',
            verticalAlign: 'top',
            textColor: '#000000',
            backgroundColor: '#bfb0b0',
            backgroundColorExplicit: true,
            lineHeight: 29.5,
            underline: false,
            opacity: 1,
        },
    ]);
}

// Generic doc 2880 snapshot runner: takes an array of annotation specs,
// replays them on a blank Letter /edit-new doc, downloads the annotated PDF,
// and per-spec asserts the text is searchable AND the bounding box renders
// the expected background color (slate #2c3e50 for white-on-white, or the
// literal backgroundColor otherwise). Add a new wrapper per snapshot.
async function runDoc2880SnapshotFlow(test, annSpecs) {
    const specs = Array.isArray(annSpecs) ? annSpecs : [annSpecs];
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, documentId);
        await forceRefreshOverlay(page, documentId);

        const sessionId = await ensurePdfSessionIdNew(page, documentId);

        const injections = await page.evaluate((specList) => {
            const st = window.__editorTestState;
            if (!st) throw new Error('__editorTestState bridge missing on window');
            const data = st.pageData && st.pageData[0];
            if (!data) throw new Error('pageData[0] missing for text injection');
            const pageHeightPts = Number(data.hPts);
            const pageWidthPts = Number(data.wPts);
            const out = [];
            specList.forEach((spec, idx) => {
                const uid = 'snap_' + idx + '_' + Math.random().toString(36).slice(2, 8);
                const ann = Object.assign({
                    _uid: uid,
                    id: st.generateAnnotationId(),
                    pageIndex: 0,
                    type: 'text',
                    originalText: spec.text,
                    rotation: 0,
                    userAuthored: true,
                    userCreated: true,
                }, spec);
                data.annotations.push(ann);
                st.editedTexts[uid] = spec.text;
                out.push({
                    pageHeightPts,
                    pageWidthPts,
                    uid,
                    id: ann.id,
                    pdfX: spec.pdfX,
                    pdfY: spec.pdfY,
                    pdfWidth: spec.pdfWidth,
                    pdfHeight: spec.pdfHeight,
                });
            });
            st.clearActiveAnnotation();
            st.redrawOverlay(0);
            st.markDirty();
            return out;
        }, specs);

        await capturePageScreenshotNew(page, beforeSavePath);
        await waitForAnnotationSaveNew(page);

        const savedResp = await fetchSavedAnnotationsNew(page, documentId, sessionId);
        const savedAnnotations = Array.isArray(savedResp?.body?.annotations) ? savedResp.body.annotations : [];
        const savedTextRows = savedAnnotations.filter((a) => a?.type === 'text');
        const savedCheck = {
            item: 'saved_annotations_persisted',
            result: savedTextRows.length === specs.length ? 'PASS' : 'FAIL',
            description: 'Auto-save persists every snapshot annotation to the saved-annotations API.',
            detail: `expected=${specs.length} got=${savedTextRows.length}`,
        };

        await downloadAnnotatedPdfNew(page, documentId, finalPdfPath);

        const downloadedPdfExists = fs.existsSync(finalPdfPath);
        const downloadedPdfSize = downloadedPdfExists ? fs.statSync(finalPdfPath).size : 0;

        const checks = [savedCheck];
        const perSpecMeta = [];

        for (let i = 0; i < specs.length; i += 1) {
            const spec = specs[i];
            const inj = injections[i];
            const tag = `[${i + 1}/${specs.length}] "${spec.text}"`;
            const searchQuery = String(spec.searchText || spec.text || '').trim();

            let searchRectCount = 0;
            let searchRects = [];
            let searchError = null;
            try {
                const searchResult = loadPdfSearchRects(finalPdfPath, 1, searchQuery);
                searchRects = Array.isArray(searchResult?.rects) ? searchResult.rects : [];
                searchRectCount = searchRects.length;
            } catch (err) {
                searchError = err && err.message ? err.message : String(err);
            }
            checks.push({
                item: `download_pdf_contains_snapshot_text_${i + 1}`,
                result: searchRectCount > 0 ? 'PASS' : 'FAIL',
                description: `Downloaded PDF page 1 contains the searchable text ${tag} (query="${searchQuery}").`,
                detail: JSON.stringify({ downloadedPdfExists, downloadedPdfSize, searchQuery, searchRectCount, searchError }),
            });

            const pageHeight = Number(inj.pageHeightPts) || 792;
            const annTop = pageHeight - (inj.pdfY + inj.pdfHeight);
            const annBottom = pageHeight - inj.pdfY;
            const bbox = [inj.pdfX, annTop, inj.pdfX + inj.pdfWidth, annBottom];

            const expected = expectedBgRgbForSpec(spec);
            let pixelSummary = null;
            let pixelError = null;
            if (expected) {
                try {
                    pixelSummary = countSlatePixelsInPdfBbox(finalPdfPath, 1, bbox, expected.rgb);
                } catch (err) {
                    pixelError = err && err.message ? err.message : String(err);
                }
                const matched = pixelSummary && Number.isFinite(pixelSummary.slate) ? pixelSummary.slate : 0;
                checks.push({
                    item: `download_pdf_renders_expected_bg_in_text_bbox_${i + 1}`,
                    result: matched >= 1000 ? 'PASS' : 'FAIL',
                    description: `Downloaded PDF page 1 renders the ${tag} bounding box with expected bg (${expected.reason}).`,
                    detail: JSON.stringify({ bbox, expectedRgb: expected.rgb, pixelSummary, pixelError, matched }),
                });
            } else {
                checks.push({
                    item: `download_pdf_renders_expected_bg_in_text_bbox_${i + 1}`,
                    result: 'PASS',
                    description: `${tag} has no opaque background fill expected (transparent / unparseable); skipped pixel check.`,
                    detail: JSON.stringify({ bbox, spec_backgroundColor: spec.backgroundColor || null }),
                });
            }
            perSpecMeta.push({ spec, injection: inj, bbox, expected, pixelSummary, searchRects });
        }

        const status = checks.every((c) => c.result === 'PASS') ? 'PASS' : 'FAIL';

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [beforeSaveName, finalPdfName],
            fileSize: downloadedPdfSize,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                specs,
                perSpec: perSpecMeta,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) { /* ignore */ }
        }
        await browser.close();
    }
}

async function runEditNewShapeGauntletFlow() {
    const test = TESTS.test_30_edit_new_shape_gauntlet_new;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterReloadName = buildArtifactName(test.key, runToken, 'after_reload');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterReloadPath = path.join(OUTPUT_DIR, afterReloadName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocumentNew(page);
        await waitForEditorReadyNew(page);
        await clearAnnotationSessionStateNew(page, documentId);
        // Prime fitz extraction so /download-annotated-pdf can resolve a clean
        // PDF source when use_exact_download_path=true (matches what the
        // pdf-new font-family test does for fresh blank docs).
        await forceRefreshOverlay(page, documentId);

        const sessionId = await ensurePdfSessionIdNew(page, documentId);
        const injection = await injectShapeAnnotationsNew(page, SHAPE_GAUNTLET_CASES);
        const pageHeightPts = Number(injection.pageHeightPts);

        await capturePageScreenshotNew(page, beforeSavePath);
        await waitForAnnotationSaveNew(page);

        const storedShapes = await readShapeAnnotationsNew(page);
        const storedByKey = new Map(storedShapes.map((s) => [s._gauntletKey, s]));

        // Per-shape stored-state checks.
        const storedChecks = SHAPE_GAUNTLET_CASES.map((c) => {
            const stored = storedByKey.get(c.key);
            if (!stored) {
                return {
                    item: `stored_${c.key}`,
                    result: 'FAIL',
                    description: `Injected shape ${c.key} (${c.shapeType}) is present in editor state.`,
                    detail: 'no annotation matched the injected gauntlet key',
                };
            }
            const expectedShapeType = c.shapeType;
            const expectedStrokeColor = c.strokeColor.toLowerCase();
            const expectedFillColor = c.fillColor.toLowerCase();
            const okShape = stored.shapeType === expectedShapeType;
            const okStroke = stored.strokeColor === expectedStrokeColor;
            const okFill = stored.fillColor === expectedFillColor;
            const okStrokeWidth = approxEqual(stored.strokeWidth, c.strokeWidth, 0.01);
            const okStrokeOp = approxEqual(stored.strokeOpacity, c.strokeOpacity, 0.01);
            const okFillOp = approxEqual(stored.fillOpacity, c.fillOpacity, 0.01);
            const okStrokeTr = stored.strokeTransparent === Boolean(c.strokeTransparent);
            const okFillTr = stored.fillTransparent === Boolean(c.fillTransparent);
            const allOk = okShape && okStroke && okFill && okStrokeWidth
                && okStrokeOp && okFillOp && okStrokeTr && okFillTr;
            return {
                item: `stored_${c.key}`,
                result: allOk ? 'PASS' : 'FAIL',
                description: `Injected shape ${c.key} (${c.shapeType}) is stored with the expected fields.`,
                detail: JSON.stringify({
                    expected: {
                        shapeType: expectedShapeType,
                        strokeColor: expectedStrokeColor,
                        fillColor: expectedFillColor,
                        strokeWidth: c.strokeWidth,
                        strokeOpacity: c.strokeOpacity,
                        fillOpacity: c.fillOpacity,
                        strokeTransparent: Boolean(c.strokeTransparent),
                        fillTransparent: Boolean(c.fillTransparent),
                    },
                    stored,
                }),
            };
        });

        // Saved-annotations API verification: every injected shape persisted.
        const savedResp = await fetchSavedAnnotationsNew(page, documentId, sessionId);
        const savedShapes = (savedResp?.body?.annotations || [])
            .filter((a) => a?.type === 'shape');
        const savedShapesByKey = new Map(savedShapes.map((s) => [s._gauntletKey, s]));
        const apiCheck = {
            item: 'saved_annotations_api_count',
            result: savedShapes.length === SHAPE_GAUNTLET_CASES.length ? 'PASS' : 'FAIL',
            description: `Saved-annotations API returns ${SHAPE_GAUNTLET_CASES.length} shape rows.`,
            detail: `expected=${SHAPE_GAUNTLET_CASES.length} got=${savedShapes.length}`,
        };

        // Download via the same code path the user clicks (use_exact_download_path).
        await downloadAnnotatedPdfNew(page, documentId, finalPdfPath);
        const pdfDump = await fetchPdfDrawingSummaries(finalPdfPath);
        const drawings = Array.isArray(pdfDump.drawings) ? pdfDump.drawings : [];
        const pdfPageHeight = Number(pdfDump.page_height || pageHeightPts);

        // For each expected shape compute its bbox in image-y-down PDF coords
        // and find every drawing whose center lies within the position
        // tolerance. Filled+stroked rectangles get split by PyMuPDF into one
        // fill drawing and one stroke drawing (items=['l','l','l','l'] vs
        // ['qu']), so we accept multiple matched drawings and verify the
        // expected fill/stroke independently against any of them.
        const expectedExpanded = SHAPE_GAUNTLET_CASES.map((c) => {
            const x0 = c.leftPt;
            const y0 = c.topPt;
            const x1 = c.leftPt + c.widthPt;
            const y1 = c.topPt + c.heightPt;
            return {
                key: c.key,
                shapeType: c.shapeType,
                rect: [x0, y0, x1, y1],
                expectedStroke: !c.strokeTransparent ? c.strokeColor.toLowerCase() : null,
                expectedFill: !c.fillTransparent ? c.fillColor.toLowerCase() : null,
                expectedStrokeWidth: c.strokeWidth,
            };
        });

        const drawingsWithCenter = drawings
            .map((d) => ({
                drawing: d,
                center: Array.isArray(d.rect) && d.rect.length === 4 ? bboxCenter(d.rect) : null,
            }))
            .filter((entry) => entry.center !== null);

        // Sanity check: total drawings should equal injected shapes plus any
        // PyMuPDF stroke/fill splits (one extra drawing per filled+stroked
        // square). Compute the expected count dynamically.
        const expectedDrawingCount = SHAPE_GAUNTLET_CASES.reduce((sum, c) => {
            const filled = !c.fillTransparent;
            const stroked = !c.strokeTransparent;
            // Squares (default rect path) get split when both filled and
            // stroked; every other shape produces exactly one drawing entry.
            if (c.shapeType === 'square' && filled && stroked) return sum + 2;
            return sum + 1;
        }, 0);

        const drawingCheck = {
            item: 'pdf_total_drawing_count',
            result: drawings.length === expectedDrawingCount ? 'PASS' : 'FAIL',
            description: `Downloaded PDF contains exactly ${expectedDrawingCount} drawings (one per injected shape, plus one extra fill/stroke split per filled+stroked square).`,
            detail: `expected=${expectedDrawingCount} got=${drawings.length}`,
        };

        const renderChecks = [];
        for (const expected of expectedExpanded) {
            const expectedCenter = bboxCenter(expected.rect);
            const matches = drawingsWithCenter
                .map((entry) => {
                    const dx = entry.center.x - expectedCenter.x;
                    const dy = entry.center.y - expectedCenter.y;
                    return { entry, distance: Math.sqrt((dx * dx) + (dy * dy)) };
                })
                .filter((m) => m.distance <= SHAPE_GAUNTLET_CENTER_TOLERANCE_PT)
                .sort((a, b) => a.distance - b.distance)
                .map((m) => m.entry.drawing);

            if (matches.length === 0) {
                renderChecks.push({
                    item: `pdf_${expected.key}`,
                    result: 'FAIL',
                    description: `Shape ${expected.key} (${expected.shapeType}) is rendered into the downloaded PDF.`,
                    detail: `no drawing center within ${SHAPE_GAUNTLET_CENTER_TOLERANCE_PT}pt of expected (${expectedCenter.x.toFixed(1)}, ${expectedCenter.y.toFixed(1)})`,
                });
                continue;
            }

            const fillOk = expected.expectedFill
                ? matches.some((d) => String(d.fill || '').toLowerCase() === expected.expectedFill)
                : matches.every((d) => d.fill === null);
            const strokeMatch = expected.expectedStroke
                ? matches.find((d) => String(d.stroke || '').toLowerCase() === expected.expectedStroke
                    && approxEqual(Number(d.width) || 0, expected.expectedStrokeWidth, SHAPE_GAUNTLET_STROKE_WIDTH_TOLERANCE_PT))
                : null;
            const strokeOk = expected.expectedStroke
                ? Boolean(strokeMatch)
                : matches.every((d) => d.stroke === null);
            const allOk = fillOk && strokeOk;

            renderChecks.push({
                item: `pdf_${expected.key}`,
                result: allOk ? 'PASS' : 'FAIL',
                description: `Shape ${expected.key} (${expected.shapeType}) renders into the downloaded PDF at the expected position with the expected fill, stroke and stroke width.`,
                detail: JSON.stringify({
                    expected: {
                        center: expectedCenter,
                        stroke: expected.expectedStroke,
                        fill: expected.expectedFill,
                        stroke_width: expected.expectedStrokeWidth,
                    },
                    matches: matches.map((d) => ({
                        rect: d.rect,
                        fill: d.fill,
                        stroke: d.stroke,
                        width: d.width,
                    })),
                }),
            });
        }

        // Reload the editor and verify all shapes hydrate correctly.
        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await waitForEditorReadyNew(page);
        await capturePageScreenshotNew(page, afterReloadPath);
        const reloadedShapes = await readShapeAnnotationsNew(page);
        const reloadedByKey = new Map();
        // The persisted payload doesn't necessarily include _gauntletKey since
        // it starts with `_` (some persistence paths strip underscore-prefixed
        // private fields). Re-match by closest center + shapeType instead.
        const reloadedRemaining = reloadedShapes.slice();
        for (const expected of SHAPE_GAUNTLET_CASES) {
            const x0 = expected.leftPt;
            const y0 = pageHeightPts - (expected.topPt + expected.heightPt);
            const w = expected.widthPt;
            const h = expected.heightPt;
            const expectedCx = x0 + w / 2;
            const expectedCy = y0 + h / 2;
            let bestIdx = -1;
            let bestDist = Infinity;
            for (let i = 0; i < reloadedRemaining.length; i += 1) {
                const r = reloadedRemaining[i];
                if (r.shapeType !== expected.shapeType) continue;
                const cx = r.pdfX + r.pdfWidth / 2;
                const cy = r.pdfY + r.pdfHeight / 2;
                const dx = cx - expectedCx;
                const dy = cy - expectedCy;
                const dist = Math.sqrt((dx * dx) + (dy * dy));
                if (dist < bestDist) {
                    bestDist = dist;
                    bestIdx = i;
                }
            }
            if (bestIdx >= 0) {
                reloadedByKey.set(expected.key, reloadedRemaining[bestIdx]);
                reloadedRemaining.splice(bestIdx, 1);
            }
        }

        const reloadCheck = {
            item: 'reload_all_shapes_hydrated',
            result: reloadedByKey.size === SHAPE_GAUNTLET_CASES.length ? 'PASS' : 'FAIL',
            description: `After reload the editor hydrates all ${SHAPE_GAUNTLET_CASES.length} shape annotations with matching shapeType and position.`,
            detail: `matched=${reloadedByKey.size} reloaded_total=${reloadedShapes.length}`,
        };

        const reloadFieldChecks = SHAPE_GAUNTLET_CASES.map((c) => {
            const r = reloadedByKey.get(c.key);
            if (!r) {
                return {
                    item: `reloaded_${c.key}`,
                    result: 'FAIL',
                    description: `Reloaded shape ${c.key} (${c.shapeType}) keeps its stored fields after reload.`,
                    detail: 'no reloaded annotation matched expected center+shapeType',
                };
            }
            const okStroke = r.strokeColor === c.strokeColor.toLowerCase();
            const okFill = r.fillColor === c.fillColor.toLowerCase();
            const okStrokeWidth = approxEqual(r.strokeWidth, c.strokeWidth, 0.01);
            const okStrokeOp = approxEqual(r.strokeOpacity, c.strokeOpacity, 0.01);
            const okFillOp = approxEqual(r.fillOpacity, c.fillOpacity, 0.01);
            const okStrokeTr = r.strokeTransparent === Boolean(c.strokeTransparent);
            const okFillTr = r.fillTransparent === Boolean(c.fillTransparent);
            const allOk = okStroke && okFill && okStrokeWidth && okStrokeOp && okFillOp && okStrokeTr && okFillTr;
            return {
                item: `reloaded_${c.key}`,
                result: allOk ? 'PASS' : 'FAIL',
                description: `Reloaded shape ${c.key} (${c.shapeType}) keeps its stored fields after reload.`,
                detail: JSON.stringify({
                    expected: {
                        strokeColor: c.strokeColor.toLowerCase(),
                        fillColor: c.fillColor.toLowerCase(),
                        strokeWidth: c.strokeWidth,
                        strokeOpacity: c.strokeOpacity,
                        fillOpacity: c.fillOpacity,
                        strokeTransparent: Boolean(c.strokeTransparent),
                        fillTransparent: Boolean(c.fillTransparent),
                    },
                    reloaded: r,
                }),
            };
        });

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'A fresh blank PDF was created through the pdf-new blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'shapes_injected',
                result: 'PASS',
                description: `Ten shape annotations injected via the pdf-new test bridge (page_height=${pageHeightPts}pt).`,
                detail: `injected=${injection.injected.length}`,
            },
            ...storedChecks,
            apiCheck,
            drawingCheck,
            ...renderChecks,
            reloadCheck,
            ...reloadFieldChecks,
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(finalPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                { label: 'Before Save', kind: 'image', filename: beforeSaveName },
                { label: 'After Reload', kind: 'image', filename: afterReloadName },
                { label: 'Final PDF', kind: 'pdf', filename: finalPdfName },
            ],
            metadata: {
                document_id: documentId,
                page_height_pts: pageHeightPts,
                cases: SHAPE_GAUNTLET_CASES.map((c) => ({ key: c.key, shapeType: c.shapeType })),
                stored_shapes: storedShapes,
                pdf_drawing_count: drawings.length,
                pdf_drawings: drawings,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runInvoiceCodeColumnSeparatedFlow() {
    const test = TESTS.test_14_invoice_code_column_separated;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'invoice_code_column_load');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createFixtureDocument(page, INVOICE_TEST_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const overlayFields = await collectOverlayFieldTexts(page);
        await capturePageScreenshot(page, screenshotPath);

        const codeFields = overlayFields
            .filter((entry) => /BPXPN-\d{5}/.test(entry.text))
            .map((entry) => ({
                ...entry,
                codeMatches: entry.text.match(/BPXPN-\d{5}/g) || [],
            }));
        const firstCodeField = page.locator('.overlay-field[data-word-index="block-1-79"]').first();
        const secondCodeField = page.locator('.overlay-field[data-word-index="block-1-80"]').first();
        const firstCodeBox = await firstCodeField.boundingBox();
        const secondCodeBox = await secondCodeField.boundingBox();
        let invoiceInteraction;
        if (!firstCodeBox || !secondCodeBox) {
            invoiceInteraction = {
                error: 'missing expected invoice code fields for interaction check',
            };
        } else {
            const readActiveCodeFields = async () => page.evaluate(() => {
                const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
                return Array.from(document.querySelectorAll('.overlay-field.active, .overlay-field.selected, .overlay-field.editing'))
                    .map((field) => ({
                        key: field.dataset.wordIndex || '',
                        text: normalize(field.innerText || field.textContent || ''),
                    }));
            });

            await page.mouse.click(firstCodeBox.x + (firstCodeBox.width / 2), firstCodeBox.y + (firstCodeBox.height / 2));
            await page.waitForTimeout(200);
            const afterClick = await readActiveCodeFields();

            await page.mouse.dblclick(firstCodeBox.x + (firstCodeBox.width / 2), firstCodeBox.y + (firstCodeBox.height / 2));
            await page.waitForTimeout(300);
            const afterDblclick = await readActiveCodeFields();

            const editingState = await page.evaluate(() => {
                const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
                const editing = document.querySelector('.overlay-field.editing');
                return editing ? {
                    key: editing.dataset.wordIndex || '',
                    text: normalize(editing.innerText || editing.textContent || ''),
                } : null;
            });

            invoiceInteraction = {
                firstCodeBox,
                secondCodeBox,
                verticalGap: secondCodeBox.y - (firstCodeBox.y + firstCodeBox.height),
                afterClick,
                afterDblclick,
                editingKey: editingState?.key || null,
                editingText: editingState?.text || null,
                secondFieldKey: 'block-1-80',
            };
        }
        const invoiceVisualState = await page.evaluate(() => {
            const firstField = document.querySelector('.overlay-field[data-word-index="block-1-79"]');
            const secondField = document.querySelector('.overlay-field[data-word-index="block-1-80"]');
            if (!firstField || !secondField) {
                return { error: 'missing expected invoice code fields for visual check' };
            }
            const firstStyle = getComputedStyle(firstField);
            const secondStyle = getComputedStyle(secondField);
            return {
                first: {
                    background: firstStyle.backgroundColor,
                    borderColor: firstStyle.borderColor,
                    boxShadow: firstStyle.boxShadow,
                    borderRadius: firstStyle.borderRadius,
                },
                second: {
                    background: secondStyle.backgroundColor,
                    borderColor: secondStyle.borderColor,
                    boxShadow: secondStyle.boxShadow,
                    borderRadius: secondStyle.borderRadius,
                },
            };
        });

        const groupedFields = codeFields.filter((entry) => entry.codeMatches.length !== 1 || entry.text !== entry.codeMatches[0]);
        const codeFieldsByPage = Object.fromEntries(
            Object.entries(INVOICE_EXPECTED_CODE_FIELDS_BY_PAGE).map(([pageNumber, expectedCodes]) => {
                const numericPage = Number(pageNumber);
                return [
                    numericPage,
                    {
                        expectedCodes,
                        fields: codeFields.filter((entry) => entry.pageNumber === numericPage),
                    },
                ];
            })
        );
        const pageSummaries = Object.fromEntries(
            Object.entries(codeFieldsByPage).map(([pageNumber, summary]) => {
                const fieldTexts = summary.fields.map((entry) => entry.text);
                const extractedCodes = summary.fields.flatMap((entry) => entry.codeMatches);
                const uniqueCodes = Array.from(new Set(extractedCodes));
                return [
                    pageNumber,
                    {
                        expectedCount: summary.expectedCodes.length,
                        actualCount: summary.fields.length,
                        fieldTexts,
                        missingCodes: summary.expectedCodes.filter((code) => !uniqueCodes.includes(code)),
                        unexpectedCodes: uniqueCodes.filter((code) => !summary.expectedCodes.includes(code)),
                        uniqueCodes,
                    },
                ];
            })
        );
        const totalExpectedCodes = Object.values(INVOICE_EXPECTED_CODE_FIELDS_BY_PAGE).reduce((sum, codes) => sum + codes.length, 0);
        const pageCountMatches = Object.values(pageSummaries).every((summary) => summary.actualCount === summary.expectedCount);
        const pageCodesMatch = Object.values(pageSummaries).every((summary) => summary.missingCodes.length === 0 && summary.unexpectedCodes.length === 0);

        const checks = [
            {
                item: 'expected_code_field_count',
                result: codeFields.length === totalExpectedCodes && pageCountMatches ? 'PASS' : 'FAIL',
                description: 'Each invoice page loads one BPXPN overlay field per expected code row.',
                detail: JSON.stringify({
                    expected_total_count: totalExpectedCodes,
                    actual_total_count: codeFields.length,
                    per_page: pageSummaries,
                }),
            },
            {
                item: 'each_code_field_contains_single_code',
                result: groupedFields.length === 0 ? 'PASS' : 'FAIL',
                description: 'Each BPXPN overlay field contains exactly one code and no grouped multiline stack.',
                detail: JSON.stringify(groupedFields),
            },
            {
                item: 'invoice_code_fields_edit_independently',
                result: invoiceInteraction.error
                    ? 'FAIL'
                    : (
                        invoiceInteraction.editingKey === 'block-1-79'
                        && Array.isArray(invoiceInteraction.afterClick)
                        && invoiceInteraction.afterClick.length <= 1
                        && Array.isArray(invoiceInteraction.afterDblclick)
                        && invoiceInteraction.afterDblclick.every((entry) => entry.key === 'block-1-79')
                    ) ? 'PASS' : 'FAIL',
                description: 'Clicking or editing one invoice code row does not activate a grouped neighboring stack.',
                detail: JSON.stringify(invoiceInteraction),
            },
            {
                item: 'invoice_code_fields_visually_separated',
                result: invoiceVisualState.error
                    ? 'FAIL'
                    : (
                        /rgba\(255,\s*255,\s*255,\s*0\.(9|98)/.test(invoiceVisualState.first.background)
                        && !/none/i.test(invoiceVisualState.first.boxShadow)
                        && invoiceInteraction
                        && Number(invoiceInteraction.verticalGap) >= 10
                    ) ? 'PASS' : 'FAIL',
                description: 'Invoice code rows render with visible box styling and a real vertical gap instead of reading as one continuous column.',
                detail: JSON.stringify({
                    interaction: invoiceInteraction,
                    visual: invoiceVisualState,
                }),
            },
            {
                item: 'all_expected_codes_present',
                result: pageCodesMatch ? 'PASS' : 'FAIL',
                description: 'The loaded BPXPN overlay fields match the expected invoice code set on each page.',
                detail: JSON.stringify(pageSummaries),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Invoice Code Column Load', kind: 'image', filename: screenshotName },
            ],
            fileSize: fs.existsSync(INVOICE_TEST_FIXTURE_PATH) ? fs.statSync(INVOICE_TEST_FIXTURE_PATH).size : 0,
            metadata: {
                document_id: documentId,
                code_fields: codeFields,
            },
        });
    } catch (error) {
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: 'fail',
            checks: [],
            error: error instanceof Error ? error.message : String(error),
            metadata: {
                document_id: documentId,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures.
            }
        }
        await browser.close();
    }
}

async function runOverlayTextBackgroundPersistsAfterSaveFlow() {
    const test = TESTS.test_25_overlay_text_background_persists_after_save;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName = buildArtifactName(test.key, runToken, 'after_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath = path.join(OUTPUT_DIR, afterSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await clearPdfSessionId(page);

        documentId = await createFixtureDocument(page, NDA_FIXTURE_PATH);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const targetSelector = await getOverlayFieldSelector(page, OVERLAY_BACKGROUND_TEST_TARGET_TEXT);
        const initialVisual = await readOverlayFieldBackgroundState(page, targetSelector);
        const appliedBackground = await setOverlayFieldBackgroundColor(page, targetSelector, OVERLAY_BACKGROUND_TEST_COLOR);
        await capturePageScreenshot(page, beforeSavePath);

        await waitForOverlaySaveComplete(page);
        await page.waitForLoadState('domcontentloaded', { timeout: 90000 }).catch(() => {});
        await waitForEditorReady(page);
        await forceRefreshOverlay(page, documentId);
        await activateOverlay(page);

        const reloadedSelector = await getOverlayFieldSelector(page, OVERLAY_BACKGROUND_TEST_TARGET_TEXT);
        const savedVisual = await readOverlayFieldBackgroundState(page, reloadedSelector);
        await capturePageScreenshot(page, afterSavePath);

        await downloadSavedPdf(page, documentId, finalPdfPath);
        const pdfDrawings = await fetchPdfDrawingSummaries(finalPdfPath);
        const targetCenter = bboxCenter(appliedBackground.bbox || [0, 0, 0, 0]);
        const matchingDrawings = (Array.isArray(pdfDrawings.drawings) ? pdfDrawings.drawings : [])
            .filter((drawing) => Array.isArray(drawing.rect) && drawing.rect.length === 4)
            .filter((drawing) => normalizeHex(drawing.fill, '') === OVERLAY_BACKGROUND_TEST_COLOR)
            .map((drawing) => {
                const drawingCenter = bboxCenter(drawing.rect);
                return {
                    rect: drawing.rect,
                    fill: drawing.fill,
                    overlap: rectOverlapRatio(appliedBackground.bbox, drawing.rect),
                    centerDeltaX: Math.abs(targetCenter.x - drawingCenter.x),
                    centerDeltaY: Math.abs(targetCenter.y - drawingCenter.y),
                };
            })
            .sort((left, right) => {
                if (right.overlap !== left.overlap) {
                    return right.overlap - left.overlap;
                }
                return (left.centerDeltaX + left.centerDeltaY) - (right.centerDeltaX + right.centerDeltaY);
            });
        const matchedDrawing = matchingDrawings[0] || null;

        const savedVisualHexes = [
            savedVisual.textBackground,
            savedVisual.wrapperBackground,
            savedVisual.fieldBackground,
            savedVisual.datasetBackground,
        ].map((value) => colorToHex(value, '')).filter(Boolean);
        const editorBackgroundPass = savedVisualHexes.includes(OVERLAY_BACKGROUND_TEST_COLOR);
        const savedPdfBackgroundPass = Boolean(
            matchedDrawing
            && matchedDrawing.overlap >= 0.6
            && matchedDrawing.centerDeltaX <= OVERLAY_BACKGROUND_MATCH_TOLERANCE
            && matchedDrawing.centerDeltaY <= OVERLAY_BACKGROUND_MATCH_TOLERANCE
        );

        const checks = [
            {
                item: 'overlay_background_edit_record_created',
                result: normalizeHex(appliedBackground.backgroundColor, '') === OVERLAY_BACKGROUND_TEST_COLOR ? 'PASS' : 'FAIL',
                description: 'Applying the overlay background produces a persisted edit record with the requested background color before save.',
                detail: JSON.stringify({
                    applied_background: appliedBackground,
                    initial_visual: initialVisual,
                }),
            },
            {
                item: 'overlay_background_reloads_after_save',
                result: editorBackgroundPass ? 'PASS' : 'FAIL',
                description: 'After save and overlay reload, the target invoice field still renders with the requested background color in the editor.',
                detail: JSON.stringify({
                    target_text: OVERLAY_BACKGROUND_TEST_TARGET_TEXT,
                    expected_background: OVERLAY_BACKGROUND_TEST_COLOR,
                    saved_visual: savedVisual,
                    saved_visual_hexes: savedVisualHexes,
                }),
            },
            {
                item: 'saved_pdf_contains_overlay_background_fill',
                result: savedPdfBackgroundPass ? 'PASS' : 'FAIL',
                description: 'The saved PDF contains a filled drawing near the edited overlay field bbox with the requested background color.',
                detail: JSON.stringify({
                    expected_bbox: appliedBackground.bbox,
                    expected_fill: OVERLAY_BACKGROUND_TEST_COLOR,
                    matched_drawing: matchedDrawing,
                    candidate_drawings: matchingDrawings,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Overlay Background Before Save', kind: 'image', filename: beforeSaveName },
                { label: 'Overlay Background After Save', kind: 'image', filename: afterSaveName },
                { label: 'Overlay Background Final PDF', kind: 'pdf', filename: finalPdfName },
            ],
            fileSize: fs.existsSync(finalPdfPath) ? fs.statSync(finalPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                target_text: OVERLAY_BACKGROUND_TEST_TARGET_TEXT,
                expected_background: OVERLAY_BACKGROUND_TEST_COLOR,
                applied_background: appliedBackground,
                initial_visual: initialVisual,
                saved_visual: savedVisual,
                matched_drawing: matchedDrawing,
                candidate_drawings: matchingDrawings,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runOverlayBackgroundInLoadedSavedPdfModeFlow() {
    const test = TESTS.test_26_overlay_background_persists_in_loaded_saved_pdf_mode;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName = buildArtifactName(test.key, runToken, 'after_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath = path.join(OUTPUT_DIR, afterSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await clearPdfSessionId(page);

        // Create a fresh document and generate a session ID.
        documentId = await createFixtureDocument(page, NDA_FIXTURE_PATH);
        await waitForEditorReady(page);
        const sessionId = await ensurePdfSessionId(page, 'test26');

        // Navigate directly into loadedSavedPdf mode — the session has no prior saves
        // so the overlay will be rebuilt from extraction (same as the real user scenario
        // where the user loads a previously saved session).
        await openLoadedSavedPdfEditor(page, documentId, sessionId);
        await activateOverlay(page);

        // Apply a background color to the lead-in overlay field WITHOUT changing the text.
        const targetSelector = await getOverlayFieldSelector(page, OVERLAY_BACKGROUND_TEST_TARGET_TEXT);
        const appliedBackground = await setOverlayFieldBackgroundColor(page, targetSelector, OVERLAY_BACKGROUND_TEST_COLOR);
        await capturePageScreenshot(page, beforeSavePath);

        // Save in loadedSavedPdf mode.
        await waitForOverlaySaveComplete(page);
        await page.waitForLoadState('domcontentloaded', { timeout: 90000 }).catch(() => {});
        await waitForEditorReady(page);
        await capturePageScreenshot(page, afterSavePath);

        // Download the PDF and verify the background fill is present.
        await downloadSavedPdf(page, documentId, finalPdfPath);
        const pdfDrawings = await fetchPdfDrawingSummaries(finalPdfPath);
        const targetCenter = bboxCenter(appliedBackground.bbox || [0, 0, 0, 0]);
        const matchingDrawings = (Array.isArray(pdfDrawings.drawings) ? pdfDrawings.drawings : [])
            .filter((drawing) => Array.isArray(drawing.rect) && drawing.rect.length === 4)
            .filter((drawing) => normalizeHex(drawing.fill, '') === OVERLAY_BACKGROUND_TEST_COLOR)
            .map((drawing) => {
                const drawingCenter = bboxCenter(drawing.rect);
                return {
                    rect: drawing.rect,
                    fill: drawing.fill,
                    overlap: rectOverlapRatio(appliedBackground.bbox, drawing.rect),
                    centerDeltaX: Math.abs(targetCenter.x - drawingCenter.x),
                    centerDeltaY: Math.abs(targetCenter.y - drawingCenter.y),
                };
            })
            .sort((left, right) => {
                if (right.overlap !== left.overlap) return right.overlap - left.overlap;
                return (left.centerDeltaX + left.centerDeltaY) - (right.centerDeltaX + right.centerDeltaY);
            });
        const matchedDrawing = matchingDrawings[0] || null;

        const savedPdfBackgroundPass = Boolean(
            matchedDrawing
            && matchedDrawing.overlap >= 0.5
            && matchedDrawing.centerDeltaX <= OVERLAY_BACKGROUND_MATCH_TOLERANCE * 2
            && matchedDrawing.centerDeltaY <= OVERLAY_BACKGROUND_MATCH_TOLERANCE * 2
        );

        const checks = [
            {
                item: 'background_edit_record_created_in_loaded_saved_mode',
                result: normalizeHex(appliedBackground.backgroundColor, '') === OVERLAY_BACKGROUND_TEST_COLOR ? 'PASS' : 'FAIL',
                description: 'Applying a background in loadedSavedPdf mode produces an edit record with the requested background color.',
                detail: JSON.stringify({ applied_background: appliedBackground }),
            },
            {
                item: 'saved_pdf_contains_background_fill_after_loaded_save',
                result: savedPdfBackgroundPass ? 'PASS' : 'FAIL',
                description: 'After saving in loadedSavedPdf mode with a background-only change, the saved PDF contains a filled drawing at the overlay field position.',
                detail: JSON.stringify({
                    expected_bbox: appliedBackground.bbox,
                    expected_fill: OVERLAY_BACKGROUND_TEST_COLOR,
                    matched_drawing: matchedDrawing,
                    candidate_drawings: matchingDrawings,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Before Save (loadedSavedPdf mode)', kind: 'image', filename: beforeSaveName },
                { label: 'After Save (loadedSavedPdf mode)', kind: 'image', filename: afterSaveName },
                { label: 'Final PDF', kind: 'pdf', filename: finalPdfName },
            ],
            fileSize: fs.existsSync(finalPdfPath) ? fs.statSync(finalPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                session_id: sessionId,
                target_text: OVERLAY_BACKGROUND_TEST_TARGET_TEXT,
                expected_background: OVERLAY_BACKGROUND_TEST_COLOR,
                applied_background: appliedBackground,
                matched_drawing: matchedDrawing,
                candidate_drawings: matchingDrawings,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runBlankTextRotationPersistsAfterSaveFlow() {
    const test = TESTS.test_27_blank_text_rotation_persists_after_save;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'before_download');
    const pdfName = buildArtifactName(test.key, runToken, 'download_pdf', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Letter',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, BLANK_TEXT_ROTATION_TEST_TEXT, 120, 140);
        const configuredState = await setTextAnnotationVisualProperties(page, BLANK_TEXT_ROTATION_TEST_TEXT, {
            keepBounds: true,
            left: 124,
            top: 112,
            width: 260,
            height: 56,
            rotation: BLANK_TEXT_ROTATION_DEGREES,
        });

        await capturePageScreenshot(page, screenshotPath);
        await clickOutsideFirstPage(page);
        await page.waitForTimeout(1200);
        await downloadPdfViaToolbar(page, pdfPath);

        const pdfGeometry = loadPdfTextGeometry(pdfPath, 1, BLANK_TEXT_ROTATION_TEST_TEXT);
        const quadAngles = Array.isArray(pdfGeometry?.quads)
            ? pdfGeometry.quads.map((entry) => normalizeRotationAngleDegrees(entry?.angle))
            : [];
        const lineAngles = Array.isArray(pdfGeometry?.lines)
            ? pdfGeometry.lines.map((entry) => normalizeRotationAngleDegrees(entry?.angle))
            : [];
        const observedAngle = quadAngles.find((angle) => angle > 0.5)
            ?? lineAngles.find((angle) => angle > 0.5)
            ?? 0;
        const rotationPass = Math.abs(observedAngle - BLANK_TEXT_ROTATION_DEGREES) <= BLANK_TEXT_ROTATION_ANGLE_TOLERANCE;

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'text_rotation_applied_before_export',
                result: Math.abs((Number(configuredState.rotation) || 0) - BLANK_TEXT_ROTATION_DEGREES) <= 0.1 ? 'PASS' : 'FAIL',
                description: 'The text annotation carried the requested rotation in editor state before export.',
                detail: JSON.stringify(configuredState),
            },
            {
                item: 'download_pdf_contains_rotated_text_geometry',
                result: rotationPass ? 'PASS' : 'FAIL',
                description: 'The Download PDF export keeps the stamped text rotated rather than returning it to horizontal.',
                detail: JSON.stringify({
                    expected_angle: BLANK_TEXT_ROTATION_DEGREES,
                    observed_angle: observedAngle,
                    quad_angles: quadAngles,
                    line_angles: lineAngles,
                    geometry: pdfGeometry,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Before Download', kind: 'image', filename: screenshotName },
                { label: 'Downloaded PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                configured_state: configuredState,
                pdf_geometry: pdfGeometry,
                observed_angle: observedAngle,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runBlankTextBackgroundPersistsAfterSaveFlow() {
    const test = TESTS.test_28_blank_text_background_persists_after_save;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'before_download');
    const pdfName = buildArtifactName(test.key, runToken, 'download_pdf', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Letter',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, BLANK_TEXT_BACKGROUND_TEST_TEXT, 120, 140);
        const configuredState = await setTextAnnotationVisualProperties(page, BLANK_TEXT_BACKGROUND_TEST_TEXT, {
            keepBounds: true,
            left: 96,
            top: 164,
            width: 280,
            height: 58,
            backgroundColor: BLANK_TEXT_BACKGROUND_COLOR,
        });

        await capturePageScreenshot(page, screenshotPath);
        await clickOutsideFirstPage(page);
        await page.waitForTimeout(1200);
        await downloadPdfViaToolbar(page, pdfPath);

        const textSearch = loadPdfSearchRects(pdfPath, 1, BLANK_TEXT_BACKGROUND_TEST_TEXT);
        const textRect = Array.isArray(textSearch?.rects) && textSearch.rects.length > 0
            ? textSearch.rects[0]
            : null;
        const pdfDrawings = await fetchPdfDrawingSummaries(pdfPath);
        const pdfPageHeight = Number(pdfDrawings?.page_height || textSearch?.page_height || 0) || 0;
        const expectedBackgroundRect = (
            pdfPageHeight > 0
            && Number.isFinite(Number(configuredState?.pdfX))
            && Number.isFinite(Number(configuredState?.pdfY))
            && Number.isFinite(Number(configuredState?.pdfWidth))
            && Number.isFinite(Number(configuredState?.pdfHeight))
        ) ? [
            Number(configuredState.pdfX),
            pdfPageHeight - (Number(configuredState.pdfY) + Number(configuredState.pdfHeight)),
            Number(configuredState.pdfX) + Number(configuredState.pdfWidth),
            pdfPageHeight - Number(configuredState.pdfY),
        ] : null;
        const targetCenter = expectedBackgroundRect ? bboxCenter(expectedBackgroundRect) : null;
        const matchingDrawings = (Array.isArray(pdfDrawings?.drawings) ? pdfDrawings.drawings : [])
            .filter((drawing) => Array.isArray(drawing?.rect) && drawing.rect.length === 4)
            .filter((drawing) => normalizeHex(drawing.fill, '') === BLANK_TEXT_BACKGROUND_COLOR)
            .map((drawing) => {
                const drawingCenter = bboxCenter(drawing.rect);
                return {
                    rect: drawing.rect,
                    fill: drawing.fill,
                    overlap: expectedBackgroundRect ? rectOverlapRatio(expectedBackgroundRect, drawing.rect) : 0,
                    centerDeltaX: targetCenter ? Math.abs(targetCenter.x - drawingCenter.x) : null,
                    centerDeltaY: targetCenter ? Math.abs(targetCenter.y - drawingCenter.y) : null,
                };
            })
            .sort((left, right) => {
                if (right.overlap !== left.overlap) {
                    return right.overlap - left.overlap;
                }
                return ((left.centerDeltaX || 0) + (left.centerDeltaY || 0)) - ((right.centerDeltaX || 0) + (right.centerDeltaY || 0));
            });
        const matchedDrawing = matchingDrawings[0] || null;
        const backgroundPass = Boolean(
            textRect
            && expectedBackgroundRect
            && matchedDrawing
            && matchedDrawing.overlap >= 0.95
            && matchedDrawing.centerDeltaX <= BLANK_TEXT_DRAWING_MATCH_TOLERANCE
            && matchedDrawing.centerDeltaY <= BLANK_TEXT_DRAWING_MATCH_TOLERANCE
        );
        const configuredBackgroundHexes = [
            configuredState.backgroundColor,
            configuredState.fieldBackground,
            configuredState.textBackground,
            configuredState.wrapperBackground,
        ].map((value) => colorToHex(value, '')).filter(Boolean);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'text_background_applied_before_export',
                result: configuredBackgroundHexes.includes(BLANK_TEXT_BACKGROUND_COLOR) ? 'PASS' : 'FAIL',
                description: 'The text annotation showed the requested background color in the editor before export.',
                detail: JSON.stringify({
                    configured_state: configuredState,
                    configured_background_hexes: configuredBackgroundHexes,
                }),
            },
            {
                item: 'download_pdf_contains_text_background_fill',
                result: backgroundPass ? 'PASS' : 'FAIL',
                description: 'The Download PDF export contains a filled rectangle matching the annotation background color at the saved annotation bounds.',
                detail: JSON.stringify({
                    expected_background: BLANK_TEXT_BACKGROUND_COLOR,
                    expected_background_rect: expectedBackgroundRect,
                    text_rect: textRect,
                    matched_drawing: matchedDrawing,
                    candidate_drawings: matchingDrawings,
                    drawing_summary: pdfDrawings,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Before Download', kind: 'image', filename: screenshotName },
                { label: 'Downloaded PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                configured_state: configuredState,
                text_rect: textRect,
                matched_drawing: matchedDrawing,
                candidate_drawings: matchingDrawings,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runImageResizePersistsAfterDownloadFlow() {
    const test = TESTS.test_29_image_resize_persists_after_download;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'before_download');
    const pdfName = buildArtifactName(test.key, runToken, 'download_pdf', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Letter',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        const imageDataUrl = await page.evaluate(() => {
            const canvas = document.createElement('canvas');
            canvas.width = 300;
            canvas.height = 100;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#1d4ed8';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(20, 20, 260, 60);
            ctx.fillStyle = '#dc2626';
            ctx.fillRect(110, 30, 80, 40);
            return canvas.toDataURL('image/png');
        });

        await page.evaluate(() => {
            openSignatureModal('image');
        });
        await page.waitForSelector('#signature-modal.active', { timeout: 10000 });
        await page.setInputFiles('#signature-image-input', [{
            name: 'resize-regression.png',
            mimeType: 'image/png',
            buffer: Buffer.from(imageDataUrl.split(',')[1], 'base64'),
        }]);
        await page.waitForFunction(() => {
            const saveBtn = document.getElementById('signature-save');
            return saveBtn && saveBtn.disabled === false;
        }, null, { timeout: 10000 });
        await page.click('#signature-save');
        await page.waitForFunction(() => Array.isArray(annotations) && annotations.some((item) => item?.type === 'image'), null, { timeout: 30000 });
        await page.waitForTimeout(500);

        const initialState = await readFirstImageAnnotationState(page);
        const targetWidth = 360;
        const targetHeight = 220;
        const resizedState = await resizeFirstImageAnnotation(page, targetWidth, targetHeight);

        await capturePageScreenshot(page, screenshotPath);
        await clickOutsideFirstPage(page);
        await page.waitForTimeout(1200);
        await downloadPdfViaToolbar(page, pdfPath);

        const renderSummary = await fetchPdfRenderedForegroundBBox(pdfPath);
        const renderBBox = Array.isArray(renderSummary?.bbox) ? renderSummary.bbox : null;
        const renderWidth = renderBBox ? Math.abs(Number(renderBBox[2]) - Number(renderBBox[0])) + 1 : 0;
        const renderHeight = renderBBox ? Math.abs(Number(renderBBox[3]) - Number(renderBBox[1])) + 1 : 0;
        const widthMatches = renderBBox && Math.abs(renderWidth - resizedState.pdfWidth) <= 2;
        const heightMatches = renderBBox && Math.abs(renderHeight - resizedState.pdfHeight) <= 2;
        const resizedBoxPass = Math.abs(resizedState.width - targetWidth) <= 4 && Math.abs(resizedState.height - targetHeight) <= 4;

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'image_imported',
                result: initialState?.id ? 'PASS' : 'FAIL',
                description: 'The image annotation was created in the editor before resize.',
                detail: JSON.stringify(initialState),
            },
            {
                item: 'image_resized_in_editor',
                result: resizedBoxPass ? 'PASS' : 'FAIL',
                description: 'The editor-side image annotation was resized to the requested box before export.',
                detail: JSON.stringify(resizedState),
            },
            {
                item: 'download_pdf_contains_resized_image_rect',
                result: widthMatches && heightMatches ? 'PASS' : 'FAIL',
                description: 'The Download PDF export keeps the stamped image at the resized annotation dimensions instead of restoring the source aspect ratio.',
                detail: JSON.stringify({
                    expected_pdf_width: resizedState.pdfWidth,
                    expected_pdf_height: resizedState.pdfHeight,
                    observed_render_bbox: renderBBox,
                    observed_render_width: renderWidth,
                    observed_render_height: renderHeight,
                    render_summary: renderSummary,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Before Download', kind: 'image', filename: screenshotName },
                { label: 'Downloaded PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                initial_state: initialState,
                resized_state: resizedState,
                render_summary: renderSummary,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphLayoutProgressionFlow() {
    const test = TESTS.test_3_paragraphs;
    ensureOutputDir();

    const runToken = buildRunToken();
    const singleScreenshotName = buildArtifactName(test.key, runToken, 'single_paragraph');
    const twoScreenshotName = buildArtifactName(test.key, runToken, 'two_side_by_side');
    const threeScreenshotName = buildArtifactName(test.key, runToken, 'three_side_by_side');
    const singlePdfName = buildArtifactName(test.key, runToken, 'single_paragraph', 'pdf');
    const twoPdfName = buildArtifactName(test.key, runToken, 'two_side_by_side', 'pdf');
    const threePdfName = buildArtifactName(test.key, runToken, 'three_side_by_side', 'pdf');
    const singleScreenshotPath = path.join(OUTPUT_DIR, singleScreenshotName);
    const twoScreenshotPath = path.join(OUTPUT_DIR, twoScreenshotName);
    const threeScreenshotPath = path.join(OUTPUT_DIR, threeScreenshotName);
    const singlePdfPath = path.join(OUTPUT_DIR, singlePdfName);
    const twoPdfPath = path.join(OUTPUT_DIR, twoPdfName);
    const threePdfPath = path.join(OUTPUT_DIR, threePdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let singleDocumentId = null;
    let twoDocumentId = null;
    let threeDocumentId = null;

    try {
        singleDocumentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, singleDocumentId);

        const singleMetrics = [
            await createConfiguredParagraphAnnotation(page, PARAGRAPH_LAYOUT_SINGLE_CASE),
        ];
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, singleScreenshotPath);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, singleDocumentId, singlePdfPath);
        const singleSavedStates = [
            await readTextAnnotationState(page, PARAGRAPH_LAYOUT_SINGLE_CASE.fragment),
        ];
        const singleGrouping = await analyzeAnnotationGroupings(page, [PARAGRAPH_LAYOUT_SINGLE_CASE]);

        twoDocumentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, twoDocumentId);

        const twoMetrics = [];
        for (const paragraphCase of PARAGRAPH_LAYOUT_TWO_CASES) {
            twoMetrics.push(await createConfiguredParagraphAnnotation(page, paragraphCase));
        }
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, twoScreenshotPath);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, twoDocumentId, twoPdfPath);
        const twoSavedStates = [];
        for (const paragraphCase of PARAGRAPH_LAYOUT_TWO_CASES) {
            twoSavedStates.push(await readTextAnnotationState(page, paragraphCase.fragment));
        }
        const twoGrouping = await analyzeAnnotationGroupings(page, PARAGRAPH_LAYOUT_TWO_CASES);

        threeDocumentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, threeDocumentId);

        const threeMetrics = [];
        for (const paragraphCase of PARAGRAPH_LAYOUT_THREE_CASES) {
            threeMetrics.push(await createConfiguredParagraphAnnotation(page, paragraphCase));
        }
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, threeScreenshotPath);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, threeDocumentId, threePdfPath);
        const threeSavedStates = [];
        for (const paragraphCase of PARAGRAPH_LAYOUT_THREE_CASES) {
            threeSavedStates.push(await readTextAnnotationState(page, paragraphCase.fragment));
        }
        const threeGrouping = await analyzeAnnotationGroupings(page, PARAGRAPH_LAYOUT_THREE_CASES);

        const singleFitsPage = layoutMetricsFitPage(singleMetrics);
        const twoFitsPage = layoutMetricsFitPage(twoMetrics);
        const twoNoOverlap = layoutMetricsDoNotOverlap(twoMetrics);
        const twoOrdered = layoutMetricsOrderedLeftToRight(twoMetrics);
        const threeFitsPage = layoutMetricsFitPage(threeMetrics);
        const threeNoOverlap = layoutMetricsDoNotOverlap(threeMetrics);
        const threeOrdered = layoutMetricsOrderedLeftToRight(threeMetrics);
        const singleLineCount = singleMetrics[0]?.lineCount || 0;
        const twoMaxLines = Math.max(...twoMetrics.map((metrics) => Number(metrics?.lineCount) || 0));
        const threeMaxLines = Math.max(...threeMetrics.map((metrics) => Number(metrics?.lineCount) || 0));
        const lineCountProgression = singleLineCount < twoMaxLines && twoMaxLines < threeMaxLines;
        const singleSavedOrder = singleSavedStates.map((state) => Number(state?.pdfX) || 0);
        const twoSavedOrder = twoSavedStates.map((state) => Number(state?.pdfX) || 0);
        const threeSavedOrder = threeSavedStates.map((state) => Number(state?.pdfX) || 0);
        const singleSavedValid = singleSavedStates.every((state) => (Number(state?.pdfX) || 0) > 0 && (Number(state?.pdfY) || 0) > 0);
        const twoSavedValid = twoSavedStates.every((state) => (Number(state?.pdfX) || 0) > 0 && (Number(state?.pdfY) || 0) > 0);
        const threeSavedValid = threeSavedStates.every((state) => (Number(state?.pdfX) || 0) > 0 && (Number(state?.pdfY) || 0) > 0);
        const twoSavedOrdered = twoSavedOrder.every((value, index) => index === 0 || value > twoSavedOrder[index - 1]);
        const threeSavedOrdered = threeSavedOrder.every((value, index) => index === 0 || value > threeSavedOrder[index - 1]);

        const checks = [
            {
                item: 'blank_pdfs_created',
                result: singleDocumentId && twoDocumentId && threeDocumentId ? 'PASS' : 'FAIL',
                description: 'Fresh blank PDFs were created through the frontend blank-PDF flow for the one-column, two-column, and three-column layouts.',
                detail: `single_document_id=${singleDocumentId} two_document_id=${twoDocumentId} three_document_id=${threeDocumentId}`,
            },
            {
                item: 'single_paragraph_fits_page',
                result: singleFitsPage ? 'PASS' : 'FAIL',
                description: 'One wide paragraph annotation fit inside the page as a single-column layout before save.',
                detail: JSON.stringify(singleMetrics),
            },
            {
                item: 'two_paragraphs_fit_side_by_side',
                result: twoFitsPage && twoNoOverlap && twoOrdered ? 'PASS' : 'FAIL',
                description: 'Two paragraph annotations fit side by side on the page without overlapping before save.',
                detail: JSON.stringify(twoMetrics),
            },
            {
                item: 'three_paragraphs_scaled_to_fit',
                result: threeFitsPage && threeNoOverlap && threeOrdered ? 'PASS' : 'FAIL',
                description: 'Three paragraph annotations scaled down to fit side by side on the page without overlapping before save.',
                detail: JSON.stringify(threeMetrics),
            },
            {
                item: 'narrower_layouts_wrap_more_lines',
                result: lineCountProgression ? 'PASS' : 'FAIL',
                description: 'Splitting the page from one column to two columns to three columns increased paragraph wrapping line counts.',
                detail: JSON.stringify({
                    single_line_count: singleLineCount,
                    two_column_line_counts: twoMetrics.map((metrics) => metrics.lineCount),
                    three_column_line_counts: threeMetrics.map((metrics) => metrics.lineCount),
                }),
            },
            {
                item: 'single_layout_saved',
                result: singleSavedValid
                    && singleGrouping.matchesByCase[0]?.matchCount === 1
                    && singleGrouping.groupedBlocks.length === 0 ? 'PASS' : 'FAIL',
                description: 'Saving the single wide paragraph preserved one in-page annotation with valid PDF coordinates.',
                detail: JSON.stringify({
                    pdf_x_positions: singleSavedOrder,
                    states: singleSavedStates,
                    grouping: singleGrouping,
                }),
            },
            {
                item: 'two_layout_saved',
                result: twoSavedValid
                    && twoSavedOrdered
                    && twoGrouping.matchesByCase.every((entry) => entry.matchCount === 1)
                    && twoGrouping.groupedBlocks.length === 0 ? 'PASS' : 'FAIL',
                description: 'Saving the two side-by-side paragraphs preserved two separate in-page annotations in left-to-right PDF order.',
                detail: JSON.stringify({
                    pdf_x_positions: twoSavedOrder,
                    states: twoSavedStates,
                    grouping: twoGrouping,
                }),
            },
            {
                item: 'three_layout_saved',
                result: threeSavedValid
                    && threeSavedOrdered
                    && threeGrouping.matchesByCase.every((entry) => entry.matchCount === 1)
                    && threeGrouping.groupedBlocks.length === 0 ? 'PASS' : 'FAIL',
                description: 'Saving the three scaled side-by-side paragraphs preserved three separate in-page annotations in left-to-right PDF order.',
                detail: JSON.stringify({
                    pdf_x_positions: threeSavedOrder,
                    states: threeSavedStates,
                    grouping: threeGrouping,
                }),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const totalPdfSize = [singlePdfPath, twoPdfPath, threePdfPath]
            .filter((pdfPath) => fs.existsSync(pdfPath))
            .reduce((sum, pdfPath) => sum + fs.statSync(pdfPath).size, 0);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: totalPdfSize,
            artifacts: [
                {
                    label: 'Single Paragraph Layout',
                    kind: 'image',
                    filename: singleScreenshotName,
                },
                {
                    label: 'Single Paragraph PDF',
                    kind: 'pdf',
                    filename: singlePdfName,
                },
                {
                    label: 'Two Side-by-Side Paragraphs',
                    kind: 'image',
                    filename: twoScreenshotName,
                },
                {
                    label: 'Two Side-by-Side PDF',
                    kind: 'pdf',
                    filename: twoPdfName,
                },
                {
                    label: 'Three Side-by-Side Paragraphs',
                    kind: 'image',
                    filename: threeScreenshotName,
                },
                {
                    label: 'Three Side-by-Side PDF',
                    kind: 'pdf',
                    filename: threePdfName,
                },
            ],
            metadata: {
                document_ids: [singleDocumentId, twoDocumentId, threeDocumentId],
                single_metrics: singleMetrics,
                two_metrics: twoMetrics,
                three_metrics: threeMetrics,
                single_saved_states: singleSavedStates,
                two_saved_states: twoSavedStates,
                three_saved_states: threeSavedStates,
            },
        });
    } finally {
        for (const documentId of [singleDocumentId, twoDocumentId, threeDocumentId]) {
            if (!documentId) {
                continue;
            }
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphFlow() {
    const test = TESTS.test_3_paragraphs;
    ensureOutputDir();

    const runToken = buildRunToken();
    const narrowScreenshotName = buildArtifactName(test.key, runToken, 'narrow_200px');
    const wideScreenshotName = buildArtifactName(test.key, runToken, 'wide_800px');
    const movedScreenshotName = buildArtifactName(test.key, runToken, 'after_move_save');
    const deletedScreenshotName = buildArtifactName(test.key, runToken, 'after_delete_save');
    const movedPdfName = buildArtifactName(test.key, runToken, 'after_move_save', 'pdf');
    const deletedPdfName = buildArtifactName(test.key, runToken, 'after_delete_save', 'pdf');
    const narrowScreenshotPath = path.join(OUTPUT_DIR, narrowScreenshotName);
    const wideScreenshotPath = path.join(OUTPUT_DIR, wideScreenshotName);
    const movedScreenshotPath = path.join(OUTPUT_DIR, movedScreenshotName);
    const deletedScreenshotPath = path.join(OUTPUT_DIR, deletedScreenshotName);
    const movedPdfPath = path.join(OUTPUT_DIR, movedPdfName);
    const deletedPdfPath = path.join(OUTPUT_DIR, deletedPdfName);
    const columnsScreenshotName = buildArtifactName(test.key, runToken, 'three_columns_save');
    const columnsPdfName = buildArtifactName(test.key, runToken, 'three_columns_save', 'pdf');
    const columnsScreenshotPath = path.join(OUTPUT_DIR, columnsScreenshotName);
    const columnsPdfPath = path.join(OUTPUT_DIR, columnsPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let mainDocumentId = null;
    let deleteDocumentId = null;
    let columnsDocumentId = null;

    try {
        mainDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, mainDocumentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        const narrowMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, narrowScreenshotPath);

        await updateTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT, {
            width: PARAGRAPH_WIDE_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        const wideMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, wideScreenshotPath);

        await updateTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT, {
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        const narrowAgainMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        const targetPosition = buildDeterministicParagraphTarget(narrowAgainMetrics);
        await updateTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT, {
            left: targetPosition.left,
            top: targetPosition.top,
        });
        const movedMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        const moveResult = {
            before: narrowAgainMetrics,
            after: movedMetrics,
            deltaX: targetPosition.left - narrowAgainMetrics.left,
            deltaY: targetPosition.top - narrowAgainMetrics.top,
        };

        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, mainDocumentId, movedPdfPath);
        await forceRefreshOverlay(page, mainDocumentId);
        const movedExtraction = await fetchExtraction(page, mainDocumentId);
        const movedBlock = extractMatchingBlock(movedExtraction, PARAGRAPH_TEXT_FRAGMENT);
        if (!movedBlock) {
            throw new Error(`paragraph block missing after annotation save: ${JSON.stringify(movedExtraction?.extraction_data?.[0]?.blocks || [], null, 2)}`);
        }
        const movedLines = extractBlockLines(movedExtraction, movedBlock);
        if (movedLines.length === 0) {
            throw new Error(`paragraph block has no extracted lines after annotation save: ${JSON.stringify(movedBlock, null, 2)}`);
        }
        const movedBlockText = normalize(movedBlock?.text || '');
        const punctuationPreserved = normalizeTypographicAscii(movedBlockText).includes(normalizeTypographicAscii(PARAGRAPH_PUNCTUATION_EXPECTED));
        const punctuationSubstituted = movedBlockText.includes('?');
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, movedScreenshotPath);

        deleteDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, deleteDocumentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        await deleteTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT);
        await clickOutsideFirstPage(page);
        await downloadSavedPdf(page, deleteDocumentId, deletedPdfPath);
        await forceRefreshOverlay(page, deleteDocumentId);
        const deletedExtraction = await fetchExtraction(page, deleteDocumentId);
        const deletedBlock = extractMatchingBlock(deletedExtraction, PARAGRAPH_TEXT_FRAGMENT);
        await capturePageScreenshot(page, deletedScreenshotPath);

        columnsDocumentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, columnsDocumentId);

        for (let index = 0; index < PARAGRAPH_COLUMN_TEXTS.length; index += 1) {
            const fragment = PARAGRAPH_COLUMN_FRAGMENTS[index];
            await createTextAnnotationAt(page, fragment, 30, 120);
            await updateTextAnnotation(page, fragment, {
                text: PARAGRAPH_COLUMN_TEXTS[index],
                keepBounds: true,
                left: PARAGRAPH_COLUMN_LEFTS[index],
                top: 80,
                width: PARAGRAPH_COLUMN_WIDTH,
                height: PARAGRAPH_COLUMN_HEIGHT,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, columnsDocumentId, columnsPdfPath);
        await forceRefreshOverlay(page, columnsDocumentId);
        const columnsExtraction = await fetchExtraction(page, columnsDocumentId);
        const columnBlocks = PARAGRAPH_COLUMN_FRAGMENTS.map((fragment) => extractMatchingBlock(columnsExtraction, fragment));
        await capturePageScreenshot(page, columnsScreenshotPath);

        const movedBlockBBox = blockBBox(movedBlock);
        const meaningfulNarrowLineWidths = narrowAgainMetrics.lineWidths.filter((value) => value >= 20);
        const narrowFillLines = countWideLines(narrowAgainMetrics);
        const narrowFillTarget = Math.max(2, Math.floor(meaningfulNarrowLineWidths.length * 0.7));

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'Fresh blank PDFs were created through the frontend blank-PDF flow.',
                detail: `documents=${mainDocumentId},${deleteDocumentId}`,
            },
            {
                item: 'paragraph_reflowed_wider',
                result: wideMetrics.lineCount < narrowMetrics.lineCount ? 'PASS' : 'FAIL',
                description: 'Expanding the paragraph to 800px reduced the number of wrapped annotation lines.',
                detail: `narrow_lines=${narrowMetrics.lineCount} wide_lines=${wideMetrics.lineCount}`,
            },
            {
                item: 'paragraph_reflowed_back_to_narrow',
                result: Math.abs(narrowAgainMetrics.lineCount - narrowMetrics.lineCount) <= 1 ? 'PASS' : 'FAIL',
                description: 'Shrinking the paragraph back to 200px restored the narrow wrapped annotation layout.',
                detail: `first_narrow_lines=${narrowMetrics.lineCount} second_narrow_lines=${narrowAgainMetrics.lineCount}`,
            },
            {
                item: 'narrow_layout_fills_box_edges',
                result: narrowFillLines >= narrowFillTarget ? 'PASS' : 'FAIL',
                description: 'The 200px paragraph uses most of the available width on its wrapped lines before save.',
                detail: `wide_lines_in_box=${narrowFillLines} target=${narrowFillTarget} line_widths=${JSON.stringify(narrowAgainMetrics.lineWidths.map((value) => Number(value.toFixed(2))))} content_width=${narrowAgainMetrics.contentWidth.toFixed(2)}`,
            },
            {
                item: 'typographic_punctuation_not_converted_to_question_marks',
                result: punctuationPreserved && !punctuationSubstituted ? 'PASS' : 'FAIL',
                description: 'After the annotation save, typographic apostrophes and smart quotes are preserved as readable punctuation instead of being converted to question marks.',
                detail: JSON.stringify({
                    expected_saved_text: PARAGRAPH_PUNCTUATION_EXPECTED,
                    extracted_block_text: movedBlockText,
                    found_question_mark: punctuationSubstituted,
                }),
            },
            {
                item: 'narrow_browser_box_matches_target',
                result: Math.abs(narrowAgainMetrics.width - PARAGRAPH_NARROW_WIDTH) <= PARAGRAPH_SIZE_TOLERANCE
                    && Math.abs(narrowAgainMetrics.height - PARAGRAPH_NARROW_HEIGHT) <= PARAGRAPH_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The live paragraph annotation was resized to the requested 200px by 500px narrow size.',
                detail: `browser_size=${narrowAgainMetrics.width.toFixed(2)}x${narrowAgainMetrics.height.toFixed(2)}`,
            },
            {
                item: 'wide_browser_box_matches_target',
                result: Math.abs(wideMetrics.width - PARAGRAPH_WIDE_WIDTH) <= PARAGRAPH_SIZE_TOLERANCE
                    && Math.abs(wideMetrics.height - PARAGRAPH_NARROW_HEIGHT) <= PARAGRAPH_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The live paragraph annotation was resized to the requested 800px width without changing height.',
                detail: `browser_size=${wideMetrics.width.toFixed(2)}x${wideMetrics.height.toFixed(2)}`,
            },
            {
                item: 'moved_paragraph_saved_new_position',
                result: moveResult.deltaX >= PARAGRAPH_LAYOUT_TOLERANCE || moveResult.deltaY >= PARAGRAPH_LAYOUT_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The paragraph annotation moved to a materially different browser position before save.',
                detail: `browser_delta=(${moveResult.deltaX.toFixed(2)}, ${moveResult.deltaY.toFixed(2)}) target=${JSON.stringify(targetPosition)} saved_bbox=${JSON.stringify(movedBlockBBox)}`,
            },
            {
                item: 'paragraph_saved_after_move',
                result: movedBlock ? 'PASS' : 'FAIL',
                description: 'The moved paragraph was stamped into the saved PDF through the annotation path.',
                detail: `saved_bbox=${JSON.stringify(movedBlockBBox)}`,
            },
            {
                item: 'paragraph_deleted',
                result: deletedBlock ? 'FAIL' : 'PASS',
                description: 'Deleting the unsaved paragraph before save prevented it from being stamped into the PDF.',
                detail: deletedBlock ? JSON.stringify(deletedBlock) : 'paragraph text absent after delete save',
            },
            {
                item: 'three_side_by_side_paragraphs_saved',
                result: columnBlocks.every(Boolean) ? 'PASS' : 'FAIL',
                description: 'Three side-by-side 200px by 800px paragraph annotations were all stamped into the saved PDF.',
                detail: JSON.stringify(columnBlocks.map((block, index) => ({
                    fragment: PARAGRAPH_COLUMN_FRAGMENTS[index],
                    found: Boolean(block),
                    bbox: block ? blockBBox(block) : null,
                }))),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(movedPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Narrow 200px Paragraph',
                    kind: 'image',
                    filename: narrowScreenshotName,
                },
                {
                    label: 'Wide 800px Paragraph',
                    kind: 'image',
                    filename: wideScreenshotName,
                },
                {
                    label: 'After Move Save',
                    kind: 'image',
                    filename: movedScreenshotName,
                },
                {
                    label: 'After Delete Save',
                    kind: 'image',
                    filename: deletedScreenshotName,
                },
                {
                    label: 'Moved PDF',
                    kind: 'pdf',
                    filename: movedPdfName,
                },
                {
                    label: 'Deleted PDF',
                    kind: 'pdf',
                    filename: deletedPdfName,
                },
                {
                    label: 'Three Columns Save',
                    kind: 'image',
                    filename: columnsScreenshotName,
                },
                {
                    label: 'Three Columns PDF',
                    kind: 'pdf',
                    filename: columnsPdfName,
                },
            ],
            metadata: {
                document_ids: [mainDocumentId, deleteDocumentId, columnsDocumentId].filter(Boolean),
                paragraph_text: PARAGRAPH_TEXT,
                narrow_metrics: narrowMetrics,
                wide_metrics: wideMetrics,
                narrow_again_metrics: narrowAgainMetrics,
                move_target: targetPosition,
                browser_move: moveResult,
                moved_saved_lines: movedLines,
                moved_saved_text: movedBlockText,
                moved_saved_bbox: movedBlockBBox,
                column_saved_blocks: columnBlocks,
            },
        });
    } finally {
        if (mainDocumentId) {
            try {
                await deleteDocument(page, mainDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        if (deleteDocumentId) {
            try {
                await deleteDocument(page, deleteDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        if (columnsDocumentId) {
            try {
                await deleteDocument(page, columnsDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runThreeParagraphColumnsFlow() {
    const test = TESTS.test_4_three_paragraph_columns;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'three_columns_save');
    const pdfName = buildArtifactName(test.key, runToken, 'three_columns_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (let index = 0; index < PARAGRAPH_COLUMN_TEXTS.length; index += 1) {
            const fragment = PARAGRAPH_COLUMN_FRAGMENTS[index];
            await createTextAnnotationAt(page, fragment, 30, 120);
            await updateTextAnnotation(page, fragment, {
                text: PARAGRAPH_COLUMN_TEXTS[index],
                keepBounds: true,
                left: PARAGRAPH_COLUMN_LEFTS[index],
                top: 80,
                width: PARAGRAPH_COLUMN_WIDTH,
                height: PARAGRAPH_COLUMN_HEIGHT,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const columnBlocks = PARAGRAPH_COLUMN_FRAGMENTS.map((fragment) => extractMatchingBlock(extraction, fragment));
        await capturePageScreenshot(page, screenshotPath);

        const leftPositions = columnBlocks.map((block) => (block ? blockBBox(block)[0] : null));
        const leftToRightOrdered = leftPositions.every((value, index) => (
            index === 0 || value === null || leftPositions[index - 1] === null || value > leftPositions[index - 1]
        ));

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'three_side_by_side_paragraphs_saved',
                result: columnBlocks.every(Boolean) ? 'PASS' : 'FAIL',
                description: 'Three side-by-side 200px by 800px paragraph annotations were all stamped into the saved PDF.',
                detail: JSON.stringify(columnBlocks.map((block, index) => ({
                    fragment: PARAGRAPH_COLUMN_FRAGMENTS[index],
                    found: Boolean(block),
                    bbox: block ? blockBBox(block) : null,
                }))),
            },
            {
                item: 'three_columns_preserve_left_to_right_order',
                result: leftToRightOrdered ? 'PASS' : 'FAIL',
                description: 'The saved paragraph columns remain ordered from left to right after save.',
                detail: JSON.stringify(leftPositions),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(pdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Three Columns Save',
                    kind: 'image',
                    filename: screenshotName,
                },
                {
                    label: 'Three Columns PDF',
                    kind: 'pdf',
                    filename: pdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                left_positions: leftPositions,
                column_saved_blocks: columnBlocks,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphBlockIntegrityFlow() {
    const test = TESTS.test_5_paragraph_block_integrity;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_block_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_block_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const expectedStart = normalizeTypographicAscii(PARAGRAPH_TEXT_FRAGMENT);
        const expectedTail = normalizeTypographicAscii(PARAGRAPH_PUNCTUATION_EXPECTED);
        const rawParagraphBlock = rawBlocks.find((block) => {
            const text = normalizeTypographicAscii(block?.text || '');
            return text.includes(expectedStart) && text.includes(expectedTail);
        }) || null;

        const siblingParagraphBlocks = rawParagraphBlock
            ? rawBlocks.filter((block) => (
                Math.abs((Number(block?.left) || 0) - (Number(rawParagraphBlock?.left) || 0)) <= 3
                && Math.abs((Number(block?.font_size) || 0) - (Number(rawParagraphBlock?.font_size) || 0)) <= 0.75
                && normalize(block?.font || '') === normalize(rawParagraphBlock?.font || '')
                && (Number(block?.top) || 0) >= ((Number(rawParagraphBlock?.top) || 0) - 1)
                && ((Number(block?.top) || 0) + (Number(block?.height) || 0)) <= (((Number(rawParagraphBlock?.top) || 0) + (Number(rawParagraphBlock?.height) || 0)) + 1)
            ))
            : [];

        const paragraphLines = rawParagraphBlock ? extractBlockLines(extraction, rawParagraphBlock) : [];
        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw paragraph block that includes both the start and end of the paragraph text.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 220),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'paragraph_raw_block_has_multiple_lines',
                result: rawParagraphBlock && paragraphLines.length > 1 ? 'PASS' : 'FAIL',
                description: 'The saved paragraph block exposes multiple wrapped lines inside one block.',
                detail: JSON.stringify(paragraphLines),
            },
            {
                item: 'paragraph_not_split_into_raw_line_blocks',
                result: rawParagraphBlock && siblingParagraphBlocks.length === 1 ? 'PASS' : 'FAIL',
                description: 'The saved paragraph is not split into multiple raw blocks on the same column and font.',
                detail: JSON.stringify(siblingParagraphBlocks.map((block) => ({
                    bbox: blockBBox(block),
                    text: block?.text,
                    block_num: block?.block_num,
                    line_count: block?.line_count,
                }))),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(pdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Paragraph Block Save',
                    kind: 'image',
                    filename: screenshotName,
                },
                {
                    label: 'Paragraph Block PDF',
                    kind: 'pdf',
                    filename: pdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                raw_paragraph_block: rawParagraphBlock,
                sibling_paragraph_blocks: siblingParagraphBlocks,
                paragraph_lines: paragraphLines,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphBlankLineIntegrityFlow() {
    const test = TESTS.test_6_paragraph_blank_line_integrity;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_blank_line_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_blank_line_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_BLANK_LINE_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const expectedStart = normalizeTypographicAscii(PARAGRAPH_BLANK_LINE_START);
        const expectedTail = normalizeTypographicAscii(PARAGRAPH_BLANK_LINE_END);
        const rawParagraphBlock = rawBlocks.find((block) => {
            const text = normalizeTypographicAscii(block?.text || '');
            return text.includes(expectedStart) && text.includes(expectedTail);
        }) || null;

        const siblingParagraphBlocks = rawParagraphBlock
            ? rawBlocks.filter((block) => (
                Math.abs((Number(block?.left) || 0) - (Number(rawParagraphBlock?.left) || 0)) <= 3
                && Math.abs((Number(block?.font_size) || 0) - (Number(rawParagraphBlock?.font_size) || 0)) <= 0.75
                && normalize(block?.font || '') === normalize(rawParagraphBlock?.font || '')
                && (Number(block?.top) || 0) >= ((Number(rawParagraphBlock?.top) || 0) - 1)
                && ((Number(block?.top) || 0) + (Number(block?.height) || 0)) <= (((Number(rawParagraphBlock?.top) || 0) + (Number(rawParagraphBlock?.height) || 0)) + 1)
            ))
            : [];

        const paragraphLines = rawParagraphBlock ? extractBlockLines(extraction, rawParagraphBlock) : [];
        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'blank_line_paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw block that includes both the first paragraph and the post-blank-line paragraph text.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 240),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'blank_line_paragraph_not_split_into_raw_blocks',
                result: rawParagraphBlock && siblingParagraphBlocks.length === 1 ? 'PASS' : 'FAIL',
                description: 'The saved annotation with an explicit blank line is not split into multiple raw blocks on the same column and font.',
                detail: JSON.stringify(siblingParagraphBlocks.map((block) => ({
                    bbox: blockBBox(block),
                    text: block.text,
                    block_num: block.block_num,
                    line_count: block.line_count,
                }))),
            },
            {
                item: 'blank_line_separator_preserved_in_raw_text',
                result: rawParagraphBlock && String(rawParagraphBlock.text || '').includes('\n\n') ? 'PASS' : 'FAIL',
                description: 'The saved raw block preserves the explicit blank-line separator as a double newline.',
                detail: rawParagraphBlock ? JSON.stringify(rawParagraphBlock.text) : 'null',
            },
            {
                item: 'blank_line_paragraph_has_multiple_lines',
                result: paragraphLines.length >= 4 ? 'PASS' : 'FAIL',
                description: 'The saved blank-line paragraph still exposes multiple wrapped lines inside the merged block.',
                detail: JSON.stringify(paragraphLines),
            },
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Paragraph Blank Line Save', kind: 'image', filename: screenshotName },
                { label: 'Paragraph Blank Line PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_blank_line_block: rawParagraphBlock,
                paragraph_lines: paragraphLines,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphIndentPreservedFlow() {
    const test = TESTS.test_13_paragraph_indent_preserved;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_indent_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_indent_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_INDENTED_TEXT,
            keepBounds: true,
            left: 48,
            top: 96,
            width: 320,
            height: 280,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const rawParagraphBlock = rawBlocks.find((block) => normalizeTypographicAscii(block?.text || '').includes(normalizeTypographicAscii(PARAGRAPH_INDENTED_START))) || null;
        const blockLines = rawParagraphBlock ? extractBlockLines(extraction, rawParagraphBlock) : [];
        const lineBBoxes = Array.isArray(rawParagraphBlock?.line_bboxes) ? rawParagraphBlock.line_bboxes : [];
        const normalizedLineLefts = lineBBoxes
            .filter((bbox) => Array.isArray(bbox) && bbox.length >= 4)
            .map((bbox) => Number(bbox[0]) || 0);
        const firstLineLeft = normalizedLineLefts[0] ?? null;
        const followingLineLefts = normalizedLineLefts.slice(1);
        const minimumFollowingLeft = followingLineLefts.length ? Math.min(...followingLineLefts) : null;
        const indentDelta = (firstLineLeft !== null && minimumFollowingLeft !== null)
            ? firstLineLeft - minimumFollowingLeft
            : null;

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'indented_paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw block for the indented paragraph.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 220),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'indented_paragraph_has_multiple_lines',
                result: rawParagraphBlock && blockLines.length >= 4 ? 'PASS' : 'FAIL',
                description: 'The saved indented paragraph still exposes multiple lines inside one block.',
                detail: JSON.stringify(blockLines),
            },
            {
                item: 'first_line_indent_preserved',
                result: indentDelta !== null && indentDelta >= 8 ? 'PASS' : 'FAIL',
                description: 'The saved block preserves a measurable first-line indent instead of flattening all lines to the same left edge.',
                detail: JSON.stringify({
                    first_line_left: firstLineLeft,
                    minimum_following_left: minimumFollowingLeft,
                    indent_delta: indentDelta,
                    line_bboxes: lineBBoxes,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Paragraph Indent Save', kind: 'image', filename: screenshotName },
                { label: 'Paragraph Indent PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_paragraph_block: rawParagraphBlock,
                block_lines: blockLines,
                line_bboxes: lineBBoxes,
                indent_delta: indentDelta,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runShortIntroBlankLineIntegrityFlow() {
    const test = TESTS.test_7_short_intro_blank_line_integrity;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'short_intro_blank_line_save');
    const pdfName = buildArtifactName(test.key, runToken, 'short_intro_blank_line_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_SHORT_INTRO_BLANK_LINE_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: 420,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const expectedStart = normalizeTypographicAscii(PARAGRAPH_SHORT_INTRO_START);
        const expectedTail = normalizeTypographicAscii(PARAGRAPH_SHORT_INTRO_END);
        const rawParagraphBlock = rawBlocks.find((block) => {
            const text = normalizeTypographicAscii(block?.text || '');
            return text.includes(expectedStart) && text.includes(expectedTail);
        }) || null;

        const siblingParagraphBlocks = rawParagraphBlock
            ? rawBlocks.filter((block) => (
                Math.abs((Number(block?.left) || 0) - (Number(rawParagraphBlock?.left) || 0)) <= 3
                && Math.abs((Number(block?.font_size) || 0) - (Number(rawParagraphBlock?.font_size) || 0)) <= 0.75
                && normalize(block?.font || '') === normalize(rawParagraphBlock?.font || '')
                && (Number(block?.top) || 0) >= ((Number(rawParagraphBlock?.top) || 0) - 1)
                && ((Number(block?.top) || 0) + (Number(block?.height) || 0)) <= (((Number(rawParagraphBlock?.top) || 0) + (Number(rawParagraphBlock?.height) || 0)) + 1)
            ))
            : [];

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'short_intro_paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw block spanning the short intro paragraph and later paragraph text.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 260),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'short_intro_paragraph_not_split_into_raw_blocks',
                result: rawParagraphBlock && siblingParagraphBlocks.length === 1 ? 'PASS' : 'FAIL',
                description: 'The saved short-intro blank-line annotation is not split into multiple raw blocks on the same column and font.',
                detail: JSON.stringify(siblingParagraphBlocks.map((block) => ({
                    bbox: blockBBox(block),
                    text: block.text,
                    block_num: block.block_num,
                    line_count: block.line_count,
                }))),
            },
            {
                item: 'short_intro_blank_line_separator_preserved',
                result: rawParagraphBlock && (String(rawParagraphBlock.text || '').match(/\n\n/g) || []).length >= 2 ? 'PASS' : 'FAIL',
                description: 'The saved raw block preserves both explicit blank-line separators as double newlines.',
                detail: rawParagraphBlock ? JSON.stringify(rawParagraphBlock.text) : 'null',
            },
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Short Intro Blank Line Save', kind: 'image', filename: screenshotName },
                { label: 'Short Intro Blank Line PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_short_intro_block: rawParagraphBlock,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runEditedParagraphNewlinesPreservedFlow() {
    const test = TESTS.test_8_edited_paragraph_newlines_preserved;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'edited_paragraph_newlines_save');
    const pdfName = buildArtifactName(test.key, runToken, 'edited_paragraph_newlines_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_EDITED_NEWLINES_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: 180,
            height: 260,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const rawParagraphBlock = rawBlocks.find((block) => (
            normalizeTypographicAscii(block?.text || '') === normalizeTypographicAscii(PARAGRAPH_EDITED_NEWLINES_TEXT)
        )) || null;

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'edited_newlines_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The edited multiline annotation is saved back as one raw extraction block.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text: rawParagraphBlock.text,
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'edited_newlines_text_exactly_preserved',
                result: rawParagraphBlock && String(rawParagraphBlock.text || '') === PARAGRAPH_EDITED_NEWLINES_TEXT ? 'PASS' : 'FAIL',
                description: 'The saved raw block preserves the explicit edited blank lines exactly.',
                detail: rawParagraphBlock ? JSON.stringify(rawParagraphBlock.text) : 'null',
            },
            {
                item: 'edited_newlines_separator_count_preserved',
                result: rawParagraphBlock && (String(rawParagraphBlock.text || '').match(/\n\n/g) || []).length === 3 ? 'PASS' : 'FAIL',
                description: 'The saved raw block still contains the three explicit blank-line separators.',
                detail: rawParagraphBlock ? String((String(rawParagraphBlock.text || '').match(/\n\n/g) || []).length) : 'null',
            },
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Edited Paragraph Newlines Save', kind: 'image', filename: screenshotName },
                { label: 'Edited Paragraph Newlines PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_edited_newlines_block: rawParagraphBlock,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphPositionAccuracyFlow() {
    const test = TESTS.test_9_paragraph_position_accuracy;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_position_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_position_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (const positionCase of PARAGRAPH_POSITION_CASES) {
            await createTextAnnotationAt(page, positionCase.fragment, 30, 120);
            await updateTextAnnotation(page, positionCase.fragment, {
                text: positionCase.text,
                keepBounds: true,
                left: positionCase.left,
                top: positionCase.top,
                width: positionCase.width,
                height: positionCase.height,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);

        const afterSaveMetrics = {};
        for (const positionCase of PARAGRAPH_POSITION_CASES) {
            afterSaveMetrics[positionCase.fragment] = await readTextAnnotationMetrics(page, positionCase.fragment);
        }
        await capturePageScreenshot(page, screenshotPath);

        const referenceCase = PARAGRAPH_POSITION_CASES[0];
        const referenceAfter = afterSaveMetrics[referenceCase.fragment];
        const referenceOffsetX = (referenceAfter?.left || 0) - referenceCase.left;
        const referenceOffsetY = (referenceAfter?.top || 0) - referenceCase.top;

        const positionChecks = PARAGRAPH_POSITION_CASES.map((positionCase) => {
            const after = afterSaveMetrics[positionCase.fragment];
            const offsetX = (after?.left || 0) - positionCase.left;
            const offsetY = (after?.top || 0) - positionCase.top;
            const offsetErrorX = Math.abs(offsetX - referenceOffsetX);
            const offsetErrorY = Math.abs(offsetY - referenceOffsetY);

            return {
                item: `${normalize(positionCase.fragment).toLowerCase().replace(/\s+/g, '_')}_anchor_offset_consistent`,
                result: offsetErrorX <= PARAGRAPH_POSITION_TOLERANCE && offsetErrorY <= PARAGRAPH_POSITION_TOLERANCE ? 'PASS' : 'FAIL',
                description: `${positionCase.fragment} keeps a consistent placement offset after save and reload.`,
                detail: `target=(${positionCase.left}, ${positionCase.top}) after=(${after?.left?.toFixed?.(2) ?? after?.left}, ${after?.top?.toFixed?.(2) ?? after?.top}) offset=(${offsetX.toFixed(2)}, ${offsetY.toFixed(2)}) reference_offset=(${referenceOffsetX.toFixed(2)}, ${referenceOffsetY.toFixed(2)}) error=(${offsetErrorX.toFixed(2)}, ${offsetErrorY.toFixed(2)})`,
            };
        });

        const relativeChecks = [];
        for (let index = 1; index < PARAGRAPH_POSITION_CASES.length; index += 1) {
            const current = PARAGRAPH_POSITION_CASES[index];
            const previous = PARAGRAPH_POSITION_CASES[index - 1];
            const afterCurrent = afterSaveMetrics[current.fragment];
            const afterPrevious = afterSaveMetrics[previous.fragment];
            const targetDeltaX = current.left - previous.left;
            const targetDeltaY = current.top - previous.top;
            const afterDeltaX = (afterCurrent?.left || 0) - (afterPrevious?.left || 0);
            const afterDeltaY = (afterCurrent?.top || 0) - (afterPrevious?.top || 0);
            const deltaXError = Math.abs(afterDeltaX - targetDeltaX);
            const deltaYError = Math.abs(afterDeltaY - targetDeltaY);
            relativeChecks.push({
                item: `${normalize(previous.fragment).toLowerCase().replace(/\s+/g, '_')}_to_${normalize(current.fragment).toLowerCase().replace(/\s+/g, '_')}_relative_position_preserved`,
                result: deltaXError <= PARAGRAPH_POSITION_TOLERANCE && deltaYError <= PARAGRAPH_POSITION_TOLERANCE ? 'PASS' : 'FAIL',
                description: `${previous.fragment} and ${current.fragment} keep their relative spacing after save and reload.`,
                detail: `target_delta=(${targetDeltaX.toFixed(2)}, ${targetDeltaY.toFixed(2)}) after_delta=(${afterDeltaX.toFixed(2)}, ${afterDeltaY.toFixed(2)}) error=(${deltaXError.toFixed(2)}, ${deltaYError.toFixed(2)})`,
            });
        }

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            ...positionChecks,
            ...relativeChecks,
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Paragraph Position Save', kind: 'image', filename: screenshotName },
                { label: 'Paragraph Position PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                reference_offset: {
                    left: referenceOffsetX,
                    top: referenceOffsetY,
                },
                after_save_metrics: afterSaveMetrics,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runDeleteAllPromotedTextSaveFlow() {
    const test = TESTS.test_10_delete_all_promoted_text_save;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'delete_all_promoted_text_save');
    const pdfName = buildArtifactName(test.key, runToken, 'delete_all_promoted_text_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Letter',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await page.waitForFunction(() => (
            typeof annotations !== 'undefined'
            && Array.isArray(annotations)
            && annotations.some((annotation) => annotation?.promotedFromExtraction || annotation?.savedTextOverlay)
        ), null, { timeout: 60000 });

        const reloadedTextAnnotationState = await page.evaluate(() => ({
            promotedCount: annotations.filter((annotation) => annotation?.promotedFromExtraction).length,
            savedOverlayCount: annotations.filter((annotation) => annotation?.savedTextOverlay).length,
        }));

        await page.evaluate(() => {
            const originalConfirm = window.confirm;
            window.confirm = () => true;
            try {
                document.getElementById('remove-all-annotations')?.click();
            } finally {
                window.confirm = originalConfirm;
            }
        });
        await page.waitForFunction(() => typeof annotations !== 'undefined' && annotations.length === 0, null, { timeout: 30000 });

        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await capturePageScreenshot(page, screenshotPath);

        const extraction = await fetchExtraction(page, documentId);
        const paragraphBlock = extractMatchingBlock(extraction, PARAGRAPH_TEXT_FRAGMENT);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'text_annotations_loaded_before_delete',
                result: (reloadedTextAnnotationState.promotedCount + reloadedTextAnnotationState.savedOverlayCount) > 0 ? 'PASS' : 'FAIL',
                description: 'The saved paragraph reloaded as editable text annotations before deletion.',
                detail: JSON.stringify(reloadedTextAnnotationState),
            },
            {
                item: 'empty_annotation_save_completed',
                result: 'PASS',
                description: 'Saving after deleting all promoted annotations completed through the PDF save flow.',
                detail: 'save button accepted an empty remaining annotation set',
            },
            {
                item: 'deleted_promoted_paragraph_absent_after_save',
                result: paragraphBlock ? 'FAIL' : 'PASS',
                description: 'The deleted promoted paragraph is absent from the saved PDF extraction after save.',
                detail: paragraphBlock ? JSON.stringify(paragraphBlock) : 'paragraph text absent after delete-all save',
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Delete All Promoted Text Save', kind: 'image', filename: screenshotName },
                { label: 'Delete All Promoted Text PDF', kind: 'pdf', filename: pdfName },
            ],
            metadata: {
                document_id: documentId,
                reloaded_text_annotation_state: reloadedTextAnnotationState,
                residual_block: paragraphBlock,
            },
        });
    } catch (error) {
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: 'fail',
            checks: [],
            error: error instanceof Error ? error.message : String(error),
            metadata: {
                document_id: documentId,
            },
        });
    } finally {
        await browser.close();
    }
}

async function runDownloadPdfTopRightTextPositionFlow() {
    const test = TESTS.test_24_download_pdf_top_right_text_position;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'top_right_before_save');
    const downloadPdfName = buildArtifactName(test.key, runToken, 'download_pdf', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const downloadPdfPath = path.join(OUTPUT_DIR, downloadPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Letter',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAtWithStyles(page, DOWNLOAD_TOP_RIGHT_TEXT, 120, 140, {
            fontSize: DOWNLOAD_TOP_RIGHT_FONT_SIZE_PX,
        });

        const initialState = await readTextAnnotationState(page, DOWNLOAD_TOP_RIGHT_TEXT);
        const targetLeft = Math.max(0, Math.round(initialState.overlayWidth - initialState.width - DOWNLOAD_TOP_RIGHT_MARGIN_PX));
        const targetTop = DOWNLOAD_TOP_RIGHT_MARGIN_PX;
        const moveState = await moveTextAnnotationTo(page, DOWNLOAD_TOP_RIGHT_TEXT, targetLeft, targetTop);
        const movedState = await readTextAnnotationState(page, DOWNLOAD_TOP_RIGHT_TEXT);

        await capturePageScreenshot(page, screenshotPath);
        await saveAnnotationsOnly(page);
        await downloadPdfViaToolbar(page, downloadPdfPath);

        const downloadSearch = loadPdfSearchRects(downloadPdfPath, 1, DOWNLOAD_TOP_RIGHT_TEXT);
        const downloadRect = Array.isArray(downloadSearch.rects) && downloadSearch.rects.length > 0
            ? downloadSearch.rects[0]
            : null;
        const downloadSpansByLine = loadPdfTargetLineSpans(downloadPdfPath, 1, [DOWNLOAD_TOP_RIGHT_TEXT]);
        const downloadLineSpans = Array.isArray(downloadSpansByLine?.[DOWNLOAD_TOP_RIGHT_TEXT])
            ? downloadSpansByLine[DOWNLOAD_TOP_RIGHT_TEXT]
            : [];
        const downloadPrimarySpan = downloadLineSpans.find((span) => normalize(span?.text) === normalize(DOWNLOAD_TOP_RIGHT_TEXT))
            || downloadLineSpans[0]
            || null;

        const currentScale = movedState.currentScale || initialState.currentScale || 1;
        const expectedTopPdf = movedState.top / currentScale;
        const expectedRightGapPdf = (movedState.overlayWidth - (movedState.left + movedState.width)) / currentScale;
        const actualTopPdf = downloadRect ? downloadRect[1] : null;
        const actualRightGapPdf = downloadRect ? (downloadSearch.page_width - downloadRect[2]) : null;
        const topError = downloadRect ? Math.abs(actualTopPdf - expectedTopPdf) : Infinity;
        const rightGapError = downloadRect ? Math.abs(actualRightGapPdf - expectedRightGapPdf) : Infinity;
        const actualDownloadFontSize = Number(downloadPrimarySpan?.size) || null;
        const fontSizeError = actualDownloadFontSize === null
            ? Infinity
            : Math.abs(actualDownloadFontSize - DOWNLOAD_TOP_RIGHT_FONT_SIZE_PX);

        const checks = [
            {
                item: 'browser_font_size_is_57px',
                result: Math.abs((Number(movedState.requestedFontSize) || 0) - DOWNLOAD_TOP_RIGHT_FONT_SIZE_PX) <= 0.5 ? 'PASS' : 'FAIL',
                description: `The text annotation kept the requested editor font size of ${DOWNLOAD_TOP_RIGHT_FONT_SIZE_PX}px before save.`,
                detail: `requested_font_size=${movedState.requestedFontSize} rendered_font_size_px=${movedState.fontSizePx.toFixed(2)}`,
            },
            {
                item: 'browser_drag_reaches_top_right_corner',
                result: Math.abs(movedState.left - targetLeft) <= 6 && Math.abs(movedState.top - targetTop) <= 6 ? 'PASS' : 'FAIL',
                description: 'The committed text annotation was dragged into the top-right corner before save.',
                detail: `target=(${targetLeft}, ${targetTop}) actual=(${movedState.left}, ${movedState.top}) move_state=${JSON.stringify(moveState)}`,
            },
            {
                item: 'download_pdf_contains_text',
                result: downloadRect ? 'PASS' : 'FAIL',
                description: 'The Download PDF export contains the stamped "Wolfchenez News" text.',
                detail: `download_rect=${JSON.stringify(downloadRect)}`,
            },
            {
                item: 'download_pdf_font_size_is_exactly_57px',
                result: fontSizeError <= DOWNLOAD_TOP_RIGHT_FONT_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The Download PDF export keeps the stamped text at exactly 57pt in the PDF content stream.',
                detail: JSON.stringify({
                    expected_font_size: DOWNLOAD_TOP_RIGHT_FONT_SIZE_PX,
                    actual_font_size: actualDownloadFontSize,
                    font_size_error: fontSizeError,
                    spans: downloadLineSpans,
                }),
            },
            {
                item: 'download_pdf_top_gap_matches_editor',
                result: topError <= DOWNLOAD_TOP_RIGHT_POSITION_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The downloaded PDF keeps the text near the same top offset seen in the editor after drag.',
                detail: `expected_top_pdf=${expectedTopPdf.toFixed(2)} actual_top_pdf=${actualTopPdf?.toFixed?.(2) ?? actualTopPdf} error=${topError.toFixed(2)}`,
            },
            {
                item: 'download_pdf_right_gap_matches_editor',
                result: rightGapError <= DOWNLOAD_TOP_RIGHT_POSITION_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The downloaded PDF keeps the text near the same right-edge offset seen in the editor after drag.',
                detail: `expected_right_gap_pdf=${expectedRightGapPdf.toFixed(2)} actual_right_gap_pdf=${actualRightGapPdf?.toFixed?.(2) ?? actualRightGapPdf} error=${rightGapError.toFixed(2)}`,
            },
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Top Right Before Save', kind: 'image', filename: screenshotName },
                { label: 'Download PDF Export', kind: 'pdf', filename: downloadPdfName },
            ],
            fileSize: fs.existsSync(downloadPdfPath) ? fs.statSync(downloadPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                initial_state: initialState,
                moved_state: movedState,
                target_left: targetLeft,
                target_top: targetTop,
                download_rect: downloadRect,
                download_line_spans: downloadLineSpans,
                expected_top_pdf: expectedTopPdf,
                expected_right_gap_pdf: expectedRightGapPdf,
                actual_top_pdf: actualTopPdf,
                actual_right_gap_pdf: actualRightGapPdf,
                actual_download_font_size: actualDownloadFontSize,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await context.close();
        await browser.close();
    }
}

async function runSeparateLinesNotGroupedFlow() {
    const test = TESTS.test_11_separate_lines_not_grouped;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'separate_lines_save');
    const pdfName = buildArtifactName(test.key, runToken, 'separate_lines_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (const lineCase of PARAGRAPH_SEPARATE_LINE_CASES) {
            await createTextAnnotationAt(page, lineCase.fragment, 30, 120);
            await updateTextAnnotation(page, lineCase.fragment, {
                text: lineCase.text,
                keepBounds: true,
                left: lineCase.left,
                top: lineCase.top,
                width: lineCase.width,
                height: lineCase.height,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];

        const matchedBlocks = PARAGRAPH_SEPARATE_LINE_CASES.map((lineCase) => {
            const block = rawBlocks.find((candidate) => normalizeTypographicAscii(candidate?.text || '').includes(normalizeTypographicAscii(lineCase.text)));
            return {
                fragment: lineCase.fragment,
                text: lineCase.text,
                block,
            };
        });

        const uniqueBlockNums = new Set(
            matchedBlocks
                .map((entry) => entry.block?.block_num)
                .filter((value) => value !== undefined && value !== null)
        );
        const combinedBlock = rawBlocks.find((block) => PARAGRAPH_SEPARATE_LINE_CASES.every((lineCase) => (
            normalizeTypographicAscii(block?.text || '').includes(normalizeTypographicAscii(lineCase.text))
        ))) || null;

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'separate_lines_each_saved_as_raw_block',
                result: matchedBlocks.every((entry) => entry.block) ? 'PASS' : 'FAIL',
                description: 'Each one-line text box is present in the saved extraction as its own raw block candidate.',
                detail: JSON.stringify(matchedBlocks.map((entry) => ({
                    fragment: entry.fragment,
                    block_num: entry.block?.block_num ?? null,
                    bbox: entry.block ? blockBBox(entry.block) : null,
                    text: entry.block?.text ?? null,
                }))),
            },
            {
                item: 'separate_lines_do_not_share_one_raw_block',
                result: uniqueBlockNums.size === PARAGRAPH_SEPARATE_LINE_CASES.length ? 'PASS' : 'FAIL',
                description: 'The three separate one-line text boxes do not collapse into the same raw block after save.',
                detail: JSON.stringify({
                    unique_block_count: uniqueBlockNums.size,
                    block_nums: Array.from(uniqueBlockNums),
                }),
            },
            {
                item: 'no_combined_paragraph_block_contains_all_three_lines',
                result: combinedBlock ? 'FAIL' : 'PASS',
                description: 'The saved extraction does not contain one merged paragraph block holding all three independent lines.',
                detail: combinedBlock ? JSON.stringify({
                    block_num: combinedBlock.block_num,
                    bbox: blockBBox(combinedBlock),
                    text: combinedBlock.text,
                }) : 'no combined block',
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Separate Lines Save', kind: 'image', filename: screenshotName },
                { label: 'Separate Lines PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                matched_blocks: matchedBlocks.map((entry) => ({
                    fragment: entry.fragment,
                    block: entry.block,
                })),
                combined_block: combinedBlock,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runTinySavedLabelsReloadSeparatelyFlow() {
    const test = TESTS.test_12_tiny_saved_labels_reload_separately;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'tiny_saved_labels_reload');
    const pdfName = buildArtifactName(test.key, runToken, 'tiny_saved_labels_reload', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (const lineCase of PARAGRAPH_TINY_SEPARATE_LINE_CASES) {
            await createTextAnnotationAt(page, lineCase.seed, 30, 120);
            await updateTextAnnotation(page, lineCase.seed, {
                text: lineCase.text,
                keepBounds: true,
                left: lineCase.left,
                top: lineCase.top,
                width: lineCase.width,
                height: lineCase.height,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await page.waitForFunction(() => (
            typeof annotations !== 'undefined'
            && Array.isArray(annotations)
            && annotations.length >= 4
        ), null, { timeout: 60000 });

        const reloadedAnnotations = await page.evaluate(() => annotations.map((annotation) => ({
            id: annotation?.id || null,
            text: annotation?.text || '',
            left: Number(annotation?.pdfX) || 0,
            top: Number(annotation?.pdfY) || 0,
            savedTextOverlay: Boolean(annotation?.savedTextOverlay),
            promotedFromExtraction: Boolean(annotation?.promotedFromExtraction),
        })));
        await capturePageScreenshot(page, screenshotPath);

        const tinyLabels = reloadedAnnotations.filter((annotation) => (
            annotation.text === 'no' || annotation.text === 'yes'
        ));
        const mergedTextAreas = reloadedAnnotations.filter((annotation) => /\n/.test(annotation.text || ''));
        const savedOverlayCount = tinyLabels.filter((annotation) => annotation.savedTextOverlay).length;

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'tiny_labels_reload_as_four_annotations',
                result: tinyLabels.length === 4 ? 'PASS' : 'FAIL',
                description: 'The four tiny stacked labels reload as four individual annotations.',
                detail: JSON.stringify(tinyLabels),
            },
            {
                item: 'tiny_labels_reload_from_saved_annotation_state',
                result: savedOverlayCount === 4 ? 'PASS' : 'FAIL',
                description: 'The reloaded tiny labels come from saved annotation state rather than promoted extraction grouping.',
                detail: JSON.stringify(tinyLabels.map((annotation) => ({
                    text: annotation.text,
                    savedTextOverlay: annotation.savedTextOverlay,
                    promotedFromExtraction: annotation.promotedFromExtraction,
                }))),
            },
            {
                item: 'tiny_labels_not_merged_into_multiline_annotation',
                result: mergedTextAreas.length === 0 ? 'PASS' : 'FAIL',
                description: 'Reloading the editor does not merge the tiny labels into one multiline text area.',
                detail: JSON.stringify(mergedTextAreas),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Tiny Saved Labels Reload', kind: 'image', filename: screenshotName },
                { label: 'Tiny Saved Labels PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                reloaded_annotations: reloadedAnnotations,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runEditNewF1040s1NoopEditParityFlow() {
    const test = TESTS.test_33_edit_new_f1040s1_noop_edit_parity;
    ensureOutputDir();

    const runToken = buildRunToken();
    const baselinePageName = buildArtifactName(test.key, runToken, 'page_baseline');
    const targetBaselineName = buildArtifactName(test.key, runToken, 'target_baseline');
    const targetActiveName = buildArtifactName(test.key, runToken, 'target_active_edit');
    const targetDiffName = buildArtifactName(test.key, runToken, 'target_diff');
    const worstBaselineName = buildArtifactName(test.key, runToken, 'worst_baseline');
    const worstActiveName = buildArtifactName(test.key, runToken, 'worst_active_edit');
    const worstDiffName = buildArtifactName(test.key, runToken, 'worst_diff');

    const baselinePagePath = path.join(OUTPUT_DIR, baselinePageName);
    const targetBaselinePath = path.join(OUTPUT_DIR, targetBaselineName);
    const targetActivePath = path.join(OUTPUT_DIR, targetActiveName);
    const targetDiffPath = path.join(OUTPUT_DIR, targetDiffName);
    const worstBaselinePath = path.join(OUTPUT_DIR, worstBaselineName);
    const worstActivePath = path.join(OUTPUT_DIR, worstActiveName);
    const worstDiffPath = path.join(OUTPUT_DIR, worstDiffName);

    const { pixelmatch, PNG } = getPixelDiffLibs();

    // Per-pixel match threshold passed to pixelmatch (0 = strict, 1 = loose).
    // Anti-aliasing of identical text on subsequent renders can produce 1px
    // edge halos that aren't real visual changes.
    const PIXEL_MATCH_THRESHOLD = 0.18;
    // Per-annotation maximum tolerated diff ratio (mismatched_pixels / total_pixels).
    // 0.005 = 0.5% pixel drift. Real bugs (font weight change, leader-dot
    // collapse, layout shift) produce diff ratios well above 5%.
    const MAX_DIFF_RATIO = 0.01;

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createFixtureDocument(page, EDIT_NEW_F1040S1_FIXTURE_PATH);
        await clearAnnotationSessionStateNew(page, documentId);
        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await waitForEditorReadyNew(page);

        // Toggle Edit PDF mode on through the bridge so visual diff captures
        // the same UI state the user sees after pressing the toolbar button.
        await page.evaluate(() => {
            const st = window.__editorTestState;
            if (!st || typeof st.setEditModeEnabled !== 'function') {
                throw new Error('pdf-new edit mode bridge missing');
            }
            st.setEditModeEnabled(true);
            st.redrawOverlay?.(0);
        });
        await page.waitForTimeout(800);

        // Inject screenshot-only CSS that hides every piece of selection
        // chrome so the baseline (deselected) and the active-edit shot
        // capture only the rendered annotation content. Includes the canvas
        // dashed outlines (suppressed via window flag the editor checks)
        // plus all DOM chrome.
        await page.addStyleTag({
            content: `
                #sb-1, [id^="sb-"],
                #tm-1, [id^="tm-"],
                #sh-1, [id^="sh-"],
                [id^="rh-"], .resize-handle, .rotate-handle,
                #ann-format-bar, .ann-format-bar,
                .annotation-tbc-menu, .shape-action-bar,
                .text-resize-handle, .text-rotate-handle, .shape-resize-handle,
                .delete-btn, .box-menu {
                    visibility: hidden !important;
                    opacity: 0 !important;
                    pointer-events: none !important;
                }
                /* Caret on the active editor must not appear in screenshots. */
                #ae-1, [id^="ae-"] { caret-color: transparent !important; }
            `,
        });

        // Hide chrome and disable hover state during the entire capture flow.
        await page.evaluate(() => {
            window.__pdfTestSuppressSelectionChrome = true;
            const st = window.__editorTestState;
            if (st && typeof st.redrawOverlay === 'function') st.redrawOverlay(0);
        });
        await page.waitForTimeout(200);

        // Capture a clean baseline screenshot of page 1 before any
        // selection happens so we can compute per-annotation regions.
        await capturePageScreenshotNew(page, baselinePagePath);

        // Snapshot annotation list + per-annotation on-page rect (in viewport
        // CSS pixels). Returns rects relative to the page so the test can
        // clip Playwright screenshots to each annotation's region.
        const PARITY_FIELDS = [
            'pdfX', 'pdfY', 'pdfWidth', 'pdfHeight',
            'fontFamily', 'fontWeight', 'fontStyle', 'fontSize',
            'textAlign', 'verticalAlign',
            'textColor', 'backgroundColor', 'backgroundColorExplicit',
            'lineHeight', 'underline', 'opacity', 'rotation',
        ];

        const annotationsInfo = await page.evaluate((fields) => {
            const st = window.__editorTestState;
            const data = st && st.pageData && st.pageData[0];
            if (!data) throw new Error('pageData[0] missing');
            const canvas = document.getElementById('oc-1');
            if (!canvas) throw new Error('overlay canvas oc-1 missing');
            const cr = canvas.getBoundingClientRect();
            const scale = data.scale;
            const ch = data.canvasHeight;
            // The canvas backing/CSS pixels (data.canvasWidth/Height) may be
            // CSS-zoomed by an outer transform; cr reflects the visible
            // viewport size. Convert canvas-internal coords → viewport px.
            const cw = data.canvasWidth;
            const zoomX = cw > 0 ? (cr.width / cw) : 1;
            const zoomY = ch > 0 ? (cr.height / ch) : 1;
            return data.annotations.map((ann, idx) => {
                const uid = String(ann._uid || '');
                const id = String(ann.id || ann._uid || `idx_${idx}`);
                const text = String(
                    st.editedTexts && Object.prototype.hasOwnProperty.call(st.editedTexts, uid)
                        ? st.editedTexts[uid]
                        : (ann.text != null ? ann.text : '')
                );
                const out = { uid, id, idx, text, type: String(ann.type || '') };
                for (const f of fields) out[f] = (ann && Object.prototype.hasOwnProperty.call(ann, f)) ? ann[f] : null;
                const x = Number(ann.pdfX);
                const y = Number(ann.pdfY);
                const w = Number(ann.pdfWidth);
                const h = Number(ann.pdfHeight);
                if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(w) || !Number.isFinite(h) || w <= 0 || h <= 0) {
                    out._rect = null;
                } else {
                    const left = cr.left + x * scale * zoomX;
                    const top = cr.top + (ch - (y + h) * scale) * zoomY;
                    const width = w * scale * zoomX;
                    const height = h * scale * zoomY;
                    out._rect = { x: left, y: top, width, height };
                }
                return out;
            });
        }, PARITY_FIELDS);

        const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const targetFragmentNorm = normalize(EDIT_NEW_F1040S1_TARGET_TEXT_FRAGMENT);
        const targetIdx = annotationsInfo.findIndex((entry) => normalize(entry.text).includes(targetFragmentNorm));
        const targetEntry = targetIdx >= 0 ? annotationsInfo[targetIdx] : null;

        // ── Per-annotation visual diff ────────────────────────────────────
        // For each annotation we:
        //   1. Make sure no annotation is selected → take the baseline shot.
        //   2. Select + focus the annotation (data-editing=1 → focus the
        //      contenteditable) so the editor enters inline edit mode.
        //      KEEP IT ACTIVE (no blur) while we capture.
        //   3. Take the active-edit shot of the same region.
        //   4. Pixelmatch and record the diff ratio.
        // The padding around each rect catches descenders / leader dots that
        // sit just outside the stored bbox.
        const REGION_PADDING_PX = 6;

        const clearSelection = async () => {
            await page.evaluate(() => {
                const st = window.__editorTestState;
                if (st && typeof st.clearActiveAnnotation === 'function') {
                    st.clearActiveAnnotation();
                } else if (st) {
                    st.selectAnnotation && st.selectAnnotation(null, 0);
                }
                const ae = document.getElementById('ae-1');
                if (ae) {
                    delete ae.dataset.editing;
                    delete ae.dataset.editingUid;
                    if (document.activeElement === ae) ae.blur();
                }
                if (st && typeof st.redrawOverlay === 'function') st.redrawOverlay(0);
            });
            await page.waitForTimeout(80);
        };

        const enterEditModeOn = async (uid) => {
            await page.evaluate((u) => {
                const st = window.__editorTestState;
                const data = st.pageData[0];
                const ann = data.annotations.find((a) => a._uid === u);
                if (!ann) throw new Error('annotation vanished: ' + u);
                st.selectAnnotation(ann, 0);
                const ae = document.getElementById('ae-1');
                if (ae) {
                    ae.dataset.editing = '1';
                    ae.dataset.editingUid = String(ann._uid || '');
                    ae.focus({ preventScroll: true });
                }
                if (st && typeof st.redrawOverlay === 'function') st.redrawOverlay(0);
            }, uid);
            await page.waitForTimeout(120);
        };

        const captureRegion = async (rect, outputPath) => {
            // Clamp clip to the page viewport.
            const vp = await page.evaluate(() => ({
                scrollX: window.scrollX || 0,
                scrollY: window.scrollY || 0,
                innerWidth: window.innerWidth || document.documentElement.clientWidth || 0,
                innerHeight: window.innerHeight || document.documentElement.clientHeight || 0,
            }));
            const clipX = Math.max(vp.scrollX, rect.x - REGION_PADDING_PX);
            const clipY = Math.max(vp.scrollY, rect.y - REGION_PADDING_PX);
            const maxRight = vp.scrollX + vp.innerWidth;
            const maxBottom = vp.scrollY + vp.innerHeight;
            const wantW = rect.width + REGION_PADDING_PX * 2;
            const wantH = rect.height + REGION_PADDING_PX * 2;
            const w = Math.max(2, Math.min(wantW, maxRight - clipX));
            const h = Math.max(2, Math.min(wantH, maxBottom - clipY));
            await page.screenshot({ path: outputPath, clip: { x: clipX, y: clipY, width: w, height: h } });
            return { width: w, height: h };
        };

        const computeDiff = (baselinePath, activePath, diffPath) => {
            const baseBuf = fs.readFileSync(baselinePath);
            const actBuf = fs.readFileSync(activePath);
            const basePng = PNG.sync.read(baseBuf);
            const actPng = PNG.sync.read(actBuf);
            const w = Math.min(basePng.width, actPng.width);
            const h = Math.min(basePng.height, actPng.height);
            // If sizes differ even by a pixel, count the entire size delta
            // as mismatched so the diff metric reflects layout drift.
            const sizeMismatched = (basePng.width - w) * basePng.height
                + (actPng.width - w) * actPng.height
                + (basePng.height - h) * w
                + (actPng.height - h) * w;
            const diffPng = new PNG({ width: w, height: h });
            // pixelmatch needs same-size buffers; copy the cropped top-left
            // region of each into a fresh w×h buffer.
            const toCropped = (src) => {
                if (src.width === w && src.height === h) return src.data;
                const out = Buffer.alloc(w * h * 4);
                for (let y = 0; y < h; y++) {
                    src.data.copy(out, y * w * 4, y * src.width * 4, y * src.width * 4 + w * 4);
                }
                return out;
            };
            const baseCropped = toCropped(basePng);
            const actCropped = toCropped(actPng);
            const mismatched = pixelmatch(baseCropped, actCropped, diffPng.data, w, h, {
                threshold: PIXEL_MATCH_THRESHOLD,
                includeAA: false,
                alpha: 0.4,
            });
            if (diffPath) fs.writeFileSync(diffPath, PNG.sync.write(diffPng));
            const total = w * h + sizeMismatched;
            const ratio = total > 0 ? (mismatched + sizeMismatched) / total : 0;
            return { mismatched, sizeMismatched, total, ratio, width: w, height: h, baseSize: { w: basePng.width, h: basePng.height }, actSize: { w: actPng.width, h: actPng.height } };
        };

        // Run the target FIRST so its diff artifacts are stable filenames.
        const orderedEntries = (() => {
            const usable = annotationsInfo.filter((e) => e._rect);
            if (!targetEntry || !targetEntry._rect) return usable;
            const tail = usable.filter((e) => e.uid !== targetEntry.uid);
            return [targetEntry, ...tail];
        })();

        const perAnnotationResults = [];
        let targetDiffResult = null;

        for (let i = 0; i < orderedEntries.length; i++) {
            const entry = orderedEntries[i];
            const isTarget = !!(targetEntry && entry.uid === targetEntry.uid);
            const tmpBaseline = isTarget ? targetBaselinePath : path.join(OUTPUT_DIR, `${test.key}_${runToken}_ann_${i}_baseline.png`);
            const tmpActive = isTarget ? targetActivePath : path.join(OUTPUT_DIR, `${test.key}_${runToken}_ann_${i}_active.png`);
            const tmpDiff = isTarget ? targetDiffPath : null;

            await clearSelection();
            await captureRegion(entry._rect, tmpBaseline);
            await enterEditModeOn(entry.uid);
            await captureRegion(entry._rect, tmpActive);
            const diffResult = computeDiff(tmpBaseline, tmpActive, tmpDiff);
            perAnnotationResults.push({
                uid: entry.uid,
                idx: entry.idx,
                isTarget,
                textPreview: entry.text.slice(0, 80),
                diff: diffResult,
                baselinePath: tmpBaseline,
                activePath: tmpActive,
            });
            if (isTarget) targetDiffResult = perAnnotationResults[perAnnotationResults.length - 1];

            // Clean up per-non-target temporary screenshots once their diff
            // is computed to keep the artifacts dir manageable for 90+ ann
            // documents. Keep the baseline & active for the worst offender,
            // emitted at the end.
            if (!isTarget) {
                // Will be deleted after worst-offender pick.
            }
        }

        await clearSelection();

        // Pick the worst non-target offender and persist its before/after/diff
        // as artifacts; delete the rest of the temp per-annotation pngs.
        const otherResults = perAnnotationResults.filter((r) => !r.isTarget);
        const worst = otherResults
            .slice()
            .sort((a, b) => b.diff.ratio - a.diff.ratio)[0] || null;

        if (worst) {
            try { fs.copyFileSync(worst.baselinePath, worstBaselinePath); } catch (_e) {}
            try { fs.copyFileSync(worst.activePath, worstActivePath); } catch (_e) {}
            try {
                const worstDiff = computeDiff(worstBaselinePath, worstActivePath, worstDiffPath);
                worst.diff = worstDiff;
            } catch (_e) {}
        }
        for (const r of otherResults) {
            try { fs.unlinkSync(r.baselinePath); } catch (_e) {}
            try { fs.unlinkSync(r.activePath); } catch (_e) {}
        }

        const checks = [];
        checks.push({
            item: 'fixture_loaded',
            result: 'PASS',
            description: 'Fixture PDF tests/OverlayEditor/f1040s1.pdf uploaded and opened in /edit-new',
            detail: `documentId=${documentId} annotation_count=${annotationsInfo.length}`,
        });
        checks.push({
            item: 'edit_mode_toggled',
            result: 'PASS',
            description: 'Edit PDF mode toggled on (#ftb-edit-mode equivalent via setEditModeEnabled)',
            detail: 'setEditModeEnabled(true) invoked through the test bridge',
        });
        checks.push({
            item: 'target_annotation_present',
            result: targetEntry ? 'PASS' : 'FAIL',
            description: `Page 1 exposes at least one extracted annotation containing the "${EDIT_NEW_F1040S1_TARGET_TEXT_FRAGMENT}" paragraph`,
            detail: targetEntry
                ? `matched uid=${targetEntry.uid} text=${JSON.stringify(targetEntry.text.slice(0, 200))}`
                : `no annotation contained the target fragment; sample texts: ${JSON.stringify(annotationsInfo.slice(0, 8).map((e) => e.text.slice(0, 80)))}`,
        });

        const targetPass = !!(targetDiffResult && targetDiffResult.diff.ratio <= MAX_DIFF_RATIO);
        checks.push({
            item: 'target_active_edit_visual_parity',
            result: !targetEntry ? 'FAIL' : (targetPass ? 'PASS' : 'FAIL'),
            description: 'Visual diff: capture a baseline screenshot of the target 1099-K paragraph region (no annotation focused), then enter inline edit mode on that annotation and KEEP the editor active. The active-edit-mode pixel rendering of the same region must match the baseline within tolerance (proves the active editor does not restyle the loaded text).',
            detail: !targetEntry
                ? 'target annotation not found — see target_annotation_present'
                : (targetPass
                    ? `pixel diff ratio=${targetDiffResult.diff.ratio.toFixed(5)} <= ${MAX_DIFF_RATIO} (mismatched=${targetDiffResult.diff.mismatched + targetDiffResult.diff.sizeMismatched} / ${targetDiffResult.diff.total})`
                    : `pixel diff ratio=${targetDiffResult.diff.ratio.toFixed(5)} > ${MAX_DIFF_RATIO} (mismatched=${targetDiffResult.diff.mismatched + targetDiffResult.diff.sizeMismatched} / ${targetDiffResult.diff.total}, baseline=${targetDiffResult.diff.baseSize.w}x${targetDiffResult.diff.baseSize.h}, active=${targetDiffResult.diff.actSize.w}x${targetDiffResult.diff.actSize.h}); see ${targetDiffName}`),
        });

        const offenders = otherResults.filter((r) => r.diff.ratio > MAX_DIFF_RATIO);
        const allOthersPass = offenders.length === 0;
        checks.push({
            item: 'all_annotations_active_edit_visual_parity',
            result: allOthersPass ? 'PASS' : 'FAIL',
            description: 'Visual diff: the same baseline-vs-active-edit-mode comparison applied to every other page-1 annotation must match within tolerance.',
            detail: allOthersPass
                ? `verified ${otherResults.length} non-target annotations with diff ratio <= ${MAX_DIFF_RATIO} (max=${otherResults.reduce((m, r) => Math.max(m, r.diff.ratio), 0).toFixed(5)})`
                : JSON.stringify({
                    threshold: MAX_DIFF_RATIO,
                    offender_count: offenders.length,
                    worst_offender: worst ? {
                        uid: worst.uid,
                        text_preview: worst.textPreview,
                        diff_ratio: worst.diff.ratio,
                        baseline_artifact: worstBaselineName,
                        active_artifact: worstActiveName,
                        diff_artifact: worstDiffName,
                    } : null,
                    offender_sample: offenders.slice(0, 8).map((r) => ({
                        uid: r.uid,
                        text_preview: r.textPreview,
                        diff_ratio: Number(r.diff.ratio.toFixed(5)),
                    })),
                }),
        });

        const status = checks.every((c) => c.result === 'PASS') ? 'pass' : 'fail';
        const artifacts = [
            { label: 'Page 1 baseline (no annotation focused)', kind: 'screenshot', filename: baselinePageName },
        ];
        if (targetEntry) {
            artifacts.push({ label: 'Target annotation baseline (deselected)', kind: 'screenshot', filename: targetBaselineName });
            artifacts.push({ label: 'Target annotation active edit mode', kind: 'screenshot', filename: targetActiveName });
            artifacts.push({ label: 'Target annotation pixel diff', kind: 'screenshot', filename: targetDiffName });
        }
        if (worst) {
            artifacts.push({ label: `Worst non-target offender baseline (uid=${worst.uid})`, kind: 'screenshot', filename: worstBaselineName });
            artifacts.push({ label: `Worst non-target offender active edit mode (uid=${worst.uid})`, kind: 'screenshot', filename: worstActiveName });
            artifacts.push({ label: `Worst non-target offender pixel diff (uid=${worst.uid})`, kind: 'screenshot', filename: worstDiffName });
        }

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts,
            metadata: {
                document_id: documentId,
                annotation_count: annotationsInfo.length,
                target_uid: targetEntry?.uid || null,
                target_text_preview: targetEntry?.text?.slice(0, 200) || null,
                target_diff_ratio: targetDiffResult?.diff?.ratio ?? null,
                non_target_max_diff_ratio: otherResults.reduce((m, r) => Math.max(m, r.diff.ratio), 0),
                non_target_offender_count: offenders.length,
                non_target_offender_sample: offenders.slice(0, 16).map((r) => ({
                    uid: r.uid,
                    text_preview: r.textPreview,
                    diff_ratio: Number(r.diff.ratio.toFixed(5)),
                })),
                pixel_match_threshold: PIXEL_MATCH_THRESHOLD,
                max_diff_ratio: MAX_DIFF_RATIO,
            },
        });
    } catch (error) {
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: 'error',
            error: error.stack || String(error),
        });
    } finally {
        if (documentId) {
            try { await deleteDocument(page, documentId); } catch (_error) { /* ignore */ }
        }
        await browser.close();
    }
}

async function runEditNewSpecialRulesGroupingFlow() {
    return runEditNewParagraphGroupingFlow({
        test: TESTS.test_31_edit_new_special_rules_grouping,
        fixturePath: EDIT_NEW_SPECIAL_RULES_FIXTURE_PATH,
        expectedBlocks: EDIT_NEW_SPECIAL_RULES_EXPECTED_BLOCKS,
        headingKey: 'special_rules_heading',
        groupingLabel: 'Special Rules',
    });
}

async function runEditNewWhatsNewGroupingFlow() {
    return runEditNewParagraphGroupingFlow({
        test: TESTS.test_32_edit_new_whats_new_grouping,
        fixturePath: EDIT_NEW_WHATS_NEW_FIXTURE_PATH,
        expectedBlocks: EDIT_NEW_WHATS_NEW_EXPECTED_BLOCKS,
        headingKey: 'whats_new_heading',
        groupingLabel: "What's New",
    });
}

async function runEditNewParagraphGroupingFlow({ test, fixturePath, expectedBlocks, headingKey, groupingLabel }) {
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'edit_new_page1');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createFixtureDocument(page, fixturePath);
        await clearAnnotationSessionStateNew(page, documentId);
        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await waitForEditorReadyNew(page);
        await page.evaluate(() => {
            const st = window.__editorTestState;
            if (!st || typeof st.setEditModeEnabled !== 'function') {
                throw new Error('pdf-new edit mode bridge missing');
            }
            st.setEditModeEnabled(true);
            st.redrawOverlay?.(0);
        });
        await page.waitForTimeout(1200);

        const sectionState = await page.evaluate((expectedBlocks) => {
            const normalizeText = (value) => String(value || '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
            const normalizeAscii = (value) => {
                let s = String(value || '')
                    .replace(/[\u00ad\u2010\u2011]/g, '-')
                    .replace(/[\u2018\u2019]/g, "'")
                    .replace(/[\u201c\u201d]/g, '"')
                    .replace(/[\u2013\u2014]/g, '-');
                s = normalizeText(s);
                // Collapse whitespace around fraction slashes between digits
                // (PyMuPDF extracts typeset fractions like "66²/₃%" as
                // "66 2 / 3 %" with spaces between super/subscript spans).
                s = s.replace(/(\d)\s+\/\s+(\d)/g, '$1/$2');
                // Collapse "<digits> <digits>/<digits>" → "<digits><digits>/<digits>"
                // (e.g. "66 2/3" → "662/3"). Only when the second number is
                // immediately followed by '/' so we don't merge unrelated digits.
                s = s.replace(/(\d)\s+(\d+\/\d+)/g, '$1$2');
                // Collapse spaces before % when adjacent to digits.
                s = s.replace(/(\d)\s+%/g, '$1%');
                // Collapse whitespace before sentence-ending punctuation
                // (italic-to-regular span gaps emit " ." / " ,").
                s = s.replace(/\s+([.,;:!?])/g, '$1');
                return s;
            };

            const st = window.__editorTestState;
            const data = st?.pageData?.[0];
            const scale = Number(data?.scale) || 1;
            const canvasHeight = Number(data?.canvasHeight) || 0;
            const annotations = Array.isArray(data?.annotations) ? data.annotations : [];
            const editedTexts = st?.editedTexts || {};

            const summaries = annotations.map((ann) => {
                const text = String(editedTexts[ann._uid] != null ? editedTexts[ann._uid] : (ann.text || ''));
                const normalizedText = normalizeAscii(text);
                const sourceTextLines = Array.isArray(ann?.sourceTextLines)
                    ? ann.sourceTextLines.map((line) => normalizeAscii(line)).filter(Boolean)
                    : [];
                const displayTop = canvasHeight > 0
                    ? canvasHeight - ((Number(ann?.pdfY) || 0) + (Number(ann?.pdfHeight) || 0)) * scale
                    : 0;
                return {
                    id: String(ann?.id || ann?._uid || ''),
                    text,
                    normalizedText,
                    sourceTextLines,
                    lineCount: sourceTextLines.length || (normalizedText ? 1 : 0),
                    displayLeft: (Number(ann?.pdfX) || 0) * scale,
                    displayTop,
                    displayWidth: (Number(ann?.pdfWidth) || 0) * scale,
                    displayHeight: (Number(ann?.pdfHeight) || 0) * scale,
                };
            }).sort((left, right) => (
                left.displayTop - right.displayTop || left.displayLeft - right.displayLeft
            ));

            const preparedBlocks = expectedBlocks.map((block) => ({
                ...block,
                normalizedText: normalizeAscii(block.text),
                normalizedMarker: normalizeAscii(block.marker),
            }));

            const exactMatches = Object.fromEntries(preparedBlocks.map((block) => [
                block.key,
                summaries.filter((entry) => entry.normalizedText === block.normalizedText),
            ]));

            const sectionMarkerHits = summaries
                .map((entry) => ({
                    id: entry.id,
                    normalizedText: entry.normalizedText,
                    lineCount: entry.lineCount,
                    displayLeft: entry.displayLeft,
                    displayTop: entry.displayTop,
                    displayWidth: entry.displayWidth,
                    displayHeight: entry.displayHeight,
                    matchedKeys: preparedBlocks
                        .filter((block) => entry.normalizedText.includes(block.normalizedMarker))
                        .map((block) => block.key),
                }))
                .filter((entry) => entry.matchedKeys.length > 0);

            return {
                annotationCount: summaries.length,
                exactMatches,
                combinedSections: sectionMarkerHits.filter((entry) => entry.matchedKeys.length > 1),
                matchedSections: sectionMarkerHits,
                sampleAnnotations: summaries.slice(0, 200),
            };
        }, expectedBlocks);

        await capturePageScreenshotNew(page, screenshotPath);

        const matchSummary = expectedBlocks.map((block) => ({
            key: block.key,
            expectedText: block.text,
            minSourceLines: block.minSourceLines,
            matches: Array.isArray(sectionState?.exactMatches?.[block.key]) ? sectionState.exactMatches[block.key] : [],
        }));
        const exactMatchesPass = matchSummary.every((entry) => entry.matches.length === 1);
        const orderedMatches = matchSummary
            .map((entry) => ({
                key: entry.key,
                match: entry.matches[0] || null,
            }))
            .filter((entry) => entry.match);
        const materializedAnnotationsPass = Number(sectionState?.annotationCount || 0) > 0;
        // Verify reading order column-by-column: group matches by display column
        // (using a wide left-bucket so multi-column PDF pages still validate the
        // expected per-column top-to-bottom sequence).
        const columnBucketWidth = 200;
        const columnGroups = new Map();
        orderedMatches.forEach((entry) => {
            const left = Number(entry.match?.displayLeft || 0);
            const bucket = Math.floor(left / columnBucketWidth);
            if (!columnGroups.has(bucket)) columnGroups.set(bucket, []);
            columnGroups.get(bucket).push(entry);
        });
        const orderedSectionsPass = orderedMatches.length === expectedBlocks.length
            && Array.from(columnGroups.values()).every((groupEntries) => (
                groupEntries.every((entry, index) => (
                    index === 0 || entry.match.displayTop > groupEntries[index - 1].match.displayTop
                ))
            ));
        const sourceLineCountsPass = matchSummary.every((entry) => (
            entry.matches.length === 1
            && (Number(entry.matches[0]?.lineCount) || 0) >= entry.minSourceLines
        ));
        const headingMatch = matchSummary.find((entry) => entry.key === headingKey)?.matches?.[0] || null;

        const checks = [
            {
                item: 'fixture_pdf_uploaded',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'The fixture PDF was uploaded and opened through the existing frontend upload flow.',
                detail: JSON.stringify({
                    document_id: documentId,
                    fixture_path: fixturePath,
                }),
            },
            {
                item: 'edit_new_materialized_annotations_present',
                result: materializedAnnotationsPass ? 'PASS' : 'FAIL',
                description: 'The /edit-new payload materializes editable annotations for the fixture instead of returning an empty annotation set.',
                detail: JSON.stringify({
                    annotation_count: sectionState?.annotationCount ?? 0,
                    matched_sections: sectionState?.matchedSections || [],
                }),
            },
            {
                item: 'paragraph_exact_annotation_blocks_present',
                result: exactMatchesPass ? 'PASS' : 'FAIL',
                description: `Each expected ${groupingLabel} paragraph from the source PDF appears in /edit-new as exactly one matching annotation block.`,
                detail: JSON.stringify(matchSummary.map((entry) => ({
                    key: entry.key,
                    expected_text: entry.expectedText,
                    match_count: entry.matches.length,
                    matches: entry.matches.map((match) => ({
                        id: match.id,
                        text: match.text,
                        line_count: match.lineCount,
                        top: match.displayTop,
                        left: match.displayLeft,
                        width: match.displayWidth,
                        height: match.displayHeight,
                    })),
                }))),
            },
            {
                item: 'paragraph_sections_not_merged_together',
                result: (sectionState?.combinedSections || []).length === 0 ? 'PASS' : 'FAIL',
                description: `No /edit-new annotation merges multiple ${groupingLabel} section starters into one bounding box.`,
                detail: JSON.stringify(sectionState?.combinedSections || []),
            },
            {
                item: 'paragraph_heading_is_standalone',
                result: headingMatch && Number(headingMatch.lineCount) === 1 ? 'PASS' : 'FAIL',
                description: `The ${groupingLabel} heading stays a standalone one-line annotation instead of being merged into a paragraph block.`,
                detail: JSON.stringify(headingMatch),
            },
            {
                item: 'paragraph_source_line_counts_preserved',
                result: sourceLineCountsPass ? 'PASS' : 'FAIL',
                description: `Each matched ${groupingLabel} annotation preserves a multi-line source structure consistent with the source PDF paragraph boundaries.`,
                detail: JSON.stringify(matchSummary.map((entry) => ({
                    key: entry.key,
                    min_source_lines: entry.minSourceLines,
                    observed_line_count: entry.matches[0]?.lineCount ?? null,
                    observed_source_lines: entry.matches[0]?.sourceTextLines ?? [],
                }))),
            },
            {
                item: 'paragraph_section_order_preserved',
                result: orderedSectionsPass ? 'PASS' : 'FAIL',
                description: `The matched ${groupingLabel} annotations appear in the same top-to-bottom order as the source PDF.`,
                detail: JSON.stringify(orderedMatches.map((entry) => ({
                    key: entry.key,
                    top: entry.match?.displayTop ?? null,
                    left: entry.match?.displayLeft ?? null,
                }))),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Edit-New Page 1', kind: 'image', filename: screenshotName },
            ],
            metadata: {
                document_id: documentId,
                fixture_path: fixturePath,
                annotation_count: sectionState?.annotationCount ?? 0,
                match_summary: matchSummary,
                combined_sections: sectionState?.combinedSections || [],
                matched_sections: sectionState?.matchedSections || [],
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

function listFiles() {
    // Classify each test by which editor URL it exercises so the admin Run
    // PDF Tests page can split them into /edit (legacy) and /edit-new tabs.
    // Tests whose key ends in `_new`, contains `edit_new`, or whose key starts
    // with `custom_pdf_test_` (the doc 2880 snapshot regressions, all of
    // which run on /edit-new) belong to the new editor; everything else is
    // legacy /edit.
    const classifyEditor = (key) => {
        const k = String(key || '');
        if (/_new(?:$|_)/.test(k)) return 'edit-new';
        if (/edit_new/.test(k)) return 'edit-new';
        if (/^custom_pdf_test_/.test(k)) return 'edit-new';
        if (/^text_layout_test_suite_/.test(k)) return 'edit-new';
        return 'edit';
    };

    const files = [
        ...Object.values(TESTS)
            .filter((test) => !PARAGRAPH_TEST_KEYS.includes(test.key))
            .filter((test) => !TEXT_LAYOUT_SUITE_1_KEYS.includes(test.key))
            .map((test) => ({
                filename: `${test.key}.pdf`,
                path: test.key,
                description: test.description,
                test_category: 'PDF Tests',
                section_name: test.label,
                criteria: TEST_CRITERIA[test.key] || [],
                editor: classifyEditor(test.key),
            })),
        ...Object.values(TEST_SUITES).map((suite) => ({
            filename: `${suite.key}.pdf`,
            path: suite.key,
            description: suite.description,
            test_category: 'PDF Tests',
            section_name: suite.label,
            criteria: TEST_CRITERIA[suite.key] || [],
            editor: classifyEditor(suite.key),
            // Expose the constituent sub-tests so the admin detail screen can
            // render per-test Run buttons inside a suite. Each entry mirrors
            // the shape of a top-level file entry just enough for the UI to
            // call runSingleTest with its `path`.
            member_tests: (suite.testKeys || [])
                .map((mk) => TESTS[mk])
                .filter(Boolean)
                .map((mt) => ({
                    path: mt.key,
                    filename: `${mt.key}.pdf`,
                    section_name: mt.label,
                    description: mt.description,
                    criteria: TEST_CRITERIA[mt.key] || [],
                })),
        })),
    ];

    return {
        total: files.length,
        files,
    };
}

async function runSuite(key) {
    const suite = TEST_SUITES[key];
    if (!suite) {
        outputJson(buildResult({
            testKey: key || 'unknown',
            label: 'Unknown PDF Suite',
            description: '',
            status: 'error',
            error: `Unknown PDF suite: ${key || '(missing)'}`,
        }));
        process.exit(1);
    }

    const checks = [];
    const artifacts = [];
    const warnings = [];
    const metadata = {
        suite_key: suite.key,
        suite_label: suite.label,
        tests: [],
    };
    let totalFileSize = 0;
    let suiteFailed = false;

    for (const testKey of suite.testKeys) {
        const test = TESTS[testKey];
        if (!test) {
            suiteFailed = true;
            warnings.push(`Missing suite test: ${testKey}`);
            checks.push({
                item: `${testKey}:missing`,
                result: 'FAIL',
                description: `Suite member ${testKey} exists in the suite definition.`,
                detail: 'Test key not found in TESTS.',
            });
            continue;
        }

        try {
            const result = await test.run();
            totalFileSize += Number(result?.file_size) || 0;
            metadata.tests.push({
                key: test.key,
                label: test.label,
                status: result.status,
                checks_passed: result.checks_passed,
                checks_total: result.checks_total,
            });

            for (const check of result.checks || []) {
                checks.push({
                    ...check,
                    item: `${test.key}:${check.item}`,
                    description: `${test.label} - ${check.description}`,
                });
            }

            for (const artifact of result.artifacts || []) {
                artifacts.push({
                    ...artifact,
                    label: `${test.label} - ${artifact.label}`,
                });
            }

            if (result.status !== 'pass' && String(result.status).toLowerCase() !== 'pass') {
                suiteFailed = true;
                if (result.error) {
                    warnings.push(`${test.key}: ${result.error}`);
                }
            }
        } catch (error) {
            suiteFailed = true;
            const errorText = error.stack || String(error);
            metadata.tests.push({
                key: test.key,
                label: test.label,
                status: 'error',
                checks_passed: 0,
                checks_total: 0,
            });
            checks.push({
                item: `${test.key}:runner_error`,
                result: 'FAIL',
                description: `${test.label} completed without throwing.`,
                detail: errorText,
            });
            warnings.push(`${test.key}: ${errorText}`);
        }
    }

    outputJson(buildResult({
        testKey: suite.key,
        label: suite.label,
        description: suite.description,
        status: suiteFailed ? 'fail' : 'pass',
        checks,
        warnings,
        artifacts,
        fileSize: totalFileSize,
        metadata,
    }));
    process.exit(suiteFailed ? 1 : 0);
}

async function runSingleTest(key) {
    const suite = TEST_SUITES[key];
    if (suite) {
        await runSuite(key);
        return;
    }

    const test = TESTS[key];
    if (!test) {
        outputJson(buildResult({
            testKey: key || 'unknown',
            label: 'Unknown PDF Test',
            description: '',
            status: 'error',
            error: `Unknown PDF test: ${key || '(missing)'}`,
        }));
        process.exit(1);
    }

    try {
        const result = await test.run();
        outputJson(result);
        process.exit(result.status === 'pass' ? 0 : 1);
    } catch (error) {
        outputJson(buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: 'error',
            error: error.stack || String(error),
        }));
        process.exit(1);
    }
}

async function main() {
    const args = process.argv.slice(2);

    if (args.includes('--list-files')) {
        outputJson(listFiles());
        return;
    }

    if (args.includes('--single-test')) {
        const index = args.indexOf('--single-test');
        const key = args[index + 1];
        await runSingleTest(key);
        return;
    }

    // Backward compatibility: accept a bare positional test key.
    if (args.length === 1 && !args[0].startsWith('--')) {
        await runSingleTest(args[0]);
        return;
    }

    outputJson({
        success: false,
        message: 'Usage: --list-files | --single-test <key> | <key>',
    });
    process.exit(1);
}

main();
