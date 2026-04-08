#!/usr/bin/env node

'use strict';

function sanitizeLine(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function getPromotedMeaningfulBlockLines(block) {
    return Array.isArray(block?.text_lines)
        ? block.text_lines.map((line) => sanitizeLine(line)).filter((line) => line.length > 0)
        : [];
}

function promotedBlockLooksLikeDecorativeCalloutSymbol(block) {
    const lines = getPromotedMeaningfulBlockLines(block);
    if (lines.length !== 1) {
        return false;
    }

    const text = String(lines[0] || '').replace(/\s+/g, '');
    if (!/^(?:!|¡|‼|▲|△|▼|▽|◆|◇|■|□|●|○|◦|▪|▶|►)$/.test(text)) {
        return false;
    }

    const width = Math.max(0, Number(block?.width) || 0);
    const height = Math.max(0, Number(block?.height) || 0);
    return width > 0
        && height > 0
        && width <= 42
        && height <= 60;
}

function promotedBlockLooksLikeCompactCalloutCaption(block) {
    const lines = getPromotedMeaningfulBlockLines(block);
    if (lines.length !== 1) {
        return false;
    }

    const text = String(lines[0] || '').trim();
    if (!/^[A-Z][A-Z0-9/& -]{2,14}$/.test(text)) {
        return false;
    }

    const width = Math.max(0, Number(block?.width) || 0);
    const height = Math.max(0, Number(block?.height) || 0);
    return width > 0
        && height > 0
        && width <= 80
        && height <= 20;
}

function blockBelongsToDecorativeCalloutIconCluster(block, pageBlocks) {
    if (!block || typeof block !== 'object') {
        return false;
    }

    const blockIsSymbol = promotedBlockLooksLikeDecorativeCalloutSymbol(block);
    const blockIsCaption = promotedBlockLooksLikeCompactCalloutCaption(block);
    if (!blockIsSymbol && !blockIsCaption) {
        return false;
    }

    const blockLeft = Number(block?.left) || 0;
    const blockTop = Number(block?.top) || 0;
    const blockWidth = Math.max(0, Number(block?.width) || 0);
    const blockHeight = Math.max(0, Number(block?.height) || 0);
    const blockRight = blockLeft + blockWidth;
    const blockBottom = blockTop + blockHeight;
    const blockCenterX = blockLeft + (blockWidth / 2);

    let sawSymbol = blockIsSymbol;
    let sawCaption = blockIsCaption;

    (Array.isArray(pageBlocks) ? pageBlocks : []).forEach((otherBlock) => {
        if (!otherBlock || otherBlock === block) {
            return;
        }

        const otherIsSymbol = promotedBlockLooksLikeDecorativeCalloutSymbol(otherBlock);
        const otherIsCaption = promotedBlockLooksLikeCompactCalloutCaption(otherBlock);
        if (!otherIsSymbol && !otherIsCaption) {
            return;
        }

        const otherLeft = Number(otherBlock?.left) || 0;
        const otherTop = Number(otherBlock?.top) || 0;
        const otherWidth = Math.max(0, Number(otherBlock?.width) || 0);
        const otherHeight = Math.max(0, Number(otherBlock?.height) || 0);
        const otherRight = otherLeft + otherWidth;
        const otherBottom = otherTop + otherHeight;
        const otherCenterX = otherLeft + (otherWidth / 2);

        const horizontalOverlap = Math.max(0, Math.min(blockRight, otherRight) - Math.max(blockLeft, otherLeft));
        const minWidth = Math.max(1, Math.min(blockWidth, otherWidth));
        const overlapRatio = horizontalOverlap / minWidth;
        const sameNarrowColumn = overlapRatio >= 0.55
            || Math.abs(blockCenterX - otherCenterX) <= 12;
        if (!sameNarrowColumn) {
            return;
        }

        const verticalGap = Math.max(
            0,
            Math.max(otherTop - blockBottom, blockTop - otherBottom)
        );
        if (verticalGap > 12) {
            return;
        }

        sawSymbol = sawSymbol || otherIsSymbol;
        sawCaption = sawCaption || otherIsCaption;
    });

    return sawSymbol && sawCaption;
}

const cautionTriangle = {
    block_num: 6,
    text_lines: ['▲'],
    left: 37.76599884033203,
    top: 115.19983673095705,
    width: 32.46880340576172,
    height: 36.400001525878906,
};

const cautionBang = {
    block_num: 14,
    text_lines: ['!'],
    left: 50.47999954223633,
    top: 126.23627471923828,
    width: 7.040000915527344,
    height: 21.99999237060547,
};

const cautionCaption = {
    block_num: 7,
    text_lines: ['CAUTION'],
    left: 38.41299819946289,
    top: 147.91258239746094,
    width: 31.174007415771484,
    height: 6.5,
};

const nearbyParagraph = {
    block_num: 22,
    text_lines: ['You can’t take both an education credit from Form 8863 and the tuition and fees deduction from this form for the'],
    left: 81,
    top: 142.6065673828125,
    width: 497.55609130859375,
    height: 9.000152587890625,
};

const pageBlocks = [cautionTriangle, cautionBang, cautionCaption, nearbyParagraph];

const assertions = [
    {
        ok: promotedBlockLooksLikeDecorativeCalloutSymbol(cautionTriangle),
        message: 'triangle block should be recognized as a decorative callout symbol',
    },
    {
        ok: promotedBlockLooksLikeDecorativeCalloutSymbol(cautionBang),
        message: 'exclamation block should be recognized as a decorative callout symbol',
    },
    {
        ok: promotedBlockLooksLikeCompactCalloutCaption(cautionCaption),
        message: 'CAUTION block should be recognized as a compact callout caption',
    },
    {
        ok: blockBelongsToDecorativeCalloutIconCluster(cautionTriangle, pageBlocks),
        message: 'triangle block should be suppressed as part of a decorative callout icon cluster',
    },
    {
        ok: blockBelongsToDecorativeCalloutIconCluster(cautionBang, pageBlocks),
        message: 'exclamation block should be suppressed as part of a decorative callout icon cluster',
    },
    {
        ok: blockBelongsToDecorativeCalloutIconCluster(cautionCaption, pageBlocks),
        message: 'CAUTION block should be suppressed as part of a decorative callout icon cluster',
    },
    {
        ok: !blockBelongsToDecorativeCalloutIconCluster(nearbyParagraph, pageBlocks),
        message: 'nearby paragraph text must not be suppressed',
    },
];

const failed = assertions.filter((assertion) => !assertion.ok);
if (failed.length > 0) {
    failed.forEach((assertion) => {
        console.error(`FAIL: ${assertion.message}`);
    });
    process.exit(1);
}

console.log('doc2792 caution icon grouping regression passed');
console.log(JSON.stringify({
    suppressed_block_nums: pageBlocks
        .filter((block) => blockBelongsToDecorativeCalloutIconCluster(block, pageBlocks))
        .map((block) => block.block_num),
    preserved_block_nums: pageBlocks
        .filter((block) => !blockBelongsToDecorativeCalloutIconCluster(block, pageBlocks))
        .map((block) => block.block_num),
}, null, 2));
