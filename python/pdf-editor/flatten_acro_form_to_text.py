#!/usr/bin/env python3
import json
import sys

import fitz


def widget_value(widget) -> str:
    value = getattr(widget, "field_value", "") or ""
    if isinstance(value, (list, tuple)):
        return "\n".join(str(item or "") for item in value)
    return str(value)


def widget_font_size(widget) -> float:
    try:
        size = float(getattr(widget, "text_fontsize", 0) or 0)
    except Exception:
        size = 0.0
    return size if size > 0 else 9.0


def widget_font_name(widget) -> str:
    raw = str(getattr(widget, "text_font", "") or "").strip().lower()
    if "bold" in raw or raw == "hebo":
        return "helv"
    if raw in {"helv", "helvetica"}:
        return "helv"
    return "helv"


def widget_color(widget):
    color = getattr(widget, "text_color", None)
    if isinstance(color, (list, tuple)) and len(color) >= 3:
        try:
            return tuple(max(0.0, min(1.0, float(value))) for value in color[:3])
        except Exception:
            pass
    return (0, 0, 0)


def is_text_widget(widget) -> bool:
    field_type = getattr(widget, "field_type", None)
    text_type = getattr(fitz, "PDF_WIDGET_TYPE_TEXT", None)
    if text_type is not None and field_type == text_type:
        return True

    type_name = str(getattr(widget, "field_type_string", "") or "").lower()
    if "text" in type_name:
        return True

    # Older PyMuPDF builds do not always expose enough type metadata. The
    # guided templates currently only create text widgets, so treat missing
    # metadata as text-like while still avoiding known non-text types.
    return field_type is None


def draw_widget_value(page, widget) -> bool:
    if not is_text_widget(widget):
        return False

    text = widget_value(widget)

    rect = fitz.Rect(widget.rect)
    if rect.is_empty or not rect.is_valid:
        return False

    if text == "":
        return True

    font_size = widget_font_size(widget)
    # Give text a small inset so stamped values match normal form appearance
    # and do not touch field edges.
    inset_x = min(2.0, max(0.0, rect.width * 0.08))
    inset_y = min(1.0, max(0.0, rect.height * 0.08))
    text_rect = fitz.Rect(rect.x0 + inset_x, rect.y0 + inset_y, rect.x1 - inset_x, rect.y1 + 2)

    try:
        align = int(getattr(widget, "text_align", fitz.TEXT_ALIGN_LEFT) or fitz.TEXT_ALIGN_LEFT)
    except Exception:
        align = fitz.TEXT_ALIGN_LEFT

    written = page.insert_textbox(
        text_rect,
        text,
        fontname=widget_font_name(widget),
        fontsize=font_size,
        color=widget_color(widget),
        align=align,
    )
    if written >= 0:
        return True

    # If the widget box is too short for insert_textbox, fall back to a direct
    # baseline insertion. This is common for line-signature fields.
    baseline_y = min(rect.y1 - 1, rect.y0 + max(font_size, rect.height * 0.78))
    page.insert_text(
        fitz.Point(rect.x0 + inset_x, baseline_y),
        text.replace("\n", " "),
        fontname=widget_font_name(widget),
        fontsize=font_size,
        color=widget_color(widget),
    )
    return True


def load_skip_field_names(path: str) -> set:
    """Load the optional set of field names whose values must NOT be baked
    into the page content. The caller turns these fields into editable text
    annotations instead, so baking them too would double the text."""
    names = set()
    if not path:
        return names
    try:
        with open(path, "r", encoding="utf-8") as handle:
            raw = json.load(handle)
    except Exception:
        return names
    if isinstance(raw, dict):
        raw = raw.get("field_names") or raw.get("fieldNames") or []
    if isinstance(raw, (list, tuple)):
        for item in raw:
            text = str(item or "").strip()
            if text:
                names.add(text)
    return names


def widget_field_names(widget):
    names = []
    for attr in ("field_name", "field_label"):
        value = str(getattr(widget, attr, "") or "").strip()
        if value:
            names.append(value)
    return names


def main() -> int:
    if len(sys.argv) < 3:
        print("Usage: flatten_acro_form_to_text.py <input_pdf> <output_pdf> [skip_field_names.json]", file=sys.stderr)
        return 1

    input_pdf, output_pdf = sys.argv[1:3]
    skip_field_names = load_skip_field_names(sys.argv[3]) if len(sys.argv) > 3 else set()
    doc = fitz.open(input_pdf)
    flattened = 0
    removed = 0
    skipped = 0

    for page in doc:
        widgets = list(page.widgets() or [])
        for widget in widgets:
            if skip_field_names and any(name in skip_field_names for name in widget_field_names(widget)):
                skipped += 1
                continue
            if draw_widget_value(page, widget):
                flattened += 1
        for widget in widgets:
            try:
                page.delete_widget(widget)
                removed += 1
            except Exception:
                pass

    try:
        doc.need_appearances(False)
    except Exception:
        pass
    doc.save(output_pdf, garbage=4, deflate=True)
    doc.close()

    print(json.dumps({"success": True, "flattened": flattened, "removed": removed, "skipped": skipped}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
