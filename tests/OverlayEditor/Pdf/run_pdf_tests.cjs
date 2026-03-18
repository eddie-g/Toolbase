#!/usr/bin/env node

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1600, height: 1800 };

const POSITION_TEXT = 'This is a test PDF example 1';
const FIRST_MOVE_Y = -250;
const SECOND_MOVE_Y = 250;
const TEXT_POSITION_GROUPING_SCENARIOS = [
    {
        key: 'mixed_long_short_stack',
        label: 'Long / short / short / long-right stack',
        cases: [
            { text: 'Stack Long Alpha lorem ipsum dolor sit amet 01', left: 72, top: 96 },
            { text: 'Stack Short Bravo 02', left: 72, top: 144 },
            { text: 'Stack Short Charlie 03', left: 72, top: 192 },
            { text: 'Stack Long Delta aligned right directly below 04', left: 246, top: 240 },
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
const DOWNLOAD_TOP_RIGHT_TEXT = 'Wolfchenez News';
const DOWNLOAD_TOP_RIGHT_FONT_SIZE_PX = 57;
const DOWNLOAD_TOP_RIGHT_MARGIN_PX = 20;
const DOWNLOAD_TOP_RIGHT_POSITION_TOLERANCE = 18;
const DOWNLOAD_TOP_RIGHT_FONT_SIZE_TOLERANCE = 0.001;
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
const INVOICE_TEST_FIXTURE_PATH = path.resolve(__dirname, '..', '..', '..', 'public', 'invoice_test.pdf');
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
        description: 'Create blank PDFs, save one centered text annotation after moving it 250px up, save a second after moving it back down, and verify mixed long/short line stacks plus five short lines and five long lines all remain in separate saved extraction groups.',
        run: runTextPositionFlow,
    },
    test_2_text_styling: {
        key: 'test_2_text_styling',
        label: 'Test 2 : Text Styling',
        description: 'Create one combined text box, style individual words with different color, font, boldness, and underline, save, and confirm the mixed styling survives in the saved PDF.',
        run: runTextStylingFlow,
    },
    test_18_text_font_size_reload_no_duplicates: {
        key: 'test_18_text_font_size_reload_no_duplicates',
        label: 'Test 18 : Text Font Size Reload No Duplicates',
        description: 'Create one text box with three sentences, increase the middle sentence font size, decrease the last sentence font size, save, reload the editor, and verify the editor does not duplicate the saved text.',
        run: runTextFontSizeReloadNoDuplicatesFlow,
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
    test_4_load_tests: {
        key: 'test_4_load_tests',
        label: 'Test 4 : Load Tests',
        description: 'Upload drylab_full.pdf, load page 1 in the overlay editor, and verify key text retains original word positions, line heights, and styles on initial load.',
        run: runDrylabLoadFlow,
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
        label: 'Test 3 : Paragraphs',
        description: 'Create a lorem ipsum paragraph as a text annotation, resize it from a tall 200px column to 800px wide and back, move it, save through the annotation path, verify an unsaved deleted paragraph does not get stamped, and save three side-by-side 200px by 800px paragraph columns.',
        run: runParagraphFlow,
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

const TEST_SUITES = {
    paragraph_suite: {
        key: 'paragraph_suite',
        label: 'Paragraph Suite',
        description: 'Run all paragraph-related PDF tests as one suite, including paragraph resizing, save/reload integrity, grouping boundaries, position accuracy, delete-save behavior, and indentation preservation.',
        testKeys: PARAGRAPH_TEST_KEYS,
    },
};

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
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

    execFileSync('python3', [
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
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 90000 });
    await page.waitForTimeout(1500);
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

async function createTextAnnotationAtWithStyles(page, text, offsetX, offsetY, styles = {}) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

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

async function moveTextAnnotationTo(page, textFragment, targetLeft, targetTop) {
    const annotation = page.locator('.annotation').filter({ hasText: textFragment }).first();

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

async function readFirstPromotedAnnotationState(page) {
    return page.evaluate(() => {
        const record = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations.find((item) => item?.promotedFromExtraction)
            : null;
        const element = record?.element || document.querySelector('.annotation.promoted-extraction');
        const textElement = element?.querySelector('.annotation-text');

        return {
            id: record?.id || null,
            text: record?.text || textElement?.innerText || '',
            promotedDirty: Boolean(record?.promotedDirty),
            promotedReflowEnabled: Boolean(record?.promotedReflowEnabled),
            exactGeometry: textElement?.dataset?.exactPromotedGeometry || null,
            annotationLineHeight: Number(record?.lineHeight || 0),
            width: element?.offsetWidth || 0,
            height: element?.offsetHeight || 0,
        };
    });
}

async function noOpEditFirstPromotedAnnotation(page) {
    const annotation = page.locator('.annotation.promoted-extraction').first();
    await annotation.waitFor({ timeout: 10000 });
    await annotation.dispatchEvent('dblclick');
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.mouse.click(20, 20);
    await page.waitForTimeout(1200);
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
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && (
            response.url().includes('/apply-annotations-direct')
            || response.url().includes('/save-annotations')
        )
    ), { timeout: 60000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`annotation save failed with ${response.status()}`);
    }

    await page.waitForFunction(() => typeof annotations !== 'undefined' && annotations.length === 0, null, { timeout: 60000 });
    await waitForEditorReady(page);
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

    const raw = execFileSync('python3', ['-c', pythonCode, pdfPath], {
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
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, { timeout: 30000 });
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 30000 });
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
    await pageLocator.screenshot({ path: outputPath });
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

    const raw = execFileSync('python3', ['-c', pythonCode, pdfPath, String(pageNumber)], {
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

    const raw = execFileSync('python3', ['-c', pythonCode, pdfPath, String(pageNumber), JSON.stringify(targetConfigs)], {
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

    const raw = execFileSync('python3', ['-c', pythonCode, pdfPath, String(pageNumber), targetText], {
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

    const raw = execFileSync('python3', ['-c', pythonCode, pdfPath, String(pageNumber), JSON.stringify(targetLineTexts)], {
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
        const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => {
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
        }).filter((entry) => entry.text);

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

            if (!startNode && start >= currentOffset && start <= nextOffset) {
                startNode = node;
                startNodeOffset = start - currentOffset;
            }

            if (!endNode && end >= currentOffset && end <= nextOffset) {
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
            dispatchValue(document.getElementById('etb-font'), inputStyles.fontFamily, 'change');
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
        };
    }, targetText);
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

async function runTextPositionFlow() {
    const test = TESTS.test_1_text_position;
    ensureOutputDir();

    const runToken = buildRunToken();
    const firstScreenshotName = buildArtifactName(test.key, runToken, 'after_first_save');
    const secondScreenshotName = buildArtifactName(test.key, runToken, 'after_second_save');
    const secondPdfName = buildArtifactName(test.key, runToken, 'after_second_save', 'pdf');
    const firstScreenshotPath = path.join(OUTPUT_DIR, firstScreenshotName);
    const secondScreenshotPath = path.join(OUTPUT_DIR, secondScreenshotName);
    const secondPdfPath = path.join(OUTPUT_DIR, secondPdfName);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    let firstDocumentId = null;
    let secondDocumentId = null;
    const groupingDocumentIds = [];
    const groupingScenarioResults = [];

    try {
        firstDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, firstDocumentId);

        await createCenterTextAnnotation(page, POSITION_TEXT);
        const firstStartMetrics = await readTextAnnotationMetrics(page, POSITION_TEXT);
        await updateTextAnnotation(page, POSITION_TEXT, {
            top: Math.round(firstStartMetrics.top + FIRST_MOVE_Y),
        });
        await waitForAnnotationSave(page);

        await forceRefreshOverlay(page, firstDocumentId);
        const firstExtraction = await fetchExtraction(page, firstDocumentId);
        const firstMatches = extractMatchingBBoxes(firstExtraction, POSITION_TEXT);
        if (firstMatches.length !== 1) {
            throw new Error(`expected 1 first-save text match, found ${firstMatches.length}`);
        }
        const firstBBox = firstMatches[0];
        await capturePageScreenshot(page, firstScreenshotPath);

        secondDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, secondDocumentId);

        await createCenterTextAnnotation(page, POSITION_TEXT);
        const secondStartMetrics = await readTextAnnotationMetrics(page, POSITION_TEXT);
        const secondMoveUpState = await updateTextAnnotation(page, POSITION_TEXT, {
            top: Math.round(secondStartMetrics.top + FIRST_MOVE_Y),
        });
        const secondMoveDownState = await updateTextAnnotation(page, POSITION_TEXT, {
            top: Math.round(secondMoveUpState.top + SECOND_MOVE_Y),
        });

        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, secondDocumentId, secondPdfPath);
        await forceRefreshOverlay(page, secondDocumentId);
        const secondExtraction = await fetchExtraction(page, secondDocumentId);
        const secondMatches = extractMatchingBBoxes(secondExtraction, POSITION_TEXT);
        if (secondMatches.length !== 1) {
            throw new Error(`expected 1 final text match, found ${secondMatches.length}`);
        }
        const secondBBox = secondMatches[0];
        await capturePageScreenshot(page, secondScreenshotPath);

        const firstCenter = bboxCenter(firstBBox);
        const secondCenter = bboxCenter(secondBBox);
        const savedDelta = secondCenter.y - firstCenter.y;
        const browserDelta = SECOND_MOVE_Y;

        for (const scenario of TEXT_POSITION_GROUPING_SCENARIOS) {
            const scenarioDocumentId = await createBlankDocument(page);
            groupingDocumentIds.push(scenarioDocumentId);
            await waitForEditorReady(page);
            await clearAnnotationSessionState(page, scenarioDocumentId);

            for (const scenarioCase of scenario.cases) {
                await createTextAnnotationAt(page, scenarioCase.text, scenarioCase.left, scenarioCase.top);
            }

            await waitForAnnotationSave(page);
            await forceRefreshOverlay(page, scenarioDocumentId);
            const scenarioExtraction = await fetchExtraction(page, scenarioDocumentId);
            groupingScenarioResults.push({
                key: scenario.key,
                label: scenario.label,
                documentId: scenarioDocumentId,
                analysis: analyzeIndependentTextGrouping(scenarioExtraction, scenario.cases),
            });
        }

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'Fresh blank PDFs were created through the frontend blank-PDF flow.',
                detail: `documents=${firstDocumentId},${secondDocumentId}`,
            },
            {
                item: 'first_save_visible',
                result: firstMatches.length === 1 ? 'PASS' : 'FAIL',
                description: 'The first annotation save produced one visible text line in the blank PDF.',
                detail: `bbox=${JSON.stringify(firstBBox)}`,
            },
            {
                item: 'second_save_visible',
                result: secondMatches.length === 1 ? 'PASS' : 'FAIL',
                description: 'The second annotation save preserved one visible text line after the browser move.',
                detail: `bbox=${JSON.stringify(secondBBox)}`,
            },
            {
                item: 'second_move_browser_delta',
                result: browserDelta >= 220 && browserDelta <= 280 ? 'PASS' : 'FAIL',
                description: 'The annotation move request asked for about 250px downward before the second save.',
                detail: `browser_delta_y=${browserDelta.toFixed(2)}`,
            },
            {
                item: 'second_move_saved_delta',
                result: savedDelta >= 100 ? 'PASS' : 'FAIL',
                description: 'The saved PDF moved the line materially downward between the two annotation-only saves.',
                detail: `saved_delta_y=${savedDelta.toFixed(2)} first_center=${firstCenter.y.toFixed(2)} second_center=${secondCenter.y.toFixed(2)}`,
            },
            ...groupingScenarioResults.map((scenarioResult) => {
                const caseFailures = scenarioResult.analysis.matchesByCase.filter((entry) => entry.matchCount !== 1);
                const groupedBlocks = scenarioResult.analysis.groupedBlocks;
                return {
                    item: `text_grouping_${scenarioResult.key}`,
                    result: caseFailures.length === 0 && groupedBlocks.length === 0 ? 'PASS' : 'FAIL',
                    description: `${scenarioResult.label} saves as one raw extraction group per line, with no grouped multi-line block.`,
                    detail: JSON.stringify({
                        document_id: scenarioResult.documentId,
                        case_failures: caseFailures,
                        grouped_blocks: groupedBlocks,
                    }),
                };
            }),
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const secondPdfStats = fs.statSync(secondPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: secondPdfStats.size,
            artifacts: [
                {
                    label: 'After First Save',
                    kind: 'image',
                    filename: firstScreenshotName,
                },
                {
                    label: 'After Second Save',
                    kind: 'image',
                    filename: secondScreenshotName,
                },
                {
                    label: 'Final PDF',
                    kind: 'pdf',
                    filename: secondPdfName,
                },
            ],
            metadata: {
                document_ids: [firstDocumentId, secondDocumentId, ...groupingDocumentIds],
                target_text: POSITION_TEXT,
                first_bbox: firstBBox,
                second_bbox: secondBBox,
                browser_second_move_delta_y: browserDelta,
                second_move_delta_y: savedDelta,
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
    const test = TESTS.test_2_text_styling;
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
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        const editor = await openCenterTextCreator(page);
        await editor.fill(STYLE_TEXT);

        const colorSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.color.text);
        const colorEditorState = await applyEditTextBannerStyles(page, {
            textColor: STYLE_SEGMENTS.color.textColor,
        });

        const fontSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.font.text);
        const fontEditorState = await applyEditTextBannerStyles(page, {
            fontFamily: STYLE_SEGMENTS.font.fontFamily,
        });

        const boldSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.bold.text);
        const boldEditorState = await applyEditTextBannerStyles(page, {
            bold: STYLE_SEGMENTS.bold.bold,
        });

        const underlineSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.underline.text);
        const underlineEditorState = await applyEditTextBannerStyles(page, {
            underline: STYLE_SEGMENTS.underline.underline,
        });

        await commitActiveTextBox(page);
        const annotation = page.locator('.annotation').filter({ hasText: STYLE_TEXT }).first();
        await annotation.waitFor({ timeout: 10000 });
        await annotation.click();
        await waitForSelectionToolbarSelection(page, 'annotation');

        const beforeSaveAnnotationState = await readCommittedAnnotationRichState(page, STYLE_TEXT);
        await page.waitForTimeout(500);
        const beforeSaveToolbarState = await readSelectionToolbarState(page);
        await capturePageScreenshot(page, beforeSavePath);

        await waitForAnnotationSave(page);
        await capturePageScreenshot(page, afterSavePath);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        await forceRefreshOverlay(page, documentId);
        const savedExtraction = await fetchExtraction(page, documentId);
        const savedLine = extractMatchingLine(savedExtraction, STYLE_TEXT);
        const savedWords = extractWordsForLine(savedExtraction, savedLine);
        const savedColorWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.color.text);
        const savedFontWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.font.text);
        const savedBoldWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.bold.text);
        const savedUnderlineWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.underline.text);

        const expectedColor = normalizeHex(STYLE_SEGMENTS.color.textColor);
        const serializedAnnotationHtml = String(beforeSaveAnnotationState.richTextHtml || beforeSaveAnnotationState.html || '');
        const savedColor = normalizeHex(savedColorWord?.hex_color || savedColorWord?.color);
        const savedFontName = String(savedFontWord?.font || '');
        const boldDetected = Boolean(savedBoldWord?.bold)
            || parseInt(savedBoldWord?.font_weight || '0', 10) >= 600;
        const underlineDetected = Boolean(savedUnderlineWord?.has_drawn_underline);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'editor_mixed_styles_applied_before_save',
                result: (
                    serializedAnnotationHtml.includes('color:#c62828')
                    && /(times new roman|garamond|baskerville)/i.test(serializedAnnotationHtml)
                    && serializedAnnotationHtml.includes('font-weight:700')
                    && serializedAnnotationHtml.includes('text-decoration:underline')
                ) ? 'PASS' : 'FAIL',
                description: 'The combined text box contained mixed rich-text spans for color, font, boldness, and underline before save.',
                detail: JSON.stringify({
                    selections: {
                        color: colorSelection,
                        font: fontSelection,
                        bold: boldSelection,
                        underline: underlineSelection,
                    },
                    editor_states: {
                        color: colorEditorState,
                        font: fontEditorState,
                        bold: boldEditorState,
                        underline: underlineEditorState,
                    },
                    annotation_state: beforeSaveAnnotationState,
                    before_save: beforeSaveToolbarState,
                }),
            },
            {
                item: 'saved_line_visible',
                result: savedLine ? 'PASS' : 'FAIL',
                description: 'The saved PDF exposes the combined styled text as one extracted line.',
                detail: savedLine ? JSON.stringify({
                    text: savedLine.text,
                    font: savedLine.font,
                    font_size: savedLine.font_size,
                    hex_color: savedLine.hex_color,
                }) : 'no matching saved line',
            },
            {
                item: 'saved_color_word_matches',
                result: savedColorWord && savedColor === expectedColor ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps the requested color on the color-specific word.',
                detail: JSON.stringify({
                    word: savedColorWord?.text || null,
                    saved_color: savedColor,
                    expected_color: expectedColor,
                }),
            },
            {
                item: 'saved_font_word_matches',
                result: savedFontWord && STYLE_SEGMENTS.font.fontRegex.test(savedFontName) ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps the requested alternate font on the font-specific word.',
                detail: JSON.stringify({
                    word: savedFontWord?.text || null,
                    saved_font: savedFontName,
                    expected_regex: String(STYLE_SEGMENTS.font.fontRegex),
                }),
            },
            {
                item: 'saved_bold_word_matches',
                result: savedBoldWord && boldDetected === true ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps bold styling on the bold-specific word.',
                detail: JSON.stringify({
                    word: savedBoldWord?.text || null,
                    saved_bold: Boolean(savedBoldWord?.bold),
                    saved_font_weight: savedBoldWord?.font_weight || null,
                }),
            },
            {
                item: 'saved_underline_word_matches',
                result: savedUnderlineWord && underlineDetected === true ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps underline styling on the underline-specific word.',
                detail: JSON.stringify({
                    word: savedUnderlineWord?.text || null,
                    saved_underline: underlineDetected,
                }),
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
                target_text: STYLE_TEXT,
                expected_style_segments: {
                    ...STYLE_SEGMENTS,
                    font: {
                        ...STYLE_SEGMENTS.font,
                        fontRegex: String(STYLE_SEGMENTS.font.fontRegex),
                    },
                },
                before_save_annotation_state: beforeSaveAnnotationState,
                before_save_toolbar_state: beforeSaveToolbarState,
                extracted_line: savedLine,
                extracted_words: savedWords,
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

async function runTextFontSizeReloadNoDuplicatesFlow() {
    const test = TESTS.test_18_text_font_size_reload_no_duplicates;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const reloadName = buildArtifactName(test.key, runToken, 'after_reload');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const reloadPath = path.join(OUTPUT_DIR, reloadName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        const editor = await openCenterTextCreator(page);
        await editor.fill(FONT_SIZE_DUPLICATE_TEXT);

        const largeSelection = await selectActiveEditorText(page, FONT_SIZE_DUPLICATE_SENTENCES.large);
        const largeEditorState = await applyEditTextBannerStyles(page, {
            fontSize: FONT_SIZE_DUPLICATE_LARGE_PX,
        });

        const smallSelection = await selectActiveEditorText(page, FONT_SIZE_DUPLICATE_SENTENCES.small);
        const smallEditorState = await applyEditTextBannerStyles(page, {
            fontSize: FONT_SIZE_DUPLICATE_SMALL_PX,
        });

        await commitActiveTextBox(page);
        const annotation = page.locator('.annotation').filter({ hasText: FONT_SIZE_DUPLICATE_SENTENCES.regular }).first();
        await annotation.waitFor({ timeout: 10000 });
        await annotation.click();
        await waitForSelectionToolbarSelection(page, 'annotation');

        const beforeSaveAnnotationState = await readCommittedAnnotationRichState(page, FONT_SIZE_DUPLICATE_TEXT);
        const beforeSaveToolbarState = await readSelectionToolbarState(page);
        await capturePageScreenshot(page, beforeSavePath);

        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        const savedExtraction = await fetchExtraction(page, documentId);
        const savedBlock = extractMatchingBlock(savedExtraction, FONT_SIZE_DUPLICATE_SENTENCES.regular);

        await clearAnnotationSessionState(page, documentId);
        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await page.waitForFunction((fragments) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const annotationEls = Array.from(document.querySelectorAll('.annotation'));
            return annotationEls.some((annotationEl) => {
                const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
                const text = normalizeText(textEl?.innerText || textEl?.textContent || '');
                return fragments.every((fragment) => text.includes(normalizeText(fragment)));
            });
        }, Object.values(FONT_SIZE_DUPLICATE_SENTENCES), { timeout: 60000 });

        const reloadedAnnotations = await page.evaluate((fragments) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            return Array.from(document.querySelectorAll('.annotation')).map((annotationEl) => {
                const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
                const text = normalizeText(textEl?.innerText || textEl?.textContent || '');
                return {
                    className: annotationEl.className,
                    text,
                    left: annotationEl.offsetLeft,
                    top: annotationEl.offsetTop,
                    width: annotationEl.offsetWidth,
                    height: annotationEl.offsetHeight,
                    matchingFragments: fragments.filter((fragment) => text.includes(normalizeText(fragment))),
                };
            }).filter((annotation) => annotation.text);
        }, Object.values(FONT_SIZE_DUPLICATE_SENTENCES));
        await capturePageScreenshot(page, reloadPath);

        const matchingAnnotations = reloadedAnnotations.filter((annotationEntry) => normalize(annotationEntry.text) === normalize(FONT_SIZE_DUPLICATE_TEXT));
        const fragmentCarriers = reloadedAnnotations.filter((annotationEntry) => (
            Object.values(FONT_SIZE_DUPLICATE_SENTENCES).some((sentence) => normalize(annotationEntry.text).includes(normalize(sentence)))
        ));
        const promotedDuplicates = fragmentCarriers.filter((annotationEntry) => annotationEntry.className.includes('promoted-extraction'));
        const primaryAnnotation = matchingAnnotations[0] || null;
        const fragmentOccurrences = Object.fromEntries(
            Object.entries(FONT_SIZE_DUPLICATE_SENTENCES).map(([key, sentence]) => [
                key,
                countNormalizedOccurrences(primaryAnnotation?.text || '', sentence),
            ])
        );
        const savedBlockOccurrences = Object.fromEntries(
            Object.entries(FONT_SIZE_DUPLICATE_SENTENCES).map(([key, sentence]) => [
                key,
                countNormalizedOccurrences(savedBlock?.text || '', sentence),
            ])
        );
        const serializedAnnotationHtml = String(beforeSaveAnnotationState.richTextHtml || beforeSaveAnnotationState.html || '');

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'editor_font_sizes_applied_before_save',
                result: serializedAnnotationHtml.includes(`font-size:${FONT_SIZE_DUPLICATE_LARGE_PX}px`)
                    && serializedAnnotationHtml.includes(`font-size:${FONT_SIZE_DUPLICATE_SMALL_PX}px`)
                    ? 'PASS' : 'FAIL',
                description: 'The text box contained explicit larger and smaller font-size spans before save.',
                detail: JSON.stringify({
                    selections: {
                        large: largeSelection,
                        small: smallSelection,
                    },
                    editor_states: {
                        large: largeEditorState,
                        small: smallEditorState,
                    },
                    annotation_state: beforeSaveAnnotationState,
                    toolbar_state: beforeSaveToolbarState,
                }),
            },
            {
                item: 'saved_pdf_contains_each_sentence_once',
                result: savedBlock
                    && Object.values(savedBlockOccurrences).every((count) => count === 1)
                    ? 'PASS' : 'FAIL',
                description: 'The saved PDF extraction still contains each sentence exactly once.',
                detail: JSON.stringify({
                    saved_block: savedBlock,
                    sentence_occurrences: savedBlockOccurrences,
                }),
            },
            {
                item: 'reloaded_editor_has_single_matching_annotation',
                result: matchingAnnotations.length === 1 ? 'PASS' : 'FAIL',
                description: 'Reloading the editor produces exactly one visible annotation for the saved mixed-size text box.',
                detail: JSON.stringify({
                    matching_annotation_count: matchingAnnotations.length,
                    matching_annotations: matchingAnnotations,
                }),
            },
            {
                item: 'reloaded_editor_has_no_promoted_duplicate',
                result: promotedDuplicates.length === 0 ? 'PASS' : 'FAIL',
                description: 'Reloading the editor does not add a promoted-extraction duplicate on top of the saved annotation.',
                detail: JSON.stringify({
                    promoted_duplicates: promotedDuplicates,
                    fragment_carriers: fragmentCarriers,
                }),
            },
            {
                item: 'reloaded_editor_text_not_duplicated',
                result: primaryAnnotation
                    && Object.values(fragmentOccurrences).every((count) => count === 1)
                    && fragmentCarriers.length === 1
                    ? 'PASS' : 'FAIL',
                description: 'After reload, the editor text contains one copy of each sentence instead of duplicated content.',
                detail: JSON.stringify({
                    primary_annotation: primaryAnnotation,
                    fragment_occurrences: fragmentOccurrences,
                    fragment_carriers: fragmentCarriers,
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
                { label: 'Before Save', kind: 'image', filename: beforeSaveName },
                { label: 'After Reload', kind: 'image', filename: reloadName },
                { label: 'Final PDF', kind: 'pdf', filename: finalPdfName },
            ],
            fileSize: fs.existsSync(finalPdfPath) ? fs.statSync(finalPdfPath).size : 0,
            metadata: {
                document_id: documentId,
                target_text: FONT_SIZE_DUPLICATE_TEXT,
                before_save_annotation_state: beforeSaveAnnotationState,
                before_save_toolbar_state: beforeSaveToolbarState,
                saved_block: savedBlock,
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
    const test = TESTS.test_4_load_tests;
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

function listFiles() {
    const files = [
        ...Object.values(TESTS)
            .filter((test) => !PARAGRAPH_TEST_KEYS.includes(test.key))
            .map((test) => ({
                filename: `${test.key}.pdf`,
                path: test.key,
                description: test.description,
                test_category: 'PDF Tests',
                section_name: test.label,
            })),
        ...Object.values(TEST_SUITES).map((suite) => ({
            filename: `${suite.key}.pdf`,
            path: suite.key,
            description: suite.description,
            test_category: 'PDF Tests',
            section_name: suite.label,
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

            if (result.status !== 'pass') {
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
