/*
 * Text resolver for extracted PDF spans.
 *
 * Some PDFs encode normal words with custom character positioning. PyMuPDF can
 * expose those as render_text/text with artificial spaces inserted between
 * glyphs, while the underlying content op still carries the clean string. Use
 * the clean op text when it is the same text modulo whitespace.
 */

const compactText = (value) => String(value ?? '').replace(/\s+/g, '');

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
