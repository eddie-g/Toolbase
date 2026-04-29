#!/usr/bin/env python3
import json
import sys
from typing import Any, Dict

import fitz


def load_entries(path: str) -> Dict[str, Dict[str, Any]]:
    with open(path, "r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if isinstance(payload, dict):
        raw_entries = payload.get("entries", [])
    else:
        raw_entries = payload

    entries: Dict[str, Dict[str, Any]] = {}
    for entry in raw_entries or []:
        if not isinstance(entry, dict):
            continue
        key = str(entry.get("fieldName") or entry.get("key") or "").strip()
        if not key:
            continue
        entries[key] = entry
    return entries


def normalize_checkbox_value(raw_value: Any) -> bool:
    if isinstance(raw_value, bool):
        return raw_value
    if raw_value in (None, "", "Off", "False", "false", 0, "0"):
        return False
    return True


def normalize_text_value(raw_value: Any) -> str:
    if isinstance(raw_value, list):
        return "\n".join(str(item or "") for item in raw_value)
    return "" if raw_value is None else str(raw_value)


def apply_widget_value(widget: fitz.Widget, entry: Dict[str, Any]) -> bool:
    field_type = str(entry.get("fieldType") or "").upper()
    raw_value = entry.get("value")

    # PyMuPDF's Widget.update() calls _validate(), which raises
    # `ValueError: bad rect` for widgets whose annotation rectangle is empty
    # (x0 >= x1 or y0 >= y1) or otherwise non-finite. Some PDFs ship with
    # such degenerate widgets (often hidden / invisible signature or
    # placeholder fields). Updating them aborts the entire AcroForm pass and
    # propagates as a 500 to the download endpoint. Skip them defensively
    # rather than failing the whole download.
    try:
        rect = widget.rect
        if (
            rect is None
            or not rect.is_valid
            or rect.is_empty
            or not (rect.x0 < rect.x1 and rect.y0 < rect.y1)
        ):
            return False
    except Exception:
        return False

    def _safe_update() -> bool:
        try:
            widget.update()
            return True
        except ValueError:
            return False

    if field_type == "BTN":
        is_checkbox = bool(entry.get("checkBox"))
        is_radio = bool(entry.get("radioButton"))
        if is_checkbox:
            widget.field_value = widget.on_state() if normalize_checkbox_value(raw_value) else "Off"
            return _safe_update()
        if is_radio:
            desired_value = str(raw_value or "").strip()
            on_state = str(widget.on_state() or "").strip()
            widget.field_value = on_state if desired_value and desired_value == on_state else "Off"
            return _safe_update()

    widget.field_value = normalize_text_value(raw_value)
    return _safe_update()


def main() -> int:
    if len(sys.argv) != 4:
        print("Usage: apply_acro_form_values.py <input_pdf> <entries_json> <output_pdf>", file=sys.stderr)
        return 1

    input_pdf, entries_json, output_pdf = sys.argv[1:4]
    entries = load_entries(entries_json)
    if not entries:
        doc = fitz.open(input_pdf)
        doc.save(output_pdf)
        return 0

    doc = fitz.open(input_pdf)
    applied = 0

    for page in doc:
        for widget in list(page.widgets() or []):
            field_name = str(getattr(widget, "field_name", "") or "").strip()
            if not field_name or field_name not in entries:
                continue
            if apply_widget_value(widget, entries[field_name]):
                applied += 1

    doc.save(output_pdf)
    print(json.dumps({"success": True, "applied": applied}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
