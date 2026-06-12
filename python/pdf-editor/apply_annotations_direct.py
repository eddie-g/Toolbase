#!/usr/bin/env python3
"""
Apply editor annotations directly to an existing PDF using PyMuPDF.
This path avoids pdf-lib full-document rewrites so shape stamping is
independent of overlay/text save behavior.
"""

import base64
import html
import io
import json
import math
import os
import re
import sys
from html.parser import HTMLParser
from typing import Any, Dict, Optional, Tuple
from urllib.parse import urlparse

import fitz
try:
    from PIL import Image
except Exception:  # pragma: no cover - optional runtime dependency
    Image = None

from pdf_annotation_contract import (
    normalize_annotations_for_pdf_export,
    sanitize_pdf_text,
    sanitize_rich_text_html,
)


SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(SCRIPT_DIR, "fonts")
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, "..", ".."))
PUBLIC_DIR = os.path.join(PROJECT_ROOT, "public")
TEMP_DIR = os.path.join(PROJECT_ROOT, "storage", "app", "temp")


PDF_FONT_VARIANTS = {
    "Helvetica": {
        "normal": "helv",
        "bold": "hebo",
        "italic": "heit",
        "boldItalic": "hebi",
    },
    "TrebuchetMS": {
        "normal": "helv",
        "bold": "hebo",
        "italic": "heit",
        "boldItalic": "hebi",
    },
    "TimesRoman": {
        "normal": "tiro",
        "bold": "tibo",
        "italic": "tiit",
        "boldItalic": "tibi",
    },
    "Courier": {
        "normal": "cour",
        "bold": "cobo",
        "italic": "coit",
        "boldItalic": "cobi",
    },
}

