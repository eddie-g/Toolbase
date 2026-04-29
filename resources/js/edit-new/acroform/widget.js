/*
 * normalizeAcroWidget — turn a raw pdf.js widget annotation plus the
 * loaded acroFieldLookup entry into the editor's normalized widget
 * shape (Phase 7w). Filters out non-interactive push buttons and
 * signature fields.
 */

import { acroFieldLookup } from '../store/acro-state.js';
import {
    acroFieldKey,
    normalizeAcroRect,
    normalizeAcroTextColor,
} from './normalize.js';

export function normalizeAcroWidget(annotation, pageNumber) {
    const fieldKey = acroFieldKey(annotation);
    const dbEntry = acroFieldLookup[fieldKey] || null;
    const rect = normalizeAcroRect(dbEntry?.rect || annotation?.rect);
    if (!rect) return null;

    const [x0, y0, x1, y1] = rect;
    const left = Math.min(x0, x1);
    const right = Math.max(x0, x1);
    const bottom = Math.min(y0, y1);
    const top = Math.max(y0, y1);
    const fieldType = String(dbEntry?.fieldType || annotation?.fieldType || '').trim().toUpperCase();
    const checkBox = Boolean(dbEntry?.checkBox ?? annotation?.checkBox);
    const radioButton = Boolean(dbEntry?.radioButton ?? annotation?.radioButton);

    // Ignore non-interactive push buttons such as hyperlink widgets. edit-new should
    // only expose fillable fields: text, choice, and checkbox/radio buttons.
    if (fieldType === 'BTN' && !checkBox && !radioButton) return null;
    if (fieldType === 'SIG') return null;

    return {
        key: fieldKey || `acro-${pageNumber}-${Math.random().toString(36).slice(2)}`,
        pageIndex: pageNumber - 1,
        fieldName: String(dbEntry?.fieldName || annotation?.fieldName || fieldKey || '').trim(),
        fieldType,
        value: dbEntry?.value ?? annotation?.fieldValue ?? '',
        exportValue: String(dbEntry?.exportValue || annotation?.exportValue || '').trim(),
        checkBox,
        radioButton,
        combo: Boolean(dbEntry?.combo ?? annotation?.combo),
        multiLine: Boolean(dbEntry?.multiLine ?? annotation?.multiLine),
        multiSelect: Boolean(dbEntry?.multiSelect ?? annotation?.multiSelect),
        readOnly: Boolean(annotation?.readOnly),
        textColor: normalizeAcroTextColor(
            dbEntry?.textColor
            ?? annotation?.textColor
            ?? annotation?.fontColor
            ?? annotation?.color
            ?? annotation?.defaultAppearanceData?.fontColor
        ) || '#0f172a',
        rect: [left, bottom, right, top],
    };
}
