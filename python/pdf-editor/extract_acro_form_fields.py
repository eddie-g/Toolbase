#!/usr/bin/env python3
import json
import sys

import fitz


def normalize_field_type(widget) -> str:
    field_type = getattr(widget, "field_type", None)
    field_type_string = str(getattr(widget, "field_type_string", "") or "").strip().lower()

    if field_type == fitz.PDF_WIDGET_TYPE_TEXT or field_type_string == "text":
        return "TX"
    if field_type in (fitz.PDF_WIDGET_TYPE_COMBOBOX, fitz.PDF_WIDGET_TYPE_LISTBOX):
        return "CH"
    if field_type in (
        fitz.PDF_WIDGET_TYPE_BUTTON,
        fitz.PDF_WIDGET_TYPE_CHECKBOX,
        fitz.PDF_WIDGET_TYPE_RADIOBUTTON,
    ):
        return "BTN"
    if field_type == fitz.PDF_WIDGET_TYPE_SIGNATURE or field_type_string == "signature":
        return "SIG"
    return (field_type_string or "UNKNOWN").upper()


def normalize_value(widget, field_type: str):
    raw_value = getattr(widget, "field_value", None)
    if field_type == "BTN":
        if getattr(widget, "field_type", None) == fitz.PDF_WIDGET_TYPE_CHECKBOX:
            return bool(raw_value not in (None, "", "Off", "False", "false", False, 0, "0"))
        return "" if raw_value is None else str(raw_value)
    if field_type == "CH":
        choice_values = getattr(widget, "choice_values", None)
        if isinstance(raw_value, (list, tuple)):
            return [str(value or "") for value in raw_value]
        if isinstance(choice_values, (list, tuple)) and len(choice_values) and isinstance(raw_value, str) and raw_value in choice_values:
            return str(raw_value)
        return "" if raw_value is None else str(raw_value)
    return "" if raw_value is None else str(raw_value)


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: extract_acro_form_fields.py <pdf_path>", file=sys.stderr)
        return 1

    pdf_path = sys.argv[1]
    doc = fitz.open(pdf_path)
    fields_by_key = {}

    for page_index in range(len(doc)):
        page = doc[page_index]
        widgets = list(page.widgets() or [])
        for widget in widgets:
            field_name = str(getattr(widget, "field_name", "") or "").strip()
            if not field_name:
                continue

            field_type = normalize_field_type(widget)
            is_checkbox = getattr(widget, "field_type", None) == fitz.PDF_WIDGET_TYPE_CHECKBOX
            is_radio = getattr(widget, "field_type", None) == fitz.PDF_WIDGET_TYPE_RADIOBUTTON
            is_combo = getattr(widget, "field_type", None) == fitz.PDF_WIDGET_TYPE_COMBOBOX
            is_multiselect = isinstance(getattr(widget, "choice_values", None), (list, tuple)) and field_type == "CH"
            export_value = ""
            try:
                button_states = widget.button_states() if hasattr(widget, "button_states") else None
            except Exception:
                button_states = None
            if isinstance(button_states, dict):
                normal_states = button_states.get("normal") or []
                export_value = next((str(state) for state in normal_states if str(state) != "Off"), "")
            elif isinstance(button_states, (list, tuple)):
                export_value = next((str(state) for state in button_states if str(state) != "Off"), "")

            field_entry = {
                "key": field_name,
                "fieldName": field_name,
                "pageIndex": page_index,
                "fieldType": field_type,
                "checkBox": bool(is_checkbox),
                "radioButton": bool(is_radio),
                "combo": bool(is_combo),
                "multiLine": bool(getattr(widget, "text_multiline", False)),
                "multiSelect": bool(is_multiselect),
                "exportValue": export_value,
                "value": normalize_value(widget, field_type),
            }

            # Store one logical entry per field key. Keep the first page index and
            # prefer a non-empty value if a later widget in the same logical field has one.
            existing = fields_by_key.get(field_name)
            if existing is None:
                fields_by_key[field_name] = field_entry
                continue

            existing_value = existing.get("value")
            next_value = field_entry.get("value")
            if existing_value in ("", [], False, None) and next_value not in ("", [], False, None):
                existing["value"] = next_value
            if not existing.get("exportValue") and field_entry.get("exportValue"):
                existing["exportValue"] = field_entry["exportValue"]

    print(json.dumps({"fields": list(fields_by_key.values())}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
