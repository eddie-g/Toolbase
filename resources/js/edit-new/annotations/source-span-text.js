/*
 * Text resolver for extracted PDF spans.
 *
 * Some PDFs encode normal words with custom character positioning. PyMuPDF can
 * expose those as render_text/text with artificial spaces inserted between
 * glyphs, while the underlying content op still carries the clean string. Use
 * the clean op text when it is the same text modulo whitespace.
 */

const compactText = (value) => String(value ?? '').replace(/\s+/g, '');

function inferredFontWeightFromName(span) {
    const name = [
        span?.embedded_font_name,
        span?.font,
        span?.fontFamily,
    ].map((value) => String(value || '')).join(' ');

    if (/\b(?:Blk|Black|Heavy|Ultra)\b|(?:-|_)(?:Blk|Black|Heavy|Ultra)/i.test(name)) return '900';
    if (/\b(?:Bold|Bd)\b|(?:-|_)(?:Bold|Bd)/i.test(name)) return '700';
    if (/\b(?:Demi|Dem|SemiBold|Semibold)\b|(?:-|_)(?:Demi|Dem|SemiBold|Semibold)/i.test(name)) return '600';
    if (/\bMedium\b|(?:-|_)Medium/i.test(name)) return '500';
    return '';
}

export function sourceSpanText(span) {
    const rendered = String(span?.render_text ?? span?.text ?? '');
    if (!rendered) return '';

    const ops = Array.isArray(span?.source_content_ops) ? span.source_content_ops : [];
    const operatorText = ops
        .map((op) => String(op?.operator_text ?? ''))
        .filter((text) => text.length > 0)
        .join('');

    if (
        operatorText
        && compactText(operatorText) === compactText(rendered)
        && operatorText.replace(/\s+/g, ' ').trim().length < rendered.replace(/\s+/g, ' ').trim().length
    ) {
        return operatorText;
    }

    return rendered;
}

export function sourceSpanFontWeight(span, fallback = '400') {
    const declared = String(span?.font_weight || span?.fontWeight || (span?.bold ? '700' : '') || fallback || '400');
    const inferred = inferredFontWeightFromName(span);
    const declaredWeight = Number.parseInt(declared, 10);
    const inferredWeight = Number.parseInt(inferred, 10);
    if (Number.isFinite(inferredWeight) && inferredWeight > 400 && (!Number.isFinite(declaredWeight) || declaredWeight <= 400)) {
        return inferred;
    }
    return declared;
}
