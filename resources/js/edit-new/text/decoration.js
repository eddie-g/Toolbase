/*
 * text/decoration (NK_7).
 *
 * Underline and strikeout are two tokens of a single CSS property. Any caller
 * that writes `text-decoration` wholesale silently drops whichever token it did
 * not know about — which is exactly how adding strikeout would have broken
 * underline. Every read and write goes through these helpers instead, so the
 * two stay independent.
 */

/** The CSS `text-decoration-line` value for a pair of flags. */
export function composeTextDecorationLine(underline, strikeout) {
    const tokens = [];
    if (underline) tokens.push('underline');
    if (strikeout) tokens.push('line-through');
    return tokens.length ? tokens.join(' ') : 'none';
}

/** Which tokens a CSS decoration value carries. */
export function decorationTokensFromValue(value) {
    const text = String(value || '').toLowerCase();
    return {
        underline: text.includes('underline'),
        strikeout: text.includes('line-through'),
    };
}

/** The decoration an annotation's own flags ask for. */
export function annotationTextDecorationLine(annotation) {
    return composeTextDecorationLine(
        Boolean(annotation?.underline),
        Boolean(annotation?.strikeout),
    );
}