FONT_FILE_VARIANTS = {
    "Arimo": {
        "normal": os.path.join(FONT_DIR, "Arimo-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Arimo-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Arimo-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Arimo-Bold.ttf"),
    },
    "Gelasio": {
        "normal": os.path.join(FONT_DIR, "Gelasio-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Gelasio-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Gelasio-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Gelasio-Bold.ttf"),
    },
    "Georgia": {
        "normal": os.path.join(FONT_DIR, "LiberationSerif-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "LiberationSerif-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "LiberationSerif-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "LiberationSerif-BoldItalic.ttf"),
    },
    "Garamond": {
        "normal": os.path.join(FONT_DIR, "EBGaramond-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "EBGaramond-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "EBGaramond-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "EBGaramond-BoldItalic.ttf"),
    },
    "Tinos": {
        "normal": os.path.join(FONT_DIR, "Tinos-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Tinos-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Tinos-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Tinos-Bold.ttf"),
    },
    "Cousine": {
        "normal": os.path.join(FONT_DIR, "Cousine-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Cousine-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Cousine-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Cousine-Bold.ttf"),
    },
    "Lato": {
        "normal": os.path.join(FONT_DIR, "Lato-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Lato-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Lato-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Lato-Bold.ttf"),
    },
    "OpenSans": {
        "normal": os.path.join(FONT_DIR, "OpenSans-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "OpenSans-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "OpenSans-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "OpenSans-Bold.ttf"),
    },
    "Poppins": {
        "normal": os.path.join(FONT_DIR, "Poppins-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Poppins-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Poppins-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Poppins-Bold.ttf"),
    },
    "Roboto": {
        "normal": os.path.join(FONT_DIR, "Roboto-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Roboto-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Roboto-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Roboto-Bold.ttf"),
    },
    "SourceSansPro": {
        "normal": os.path.join(FONT_DIR, "SourceSans3-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "SourceSans3-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "SourceSans3-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "SourceSans3-Bold.ttf"),
    },
    "Helvetica": {
        "normal": os.path.join(FONT_DIR, "Arimo-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Arimo-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Arimo-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Arimo-Bold.ttf"),
    },
    "Verdana": {
        "normal": os.path.join(FONT_DIR, "Verdana-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Verdana-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Verdana-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Verdana-BoldItalic.ttf"),
    },
    "TrebuchetMS": {
        "normal": os.path.join(FONT_DIR, "Verdana-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Verdana-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Verdana-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Verdana-BoldItalic.ttf"),
    },
    "TimesRoman": {
        "normal": os.path.join(FONT_DIR, "LiberationSerif-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "LiberationSerif-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "LiberationSerif-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "LiberationSerif-BoldItalic.ttf"),
    },
    "Courier": {
        "normal": os.path.join(FONT_DIR, "Cousine-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Cousine-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Cousine-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Cousine-Bold.ttf"),
    },
}

HTML_FONT_FAMILY_ALIASES = {
    "Arimo": [
        "Arimo",
        "Arimo-Regular",
    ],
    "Gelasio": [
        "Gelasio",
    ],
    "Georgia": [
        "Georgia",
    ],
    "Garamond": [
        "AnnotGaramond",
        "Garamond",
        "EB Garamond",
        "Baskerville",
    ],
    "Tinos": [
        "Tinos",
        "Tinos-Regular",
    ],
    "Cousine": [
        "Cousine",
        "Cousine-Regular",
    ],
    "Lato": [
        "Lato",
        "Lato-Regular",
        "PdbpbbLato",
        "PdbpbbLato-Regular",
        "DrhchpLato",
        "DrhchpLato-Regular",
    ],
    "OpenSans": [
        "Open Sans",
        "OpenSans",
        "OpenSans-Regular",
    ],
    "Poppins": [
        "Poppins",
        "Poppins-Regular",
        "Poppins-Medium",
        "Poppins-SemiBold",
    ],
    "Roboto": [
        "Roboto",
        "Roboto-Regular",
    ],
    "SourceSansPro": [
        "Source Sans Pro",
        "SourceSansPro",
        "SourceSans3",
        "SourceSans3-Regular",
    ],
    "Helvetica": [
        "AnnotHelvetica",
        "Helvetica",
        "Arial",
        "Geneva",
        "sans-serif",
    ],
    "Verdana": [
        "Verdana",
        "Geneva",
    ],
    "TrebuchetMS": [
        "Trebuchet MS",
        "TrebuchetMS",
    ],
    "TimesRoman": [
        "TimesRoman",
        "Times New Roman",
        "Times",
        "AnnotPalatino",
        "Palatino",
        "Book Antiqua",
        "serif",
    ],
    "Courier": [
        "Courier",
        "Courier New",
        "monospace",
    ],
}

EMBEDDED_FONT_METADATA_CACHE: dict[str, dict[str, Any]] = {}

EMBEDDED_FONT_BYPASS_FAMILIES = {
    "Arial",
    "Helvetica",
    "Verdana",
    "Tahoma",
    "TahomaUnicode",
    "Times",
    "TimesNewRoman",
    "Palatino",
    "Garamond",
    "Courier",
    "Calibri",
    "Roboto",
    "Lato",
    "Montserrat",
    "OpenSans",
    "Poppins",
    "Raleway",
    "Nunito",
    "Inter",
    "Oswald",
    "SourceSansPro",
    "PlayfairDisplay",
    "Merriweather",
}


def normalize_exact_font_family(value: Any) -> str:
    cleaned = str(value or "").strip().replace('"', "").replace("'", "")
    if not cleaned:
        return ""

    if "+" in cleaned:
        prefix, remainder = cleaned.split("+", 1)
        if len(prefix) == 6:
            cleaned = remainder

    if len(cleaned) > 7 and cleaned[:6].isalpha() and cleaned[6:7].isupper():
        maybe = cleaned[6:]
        if maybe[:1].isupper():
            cleaned = maybe

    base = cleaned.split("-", 1)[0].split("_", 1)[0].split(",", 1)[0].strip()
    suffixes = (
        "Thin", "Hairline", "ExtraLight", "UltraLight", "Light",
        "Regular", "Medium", "SemiBold", "DemiBold", "Bold",
        "ExtraBold", "UltraBold", "Black", "Heavy",
    )
    family = base
    for suffix in suffixes:
        if family.endswith(suffix) and len(family) > len(suffix):
            family = family[: -len(suffix)]
            break
    return family.strip()


def should_bypass_embedded_font(value: Any) -> bool:
    normalized = normalize_exact_font_family(value)
    if not normalized:
        return False
    if normalized in EMBEDDED_FONT_BYPASS_FAMILIES:
        return True
    lower = normalized.lower()
    return any(token in lower for token in (
        "arial",
        "helvetica",
        "verdana",
        "tahoma",
        "times",
        "palatino",
        "bookantiqua",
        "garamond",
        "baskerville",
        "courier",
        "calibri",
        "roboto",
        "lato",
        "montserrat",
        "opensans",
        "poppins",
        "sourcesans",
        "playfair",
        "merriweather",
        "inter",
        "nunito",
        "oswald",
        "raleway",
    ))


def load_embedded_font_metadata(document_id: Any) -> dict[str, Any]:
    key = str(document_id or "").strip()
    if not key:
        return {}
    if key in EMBEDDED_FONT_METADATA_CACHE:
        return EMBEDDED_FONT_METADATA_CACHE[key]

    path = os.path.join(TEMP_DIR, f"embedded_fonts_{key}.json")
    if not os.path.exists(path):
        EMBEDDED_FONT_METADATA_CACHE[key] = {}
        return {}

    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
    except Exception:
        data = {}
    if not isinstance(data, dict):
        data = {}
    EMBEDDED_FONT_METADATA_CACHE[key] = data
    return data


def embedded_font_public_path_to_absolute(web_path: Any) -> str:
    text = str(web_path or "").strip()
    if not text:
        return ""
    if os.path.isabs(text) and os.path.exists(text):
        return text
    if text.startswith("/"):
        candidate = os.path.join(PUBLIC_DIR, text.lstrip("/"))
        if os.path.exists(candidate):
            return candidate
    candidate = os.path.join(PUBLIC_DIR, text)
    if os.path.exists(candidate):
        return candidate
    return ""


def clamp_page_index(value: Any, page_count: int) -> Optional[int]:
    try:
        idx = int(value)
    except Exception:
        return None
    if idx < 0 or idx >= page_count:
        return None
    return idx


def hex_to_rgb(value: Any) -> Tuple[float, float, float]:
    if not value:
        return (0.0, 0.0, 0.0)
    text = str(value).strip().lstrip("#")
    if len(text) != 6:
        return (0.0, 0.0, 0.0)
    try:
        return (
            int(text[0:2], 16) / 255.0,
            int(text[2:4], 16) / 255.0,
            int(text[4:6], 16) / 255.0,
        )
    except Exception:
        return (0.0, 0.0, 0.0)


def to_rect(page: fitz.Page, ann: Dict[str, Any]) -> Optional[fitz.Rect]:
    try:
        x = float(ann.get("pdfX", 0))
        y = float(ann.get("pdfY", 0))
        w = float(ann.get("pdfWidth", 0))
        h = float(ann.get("pdfHeight", 0))
    except Exception:
        return None
    if w <= 0 or h <= 0:
        return None
    # Frontend stores annotation.pdfY in PDF-lib coordinates (bottom-origin).
    # PyMuPDF page methods use top-origin page coordinates.
    ph = page.rect.height
    return fitz.Rect(x, ph - (y + h), x + w, ph - y)


def normalize_font_family(value: Any) -> str:
    exact_family = normalize_exact_font_family(value)
    if exact_family in FONT_FILE_VARIANTS:
        return exact_family
    lower = str(value or "").strip().lower().replace('"', "").replace("'", "")
    lower = lower.replace(" ", "").replace("-", "")

    if "arimo" in lower:
        return "Arimo"
    if "lato" in lower:
        return "Lato"
    if "opensans" in lower:
        return "OpenSans"
    if "poppins" in lower:
        return "Poppins"
    if "roboto" in lower:
        return "Roboto"
    if "sourcesans" in lower:
        return "SourceSansPro"
    if "gelasio" in lower:
        return "Gelasio"
    if "georgia" in lower:
        return "Georgia"
    if "tinos" in lower:
        return "Tinos"
    if "timesroman" in lower:
        return "TimesRoman"
    if "garamond" in lower or "baskerville" in lower:
        return "Garamond"
    if "cousine" in lower:
        return "Cousine"
    if any(token in lower for token in ("courier", "monospace")):
        return "Courier"
    if "verdana" in lower or "geneva" in lower:
        return "Verdana"
    if "trebuchet" in lower:
        return "TrebuchetMS"
    if any(token in lower for token in ("arial", "helvetica", "sansserif")):
        return "Helvetica"
    if any(token in lower for token in (
        "times",
        "palatino",
        "bookantiqua",
        "serif",
    )):
        return "TimesRoman"
    return "Helvetica"


def css_font_family(value: Any) -> str:
    lower = str(value or "").strip().lower().replace('"', "").replace("'", "")
    lower = lower.replace(" ", "").replace("-", "")

    if "lato" in lower:
        return "Lato, Helvetica, Arial, sans-serif"
    if "opensans" in lower:
        return "Open Sans, Helvetica, Arial, sans-serif"
    if "poppins" in lower:
        return "Poppins, Helvetica, Arial, sans-serif"
    if "roboto" in lower:
        return "Roboto, Helvetica, Arial, sans-serif"
    if "sourcesans" in lower:
        return "Source Sans Pro, Helvetica, Arial, sans-serif"
    if "gelasio" in lower:
        return "Gelasio, Georgia, serif"
    if "georgia" in lower:
        return '"Georgia", "Liberation Serif", serif'
    if "garamond" in lower or "baskerville" in lower:
        return '"AnnotGaramond", "EB Garamond", Baskerville, serif'
    if "palatino" in lower or "bookantiqua" in lower:
        return '"AnnotPalatino", "Liberation Serif", "Times New Roman", serif'

    family = normalize_font_family(value)
    if family == "Arimo":
        return "Arimo, Helvetica, Arial, sans-serif"
    if family == "Helvetica":
        return '"AnnotHelvetica", "Arimo", Arial, sans-serif'
    if family == "Verdana":
        return "Verdana, Geneva, Arial, sans-serif"
    if family == "TrebuchetMS":
        return '"Trebuchet MS", Verdana, Geneva, Arial, sans-serif'
    if family == "Lato":
        return "Lato, Helvetica, Arial, sans-serif"
    if family == "OpenSans":
        return "Open Sans, Helvetica, Arial, sans-serif"
    if family == "Poppins":
        return "Poppins, Helvetica, Arial, sans-serif"
    if family == "Roboto":
        return "Roboto, Helvetica, Arial, sans-serif"
    if family == "SourceSansPro":
        return "Source Sans Pro, Helvetica, Arial, sans-serif"
    if family == "Gelasio":
        return "Gelasio, Georgia, serif"
    if family == "Georgia":
        return '"Georgia", "Liberation Serif", serif'
    if family == "Garamond":
        return '"AnnotGaramond", "EB Garamond", Baskerville, serif'
    if family == "TimesRoman" and any(token in lower for token in ("palatino", "bookantiqua", "annotpalatino")):
        return '"AnnotPalatino", "Liberation Serif", "Times New Roman", serif'
    if family == "Tinos":
        return "Tinos, Times New Roman, serif"
    if family == "Cousine":
        return "Cousine, Courier New, monospace"
    if family == "TimesRoman":
        return "TimesRoman, Tinos, Times New Roman, serif"
    if family == "Courier":
        return "Courier, Cousine, Courier New, monospace"
    return "Helvetica, Arimo, Arial, sans-serif"


def build_html_font_face_css() -> str:
    css_rules: list[str] = []
    for normalized_family, aliases in HTML_FONT_FAMILY_ALIASES.items():
        variants = FONT_FILE_VARIANTS.get(normalized_family, {})
        regular_file = variants.get("normal")
        bold_file = variants.get("bold") or regular_file
        italic_file = variants.get("italic") or regular_file
        bold_italic_file = variants.get("boldItalic") or bold_file or italic_file or regular_file
        faces = [
            (400, "normal", regular_file),
            (700, "normal", bold_file),
            (400, "italic", italic_file),
            (700, "italic", bold_italic_file),
        ]
        for alias in aliases:
            quoted_alias = json.dumps(alias)
            for weight, style, font_path in faces:
                if not font_path or not os.path.exists(font_path):
                    continue
                css_rules.append(
                    "@font-face { "
                    f"font-family: {quoted_alias}; "
                    f"src: url({os.path.basename(font_path)}); "
                    f"font-weight: {weight}; "
                    f"font-style: {style}; "
                    "}"
                )
    return "\n".join(css_rules)


HTML_FONT_FACE_CSS = build_html_font_face_css()


def _promoted_annotation_was_resized(ann: Dict[str, Any]) -> bool:
    """True if the annotation's current PDF box differs meaningfully from its
    extracted source block — i.e. the user dragged a resize handle.
    """
    try:
        cur_w = float(ann.get("pdfWidth") or 0)
        cur_h = float(ann.get("pdfHeight") or 0)
        src_w = float(ann.get("sourceBlockWidth") or 0)
        src_h = float(ann.get("sourceBlockHeight") or 0)
    except Exception:
        return False
    if cur_w <= 0 or src_w <= 0:
        return False
    if abs(cur_w - src_w) > 2.0:
        return True
    if cur_h > 0 and src_h > 0 and abs(cur_h - src_h) > 2.0:
        return True
    return False


def should_preserve_promoted_source_lines(ann: Dict[str, Any], text: str) -> bool:
    if not bool(ann.get("promotedFromExtraction")):
        return False
    if bool(ann.get("promotedReflowEnabled")):
        return False
    # If the user resized the annotation box the export must reflow into the new
    # bounds. Preserving the original PDF layout here causes text to spread
    # horizontally past the new box.
    if bool(ann.get("promotedDirty")) and _promoted_annotation_was_resized(ann):
        return False

    normalized_text_lines = str(text or "").replace("\r\n", "\n").replace("\r", "\n").split("\n")
    raw_boxes = ann.get("sourceLineBBoxes")
    raw_source_lines = ann.get("sourceTextLines")
    source_line_count = len(raw_boxes) if isinstance(raw_boxes, list) and raw_boxes else 0
    if source_line_count <= 0:
        source_line_count = len(raw_source_lines) if isinstance(raw_source_lines, list) and raw_source_lines else 0

    if source_line_count <= 0:
        return True

    if len(normalized_text_lines) == source_line_count:
        return True

    if (
        len(normalized_text_lines) == 1
        and isinstance(raw_source_lines, list)
        and len(raw_source_lines) == source_line_count
    ):
        return True

    return False


def build_annotation_htmlbox_css(ann: Dict[str, Any], font_size: float, opacity: float) -> str:
    font_weight = resolve_annotation_font_weight(ann)
    font_style = resolve_annotation_font_style(ann)
    text_align = str(ann.get("textAlign") or "left").strip().lower()
    text_decoration = "underline" if resolve_annotation_underline(ann) else "none"
    font_family = css_font_family(ann.get("fontFamily"))
    text_color = str(ann.get("textColor") or "#000000").strip() or "#000000"
    preserve_extracted_lines = should_preserve_promoted_source_lines(ann, ann.get("text") or "")
    try:
        line_height_value = float(ann.get("lineHeight") or 0)
    except Exception:
        line_height_value = 0.0
    # Match the browser's blockLineHeightPx formula (fontSize * 1.18) with a
    # unitless value so rich-html spans with per-span font-sizes scale
    # correctly. MuPDF's "normal" default produced tighter line spacing than
    # the editor.
    line_height_css = f"{line_height_value}pt" if line_height_value > 0 else "1.18"
    white_space = "pre" if preserve_extracted_lines else "pre-wrap"
    overflow_wrap = "normal" if preserve_extracted_lines else "break-word"
    word_break = "normal" if preserve_extracted_lines else "break-word"
    return (
        f"{HTML_FONT_FACE_CSS}\n"
        "body { margin: 0; padding: 0; }\n"
        ".annotation-box {\n"
        "  margin: 0;\n"
        "  padding: 0;\n"
        f"  font-family: {font_family};\n"
        f"  font-size: {font_size}pt;\n"
        f"  color: {text_color};\n"
        f"  font-weight: {font_weight};\n"
        f"  font-style: {font_style};\n"
        f"  text-align: {text_align};\n"
        f"  text-decoration: {text_decoration};\n"
        f"  opacity: {opacity};\n"
        f"  line-height: {line_height_css};\n"
        f"  white-space: {white_space};\n"
        "  hyphens: none;\n"
        f"  overflow-wrap: {overflow_wrap};\n"
        f"  word-break: {word_break};\n"
        "}\n"
        ".annotation-box p, .annotation-box div, .annotation-box span {\n"
        "  margin-top: 0;\n"
        "  margin-bottom: 0;\n"
        "}\n"
    )


def _strip_px_font_sizes_from_html(html_str: str) -> str:
    """Remove font-size:Xpx declarations from inline style attributes.

    The richTextHtml editor stores font sizes as browser screen pixels which
    reflect the current PDF zoom level.  PyMuPDF's insert_htmlbox treats CSS px
    as an absolute unit at a different scale than the browser, so those px values
    produce the wrong rendered size.  The outer .annotation-box CSS already sets
    the correct font-size in PDF points; stripping the per-span px overrides lets
    that outer size apply to all text, matching what the user intended.
    """
    import re as _re

    _PX_PROP_RE = _re.compile(
        r"\b(?:font-size|line-height|min-height)\s*:\s*[\d.]+px\s*;?",
        flags=_re.IGNORECASE,
    )

    def _clean_style(m: "re.Match[str]") -> str:
        style = m.group(1)
        cleaned = _PX_PROP_RE.sub("", style)
        # Collapse any double-semicolons left behind
        cleaned = _re.sub(r";\s*;+", ";", cleaned).strip("; ")
        if cleaned.strip():
            return f'style="{cleaned}"'
        return ""  # Drop the attribute entirely when nothing remains

    # Only touch style="..." attributes that actually contain a px size/spacing
    return _re.sub(
        r'\bstyle="([^"]*\b(?:font-size|line-height|min-height)\s*:\s*[\d.]+px[^"]*)"',
        _clean_style,
        html_str,
        flags=_re.IGNORECASE,
    )


def build_annotation_htmlbox_markup(ann: Dict[str, Any], text: str) -> str:
    rich_html = sanitize_rich_text_html(ann.get("richTextHtml") or "").strip()
    if rich_html:
        rich_html = _strip_px_font_sizes_from_html(rich_html)
    inner_html = rich_html if rich_html else html.escape(sanitize_pdf_text(text))
    return f'<div class="annotation-box">{inner_html}</div>'


class _RichTextTextNodeCounter(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.text_chunks: list[str] = []

    def handle_data(self, data: str) -> None:
        if data and data.strip():
            self.text_chunks.append(data)


def has_single_run_rich_text(ann: Dict[str, Any], text: str) -> bool:
    rich_html = str(ann.get("richTextHtml") or "").strip()
    if not rich_html:
        return True

    parser = _RichTextTextNodeCounter()
    try:
        parser.feed(rich_html)
        parser.close()
    except Exception:
        return False

    chunks = [chunk for chunk in parser.text_chunks if chunk.strip()]
    if len(chunks) != 1:
        return False

    normalized_chunk = sanitize_pdf_text(chunks[0]).replace("\r\n", "\n").replace("\r", "\n")
    normalized_text = sanitize_pdf_text(text).replace("\r\n", "\n").replace("\r", "\n")
    return normalized_chunk == normalized_text


def resolve_annotation_font_style(ann: Dict[str, Any]) -> str:
    font_style = str(ann.get("fontStyle") or "").strip() or "normal"
    if is_italic_style(font_style):
        return font_style

    rich_html = str(ann.get("richTextHtml") or "").strip().lower()
    text = sanitize_pdf_text(ann.get("text") or "")
    if rich_html and has_single_run_rich_text(ann, text):
        if "font-style:italic" in rich_html or "font-style: italic" in rich_html:
            return "italic"
        if "font-style:oblique" in rich_html or "font-style: oblique" in rich_html:
            return "oblique"

    return font_style


def resolve_annotation_font_weight(ann: Dict[str, Any]) -> str:
    font_weight = str(ann.get("fontWeight") or "").strip() or "normal"
    if is_bold_weight(font_weight):
        return font_weight

    rich_html = str(ann.get("richTextHtml") or "").strip().lower()
    text = str(ann.get("text") or "")
    if rich_html and has_single_run_rich_text(ann, text):
        if (
            "font-weight:700" in rich_html
            or "font-weight: 700" in rich_html
            or "font-weight:bold" in rich_html
            or "font-weight: bold" in rich_html
        ):
            return "700"

    return font_weight


def resolve_annotation_underline(ann: Dict[str, Any]) -> bool:
    if bool(ann.get("underline")):
        return True

    rich_html = str(ann.get("richTextHtml") or "").strip().lower()
    text = str(ann.get("text") or "")
    if rich_html and has_single_run_rich_text(ann, text):
        return (
            "text-decoration:underline" in rich_html
            or "text-decoration: underline" in rich_html
            or "text-decoration-line:underline" in rich_html
            or "text-decoration-line: underline" in rich_html
        )

    return False


def is_bold_weight(value: Any) -> bool:
    lower = str(value or "").strip().lower()
    if lower == "bold":
        return True
    try:
        return int(float(lower)) >= 600
    except Exception:
        return "bold" in lower


def is_italic_style(value: Any) -> bool:
    lower = str(value or "").strip().lower()
    return "italic" in lower or "oblique" in lower


def resolve_embedded_font_entry(ann: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    if (
        bool(ann.get("promotedFromExtraction"))
        and (
            bool(ann.get("promotedDirty"))
            or bool(ann.get("userAuthored"))
            or bool(ann.get("styleDirty"))
            or bool(str(ann.get("richTextHtml") or "").strip())
        )
    ):
        return None

    document_id = ann.get("__documentId") or ann.get("documentId")
    metadata = load_embedded_font_metadata(document_id)
    if not metadata:
        return None

    raw_family = str(ann.get("fontFamily") or "").strip()
    raw_exact = str(ann.get("fontSourceName") or raw_family or "").strip()
    selected_family = normalize_font_family(raw_family)
    exact_family = normalize_font_family(raw_exact)
    if raw_family and selected_family and exact_family and selected_family.lower() != exact_family.lower():
        raw_exact = raw_family
    normalized_exact = normalize_exact_font_family(raw_exact)
    normalized_family = normalize_exact_font_family(raw_family)
    force_embedded_font = _boolish(ann.get("forceEmbeddedFont")) or _boolish(ann.get("pdfjsForceEmbeddedFont"))
    if not force_embedded_font and (
        should_bypass_embedded_font(raw_exact)
        or should_bypass_embedded_font(normalized_exact)
        or should_bypass_embedded_font(normalized_family)
    ):
        return None
    prefer_exact_promoted_embedded_face = (
        bool(ann.get("promotedFromExtraction"))
        and bool(raw_exact)
        and raw_exact.lower() not in {normalized_exact.lower(), normalized_family.lower()}
    )
    wants_bold = is_bold_weight(resolve_annotation_font_weight(ann))
    wants_italic = is_italic_style(resolve_annotation_font_style(ann))

    best_entry = None
    best_score: tuple[int, int, int] | None = None

    for font_key, font_data in metadata.items():
        if not isinstance(font_data, dict):
            continue
        clean_name = str(font_data.get("clean_name") or font_key or "").strip()
        family_name = str(font_data.get("family") or clean_name or "").strip()
        normalized_clean = normalize_exact_font_family(clean_name)
        normalized_entry_family = normalize_exact_font_family(family_name)
        weight_text = str(font_data.get("css_weight") or "400").strip()
        style_text = str(font_data.get("css_style") or "normal").strip().lower()
        try:
            weight_value = int(float(weight_text))
        except Exception:
            weight_value = 400
        score = 0
        if raw_exact and clean_name.lower() == raw_exact.lower():
            score += 120
        if normalized_exact and normalized_clean.lower() == normalized_exact.lower():
            score += 100
        if normalized_exact and normalized_entry_family.lower() == normalized_exact.lower():
            score += 80
        if normalized_family and normalized_entry_family.lower() == normalized_family.lower():
            score += 60

        if wants_bold:
            score += 20 if weight_value >= 600 else -10
        else:
            score += 20 if weight_value < 600 else -10

        if wants_italic:
            score += 10 if style_text == "italic" else -5
        else:
            score += 10 if style_text != "italic" else -5

        if score <= 0:
            continue

        weight_distance = abs(weight_value - (700 if wants_bold else 400))
        style_penalty = 0 if (wants_italic == (style_text == "italic")) else 1
        candidate_score = (score, -style_penalty, -weight_distance)
        if best_score is None or candidate_score > best_score:
            absolute_path = embedded_font_public_path_to_absolute(font_data.get("file_path"))
            if not absolute_path:
                continue
            best_score = candidate_score
            best_entry = {
                "clean_name": clean_name,
                "family": family_name,
                "css_weight": weight_value,
                "css_style": style_text,
                "fontfile": absolute_path,
            }

    return best_entry


def should_use_htmlbox_for_text(ann: Dict[str, Any], embedded_font_entry: Optional[Dict[str, Any]]) -> bool:
    rich_html = str(ann.get("richTextHtml") or "").strip()
    text = str(ann.get("text") or "")
    if rich_html and not has_single_run_rich_text(ann, text):
        return True
    if not rich_html:
        return False

    family = normalize_font_family(ann.get("fontFamily"))
    if embedded_font_entry is None and (family in FONT_FILE_VARIANTS or family in PDF_FONT_VARIANTS):
        return False
    if not ann.get("promotedFromExtraction"):
        return embedded_font_entry is None

    exact_family = normalize_exact_font_family(ann.get("fontSourceName") or ann.get("fontFamily"))
    return exact_family in {"Arimo", "Gelasio", "Tinos", "Cousine"}


def should_prefer_pdf_font_text_rendering(
    ann: Dict[str, Any],
    embedded_font_entry: Optional[Dict[str, Any]],
    text: str,
) -> bool:
    if embedded_font_entry is not None:
        return False
    if bool(ann.get("promotedFromExtraction")):
        return False
    if not is_italic_style(resolve_annotation_font_style(ann)):
        return False

    family = normalize_font_family(ann.get("fontFamily"))
    if family not in PDF_FONT_VARIANTS:
        return False

    return has_single_run_rich_text(ann, text)


def resolve_font_vertical_metrics(font: Optional[fitz.Font]) -> tuple[float, float]:
    ascender = 0.9
    descender = 0.25
    if font is not None:
        try:
            raw_ascender = float(getattr(font, "ascender", 0.0) or 0.0)
            raw_descender = abs(float(getattr(font, "descender", 0.0) or 0.0))
            if raw_ascender > 0:
                ascender = raw_ascender
            if raw_descender > 0:
                descender = raw_descender
        except Exception:
            pass
    return ascender, descender


def expanded_text_rect(
    page: fitz.Page,
    rect: fitz.Rect,
    line_count: int,
    line_height: float,
    font_size: float = 0.0,
    font: Optional[fitz.Font] = None,
    padding_top: float = 0.0,
    padding_bottom: float = 0.0,
) -> fitz.Rect:
    if line_count <= 0:
        return rect
    effective_line_height = float(line_height or 0.0)
    if font_size > 0:
        ascender, descender = resolve_font_vertical_metrics(font)
        glyph_height = font_size * (ascender + descender)
        if effective_line_height <= 0:
            effective_line_height = glyph_height
        required_height = (
            padding_top
            + (font_size * ascender)
            + (max(0, line_count - 1) * effective_line_height)
            + (font_size * descender)
            + padding_bottom
        )
    else:
        if effective_line_height <= 0:
            return rect
        required_height = padding_top + (line_count * effective_line_height) + padding_bottom
    if required_height <= rect.height + 0.5:
        return rect
    return fitz.Rect(
        rect.x0,
        rect.y0,
        rect.x1,
        min(page.rect.y1, rect.y0 + required_height),
    )


def normalize_exact_source_line_layout(
    ann: Dict[str, Any],
    text: str,
    font: fitz.Font,
    font_size: float,
    current_rect: Optional[fitz.Rect] = None,
) -> list[Dict[str, Any]]:
    def _normalize_line_texts(value: Any) -> list[str]:
        return [
            str(line or "")
            for line in str(value or "").replace("\r\n", "\n").replace("\r", "\n").split("\n")
        ]

    def _tokenize_line(value: str) -> list[str]:
        return [token for token in re.split(r"\s+", str(value or "").strip()) if token]

    def _reconstruct_flattened_source_lines(
        flattened_lines: list[str],
        source_lines: list[str],
        line_count: int,
    ) -> list[str]:
        if line_count <= 0:
            return []
        if len(flattened_lines) != 1:
            return []
        if len(source_lines) != line_count:
            return []

        flattened_tokens = _tokenize_line(flattened_lines[0])
        if not flattened_tokens:
            return []

        source_token_counts = [
            len(_tokenize_line(source_line))
            for source_line in source_lines
        ]
        if not source_token_counts or sum(source_token_counts) != len(flattened_tokens):
            return []

        reconstructed_lines: list[str] = []
        cursor = 0
        for token_count in source_token_counts:
            line_tokens = flattened_tokens[cursor:cursor + token_count]
            if len(line_tokens) != token_count:
                return []
            reconstructed_lines.append(" ".join(line_tokens))
            cursor += token_count
        return reconstructed_lines if cursor == len(flattened_tokens) else []

    raw_boxes = ann.get("sourceLineBBoxes")
    if not isinstance(raw_boxes, list) or not raw_boxes:
        return []

    normalized_text_lines = _normalize_line_texts(text)
    raw_source_lines = ann.get("sourceTextLines")
    source_lines = raw_source_lines if isinstance(raw_source_lines, list) else []

    if len(normalized_text_lines) == len(raw_boxes):
        line_texts = [str(line or "") for line in normalized_text_lines]
    else:
        reconstructed_line_texts = _reconstruct_flattened_source_lines(
            normalized_text_lines,
            [str(line or "") for line in source_lines],
            len(raw_boxes),
        )
        if reconstructed_line_texts:
            line_texts = reconstructed_line_texts
        elif len(source_lines) == len(raw_boxes):
            line_texts = [str(line or "") for line in source_lines]
        else:
            return []

    source_anchor_x = None
    source_anchor_y = None
    try:
        raw_source_left = ann.get("sourceBlockLeft")
        if raw_source_left is not None:
            source_anchor_x = float(raw_source_left)
    except Exception:
        source_anchor_x = None
    try:
        raw_source_top = ann.get("sourceBlockTop")
        if raw_source_top is not None:
            source_anchor_y = float(raw_source_top)
    except Exception:
        source_anchor_y = None
    if source_anchor_x is None:
        try:
            source_anchor_x = min(
                min(float(raw_box[0]), float(raw_box[2]))
                for raw_box in raw_boxes
                if isinstance(raw_box, (list, tuple)) and len(raw_box) >= 4
            )
        except Exception:
            source_anchor_x = None
    if source_anchor_y is None:
        try:
            source_anchor_y = min(
                min(float(raw_box[1]), float(raw_box[3]))
                for raw_box in raw_boxes
                if isinstance(raw_box, (list, tuple)) and len(raw_box) >= 4
            )
        except Exception:
            source_anchor_y = None

    source_rect = None
    try:
        raw_source_width = ann.get("sourceBlockWidth")
        raw_source_height = ann.get("sourceBlockHeight")
        if (
            source_anchor_x is not None
            and source_anchor_y is not None
            and raw_source_width is not None
            and raw_source_height is not None
        ):
            source_rect = fitz.Rect(
                float(source_anchor_x),
                float(source_anchor_y),
                float(source_anchor_x) + float(raw_source_width),
                float(source_anchor_y) + float(raw_source_height),
            )
    except Exception:
        source_rect = None

    if source_rect is None:
        source_rects = []
        for raw_box in raw_boxes:
            if not isinstance(raw_box, (list, tuple)) or len(raw_box) < 4:
                continue
            try:
                source_rects.append(
                    fitz.Rect(
                        float(raw_box[0]),
                        float(raw_box[1]),
                        float(raw_box[2]),
                        float(raw_box[3]),
                    )
                )
            except Exception:
                continue
        if source_rects:
            source_rect = fitz.Rect(source_rects[0])
            for extra_rect in source_rects[1:]:
                source_rect |= extra_rect

    annotation_text_color = str(ann.get("textColor") or "#000000").strip() or "#000000"
    raw_annotation_font_family = str(ann.get("fontFamily") or "").strip()
    raw_annotation_font_source_name = str(ann.get("fontSourceName") or "").strip()
    raw_annotation_font_weight = str(ann.get("fontWeight") or "").strip()
    raw_annotation_font_style = str(ann.get("fontStyle") or "").strip()
    annotation_font_family = raw_annotation_font_family or "Helvetica"
    annotation_font_source_name = (
        raw_annotation_font_source_name
        or raw_annotation_font_family
        or "Helvetica"
    )
    annotation_font_weight = resolve_annotation_font_weight(ann)
    annotation_font_style = resolve_annotation_font_style(ann)
    annotation_underline = resolve_annotation_underline(ann)
    dominant_source_color = None
    dominant_source_font_family = None
    dominant_source_font_weight = None
    dominant_source_font_style = None
    dominant_source_underline = None

    source_spans = ann.get("sourceSpans")
    normalized_source_spans = []
    if isinstance(source_spans, list):
        for span in source_spans:
            if not isinstance(span, dict):
                continue
            bbox = span.get("bbox")
            if not isinstance(bbox, (list, tuple)) or len(bbox) < 4:
                continue
            try:
                span_bbox = [
                    float(bbox[0]),
                    float(bbox[1]),
                    float(bbox[2]),
                    float(bbox[3]),
                ]
            except Exception:
                continue
            origin = span.get("origin")
            try:
                span_origin = (
                    [float(origin[0]), float(origin[1])]
                    if isinstance(origin, (list, tuple)) and len(origin) >= 2
                    else None
                )
            except Exception:
                span_origin = None
            normalized_source_spans.append({
                "bbox": span_bbox,
                "origin": span_origin,
                "font": str(span.get("font") or "").strip(),
                "font_size": float(span.get("fontSize") or span.get("font_size") or font_size or 0),
                "font_weight": str(span.get("fontWeight") or span.get("font_weight") or ann.get("fontWeight") or "400"),
                "font_style": str(span.get("fontStyle") or span.get("font_style") or ann.get("fontStyle") or "normal"),
                "color": str(
                    (span.get("hex_color") if span.get("hex_color") is not None else span.get("color"))
                    or ann.get("textColor")
                    or "#000000"
                ),
                "underline": bool(span.get("underline")),
                "rotation": span.get("rotation"),
                "direction": span.get("direction"),
            })
    visible_source_anchor_x = None
    if normalized_source_spans:
        visible_source_anchor_candidates = []
        for span in normalized_source_spans:
            origin = span.get("origin")
            if isinstance(origin, (list, tuple)) and len(origin) >= 2:
                try:
                    visible_source_anchor_candidates.append(float(origin[0]))
                    continue
                except Exception:
                    pass
            try:
                visible_source_anchor_candidates.append(float(span["bbox"][0]))
            except Exception:
                continue
        if visible_source_anchor_candidates:
            visible_source_anchor_x = min(visible_source_anchor_candidates)

    translate_anchor_x = source_anchor_x
    if (
        current_rect is not None
        and isinstance(current_rect, fitz.Rect)
        and source_anchor_x is not None
        and visible_source_anchor_x is not None
    ):
        anchor_gap = float(visible_source_anchor_x) - float(source_anchor_x)
        closer_to_visible_anchor = (
            abs(float(current_rect.x0) - float(visible_source_anchor_x))
            + 0.5
            < abs(float(current_rect.x0) - float(source_anchor_x))
        )
        if anchor_gap > max(3.0, float(font_size or 0.0) * 0.25) and closer_to_visible_anchor:
            translate_anchor_x = float(visible_source_anchor_x)

    translate_x = 0.0
    translate_y = 0.0
    if (
        current_rect is not None
        and isinstance(current_rect, fitz.Rect)
        and translate_anchor_x is not None
        and source_anchor_y is not None
    ):
        should_translate = True
        if source_rect is not None and not source_rect.is_empty:
            geometry_tolerance = max(0.75, min(2.0, float(font_size or 0.0) * 0.08))
            if (
                abs(current_rect.x0 - source_rect.x0) <= geometry_tolerance
                and abs(current_rect.y0 - source_rect.y0) <= geometry_tolerance
                and abs(current_rect.width - source_rect.width) <= geometry_tolerance
                and abs(current_rect.height - source_rect.height) <= geometry_tolerance
            ):
                should_translate = False
        if should_translate:
            translate_x = current_rect.x0 - translate_anchor_x
            translate_y = current_rect.y0 - source_anchor_y

    if normalized_source_spans:
        dominant_source_span = max(
            normalized_source_spans,
            key=lambda span: max(
                1.0,
                float(span["bbox"][2]) - float(span["bbox"][0]),
            ),
        )
        dominant_source_color = str(dominant_source_span.get("color") or "").strip() or None
        dominant_source_font_family = str(dominant_source_span.get("font") or "").strip() or None
        dominant_source_font_weight = str(dominant_source_span.get("font_weight") or "").strip() or None
        dominant_source_font_style = str(dominant_source_span.get("font_style") or "").strip() or None
        dominant_source_underline = bool(dominant_source_span.get("underline"))

    force_annotation_font_family = (
        bool(raw_annotation_font_family or raw_annotation_font_source_name)
        and (
            not dominant_source_font_family
            or normalize_font_family(annotation_font_family) != normalize_font_family(dominant_source_font_family)
            or normalize_exact_font_family(annotation_font_source_name) != normalize_exact_font_family(dominant_source_font_family)
        )
    )
    style_dirty = bool(ann.get("styleDirty"))
    force_annotation_text_color = style_dirty and (
        bool(annotation_text_color)
        and bool(dominant_source_color)
        and annotation_text_color.lower() != dominant_source_color.lower()
    )
    force_annotation_font_weight = (
        bool(raw_annotation_font_weight)
        and bool(dominant_source_font_weight)
        and annotation_font_weight.lower() != dominant_source_font_weight.lower()
    )
    force_annotation_font_style = (
        bool(raw_annotation_font_style)
        and bool(dominant_source_font_style)
        and annotation_font_style.lower() != dominant_source_font_style.lower()
    )
    dominant_source_font_size = None
    if normalized_source_spans:
        try:
            dominant_source_font_size = float(dominant_source_span.get("font_size") or 0)
        except Exception:
            dominant_source_font_size = None
    font_size_tolerance = max(0.5, float(font_size or 0.0) * 0.02)
    force_annotation_font_size = (
        float(font_size or 0.0) > 0
        and dominant_source_font_size is not None
        and abs(float(font_size) - float(dominant_source_font_size)) > font_size_tolerance
    )
    force_annotation_underline = (
        dominant_source_underline is not None
        and annotation_underline != dominant_source_underline
    )

    def _overlap_amount(a_top: float, a_bottom: float, b_top: float, b_bottom: float) -> float:
        return max(0.0, min(a_bottom, b_bottom) - max(a_top, b_top))

    spans_by_line: list[list[Dict[str, Any]]] = [[] for _ in raw_boxes]
    for span in normalized_source_spans:
        span_top = float(span["bbox"][1])
        span_bottom = float(span["bbox"][3])
        best_index = -1
        best_score = -1.0
        for index, raw_box in enumerate(raw_boxes):
            if not isinstance(raw_box, (list, tuple)) or len(raw_box) < 4:
                continue
            line_top = float(raw_box[1])
            line_bottom = float(raw_box[3])
            overlap = _overlap_amount(span_top, span_bottom, line_top, line_bottom)
            min_height = max(1.0, min(span_bottom - span_top, line_bottom - line_top))
            score = overlap / min_height
            if score > best_score:
                best_score = score
                best_index = index
        if best_index >= 0 and best_score >= 0.15:
            spans_by_line[best_index].append(span)

    source_block_left = source_anchor_x
    source_block_right = None
    try:
        raw_source_width = ann.get("sourceBlockWidth")
        if source_block_left is not None and raw_source_width is not None:
            source_block_right = float(source_block_left) + float(raw_source_width)
    except Exception:
        source_block_right = None

    translated_source_block_left = (
        (source_block_left + translate_x)
        if source_block_left is not None
        else None
    )
    translated_source_block_right = (
        (source_block_right + translate_x)
        if source_block_right is not None
        else None
    )

    def _infer_line_align(line_rect: fitz.Rect) -> Optional[int]:
        if (
            translated_source_block_left is None
            or translated_source_block_right is None
            or translated_source_block_right <= translated_source_block_left
        ):
            return None
        left_margin = max(0.0, float(line_rect.x0) - float(translated_source_block_left))
        right_margin = max(0.0, float(translated_source_block_right) - float(line_rect.x1))
        tolerance = max(1.5, min(6.0, float(line_rect.height) * 0.5))
        if abs(left_margin - right_margin) <= tolerance:
            return 1
        if left_margin <= tolerance:
            return 0
        if right_margin <= tolerance:
            return 2
        symmetric_margin = max(left_margin, right_margin, 1.0)
        if abs(left_margin - right_margin) <= (symmetric_margin * 0.25):
            return 1
        return 0

    layout: list[Dict[str, Any]] = []
    for index, raw_box in enumerate(raw_boxes):
        if not isinstance(raw_box, (list, tuple)) or len(raw_box) < 4:
            return []
        try:
            rect = fitz.Rect(
                float(raw_box[0]),
                float(raw_box[1]),
                float(raw_box[2]),
                float(raw_box[3]),
            )
        except Exception:
            return []
        if rect.width <= 0 or rect.height <= 0:
            return []

        line_text = line_texts[index]
        line_spans = spans_by_line[index] if index < len(spans_by_line) else []
        baseline_y = None
        baseline_x = None
        line_style: Dict[str, Any] = {}
        if line_spans:
            sorted_line_spans = sorted(
                line_spans,
                key=lambda span: (
                    float(span["bbox"][0]),
                    float(span["origin"][0]) if span.get("origin") else float(span["bbox"][0]),
                ),
            )
            origins = [span["origin"][1] for span in line_spans if span.get("origin")]
            if origins:
                baseline_y = float(sum(origins) / len(origins))
            baseline_x_candidates = []
            for span in sorted_line_spans:
                origin = span.get("origin")
                if isinstance(origin, (list, tuple)) and len(origin) >= 2:
                    try:
                        baseline_x_candidates.append(float(origin[0]))
                        continue
                    except Exception:
                        pass
                try:
                    baseline_x_candidates.append(float(span["bbox"][0]))
                except Exception:
                    continue
            if baseline_x_candidates:
                baseline_x = min(baseline_x_candidates)
            dominant_span = max(
                line_spans,
                key=lambda span: max(
                    1.0,
                    float(span["bbox"][2]) - float(span["bbox"][0]),
                    len(str(span.get("text") or "")),
                ),
            )
            line_style = {
                "font_family": annotation_font_family if force_annotation_font_family else (dominant_span.get("font") or annotation_font_family),
                "font_source_name": annotation_font_source_name if force_annotation_font_family else (dominant_span.get("font") or annotation_font_source_name),
                "font_size": float(font_size or 0) if force_annotation_font_size else float(dominant_span.get("font_size") or dominant_span.get("fontSize") or font_size or 0),
                "font_weight": annotation_font_weight if force_annotation_font_weight else (dominant_span.get("font_weight") or dominant_span.get("fontWeight") or annotation_font_weight),
                "font_style": annotation_font_style if force_annotation_font_style else (dominant_span.get("font_style") or dominant_span.get("fontStyle") or annotation_font_style),
                "color": annotation_text_color if force_annotation_text_color else (dominant_span.get("color") or annotation_text_color),
                "underline": annotation_underline if force_annotation_underline else any(bool(span.get("underline")) for span in line_spans),
            }
        if translate_x != 0.0 or translate_y != 0.0:
            rect = fitz.Rect(
                rect.x0 + translate_x,
                rect.y0 + translate_y,
                rect.x1 + translate_x,
                rect.y1 + translate_y,
            )
            if baseline_x is not None:
                baseline_x += translate_x
            if baseline_y is not None:
                baseline_y += translate_y
        layout.append({
            "rect": rect,
            "text": line_text,
            "baseline_x": baseline_x,
            "baseline_y": baseline_y,
            "align": _infer_line_align(rect),
            "rotation": infer_exact_source_line_rotation(line_spans, rect),
            "font_family": line_style.get("font_family") or ann.get("fontFamily"),
            "font_source_name": line_style.get("font_source_name") or ann.get("fontSourceName") or ann.get("fontFamily"),
            "font_size": float(line_style.get("font_size") or font_size or 0),
            "font_weight": line_style.get("font_weight") or annotation_font_weight,
            "font_style": line_style.get("font_style") or annotation_font_style,
            "color": line_style.get("color") or annotation_text_color,
            "underline": bool(line_style.get("underline")) if line_style else annotation_underline,
        })

    return layout


def compute_exact_source_line_scale_x(text_width: float, target_width: float) -> float:
    try:
        measured_width = float(text_width or 0.0)
        available_width = float(target_width or 0.0)
    except Exception:
        return 1.0

    if measured_width <= 0 or available_width <= 0:
        return 1.0

    raw_ratio = available_width / measured_width
    if not math.isfinite(raw_ratio) or raw_ratio <= 0:
        return 1.0

    clamped_ratio = max(0.7, min(1.3, raw_ratio))
    return 1.0 if abs(clamped_ratio - 1.0) <= 0.015 else clamped_ratio


def normalize_quarter_turn_degrees(value: Any) -> float:
    try:
        raw_rotation = float(value or 0.0)
    except Exception:
        return 0.0
    normalized = (((raw_rotation % 360.0) + 540.0) % 360.0) - 180.0
    if abs(normalized - 90.0) <= 12.0:
        return 90.0
    if abs(normalized + 90.0) <= 12.0:
        return -90.0
    return 0.0


def infer_exact_source_rotation(
    raw_rotation: Any,
    direction: Any = None,
    rect: Optional[fitz.Rect] = None,
) -> float:
    normalized_rotation = normalize_quarter_turn_degrees(raw_rotation)
    if normalized_rotation:
        return normalized_rotation

    if isinstance(direction, (list, tuple)) and len(direction) >= 2:
        try:
            dx = float(direction[0])
            dy = float(direction[1])
        except Exception:
            dx = 0.0
            dy = 0.0
        if abs(dy) > 0.75 and abs(dx) <= 0.35:
            return -90.0 if dy < 0.0 else 90.0

    if rect is not None and not rect.is_empty and rect.height > rect.width * 1.2:
        if isinstance(direction, (list, tuple)) and len(direction) >= 2:
            try:
                dy = float(direction[1])
            except Exception:
                dy = 0.0
            if abs(dy) > 0.75:
                return -90.0 if dy < 0.0 else 90.0

    return 0.0


def infer_exact_source_line_rotation(
    spans: list[Dict[str, Any]],
    rect: Optional[fitz.Rect] = None,
) -> float:
    positive_weight = 0.0
    negative_weight = 0.0

    for span in spans or []:
        if not isinstance(span, dict):
            continue
        span_bbox = span.get("bbox")
        span_rect = None
        if isinstance(span_bbox, (list, tuple)) and len(span_bbox) >= 4:
            try:
                span_rect = fitz.Rect(
                    float(span_bbox[0]),
                    float(span_bbox[1]),
                    float(span_bbox[2]),
                    float(span_bbox[3]),
                )
            except Exception:
                span_rect = None
        rotation = infer_exact_source_rotation(
            span.get("rotation"),
            span.get("direction"),
            span_rect,
        )
        if not rotation:
            continue
        weight = max(
            1.0,
            float(span_rect.width) if isinstance(span_rect, fitz.Rect) else 0.0,
            float(span_rect.height) if isinstance(span_rect, fitz.Rect) else 0.0,
            float(len(str(span.get("text") or "").replace(" ", ""))),
        )
        if rotation > 0:
            positive_weight += weight
        else:
            negative_weight += weight

    if positive_weight or negative_weight:
        return 90.0 if positive_weight >= negative_weight else -90.0

    return infer_exact_source_rotation(None, None, rect)


def _apply_linear_matrix(matrix: fitz.Matrix, x: float, y: float) -> tuple[float, float]:
    return (
        (float(getattr(matrix, "a", 1.0) or 0.0) * x) + (float(getattr(matrix, "c", 0.0) or 0.0) * y),
        (float(getattr(matrix, "b", 0.0) or 0.0) * x) + (float(getattr(matrix, "d", 1.0) or 0.0) * y),
    )


def combine_morphs(
    first: Optional[Tuple[fitz.Point, fitz.Matrix]],
    second: Optional[Tuple[fitz.Point, fitz.Matrix]],
) -> Optional[Tuple[fitz.Point, fitz.Matrix]]:
    if first is None:
        return second
    if second is None:
        return first

    first_pivot, first_matrix = first
    second_pivot, second_matrix = second

    composed_a = (second_matrix.a * first_matrix.a) + (second_matrix.c * first_matrix.b)
    composed_b = (second_matrix.b * first_matrix.a) + (second_matrix.d * first_matrix.b)
    composed_c = (second_matrix.a * first_matrix.c) + (second_matrix.c * first_matrix.d)
    composed_d = (second_matrix.b * first_matrix.c) + (second_matrix.d * first_matrix.d)

    first_offset = (
        float(first_pivot.x) - ((first_matrix.a * float(first_pivot.x)) + (first_matrix.c * float(first_pivot.y))),
        float(first_pivot.y) - ((first_matrix.b * float(first_pivot.x)) + (first_matrix.d * float(first_pivot.y))),
    )
    transformed_first_offset = _apply_linear_matrix(second_matrix, first_offset[0], first_offset[1])
    second_offset = (
        float(second_pivot.x) - ((second_matrix.a * float(second_pivot.x)) + (second_matrix.c * float(second_pivot.y))),
        float(second_pivot.y) - ((second_matrix.b * float(second_pivot.x)) + (second_matrix.d * float(second_pivot.y))),
    )
    combined_offset = (
        transformed_first_offset[0] + second_offset[0],
        transformed_first_offset[1] + second_offset[1],
    )

    coeff_a = 1.0 - composed_a
    coeff_b = -composed_c
    coeff_c = -composed_b
    coeff_d = 1.0 - composed_d
    determinant = (coeff_a * coeff_d) - (coeff_b * coeff_c)

    if abs(determinant) <= 1e-9:
        if (
            abs(composed_a - 1.0) <= 1e-9
            and abs(composed_b) <= 1e-9
            and abs(composed_c) <= 1e-9
            and abs(composed_d - 1.0) <= 1e-9
            and abs(combined_offset[0]) <= 1e-6
            and abs(combined_offset[1]) <= 1e-6
        ):
            return None
        return second

    pivot_x = ((coeff_d * combined_offset[0]) - (coeff_b * combined_offset[1])) / determinant
    pivot_y = ((-coeff_c * combined_offset[0]) + (coeff_a * combined_offset[1])) / determinant

    return (
        fitz.Point(pivot_x, pivot_y),
        fitz.Matrix(composed_a, composed_b, composed_c, composed_d, 0.0, 0.0),
    )


def draw_text_using_exact_source_lines(
    page: fitz.Page,
    ann: Dict[str, Any],
    lines: list[Dict[str, Any]],
    font: fitz.Font,
    fontname: str,
    font_size: float,
    color: tuple[float, float, float],
    opacity: float,
    align: int,
    morph: Optional[Tuple[fitz.Point, fitz.Matrix]] = None,
) -> bool:
    if not lines:
        return False

    for line_entry in lines:
        if not isinstance(line_entry, dict):
            continue
        line_rect = line_entry.get("rect")
        line_text = sanitize_pdf_text(line_entry.get("text"))
        if not isinstance(line_rect, fitz.Rect):
            continue
        if not line_text:
            continue

        line_ann = dict(ann)
        line_ann["fontFamily"] = line_entry.get("font_family") or ann.get("fontFamily")
        line_ann["fontSourceName"] = line_entry.get("font_source_name") or ann.get("fontSourceName") or line_ann["fontFamily"]
        line_ann["fontWeight"] = line_entry.get("font_weight") or ann.get("fontWeight")
        line_ann["fontStyle"] = line_entry.get("font_style") or ann.get("fontStyle")
        if line_entry.get("underline") is not None:
            line_ann["underline"] = bool(line_entry.get("underline"))

        line_font_size = float(line_entry.get("font_size") or font_size or 0)
        if line_font_size <= 0:
            line_font_size = font_size

        line_fontfile = resolve_text_fontfile(line_ann)
        if line_fontfile:
            line_font = fitz.Font(fontfile=line_fontfile)
            line_fontname = resolve_text_font_resource_name(line_ann)
            page.insert_font(fontname=line_fontname, fontfile=line_fontfile)
        else:
            resolved_fontname = resolve_text_fontname(line_ann)
            if resolved_fontname != fontname or line_font_size != font_size:
                line_fontname = resolved_fontname
                line_font = fitz.Font(line_fontname)
            else:
                line_fontname = fontname
                line_font = font

        line_color = hex_to_rgb(line_entry.get("color") or ann.get("textColor") or "#000000")
        line_align = line_entry.get("align")
        if line_align is None:
            line_align = align
        line_rotation = normalize_quarter_turn_degrees(line_entry.get("rotation"))

        text_width = line_font.text_length(line_text, fontsize=line_font_size)
        line_font_ascender = float(getattr(line_font, "ascender", 0.0) or 0.0)
        if line_font_ascender <= 0:
            line_font_ascender = 0.8

        # Use insert_text with an explicit baseline point instead of insert_textbox.
        # insert_textbox clips at both rect edges — if the substitute font's glyph
        # metrics differ even slightly from the original, the tops of capital letters
        # get silently shaved off at line_rect.y0.  insert_text places text at a
        # point and never clips vertically.
        baseline_y = (
            float(line_entry.get("baseline_y"))
            if line_entry.get("baseline_y") is not None
            else (line_rect.y0 + line_font_ascender * line_font_size)
        )
        draw_x = (
            float(line_entry.get("baseline_x"))
            if line_entry.get("baseline_x") is not None
            else line_rect.x0
        )
        if line_entry.get("baseline_x") is None:
            target_extent = line_rect.height if line_rotation else line_rect.width
            scaled_text_width = text_width * compute_exact_source_line_scale_x(text_width, target_extent)
            if line_align == 1:
                draw_x = ((line_rect.x0 + line_rect.x1) / 2.0) - (scaled_text_width / 2.0)
            elif line_align == 2:
                draw_x = line_rect.x1 - scaled_text_width

        target_extent = line_rect.height if line_rotation else line_rect.width
        scale_x = compute_exact_source_line_scale_x(text_width, target_extent)
        line_morph: Optional[Tuple[fitz.Point, fitz.Matrix]] = None
        if scale_x != 1.0:
            scale_morph = (
                fitz.Point(draw_x, baseline_y),
                fitz.Matrix(scale_x, 0.0, 0.0, 1.0, 0.0, 0.0),
            )
            line_morph = scale_morph
        if line_rotation:
            line_morph = combine_morphs(
                line_morph,
                build_rotation_morph(line_rotation, fitz.Point(draw_x, baseline_y)),
            )
        line_morph = combine_morphs(line_morph, morph)

        page.insert_text(
            fitz.Point(draw_x, baseline_y),
            line_text,
            fontsize=line_font_size,
            fontname=line_fontname,
            color=line_color,
            overlay=True,
            fill_opacity=opacity,
            stroke_opacity=opacity,
            morph=line_morph,
        )

        if resolve_annotation_underline(line_ann):
            underline_y = line_rect.y1 - max(0.5, line_font_size * 0.08)
            underline_width = text_width * scale_x
            draw_rotated_line(
                page,
                fitz.Point(draw_x, underline_y),
                fitz.Point(draw_x + underline_width, underline_y),
                color=line_color,
                width=max(0.5, line_font_size * 0.06),
                opacity=opacity,
                morph=line_morph,
            )

    return True


def resolve_text_fontname(ann: Dict[str, Any]) -> str:
    family = normalize_font_family(ann.get("fontFamily"))
    variants = PDF_FONT_VARIANTS.get(family, PDF_FONT_VARIANTS["Helvetica"])
    is_bold = is_bold_weight(resolve_annotation_font_weight(ann))
    is_italic = is_italic_style(resolve_annotation_font_style(ann))
    if is_bold and is_italic:
        return variants["boldItalic"]
    if is_bold:
        return variants["bold"]
    if is_italic:
        return variants["italic"]
    return variants["normal"]


def resolve_text_fontfile(ann: Dict[str, Any]) -> Optional[str]:
    embedded_entry = resolve_embedded_font_entry(ann)
    if embedded_entry:
        return embedded_entry.get("fontfile")
    family = normalize_font_family(ann.get("fontFamily"))
    variants = FONT_FILE_VARIANTS.get(family, FONT_FILE_VARIANTS["Helvetica"])
    is_bold = is_bold_weight(resolve_annotation_font_weight(ann))
    is_italic = is_italic_style(resolve_annotation_font_style(ann))
    if is_bold and is_italic:
        candidate = variants["boldItalic"]
    elif is_bold:
        candidate = variants["bold"]
    elif is_italic:
        candidate = variants["italic"]
    else:
        candidate = variants["normal"]
    if family in {"TrebuchetMS", "Verdana"} and is_bold and is_italic:
        bold_italic_candidate = variants.get("boldItalic")
        italic_candidate = variants.get("italic")
        if (
            not bold_italic_candidate
            or bold_italic_candidate == italic_candidate
        ):
            return None
    if family == "Courier" and is_italic:
        return None
    if family == "Helvetica" and is_italic:
        return None
    return candidate if os.path.exists(candidate) else None


def resolve_text_font_resource_name(ann: Dict[str, Any]) -> str:
    embedded_entry = resolve_embedded_font_entry(ann)
    if embedded_entry:
        clean_name = str(embedded_entry.get("clean_name") or "Embedded").strip()
        safe_name = "".join(ch for ch in clean_name if ch.isalnum()) or "Embedded"
        return f"Annot{safe_name}"
    family = normalize_font_family(ann.get("fontFamily"))
    is_bold = is_bold_weight(resolve_annotation_font_weight(ann))
    is_italic = is_italic_style(resolve_annotation_font_style(ann))
    suffix = "Regular"
    if is_bold and is_italic:
        suffix = "BoldItalic"
    elif is_bold:
        suffix = "Bold"
    elif is_italic:
        suffix = "Italic"
    return f"Annot{family}{suffix}"


def parse_text_align(value: Any) -> int:
    lower = str(value or "left").strip().lower()
    if lower == "center":
        return 1
    if lower == "right":
        return 2
    if lower == "justify":
        return 3
    return 0


def normalized_opacity(value: Any) -> float:
    try:
        opacity = float(value)
    except Exception:
        opacity = 1.0
    return max(0.0, min(1.0, opacity))


def wrap_text_to_width(font: fitz.Font, text: str, font_size: float, max_width: float) -> list[str]:
    # Mirror the edit-new.blade.php renderer (buildEditedLines / renderPlainEditorHTML):
    # single "\n" is a soft break that reflows to a space; only "\n\n+" is a hard
    # paragraph break that yields a visible blank line. Otherwise the downloaded PDF
    # shows paragraph gaps between every wrapped source line.
    safe_text = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    normalized = safe_text.replace("\n\n", "\x00")
    while "\n\x00" in normalized:
        normalized = normalized.replace("\n\x00", "\x00")
    while "\x00\n" in normalized:
        normalized = normalized.replace("\x00\n", "\x00")
    normalized = normalized.replace("\n", " ").replace("\x00", "\n")
    paragraphs = normalized.split("\n")
    lines: list[str] = []

    def push_wrapped_word(word: str) -> None:
        segment = ""
        for char in word:
            candidate = segment + char
            if not segment or font.text_length(candidate, fontsize=font_size) <= max_width:
                segment = candidate
                continue
            lines.append(segment)
            segment = char
        if segment:
            lines.append(segment)

    for idx, paragraph in enumerate(paragraphs):
        words = [word for word in paragraph.split() if word]
        if not words:
            lines.append("")
        else:
            current_line = ""
            for word in words:
                candidate = f"{current_line} {word}".strip() if current_line else word
                if font.text_length(candidate, fontsize=font_size) <= max_width:
                    current_line = candidate
                    continue

                if current_line:
                    lines.append(current_line)
                    current_line = ""

                if font.text_length(word, fontsize=font_size) <= max_width:
                    current_line = word
                else:
                    push_wrapped_word(word)

            if current_line:
                lines.append(current_line)

        if idx < len(paragraphs) - 1:
            lines.append("")

    return lines or [""]


def split_text_preserving_manual_line_breaks(text: str) -> list[str]:
    normalized = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    return normalized.split("\n")


def should_preserve_pdfjs_source_visual_spacing(ann: Dict[str, Any], text: str) -> bool:
    if not bool(ann.get("savedTextOverlay")):
        return False
    if not bool(ann.get("pdfjsSourceFidelity")):
        return False
    if bool(ann.get("userForcedRichText")) or str(ann.get("pdfjsEditorMode") or "").lower() == "rich":
        return False
    if "\n" in str(text or "").replace("\r\n", "\n").replace("\r", "\n"):
        return False
    return bool(re.search(r" {2,}", str(text or "")))


def normalize_rotation_degrees(value: Any) -> float:
    try:
        rotation = float(value or 0.0)
    except Exception:
        return 0.0
    rotation %= 360.0
    return 0.0 if abs(rotation) < 1e-6 else rotation


def build_rotation_morph(
    rotation: float,
    pivot: Optional[fitz.Point],
) -> Optional[Tuple[fitz.Point, fitz.Matrix]]:
    if pivot is None or abs(rotation) < 1e-6:
        return None
    rad = math.radians(rotation)
    cos_r = math.cos(rad)
    sin_r = math.sin(rad)
    return (
        pivot,
        fitz.Matrix(cos_r, -sin_r, sin_r, cos_r, 0.0, 0.0),
    )


def draw_rotated_rect(
    page: fitz.Page,
    rect: fitz.Rect,
    *,
    fill: Optional[tuple[float, float, float]] = None,
    color: Optional[tuple[float, float, float]] = None,
    width: float = 0.0,
    opacity: float = 1.0,
    morph: Optional[Tuple[fitz.Point, fitz.Matrix]] = None,
) -> None:
    shape = page.new_shape()
    shape.draw_rect(rect)
    finish_kwargs = {
        "color": color,
        "fill": fill,
        "width": width,
    }
    if color is not None:
        finish_kwargs["stroke_opacity"] = opacity
    if fill is not None:
        finish_kwargs["fill_opacity"] = opacity
    if morph is not None:
        finish_kwargs["morph"] = morph
    shape.finish(**finish_kwargs)
    shape.commit(overlay=True)


def draw_rotated_line(
    page: fitz.Page,
    start: fitz.Point,
    end: fitz.Point,
    *,
    color: tuple[float, float, float],
    width: float,
    opacity: float,
    morph: Optional[Tuple[fitz.Point, fitz.Matrix]] = None,
) -> None:
    shape = page.new_shape()
    shape.draw_line(start, end)
    finish_kwargs = {
        "color": color,
        "width": width,
        "lineCap": 1,
        "stroke_opacity": opacity,
    }
    if morph is not None:
        finish_kwargs["morph"] = morph
    shape.finish(**finish_kwargs)
    shape.commit(overlay=True)


def erase_promoted_source_text_region(
    page: fitz.Page,
    ann: Dict[str, Any],
    current_rect: fitz.Rect,
    lines: list[Dict[str, Any]],
    morph: Optional[Tuple[fitz.Point, fitz.Matrix]] = None,
) -> None:
    if bool(ann.get("skipPromotedSourceErase")):
        return
    if not bool(ann.get("promotedFromExtraction")) or not bool(ann.get("promotedDirty")):
        return
    if current_rect.is_empty:
        return

    fill = (1.0, 1.0, 1.0)
    background = str(ann.get("backgroundColor") or "").strip().lower()
    if background and background != "transparent":
        fill = hex_to_rgb(background)

    if not lines:
        draw_rotated_rect(page, current_rect, fill=fill, color=None, width=0, opacity=1.0, morph=morph)
        return

    for line_entry in lines:
        line_rect = line_entry.get("rect")
        if not isinstance(line_rect, fitz.Rect):
            continue
        erase_rect = fitz.Rect(
            current_rect.x0,
            max(page.rect.y0, line_rect.y0 - 0.75),
            current_rect.x1,
            min(page.rect.y1, line_rect.y1 + 0.75),
        )
        if erase_rect.is_empty:
            continue
        draw_rotated_rect(page, erase_rect, fill=fill, color=None, width=0, opacity=1.0, morph=morph)


def draw_shape(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = to_rect(page, ann)
    if rect is None:
        return

    shape_type = (ann.get("shapeType") or "rect").lower()
    opacity = float(ann.get("opacity", 1.0) or 1.0)
    stroke_width = float(ann.get("strokeWidth", 2.0) or 2.0)
    rotation = float(ann.get("rotation", 0.0) or 0.0)

    def _clamp01(value: Any, fallback: float) -> float:
        try:
            n = float(value)
        except Exception:
            return fallback
        if n < 0.0:
            return 0.0
        if n > 1.0:
            return 1.0
        return n

    stroke_opacity = _clamp01(ann.get("strokeOpacity", opacity), opacity)
    fill_opacity = _clamp01(ann.get("fillOpacity", opacity), opacity)

    stroke = None if ann.get("strokeTransparent") else hex_to_rgb(ann.get("strokeColor") or "#000000")
    fill = None if ann.get("fillTransparent") else hex_to_rgb(ann.get("fillColor") or "#ffffff")

    if stroke is None and fill is None:
        return

    cx = (rect.x0 + rect.x1) / 2.0
    cy = (rect.y0 + rect.y1) / 2.0
    rad = math.radians(rotation)
    cos_r = math.cos(rad)
    sin_r = math.sin(rad)

    def rp(nx: float, ny: float) -> fitz.Point:
        x = rect.x0 + nx * rect.width
        y = rect.y0 + ny * rect.height
        if abs(rotation) < 1e-6:
            return fitz.Point(x, y)
        dx = x - cx
        dy = y - cy
        # Y axis points down; this matrix matches frontend CSS clockwise rotation.
        xr = cx + (dx * cos_r - dy * sin_r)
        yr = cy + (dx * sin_r + dy * cos_r)
        return fitz.Point(xr, yr)

    def draw_open_lines(lines) -> None:
        if stroke is None:
            return
        s = page.new_shape()
        for p1, p2 in lines:
            s.draw_line(p1, p2)
        # PDF line cap style: 0=butt, 1=round, 2=square. Default to round
        # to match the editor's default ctx.lineCap, but honor an explicit
        # `lineCap` override coming from the annotation payload.
        cap_value = (ann.get("lineCap") or "round")
        cap_lookup = {"butt": 0, "round": 1, "square": 2}
        line_cap = cap_lookup.get(str(cap_value).lower(), 1)
        s.finish(color=stroke, width=stroke_width, lineCap=line_cap, stroke_opacity=stroke_opacity)
        s.commit(overlay=True)

    def draw_poly(points, close_path=True, line_join=1) -> None:
        s = page.new_shape()
        s.draw_polyline(points)
        s.finish(
            color=stroke,
            fill=fill if close_path else None,
            width=stroke_width,
            closePath=close_path,
            lineCap=1,
            lineJoin=line_join,
            stroke_opacity=stroke_opacity,
            fill_opacity=fill_opacity,
        )
        s.commit(overlay=True)

    def clamp_line_unit_interval(value: Any, fallback: float) -> float:
        try:
            numeric = float(value)
        except Exception:
            numeric = fallback
        return max(0.0, min(1.0, numeric))

    if shape_type in ("circle", "ellipse"):
        # Draw as a rotated polygon approximation so rotation persists in direct-save mode.
        steps = 48
        points = []
        for i in range(steps):
            a = (2.0 * math.pi * i) / steps
            px = 0.5 + 0.48 * math.cos(a)
            py = 0.5 + 0.48 * math.sin(a)
            points.append(rp(px, py))
        points.append(points[0])
        draw_poly(points, close_path=True, line_join=1)
        return

    if shape_type == "triangle":
        draw_poly([rp(0.5, 0.05), rp(0.95, 0.95), rp(0.05, 0.95), rp(0.5, 0.05)], close_path=True, line_join=1)
        return

    if shape_type == "arrow":
        draw_open_lines([
            (rp(0.10, 0.50), rp(0.80, 0.50)),
            (rp(0.65, 0.35), rp(0.80, 0.50)),
            (rp(0.65, 0.65), rp(0.80, 0.50)),
        ])
        return

    if shape_type == "x":
        draw_open_lines([
            (rp(0.15, 0.15), rp(0.85, 0.85)),
            (rp(0.85, 0.15), rp(0.15, 0.85)),
        ])
        return

    if shape_type == "checkmark":
        s = page.new_shape()
        p1 = rp(0.15, 0.50)
        p2 = rp(0.40, 0.75)
        p3 = rp(0.85, 0.15)
        s.draw_polyline([p1, p2, p3])
        s.finish(color=stroke, fill=None, width=stroke_width, lineCap=1, lineJoin=1, stroke_opacity=stroke_opacity)
        s.commit(overlay=True)
        return

    if shape_type == "heart":
        s = page.new_shape()
        s.draw_bezier(rp(0.50, 0.90), rp(0.18, 0.66), rp(0.06, 0.48), rp(0.06, 0.30))
        s.draw_bezier(rp(0.06, 0.30), rp(0.06, 0.16), rp(0.17, 0.06), rp(0.31, 0.06))
        s.draw_bezier(rp(0.31, 0.06), rp(0.40, 0.06), rp(0.47, 0.11), rp(0.50, 0.18))
        s.draw_bezier(rp(0.50, 0.18), rp(0.53, 0.11), rp(0.60, 0.06), rp(0.69, 0.06))
        s.draw_bezier(rp(0.69, 0.06), rp(0.83, 0.06), rp(0.94, 0.16), rp(0.94, 0.30))
        s.draw_bezier(rp(0.94, 0.30), rp(0.94, 0.48), rp(0.82, 0.66), rp(0.50, 0.90))
        s.finish(color=stroke, fill=fill, width=stroke_width, closePath=True, lineCap=1, lineJoin=1, stroke_opacity=stroke_opacity, fill_opacity=fill_opacity)
        s.commit(overlay=True)
        return

    if shape_type == "star":
        # Match the frontend canvas star: outerRadius = min(w,h)/2 inscribed in
        # the annotation bounding box, innerRadius = outerRadius * 0.45, first
        # point at the top (angle -pi/2), 10 vertices alternating outer/inner.
        cx_n, cy_n = 0.5, 0.5
        rect_w = max(1e-6, rect.width)
        rect_h = max(1e-6, rect.height)
        min_dim = min(rect_w, rect_h)
        outer_x = (min_dim / 2.0) / rect_w
        outer_y = (min_dim / 2.0) / rect_h
        inner_ratio = 0.45
        points = []
        for i in range(10):
            r_x = outer_x if i % 2 == 0 else outer_x * inner_ratio
            r_y = outer_y if i % 2 == 0 else outer_y * inner_ratio
            angle = (-math.pi / 2.0) + (i * math.pi / 5.0)
            px = cx_n + math.cos(angle) * r_x
            py = cy_n + math.sin(angle) * r_y
            points.append(rp(px, py))
        points.append(points[0])
        draw_poly(points, close_path=True, line_join=1)
        return

    if shape_type == "polygon":
        points = [rp(0.50, 0.05), rp(0.90, 0.27), rp(0.90, 0.73), rp(0.50, 0.95), rp(0.10, 0.73), rp(0.10, 0.27)]
        points.append(points[0])
        draw_poly(points, close_path=True, line_join=1)
        return

    if shape_type == "line":
        line_start_x = clamp_line_unit_interval(ann.get("lineStartX"), 0.0)
        line_start_y = clamp_line_unit_interval(ann.get("lineStartY"), 1.0)
        line_end_x = clamp_line_unit_interval(ann.get("lineEndX"), 1.0)
        line_end_y = clamp_line_unit_interval(ann.get("lineEndY"), 0.0)
        draw_open_lines([(rp(line_start_x, line_start_y), rp(line_end_x, line_end_y))])
        return

    # Default rectangle: use the full annotation bounds. The editor stores the
    # square/rectangle box as the intended final geometry; insetting by 5% on
    # each side shrinks exported bars relative to the DOM preview.
    points = [rp(0.0, 0.0), rp(1.0, 0.0), rp(1.0, 1.0), rp(0.0, 1.0), rp(0.0, 0.0)]
    draw_poly(points, close_path=True, line_join=1)


def draw_table(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = to_rect(page, ann)
    if rect is None:
        return

    rows = max(1, int(ann.get("rows", 1) or 1))
    cols = max(1, int(ann.get("cols", 1) or 1))
    opacity = float(ann.get("opacity", 1.0) or 1.0)
    stroke_width = float(ann.get("strokeWidth", 1.0) or 1.0)
    stroke = hex_to_rgb(ann.get("strokeColor") or "#000000")
    fill = None if ann.get("fillTransparent") else hex_to_rgb(ann.get("fillColor") or "#ffffff")

    # Draw cell fill first
    if fill is not None:
        s = page.new_shape()
        s.draw_rect(rect)
        s.finish(color=None, fill=fill, width=0, fill_opacity=opacity)
        s.commit(overlay=True)

    # Draw outer rectangle (optional) + internal grid lines
    s = page.new_shape()
    outer_border = ann.get("outerBorder", True)
    if outer_border is not False and str(outer_border).lower() not in ("false", "0"):
        s.draw_rect(rect)
    cell_h = rect.height / rows
    for i in range(1, rows):
        y = rect.y0 + i * cell_h
        s.draw_line(fitz.Point(rect.x0, y), fitz.Point(rect.x1, y))
    cell_w = rect.width / cols
    for j in range(1, cols):
        x = rect.x0 + j * cell_w
        s.draw_line(fitz.Point(x, rect.y0), fitz.Point(x, rect.y1))
    s.finish(color=stroke, fill=None, width=stroke_width, lineCap=1, stroke_opacity=opacity)
    s.commit(overlay=True)


def draw_eraser(page: fitz.Page, ann: Dict[str, Any]) -> None:
    ph = page.rect.height
    rects = ann.get("strokeRects") or []
    if isinstance(rects, list) and rects:
        for r in rects:
            try:
                x = float(r.get("x", 0))
                y = float(r.get("y", 0))
                w = float(r.get("w", 0))
                h = float(r.get("h", 0))
            except Exception:
                continue
            if w <= 0 or h <= 0:
                continue
            rr = fitz.Rect(x, ph - (y + h), x + w, ph - y)
            page.draw_rect(rr, color=None, fill=(1, 1, 1), width=0, overlay=True)
        return

    rect = to_rect(page, ann)
    if rect is not None:
        page.draw_rect(rect, color=None, fill=(1, 1, 1), width=0, overlay=True)


def resolve_promoted_source_text_color(ann: Dict[str, Any]) -> str:
    if not bool(ann.get("promotedFromExtraction")) or bool(ann.get("styleDirty")):
        return str(ann.get("textColor") or "#000000")
    source_spans = ann.get("sourceSpans")
    if not isinstance(source_spans, list) or not source_spans:
        return str(ann.get("textColor") or "#000000")
    dominant_span = None
    dominant_score = -1.0
    for span in source_spans:
        if not isinstance(span, dict):
            continue
        bbox = span.get("bbox")
        try:
            width = max(0.0, float(bbox[2]) - float(bbox[0])) if isinstance(bbox, (list, tuple)) and len(bbox) >= 4 else 0.0
        except Exception:
            width = 0.0
        text_weight = float(len(sanitize_pdf_text(span.get("text") or "").replace(" ", "")) or 1)
        score = max(width, text_weight)
        if score > dominant_score:
            dominant_score = score
            dominant_span = span
    if not isinstance(dominant_span, dict):
        return str(ann.get("textColor") or "#000000")
    source_color = (
        dominant_span.get("hex_color")
        if dominant_span.get("hex_color") is not None
        else dominant_span.get("color")
    )
    return str(source_color or ann.get("textColor") or "#000000")


def draw_text(page: fitz.Page, ann: Dict[str, Any]) -> None:
    text = sanitize_pdf_text(ann.get("text") or "")
    if not text:
        return
    # Always use `fontSize` (PDF points, set by the editor as px/currentScale) rather than
    # `requestedFontSize` which stores the browser screen-pixel value (fontSize × zoom factor)
    # and must not be treated as PDF points.
    size = float(ann.get("fontSize", 12) or 12)
    try:
        line_height = float(ann.get("lineHeight") or 0)
    except Exception:
        line_height = 0.0
    if line_height <= 0:
        line_height = size * 1.2
    color = hex_to_rgb(resolve_promoted_source_text_color(ann))
    background = str(ann.get("backgroundColor") or "").strip().lower()
    background_color = None if not background or background == "transparent" else hex_to_rgb(background)
    opacity = normalized_opacity(ann.get("opacity", 1.0) or 1.0)
    rotation = normalize_rotation_degrees(ann.get("rotation", 0.0))
    fontname = resolve_text_fontname(ann)
    align = parse_text_align(ann.get("textAlign"))
    preserve_extracted_lines = should_preserve_promoted_source_lines(ann, text)
    rect = to_rect(page, ann)
    custom_font = None
    html_archive = None
    embedded_font_entry = resolve_embedded_font_entry(ann)
    prefer_pdf_font_text_rendering = should_prefer_pdf_font_text_rendering(ann, embedded_font_entry, text)
    fontfile = None if prefer_pdf_font_text_rendering else resolve_text_fontfile(ann)
    if fontfile:
        try:
            custom_font = fitz.Font(fontfile=fontfile)
            fontname = resolve_text_font_resource_name(ann)
            page.insert_font(fontname=fontname, fontfile=fontfile)
        except Exception:
            custom_font = None
            fontname = resolve_text_fontname(ann)
    preserve_pdfjs_visual_spacing = should_preserve_pdfjs_source_visual_spacing(ann, text)
    if rect is not None and not rect.is_empty:
        pivot = fitz.Point((rect.x0 + rect.x1) / 2.0, (rect.y0 + rect.y1) / 2.0)
        morph = build_rotation_morph(rotation, pivot)
        if background_color is not None:
            draw_rotated_rect(
                page,
                rect,
                color=None,
                fill=background_color,
                width=0,
                opacity=opacity,
                morph=morph,
            )
        draw_font = custom_font or fitz.Font(fontname)
        exact_source_line_layout = normalize_exact_source_line_layout(
            ann,
            text,
            draw_font,
            size,
            current_rect=rect,
        ) if preserve_extracted_lines else []
        if exact_source_line_layout:
            erase_promoted_source_text_region(page, ann, rect, exact_source_line_layout, morph)
        if exact_source_line_layout and draw_text_using_exact_source_lines(
            page,
            ann,
            exact_source_line_layout,
            draw_font,
            fontname,
            size,
            color,
            opacity,
            align,
            morph,
        ):
            return
        if (
            abs(rotation) < 1e-6
            and should_use_htmlbox_for_text(ann, embedded_font_entry)
            and not prefer_pdf_font_text_rendering
        ):
            try:
                html_archive = fitz.Archive(FONT_DIR)
            except Exception:
                html_archive = None
        preview_font = custom_font or fitz.Font(fontname)
        preview_padding_x = 0.0 if (preserve_extracted_lines or preserve_pdfjs_visual_spacing) else min(6.0, max(1.0, rect.width * 0.03))
        preview_padding_top = 0.0 if (preserve_extracted_lines or preserve_pdfjs_visual_spacing) else min(2.0, max(0.5, rect.height * 0.01))
        preview_available_width = max(1.0, rect.width - (preview_padding_x * 2.0))
        explicit_text_lines = split_text_preserving_manual_line_breaks(text)
        preview_lines = (
            explicit_text_lines
            if (preserve_extracted_lines or preserve_pdfjs_visual_spacing or len(explicit_text_lines) > 1)
            else wrap_text_to_width(preview_font, text, size, preview_available_width)
        )
        ascender, descender = resolve_font_vertical_metrics(preview_font)
        effective_line_height = line_height if line_height > 0 else (size * (ascender + descender))
        text_rect = expanded_text_rect(
            page,
            rect,
            len(preview_lines),
            effective_line_height,
            font_size=size,
            font=preview_font,
            padding_top=preview_padding_top,
            padding_bottom=max(1.0, size * descender * 0.35),
        )
        if html_archive is not None:
            padding_x = 0.0 if preserve_extracted_lines else 6.0
            padding_y = 0.0 if preserve_extracted_lines else 2.0
            # For promoted-extraction annotations, text must never be clipped on
            # the right: the CSS uses white-space:pre / no wrapping, so if the
            # render font is slightly wider than the source PDF font the last
            # character(s) get silently cut off even though spare_height stays >= 0
            # (no vertical overflow).  Extend x1 to the page right-edge so the
            # htmlbox always has enough room.
            inner_x1 = (
                page.rect.x1
                if preserve_extracted_lines
                else text_rect.x1 - padding_x
            )
            inner_rect = fitz.Rect(
                text_rect.x0 + padding_x,
                text_rect.y0 + padding_y,
                inner_x1,
                text_rect.y1 - padding_y,
            )
            if inner_rect.width > 1 and inner_rect.height > 1:
                html_box = build_annotation_htmlbox_markup(ann, text)
                html_css = build_annotation_htmlbox_css(ann, size, opacity)
                # See apply_annotations_direct_new.py for the rationale: when
                # rich HTML is present we must commit to insert_htmlbox or
                # the per-span styling is silently dropped by the fallback.
                has_rich_html = bool(
                    sanitize_rich_text_html(ann.get("richTextHtml") or "").strip()
                )
                effective_scale_low = 0.1 if has_rich_html else 1
                try:
                    spare_height, scale = page.insert_htmlbox(
                        inner_rect,
                        html_box,
                        css=html_css,
                        archive=html_archive,
                        scale_low=effective_scale_low,
                        opacity=opacity,
                        overlay=True,
                    )
                    if spare_height >= -0.001 and scale >= 0.999:
                        return
                    if has_rich_html and scale > 0:
                        return
                except Exception:
                    pass
        draw_font = preview_font
        padding_x = preview_padding_x
        padding_top = preview_padding_top
        available_width = preview_available_width
        lines = preview_lines
        baseline_y = text_rect.y0 + padding_top + (size * ascender)

        for line_index, line in enumerate(lines):
            line_baseline_y = baseline_y + (line_index * effective_line_height)
            if (line_baseline_y + (size * descender)) > text_rect.y1:
                break

            text_width = draw_font.text_length(line, fontsize=size) if line else 0.0
            line_scale_x = 1.0
            if preserve_pdfjs_visual_spacing and text_width > 0 and available_width > 0:
                raw_line_scale_x = available_width / text_width
                if math.isfinite(raw_line_scale_x) and raw_line_scale_x > 0:
                    line_scale_x = max(0.1, min(3.0, raw_line_scale_x))
                    if abs(line_scale_x - 1.0) <= 0.015:
                        line_scale_x = 1.0
            scaled_text_width = text_width * line_scale_x
            draw_x = text_rect.x0 + padding_x
            if align == 1:
                draw_x = text_rect.x0 + padding_x + max(0.0, (available_width - scaled_text_width) / 2.0)
            elif align == 2:
                draw_x = text_rect.x1 - padding_x - scaled_text_width

            line_morph = morph
            if line_scale_x != 1.0:
                line_morph = combine_morphs(
                    (
                        fitz.Point(draw_x, line_baseline_y),
                        fitz.Matrix(line_scale_x, 0.0, 0.0, 1.0, 0.0, 0.0),
                    ),
                    morph,
                )

            page.insert_text(
                fitz.Point(draw_x, line_baseline_y),
                sanitize_pdf_text(line),
                fontsize=size,
                fontname=fontname,
                color=color,
                overlay=True,
                fill_opacity=opacity,
                stroke_opacity=opacity,
                morph=line_morph,
            )

            if resolve_annotation_underline(ann) and line:
                underline_y = min(text_rect.y1 - max(0.5, size * 0.08), line_baseline_y + max(0.5, size * 0.08))
                draw_rotated_line(
                    page,
                    fitz.Point(draw_x, underline_y),
                    fitz.Point(draw_x + scaled_text_width, underline_y),
                    color=color,
                    width=max(0.5, size * 0.06),
                    opacity=opacity,
                    morph=line_morph,
                )
        return

    # Fallback for text annotations that do not include pdfWidth/pdfHeight
    # (keepBounds=false), or annotations where rect is zero-size.
    # Frontend stores pdfX/pdfY in bottom-origin coordinates (pdf-lib style).
    # Convert to top-origin for PyMuPDF and place baseline from font size.
    try:
        x = float(ann.get("pdfX", 0))
        y_pdf = float(ann.get("pdfY", 0))
    except Exception:
        return
    if x < 0:
        x = 0
    ph = page.rect.height
    y_top = ph - y_pdf
    baseline_y = y_top + size
    left_padding = 6.0
    fallback_font = custom_font or fitz.Font(fontname)
    ascender, descender = resolve_font_vertical_metrics(fallback_font)
    text_width = max(1.0, fallback_font.text_length(text, fontsize=size))
    draw_x = x + left_padding
    if align == 1:
        draw_x -= text_width / 2.0
    elif align == 2:
        draw_x -= text_width
    text_rect = fitz.Rect(
        draw_x - left_padding,
        baseline_y - (size * ascender),
        draw_x + text_width + left_padding,
        baseline_y + (size * descender),
    )
    pivot = fitz.Point((text_rect.x0 + text_rect.x1) / 2.0, (text_rect.y0 + text_rect.y1) / 2.0)
    morph = build_rotation_morph(rotation, pivot)
    if background_color is not None:
        if not text_rect.is_empty:
            draw_rotated_rect(
                page,
                text_rect,
                color=None,
                fill=background_color,
                width=0,
                opacity=opacity,
                morph=morph,
            )
    page.insert_text(
        fitz.Point(draw_x, baseline_y),
        text,
        fontsize=size,
        fontname=fontname,
        color=color,
        overlay=True,
        fill_opacity=opacity,
        stroke_opacity=opacity,
        morph=morph,
    )
    if resolve_annotation_underline(ann):
        text_width = fallback_font.text_length(text, fontsize=size)
        underline_y = baseline_y + max(0.5, size * 0.08)
        draw_rotated_line(
            page,
            fitz.Point(draw_x, underline_y),
            fitz.Point(draw_x + text_width, underline_y),
            color=color,
            width=max(0.5, size * 0.06),
            opacity=opacity,
            morph=morph,
        )


def draw_signature(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = to_rect(page, ann)
    if rect is None:
        return

    if is_direct_draw_annotation(ann) and draw_direct_draw_vector_annotation(page, ann, rect):
        return

    asset_file_path = resolve_annotation_image_path(ann)
    if asset_file_path and not is_direct_draw_annotation(ann):
        try:
            # Match the editor's resized box exactly. PyMuPDF keeps aspect ratio
            # by default, which would shrink one axis back toward the source image.
            page.insert_image(rect, filename=asset_file_path, overlay=True, keep_proportion=False)
            return
        except Exception:
            pass

    image_bytes = load_annotation_image_bytes(ann)
    if image_bytes is None and asset_file_path:
        try:
            page.insert_image(rect, filename=asset_file_path, overlay=True, keep_proportion=False)
            return
        except Exception:
            return
    if image_bytes is None:
        return
    page.insert_image(
        rect,
        overlay=True,
        keep_proportion=False,
        **direct_draw_image_insert_kwargs(ann, image_bytes),
    )


def is_direct_draw_annotation(ann: Dict[str, Any]) -> bool:
    return str(ann.get("imageToolSource") or "").strip().lower() == "direct-draw"


def direct_draw_vector_data(ann: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    vector = ann.get("directDrawVector")
    return vector if isinstance(vector, dict) else None


def draw_direct_draw_vector_annotation(page: fitz.Page, ann: Dict[str, Any], rect: fitz.Rect) -> bool:
    vector = direct_draw_vector_data(ann)
    if not vector or rect.is_empty:
        return False
    try:
        vector_width = max(1.0, float(vector.get("width") or 0.0))
        vector_height = max(1.0, float(vector.get("height") or 0.0))
    except Exception:
        return False
    strokes = vector.get("strokes")
    if not isinstance(strokes, list) or not strokes:
        return False
    rotation = normalize_rotation_degrees(ann.get("rotation", 0.0))
    morph = build_rotation_morph(rotation, fitz.Point((rect.x0 + rect.x1) / 2.0, (rect.y0 + rect.y1) / 2.0))
    scale_x = rect.width / vector_width
    scale_y = rect.height / vector_height
    stroke_scale = max(0.01, (abs(scale_x) + abs(scale_y)) / 2.0)
    drew_any = False
    for stroke in strokes:
        if not isinstance(stroke, dict):
            continue
        raw_points = stroke.get("points")
        if not isinstance(raw_points, list) or not raw_points:
            continue
        points: list[fitz.Point] = []
        for point in raw_points:
            if not isinstance(point, dict):
                continue
            try:
                px = float(point.get("x") or 0.0)
                py = float(point.get("y") or 0.0)
            except Exception:
                continue
            points.append(fitz.Point(rect.x0 + (px * scale_x), rect.y0 + (py * scale_y)))
        if not points:
            continue
        if len(points) == 1:
            points.append(fitz.Point(points[0].x + 0.01, points[0].y))
        try:
            brush_size = max(0.25, float(stroke.get("brushSize") or 1.0)) * stroke_scale
        except Exception:
            brush_size = stroke_scale
        color = hex_to_rgb(stroke.get("color") or ann.get("drawStrokeColor") or "#111827")
        opacity = normalized_opacity(stroke.get("opacity", ann.get("opacity", 1.0)))
        shape = page.new_shape()
        for start, end in zip(points, points[1:]):
            shape.draw_line(start, end)
        finish_kwargs = {
            "color": color,
            "width": brush_size,
            "lineCap": 1,
            "lineJoin": 1,
            "closePath": False,
            "stroke_opacity": opacity,
        }
        if morph is not None:
            finish_kwargs["morph"] = morph
        shape.finish(**finish_kwargs)
        shape.commit(overlay=True)
        drew_any = True
    return drew_any


def load_annotation_image_bytes(ann: Dict[str, Any]) -> Optional[bytes]:
    asset_file_path = resolve_annotation_image_path(ann)
    if asset_file_path:
        try:
            with open(asset_file_path, "rb") as handle:
                return handle.read()
        except Exception:
            pass

    data_url = str(ann.get("dataUrl") or "")
    if not data_url.startswith("data:image/"):
        return None
    try:
        _, payload = data_url.split(",", 1)
        return base64.b64decode(payload)
    except Exception:
        return None


def normalize_direct_draw_white_image_bytes(ann: Dict[str, Any], image_bytes: bytes) -> bytes:
    if Image is None or not image_bytes or not is_direct_draw_annotation(ann):
        return image_bytes
    try:
        with Image.open(io.BytesIO(image_bytes)) as image:
            rgba = image.convert("RGBA")
            if not annotation_uses_white_direct_draw(ann, rgba):
                return image_bytes
            changed = False
            pixels = rgba.load()
            for y in range(rgba.height):
                for x in range(rgba.width):
                    r, g, b, a = pixels[x, y]
                    if a <= 2:
                        if a != 0 or r != 255 or g != 255 or b != 255:
                            changed = True
                        pixels[x, y] = (255, 255, 255, 0)
                        continue
                    if r != 255 or g != 255 or b != 255:
                        changed = True
                        pixels[x, y] = (255, 255, 255, a)
            if not changed:
                return image_bytes
            output = io.BytesIO()
            rgba.save(output, format="PNG")
            return output.getvalue()
    except Exception:
        return image_bytes


def direct_draw_image_insert_kwargs(ann: Dict[str, Any], image_bytes: bytes) -> Dict[str, bytes]:
    if Image is None or not image_bytes or not is_direct_draw_annotation(ann):
        return {"stream": image_bytes}
    try:
        with Image.open(io.BytesIO(image_bytes)) as image:
            rgba = image.convert("RGBA")
            if not annotation_uses_white_direct_draw(ann, rgba):
                return {"stream": image_bytes}
            alpha = rgba.getchannel("A")
            alpha_pixels = alpha.load()
            for y in range(alpha.height):
                for x in range(alpha.width):
                    if alpha_pixels[x, y] <= 2 and alpha_pixels[x, y] != 0:
                        alpha_pixels[x, y] = 0
            white = Image.new("RGB", rgba.size, (255, 255, 255))
            stream_output = io.BytesIO()
            mask_output = io.BytesIO()
            white.save(stream_output, format="PNG")
            alpha.save(mask_output, format="PNG")
            return {"stream": stream_output.getvalue(), "mask": mask_output.getvalue()}
    except Exception:
        return {"stream": normalize_direct_draw_white_image_bytes(ann, image_bytes)}


def annotation_uses_white_direct_draw(ann: Dict[str, Any], image: "Image.Image") -> bool:
    declared_color = parse_annotation_hex_rgb(ann.get("drawStrokeColor"))
    if declared_color is not None:
        return is_near_white_rgb(declared_color)
    return image_looks_like_white_direct_draw(image)


def parse_annotation_hex_rgb(value: Any) -> Optional[tuple[int, int, int]]:
    raw = str(value or "").strip()
    if not raw.startswith("#"):
        return None
    hex_value = raw[1:]
    if len(hex_value) == 3:
        hex_value = "".join(ch * 2 for ch in hex_value)
    if len(hex_value) != 6:
        return None
    try:
        return (
            int(hex_value[0:2], 16),
            int(hex_value[2:4], 16),
            int(hex_value[4:6], 16),
        )
    except Exception:
        return None


def is_near_white_rgb(rgb: tuple[int, int, int], threshold: int = 244, max_spread: int = 12) -> bool:
    return min(rgb) >= threshold and (max(rgb) - min(rgb)) <= max_spread


def image_looks_like_white_direct_draw(image: "Image.Image") -> bool:
    weighted_alpha = 0
    weighted_red = 0
    weighted_green = 0
    weighted_blue = 0
    visible_samples = 0
    bright_samples = 0

    pixels = image.load()
    for y in range(image.height):
        for x in range(image.width):
            r, g, b, a = pixels[x, y]
            if a <= 15:
                continue
            weighted_alpha += a
            weighted_red += r * a
            weighted_green += g * a
            weighted_blue += b * a
            visible_samples += 1
            if min(r, g, b) >= 208 and (max(r, g, b) - min(r, g, b)) <= 40:
                bright_samples += 1

    if weighted_alpha <= 0 or visible_samples <= 0:
        return False

    average_rgb = (
        round(weighted_red / weighted_alpha),
        round(weighted_green / weighted_alpha),
        round(weighted_blue / weighted_alpha),
    )
    if is_near_white_rgb(average_rgb, threshold=232, max_spread=24):
        return True

    return (bright_samples / max(1, visible_samples)) >= 0.72


def resolve_annotation_image_path(ann: Dict[str, Any]) -> Optional[str]:
    asset_file_path = str(ann.get("assetFilePath") or "").strip()
    if asset_file_path and os.path.isfile(asset_file_path):
        return asset_file_path

    for key in ("assetPath", "imagePath", "src"):
        raw_value = ann.get(key)
        if raw_value is None:
            continue

        relative_path = normalize_annotation_asset_path(str(raw_value))
        if not relative_path:
            continue

        for candidate in candidate_annotation_asset_paths(relative_path):
            if os.path.isfile(candidate):
                return candidate

    return None


def normalize_annotation_asset_path(raw_value: str) -> Optional[str]:
    candidate = raw_value.strip()
    if not candidate or candidate.startswith("data:image/"):
        return None

    if os.path.isabs(candidate):
        return candidate if os.path.isfile(candidate) else None

    parsed = urlparse(candidate)
    candidate_path = parsed.path if parsed.scheme else candidate
    candidate_path = candidate_path.strip().lstrip("/")
    if not candidate_path:
        return None

    if candidate_path.startswith("storage/"):
        candidate_path = candidate_path[len("storage/") :]

    return candidate_path or None


def candidate_annotation_asset_paths(relative_path: str) -> list[str]:
    normalized_base_paths = [
        os.path.normpath(os.path.join(PROJECT_ROOT, "storage", "app", "public")),
        os.path.normpath(os.path.join(PUBLIC_DIR, "storage")),
    ]
    relative_path = relative_path.replace("\\", "/").lstrip("/")

    candidates: list[str] = []
    for base_path in normalized_base_paths:
        candidate = os.path.normpath(os.path.join(base_path, relative_path))
        try:
            if os.path.commonpath([candidate, base_path]) != base_path:
                continue
        except ValueError:
            continue
        candidates.append(candidate)

    return candidates


def apply_annotations(pdf_path: str, annotations: list) -> None:
    annotations = normalize_annotations_for_pdf_export(annotations)
    doc = fitz.open(pdf_path)
    for ann in annotations:
        if not isinstance(ann, dict):
            continue
        page_index = clamp_page_index(ann.get("pageIndex"), doc.page_count)
        if page_index is None:
            continue
        page = doc[page_index]
        kind = (ann.get("type") or "").lower()
        if kind == "shape":
            draw_shape(page, ann)
        elif kind == "table":
            draw_table(page, ann)
        elif kind == "eraser":
            draw_eraser(page, ann)
        elif kind == "text":
            draw_text(page, ann)
        elif kind == "signature":
            draw_signature(page, ann)
        elif kind == "image":
            draw_signature(page, ann)

    temp_path = pdf_path + ".ann.tmp"
    try:
        doc.save(
            temp_path,
            incremental=False,
            encryption=fitz.PDF_ENCRYPT_KEEP,
            garbage=4,
            deflate=True,
            clean=True,
        )
    except TypeError:
        doc.save(
            temp_path,
            incremental=False,
            encryption=fitz.PDF_ENCRYPT_KEEP,
            garbage=4,
            deflate=True,
        )
    finally:
        doc.close()

    os.replace(temp_path, pdf_path)


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: apply_annotations_direct.py <pdf_path> <annotations_json_path>")
        return 1

    pdf_path = sys.argv[1]
    annotations_path = sys.argv[2]
    if not os.path.exists(pdf_path):
        print(f"PDF not found: {pdf_path}")
        return 1
    if not os.path.exists(annotations_path):
        print(f"Annotations JSON not found: {annotations_path}")
        return 1

    with open(annotations_path, "r", encoding="utf-8") as fh:
        annotations = json.load(fh)
    if not isinstance(annotations, list):
        print("Annotations payload must be a JSON array.")
        return 1

    apply_annotations(pdf_path, annotations)
    print(f"Applied {len(annotations)} annotation(s) directly.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
