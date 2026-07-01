#!/usr/bin/env python3
"""
Apply editor annotations directly to an existing PDF using PyMuPDF.
This path avoids pdf-lib full-document rewrites so shape stamping is
independent of overlay/text save behavior.
"""

import base64
import difflib
import hashlib
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
    pdfjs_source_edit_export_metrics,
    pdfjs_source_edit_requires_redaction,
    pdfjs_source_edit_source_rect,
    pdfjs_source_edit_transform_scale_x,
    promoted_source_overlay_has_visible_change,
    promoted_source_overlay_text_was_edited,
    source_overlay_text_was_edited,
    sanitize_pdfjs_source_text,
    sanitize_pdf_text,
    sanitize_rich_text_html,
)


SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(SCRIPT_DIR, "fonts")
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, "..", ".."))
PUBLIC_DIR = os.path.join(PROJECT_ROOT, "public")
TEMP_DIR = os.path.join(PROJECT_ROOT, "storage", "app", "temp")
# Admin-uploaded full (non-subsetted) source fonts. Filenames are the
# original PostScript name (e.g. "ITCFranklinGothicStd-Demi.otf"). Resolved
# before the runtime-extracted subset for perfect glyph coverage.
FULL_FONT_DIR = os.path.join(PROJECT_ROOT, "storage", "app", "fonts", "full")
# Substitute alias config (free metric-compatible fonts mapped by source
# PostScript name pattern). Edited via config/font_substitutes.json.
FONT_SUBSTITUTES_PATH = os.path.join(PROJECT_ROOT, "config", "font_substitutes.json")
SOURCE_PDF_CACHE: dict[str, fitz.Document] = {}
PROMOTED_SOURCE_REPLAY_PAD = 0.1


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
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
    },
    "Gelasio": {
        "normal": os.path.join(FONT_DIR, "Gelasio-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Gelasio-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Gelasio-Italic[wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Gelasio-Italic[wght].ttf"),
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
        "italic": os.path.join(FONT_DIR, "Tinos-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Tinos-BoldItalic.ttf"),
    },
    "Cousine": {
        "normal": os.path.join(FONT_DIR, "Cousine-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Cousine-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Cousine-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Cousine-BoldItalic.ttf"),
    },
    "Lato": {
        "normal": os.path.join(FONT_DIR, "Lato-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Lato-Bold.ttf"),
        # No dedicated Lato italic shipped; fall back to Noto Sans Italic
        # (variable font covers weights) so inline italic spans render
        # slanted instead of upright. Worst case: slight face mismatch.
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
    },
    "Montserrat": {
        "light": os.path.join(FONT_DIR, "Montserrat-Light.ttf"),
        "normal": os.path.join(FONT_DIR, "Montserrat-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Montserrat-Bold.ttf"),
        # No dedicated Montserrat italic shipped; fall back to Noto Sans
        # Italic so inline italic spans render slanted.
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
    },
    "OpenSans": {
        "normal": os.path.join(FONT_DIR, "OpenSans-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "OpenSans-Bold.ttf"),
        # No dedicated OpenSans italic shipped; fall back to Noto Sans
        # Italic so inline italic spans render slanted.
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
    },
    "Poppins": {
        "normal": os.path.join(FONT_DIR, "Poppins-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Poppins-Bold.ttf"),
        # No dedicated Poppins italic shipped; fall back to Noto Sans
        # Italic so inline italic spans render slanted.
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
    },
    "Roboto": {
        "normal": os.path.join(FONT_DIR, "Roboto-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Roboto-Bold.ttf"),
        # No dedicated Roboto italic shipped; fall back to Noto Sans
        # Italic so inline italic spans render slanted.
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
    },
    "SourceSansPro": {
        "normal": os.path.join(FONT_DIR, "SourceSans3-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "SourceSans3-Bold.ttf"),
        # No dedicated Source Sans italic shipped; fall back to Noto Sans
        # Italic so inline italic spans render slanted.
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
    },
    "Helvetica": {
        "normal": os.path.join(FONT_DIR, "Arimo-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Arimo-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
        "boldItalic": os.path.join(FONT_DIR, "NotoSans-Italic[wdth,wght].ttf"),
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
        "italic": os.path.join(FONT_DIR, "Cousine-Italic.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Cousine-BoldItalic.ttf"),
    },
}


# ── Popular Google Fonts available in the /edit-new editor ─────────────────
# Each entry maps a family alias to its on-disk TTF files. Italic-less
# families fall back to NotoSans-Italic so inline italic spans still render
# slanted in the exported PDF. Variable fonts that cover the whole weight
# axis (e.g. Mulish[wght].ttf) reuse the same file for both Regular and Bold.
def _ff(reg, bold=None, italic=None, bold_italic=None,
        italic_fallback="NotoSans-Italic[wdth,wght].ttf"):
    rp = os.path.join(FONT_DIR, reg)
    bp = os.path.join(FONT_DIR, bold) if bold else rp
    ip = os.path.join(FONT_DIR, italic) if italic else os.path.join(FONT_DIR, italic_fallback)
    bip = os.path.join(FONT_DIR, bold_italic) if bold_italic else ip
    return {"normal": rp, "bold": bp, "italic": ip, "boldItalic": bip}


GOOGLE_FONT_FILE_VARIANTS = {
    "Inter":            _ff("Inter[opsz,wght].ttf",          italic="Inter-Italic[opsz,wght].ttf"),
    "Nunito":           _ff("Nunito[wght].ttf",              italic="Nunito-Italic[wght].ttf"),
    "Merriweather":     _ff("Merriweather[opsz,wdth,wght].ttf", italic="Merriweather-Italic[opsz,wdth,wght].ttf",
                            italic_fallback="NotoSerif-Italic[wdth,wght].ttf"),
    "PlayfairDisplay":  _ff("PlayfairDisplay[wght].ttf",     italic="PlayfairDisplay-Italic[wght].ttf",
                            italic_fallback="NotoSerif-Italic[wdth,wght].ttf"),
    "Raleway":          _ff("Raleway[wght].ttf",             italic="Raleway-Italic[wght].ttf"),
    "WorkSans":         _ff("WorkSans[wght].ttf",            italic="WorkSans-Italic[wght].ttf"),
    "NotoSans":         _ff("NotoSans[wdth,wght].ttf",       italic="NotoSans-Italic[wdth,wght].ttf"),
    "NotoSerif":        _ff("NotoSerif[wdth,wght].ttf",      italic="NotoSerif-Italic[wdth,wght].ttf",
                            italic_fallback="NotoSerif-Italic[wdth,wght].ttf"),
    "Oswald":           _ff("Oswald[wght].ttf"),
    "RobotoSlab":       _ff("RobotoSlab-Regular.ttf",   "RobotoSlab-Bold.ttf",
                            italic_fallback="NotoSerif-Italic[wdth,wght].ttf"),
    "RobotoMono":       _ff("RobotoMono-Regular.ttf",   "RobotoMono-Bold.ttf",
                            italic="RobotoMono-Italic.ttf",  bold_italic="RobotoMono-BoldItalic.ttf"),
    "RobotoCondensed":  _ff("RobotoCondensed-Regular.ttf","RobotoCondensed-Bold.ttf",
                            italic="RobotoCondensed-Italic.ttf", bold_italic="RobotoCondensed-BoldItalic.ttf"),
    "Ubuntu":           _ff("Ubuntu-Regular.ttf",       "Ubuntu-Bold.ttf",
                            italic="Ubuntu-Italic.ttf", bold_italic="Ubuntu-BoldItalic.ttf"),
    "Rubik":            _ff("Rubik-Regular.ttf",        "Rubik-Bold.ttf",
                            italic="Rubik-Italic.ttf",  bold_italic="Rubik-BoldItalic.ttf"),
    "DMSans":           _ff("DMSans-Regular.ttf",       "DMSans-Bold.ttf",
                            italic="DMSans-Italic.ttf", bold_italic="DMSans-BoldItalic.ttf"),
    "Mulish":           _ff("Mulish-Regular.ttf",       "Mulish-Bold.ttf",
                            italic="Mulish-Italic.ttf", bold_italic="Mulish-BoldItalic.ttf"),
    "Quicksand":        _ff("Quicksand-Regular.ttf",    "Quicksand-Bold.ttf"),
    "Kanit":            _ff("Kanit-Regular.ttf",        "Kanit-Bold.ttf",
                            italic="Kanit-Italic.ttf",  bold_italic="Kanit-BoldItalic.ttf"),
    "FiraSans":         _ff("FiraSans-Regular.ttf",     "FiraSans-Bold.ttf",
                            italic="FiraSans-Italic.ttf", bold_italic="FiraSans-BoldItalic.ttf"),
    "Lora":             _ff("Lora-Regular.ttf",         "Lora-Bold.ttf",
                            italic="Lora-Italic.ttf",   bold_italic="Lora-BoldItalic.ttf",
                            italic_fallback="NotoSerif-Italic[wdth,wght].ttf"),
    "Cabin":            _ff("Cabin-Regular.ttf",        "Cabin-Bold.ttf",
                            italic="Cabin-Italic.ttf",  bold_italic="Cabin-BoldItalic.ttf"),
    "Heebo":            _ff("Heebo-Regular.ttf",        "Heebo-Bold.ttf"),
    "Karla":            _ff("Karla-Regular.ttf",        "Karla-Bold.ttf",
                            italic="Karla-Italic.ttf",  bold_italic="Karla-BoldItalic.ttf"),
    "Manrope":          _ff("Manrope-Regular.ttf",      "Manrope-Bold.ttf"),
    "JosefinSans":      _ff("JosefinSans-Regular.ttf",  "JosefinSans-Bold.ttf",
                            italic="JosefinSans-Italic.ttf", bold_italic="JosefinSans-BoldItalic.ttf"),
    "Dosis":            _ff("Dosis-Regular.ttf",        "Dosis-Bold.ttf"),
    "Barlow":           _ff("Barlow-Regular.ttf",       "Barlow-Bold.ttf",
                            italic="Barlow-Italic.ttf", bold_italic="Barlow-BoldItalic.ttf"),
    "BebasNeue":        _ff("BebasNeue-Regular.ttf",    "BebasNeue-Bold.ttf"),
    "PTSans":           _ff("PTSans-Regular.ttf",       "PTSans-Bold.ttf",
                            italic="PTSans-Italic.ttf", bold_italic="PTSans-BoldItalic.ttf"),
    "CrimsonText":      _ff("CrimsonText-Regular.ttf",  "CrimsonText-Bold.ttf",
                            italic="CrimsonText-Italic.ttf", bold_italic="CrimsonText-BoldItalic.ttf",
                            italic_fallback="NotoSerif-Italic[wdth,wght].ttf"),
    "Hind":             _ff("Hind-Regular.ttf",         "Hind-Bold.ttf"),
    "Mukta":            _ff("Mukta-Regular.ttf",        "Mukta-Bold.ttf"),
}

FONT_FILE_VARIANTS.update(GOOGLE_FONT_FILE_VARIANTS)


# ── Variable-font static instance materialization ──────────────────────────
# MuPDF's HTML engine ignores @font-face `font-weight` when picking a
# variation from a variable font — it just renders the file's *default*
# instance. Many of the Google fonts we ship are variable fonts whose
# default variation is NOT Regular (e.g. Merriweather defaults to Light
# wght=300), which means:
#   - Regular (400) text renders too thin / wrong weight.
#   - Bold spans don't render bold at all because the file registered for
#     weight=700 is the same variable file → still picks Light default.
# Fix: at module import, for each known weight×style face in
# FONT_FILE_VARIANTS, if the file is a variable font we materialize a
# static instance at the requested wght (400/700) and italic axis, cache
# it under storage/app/temp/font-instances/, and rewrite FONT_FILE_VARIANTS
# to point at the static file so MuPDF gets one face per actual weight.
_STATIC_INSTANCE_CACHE_DIR = os.path.join(TEMP_DIR, "font-instances")


def _is_variable_font_file(path: str) -> bool:
    if not path or not os.path.exists(path):
        return False
    try:
        from fontTools.ttLib import TTFont
        ttf = TTFont(path, lazy=True)
        return "fvar" in ttf
    except Exception:
        return False


def _materialize_variable_font_instance(src_path: str, wght: int, italic: bool) -> str:
    """Return a path to a static TTF instance of `src_path` at the given
    weight (and italic slant if available). Returns `src_path` unchanged
    when the source is not a variable font, on any error, or when the
    cached instance already exists.
    """
    if not src_path or not os.path.exists(src_path):
        return src_path
    if not _is_variable_font_file(src_path):
        return src_path
    try:
        os.makedirs(_STATIC_INSTANCE_CACHE_DIR, exist_ok=True)
        base = os.path.basename(src_path).replace("[", "_").replace("]", "_").replace(",", "_")
        suffix = f"_w{wght}{'_it' if italic else ''}"
        out_path = os.path.join(_STATIC_INSTANCE_CACHE_DIR, base.replace(".ttf", f"{suffix}.ttf"))
        if os.path.exists(out_path) and os.path.getsize(out_path) > 0:
            return out_path
        from fontTools.ttLib import TTFont
        from fontTools.varLib import instancer
        ttf = TTFont(src_path)
        axes = {a.axisTag: a for a in ttf["fvar"].axes}
        loc: dict = {}
        if "wght" in axes:
            a = axes["wght"]
            loc["wght"] = max(a.minValue, min(a.maxValue, wght))
        if "wdth" in axes:
            loc["wdth"] = axes["wdth"].defaultValue
        if "opsz" in axes:
            # Use a body-text optical size (~12-18pt) by default.
            opsz_axis = axes["opsz"]
            loc["opsz"] = max(opsz_axis.minValue, min(opsz_axis.maxValue, 18))
        if "ital" in axes and italic:
            loc["ital"] = axes["ital"].maxValue
        if "slnt" in axes and italic:
            loc["slnt"] = axes["slnt"].minValue  # negative slant for italic
        if not loc:
            return src_path
        inst = instancer.instantiateVariableFont(ttf, loc)
        inst.save(out_path)
        return out_path
    except Exception:
        return src_path


def _materialize_static_instances_for_variable_fonts() -> None:
    """Walk FONT_FILE_VARIANTS once at import time and replace any variable
    font file references with paths to weight/italic-locked static
    instances. Idempotent thanks to the on-disk cache.
    """
    for family, variants in FONT_FILE_VARIANTS.items():
        if not isinstance(variants, dict):
            continue
        try:
            normal = variants.get("normal")
            bold = variants.get("bold")
            italic = variants.get("italic")
            bold_italic = variants.get("boldItalic")
            if normal:
                variants["normal"] = _materialize_variable_font_instance(normal, 400, italic=False)
            # Only materialize bold from the SAME source file when bold ==
            # normal (i.e. one variable file covers both weights). When the
            # designer shipped a separate static bold TTF, leave it alone.
            if bold and bold == normal:
                variants["bold"] = _materialize_variable_font_instance(bold, 700, italic=False)
            elif bold:
                variants["bold"] = _materialize_variable_font_instance(bold, 700, italic=False)
            if italic:
                variants["italic"] = _materialize_variable_font_instance(italic, 400, italic=True)
            if bold_italic and bold_italic == italic:
                variants["boldItalic"] = _materialize_variable_font_instance(bold_italic, 700, italic=True)
            elif bold_italic:
                variants["boldItalic"] = _materialize_variable_font_instance(bold_italic, 700, italic=True)
        except Exception:
            # Any per-family failure leaves the original variable file in
            # place (status quo before this fix); never crash the export.
            pass


# NOTE: materialization is intentionally NOT invoked at module import — each
# variable-font instancing pass takes ~20s and we ship ~60 VF files. Instead
# `build_html_font_face_css(families=...)` materializes lazily on first export
# of an annotation that actually uses each family, and the on-disk cache makes
# subsequent runs instant.


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
    "Montserrat": [
        "Montserrat",
        "Montserrat-Regular",
        "Montserrat-Light",
        "Montserrat-Thin",
        "MontserratThin_300wght",
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


# ── Aliases for the popular Google Fonts exposed in the /edit-new editor ────
GOOGLE_FONT_HTML_ALIASES = {
    "Inter":            ["Inter", "Inter-Regular"],
    "Nunito":           ["Nunito", "Nunito-Regular"],
    "Merriweather":     ["Merriweather", "Merriweather-Regular"],
    "PlayfairDisplay":  ["Playfair Display", "PlayfairDisplay", "PlayfairDisplay-Regular"],
    "Raleway":          ["Raleway", "Raleway-Regular"],
    "WorkSans":         ["Work Sans", "WorkSans", "WorkSans-Regular"],
    "NotoSans":         ["Noto Sans", "NotoSans", "NotoSans-Regular"],
    "NotoSerif":        ["Noto Serif", "NotoSerif", "NotoSerif-Regular"],
    "Oswald":           ["Oswald", "Oswald-Regular"],
    "RobotoSlab":       ["Roboto Slab", "RobotoSlab", "RobotoSlab-Regular"],
    "RobotoMono":       ["Roboto Mono", "RobotoMono", "RobotoMono-Regular"],
    "RobotoCondensed":  ["Roboto Condensed", "RobotoCondensed", "RobotoCondensed-Regular"],
    "Ubuntu":           ["Ubuntu", "Ubuntu-Regular"],
    "Rubik":            ["Rubik", "Rubik-Regular"],
    "DMSans":           ["DM Sans", "DMSans", "DMSans-Regular"],
    "Mulish":           ["Mulish", "Mulish-Regular"],
    "Quicksand":        ["Quicksand", "Quicksand-Regular"],
    "Kanit":            ["Kanit", "Kanit-Regular"],
    "FiraSans":         ["Fira Sans", "FiraSans", "FiraSans-Regular"],
    "Lora":             ["Lora", "Lora-Regular"],
    "Cabin":            ["Cabin", "Cabin-Regular"],
    "Heebo":            ["Heebo", "Heebo-Regular"],
    "Karla":            ["Karla", "Karla-Regular"],
    "Manrope":          ["Manrope", "Manrope-Regular"],
    "JosefinSans":      ["Josefin Sans", "JosefinSans", "JosefinSans-Regular"],
    "Dosis":            ["Dosis", "Dosis-Regular"],
    "Barlow":           ["Barlow", "Barlow-Regular"],
    "BebasNeue":        ["Bebas Neue", "BebasNeue", "BebasNeue-Regular"],
    "PTSans":           ["PT Sans", "PTSans", "PT_Sans", "PTSans-Regular"],
    "CrimsonText":      ["Crimson Text", "CrimsonText", "CrimsonText-Regular"],
    "Hind":             ["Hind", "Hind-Regular"],
    "Mukta":            ["Mukta", "Mukta-Regular"],
}

HTML_FONT_FAMILY_ALIASES.update(GOOGLE_FONT_HTML_ALIASES)


# Lowercase tokens used by ``normalize_font_family`` to detect a popular
# Google family from a free-form ``ann.fontFamily`` string. The first matching
# token wins, so order from longest/most-specific to shortest.
GOOGLE_FONT_NORMALIZE_TOKENS = [
    ("playfairdisplay",  "PlayfairDisplay"),
    ("crimsontext",      "CrimsonText"),
    ("robotocondensed",  "RobotoCondensed"),
    ("robotomono",       "RobotoMono"),
    ("robotoslab",       "RobotoSlab"),
    ("josefinsans",      "JosefinSans"),
    ("merriweather",     "Merriweather"),
    ("ptsans",           "PTSans"),
    ("pt_sans",          "PTSans"),
    ("bebasneue",        "BebasNeue"),
    ("worksans",         "WorkSans"),
    ("dmsans",           "DMSans"),
    ("notosans",         "NotoSans"),
    ("notoserif",        "NotoSerif"),
    ("firasans",         "FiraSans"),
    ("quicksand",        "Quicksand"),
    ("manrope",          "Manrope"),
    ("raleway",          "Raleway"),
    ("ubuntu",           "Ubuntu"),
    ("rubik",            "Rubik"),
    ("mulish",           "Mulish"),
    ("kanit",            "Kanit"),
    ("oswald",           "Oswald"),
    ("nunito",           "Nunito"),
    ("inter",            "Inter"),
    ("lora",             "Lora"),
    ("cabin",            "Cabin"),
    ("heebo",            "Heebo"),
    ("karla",            "Karla"),
    ("dosis",            "Dosis"),
    ("barlow",           "Barlow"),
    ("hind",             "Hind"),
    ("mukta",            "Mukta"),
]

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
# Allow the popular Google Fonts we ship to bypass any embedded subset that
# happens to share the same family name.
EMBEDDED_FONT_BYPASS_FAMILIES.update(GOOGLE_FONT_FILE_VARIANTS.keys())


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
    raw_value = str(value or "").strip().replace('"', "").replace("'", "")
    normalized = normalize_exact_font_family(value)
    if not normalized:
        return False
    raw_lower = raw_value.lower()
    if any(token in raw_lower for token in (
        "neueltstd",
        "neue",
        "ltstd",
        "franklingothicstd",
        "universstd",
    )):
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


# CRITICAL FAILURE GUARD: keep the downloaded PDF visually consistent with the
# editor when a text annotation has white (or near-white) text on a white /
# transparent background. The editor's `resolveDisplayBgColor` substitutes a
# dark slate (#2c3e50) placeholder behind such text so it is readable on the
# white page; without the same substitution at export time the downloaded PDF
# renders white-on-white = visually blank (see doc 2880 row 113884:
# textColor="#ffffff", backgroundColor="#ffffff", backgroundColorExplicit=true).
# Mirror the editor here so what the user sees in /edit-new is what gets
# stamped into the PDF. Returns the (possibly substituted) background RGB or
# None if no substitution is needed.
_WHITE_TEXT_RE = re.compile(r"^#?f(?:ff|[e-f]{2})(?:f(?:ff|[e-f]{2})){0,2}$", re.IGNORECASE)
_DISPLAY_BG_PLACEHOLDER_RGB = (44.0 / 255.0, 62.0 / 255.0, 80.0 / 255.0)  # #2c3e50


def _text_color_is_white_like(text_color_raw: Any) -> bool:
    text = str(text_color_raw or "").strip().replace(" ", "")
    if not text:
        return False
    return bool(_WHITE_TEXT_RE.match(text))


def _bg_is_effectively_white_or_missing(bg_raw: Any) -> bool:
    bg = str(bg_raw or "").strip().lower()
    if not bg or bg == "transparent":
        return True
    # Match #ffffff and any near-white hex (matches editor's _WHITE_TEXT_RE).
    return bool(_WHITE_TEXT_RE.match(bg))


def substitute_display_bg_for_invisible_text(
    text_color_raw: Any,
    bg_color_raw: Any,
    bg_color_rgb: Optional[Tuple[float, float, float]],
    annotation_id: str = "",
) -> Optional[Tuple[float, float, float]]:
    """Return a slate-placeholder bg RGB when text is white and bg is white or
    transparent (so white text never renders against a white page in the
    downloaded PDF). Otherwise return ``bg_color_rgb`` unchanged.
    """
    if not _text_color_is_white_like(text_color_raw):
        return bg_color_rgb
    if not _bg_is_effectively_white_or_missing(bg_color_raw):
        return bg_color_rgb
    print(
        "[apply_annotations] CRITICAL: white text on white/transparent background"
        f" for annotation {annotation_id!r} (textColor={text_color_raw!r},"
        f" backgroundColor={bg_color_raw!r}); substituting #2c3e50 placeholder"
        " background to match the editor's resolveDisplayBgColor display so the"
        " download PDF is not visually blank.",
        file=sys.stderr,
    )
    return _DISPLAY_BG_PLACEHOLDER_RGB


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


SHAPE_EDGE_SNAP_TOLERANCE_PTS = 2.0
SHAPE_BACKGROUND_FILL_BLEED_PTS = 1.25


def snap_rect_to_page_edges(
    page: fitz.Page,
    rect: fitz.Rect,
    tolerance: float = SHAPE_EDGE_SNAP_TOLERANCE_PTS,
) -> fitz.Rect:
    snapped = fitz.Rect(rect)
    page_rect = page.rect
    tol = max(0.0, float(tolerance or 0.0))
    if abs(snapped.x0 - page_rect.x0) <= tol:
        snapped.x0 = page_rect.x0
    if abs(page_rect.x1 - snapped.x1) <= tol:
        snapped.x1 = page_rect.x1
    if abs(snapped.y0 - page_rect.y0) <= tol:
        snapped.y0 = page_rect.y0
    if abs(page_rect.y1 - snapped.y1) <= tol:
        snapped.y1 = page_rect.y1
    return snapped


def expand_rect_clipped_to_page(page: fitz.Page, rect: fitz.Rect, amount: float) -> fitz.Rect:
    bleed = max(0.0, float(amount or 0.0))
    expanded = fitz.Rect(rect.x0 - bleed, rect.y0 - bleed, rect.x1 + bleed, rect.y1 + bleed)
    return expanded & page.rect


PDFJS_SOURCE_MASK_PADDING_X_PTS = 2.0
PDFJS_SOURCE_MASK_PADDING_Y_PTS = 1.0
PDFJS_SOURCE_MASK_PADDING_PTS = PDFJS_SOURCE_MASK_PADDING_X_PTS
PDFJS_SOURCE_MASK_NEIGHBOR_GAP_PTS = 0.1
# Max baseline drift (pts) for a redaction neighbour word to count as being on
# the SAME text line as the target. Beyond this it is treated as a different
# (vertically overlapping) line, so the redact rect is shrunk vertically rather
# than horizontally. Tight-leading forms stack lines ~8pt apart, so 2pt cleanly
# separates same-line words (≈0pt drift) from adjacent lines.
_SHRINK_SAME_LINE_BASELINE_TOL_PTS = 2.0


def _boolish(value: Any) -> bool:
    if value is True or value == 1:
        return True
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def is_pdfjs_visible_overlay_text(ann: Dict[str, Any]) -> bool:
    if not isinstance(ann, dict):
        return False
    if str(ann.get("type") or "").strip().lower() not in {"", "text"}:
        return False
    # A span anchored to REAL source glyphs whose text was changed in place is
    # an edited source span and MUST mask the original — even if the editor
    # mis-flagged it `userCreated` / `skipPdfjsSourceMask`. Without this the
    # original glyphs survive under the replacement (double text).
    if source_overlay_text_was_edited(ann):
        return True
    if _boolish(ann.get("userCreated")):
        return False
    if _boolish(ann.get("skipPdfjsSourceMask")) and not str(ann.get("pdfjsSourceText") or "").strip():
        return False
    if _boolish(ann.get("promotedFromExtraction")):
        return is_pdfjs_promoted_visible_overlay_text(ann)
    return (
        _boolish(ann.get("savedTextOverlay"))
        or _boolish(ann.get("pdfjsDeleted"))
        or ann.get("pdfjsSourceX") is not None
        or ann.get("pdfjsSourceY") is not None
    )


def is_pdfjs_promoted_visible_overlay_text(ann: Dict[str, Any]) -> bool:
    has_source_anchor = (
        ann.get("pdfjsSourceX") is not None
        or ann.get("pdfjsSourceY") is not None
        or ann.get("pdfjsSourceMaskX") is not None
        or ann.get("pdfjsAnchorUid") is not None
    )
    if not (
        has_source_anchor
        or _boolish(ann.get("savedTextOverlay"))
        or _boolish(ann.get("pdfjsDeleted"))
        or _boolish(ann.get("movedTextOverlay"))
    ):
        return False

    return promoted_source_overlay_has_visible_change(ann)


def normalize_pdfjs_compare_text(value: Any) -> str:
    return re.sub(r"\s+", " ", sanitize_pdf_text(value or "")).strip()


def pdfjs_annotation_box(ann: Dict[str, Any], prefix: str) -> Optional[tuple[float, float, float, float]]:
    try:
        if prefix == "source":
            x = float(ann.get("pdfjsSourceX"))
            y = float(ann.get("pdfjsSourceY"))
            w = float(ann.get("pdfjsSourceW"))
            h = float(ann.get("pdfjsSourceH"))
        else:
            x = float(ann.get("pdfX"))
            y = float(ann.get("pdfY"))
            w = float(ann.get("pdfWidth"))
            h = float(ann.get("pdfHeight"))
    except Exception:
        return None
    if w <= 0 or h <= 0:
        return None
    return (x, y, w, h)


def pdfjs_boxes_nearly_equal(
    left: tuple[float, float, float, float] | None,
    right: tuple[float, float, float, float] | None,
    tolerance: float = 3.0,
) -> bool:
    if left is None or right is None:
        return False
    return all(abs(float(a) - float(b)) <= tolerance for a, b in zip(left, right))


def _pdfjs_box_overlap_ratio(
    left: tuple[float, float, float, float] | None,
    right: tuple[float, float, float, float] | None,
) -> float:
    if left is None or right is None:
        return 0.0
    ax, ay, aw, ah = left
    bx, by, bw, bh = right
    if aw <= 0 or ah <= 0 or bw <= 0 or bh <= 0:
        return 0.0
    overlap_w = max(0.0, min(ax + aw, bx + bw) - max(ax, bx))
    overlap_h = max(0.0, min(ay + ah, by + bh) - max(ay, by))
    if overlap_w <= 0 or overlap_h <= 0:
        return 0.0
    min_area = max(0.0001, min(aw * ah, bw * bh))
    return (overlap_w * overlap_h) / min_area


def pdfjs_source_boxes_match(left: Dict[str, Any], right: Dict[str, Any]) -> bool:
    left_box = pdfjs_annotation_box(left, "source")
    right_box = pdfjs_annotation_box(right, "source")
    if pdfjs_boxes_nearly_equal(left_box, right_box, tolerance=1.5):
        return True
    return _pdfjs_box_overlap_ratio(left_box, right_box) >= 0.72


def _pdfjs_page_index(ann: Dict[str, Any]) -> Optional[int]:
    try:
        return int(ann.get("pageIndex"))
    except Exception:
        return None


def is_redundant_pdfjs_source_overlay(ann: Dict[str, Any]) -> bool:
    if not is_pdfjs_visible_overlay_text(ann):
        return False
    if _boolish(ann.get("pdfjsDeleted")):
        return False
    if _boolish(ann.get("movedTextOverlay")):
        return False
    if _boolish(ann.get("promotedFromExtraction")) and promoted_source_overlay_has_visible_change(ann):
        return False
    if (
        _boolish(ann.get("userForcedRichText"))
        or str(ann.get("pdfjsEditorMode") or "").strip().lower() == "rich"
        or str(ann.get("richTextPromotionReason") or "").strip().lower() == "manual-resize"
    ):
        return False
    if ann.get("pdfjsSourceX") is None and ann.get("pdfjsAnchorUid") is None:
        return False
    source_text = normalize_pdfjs_compare_text(ann.get("pdfjsSourceText") or ann.get("originalText") or "")
    current_text = normalize_pdfjs_compare_text(ann.get("text") or "")
    if not source_text or source_text != current_text:
        return False
    return pdfjs_boxes_nearly_equal(
        pdfjs_annotation_box(ann, "current"),
        pdfjs_annotation_box(ann, "source"),
    )


def suppress_pdfjs_deleted_masks_owned_by_replacements(annotations: list) -> None:
    if not isinstance(annotations, list):
        return
    replacements = [
        ann
        for ann in annotations
        if isinstance(ann, dict)
        and is_pdfjs_visible_overlay_text(ann)
        and not _boolish(ann.get("pdfjsDeleted"))
        and bool(sanitize_pdf_text(ann.get("text") or "").strip())
        and not is_redundant_pdfjs_source_overlay(ann)
        and pdfjs_annotation_box(ann, "source") is not None
    ]
    if not replacements:
        return

    for ann in annotations:
        if not isinstance(ann, dict):
            continue
        if not (is_pdfjs_visible_overlay_text(ann) and _boolish(ann.get("pdfjsDeleted"))):
            continue
        ann_page_index = _pdfjs_page_index(ann)
        source_text = normalize_pdfjs_compare_text(ann.get("pdfjsSourceText") or ann.get("originalText") or "")
        for replacement in replacements:
            if ann_page_index is not None and _pdfjs_page_index(replacement) != ann_page_index:
                continue
            replacement_source_text = normalize_pdfjs_compare_text(
                replacement.get("pdfjsSourceText") or replacement.get("originalText") or ""
            )
            if source_text and replacement_source_text and source_text != replacement_source_text:
                continue
            if pdfjs_source_boxes_match(ann, replacement):
                ann["skipPdfjsSourceMask"] = True
                break


def pdfjs_visible_overlay_scale_x(ann: Dict[str, Any]) -> float:
    return pdfjs_source_edit_transform_scale_x(ann)


def _pdfjs_source_base_rect(page: fitz.Page, ann: Dict[str, Any]) -> Optional[fitz.Rect]:
    source_rect = pdfjs_source_edit_source_rect(ann)
    if source_rect is None:
        return None
    x = source_rect["x"]
    y = source_rect["y"]
    w = source_rect["w"]
    h = source_rect["h"]
    ph = page.rect.height
    return fitz.Rect(x, ph - (y + h), x + w, ph - y) & page.rect


def pdfjs_visible_overlay_render_rect(
    page: fitz.Page,
    ann: Dict[str, Any],
    fallback_rect: Optional[fitz.Rect],
) -> Optional[fitz.Rect]:
    if not is_pdfjs_visible_overlay_text(ann):
        return fallback_rect
    if _boolish(ann.get("pdfjsDeleted")) or _boolish(ann.get("movedTextOverlay")):
        return fallback_rect
    if (
        _boolish(ann.get("userForcedRichText"))
        or str(ann.get("pdfjsEditorMode") or "").strip().lower() == "rich"
        or str(ann.get("richTextPromotionReason") or "").strip().lower() == "manual-resize"
    ):
        return fallback_rect
    source_rect = _pdfjs_source_base_rect(page, ann)
    if source_rect is None or source_rect.is_empty:
        return fallback_rect
    if fallback_rect is not None and not fallback_rect.is_empty:
        width = max(float(source_rect.width), float(fallback_rect.width))
        height = max(float(source_rect.height), float(fallback_rect.height))
        return fitz.Rect(
            source_rect.x0,
            source_rect.y0,
            min(float(page.rect.x1), source_rect.x0 + width),
            min(float(page.rect.y1), source_rect.y0 + height),
        )
    return source_rect


def _pdfjs_explicit_source_mask_rect(page: fitz.Page, ann: Dict[str, Any]) -> Optional[fitz.Rect]:
    try:
        x = float(ann.get("pdfjsSourceMaskX"))
        y = float(ann.get("pdfjsSourceMaskY"))
        w = float(ann.get("pdfjsSourceMaskW"))
        h = float(ann.get("pdfjsSourceMaskH"))
    except Exception:
        return None
    if w <= 0 or h <= 0:
        return None
    ph = page.rect.height
    return fitz.Rect(x, ph - (y + h), x + w, ph - y) & page.rect


def _pdfjs_matching_source_text_rect(
    page: fitz.Page,
    ann: Dict[str, Any],
    anchor_rect: fitz.Rect,
) -> Optional[fitz.Rect]:
    source_text = sanitize_pdf_text(ann.get("pdfjsSourceText") or ann.get("originalText") or "").strip()
    normalized_source = source_text.replace(" ", "").lower()
    if not normalized_source or anchor_rect is None or anchor_rect.is_empty:
        return None

    try:
        blocks = page.get_text("dict").get("blocks", [])
    except Exception:
        return None

    search_rect = fitz.Rect(
        anchor_rect.x0 - 4.0,
        anchor_rect.y0 - 4.0,
        anchor_rect.x1 + 4.0,
        anchor_rect.y1 + 4.0,
    ) & page.rect
    best_rect: Optional[fitz.Rect] = None
    best_score = -1.0

    for block in blocks:
        if not isinstance(block, dict):
            continue
        for line in block.get("lines", []) or []:
            if not isinstance(line, dict):
                continue
            line_rect: Optional[fitz.Rect] = None
            line_text_parts: list[str] = []
            overlapping_rect: Optional[fitz.Rect] = None

            for span in line.get("spans", []) or []:
                if not isinstance(span, dict):
                    continue
                span_rect = _fitz_rect_from_bbox(span.get("bbox"))
                if span_rect is None:
                    continue
                line_rect = fitz.Rect(span_rect) if line_rect is None else (line_rect | span_rect)
                span_text = sanitize_pdf_text(span.get("text") or "")
                line_text_parts.append(span_text)
                if not (span_rect & search_rect).is_empty:
                    overlapping_rect = fitz.Rect(span_rect) if overlapping_rect is None else (overlapping_rect | span_rect)

            if line_rect is None or (line_rect & search_rect).is_empty:
                continue

            compact_line = "".join(line_text_parts).replace(" ", "").lower()
            if not compact_line:
                continue
            if compact_line == normalized_source:
                text_score = 4.0
                candidate_rect = line_rect
            elif normalized_source in compact_line:
                text_score = 2.0
                candidate_rect = overlapping_rect or line_rect
            else:
                continue

            overlap = line_rect & anchor_rect
            overlap_area = 0.0 if overlap.is_empty else float(overlap.width) * float(overlap.height)
            anchor_area = max(0.0001, float(anchor_rect.width) * float(anchor_rect.height))
            rect_score = overlap_area / anchor_area
            score = text_score + rect_score
            if score > best_score:
                best_score = score
                best_rect = candidate_rect

    return best_rect


def exact_embedded_font_entry_for_document(document_id: Any, font_name: str) -> Optional[Dict[str, Any]]:
    name = str(font_name or "").strip()
    if not name:
        return None
    metadata = load_embedded_font_metadata(document_id)
    if not metadata:
        return None
    normalized_name = normalize_exact_font_family(name)
    for font_key, font_data in metadata.items():
        if not isinstance(font_data, dict):
            continue
        clean_name = str(font_data.get("clean_name") or font_key or "").strip()
        pdf_font_name = str(font_data.get("pdf_font_name") or "").strip()
        candidates = {
            clean_name,
            normalize_exact_font_family(clean_name),
            pdf_font_name,
            pdf_font_name.split("+", 1)[-1] if "+" in pdf_font_name else pdf_font_name,
        }
        if name not in candidates and normalized_name not in candidates:
            continue
        absolute_path = embedded_font_public_path_to_absolute(font_data.get("file_path"))
        if not absolute_path:
            continue
        return {
            "clean_name": clean_name,
            "fontfile": absolute_path,
        }
    return None


def _source_mask_target_contains_rect(target_rect: fitz.Rect, rect: fitz.Rect) -> bool:
    if target_rect.is_empty or rect.is_empty:
        return False
    overlap = target_rect & rect
    if overlap.is_empty:
        return False
    rect_area = max(0.0001, float(rect.width) * float(rect.height))
    overlap_ratio = (float(overlap.width) * float(overlap.height)) / rect_area
    if overlap_ratio >= 0.45:
        return True
    center = fitz.Point((rect.x0 + rect.x1) / 2.0, (rect.y0 + rect.y1) / 2.0)
    return target_rect.contains(center)


def _compact_source_mask_text(value: Any) -> str:
    return re.sub(r"\s+", "", sanitize_pdf_text(value or "")).lower()


def _source_mask_line_matches_source_text(line_text: Any, source_text: Any) -> bool:
    compact_line = _compact_source_mask_text(line_text)
    compact_source = _compact_source_mask_text(source_text)
    if not compact_line or not compact_source:
        return False
    return compact_line == compact_source or compact_line in compact_source or compact_source in compact_line


def _iter_page_text_char_rects(page: fitz.Page) -> list[tuple[fitz.Rect, str]]:
    rects: list[tuple[fitz.Rect, str]] = []
    try:
        blocks = page.get_text("rawdict").get("blocks", [])
    except Exception:
        blocks = []
    for block in blocks:
        if not isinstance(block, dict):
            continue
        for line in block.get("lines", []) or []:
            if not isinstance(line, dict):
                continue
            line_text_parts: list[str] = []
            line_rects: list[fitz.Rect] = []
            for span in line.get("spans", []) or []:
                if not isinstance(span, dict):
                    continue
                chars = span.get("chars")
                if isinstance(chars, list) and chars:
                    for char in chars:
                        if not isinstance(char, dict):
                            continue
                        c = sanitize_pdf_text(char.get("c") or "")
                        line_text_parts.append(c)
                        if not c.strip():
                            continue
                        rect = _fitz_rect_from_bbox(char.get("bbox"))
                        if rect is not None:
                            line_rects.append(rect)
                    continue
                span_text = sanitize_pdf_text(span.get("text") or "")
                line_text_parts.append(span_text)
                rect = _fitz_rect_from_bbox(span.get("bbox"))
                if rect is not None and span_text.strip():
                    line_rects.append(rect)
            line_text = "".join(line_text_parts)
            rects.extend((rect, line_text) for rect in line_rects)
    return rects


def _rect_is_inside_any_source_rect(rect: fitz.Rect, source_rects: list[fitz.Rect]) -> bool:
    if rect.is_empty:
        return False
    for source_rect in source_rects or []:
        if source_rect is None or source_rect.is_empty:
            continue
        if _source_mask_target_contains_rect(source_rect, rect):
            return True
    return False


def _neighbor_text_rects_for_source_mask(
    page: fitz.Page,
    target_rect: fitz.Rect,
    inflated_rect: fitz.Rect,
    source_text: Any = "",
    ignored_source_rects: Optional[list[fitz.Rect]] = None,
) -> list[fitz.Rect]:
    if target_rect.is_empty or inflated_rect.is_empty:
        return []
    search_rect = fitz.Rect(
        inflated_rect.x0 - 3.0,
        inflated_rect.y0 - 3.0,
        inflated_rect.x1 + 3.0,
        inflated_rect.y1 + 3.0,
    ) & page.rect
    neighbors: list[fitz.Rect] = []
    has_source_text = bool(_compact_source_mask_text(source_text))
    for rect, line_text in _iter_page_text_char_rects(page):
        if rect.is_empty:
            continue
        if _rect_is_inside_any_source_rect(rect, ignored_source_rects or []):
            continue
        if (rect & search_rect).is_empty:
            continue
        line_is_source = _source_mask_line_matches_source_text(line_text, source_text)
        if (line_is_source or not has_source_text) and _source_mask_target_contains_rect(target_rect, rect):
            continue
        neighbors.append(rect)
    return neighbors


def _clamp_inflated_source_mask_to_neighbors(
    target_rect: fitz.Rect,
    inflated_rect: fitz.Rect,
    neighbor_rects: list[fitz.Rect],
) -> fitz.Rect:
    safe = fitz.Rect(inflated_rect)
    gap = PDFJS_SOURCE_MASK_NEIGHBOR_GAP_PTS
    for neighbor in neighbor_rects:
        if neighbor.is_empty or (safe & neighbor).is_empty:
            continue

        vertical_overlap = min(target_rect.y1, neighbor.y1) - max(target_rect.y0, neighbor.y0)
        horizontal_overlap = min(target_rect.x1, neighbor.x1) - max(target_rect.x0, neighbor.x0)
        target_center_x = (target_rect.x0 + target_rect.x1) / 2.0
        target_center_y = (target_rect.y0 + target_rect.y1) / 2.0
        neighbor_center_x = (neighbor.x0 + neighbor.x1) / 2.0
        neighbor_center_y = (neighbor.y0 + neighbor.y1) / 2.0

        if neighbor.y1 <= target_rect.y0 and horizontal_overlap > 0:
            safe.y0 = max(safe.y0, neighbor.y1 + gap)
        elif neighbor.y0 >= target_rect.y1 and horizontal_overlap > 0:
            safe.y1 = min(safe.y1, neighbor.y0 - gap)
        elif neighbor.y0 > target_rect.y0 and neighbor_center_y > target_center_y and horizontal_overlap > 0:
            safe.y1 = min(safe.y1, neighbor.y0 - gap)
        elif neighbor.y1 < target_rect.y1 and neighbor_center_y < target_center_y and horizontal_overlap > 0:
            safe.y0 = max(safe.y0, neighbor.y1 + gap)
        elif neighbor.x1 <= target_rect.x0 and vertical_overlap > 0:
            safe.x0 = max(safe.x0, neighbor.x1 + gap)
        elif neighbor.x0 >= target_rect.x1 and vertical_overlap > 0:
            safe.x1 = min(safe.x1, neighbor.x0 - gap)
        elif neighbor.x0 > target_rect.x0 and neighbor_center_x > target_center_x and vertical_overlap > 0:
            safe.x1 = min(safe.x1, neighbor.x0 - gap)
        elif neighbor.x1 < target_rect.x1 and neighbor_center_x < target_center_x and vertical_overlap > 0:
            safe.x0 = max(safe.x0, neighbor.x1 + gap)

    if safe.is_empty or safe.width <= 0.05 or safe.height <= 0.05:
        return fitz.Rect(target_rect)
    return safe


def calculate_safe_pdfjs_source_mask(page: fitz.Page, target_rect: fitz.Rect, source_text: Any = "") -> fitz.Rect:
    return calculate_safe_pdfjs_source_mask_with_ignored_rects(page, target_rect, source_text, None)


def calculate_safe_pdfjs_source_mask_with_ignored_rects(
    page: fitz.Page,
    target_rect: fitz.Rect,
    source_text: Any = "",
    ignored_source_rects: Optional[list[fitz.Rect]] = None,
    padding_x: Optional[float] = None,
    padding_y: Optional[float] = None,
) -> fitz.Rect:
    pad_x = PDFJS_SOURCE_MASK_PADDING_X_PTS if padding_x is None else max(0.0, float(padding_x))
    pad_y = PDFJS_SOURCE_MASK_PADDING_Y_PTS if padding_y is None else max(0.0, float(padding_y))
    inflated = fitz.Rect(
        target_rect.x0 - pad_x,
        target_rect.y0 - pad_y,
        target_rect.x1 + pad_x,
        target_rect.y1 + pad_y,
    ) & page.rect
    neighbors = _neighbor_text_rects_for_source_mask(
        page,
        target_rect,
        inflated,
        source_text,
        ignored_source_rects=ignored_source_rects,
    )
    return _clamp_inflated_source_mask_to_neighbors(target_rect, inflated, neighbors) & page.rect


def pdfjs_source_mask_rect(page: fitz.Page, ann: Dict[str, Any]) -> Optional[fitz.Rect]:
    # Visible PDF.js export uses the immutable frontend source rectangle.
    # Do not re-discover the text with page.search_for() here: backend text
    # geometry can drift from the browser geometry and produce duplicate
    # "repaired" neighbors or oversized masks.
    explicit_mask_rect = _pdfjs_explicit_source_mask_rect(page, ann)
    if explicit_mask_rect is not None and not explicit_mask_rect.is_empty:
        if _boolish(ann.get("pdfjsDeleted")):
            source_text = ann.get("pdfjsSourceText") or ann.get("originalText") or ann.get("text") or ""
            padding_y = max(PDFJS_SOURCE_MASK_PADDING_Y_PTS, float(explicit_mask_rect.height) * 0.22)
            return calculate_safe_pdfjs_source_mask_with_ignored_rects(
                page,
                explicit_mask_rect,
                source_text,
                None,
                padding_y=padding_y,
            )
        if _boolish(ann.get("movedTextOverlay")):
            source_text = ann.get("pdfjsSourceText") or ann.get("originalText") or ann.get("text") or ""
            ignored_source_rects = ann.get("__pdfjsMovedSourceRects") if isinstance(ann.get("__pdfjsMovedSourceRects"), list) else None
            padding_y = max(PDFJS_SOURCE_MASK_PADDING_Y_PTS, float(explicit_mask_rect.height) * 0.18)
            return calculate_safe_pdfjs_source_mask_with_ignored_rects(
                page,
                explicit_mask_rect,
                source_text,
                ignored_source_rects,
                padding_y=padding_y,
            )
        return explicit_mask_rect
    base_rect = _pdfjs_source_base_rect(page, ann)
    if base_rect is None or base_rect.is_empty:
        return None
    if _boolish(ann.get("pdfjsDeleted")):
        source_text = ann.get("pdfjsSourceText") or ann.get("originalText") or ann.get("text") or ""
        padding_y = max(PDFJS_SOURCE_MASK_PADDING_Y_PTS, float(base_rect.height) * 0.22)
        return calculate_safe_pdfjs_source_mask_with_ignored_rects(
            page,
            base_rect,
            source_text,
            None,
            padding_y=padding_y,
        )
    if _boolish(ann.get("movedTextOverlay")):
        source_text = ann.get("pdfjsSourceText") or ann.get("originalText") or ann.get("text") or ""
        ignored_source_rects = ann.get("__pdfjsMovedSourceRects") if isinstance(ann.get("__pdfjsMovedSourceRects"), list) else None
        padding_y = max(PDFJS_SOURCE_MASK_PADDING_Y_PTS, float(base_rect.height) * 0.18)
        return calculate_safe_pdfjs_source_mask_with_ignored_rects(
            page,
            base_rect,
            source_text,
            ignored_source_rects,
            padding_y=padding_y,
        )
    return base_rect


def _rect_intersection_ratio(rect: fitz.Rect, target: fitz.Rect) -> float:
    if rect.is_empty or target.is_empty:
        return 0.0
    overlap = rect & target
    if overlap.is_empty:
        return 0.0
    rect_area = max(0.0001, rect.width * rect.height)
    return (overlap.width * overlap.height) / rect_area


def pdfjs_source_text_redaction_rects(page: fitz.Page, ann: Dict[str, Any], source_rect: fitz.Rect) -> list[fitz.Rect]:
    source_text = sanitize_pdf_text(ann.get("pdfjsSourceText") or ann.get("originalText") or "").strip()
    if not source_text:
        return [source_rect]

    matches: list[fitz.Rect] = []
    try:
        matches = [fitz.Rect(match) & page.rect for match in page.search_for(source_text)]
    except Exception:
        matches = []
    matches = [match for match in matches if not match.is_empty]

    candidates: list[fitz.Rect] = []
    for match in matches:
        if _rect_intersection_ratio(match, source_rect) >= 0.55:
            candidates.append(match)

    if candidates:
        return candidates
    if matches:
        try:
            occurrence = int(ann.get("pdfjsSourceOccurrence") or ann.get("occurrence") or 0)
        except Exception:
            occurrence = 0
        if occurrence < 0:
            occurrence = 0
        if occurrence < len(matches):
            return [matches[occurrence]]
        return [matches[-1]]
    return [source_rect]


def pdfjs_source_text_needs_redaction(ann: Dict[str, Any]) -> bool:
    return pdfjs_source_edit_requires_redaction(ann)


def redact_pdfjs_source_text(page: fitz.Page, ann: Dict[str, Any], source_rect: fitz.Rect, fill: tuple[float, float, float]) -> bool:
    if source_rect is None or source_rect.is_empty:
        return False
    if not pdfjs_source_text_needs_redaction(ann):
        return False
    redaction_rects = pdfjs_source_text_redaction_rects(page, ann, source_rect)
    if not redaction_rects:
        return False
    # Tighten each redaction rect to per-word rects whose centroid falls
    # inside it. fitz's apply_redactions removes ANY text whose bbox merely
    # intersects the rect, so a tall heading's source rect can clip the
    # adjacent row by 1-2pt and wipe an entire neighboring word
    # (e.g. erasing "December 2025" when redacting "SS-4"). Centroid-based
    # selection keeps neighbors intact.
    try:
        page_words = page.get_text("words")
    except Exception:
        page_words = []
    tightened_rects: list[fitz.Rect] = []
    for redaction_rect in redaction_rects:
        rect = fitz.Rect(redaction_rect) & page.rect
        if rect.is_empty:
            continue
        matched_any = False
        for w in page_words:
            x0, y0, x1, y1 = float(w[0]), float(w[1]), float(w[2]), float(w[3])
            cx = (x0 + x1) * 0.5
            cy = (y0 + y1) * 0.5
            if rect.x0 <= cx <= rect.x1 and rect.y0 <= cy <= rect.y1:
                tightened_rects.append(fitz.Rect(x0, y0, x1, y1))
                matched_any = True
        if not matched_any:
            tightened_rects.append(rect)
    added = False
    for rect in tightened_rects:
        rect = fitz.Rect(rect) & page.rect
        if rect.is_empty or rect.width <= 0.05 or rect.height <= 0.05:
            continue
        rect = _shrink_redact_rect_to_avoid_neighbors(page, rect, page_words)
        if rect.is_empty or rect.width <= 0.05 or rect.height <= 0.05:
            continue
        try:
            page.add_redact_annot(rect, fill=fill)
            added = True
        except Exception:
            continue
    if not added:
        return False
    try:
        _apply_redactions_compat(page)
        return True
    except Exception:
        return False


def sample_pdfjs_mask_fill(page: fitz.Page, rect: fitz.Rect) -> tuple[float, float, float]:
    if rect is None or rect.is_empty:
        return (1.0, 1.0, 1.0)
    try:
        pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), clip=rect, alpha=False)
    except Exception:
        return (1.0, 1.0, 1.0)
    if pix.width <= 0 or pix.height <= 0:
        return (1.0, 1.0, 1.0)

    samples = pix.samples
    channels = max(1, int(getattr(pix, "n", 3) or 3))
    buckets: Dict[tuple[int, int, int], int] = {}
    light_buckets: Dict[tuple[int, int, int], int] = {}
    total_pixels = max(1, pix.width * pix.height)
    step = max(1, int(math.sqrt(total_pixels / 5000.0)))
    sample_count = 0

    for y in range(0, pix.height, step):
        row_offset = y * pix.width * channels
        for x in range(0, pix.width, step):
            offset = row_offset + (x * channels)
            if offset + 2 >= len(samples):
                continue
            r = int(samples[offset])
            g = int(samples[offset + 1])
            b = int(samples[offset + 2])
            sample_count += 1
            luminance = (0.299 * r) + (0.587 * g) + (0.114 * b)
            key = (
                min(255, round(r / 8) * 8),
                min(255, round(g / 8) * 8),
                min(255, round(b / 8) * 8),
            )
            buckets[key] = buckets.get(key, 0) + 1
            if luminance >= 155:
                light_buckets[key] = light_buckets.get(key, 0) + 1

    if not buckets:
        return (1.0, 1.0, 1.0)

    best_key, best_count = max(buckets.items(), key=lambda item: item[1])
    best_light_key = None
    best_light_count = 0
    if light_buckets:
        best_light_key, best_light_count = max(light_buckets.items(), key=lambda item: item[1])

    r, g, b = best_key
    luminance = (0.299 * r) + (0.587 * g) + (0.114 * b)
    if luminance < 155 and best_light_key is not None:
        all_ratio = best_count / max(1, sample_count)
        saturation = max(r, g, b) - min(r, g, b)
        colored_background = saturation >= 28 and (all_ratio >= 0.45 or best_count >= best_light_count * 1.35)
        dark_background = (all_ratio >= 0.62 or (luminance < 90 and all_ratio >= 0.50)) and best_count >= best_light_count * 1.5
        if not (colored_background or dark_background):
            r, g, b = best_light_key

    return (r / 255.0, g / 255.0, b / 255.0)


def _pdfjs_rgb_luminance(fill: tuple[float, float, float]) -> float:
    try:
        r, g, b = fill
        return (0.299 * float(r) * 255.0) + (0.587 * float(g) * 255.0) + (0.114 * float(b) * 255.0)
    except Exception:
        return 255.0


def sample_pdfjs_surrounding_mask_fill(page: fitz.Page, rect: fitz.Rect) -> Optional[tuple[float, float, float]]:
    if rect is None or rect.is_empty:
        return None
    gap = 0.5
    band = max(1.0, min(8.0, min(float(rect.width), float(rect.height)) * 0.5))
    pad = max(0.5, min(6.0, min(float(rect.width), float(rect.height)) * 0.25))
    candidates = [
        fitz.Rect(rect.x0 - pad, rect.y0 - gap - band, rect.x1 + pad, rect.y0 - gap),
        fitz.Rect(rect.x0 - pad, rect.y1 + gap, rect.x1 + pad, rect.y1 + gap + band),
        fitz.Rect(rect.x0 - gap - band, rect.y0 - pad, rect.x0 - gap, rect.y1 + pad),
        fitz.Rect(rect.x1 + gap, rect.y0 - pad, rect.x1 + gap + band, rect.y1 + pad),
    ]
    scored: Dict[tuple[int, int, int], float] = {}
    for candidate in candidates:
        candidate = candidate & page.rect
        if candidate.is_empty or candidate.width <= 0.25 or candidate.height <= 0.25:
            continue
        fill = sample_pdfjs_mask_fill(page, candidate)
        key = tuple(max(0, min(255, int(round(channel * 255 / 8) * 8))) for channel in fill)
        scored[key] = scored.get(key, 0.0) + float(candidate.width) * float(candidate.height)
    if not scored:
        return None
    r, g, b = max(scored.items(), key=lambda item: item[1])[0]
    return (r / 255.0, g / 255.0, b / 255.0)


def sample_pdfjs_moved_source_mask_fill(page: fitz.Page, rect: fitz.Rect) -> tuple[float, float, float]:
    local = sample_pdfjs_mask_fill(page, rect)
    surrounding = sample_pdfjs_surrounding_mask_fill(page, rect)
    if surrounding is not None:
        local_luminance = _pdfjs_rgb_luminance(local)
        surrounding_luminance = _pdfjs_rgb_luminance(surrounding)
        if local_luminance < 100 and surrounding_luminance > local_luminance + 80:
            return local
        return surrounding
    return local


def _is_thin_page_rule(rule_rect: fitz.Rect, mask_rect: fitz.Rect) -> tuple[str, fitz.Rect] | None:
    if rule_rect.is_empty or mask_rect.is_empty:
        return None
    overlap = rule_rect & mask_rect
    if overlap.is_empty:
        return None

    max_rule_thickness = 1.5
    horizontal = (
        rule_rect.height <= max_rule_thickness
        and rule_rect.width >= 8.0
        and overlap.width >= min(rule_rect.width, mask_rect.width, 1.0)
    )
    if horizontal:
        return ("horizontal", rule_rect)

    vertical = (
        rule_rect.width <= max_rule_thickness
        and rule_rect.height >= 8.0
        and overlap.height >= min(rule_rect.height, mask_rect.height, 1.0)
    )
    if vertical:
        return ("vertical", rule_rect)

    return None


def _subtract_rule_band_from_rects(rects: list[fitz.Rect], orientation: str, rule_rect: fitz.Rect) -> list[fitz.Rect]:
    next_rects: list[fitz.Rect] = []
    guard = 0.03
    for rect in rects:
        overlap = rect & rule_rect
        if overlap.is_empty:
            next_rects.append(rect)
            continue

        if orientation == "horizontal":
            candidates = [
                fitz.Rect(rect.x0, rect.y0, rect.x1, max(rect.y0, rule_rect.y0 - guard)),
                fitz.Rect(rect.x0, min(rect.y1, rule_rect.y1 + guard), rect.x1, rect.y1),
            ]
        else:
            candidates = [
                fitz.Rect(rect.x0, rect.y0, max(rect.x0, rule_rect.x0 - guard), rect.y1),
                fitz.Rect(min(rect.x1, rule_rect.x1 + guard), rect.y0, rect.x1, rect.y1),
            ]

        next_rects.extend(candidate for candidate in candidates if not candidate.is_empty and candidate.width > 0.05 and candidate.height > 0.05)
    return next_rects


def detect_raster_horizontal_rule_bands(page: fitz.Page, rect: fitz.Rect) -> list[fitz.Rect]:
    if rect is None or rect.is_empty or rect.width < 6.0 or rect.height <= 0:
        return []
    scale = 4.0
    try:
        pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), clip=rect, alpha=False)
    except Exception:
        return []
    if pix.width <= 0 or pix.height <= 0:
        return []

    channels = max(1, int(getattr(pix, "n", 3) or 3))
    samples = pix.samples
    dark_rows: list[int] = []
    for y in range(pix.height):
        row_offset = y * pix.width * channels
        dark = 0
        contiguous_dark = 0
        longest_contiguous_dark = 0
        for x in range(pix.width):
            offset = row_offset + (x * channels)
            if offset + 2 >= len(samples):
                continue
            r = int(samples[offset])
            g = int(samples[offset + 1])
            b = int(samples[offset + 2])
            luminance = (0.299 * r) + (0.587 * g) + (0.114 * b)
            is_dark = luminance < 170
            if is_dark:
                dark += 1
                contiguous_dark += 1
                longest_contiguous_dark = max(longest_contiguous_dark, contiguous_dark)
            else:
                contiguous_dark = 0
        if pix.width > 0 and (dark / pix.width) >= 0.72 and (longest_contiguous_dark / pix.width) >= 0.65:
            dark_rows.append(y)

    if not dark_rows:
        return []

    groups: list[tuple[int, int]] = []
    start = prev = dark_rows[0]
    for row in dark_rows[1:]:
        if row <= prev + 1:
            prev = row
            continue
        groups.append((start, prev))
        start = prev = row
    groups.append((start, prev))

    bands: list[fitz.Rect] = []
    for start, end in groups:
        top = rect.y0 + (start / scale) - 0.12
        bottom = rect.y0 + ((end + 1) / scale) + 0.12
        band = fitz.Rect(rect.x0, max(rect.y0, top), rect.x1, min(rect.y1, bottom))
        if not band.is_empty and 0.05 < band.height <= 4.0:
            bands.append(band)
    return bands


def split_pdfjs_source_mask_around_page_rules(page: fitz.Page, rect: fitz.Rect) -> list[fitz.Rect]:
    if rect is None or rect.is_empty:
        return []

    pieces = [fitz.Rect(rect)]
    try:
        drawings = page.get_drawings()
    except Exception:
        return pieces

    for drawing in drawings:
        rule_rect = drawing.get("rect") if isinstance(drawing, dict) else None
        if not isinstance(rule_rect, fitz.Rect):
            continue
        rule = _is_thin_page_rule(rule_rect, rect)
        if rule is None:
            continue
        pieces = _subtract_rule_band_from_rects(pieces, rule[0], rule[1])
        if not pieces:
            break

    if pieces:
        for rule_rect in detect_raster_horizontal_rule_bands(page, rect):
            pieces = _subtract_rule_band_from_rects(pieces, "horizontal", rule_rect)
            if not pieces:
                break

    return pieces or [fitz.Rect(rect)]


def _subtract_rect_from_rects(
    rects: list[fitz.Rect],
    hole: fitz.Rect,
    gap: float = PDFJS_SOURCE_MASK_NEIGHBOR_GAP_PTS,
) -> list[fitz.Rect]:
    """Carve ``hole`` (expanded by ``gap``) out of every rect, yielding up to four
    bands (top / bottom / left / right) per rect. Used to keep a moved source
    mask fully covering its own glyphs while leaving an overlapping neighbour
    (e.g. a subheader nested inside a tall header's bounding box) untouched."""
    if hole is None or hole.is_empty:
        return list(rects)
    h = fitz.Rect(hole.x0 - gap, hole.y0 - gap, hole.x1 + gap, hole.y1 + gap)
    out: list[fitz.Rect] = []
    for rect in rects:
        if rect is None or rect.is_empty:
            continue
        inter = rect & h
        if inter.is_empty:
            out.append(rect)
            continue
        # Top band (above the hole, full width).
        if inter.y0 > rect.y0 + 0.05:
            out.append(fitz.Rect(rect.x0, rect.y0, rect.x1, inter.y0))
        # Bottom band (below the hole, full width).
        if inter.y1 < rect.y1 - 0.05:
            out.append(fitz.Rect(rect.x0, inter.y1, rect.x1, rect.y1))
        # Left band (beside the hole, within the hole's vertical span).
        if inter.x0 > rect.x0 + 0.05:
            out.append(fitz.Rect(rect.x0, inter.y0, inter.x0, inter.y1))
        # Right band (beside the hole, within the hole's vertical span).
        if inter.x1 < rect.x1 - 0.05:
            out.append(fitz.Rect(inter.x1, inter.y0, rect.x1, inter.y1))
    return [r for r in out if not r.is_empty and r.width > 0.05 and r.height > 0.05]


def _neighbor_line_rects_for_moved_mask(
    page: fitz.Page,
    region_rect: fitz.Rect,
    source_text: Any,
) -> list[fitz.Rect]:
    """Return line-level bounding rects of page text that overlaps ``region_rect``
    but does not belong to the moved source line itself. These are carved out of
    the source mask so the mask never paints over a neighbouring line that is
    nested inside a tall source glyph box."""
    if region_rect is None or region_rect.is_empty:
        return []
    search = fitz.Rect(
        region_rect.x0 - 3.0,
        region_rect.y0 - 3.0,
        region_rect.x1 + 3.0,
        region_rect.y1 + 3.0,
    ) & page.rect
    if search.is_empty:
        return []
    has_source = bool(_compact_source_mask_text(source_text))
    try:
        blocks = page.get_text("dict").get("blocks", [])
    except Exception:
        blocks = []
    out: list[fitz.Rect] = []
    for block in blocks:
        if not isinstance(block, dict):
            continue
        for line in block.get("lines", []) or []:
            if not isinstance(line, dict):
                continue
            line_rect: Optional[fitz.Rect] = None
            parts: list[str] = []
            for span in line.get("spans", []) or []:
                if not isinstance(span, dict):
                    continue
                span_rect = _fitz_rect_from_bbox(span.get("bbox"))
                if span_rect is None or span_rect.is_empty:
                    continue
                line_rect = fitz.Rect(span_rect) if line_rect is None else (line_rect | span_rect)
                parts.append(sanitize_pdf_text(span.get("text") or ""))
            if line_rect is None or line_rect.is_empty:
                continue
            if (line_rect & search).is_empty:
                continue
            line_text = "".join(parts)
            if has_source and _source_mask_line_matches_source_text(line_text, source_text):
                continue
            clip = line_rect & region_rect
            if not clip.is_empty and clip.width > 0.05 and clip.height > 0.05:
                out.append(clip)
    return out


def pdfjs_moved_source_mask_pieces(page: fitz.Page, ann: Dict[str, Any]) -> list[fitz.Rect]:
    """Mask geometry for a moved source overlay. Instead of edge-clamping the
    whole mask up to an overlapping neighbour (which abandons the header's own
    glyphs that extend past the neighbour horizontally), carve the neighbour out
    of the full glyph rect so the mask stays L-shaped and fully covers the
    moved source text."""
    clamped = pdfjs_source_mask_rect(page, ann)
    if clamped is None or clamped.is_empty:
        return []
    explicit = _pdfjs_explicit_source_mask_rect(page, ann)
    base = explicit if (explicit is not None and not explicit.is_empty) else _pdfjs_source_base_rect(page, ann)
    if base is None or base.is_empty:
        return [clamped]
    full = fitz.Rect(
        base.x0 - PDFJS_SOURCE_MASK_PADDING_X_PTS,
        base.y0 - PDFJS_SOURCE_MASK_PADDING_Y_PTS,
        base.x1 + PDFJS_SOURCE_MASK_PADDING_X_PTS,
        base.y1 + PDFJS_SOURCE_MASK_PADDING_Y_PTS,
    ) & page.rect
    if full.is_empty:
        return [clamped]
    # Union of the (generously-padded) clamped rect and the glyph-extent rect:
    # keeps the clamped rect's extra top/headroom padding while reclaiming the
    # side bands the edge-clamp gave up. Bounded below by the glyph rect so it
    # never extends past the neighbour into unrelated content.
    region = clamped | full
    # If the edge-clamp never actually shrank the mask, there is no overlapping
    # neighbour to work around; keep the original single-rect behaviour.
    if (
        abs(region.y0 - clamped.y0) < 0.5
        and abs(region.y1 - clamped.y1) < 0.5
        and abs(region.x0 - clamped.x0) < 0.5
        and abs(region.x1 - clamped.x1) < 0.5
    ):
        return [clamped]
    source_text = ann.get("pdfjsSourceText") or ann.get("originalText") or ann.get("text") or ""
    neighbors = _neighbor_line_rects_for_moved_mask(page, region, source_text)
    if not neighbors:
        return [clamped]
    pieces = [region]
    for neighbor in neighbors:
        pieces = _subtract_rect_from_rects(pieces, neighbor)
        if not pieces:
            break
    pieces = [(p & page.rect) for p in pieces if not (p & page.rect).is_empty]
    return pieces or [clamped]


def draw_pdfjs_source_mask(page: fitz.Page, ann: Dict[str, Any]) -> None:
    # PDF.js visible export is a paint model: the browser covers the immutable
    # source span with a background-colored box, then paints replacement text on
    # top. Do not use PyMuPDF redaction annotations here. Redactions are applied
    # at page-content level and can erase later replacement glyphs when a moved
    # line lands inside a neighboring source line's redaction rectangle.
    moved_overlay = _boolish(ann.get("movedTextOverlay"))
    if moved_overlay:
        base_pieces = pdfjs_moved_source_mask_pieces(page, ann)
    else:
        rect = pdfjs_source_mask_rect(page, ann)
        base_pieces = [rect] if (rect is not None and not rect.is_empty) else []
    if not base_pieces:
        return
    # Sample the moved fill once from the overall bounding region for a uniform
    # mask color across all L-shaped pieces.
    overall = fitz.Rect(base_pieces[0])
    for piece in base_pieces[1:]:
        overall = overall | piece
    moved_fill = sample_pdfjs_moved_source_mask_fill(page, overall) if moved_overlay else None
    preserve_rule_cutouts = not (moved_overlay and moved_fill is not None and _pdfjs_rgb_luminance(moved_fill) < 100)
    do_rule_split = preserve_rule_cutouts
    for base in base_pieces:
        if base is None or base.is_empty:
            continue
        pieces = split_pdfjs_source_mask_around_page_rules(page, base) if do_rule_split else [base]
        for piece in pieces:
            if piece is None or piece.is_empty:
                continue
            fill = (
                (moved_fill or sample_pdfjs_moved_source_mask_fill(page, piece))
                if moved_overlay
                else sample_pdfjs_mask_fill(page, piece)
            )
            page.draw_rect(piece, color=None, fill=fill, width=0, overlay=True)


def redact_pdfjs_deleted_source_rect(page: fitz.Page, source_rect: fitz.Rect) -> bool:
    """Redact the ENTIRE captured source rect for a deleted PDF.js span.

    Deleting a source span means every glyph inside the captured source
    rectangle must disappear. Faux-bold / overprint source text paints each
    glyph twice with a small horizontal offset, so the text-search redaction
    path (`pdfjs_source_text_redaction_rects`) covers only one set of glyphs
    and leaves the offset duplicates at word edges behind (the user-reported
    stray "P I r i" of "Part II Seller/Transferor Information"). Redacting the
    full source rect catches every glyph; `_shrink_redact_rect_to_avoid_neighbors`
    keeps adjacent rows intact and `apply_redactions` runs with graphics=0 so
    form rules/lines survive.
    """
    if source_rect is None or source_rect.is_empty:
        return False
    rect = fitz.Rect(source_rect) & page.rect
    if rect.is_empty or rect.width <= 0.05 or rect.height <= 0.05:
        return False
    try:
        page_words = page.get_text("words")
    except Exception:
        page_words = []
    rect = _shrink_redact_rect_to_avoid_neighbors(page, rect, page_words)
    if rect.is_empty or rect.width <= 0.05 or rect.height <= 0.05:
        return False
    try:
        page.add_redact_annot(rect, fill=None)
    except Exception:
        return False
    try:
        _apply_redactions_compat(page)
        return True
    except Exception:
        return False


def erase_pdfjs_deleted_source_text(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = pdfjs_source_mask_rect(page, ann)
    if rect is None or rect.is_empty:
        return
    # Deleted spans wipe the whole source region. Use a full-rect redaction so
    # overprint/faux-bold duplicate glyphs cannot survive at the word edges.
    if redact_pdfjs_deleted_source_rect(page, rect):
        return
    if redact_pdfjs_source_text(page, ann, rect, None):
        return
    draw_pdfjs_source_mask(page, ann)


def _source_color_to_hex(value: Any) -> str:
    try:
        color_value = int(value)
    except Exception:
        return "#000000"
    color_value = max(0, min(0xFFFFFF, color_value))
    return f"#{color_value:06x}"


def _font_weight_from_source_name(value: Any) -> Optional[str]:
    lower = str(value or "").strip().lower().replace("_", "-")
    compact = lower.replace("-", "").replace(" ", "")
    if not compact:
        return None
    if any(token in compact for token in ("black", "blk", "heavy", "ultrabold", "extrabold")):
        return "900"
    if any(token in compact for token in ("demibold", "semibold")):
        return "600"
    if any(token in compact for token in ("bold", "bd")):
        return "700"
    if "medium" in compact:
        return "500"
    return None


def _fitz_rect_from_bbox(value: Any) -> Optional[fitz.Rect]:
    if not isinstance(value, (list, tuple)) or len(value) < 4:
        return None
    try:
        rect = fitz.Rect(float(value[0]), float(value[1]), float(value[2]), float(value[3]))
    except Exception:
        return None
    return rect if not rect.is_empty else None


def _pdfjs_moved_target_line_baseline_y(
    page: fitz.Page,
    ann: Dict[str, Any],
    current_rect: Optional[fitz.Rect],
    source_font: str,
    source_size: float,
) -> Optional[float]:
    if not _boolish(ann.get("movedTextOverlay")) or current_rect is None or current_rect.is_empty:
        return None
    source_family = normalize_font_family(source_font or ann.get("fontFamily"))
    expected_baseline = current_rect.y0 + (current_rect.height * 0.75)
    best_score = 999999.0
    best_baseline: Optional[float] = None

    try:
        blocks = page.get_text("dict").get("blocks", [])
    except Exception:
        blocks = []

    for block in blocks:
        if not isinstance(block, dict):
            continue
        for line in block.get("lines", []) or []:
            if not isinstance(line, dict):
                continue
            for span in line.get("spans", []) or []:
                if not isinstance(span, dict):
                    continue
                span_rect = _fitz_rect_from_bbox(span.get("bbox"))
                origin = span.get("origin")
                if span_rect is None or not isinstance(origin, (list, tuple)) or len(origin) < 2:
                    continue
                try:
                    baseline_y = float(origin[1])
                    span_size = float(span.get("size") or 0.0)
                except Exception:
                    continue
                if source_size > 0 and span_size > 0 and abs(span_size - source_size) > 0.75:
                    continue
                if source_family and normalize_font_family(span.get("font") or "") != source_family:
                    continue
                vertical_overlap = max(0.0, min(span_rect.y1, current_rect.y1) - max(span_rect.y0, current_rect.y0))
                vertical_gap = 0.0 if vertical_overlap > 0 else min(
                    abs(span_rect.y0 - current_rect.y1),
                    abs(current_rect.y0 - span_rect.y1),
                )
                if vertical_gap > 3.0:
                    continue
                horizontal_gap = 0.0
                if span_rect.x1 < current_rect.x0:
                    horizontal_gap = current_rect.x0 - span_rect.x1
                elif current_rect.x1 < span_rect.x0:
                    horizontal_gap = span_rect.x0 - current_rect.x1
                if horizontal_gap > 24.0:
                    continue
                baseline_delta = abs(baseline_y - expected_baseline)
                if baseline_delta > 4.0:
                    continue
                score = baseline_delta + (vertical_gap * 2.0) + (horizontal_gap * 0.02)
                if score < best_score:
                    best_score = score
                    best_baseline = baseline_y

    return best_baseline


def _pdfjs_source_line_anchor_span(
    blocks: list[Any],
    source_rect: fitz.Rect,
    normalized_source: str,
) -> tuple[Optional[Dict[str, Any]], Optional[fitz.Rect]]:
    best_span: Optional[Dict[str, Any]] = None
    best_rect: Optional[fitz.Rect] = None
    best_score = -1.0

    for block in blocks:
        if not isinstance(block, dict):
            continue
        for line in block.get("lines", []) or []:
            if not isinstance(line, dict):
                continue
            line_spans: list[tuple[Dict[str, Any], fitz.Rect]] = []
            line_rect: Optional[fitz.Rect] = None
            for span in line.get("spans", []) or []:
                if not isinstance(span, dict):
                    continue
                span_rect = _fitz_rect_from_bbox(span.get("bbox"))
                if span_rect is None:
                    continue
                line_spans.append((span, span_rect))
                line_rect = fitz.Rect(span_rect) if line_rect is None else (line_rect | span_rect)
            if not line_spans or line_rect is None:
                continue
            overlap = line_rect & source_rect
            if overlap.is_empty:
                continue

            compact_line = sanitize_pdf_text("".join(str(span.get("text") or "") for span, _ in line_spans)).replace(" ", "").lower()
            if normalized_source and compact_line:
                if compact_line == normalized_source:
                    text_score = 4.0
                elif normalized_source in compact_line or compact_line in normalized_source:
                    text_score = 2.0
                else:
                    text_score = 0.0
            else:
                text_score = 0.0
            if normalized_source and compact_line and text_score <= 0:
                continue

            overlap_area = overlap.width * overlap.height
            source_area = max(0.0001, source_rect.width * source_rect.height)
            rect_score = overlap_area / source_area
            score = text_score + rect_score
            if score <= best_score:
                continue

            overlapping_spans = [
                (span, rect)
                for span, rect in line_spans
                if not (rect & source_rect).is_empty
            ]
            if not overlapping_spans:
                continue
            exact_spans = [
                (span, rect)
                for span, rect in overlapping_spans
                if normalized_source
                and sanitize_pdf_text(span.get("text") or "").replace(" ", "").lower() == normalized_source
            ]
            anchor_span, anchor_rect = max(
                exact_spans or overlapping_spans,
                key=lambda item: (
                    (item[1] & source_rect).get_area(),
                    len(sanitize_pdf_text(item[0].get("text") or "").replace(" ", "")),
                    item[1].width,
                    -item[1].x0,
                ),
            )
            line_start_x: Optional[float] = None
            if text_score >= 4.0:
                for span, _ in overlapping_spans:
                    origin = span.get("origin")
                    if not isinstance(origin, (list, tuple)) or len(origin) < 2:
                        continue
                    try:
                        origin_x = float(origin[0])
                    except Exception:
                        continue
                    line_start_x = origin_x if line_start_x is None else min(line_start_x, origin_x)
            if line_start_x is not None:
                origin = anchor_span.get("origin")
                if isinstance(origin, (list, tuple)) and len(origin) >= 2:
                    anchor_span = dict(anchor_span)
                    anchor_span["origin"] = [line_start_x, origin[1]]
            best_span = anchor_span
            best_rect = anchor_rect
            best_score = score

    return best_span, best_rect


def resolve_pdfjs_visible_overlay_typography(page: fitz.Page, ann: Dict[str, Any]) -> Dict[str, Any]:
    """Match PDF.js source-backed overlays to the original PDF text style.

    Browser pdf.js text spans often expose generic CSS families such as
    sans-serif plus a transform scale. The visible export path should instead
    stamp replacement text with the source PDF's real font metrics at the
    source baseline, otherwise replacements drift and resize versus the viewer.
    """
    # A manual resize promotes the editor box to rich wrapping mode. In that
    # mode the saved pdfWidth/pdfHeight are the authority, not the source
    # span's single-line baseline. Forcing source typography here makes export
    # draw one unwrapped insert_text run that can escape the saved box/page.
    if (
        _boolish(ann.get("styleDirty"))
        or _boolish(ann.get("userForcedRichText"))
        or str(ann.get("pdfjsEditorMode") or "").strip().lower() == "rich"
        or str(ann.get("richTextPromotionReason") or "").strip().lower() == "manual-resize"
    ):
        return ann

    if (
        _boolish(ann.get("pdfjsUseSourceTypography"))
        and str(ann.get("fontSourceName") or "").strip()
        and ann.get("pdfjsSourceBaselineOffsetY") is not None
    ):
        return ann

    source_rect = pdfjs_source_mask_rect(page, ann)
    if source_rect is None or source_rect.is_empty:
        return ann

    source_text = sanitize_pdf_text(ann.get("pdfjsSourceText") or ann.get("originalText") or "").strip()
    normalized_source = sanitize_pdf_text(source_text).replace(" ", "").lower()
    best_span: Optional[Dict[str, Any]] = None
    best_rect: Optional[fitz.Rect] = None
    best_score = -1.0

    try:
        blocks = page.get_text("dict").get("blocks", [])
    except Exception:
        blocks = []

    source_base_rect = _pdfjs_source_base_rect(page, ann)
    if source_base_rect is not None and not source_base_rect.is_empty:
        line_anchor_span, line_anchor_rect = _pdfjs_source_line_anchor_span(
            blocks,
            source_base_rect,
            normalized_source,
        )
        if line_anchor_span is not None and line_anchor_rect is not None:
            best_span = line_anchor_span
            best_rect = line_anchor_rect
            best_score = 999999.0

    for block in blocks:
        if not isinstance(block, dict):
            continue
        for line in block.get("lines", []) or []:
            if not isinstance(line, dict):
                continue
            for span in line.get("spans", []) or []:
                if not isinstance(span, dict):
                    continue
                span_rect = _fitz_rect_from_bbox(span.get("bbox"))
                if span_rect is None:
                    continue
                overlap = span_rect & source_rect
                if overlap.is_empty:
                    continue
                overlap_area = overlap.width * overlap.height
                span_area = max(0.0001, span_rect.width * span_rect.height)
                rect_score = overlap_area / span_area
                span_text = sanitize_pdf_text(span.get("text") or "")
                compact_span = span_text.replace(" ", "").lower()
                text_bonus = 0.0
                if normalized_source and compact_span:
                    if compact_span == normalized_source:
                        text_bonus = 2.0
                    elif normalized_source in compact_span or compact_span in normalized_source:
                        text_bonus = 1.0
                score = rect_score + text_bonus
                if score > best_score:
                    best_score = score
                    best_span = span
                    best_rect = span_rect

    if not best_span or best_rect is None:
        return ann

    resolved = dict(ann)
    source_font = str(best_span.get("font") or "").strip()
    source_family = normalize_font_family(source_font)
    if source_family:
        resolved["fontFamily"] = source_family
    if source_font:
        resolved["fontSourceName"] = source_font
    if _boolish(ann.get("movedTextOverlay")):
        # Reusing a subset/embedded PDF source font for newly inserted moved
        # text can render visually but write broken ToUnicode text maps for
        # glyphs such as quotes (extracted as NULs and, in some viewers,
        # displayed as missing-glyph boxes). Keep the source font metrics /
        # baseline discovered above, but draw with the bundled Unicode-safe
        # annotation font.
        resolved["pdfjsAvoidEmbeddedSourceFont"] = True
        resolved["fontFamily"] = normalize_font_family(ann.get("fontFamily") or "Helvetica") or "Helvetica"
    try:
        source_size = float(best_span.get("size") or 0.0)
    except Exception:
        source_size = 0.0
    if source_size > 0:
        resolved["fontSize"] = source_size
        resolved["lineHeight"] = max(float(resolved.get("lineHeight") or 0.0), source_size)
    if best_span.get("color") is not None:
        resolved["textColor"] = _source_color_to_hex(best_span.get("color"))
        resolved["color"] = resolved["textColor"]
    inferred_source_weight = _font_weight_from_source_name(source_font)
    if inferred_source_weight:
        resolved["fontWeight"] = inferred_source_weight
    elif not str(resolved.get("fontWeight") or "").strip():
        resolved["fontWeight"] = "400"
    font_lower = source_font.lower()
    if "italic" in font_lower or "oblique" in font_lower:
        resolved["fontStyle"] = "italic"
    elif not str(resolved.get("fontStyle") or "").strip():
        resolved["fontStyle"] = "normal"

    # The annotation rect is captured from PDF.js' text-layer box. PyMuPDF's
    # extracted glyph bbox is taller/different, so the baseline offset must be
    # measured from the immutable PDF.js source rect, not from best_rect. This
    # matters most after a move, where the same source run is placed beside
    # existing PDF text and any bbox/baseline mismatch is visible.
    baseline_rect = source_base_rect if source_base_rect is not None and not source_base_rect.is_empty else best_rect
    origin = best_span.get("origin")
    if isinstance(origin, (list, tuple)) and len(origin) >= 2:
        try:
            baseline_offset_x = float(origin[0]) - float(baseline_rect.x0)
            if is_pdfjs_visible_overlay_text(ann) or _boolish(ann.get("movedTextOverlay")):
                baseline_offset_x = 0.0
            resolved["pdfjsSourceBaselineOffsetX"] = baseline_offset_x
            baseline_offset_y = float(origin[1]) - float(baseline_rect.y0)
            target_baseline_y = _pdfjs_moved_target_line_baseline_y(
                page,
                ann,
                to_rect(page, ann),
                source_font,
                source_size,
            )
            if target_baseline_y is not None:
                current_rect = to_rect(page, ann)
                if current_rect is not None and not current_rect.is_empty:
                    baseline_offset_y = target_baseline_y - current_rect.y0
            resolved["pdfjsSourceBaselineOffsetY"] = baseline_offset_y
        except Exception:
            pass
    resolved["pdfjsUseSourceTypography"] = True
    return resolved


def normalize_font_family(value: Any) -> str:
    exact_family = normalize_exact_font_family(value)
    if exact_family in FONT_FILE_VARIANTS:
        return exact_family
    lower = str(value or "").strip().lower().replace('"', "").replace("'", "")
    lower = lower.replace(" ", "").replace("-", "")

    # Match popular Google Fonts first (long/specific tokens come first in
    # GOOGLE_FONT_NORMALIZE_TOKENS so e.g. "robotocondensed" wins over
    # "roboto").
    for token, family in GOOGLE_FONT_NORMALIZE_TOKENS:
        if token in lower:
            return family

    if "arimo" in lower:
        return "Arimo"
    if "lato" in lower:
        return "Lato"
    if "montserrat" in lower:
        return "Montserrat"
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
    if "montserrat" in lower:
        return "Montserrat, Helvetica, Arial, sans-serif"
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
    if family == "Montserrat":
        return "Montserrat, Helvetica, Arial, sans-serif"
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
    # Popular Google Fonts — exposed in /edit-new editor dropdown.
    GOOGLE_CSS_FAMILIES = {
        "Inter":           "Inter, Helvetica, Arial, sans-serif",
        "Nunito":          "Nunito, Helvetica, Arial, sans-serif",
        "Merriweather":    "Merriweather, Georgia, serif",
        "PlayfairDisplay": '"Playfair Display", Georgia, serif',
        "Raleway":         "Raleway, Helvetica, Arial, sans-serif",
        "WorkSans":        '"Work Sans", Helvetica, Arial, sans-serif',
        "NotoSans":        '"Noto Sans", Helvetica, Arial, sans-serif',
        "NotoSerif":       '"Noto Serif", Georgia, serif',
        "Oswald":          "Oswald, Impact, sans-serif",
        "RobotoSlab":      '"Roboto Slab", Georgia, serif',
        "RobotoMono":      '"Roboto Mono", Consolas, monospace',
        "RobotoCondensed": '"Roboto Condensed", Arial, sans-serif',
        "Ubuntu":          "Ubuntu, Helvetica, Arial, sans-serif",
        "Rubik":           "Rubik, Helvetica, Arial, sans-serif",
        "DMSans":          '"DM Sans", Helvetica, Arial, sans-serif',
        "Mulish":          "Mulish, Helvetica, Arial, sans-serif",
        "Quicksand":       "Quicksand, Helvetica, Arial, sans-serif",
        "Kanit":           "Kanit, Helvetica, Arial, sans-serif",
        "FiraSans":        '"Fira Sans", Helvetica, Arial, sans-serif',
        "Lora":            "Lora, Georgia, serif",
        "Cabin":           "Cabin, Helvetica, Arial, sans-serif",
        "Heebo":           "Heebo, Helvetica, Arial, sans-serif",
        "Karla":           "Karla, Helvetica, Arial, sans-serif",
        "Manrope":         "Manrope, Helvetica, Arial, sans-serif",
        "JosefinSans":     '"Josefin Sans", Helvetica, Arial, sans-serif',
        "Dosis":           "Dosis, Helvetica, Arial, sans-serif",
        "Barlow":          "Barlow, Helvetica, Arial, sans-serif",
        "BebasNeue":       '"Bebas Neue", Impact, sans-serif',
        "PTSans":          '"PT Sans", Helvetica, Arial, sans-serif',
        "CrimsonText":     '"Crimson Text", Georgia, serif',
        "Hind":            "Hind, Helvetica, Arial, sans-serif",
        "Mukta":           "Mukta, Helvetica, Arial, sans-serif",
    }
    if family in GOOGLE_CSS_FAMILIES:
        return GOOGLE_CSS_FAMILIES[family]
    return "Helvetica, Arimo, Arial, sans-serif"


_HTML_FONT_FACE_CSS_CACHE: dict[tuple, str] = {}


def build_html_font_face_css(families: tuple | None = None) -> str:
    """Emit @font-face declarations for the given normalized families (or all
    families if `families` is None). Variable-font weight/italic faces are
    materialized to static TTF instances on demand because MuPDF's HTML
    engine ignores @font-face `font-weight` when the source is a VF — it
    would otherwise render every face using the file's default fvar instance
    (e.g. Merriweather's default = Light wght=300 → bold spans render Light).
    Results are cached per family-set on the assumption that exports re-use
    the same handful of families repeatedly per process.
    """
    cache_key = tuple(sorted(families)) if families else ("__ALL__",)
    cached = _HTML_FONT_FACE_CSS_CACHE.get(cache_key)
    if cached is not None:
        return cached
    families_filter = set(families) if families else None
    css_rules: list[str] = []
    for normalized_family, aliases in HTML_FONT_FAMILY_ALIASES.items():
        if families_filter is not None and normalized_family not in families_filter:
            continue
        variants = FONT_FILE_VARIANTS.get(normalized_family, {})
        regular_file = variants.get("normal")
        bold_file = variants.get("bold") or regular_file
        italic_file = variants.get("italic") or regular_file
        bold_italic_file = variants.get("boldItalic") or bold_file or italic_file or regular_file
        # Materialize static instances now (lazy, on-demand). Non-VF files
        # short-circuit immediately inside _materialize_variable_font_instance.
        regular_file = _materialize_variable_font_instance(regular_file, 400, italic=False) if regular_file else regular_file
        bold_file = _materialize_variable_font_instance(bold_file, 700, italic=False) if bold_file else bold_file
        italic_file = _materialize_variable_font_instance(italic_file, 400, italic=True) if italic_file else italic_file
        bold_italic_file = _materialize_variable_font_instance(bold_italic_file, 700, italic=True) if bold_italic_file else bold_italic_file
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
    result = "\n".join(css_rules)
    _HTML_FONT_FACE_CSS_CACHE[cache_key] = result
    return result


# Lazy module-level placeholder retained for backwards compatibility with any
# callers reading the constant. Resolves to the *full* (all-families) CSS only
# when read for the first time. Per-family callers should use the function
# directly to avoid materializing VFs they don't need.
class _LazyHtmlFontFaceCss(str):
    """A str subclass that behaves like the full all-families CSS string but
    builds it on first use rather than at import time.
    """
    def __new__(cls):
        return super().__new__(cls, "")
    def _build(self) -> str:
        return build_html_font_face_css()
    def __str__(self) -> str:
        return self._build()
    def __format__(self, spec) -> str:
        return format(self._build(), spec)
    def __repr__(self) -> str:
        return repr(self._build())


HTML_FONT_FACE_CSS = _LazyHtmlFontFaceCss()


def _promoted_annotation_was_resized(ann: Dict[str, Any]) -> bool:
    """True if the annotation's current PDF box differs meaningfully from its
    extracted source block — i.e. the user dragged a resize handle. Used to
    decide whether to preserve the original PDF line geometry or reflow into
    the new bounding box on export.
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
    # A 2pt tolerance absorbs extraction float jitter; anything larger is a
    # real user-driven resize.
    if abs(cur_w - src_w) > 2.0:
        return True
    if cur_h > 0 and src_h > 0 and abs(cur_h - src_h) > 2.0:
        return True
    return False


def should_preserve_promoted_source_lines(ann: Dict[str, Any], text: str) -> bool:
    if not bool(ann.get("promotedFromExtraction")):
        return False

    # If the user resized the annotation box the export must reflow into the new
    # bounds. Preserving the original PDF layout here (white-space:pre + x1 =
    # page.rect.x1) causes the text to spread horizontally past the new box.
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

    if (
        bool(ann.get("promotedDirty"))
        and len(normalized_text_lines) > 1
        and len(normalized_text_lines) <= source_line_count
    ):
        return True

    if bool(ann.get("promotedReflowEnabled")):
        return False

    if len(normalized_text_lines) == source_line_count:
        return True

    if bool(ann.get("promotedDirty")):
        return False

    if (
        len(normalized_text_lines) == 1
        and isinstance(raw_source_lines, list)
        and len(raw_source_lines) == source_line_count
    ):
        return True

    return False


def resolve_promoted_source_typography_annotation(ann: Dict[str, Any]) -> Dict[str, Any]:
    if not bool(ann.get("promotedFromExtraction")) or bool(ann.get("styleDirty")):
        return ann

    source_spans = ann.get("sourceSpans")
    if not isinstance(source_spans, list) or not source_spans:
        return ann

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
        return ann

    exact_name = str(
        dominant_span.get("embedded_font_name")
        or dominant_span.get("font")
        or ann.get("fontSourceName")
        or ann.get("fontFamily")
        or ""
    ).strip()
    source_family = str(
        dominant_span.get("embedded_font_family")
        or dominant_span.get("font")
        or ann.get("fontFamily")
        or ""
    ).strip()
    try:
        source_font_size = float(
            dominant_span.get("fontSize")
            or dominant_span.get("font_size")
            or ann.get("fontSize")
            or 0
        )
    except Exception:
        source_font_size = 0.0
    try:
        source_line_height = max(
            float(ann.get("lineHeight") or 0.0),
            float(source_font_size or 0.0) * 1.18,
            max(
                0.0,
                float(dominant_span.get("bbox")[3]) - float(dominant_span.get("bbox")[1]),
            ) if isinstance(dominant_span.get("bbox"), (list, tuple)) and len(dominant_span.get("bbox")) >= 4 else 0.0,
        )
    except Exception:
        source_line_height = max(float(ann.get("lineHeight") or 0.0), float(source_font_size or 0.0) * 1.18)

    resolved = dict(ann)
    if exact_name:
        resolved["fontSourceName"] = exact_name
    normalized_family = normalize_font_family(exact_name or source_family)
    if normalized_family:
        resolved["fontFamily"] = normalized_family
    if source_font_size > 0:
        resolved["fontSize"] = source_font_size
    if source_line_height > 0:
        resolved["lineHeight"] = source_line_height
    resolved["fontWeight"] = str(
        dominant_span.get("fontWeight")
        or dominant_span.get("font_weight")
        or ann.get("fontWeight")
        or "400"
    )
    resolved["fontStyle"] = str(
        dominant_span.get("fontStyle")
        or dominant_span.get("font_style")
        or ann.get("fontStyle")
        or "normal"
    )
    source_color = (
        dominant_span.get("hex_color")
        if dominant_span.get("hex_color") is not None
        else dominant_span.get("color")
    )
    if source_color:
        resolved["textColor"] = str(source_color)
    return resolved


_ANNOT_INLINE_ITALIC_FAMILY = "AnnotInlineItalic"
_ANNOT_INLINE_BOLD_ITALIC_FAMILY = "AnnotInlineBoldItalic"


def _resolve_inline_italic_font_files(ann: Dict[str, Any]) -> Tuple[Optional[str], Optional[str]]:
    """Return (italic_path, boldItalic_path) for the annotation's family.

    MuPDF's HTML renderer cannot pick an @font-face italic variant via
    ``font-style: italic`` on inline spans — it only switches faces by
    explicit ``font-family``. To support per-selection italic styling inside
    rich-HTML annotations we therefore register the italic and bold-italic
    files of the annotation's chosen family under unique alias names and
    rewrite the rich HTML to use those aliases (see
    ``_rewrite_richhtml_inline_italic`` below).
    """
    raw_family = str(ann.get("fontFamily") or "").strip() or "Helvetica"
    normalized = normalize_font_family(raw_family) or raw_family
    variants = FONT_FILE_VARIANTS.get(normalized) or FONT_FILE_VARIANTS.get(raw_family) or {}
    italic_path = variants.get("italic") if variants else None
    bold_italic_path = variants.get("boldItalic") if variants else None
    if not italic_path:
        # Fall back to the Helvetica variants which are guaranteed to exist
        # so MuPDF still has a face to substitute (worst case == upright).
        italic_path = FONT_FILE_VARIANTS["Helvetica"]["italic"]
    if not bold_italic_path:
        bold_italic_path = FONT_FILE_VARIANTS["Helvetica"]["boldItalic"]
    if italic_path and not os.path.exists(italic_path):
        italic_path = None
    if bold_italic_path and not os.path.exists(bold_italic_path):
        bold_italic_path = None
    return italic_path, bold_italic_path


def _build_inline_italic_font_face_css(ann: Dict[str, Any]) -> str:
    italic_path, bold_italic_path = _resolve_inline_italic_font_files(ann)
    rules: list[str] = []
    if italic_path:
        rules.append(
            "@font-face { "
            f'font-family: "{_ANNOT_INLINE_ITALIC_FAMILY}"; '
            f"src: url({os.path.basename(italic_path)}); "
            "}"
        )
    if bold_italic_path:
        rules.append(
            "@font-face { "
            f'font-family: "{_ANNOT_INLINE_BOLD_ITALIC_FAMILY}"; '
            f"src: url({os.path.basename(bold_italic_path)}); "
            "}"
        )
    return "\n".join(rules)


def build_annotation_htmlbox_css(ann: Dict[str, Any], font_size: float, opacity: float) -> str:
    font_weight = resolve_annotation_font_weight(ann)
    font_style = resolve_annotation_font_style(ann)
    text_align = str(ann.get("textAlign") or "left").strip().lower()
    text_decoration = "underline" if resolve_annotation_underline(ann) else "none"
    font_family = css_font_family(ann.get("fontFamily"))
    text_color = str(ann.get("textColor") or "#000000").strip() or "#000000"
    # The htmlbox path renders text only — the background rectangle is drawn
    # separately by draw_text() before the htmlbox call, where the white-text-
    # on-white-bg slate placeholder substitution happens. So nothing to do here
    # for the bg; we only keep the text color as-is. (Critically, do NOT flip
    # the text color to black: the editor displays white text on the slate
    # placeholder, and the downloaded PDF must match that.)
    preserve_extracted_lines = should_preserve_promoted_source_lines(ann, ann.get("text") or "")
    try:
        line_height_value = float(ann.get("lineHeight") or 0)
    except Exception:
        line_height_value = 0.0
    if not preserve_extracted_lines:
        line_height_value = max(line_height_value, float(font_size or 0.0) * 1.18)
    # Browser side computes line-height as fontSizePx * 1.18 (see
    # blockLineHeightPx in edit-new.blade.php). When the annotation has no
    # explicit lineHeight persisted, match that ratio with a unitless value so
    # rich-html spans with different per-span font-sizes also scale correctly
    # (an absolute pt value would lock every span to the same line-height).
    # MuPDF's "normal" defaults diverge from the browser and produced visibly
    # tighter line spacing in the exported PDF vs. the editor.
    line_height_css = f"{line_height_value}pt" if line_height_value > 0 else "1.18"
    white_space = "pre" if preserve_extracted_lines else "pre-wrap"
    overflow_wrap = "normal" if preserve_extracted_lines else "break-word"
    word_break = "normal" if preserve_extracted_lines else "break-word"
    inline_italic_face_css = _build_inline_italic_font_face_css(ann)
    # Only emit @font-face for the family this annotation actually uses.
    # build_html_font_face_css() lazily materializes static instances of any
    # variable fonts in that family — restricting the scope keeps first-export
    # cost bounded (each VF instancing pass is ~20s).
    annotation_family_key = normalize_font_family(ann.get("fontFamily"))
    font_face_css = build_html_font_face_css(families=(annotation_family_key,))
    return (
        f"{font_face_css}\n"
        f"{inline_italic_face_css}\n"
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
    """Remove screen-pixel layout adjustments from inline styles.

    The richTextHtml editor persists per-line CSS that was authored at the
    browser's current zoom level: ``font-size`` and ``line-height``/
    ``min-height`` in screen pixels, plus a ``transform: translateY(Xpx)`` /
    ``transform-origin`` pair the editor uses to vertically nudge the
    contenteditable so it visually centers inside the .rich-html-item
    container.  PyMuPDF's insert_htmlbox treats CSS px as an absolute unit at
    a different scale than the browser, so those values produce wrong sizes,
    inflated line spacing, AND a stray vertical shift that moves the text up
    out of its bounding box (visible as text drifting outside a shape it sat
    inside in the editor).  The outer .annotation-box CSS already sets the
    correct font-size and line-height in PDF points; stripping the per-element
    px overrides AND the editor-side transform lets that outer sizing apply
    to all text, matching what the user saw in the editor.
    """
    import re as _re

    _PX_PROP_RE = _re.compile(
        r"\b(?:font-size|line-height|min-height)\s*:\s*[\d.]+px\s*;?",
        flags=_re.IGNORECASE,
    )
    # Editor-pixel transforms (translateY etc.) and their origin must go too.
    # Their entire declaration is removed (we never want them in the PDF
    # render even if the unit is non-px, because they were tuned against the
    # editor's screen-pixel font metrics, not the PDF point metrics).
    _TRANSFORM_PROP_RE = _re.compile(
        r"\b(?:transform|transform-origin)\s*:[^;\"]*;?",
        flags=_re.IGNORECASE,
    )

    def _clean_style(m: "re.Match[str]") -> str:
        style = m.group(1)
        cleaned = _PX_PROP_RE.sub("", style)
        cleaned = _TRANSFORM_PROP_RE.sub("", cleaned)
        # Collapse any double-semicolons left behind
        cleaned = _re.sub(r";\s*;+", ";", cleaned).strip("; ")
        if cleaned.strip():
            return f'style="{cleaned}"'
        return ""  # Drop the attribute entirely when nothing remains

    # Touch any style="..." attribute that contains either a stripped px sizing
    # property OR an editor-pixel transform.
    return _re.sub(
        r'\bstyle="([^"]*\b(?:font-size|line-height|min-height|transform|transform-origin)\b[^"]*)"',
        _clean_style,
        html_str,
        flags=_re.IGNORECASE,
    )


def _rewrite_richhtml_inline_italic(html_str: str) -> str:
    """Translate inline italic markers into explicit font-family overrides.

    MuPDF's HTML engine ignores ``font-style: italic``, ``<i>``, and ``<em>``
    when picking @font-face variants — it only switches faces by
    ``font-family``. To make per-selection italic styling actually render in
    the downloaded PDF we rewrite the rich HTML so every italic-marker span
    carries a ``font-family`` override pointing at the per-annotation italic
    (or bold-italic) family alias registered by
    ``_build_inline_italic_font_face_css``.

    The same engine also ignores the long-form ``text-decoration-line``
    property (only the legacy ``text-decoration`` shorthand draws an
    underline stroke), so we normalise that here too.

    The transformation is intentionally regex-based and idempotent: it never
    drops existing styling, just augments it.
    """
    if not html_str:
        return html_str

    # MuPDF only honours the legacy `text-decoration` shorthand for
    # underline drawing. Browsers emit `text-decoration-line: underline`
    # for per-selection underline styling, which is silently ignored.
    html_str = re.sub(
        r"text-decoration-line\s*:",
        "text-decoration:",
        html_str,
        flags=re.IGNORECASE,
    )

    italic_family = f'"{_ANNOT_INLINE_ITALIC_FAMILY}"'
    bold_italic_family = f'"{_ANNOT_INLINE_BOLD_ITALIC_FAMILY}"'

    def _style_has_italic(style_value: str) -> bool:
        s = style_value.lower()
        return ("font-style:italic" in s.replace(" ", "")) or ("font-style:oblique" in s.replace(" ", ""))

    def _style_has_bold(style_value: str) -> bool:
        s = style_value.lower().replace(" ", "")
        if "font-weight:bold" in s:
            return True
        m = re.search(r"font-weight:(\d{3,4})", s)
        if m:
            try:
                return int(m.group(1)) >= 600
            except ValueError:
                return False
        return False

    def _augment_style(style_value: str, family: str) -> str:
        cleaned = style_value.strip().rstrip(";")
        # Drop any existing font-family declaration so ours wins.
        cleaned = re.sub(r"font-family\s*:[^;]*;?", "", cleaned, flags=re.IGNORECASE).strip().rstrip(";")
        prefix = (cleaned + "; ") if cleaned else ""
        return f'{prefix}font-family: {family}'

    def _replace_span(match: "re.Match[str]") -> str:
        style_value = match.group(1)
        if not _style_has_italic(style_value):
            return match.group(0)
        family = bold_italic_family if _style_has_bold(style_value) else italic_family
        new_style = _augment_style(style_value, family)
        return f'<span style="{html.escape(new_style, quote=True)}"'

    # Inject the italic family into every <span style="...font-style:italic..."> tag.
    rewritten = re.sub(
        r'<span\s+style="([^"]*)"',
        _replace_span,
        html_str,
        flags=re.IGNORECASE,
    )

    # Map <i> and <em> tags to italic spans. Nested <b>/<strong> would still
    # mark the run as bold, but the italic family alone is "italic-only" so
    # we conservatively wrap with the italic family; a dedicated
    # bold+italic tag is uncommon in browser-generated rich text so this
    # covers the realistic editor output.
    italic_family_attr = html.escape(f"font-family: {italic_family}", quote=True)
    rewritten = re.sub(
        r'<(i|em)(\s[^>]*)?>',
        lambda m: f'<span style="{italic_family_attr}"{m.group(2) or ""}>',
        rewritten,
        flags=re.IGNORECASE,
    )
    rewritten = re.sub(r'</(i|em)>', '</span>', rewritten, flags=re.IGNORECASE)

    return rewritten


def _rewrite_kerning_spacer_widths_to_pt(html_str: str) -> str:
    """Convert editor-zoom-pixel widths on kerning spacer spans to PDF points.

    The editor emits leader-dot / inter-span gap spacers as
    ``<span data-kerning-offset="GAP_PT" ...
    style="display:inline-block; ... width:GAP_PXpx;min-width:GAP_PXpx; ...">``
    where ``GAP_PT`` is the original PDF-point gap and ``GAP_PX`` is that gap
    scaled by the current editor zoom (so it looks right on screen).

    PyMuPDF's ``insert_htmlbox`` interprets CSS px against its own
    px-per-pt mapping (1px = 0.75pt at 96dpi), not the editor's zoom, so the
    pixel widths produce wildly wrong leader-dot spacing in the downloaded
    PDF (dots collapse together or fly apart depending on zoom).

    The ``data-kerning-offset`` attribute already carries the source-of-truth
    gap in PDF points. Rewrite the inline ``width``/``min-width`` to use
    that value with a ``pt`` unit so MuPDF lays out the gaps at their
    original PDF-point widths regardless of the editor's zoom at save time.
    """
    if not html_str or "data-kerning-offset" not in html_str:
        return html_str
    import re as _re
    span_re = _re.compile(
        r'(<span\b[^>]*\bdata-kerning-offset="([\d.]+)"[^>]*?\bstyle=")([^"]*)(")',
        flags=_re.IGNORECASE,
    )
    width_re = _re.compile(r"\b(min-width|width)\s*:\s*[\d.]+px", flags=_re.IGNORECASE)

    def _rewrite(m: "re.Match[str]") -> str:
        prefix, gap_pt, style, suffix = m.group(1), m.group(2), m.group(3), m.group(4)
        try:
            gap = float(gap_pt)
        except (TypeError, ValueError):
            return m.group(0)
        if gap <= 0:
            return m.group(0)
        replacement = f"{gap:.2f}pt"
        new_style = width_re.sub(lambda mm: f"{mm.group(1)}:{replacement}", style)
        return f"{prefix}{new_style}{suffix}"

    return span_re.sub(_rewrite, html_str)


def build_annotation_htmlbox_markup(ann: Dict[str, Any], text: str) -> str:
    rich_html = sanitize_rich_text_html(ann.get("richTextHtml") or "").strip()
    if rich_html:
        rich_html = _strip_px_font_sizes_from_html(rich_html)
        rich_html = _rewrite_kerning_spacer_widths_to_pt(rich_html)
        rich_html = _rewrite_richhtml_inline_italic(rich_html)
    inner_html = rich_html if rich_html else html.escape(sanitize_pdf_text(text))
    return f'<div class="annotation-box">{inner_html}</div>'


class _RichTextTextNodeCounter(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.text_chunks: list[str] = []

    def handle_data(self, data: str) -> None:
        if data and data.strip():
            self.text_chunks.append(data)


_RICH_TEXT_BLOCK_TAGS = {"div", "p", "li", "ul", "ol"}


def _normalize_rich_text_compare_text(value: Any) -> str:
    normalized = sanitize_pdf_text(value).replace("\r\n", "\n").replace("\r", "\n")
    normalized = re.sub(r"[ \t]+\n", "\n", normalized)
    normalized = re.sub(r"\n[ \t]+", "\n", normalized)
    normalized = re.sub(r"\n{3,}", "\n\n", normalized)
    return normalized.strip()


def _apply_inline_style_state(state: Dict[str, Any], style_value: str) -> Dict[str, Any]:
    next_state = dict(state)
    for declaration in str(style_value or "").split(";"):
        if ":" not in declaration:
            continue
        prop, raw_value = declaration.split(":", 1)
        prop = prop.strip().lower()
        value = raw_value.strip().lower()
        if not prop:
            continue
        if prop == "font-style":
            if "oblique" in value:
                next_state["font_style"] = "oblique"
            elif "italic" in value:
                next_state["font_style"] = "italic"
            elif "normal" in value:
                next_state["font_style"] = "normal"
        elif prop == "font-weight":
            if value == "bold":
                next_state["font_weight"] = "700"
            else:
                match = re.search(r"\d{3,4}", value)
                if match:
                    next_state["font_weight"] = match.group(0)
                elif "normal" in value:
                    next_state["font_weight"] = "400"
        elif prop in {"text-decoration", "text-decoration-line"}:
            compact_value = value.replace(" ", "")
            if "underline" in compact_value:
                next_state["underline"] = True
            elif "none" in compact_value:
                next_state["underline"] = False
        elif prop == "color":
            normalized_color = normalize_css_color(value)
            if normalized_color:
                next_state["color"] = normalized_color
        elif prop == "font-family":
            font_family = parse_inline_font_family(value)
            if font_family:
                next_state["font_family"] = font_family
                next_state["font_source_name"] = font_family
    return next_state


def normalize_css_color(value: Any) -> str:
    text = str(value or "").strip()
    if not text:
        return ""
    if text.startswith("#"):
        hex_digits = text[1:]
        if len(hex_digits) == 3 and re.fullmatch(r"[0-9a-fA-F]{3}", hex_digits):
            return "#" + "".join(ch * 2 for ch in hex_digits).lower()
        if len(hex_digits) == 6 and re.fullmatch(r"[0-9a-fA-F]{6}", hex_digits):
            return "#" + hex_digits.lower()
        return ""

    rgb_match = re.fullmatch(
        r"rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+\s*)?\)",
        text,
        flags=re.IGNORECASE,
    )
    if rgb_match:
        channels = [max(0, min(255, int(part))) for part in rgb_match.groups()[:3]]
        return "#{:02x}{:02x}{:02x}".format(*channels)

    return ""


def parse_inline_font_family(value: Any) -> str:
    families = [
        part.strip().strip('"').strip("'")
        for part in str(value or "").split(",")
    ]
    return next((family for family in families if family), "")


class _RichTextLayoutParser(HTMLParser):
    def __init__(self, base_style: Dict[str, Any]) -> None:
        super().__init__(convert_charrefs=True)
        self._state_stack: list[Dict[str, Any]] = [dict(base_style)]
        self.ops: list[Dict[str, Any]] = []

    def _append_break(self) -> None:
        self.ops.append({"type": "break"})

    def handle_starttag(self, tag: str, attrs: list[tuple[str, Optional[str]]]) -> None:
        lower_tag = str(tag or "").strip().lower()
        if lower_tag == "br":
            self._append_break()
            return

        next_state = dict(self._state_stack[-1])
        if lower_tag in {"i", "em"}:
            next_state["font_style"] = "italic"
        elif lower_tag in {"b", "strong"}:
            next_state["font_weight"] = "700"
        elif lower_tag == "u":
            next_state["underline"] = True

        attrs_map = {str(name or "").strip().lower(): str(value or "") for name, value in attrs}
        next_state = _apply_inline_style_state(next_state, attrs_map.get("style", ""))
        self._state_stack.append(next_state)

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, Optional[str]]]) -> None:
        self.handle_starttag(tag, attrs)
        if str(tag or "").strip().lower() != "br" and len(self._state_stack) > 1:
            self._state_stack.pop()

    def handle_endtag(self, tag: str) -> None:
        lower_tag = str(tag or "").strip().lower()
        if lower_tag in _RICH_TEXT_BLOCK_TAGS:
            self._append_break()
        if len(self._state_stack) > 1:
            self._state_stack.pop()

    def handle_data(self, data: str) -> None:
        if not data:
            return
        normalized_text = sanitize_pdf_text(data).replace("\r\n", "\n").replace("\r", "\n")
        if not normalized_text:
            return
        self.ops.append({
            "type": "text",
            "text": normalized_text,
            **dict(self._state_stack[-1]),
        })


def parse_rich_text_layout_ops(ann: Dict[str, Any]) -> list[Dict[str, Any]]:
    rich_html = sanitize_rich_text_html(ann.get("richTextHtml") or "").strip()
    if not rich_html:
        return []

    parser = _RichTextLayoutParser({
        "font_family": ann.get("fontFamily") or "Helvetica",
        "font_source_name": ann.get("fontSourceName") or ann.get("fontFamily") or "Helvetica",
        "font_size": float(ann.get("fontSize") or 12),
        "font_weight": resolve_annotation_font_weight(ann),
        "font_style": resolve_annotation_font_style(ann),
        "color": normalize_css_color(ann.get("textColor")) or str(ann.get("textColor") or "#000000"),
        "underline": bool(resolve_annotation_underline(ann)),
        "span_rotation": 0.0,
        "documentId": ann.get("documentId"),
        "__documentId": ann.get("__documentId"),
        "promotedFromExtraction": ann.get("promotedFromExtraction"),
        "promotedDirty": ann.get("promotedDirty"),
        "userAuthored": ann.get("userAuthored"),
        "styleDirty": ann.get("styleDirty"),
        "richTextHtml": ann.get("richTextHtml"),
    })
    try:
        parser.feed(_strip_px_font_sizes_from_html(rich_html))
        parser.close()
    except Exception:
        return []

    ops = list(parser.ops)
    while ops and ops[-1].get("type") == "break":
        ops.pop()
    return ops


def _rich_text_layout_ops_to_text(ops: list[Dict[str, Any]]) -> str:
    parts: list[str] = []
    for entry in ops:
        if entry.get("type") == "break":
            parts.append("\n")
        elif entry.get("type") == "text":
            parts.append(sanitize_pdf_text(entry.get("text") or ""))
    return "".join(parts)


def _merge_rich_text_line_chars(chars: list[tuple[str, Dict[str, Any]]]) -> list[Dict[str, Any]]:
    spans: list[Dict[str, Any]] = []
    current_style: Optional[Dict[str, Any]] = None
    current_chars: list[str] = []

    def flush() -> None:
        nonlocal current_style, current_chars
        if current_style is None or not current_chars:
            return
        span = dict(current_style)
        span["text"] = "".join(current_chars)
        spans.append(span)
        current_style = None
        current_chars = []

    for character, style in chars:
        next_style = dict(style)
        if current_style is None or _style_run_signature(next_style) != _style_run_signature(current_style):
            flush()
            current_style = next_style
        current_chars.append(character)

    flush()
    return spans


def wrap_rich_text_layout_ops(
    ops: list[Dict[str, Any]],
    max_width: float,
) -> list[list[Dict[str, Any]]]:
    if max_width <= 0:
        return []

    font_cache: Dict[tuple[Any, ...], fitz.Font] = {}
    lines: list[list[tuple[str, Dict[str, Any]]]] = []
    current_chars: list[tuple[str, Dict[str, Any]]] = []
    current_width = 0.0
    last_break_index: Optional[int] = None

    def finalize_current_line() -> None:
        nonlocal current_chars, current_width, last_break_index
        lines.append(list(current_chars))
        current_chars = []
        current_width = 0.0
        last_break_index = None

    def process_char(character: str, style: Dict[str, Any]) -> None:
        nonlocal current_width, last_break_index
        char_width = _measure_style_run_text_width(character, style, font_cache)
        if current_chars and (current_width + char_width) > (max_width + 0.01):
            if last_break_index is not None and last_break_index > 0:
                overflow_chars = current_chars[last_break_index:] + [(character, dict(style))]
                current_chars[:] = current_chars[:last_break_index]
                finalize_current_line()
                for overflow_char, overflow_style in overflow_chars:
                    process_char(overflow_char, overflow_style)
                return
            finalize_current_line()

        current_chars.append((character, dict(style)))
        current_width += char_width
        if character.isspace():
            last_break_index = len(current_chars)

    for entry in ops:
        if entry.get("type") == "break":
            finalize_current_line()
            continue
        if entry.get("type") != "text":
            continue
        style = {
            "font_family": entry.get("font_family"),
            "font_source_name": entry.get("font_source_name"),
            "font_size": float(entry.get("font_size") or 12),
            "font_weight": entry.get("font_weight"),
            "font_style": entry.get("font_style"),
            "color": entry.get("color"),
            "underline": bool(entry.get("underline")),
            "span_rotation": entry.get("span_rotation") or 0.0,
            "documentId": entry.get("documentId"),
            "__documentId": entry.get("__documentId"),
            "promotedFromExtraction": entry.get("promotedFromExtraction"),
            "promotedDirty": entry.get("promotedDirty"),
            "userAuthored": entry.get("userAuthored"),
            "styleDirty": entry.get("styleDirty"),
            "richTextHtml": entry.get("richTextHtml"),
        }
        for character in sanitize_pdf_text(entry.get("text") or ""):
            if character == "\n":
                finalize_current_line()
                continue
            process_char(character, style)

    if current_chars or not lines:
        finalize_current_line()

    return [_merge_rich_text_line_chars(line_chars) for line_chars in lines]


def build_wrapped_rich_text_span_layout(
    ann: Dict[str, Any],
    text_rect: fitz.Rect,
    available_width: float,
    padding_x: float,
    padding_top: float,
    line_height: float,
    align: int,
    font_ascender: float,
) -> list[Dict[str, Any]]:
    ops = parse_rich_text_layout_ops(ann)
    if not ops:
        return []

    rendered_text = _rich_text_layout_ops_to_text(ops)
    if _normalize_rich_text_compare_text(rendered_text) != _normalize_rich_text_compare_text(ann.get("text") or ""):
        return []

    wrapped_lines = wrap_rich_text_layout_ops(ops, available_width)
    if not wrapped_lines:
        return []

    font_cache: Dict[tuple[Any, ...], fitz.Font] = {}
    layout: list[Dict[str, Any]] = []
    for line_index, line_runs in enumerate(wrapped_lines):
        line_top = text_rect.y0 + padding_top + (line_index * line_height)
        line_bottom = min(text_rect.y1, line_top + line_height)
        line_rect = fitz.Rect(
            text_rect.x0 + padding_x,
            line_top,
            text_rect.x1 - padding_x,
            line_bottom,
        )
        baseline_y = line_top + (float(font_ascender) * float(ann.get("fontSize") or 12))
        total_width = sum(
            _measure_style_run_text_width(run.get("text") or "", run, font_cache)
            for run in line_runs
        )
        draw_x = line_rect.x0
        if align == 1:
            draw_x = line_rect.x0 + max(0.0, (available_width - total_width) / 2.0)
        elif align == 2:
            draw_x = line_rect.x1 - total_width

        spans: list[Dict[str, Any]] = []
        cursor_x = draw_x
        for run in line_runs:
            run_text = sanitize_pdf_text(run.get("text") or "")
            if run_text == "":
                continue
            run_width = _measure_style_run_text_width(run_text, run, font_cache)
            spans.append({
                **run,
                "text": run_text,
                "rect": fitz.Rect(cursor_x, line_top, cursor_x + max(run_width, 0.01), line_bottom),
                "baseline_x": cursor_x,
                "baseline_y": baseline_y,
                "span_rotation": run.get("span_rotation") or 0.0,
            })
            cursor_x += run_width

        layout.append({
            "rect": line_rect,
            "rotation": 0.0,
            "spans": spans,
        })

    return layout


class _RichTextUniformStyleInspector(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.text_parts: list[str] = []
        self.text_segments: list[Dict[str, Any]] = []
        self._state_stack: list[Dict[str, Any]] = [{
            "font_style": "normal",
            "font_weight": "400",
            "underline": False,
        }]

    def _append_newline(self) -> None:
        if self.text_parts and not self.text_parts[-1].endswith("\n"):
            self.text_parts.append("\n")

    def handle_starttag(self, tag: str, attrs: list[tuple[str, Optional[str]]]) -> None:
        lower_tag = str(tag or "").strip().lower()
        if lower_tag == "br":
            self._append_newline()
            return

        next_state = dict(self._state_stack[-1])
        if lower_tag in {"i", "em"}:
            next_state["font_style"] = "italic"
        elif lower_tag in {"b", "strong"}:
            next_state["font_weight"] = "700"
        elif lower_tag == "u":
            next_state["underline"] = True

        attrs_map = {str(name or "").strip().lower(): str(value or "") for name, value in attrs}
        next_state = _apply_inline_style_state(next_state, attrs_map.get("style", ""))
        self._state_stack.append(next_state)

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, Optional[str]]]) -> None:
        self.handle_starttag(tag, attrs)
        if str(tag or "").strip().lower() != "br" and len(self._state_stack) > 1:
            self._state_stack.pop()

    def handle_endtag(self, tag: str) -> None:
        lower_tag = str(tag or "").strip().lower()
        if lower_tag in _RICH_TEXT_BLOCK_TAGS:
            self._append_newline()
        if len(self._state_stack) > 1:
            self._state_stack.pop()

    def handle_data(self, data: str) -> None:
        if not data:
            return
        self.text_parts.append(data)
        if data.strip():
            self.text_segments.append({
                "text": data,
                **dict(self._state_stack[-1]),
            })

    def normalized_text(self) -> str:
        return _normalize_rich_text_compare_text("".join(self.text_parts))


def resolve_uniform_rich_text_styles(ann: Dict[str, Any], text: str) -> Dict[str, Any]:
    rich_html = sanitize_rich_text_html(ann.get("richTextHtml") or "").strip()
    if not rich_html:
        return {}

    parser = _RichTextUniformStyleInspector()
    try:
        parser.feed(rich_html)
        parser.close()
    except Exception:
        return {}

    if parser.normalized_text() != _normalize_rich_text_compare_text(text):
        return {}

    segments = [
        segment
        for segment in parser.text_segments
        if sanitize_pdf_text(segment.get("text") or "").strip()
    ]
    if not segments:
        return {}

    all_italic = all(is_italic_style(segment.get("font_style")) for segment in segments)
    all_oblique = all(
        str(segment.get("font_style") or "").strip().lower() == "oblique"
        for segment in segments
    )
    all_bold = all(is_bold_weight(segment.get("font_weight")) for segment in segments)
    all_underline = all(bool(segment.get("underline")) for segment in segments)

    result: Dict[str, Any] = {}
    if all_italic:
        result["font_style"] = "oblique" if all_oblique else "italic"
    if all_bold:
        result["font_weight"] = "700"
    if all_underline:
        result["underline"] = True
    return result


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

    text = sanitize_pdf_text(ann.get("text") or "")
    uniform_styles = resolve_uniform_rich_text_styles(ann, text)
    uniform_font_style = str(uniform_styles.get("font_style") or "").strip()
    if is_italic_style(uniform_font_style):
        return uniform_font_style

    rich_html = str(ann.get("richTextHtml") or "").strip().lower()
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

    text = str(ann.get("text") or "")
    uniform_styles = resolve_uniform_rich_text_styles(ann, text)
    uniform_font_weight = str(uniform_styles.get("font_weight") or "").strip()
    if is_bold_weight(uniform_font_weight):
        return uniform_font_weight

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

    text = str(ann.get("text") or "")
    uniform_styles = resolve_uniform_rich_text_styles(ann, text)
    if bool(uniform_styles.get("underline")):
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


def _resolve_embedded_weight_sibling(
    metadata: Dict[str, Any],
    raw_exact: str,
    wants_bold: bool,
    wants_italic: bool,
) -> Optional[Dict[str, Any]]:
    """When a rich-text span requests a weight/style that differs from the
    base source font, find the matching sibling face within the same family +
    stretch (e.g. "HelveticaLTStd-Cond" + bold -> "HelveticaLTStd-BoldCond").

    Returns None when the base face already matches the requested weight/style
    (the normal name-scoring path handles those), when the source font can't be
    located in the metadata, or when no better-matching sibling exists.
    """
    if not raw_exact:
        return None

    source_fd = None
    for font_key, font_data in metadata.items():
        if not isinstance(font_data, dict):
            continue
        clean_name = str(font_data.get("clean_name") or font_key or "").strip()
        if clean_name.lower() == raw_exact.lower():
            source_fd = font_data
            break
    if source_fd is None:
        return None

    try:
        src_weight = int(float(source_fd.get("css_weight") or 400))
    except Exception:
        src_weight = 400
    src_style = str(source_fd.get("css_style") or "normal").strip().lower()
    src_is_bold = src_weight >= 600
    src_is_italic = src_style == "italic"

    # Base face already matches the request: let the normal path resolve it.
    if src_is_bold == wants_bold and src_is_italic == wants_italic:
        return None

    family = str(source_fd.get("family") or "").strip().lower()
    if not family:
        return None
    stretch = str(source_fd.get("css_stretch") or "normal").strip().lower()
    target_weight = 700 if wants_bold else 400

    best = None
    best_key: tuple[int, int, int] | None = None
    for font_key, font_data in metadata.items():
        if not isinstance(font_data, dict):
            continue
        if str(font_data.get("family") or "").strip().lower() != family:
            continue
        if str(font_data.get("css_stretch") or "normal").strip().lower() != stretch:
            continue
        try:
            weight_value = int(float(font_data.get("css_weight") or 400))
        except Exception:
            weight_value = 400
        style_text = str(font_data.get("css_style") or "normal").strip().lower()
        is_bold = weight_value >= 600
        is_italic = style_text == "italic"
        key = (
            1 if is_italic == wants_italic else 0,
            1 if is_bold == wants_bold else 0,
            -abs(weight_value - target_weight),
        )
        if best_key is None or key > best_key:
            absolute_path = embedded_font_public_path_to_absolute(font_data.get("file_path"))
            if not absolute_path:
                continue
            best_key = key
            best = {
                "clean_name": str(font_data.get("clean_name") or font_key or "").strip(),
                "family": str(font_data.get("family") or "").strip(),
                "css_weight": weight_value,
                "css_style": style_text,
                "fontfile": absolute_path,
            }

    if best is None:
        return None
    # Only return a sibling that genuinely improves the weight match and isn't
    # just the base face again.
    if (best["css_weight"] >= 600) != wants_bold:
        return None
    if best["clean_name"].lower() == raw_exact.lower():
        return None
    return best


def resolve_embedded_font_entry(ann: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    if _boolish(ann.get("pdfjsAvoidEmbeddedSourceFont")):
        return None
    force_embedded_font = _boolish(ann.get("forceEmbeddedFont")) or _boolish(ann.get("pdfjsForceEmbeddedFont"))
    is_pdfjs_visible_overlay = is_pdfjs_visible_overlay_text(ann)
    if is_pdfjs_visible_overlay and _boolish(ann.get("movedTextOverlay")) and not force_embedded_font:
        return None
    has_exact_pdfjs_source_font = bool(str(ann.get("fontSourceName") or "").strip())
    if is_pdfjs_visible_overlay and not has_exact_pdfjs_source_font:
        return None

    if (
        bool(ann.get("promotedFromExtraction"))
        and not _boolish(ann.get("preserveSourceTypography"))
        and (
            bool(ann.get("promotedDirty"))
            or bool(ann.get("userAuthored"))
            or bool(ann.get("styleDirty"))
            or bool(str(ann.get("richTextHtml") or "").strip())
        )
    ):
        return None

    text_to_stamp = str(ann.get("text") or "")
    preserve_source = _boolish(ann.get("preserveSourceTypography")) and bool(
        ann.get("promotedFromExtraction")
    )

    # ── Priority 1: admin-uploaded FULL source font ────────────────────────
    # If a non-subsetted OTF/TTF for this PostScript name has been dropped
    # into storage/app/fonts/full/, prefer it over everything else. Perfect
    # glyph coverage AND exact face.
    raw_exact_psname = str(ann.get("fontSourceName") or "").strip()
    if (preserve_source or force_embedded_font) and raw_exact_psname:
        full_entry = resolve_full_font_entry(raw_exact_psname, text_to_stamp)
        if full_entry is not None:
            return full_entry

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
    if not force_embedded_font and (should_bypass_embedded_font(raw_exact) or should_bypass_embedded_font(normalized_exact)):
        return None
    if not force_embedded_font and not has_exact_pdfjs_source_font and should_bypass_embedded_font(normalized_family):
        return None
    prefer_exact_promoted_embedded_face = (
        bool(ann.get("promotedFromExtraction"))
        and bool(raw_exact)
        and raw_exact.lower() not in {normalized_exact.lower(), normalized_family.lower()}
    )
    wants_bold = is_bold_weight(resolve_annotation_font_weight(ann))
    wants_italic = is_italic_style(resolve_annotation_font_style(ann))

    # Rich-text spans may request a weight/style that differs from the base
    # source font (e.g. a bold "13." span inside an otherwise regular
    # "HelveticaLTStd-Cond" line). The exact-name scoring below always locks
    # onto the base face because the +120/+100 name bonus dwarfs the ±20 weight
    # bonus, so the bold span would silently render in regular weight. When the
    # base source font's own metadata says its weight/style differs from what
    # is requested, resolve the correct sibling face (same family + stretch,
    # matching weight/style) first.
    sibling_entry = _resolve_embedded_weight_sibling(
        metadata, raw_exact, wants_bold, wants_italic
    )

    best_entry = sibling_entry
    best_score: tuple[int, int, int] | None = None

    for font_key, font_data in (metadata.items() if sibling_entry is None else ()):
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

    # Validate glyph coverage for promoted text-only overwrites. Runtime
    # extracted source fonts are themselves subsets of the original embedded
    # font: they only carry glyphs that appeared in the source PDF. Stamping
    # new text that introduces unseen characters (e.g. "Flying" needs F/g but
    # the source heading is "Application for Employer Identification Number"
    # so the subset has no F or g) renders those chars as `.notdef` boxes.
    # When that happens, try the substitute-alias chain (free metric-
    # compatible bundled font) before giving up to the Arimo fallback.
    if (
        best_entry is not None
        and (preserve_source or force_embedded_font)
        and text_to_stamp
        and not _embedded_font_covers_text(best_entry.get("fontfile"), text_to_stamp)
    ):
        sub_entry = resolve_substitute_font_entry(raw_exact_psname or raw_exact, ann)
        if sub_entry is not None:
            return sub_entry
        return None

    # Even if the extracted subset matched well, when the user asked for
    # source-typography preservation but the subset doesn't cover the text,
    # we landed in the branch above. The remaining cases (no embedded match
    # found at all, but the user still wants the source face) get one more
    # shot via the substitute table.
    if best_entry is None and preserve_source and raw_exact_psname and text_to_stamp:
        sub_entry = resolve_substitute_font_entry(raw_exact_psname, ann)
        if sub_entry is not None:
            return sub_entry

    return best_entry


# ── Full-font + substitute resolvers ───────────────────────────────────────


_FULL_FONT_INDEX: dict[str, str] | None = None
_FONT_SUBSTITUTES: list[dict[str, Any]] | None = None


def _build_full_font_index() -> dict[str, str]:
    index: dict[str, str] = {}
    if not os.path.isdir(FULL_FONT_DIR):
        return index
    try:
        entries = os.listdir(FULL_FONT_DIR)
    except OSError:
        return index
    for name in entries:
        path = os.path.join(FULL_FONT_DIR, name)
        if not os.path.isfile(path):
            continue
        stem, ext = os.path.splitext(name)
        if ext.lower() not in {".otf", ".ttf"}:
            continue
        key = stem.strip().lower()
        if key and key not in index:
            index[key] = path
    return index


def _get_full_font_index() -> dict[str, str]:
    global _FULL_FONT_INDEX
    if _FULL_FONT_INDEX is None:
        _FULL_FONT_INDEX = _build_full_font_index()
    return _FULL_FONT_INDEX


def resolve_full_font_entry(psname: str, text: str) -> Optional[Dict[str, Any]]:
    """Return a font entry for an admin-uploaded full font matching `psname`
    iff that font covers every glyph in `text`. Returns None otherwise."""
    if not psname:
        return None
    index = _get_full_font_index()
    if not index:
        return None
    key = psname.strip().lower()
    path = index.get(key)
    if path is None:
        # Tolerate trailing "-Dem"/"-Demi" inconsistencies between source
        # PDF font name and uploaded file by trying a few normalizations.
        for variant in {key.replace("-dem", "-demi"), key.replace("-demi", "-dem"),
                        key.replace(" ", ""), key.replace("_", "-")}:
            if variant in index:
                path = index[variant]
                break
    if path is None:
        return None
    if text and not _embedded_font_covers_text(path, text):
        # Uploaded font is itself incomplete for the requested text.
        return None
    return {
        "clean_name": os.path.splitext(os.path.basename(path))[0],
        "family": os.path.splitext(os.path.basename(path))[0],
        "css_weight": 400,
        "css_style": "normal",
        "fontfile": path,
        "source": "full",
    }


def _load_font_substitutes() -> list[dict[str, Any]]:
    global _FONT_SUBSTITUTES
    if _FONT_SUBSTITUTES is not None:
        return _FONT_SUBSTITUTES
    rules: list[dict[str, Any]] = []
    try:
        with open(FONT_SUBSTITUTES_PATH, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        for rule in data.get("rules") or []:
            if not isinstance(rule, dict):
                continue
            match = str(rule.get("match") or "").strip()
            family = str(rule.get("family") or "").strip()
            if not match or not family:
                continue
            rules.append({
                "match": match.lower(),
                "family": family,
                "weight": str(rule.get("weight") or "normal").strip().lower(),
                "style": str(rule.get("style") or "normal").strip().lower(),
            })
    except (OSError, json.JSONDecodeError):
        pass
    _FONT_SUBSTITUTES = rules
    return rules


def resolve_substitute_font_entry(psname: str, ann: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    """Map a commercial source font PostScript name onto a bundled free
    metric-compatible substitute by consulting config/font_substitutes.json.
    Returns a font entry pointing at the appropriate bundled file.

    Note: substitutes are intentionally weight/style-aware via the
    annotation's own font weight/style, NOT the substitute rule's defaults,
    so a Bold span still gets a bold variant of the substitute family."""
    if not psname:
        return None
    needle = psname.strip().lower()
    if not needle:
        return None
    rule = None
    for candidate in _load_font_substitutes():
        if candidate["match"] in needle:
            rule = candidate
            break
    if rule is None:
        return None
    family = rule["family"]
    variants = FONT_FILE_VARIANTS.get(family)
    if not variants:
        return None

    wants_bold = is_bold_weight(resolve_annotation_font_weight(ann))
    wants_italic = is_italic_style(resolve_annotation_font_style(ann))
    if wants_bold and wants_italic and variants.get("boldItalic"):
        variant_key = "boldItalic"
    elif wants_italic and variants.get("italic"):
        variant_key = "italic"
    elif wants_bold and variants.get("bold"):
        variant_key = "bold"
    else:
        variant_key = "normal" if "normal" in variants else next(iter(variants))

    path = variants.get(variant_key)
    if not path or not os.path.isfile(path):
        # Fall back to whatever variant of this family does exist.
        for key in ("normal", "bold", "italic", "boldItalic"):
            cand = variants.get(key)
            if cand and os.path.isfile(cand):
                path = cand
                variant_key = key
                break
        else:
            return None

    return {
        "clean_name": f"{family}-{variant_key}",
        "family": family,
        "css_weight": 700 if "bold" in variant_key.lower() else 400,
        "css_style": "italic" if "italic" in variant_key.lower() else "normal",
        "fontfile": path,
        "source": "substitute",
        "substitute_for": psname,
    }


_FONT_CMAP_CACHE: dict[str, set[int]] = {}


def _embedded_font_covers_text(fontfile: Any, text: str) -> bool:
    path = str(fontfile or "").strip()
    if not path or not text:
        return True
    cmap = _FONT_CMAP_CACHE.get(path)
    if cmap is None:
        try:
            from fontTools.ttLib import TTFont
            tt = TTFont(path, lazy=True)
            cmap = set(tt.getBestCmap().keys())
            tt.close()
        except Exception:
            # Without a way to introspect we cannot prove the font is missing
            # glyphs — assume it covers and let the writer attempt the stamp.
            cmap = set()
            _FONT_CMAP_CACHE[path] = cmap
            return True
        _FONT_CMAP_CACHE[path] = cmap
    if not cmap:
        return True
    for ch in text:
        codepoint = ord(ch)
        # Whitespace and common controls are fine even if the cmap doesn't
        # list them explicitly — fitz handles them via the space-width hint.
        if codepoint <= 0x20:
            continue
        if codepoint not in cmap:
            return False
    return True


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


def _pdf_base_font_can_encode_text(text: str) -> bool:
    try:
        str(text or "").encode("latin-1")
        return True
    except Exception:
        return False


def should_prefer_pdf_base_font_for_pdfjs_source_overlay(ann: Dict[str, Any], text: str) -> bool:
    if not is_pdfjs_visible_overlay_text(ann):
        return False
    family = normalize_font_family(ann.get("fontSourceName") or ann.get("fontFamily"))
    if family not in PDF_FONT_VARIANTS:
        return False
    return _pdf_base_font_can_encode_text(text)


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
        active_raw_boxes = list(raw_boxes)
    elif (
        bool(ann.get("promotedDirty"))
        and len(normalized_text_lines) > 1
        and len(normalized_text_lines) <= len(raw_boxes)
    ):
        line_texts, active_raw_boxes, _ = _aligned_dirty_promoted_line_subset(
            [str(line or "") for line in normalized_text_lines],
            raw_boxes,
            source_lines,
        )
    else:
        reconstructed_line_texts = _reconstruct_flattened_source_lines(
            normalized_text_lines,
            [str(line or "") for line in source_lines],
            len(raw_boxes),
        )
        if reconstructed_line_texts:
            line_texts = reconstructed_line_texts
            active_raw_boxes = list(raw_boxes)
        elif len(source_lines) == len(raw_boxes):
            line_texts = [str(line or "") for line in source_lines]
            active_raw_boxes = list(raw_boxes)
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

    style_dirty = bool(ann.get("styleDirty"))
    force_annotation_font_family = style_dirty and (
        bool(raw_annotation_font_family or raw_annotation_font_source_name)
        and (
            not dominant_source_font_family
            or normalize_font_family(annotation_font_family) != normalize_font_family(dominant_source_font_family)
            or normalize_exact_font_family(annotation_font_source_name) != normalize_exact_font_family(dominant_source_font_family)
        )
    )
    force_annotation_text_color = style_dirty and (
        bool(annotation_text_color)
        and bool(dominant_source_color)
        and annotation_text_color.lower() != dominant_source_color.lower()
    )
    force_annotation_font_weight = style_dirty and (
        bool(raw_annotation_font_weight)
        and bool(dominant_source_font_weight)
        and annotation_font_weight.lower() != dominant_source_font_weight.lower()
    )
    force_annotation_font_style = style_dirty and (
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
    force_annotation_font_size = style_dirty and (
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

    spans_by_line: list[list[Dict[str, Any]]] = [[] for _ in active_raw_boxes]
    for span in normalized_source_spans:
        span_top = float(span["bbox"][1])
        span_bottom = float(span["bbox"][3])
        best_index = -1
        best_score = -1.0
        for index, raw_box in enumerate(active_raw_boxes):
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
    for index, raw_box in enumerate(active_raw_boxes):
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


def normalize_exact_source_span_layout(
    ann: Dict[str, Any],
    text: str,
    font_size: float,
    current_rect: Optional[fitz.Rect] = None,
) -> list[Dict[str, Any]]:
    raw_boxes = ann.get("sourceLineBBoxes")
    source_spans = ann.get("sourceSpans")
    raw_source_lines = ann.get("sourceTextLines")
    source_lines = raw_source_lines if isinstance(raw_source_lines, list) else []
    if not isinstance(source_spans, list) or not source_spans:
        return []

    normalized_text_lines = str(text or "").replace("\r\n", "\n").replace("\r", "\n").split("\n")
    if len(normalized_text_lines) == len(raw_boxes or []):
        line_texts = [sanitize_pdf_text(line) for line in normalized_text_lines]
        active_raw_boxes = list(raw_boxes or [])
    elif (
        bool(ann.get("promotedDirty"))
        and len(normalized_text_lines) > 1
        and isinstance(raw_boxes, list)
        and len(normalized_text_lines) <= len(raw_boxes)
    ):
        line_texts, active_raw_boxes, _ = _aligned_dirty_promoted_line_subset(
            [sanitize_pdf_text(line) for line in normalized_text_lines],
            raw_boxes,
            source_lines,
        )
    else:
        line_texts = []
        active_raw_boxes = list(raw_boxes or [])

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

    if bool(ann.get("promotedDirty")) and active_raw_boxes:
        try:
            source_anchor_x = min(
                float(box[0])
                for box in active_raw_boxes
                if isinstance(box, (list, tuple)) and len(box) >= 4
            )
        except Exception:
            pass
        try:
            source_anchor_y = min(
                float(box[1])
                for box in active_raw_boxes
                if isinstance(box, (list, tuple)) and len(box) >= 4
            )
        except Exception:
            pass

    if source_anchor_x is None or source_anchor_y is None:
        source_rects = []
        for span in source_spans:
            if not isinstance(span, dict):
                continue
            bbox = span.get("bbox")
            if not isinstance(bbox, (list, tuple)) or len(bbox) < 4:
                continue
            try:
                source_rects.append(
                    fitz.Rect(float(bbox[0]), float(bbox[1]), float(bbox[2]), float(bbox[3]))
                )
            except Exception:
                continue
        if source_rects:
            if source_anchor_x is None:
                source_anchor_x = min(rect.x0 for rect in source_rects)
            if source_anchor_y is None:
                source_anchor_y = min(rect.y0 for rect in source_rects)

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

    if bool(ann.get("promotedDirty")) and active_raw_boxes:
        try:
            merged_source_rect = None
            for raw_box in active_raw_boxes:
                if not isinstance(raw_box, (list, tuple)) or len(raw_box) < 4:
                    continue
                current_box_rect = fitz.Rect(
                    float(raw_box[0]),
                    float(raw_box[1]),
                    float(raw_box[2]),
                    float(raw_box[3]),
                )
                merged_source_rect = current_box_rect if merged_source_rect is None else (merged_source_rect | current_box_rect)
            if merged_source_rect is not None:
                source_rect = merged_source_rect
        except Exception:
            pass

    translate_x = 0.0
    translate_y = 0.0
    if (
        current_rect is not None
        and isinstance(current_rect, fitz.Rect)
        and source_anchor_x is not None
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
            translate_x = current_rect.x0 - source_anchor_x
            translate_y = current_rect.y0 - source_anchor_y

    def _normalize_color(value: Any, fallback: str) -> str:
        if isinstance(value, int):
            return f"#{(value >> 16) & 0xFF:02x}{(value >> 8) & 0xFF:02x}{value & 0xFF:02x}"
        normalized = str(value or "").strip()
        return normalized or fallback

    def _source_span_glyph_rect(span: Dict[str, Any], rect: fitz.Rect, baseline_y: Optional[float]) -> fitz.Rect:
        if baseline_y is None:
            return rect
        try:
            span_font_size = float(span.get("fontSize") or span.get("font_size") or font_size or 0.0)
            ascender = float(span.get("ascender"))
            descender = float(span.get("descender"))
        except Exception:
            return rect
        if span_font_size <= 0 or ascender <= 0:
            return rect
        descender_below = abs(descender)
        glyph_top = float(baseline_y) - (ascender * span_font_size) - 0.35
        glyph_bottom = float(baseline_y) + (descender_below * span_font_size) + 0.35
        if glyph_bottom <= glyph_top:
            return rect
        return fitz.Rect(rect.x0 - 0.35, min(rect.y0, glyph_top), rect.x1 + 0.35, max(rect.y1, glyph_bottom))

    normalized_spans = []
    for span in source_spans:
        if not isinstance(span, dict):
            continue
        bbox = span.get("bbox")
        if not isinstance(bbox, (list, tuple)) or len(bbox) < 4:
            continue
        try:
            rect = fitz.Rect(float(bbox[0]), float(bbox[1]), float(bbox[2]), float(bbox[3]))
        except Exception:
            continue
        if rect.width <= 0 or rect.height <= 0:
            continue
        text_value = sanitize_pdf_text(span.get("text") or "")
        if not text_value:
            continue
        origin = span.get("origin")
        baseline_x = None
        baseline_y = None
        try:
            if isinstance(origin, (list, tuple)) and len(origin) >= 2:
                baseline_x = float(origin[0])
                baseline_y = float(origin[1])
        except Exception:
            baseline_x = None
            baseline_y = None
        if translate_x != 0.0 or translate_y != 0.0:
            rect = fitz.Rect(rect.x0 + translate_x, rect.y0 + translate_y, rect.x1 + translate_x, rect.y1 + translate_y)
            if baseline_x is not None:
                baseline_x += translate_x
            if baseline_y is not None:
                baseline_y += translate_y
        rect = _source_span_glyph_rect(span, rect, baseline_y)
        normalized_spans.append({
            "text": text_value,
            "rect": rect,
            "baseline_x": baseline_x,
            "baseline_y": baseline_y,
            "font_family": str(span.get("font") or ann.get("fontFamily") or "Helvetica"),
            "font_source_name": str(
                span.get("embedded_font_name")
                or span.get("font")
                or ann.get("fontSourceName")
                or ann.get("fontFamily")
                or "Helvetica"
            ),
            "font_size": float(span.get("fontSize") or span.get("font_size") or font_size or 0),
            "font_weight": str(span.get("fontWeight") or span.get("font_weight") or ann.get("fontWeight") or "400"),
            "font_style": str(span.get("fontStyle") or span.get("font_style") or ann.get("fontStyle") or "normal"),
            "color": _normalize_color(span.get("hex_color") if span.get("hex_color") is not None else span.get("color"), str(ann.get("textColor") or "#000000")),
            "underline": bool(span.get("underline")),
            "span_rotation": infer_exact_source_rotation(span.get("rotation"), span.get("direction"), rect),
        })

    if not normalized_spans:
        return []

    line_rects = []
    if active_raw_boxes:
        for raw_box in active_raw_boxes:
            if not isinstance(raw_box, (list, tuple)) or len(raw_box) < 4:
                continue
            try:
                rect = fitz.Rect(float(raw_box[0]), float(raw_box[1]), float(raw_box[2]), float(raw_box[3]))
            except Exception:
                continue
            if translate_x != 0.0 or translate_y != 0.0:
                rect = fitz.Rect(rect.x0 + translate_x, rect.y0 + translate_y, rect.x1 + translate_x, rect.y1 + translate_y)
            line_rects.append(rect)

    if not line_rects:
        merged_rect = fitz.Rect(normalized_spans[0]["rect"])
        for span in normalized_spans[1:]:
            merged_rect |= span["rect"]
        line_rects = [merged_rect]

    def _overlap_amount(a_top: float, a_bottom: float, b_top: float, b_bottom: float) -> float:
        return max(0.0, min(a_bottom, b_bottom) - max(a_top, b_top))

    spans_by_line: list[list[Dict[str, Any]]] = [[] for _ in line_rects]
    for span in normalized_spans:
        span_top = float(span["rect"].y0)
        span_bottom = float(span["rect"].y1)
        best_index = -1
        best_score = -1.0
        for index, line_rect in enumerate(line_rects):
            overlap = _overlap_amount(span_top, span_bottom, line_rect.y0, line_rect.y1)
            min_height = max(1.0, min(span_bottom - span_top, line_rect.y1 - line_rect.y0))
            score = overlap / min_height
            if score > best_score:
                best_score = score
                best_index = index
        if best_index >= 0 and best_score >= 0.15:
            spans_by_line[best_index].append(span)

    layout: list[Dict[str, Any]] = []
    for index, line_rect in enumerate(line_rects):
        line_spans = sorted(
            spans_by_line[index] if index < len(spans_by_line) else [],
            key=lambda span: (
                float(span["rect"].x0),
                float(span["baseline_x"]) if span.get("baseline_x") is not None else float(span["rect"].x0),
            ),
        )
        if not line_spans:
            continue
        line_rect = fitz.Rect(line_rect)
        for span in line_spans:
            line_rect |= span["rect"]
        current_line_text = line_texts[index] if index < len(line_texts) else ""
        if bool(ann.get("promotedDirty")) and current_line_text:
            if len(line_spans) == 1:
                span = dict(line_spans[0])
                span["text"] = current_line_text
                line_spans = [span]
            elif len(line_spans) >= 2:
                first_span = dict(line_spans[0])
                remaining_text = current_line_text
                first_text = sanitize_pdf_text(first_span.get("text") or "")
                synthetic_spans: list[Dict[str, Any]] = []
                if first_text and remaining_text.startswith(first_text):
                    first_span["text"] = first_text
                    synthetic_spans.append(first_span)
                    remaining_text = remaining_text[len(first_text):].lstrip()
                    if remaining_text:
                        second_span = dict(line_spans[1])
                        second_span["text"] = remaining_text
                        synthetic_spans.append(second_span)
                    line_spans = synthetic_spans
                else:
                    dominant_span = max(
                        line_spans,
                        key=lambda span: max(
                            1.0,
                            float(span["rect"].x1) - float(span["rect"].x0),
                            len(str(span.get("text") or "")),
                        ),
                    )
                    fallback_span = dict(dominant_span)
                    fallback_span["text"] = current_line_text
                    fallback_span["rect"] = fitz.Rect(line_rect)
                    fallback_span["baseline_x"] = float(line_rect.x0)
                    fallback_span["baseline_y"] = (
                        float(dominant_span.get("baseline_y"))
                        if dominant_span.get("baseline_y") is not None
                        else float(line_rect.y1)
                    )
                    line_spans = [fallback_span]
        layout.append({
            "rect": line_rect,
            "rotation": infer_exact_source_line_rotation(line_spans, line_rect),
            "spans": line_spans,
        })

    return layout


def _aligned_dirty_promoted_line_subset(
    current_lines: list[str],
    raw_boxes: Any,
    raw_source_lines: Any,
) -> tuple[list[str], list[Any], int]:
    normalized_current_lines = [
        sanitize_pdf_text(line)
        for line in current_lines
        if sanitize_pdf_text(line)
    ]
    source_boxes = list(raw_boxes or []) if isinstance(raw_boxes, list) else []
    source_lines = (
        [sanitize_pdf_text(line) for line in raw_source_lines]
        if isinstance(raw_source_lines, list)
        else []
    )

    if not normalized_current_lines or not source_boxes:
        return normalized_current_lines, source_boxes, 0
    if len(normalized_current_lines) == len(source_boxes):
        return normalized_current_lines, source_boxes, 0
    if not source_lines or len(source_lines) != len(source_boxes):
        return normalized_current_lines, source_boxes[:len(normalized_current_lines)], 0

    last_possible_start = len(source_lines) - len(normalized_current_lines)
    for start_index in range(max(0, last_possible_start) + 1):
        if source_lines[start_index:start_index + len(normalized_current_lines)] == normalized_current_lines:
            return (
                normalized_current_lines,
                source_boxes[start_index:start_index + len(normalized_current_lines)],
                start_index,
            )

    return normalized_current_lines, source_boxes[:len(normalized_current_lines)], 0


def _style_run_signature(style: Dict[str, Any]) -> tuple[Any, ...]:
    return (
        str(style.get("font_family") or ""),
        str(style.get("font_source_name") or ""),
        round(float(style.get("font_size") or 0.0), 4),
        str(style.get("font_weight") or ""),
        str(style.get("font_style") or ""),
        str(style.get("color") or ""),
        bool(style.get("underline")),
        normalize_quarter_turn_degrees(style.get("span_rotation")),
    )


def _measure_style_run_text_width(
    text: str,
    style: Dict[str, Any],
    font_cache: Optional[Dict[tuple[Any, ...], fitz.Font]] = None,
) -> float:
    if not text:
        return 0.0

    style_ann = {
        "fontFamily": style.get("font_family"),
        "fontSourceName": style.get("font_source_name"),
        "fontWeight": style.get("font_weight"),
        "fontStyle": style.get("font_style"),
        "textColor": style.get("color"),
        "underline": style.get("underline"),
        "documentId": style.get("documentId"),
        "__documentId": style.get("__documentId"),
        "promotedFromExtraction": style.get("promotedFromExtraction"),
        "preserveSourceTypography": style.get("preserveSourceTypography"),
        "promotedDirty": style.get("promotedDirty"),
        "userAuthored": style.get("userAuthored"),
        "styleDirty": style.get("styleDirty"),
        "richTextHtml": style.get("richTextHtml"),
    }
    font_size = float(style.get("font_size") or 0.0)
    if font_size <= 0:
        font_size = 12.0

    cache_key = _style_run_signature(style)
    font = font_cache.get(cache_key) if font_cache is not None else None
    if font is None:
        style_ann["text"] = text
        fontfile = resolve_text_fontfile_with_coverage(style_ann, text)
        if fontfile:
            font = fitz.Font(fontfile=fontfile)
        else:
            font = fitz.Font(resolve_text_fontname(style_ann))
        if font_cache is not None:
            font_cache[cache_key] = font

    return float(font.text_length(text, fontsize=font_size))


def _style_mapping_is_ambiguous(
    source_text: str,
    source_char_styles: list[Dict[str, Any]],
    matcher: difflib.SequenceMatcher,
) -> bool:
    if not source_text or not source_char_styles:
        return False

    for tag, i1, i2, _j1, _j2 in matcher.get_opcodes():
        if tag != "equal":
            continue
        segment = source_text[i1:i2]
        if len(segment.strip()) < 3:
            continue

        occurrence_patterns: set[tuple[tuple[Any, ...], ...]] = set()
        search_index = 0
        while True:
            occurrence_index = source_text.find(segment, search_index)
            if occurrence_index < 0:
                break
            occurrence_patterns.add(tuple(
                _style_run_signature(source_char_styles[occurrence_index + offset])
                for offset in range(len(segment))
            ))
            if len(occurrence_patterns) > 1:
                return True
            search_index = occurrence_index + 1

    return False


def _normalized_style_value(value: Any) -> str:
    return str(value or "").strip().lower()


def _should_use_authoritative_dirty_promoted_base_style(
    ann: Dict[str, Any],
    expected_line_text: str,
    source_line_spans: list[Dict[str, Any]],
    base_style: Dict[str, Any],
) -> bool:
    if has_single_run_rich_text(ann, expected_line_text):
        return True
    if not source_line_spans:
        return True

    base_exact_family = normalize_exact_font_family(
        base_style.get("font_source_name") or base_style.get("font_family")
    )
    source_families = {
        normalize_exact_font_family(span.get("font_source_name") or span.get("font_family"))
        for span in source_line_spans
        if normalize_exact_font_family(span.get("font_source_name") or span.get("font_family"))
    }
    if base_exact_family and source_families and base_exact_family not in source_families:
        return True

    source_weights = {
        _normalized_style_value(span.get("font_weight"))
        for span in source_line_spans
        if _normalized_style_value(span.get("font_weight"))
    }
    base_weight = _normalized_style_value(base_style.get("font_weight"))
    if len(source_weights) == 1 and base_weight and base_weight not in source_weights:
        return True

    source_styles = {
        _normalized_style_value(span.get("font_style"))
        for span in source_line_spans
        if _normalized_style_value(span.get("font_style"))
    }
    base_style_value = _normalized_style_value(base_style.get("font_style"))
    if len(source_styles) == 1 and base_style_value and base_style_value not in source_styles:
        return True

    return False


def _source_line_uses_uniform_value(
    source_line_spans: list[Dict[str, Any]],
    field: str,
) -> tuple[bool, str]:
    values = {
        _normalized_style_value(span.get(field))
        for span in source_line_spans
        if isinstance(span, dict) and _normalized_style_value(span.get(field)) != ""
    }
    if len(values) != 1:
        return False, ""
    return True, next(iter(values))


def _map_source_styles_onto_saved_text(
    current_line_text: str,
    source_line_spans: list[Dict[str, Any]],
    base_style: Dict[str, Any],
) -> list[Dict[str, Any]]:
    current_line_text = sanitize_pdf_text(current_line_text)
    if current_line_text == "":
        return []

    source_chars: list[str] = []
    source_char_styles: list[Dict[str, Any]] = []
    for span in source_line_spans:
        span_text = sanitize_pdf_text(span.get("text") or "")
        if not span_text:
            continue
        span_style = {
            "font_family": span.get("font_family") or base_style.get("font_family"),
            "font_source_name": span.get("font_source_name") or base_style.get("font_source_name"),
            "font_size": float(span.get("font_size") or base_style.get("font_size") or 0.0),
            "font_weight": span.get("font_weight") or base_style.get("font_weight"),
            "font_style": span.get("font_style") or base_style.get("font_style"),
            "color": span.get("color") or base_style.get("color"),
            "underline": bool(span.get("underline")) if span.get("underline") is not None else bool(base_style.get("underline")),
            "span_rotation": span.get("span_rotation") or base_style.get("span_rotation") or 0.0,
            "documentId": base_style.get("documentId"),
            "__documentId": base_style.get("__documentId"),
            "promotedFromExtraction": base_style.get("promotedFromExtraction"),
        }
        for character in span_text:
            source_chars.append(character)
            source_char_styles.append(dict(span_style))

    source_text = "".join(source_chars)
    if not source_text or len(source_char_styles) != len(source_text):
        return [{**base_style, "text": current_line_text}]

    matcher = difflib.SequenceMatcher(a=source_text, b=current_line_text, autojunk=False)
    if _style_mapping_is_ambiguous(source_text, source_char_styles, matcher):
        return [{**base_style, "text": current_line_text}]

    mapped_styles: list[Optional[Dict[str, Any]]] = [None] * len(current_line_text)
    for tag, i1, i2, j1, j2 in matcher.get_opcodes():
        if tag != "equal":
            continue
        for offset in range(min(i2 - i1, j2 - j1)):
            mapped_styles[j1 + offset] = dict(source_char_styles[i1 + offset])

    runs: list[Dict[str, Any]] = []
    current_style: Optional[Dict[str, Any]] = None
    current_chars: list[str] = []

    def flush_run() -> None:
        nonlocal current_style, current_chars
        if current_style is None or not current_chars:
            return
        run = dict(current_style)
        run["text"] = "".join(current_chars)
        runs.append(run)
        current_style = None
        current_chars = []

    for index, character in enumerate(current_line_text):
        style = dict(mapped_styles[index] or base_style)
        if current_style is None or _style_run_signature(style) != _style_run_signature(current_style):
            flush_run()
            current_style = style
        current_chars.append(character)

    flush_run()
    return runs or [{**base_style, "text": current_line_text}]


def validate_style_mapped_span_layout(
    lines: list[Dict[str, Any]],
    expected_lines: list[str],
) -> bool:
    if len(lines) != len(expected_lines):
        return False

    actual_lines: list[str] = []
    for line in lines:
        spans = line.get("spans") or []
        if not spans:
            return False
        line_text = "".join(sanitize_pdf_text(span.get("text") or "") for span in spans)
        if line_text == "":
            return False
        actual_lines.append(line_text)

    normalized_expected = [sanitize_pdf_text(line) for line in expected_lines]
    return actual_lines == normalized_expected


def _rich_text_runs_per_line_for_dirty_promoted(
    ann: Dict[str, Any],
) -> list[list[Dict[str, Any]]]:
    """Parse the annotation's richTextHtml into per-line styled runs.

    Returns ``[]`` when there is no rich text payload, when the parser
    yields a single uniform style across the whole annotation (nothing
    per-run to preserve), or when parsing fails. Each returned run carries
    only the fields the rich text actually specified (font_weight,
    font_style, underline, color) plus ``text`` — callers merge these on
    top of the line's base_style so font_family/size/document context are
    inherited.
    """
    ops = parse_rich_text_layout_ops(ann)
    if not ops:
        return []
    lines: list[list[Dict[str, Any]]] = [[]]
    for op in ops:
        if op.get("type") == "break":
            lines.append([])
            continue
        if op.get("type") != "text":
            continue
        text = sanitize_pdf_text(op.get("text") or "")
        if not text:
            continue
        run: Dict[str, Any] = {"text": text}
        for key in ("font_weight", "font_style", "underline", "color"):
            value = op.get(key)
            if value is None or value == "":
                continue
            run[key] = value
        lines[-1].append(run)
    while lines and not lines[-1]:
        lines.pop()
    if not lines:
        return []
    # If every emitted run shares the same style signature there is no
    # per-run formatting to preserve — let the source-span char-mapper run.
    signatures = {
        _style_run_signature(run)
        for line_runs in lines
        for run in line_runs
    }
    if len(signatures) < 2:
        return []
    return lines


def _rich_runs_text_matches_line(
    runs: list[Dict[str, Any]],
    expected_line_text: str,
) -> bool:
    """True when concatenating run texts reproduces the expected line text
    after whitespace normalization. This guards against editor-side rich
    HTML drift (e.g. the user saved richTextHtml from a previous edit and
    then changed plain `text` to something the rich payload no longer
    matches) — in that case we fall back to the char-mapper.
    """
    def normalize_for_match(value: Any) -> str:
        normalized = _normalize_rich_text_compare_text(value)
        return re.sub(r"[ \t]+", " ", normalized)

    rendered = "".join(sanitize_pdf_text(run.get("text") or "") for run in runs)
    return normalize_for_match(rendered) == normalize_for_match(expected_line_text)


def _match_source_span_face_for_style(
    source_line_spans: list[Dict[str, Any]],
    desired_weight: Any,
    desired_style: Any,
) -> Optional[Dict[str, Any]]:
    """Find the source span whose weight+style best matches the requested
    pair so a rich-text run can adopt that span's embedded font face.

    Used by the dirty-promoted span layout to map per-run weight overrides
    (e.g. "<b>12c</b>") onto the actual -Bd / -It / -BdIt subset the
    source PDF embedded, instead of falling back to the dominant span's
    regular face. Returns ``None`` when no source spans are present or
    when nothing matches both weight and style (caller keeps the dominant
    face in that case so coverage gates still apply).
    """
    if not source_line_spans:
        return None
    desired_bold = is_bold_weight(str(desired_weight or ""))
    desired_italic = is_italic_style(str(desired_style or ""))
    exact_match: Optional[Dict[str, Any]] = None
    weight_only_match: Optional[Dict[str, Any]] = None
    style_only_match: Optional[Dict[str, Any]] = None
    for span in source_line_spans:
        if not isinstance(span, dict):
            continue
        span_bold = is_bold_weight(str(span.get("font_weight") or ""))
        span_italic = is_italic_style(str(span.get("font_style") or ""))
        if span_bold == desired_bold and span_italic == desired_italic:
            if exact_match is None:
                exact_match = span
        elif span_bold == desired_bold and weight_only_match is None:
            weight_only_match = span
        elif span_italic == desired_italic and style_only_match is None:
            style_only_match = span
    return exact_match or weight_only_match or style_only_match


def _source_face_spans_for_annotation(ann: Dict[str, Any]) -> list[Dict[str, Any]]:
    source_spans = ann.get("sourceSpans")
    if not isinstance(source_spans, list):
        return []

    faces: list[Dict[str, Any]] = []
    for span in source_spans:
        if not isinstance(span, dict):
            continue
        font_source_name = str(
            span.get("embedded_font_name")
            or span.get("font")
            or ann.get("fontSourceName")
            or ann.get("fontFamily")
            or ""
        ).strip()
        font_family = str(
            span.get("embedded_font_family")
            or span.get("font")
            or ann.get("fontFamily")
            or font_source_name
            or ""
        ).strip()
        if not font_source_name and not font_family:
            continue
        faces.append({
            "font_family": font_family or font_source_name,
            "font_source_name": font_source_name or font_family,
            "font_size": float(span.get("fontSize") or span.get("font_size") or ann.get("fontSize") or 12),
            "font_weight": str(span.get("fontWeight") or span.get("font_weight") or ann.get("fontWeight") or "400"),
            "font_style": str(span.get("fontStyle") or span.get("font_style") or ann.get("fontStyle") or "normal"),
        })
    return faces


def apply_source_faces_to_rich_span_layout(
    ann: Dict[str, Any],
    layout: list[Dict[str, Any]],
) -> list[Dict[str, Any]]:
    if not (
        bool(ann.get("promotedFromExtraction"))
        and _boolish(ann.get("preserveSourceTypography"))
    ):
        return layout

    source_faces = _source_face_spans_for_annotation(ann)
    if not source_faces:
        return layout

    repaired_layout: list[Dict[str, Any]] = []
    for line_entry in layout:
        if not isinstance(line_entry, dict):
            repaired_layout.append(line_entry)
            continue
        repaired_line = dict(line_entry)
        repaired_spans: list[Dict[str, Any]] = []
        for span_entry in line_entry.get("spans") or []:
            if not isinstance(span_entry, dict):
                continue
            repaired_span = dict(span_entry)
            matched_face = _match_source_span_face_for_style(
                source_faces,
                repaired_span.get("font_weight"),
                repaired_span.get("font_style"),
            )
            if matched_face is not None:
                for face_key in ("font_family", "font_source_name", "font_size"):
                    face_value = matched_face.get(face_key)
                    if face_value is not None and face_value != "":
                        repaired_span[face_key] = face_value
            repaired_spans.append(repaired_span)
        repaired_line["spans"] = repaired_spans
        repaired_layout.append(repaired_line)
    return repaired_layout


def build_dirty_promoted_style_mapped_span_layout(
    ann: Dict[str, Any],
    text: str,
    line_layout: list[Dict[str, Any]],
) -> list[Dict[str, Any]]:
    if not bool(ann.get("promotedFromExtraction")) or not bool(ann.get("promotedDirty")):
        return []
    source_spans = ann.get("sourceSpans")
    raw_boxes = ann.get("sourceLineBBoxes")
    if not isinstance(source_spans, list) or not source_spans:
        return []
    if not isinstance(raw_boxes, list) or not raw_boxes:
        return []

    source_text_spans = [
        span for span in source_spans
        if isinstance(span, dict) and sanitize_pdf_text(span.get("text") or "").strip()
    ]
    if len(source_text_spans) <= 1:
        return []

    expected_lines = [sanitize_pdf_text(line) for line in split_text_preserving_manual_line_breaks(text)]
    if not expected_lines or len(expected_lines) != len(line_layout):
        return []

    raw_source_lines = ann.get("sourceTextLines")
    expected_lines, active_raw_boxes, _ = _aligned_dirty_promoted_line_subset(
        expected_lines,
        raw_boxes,
        raw_source_lines if isinstance(raw_source_lines, list) else [],
    )
    if len(active_raw_boxes) != len(expected_lines):
        return []

    translate_x = 0.0
    translate_y = 0.0
    first_line_rect = line_layout[0].get("rect") if line_layout else None
    first_raw_box = active_raw_boxes[0] if active_raw_boxes else None
    if isinstance(first_line_rect, fitz.Rect) and isinstance(first_raw_box, (list, tuple)) and len(first_raw_box) >= 4:
        try:
            translate_x = float(first_line_rect.x0) - float(first_raw_box[0])
            translate_y = float(first_line_rect.y0) - float(first_raw_box[1])
        except Exception:
            translate_x = 0.0
            translate_y = 0.0

    normalized_source_spans: list[Dict[str, Any]] = []
    for span in source_spans:
        if not isinstance(span, dict):
            continue
        bbox = span.get("bbox")
        if not isinstance(bbox, (list, tuple)) or len(bbox) < 4:
            continue
        try:
            rect = fitz.Rect(
                float(bbox[0]) + translate_x,
                float(bbox[1]) + translate_y,
                float(bbox[2]) + translate_x,
                float(bbox[3]) + translate_y,
            )
        except Exception:
            continue
        if rect.is_empty:
            continue
        origin = span.get("origin")
        baseline_x = None
        baseline_y = None
        try:
            if isinstance(origin, (list, tuple)) and len(origin) >= 2:
                baseline_x = float(origin[0]) + translate_x
                baseline_y = float(origin[1]) + translate_y
        except Exception:
            baseline_x = None
            baseline_y = None
        normalized_source_spans.append({
            "text": sanitize_pdf_text(span.get("text") or ""),
            "rect": rect,
            "baseline_x": baseline_x,
            "baseline_y": baseline_y,
            "font_family": str(span.get("font") or ann.get("fontFamily") or "Helvetica"),
            "font_source_name": str(
                span.get("embedded_font_name")
                or span.get("font")
                or ann.get("fontSourceName")
                or ann.get("fontFamily")
                or "Helvetica"
            ),
            "font_size": float(span.get("fontSize") or span.get("font_size") or ann.get("fontSize") or 12),
            "font_weight": str(span.get("fontWeight") or span.get("font_weight") or ann.get("fontWeight") or "400"),
            "font_style": str(span.get("fontStyle") or span.get("font_style") or ann.get("fontStyle") or "normal"),
            "color": str(span.get("hex_color") or span.get("color") or ann.get("textColor") or "#000000"),
            "underline": bool(span.get("underline")),
            "span_rotation": infer_exact_source_rotation(span.get("rotation"), span.get("direction"), rect),
        })

    if not normalized_source_spans:
        return []

    def _overlap_amount(a_top: float, a_bottom: float, b_top: float, b_bottom: float) -> float:
        return max(0.0, min(a_bottom, b_bottom) - max(a_top, b_top))

    spans_by_line: list[list[Dict[str, Any]]] = [[] for _ in line_layout]
    for span in normalized_source_spans:
        best_index = -1
        best_score = -1.0
        for index, line_entry in enumerate(line_layout):
            line_rect = line_entry.get("rect")
            if not isinstance(line_rect, fitz.Rect):
                continue
            overlap = _overlap_amount(span["rect"].y0, span["rect"].y1, line_rect.y0, line_rect.y1)
            min_height = max(1.0, min(span["rect"].height, line_rect.height))
            score = overlap / min_height
            if score > best_score:
                best_score = score
                best_index = index
        if best_index >= 0 and best_score >= 0.15:
            spans_by_line[best_index].append(span)

    font_cache: Dict[tuple[Any, ...], fitz.Font] = {}
    # Pre-parse multi-run rich text so the per-line loop can prefer the
    # annotation's own per-run weight/style over the source-span char-mapper.
    # The char-mapper does diff-based matching against the original source
    # text, so any *new* characters that don't appear in the source (e.g.
    # changing "5b City..." → "12c        County and max downs") fall back
    # to the line's base_style — losing the bold prefix that the source's
    # first span carried. The rich text payload (either editor-authored or
    # synthesized by DocumentController::synthesizeRichTextFromSourceSpans
    # when overwriting multi-span source annotations) already records the
    # intended per-run formatting, so when it's present and multi-run we
    # honour it directly.
    rich_text_runs_per_line = _rich_text_runs_per_line_for_dirty_promoted(ann)
    mapped_layout: list[Dict[str, Any]] = []
    for index, line_entry in enumerate(line_layout):
        line_rect = line_entry.get("rect")
        if not isinstance(line_rect, fitz.Rect):
            return []

        source_line_spans = sorted(
            spans_by_line[index] if index < len(spans_by_line) else [],
            key=lambda span: (
                float(span["rect"].x0),
                float(span["baseline_x"]) if span.get("baseline_x") is not None else float(span["rect"].x0),
            ),
        )
        base_style = {
            "font_family": line_entry.get("font_family") or ann.get("fontFamily") or "Helvetica",
            "font_source_name": line_entry.get("font_source_name") or ann.get("fontSourceName") or ann.get("fontFamily") or "Helvetica",
            "font_size": float(line_entry.get("font_size") or ann.get("fontSize") or 12),
            "font_weight": line_entry.get("font_weight") or ann.get("fontWeight") or "400",
            "font_style": line_entry.get("font_style") or ann.get("fontStyle") or "normal",
            "color": line_entry.get("color") or ann.get("textColor") or "#000000",
            "underline": bool(line_entry.get("underline")) if line_entry.get("underline") is not None else bool(ann.get("underline")),
            "span_rotation": line_entry.get("rotation") or 0.0,
            "documentId": ann.get("documentId"),
            "__documentId": ann.get("__documentId"),
            "promotedFromExtraction": ann.get("promotedFromExtraction"),
        }

        rich_runs_for_line = None
        if _should_use_authoritative_dirty_promoted_base_style(
            ann,
            expected_lines[index],
            source_line_spans,
            base_style,
        ):
            mapped_runs = [{**base_style, "text": expected_lines[index]}]
        else:
            rich_runs_for_line = (
                rich_text_runs_per_line[index]
                if rich_text_runs_per_line and index < len(rich_text_runs_per_line)
                else None
            )
            if rich_runs_for_line and _rich_runs_text_matches_line(
                rich_runs_for_line, expected_lines[index]
            ):
                mapped_runs = []
                for run in rich_runs_for_line:
                    merged = {**base_style, **run}
                    # The rich text only specifies semantic weight/style
                    # ("font-weight:700"), not which embedded font file to
                    # use. base_style.font_source_name is the *dominant*
                    # span's face (e.g. HelveticaNeueLTStd-Roman) so a
                    # weight=700 run would otherwise still render in the
                    # regular face. Look at the original source spans on
                    # this line for one with matching weight+style and
                    # adopt its font face so the bold prefix actually
                    # picks up the -Bd (or equivalent) variant the source
                    # PDF already embedded.
                    matched_face = _match_source_span_face_for_style(
                        source_line_spans,
                        merged.get("font_weight"),
                        merged.get("font_style"),
                    )
                    if matched_face is not None:
                        for face_key in (
                            "font_family",
                            "font_source_name",
                            "font_size",
                        ):
                            face_value = matched_face.get(face_key)
                            if face_value is None or face_value == "":
                                continue
                            merged[face_key] = face_value
                    mapped_runs.append(merged)
            else:
                mapped_runs = _map_source_styles_onto_saved_text(
                    expected_lines[index],
                    source_line_spans,
                    base_style,
                )
        if not mapped_runs:
            return []

        uniform_source_color, source_color_value = _source_line_uses_uniform_value(source_line_spans, "color")
        base_color_value = _normalized_style_value(base_style.get("color"))
        if bool(ann.get("styleDirty")) and uniform_source_color and base_color_value and base_color_value != source_color_value:
            for run in mapped_runs:
                run["color"] = base_style.get("color")

        baseline_y = line_entry.get("baseline_y")
        if baseline_y is None and source_line_spans:
            origins = [span["baseline_y"] for span in source_line_spans if span.get("baseline_y") is not None]
            if origins:
                baseline_y = float(sum(origins) / len(origins))

        align = line_entry.get("align")
        draw_x = line_entry.get("baseline_x")
        if draw_x is None:
            total_width = sum(_measure_style_run_text_width(run.get("text") or "", run, font_cache) for run in mapped_runs)
            draw_x = float(line_rect.x0)
            if align == 1:
                draw_x = ((line_rect.x0 + line_rect.x1) / 2.0) - (total_width / 2.0)
            elif align == 2:
                draw_x = line_rect.x1 - total_width

        rich_non_space_runs = [
            run for run in mapped_runs
            if sanitize_pdf_text(run.get("text") or "").strip()
        ]
        use_source_span_positions = (
            bool(rich_runs_for_line)
            and bool(source_line_spans)
            and len(rich_non_space_runs) == len(source_line_spans)
            and bool(ann.get("promotedFromExtraction"))
            and bool(ann.get("promotedDirty"))
            and _boolish(ann.get("preserveSourceTypography"))
        )

        spans: list[Dict[str, Any]] = []
        cursor_x = float(draw_x)
        source_position_index = 0
        previous_positioned_rect: Optional[fitz.Rect] = None
        for run in mapped_runs:
            run_text = sanitize_pdf_text(run.get("text") or "")
            if run_text == "":
                continue
            if use_source_span_positions and not run_text.strip():
                run_text = " "
            run_width = _measure_style_run_text_width(run_text, run, font_cache)
            if use_source_span_positions and run_text.strip():
                source_span = source_line_spans[source_position_index]
                source_position_index += 1
                span_rect = fitz.Rect(source_span["rect"])
                span_baseline_x = source_span.get("baseline_x")
                span_baseline_y = source_span.get("baseline_y")
                if span_baseline_x is None:
                    span_baseline_x = span_rect.x0
                if span_baseline_y is None:
                    span_baseline_y = baseline_y
                previous_positioned_rect = span_rect
                spans.append({
                    **run,
                    "text": run_text,
                    "rect": span_rect,
                    "baseline_x": float(span_baseline_x),
                    "baseline_y": span_baseline_y,
                    "span_rotation": run.get("span_rotation") or source_span.get("span_rotation") or line_entry.get("rotation") or 0.0,
                })
                continue

            if use_source_span_positions and not run_text.strip():
                if previous_positioned_rect is not None:
                    cursor_x = float(previous_positioned_rect.x1)
                elif source_position_index < len(source_line_spans):
                    cursor_x = float(source_line_spans[source_position_index]["rect"].x0)

            span_rect = fitz.Rect(cursor_x, line_rect.y0, cursor_x + max(run_width, 0.01), line_rect.y1)
            spans.append({
                **run,
                "text": run_text,
                "rect": span_rect,
                "baseline_x": cursor_x,
                "baseline_y": baseline_y,
                "span_rotation": run.get("span_rotation") or line_entry.get("rotation") or 0.0,
            })
            cursor_x += run_width

        if not spans:
            return []
        mapped_layout.append({
            "rect": line_rect,
            "rotation": line_entry.get("rotation") or 0.0,
            "spans": spans,
        })

    if not validate_style_mapped_span_layout(mapped_layout, expected_lines):
        return []

    return mapped_layout


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
        line_text = (
            sanitize_pdfjs_source_text(line_entry.get("text"))
            if is_pdfjs_visible_overlay_text(ann)
            else sanitize_pdf_text(line_entry.get("text"))
        )
        if not isinstance(line_rect, fitz.Rect):
            continue
        if not line_text:
            continue

        line_ann = dict(ann)
        line_ann["fontFamily"] = line_entry.get("font_family") or ann.get("fontFamily")
        line_ann["fontSourceName"] = line_entry.get("font_source_name") or ann.get("fontSourceName") or line_ann["fontFamily"]
        if _boolish(ann.get("pdfjsAvoidEmbeddedSourceFont")):
            line_ann["fontFamily"] = ann.get("fontFamily") or "Helvetica"
            line_ann["fontSourceName"] = line_ann["fontFamily"]
        line_ann["fontWeight"] = line_entry.get("font_weight") or ann.get("fontWeight")
        line_ann["fontStyle"] = line_entry.get("font_style") or ann.get("fontStyle")
        if line_entry.get("underline") is not None:
            line_ann["underline"] = bool(line_entry.get("underline"))

        line_font_size = float(line_entry.get("font_size") or font_size or 0)
        if line_font_size <= 0:
            line_font_size = font_size

        line_ann["text"] = line_text
        line_fontfile = resolve_text_fontfile_with_coverage(line_ann, line_text)
        if line_fontfile:
            line_font = fitz.Font(fontfile=line_fontfile)
            line_fontname = resolve_text_font_resource_name(line_ann, line_fontfile)
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


def draw_text_using_exact_source_spans(
    page: fitz.Page,
    ann: Dict[str, Any],
    lines: list[Dict[str, Any]],
    opacity: float,
    morph: Optional[Tuple[fitz.Point, fitz.Matrix]] = None,
) -> bool:
    if not lines:
        return False

    for line_entry in lines:
        if not isinstance(line_entry, dict):
            continue
        _prev_span_rect: Optional[fitz.Rect] = None
        _prev_span_text: Optional[str] = None
        for span_entry in line_entry.get("spans") or []:
            if not isinstance(span_entry, dict):
                continue
            span_text = (
                sanitize_pdfjs_source_text(span_entry.get("text"))
                if is_pdfjs_visible_overlay_text(ann)
                else sanitize_pdf_text(span_entry.get("text"))
            )
            span_rect = span_entry.get("rect")
            if not span_text or not isinstance(span_rect, fitz.Rect):
                continue

            span_ann = dict(ann)
            span_ann["fontFamily"] = span_entry.get("font_family") or ann.get("fontFamily")
            span_ann["fontSourceName"] = span_entry.get("font_source_name") or ann.get("fontSourceName") or span_ann["fontFamily"]
            if _boolish(ann.get("pdfjsAvoidEmbeddedSourceFont")):
                span_ann["fontFamily"] = ann.get("fontFamily") or "Helvetica"
                span_ann["fontSourceName"] = span_ann["fontFamily"]
            span_ann["fontWeight"] = span_entry.get("font_weight") or ann.get("fontWeight")
            span_ann["fontStyle"] = span_entry.get("font_style") or ann.get("fontStyle")
            span_ann["textColor"] = span_entry.get("color") or ann.get("textColor") or "#000000"
            span_ann["underline"] = bool(span_entry.get("underline"))

            span_font_size = float(span_entry.get("font_size") or 0)
            if span_font_size <= 0:
                try:
                    span_font_size = float(ann.get("fontSize", 12) or 12)
                except Exception:
                    span_font_size = 12.0

            # Coverage check inside `resolve_embedded_font_entry` validates the
            # selected face against `ann["text"]`. Since each source span uses its
            # own subsetted runtime font (e.g. the bold -Bd subset only contains
            # glyphs that appeared in the source bold text), we must scope the
            # coverage check to JUST this span's text. Otherwise glyphs that
            # appear only in OTHER spans (e.g. 'x'/'s' in the regular tail) would
            # cause this span's bold face to fail coverage and fall back to a
            # generic substitute, losing the bold style.
            span_ann["text"] = span_text
            span_fontfile = resolve_text_fontfile_with_coverage(span_ann, span_text)
            if span_fontfile:
                span_font = fitz.Font(fontfile=span_fontfile)
                span_fontname = resolve_text_font_resource_name(span_ann, span_fontfile)
                page.insert_font(fontname=span_fontname, fontfile=span_fontfile)
            else:
                span_fontname = resolve_text_fontname(span_ann)
                span_font = fitz.Font(span_fontname)

            span_color = hex_to_rgb(span_entry.get("color") or ann.get("textColor") or "#000000")
            span_rotation = normalize_quarter_turn_degrees(
                span_entry.get("span_rotation") or line_entry.get("rotation")
            )
            baseline_y = (
                float(span_entry.get("baseline_y"))
                if span_entry.get("baseline_y") is not None
                else (span_rect.y0 + (float(getattr(span_font, "ascender", 0.8) or 0.8) * span_font_size))
            )
            draw_x = (
                float(span_entry.get("baseline_x"))
                if span_entry.get("baseline_x") is not None
                else span_rect.x0
            )

            # Compensation for stripped leading whitespace.
            # Source PDF extraction sometimes strips leading spaces from a span's text
            # while keeping the span's bbox/origin at the position of those stripped
            # spaces.  When the previous span's right edge abuts this span's left edge
            # (adjacent bboxes), the previous span is a short field-number label
            # (e.g. "1", "2", "6a", "6b") whose glyph fills its bbox exactly, and the
            # substitute-font text width is noticeably smaller than the stored bbox
            # width (≥12 pt, equivalent to ≈5+ spaces at 8pt), the difference
            # represents whitespace that was present in the original but excluded from
            # the stored text.  Shift draw_x right by that difference so the first
            # visible glyph lands where it did in the original PDF, restoring the
            # visual gap between the field number and its description text.
            if _prev_span_rect is not None and _prev_span_text is not None:
                _adj_gap = abs(_prev_span_rect.x1 - span_rect.x0)
                if _adj_gap < 0.5:
                    _bbox_w = span_rect.x1 - span_rect.x0
                    _text_w = span_font.text_length(span_text, fontsize=span_font_size)
                    _excess = _bbox_w - _text_w
                    _prev_stripped = _prev_span_text.strip()
                    _prev_is_field_label = (
                        1 <= len(_prev_stripped) <= 3
                        and all(c.isalnum() for c in _prev_stripped.lower())
                    )
                    if (
                        _excess > 12.0
                        and _prev_is_field_label
                        and draw_x + _excess + _text_w <= span_rect.x1 + 0.5
                    ):
                        draw_x = draw_x + _excess
            _prev_span_rect = span_rect
            _prev_span_text = span_text

            span_target_extent = (
                span_rect.height
                if span_rotation
                else max(0.0, span_rect.x1 - draw_x)
            )
            _use_word_justify = False
            _word_extra_spacing = 0.0
            if span_target_extent > 1.0:
                _measured_w = span_font.text_length(span_text, fontsize=span_font_size)
                if _measured_w > span_target_extent + 0.05:
                    _space_w_reserve = (
                        0.0
                        if span_rotation or " " not in span_text
                        else span_font.text_length(" ", fontsize=span_font_size)
                    )
                    _scale_target = max(
                        span_target_extent - _space_w_reserve,
                        span_target_extent * 0.95,
                    )
                    _scale = _scale_target / _measured_w
                    span_font_size *= _scale
                    if span_entry.get("baseline_y") is None:
                        baseline_y = span_rect.y0 + (float(getattr(span_font, "ascender", 0.8) or 0.8) * span_font_size)
                elif not span_rotation:
                    _space_count = span_text.count(" ")
                    if _space_count > 0 and _measured_w < span_target_extent - 2.0:
                        _space_w_estimate = span_font.text_length(" ", fontsize=span_font_size)
                        _extra_per_space = max(
                            0.0,
                            (span_target_extent - _measured_w - _space_w_estimate) / _space_count,
                        )
                        if 0.0 < _extra_per_space < 3.0:
                            _use_word_justify = True
                            _word_extra_spacing = _extra_per_space

            effective_morph = combine_morphs(
                build_rotation_morph(span_rotation, fitz.Point(draw_x, baseline_y)),
                morph,
            )

            if _use_word_justify:
                _space_w = span_font.text_length(" ", fontsize=span_font_size)
                _cur_x = draw_x
                _words = span_text.split(" ")
                for _wi, _word in enumerate(_words):
                    if _word:
                        page.insert_text(
                            fitz.Point(_cur_x, baseline_y),
                            _word,
                            fontsize=span_font_size,
                            fontname=span_fontname,
                            color=span_color,
                            overlay=True,
                            fill_opacity=opacity,
                            stroke_opacity=opacity,
                            morph=effective_morph,
                        )
                        _cur_x += span_font.text_length(_word, fontsize=span_font_size)
                    if _wi < len(_words) - 1:
                        _cur_x += _space_w + _word_extra_spacing
            else:
                page.insert_text(
                    fitz.Point(draw_x, baseline_y),
                    span_text,
                    fontsize=span_font_size,
                    fontname=span_fontname,
                    color=span_color,
                    overlay=True,
                    fill_opacity=opacity,
                    stroke_opacity=opacity,
                    morph=effective_morph,
                )

            if resolve_annotation_underline(span_ann):
                underline_y = span_rect.y1 - max(0.5, span_font_size * 0.08)
                draw_rotated_line(
                    page,
                    fitz.Point(span_rect.x0, underline_y),
                    fitz.Point(span_rect.x1, underline_y),
                    color=span_color,
                    width=max(0.5, span_font_size * 0.04),
                    opacity=opacity,
                    morph=effective_morph,
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
    if family == "Montserrat" and not is_bold and not is_italic:
        try:
            weight_value = int(float(resolve_annotation_font_weight(ann)))
        except Exception:
            weight_value = 400
        if weight_value <= 350:
            candidate = variants.get("light") or candidate
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
    if not candidate or not os.path.exists(candidate):
        return None
    # If the resolved font file is a variable font (Raleway[wght].ttf,
    # Nunito[wght].ttf, Merriweather...wght.ttf, …) MuPDF's `insert_text`
    # path renders it using the file's *default* fvar instance regardless
    # of the requested weight, so Bold annotations silently render at the
    # default (often Light, e.g. Raleway defaults to wght=100). Materialize
    # a static instance locked to the requested wght/italic axis and use
    # that instead. The materialization is cached on disk under
    # storage/app/temp/font-instances/, so the cost is only paid once per
    # (family, weight, italic) triple.
    target_weight = 700 if is_bold else 400
    materialized = _materialize_variable_font_instance(candidate, target_weight, italic=is_italic)
    return materialized if materialized and os.path.exists(materialized) else candidate


def resolve_text_fontfile_with_coverage(ann: Dict[str, Any], text: str) -> Optional[str]:
    """Resolve a font file only if it can encode the exact text to draw.

    Runtime-extracted PDF fonts are usually subsets. A style match is not
    enough: if the subset does not contain every glyph in this run, using it
    emits .notdef boxes in the exported PDF.
    """
    scoped_ann = dict(ann)
    scoped_ann["text"] = text
    fontfile = resolve_text_fontfile(scoped_ann)
    if fontfile and _embedded_font_covers_text(fontfile, text):
        return fontfile

    source_name = str(scoped_ann.get("fontSourceName") or scoped_ann.get("fontFamily") or "").strip()
    substitute = resolve_substitute_font_entry(source_name, scoped_ann)
    sub_fontfile = substitute.get("fontfile") if substitute else None
    if sub_fontfile and _embedded_font_covers_text(sub_fontfile, text):
        return sub_fontfile

    fallback_ann = dict(scoped_ann)
    fallback_ann["pdfjsAvoidEmbeddedSourceFont"] = True
    fallback_ann["fontSourceName"] = fallback_ann.get("fontFamily") or "Helvetica"
    fallback_fontfile = resolve_text_fontfile(fallback_ann)
    if fallback_fontfile and _embedded_font_covers_text(fallback_fontfile, text):
        return fallback_fontfile

    return None


def _font_resource_file_suffix(fontfile: Any) -> str:
    path = str(fontfile or "").strip()
    if not path:
        return ""
    resolved = os.path.realpath(path)
    digest = hashlib.sha1(resolved.encode("utf-8", errors="ignore")).hexdigest()[:10]
    return f"F{digest}"


def resolve_text_font_resource_name(ann: Dict[str, Any], fontfile: Any = None) -> str:
    file_suffix = _font_resource_file_suffix(fontfile)
    embedded_entry = resolve_embedded_font_entry(ann)
    if embedded_entry:
        clean_name = str(embedded_entry.get("clean_name") or "Embedded").strip()
        safe_name = "".join(ch for ch in clean_name if ch.isalnum()) or "Embedded"
        return f"Annot{safe_name}{file_suffix}"
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
    return f"Annot{family}{suffix}{file_suffix}"


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


def wrap_text_to_width(
    font: fitz.Font,
    text: str,
    font_size: float,
    max_width: float,
    preserve_blank_lines: bool = False,
) -> list[str]:
    # Mirror the edit-new.blade.php renderer (renderPlainEditorHTML):
    #   const normalized = String(text ?? '')
    #       .replace(/\r\n?/g, '\n')
    #       .replace(/\n{2,}/g, '\n');   // collapse 2+ consecutive newlines to 1
    #   const paragraphs = normalized.split('\n');
    #   innerHtml = paragraphs.map(p => escapeHtml(p) || '<br>').join('<br>');
    # Every "\n" is therefore a HARD line break; multiple consecutive newlines
    # collapse to a single hard break (no extra blank line). Leading whitespace
    # inside each paragraph is preserved (white-space: pre-wrap in the editor),
    # which matters for indented numbered lists like "    1. blah".
    safe_text = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    if not preserve_blank_lines:
        while "\n\n" in safe_text:
            safe_text = safe_text.replace("\n\n", "\n")
    paragraphs = safe_text.split("\n")
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

    for paragraph in paragraphs:
        if paragraph == "":
            lines.append("")
            continue
        # Preserve leading whitespace (e.g. "    1. blah") so indented lines
        # don't collapse flush-left after the editor → PDF round-trip.
        leading = ""
        for ch in paragraph:
            if ch == " " or ch == "\t":
                leading += ch
            else:
                break
        rest = paragraph[len(leading):]
        # Tokenize the remainder while preserving runs of spaces between words
        # (so "3.  ok" keeps both spaces, matching white-space: pre-wrap in
        # the editor). Tokens alternate between word and whitespace runs; the
        # wrapping logic below treats a whitespace run as part of the
        # following word's break candidate.
        tokens: list[str] = []
        if rest:
            buf = ""
            in_space = False
            for ch in rest:
                is_space = (ch == " " or ch == "\t")
                if buf == "":
                    buf = ch
                    in_space = is_space
                elif is_space == in_space:
                    buf += ch
                else:
                    tokens.append(buf)
                    buf = ch
                    in_space = is_space
            if buf:
                tokens.append(buf)
        if not tokens:
            # Paragraph was only whitespace.
            lines.append(leading)
            continue
        # Reassemble into [(separator_before_word, word)] pairs. The first
        # word's separator is "" (or the leading whitespace if the rest
        # started with spaces).
        pairs: list[tuple[str, str]] = []
        i = 0
        sep = ""
        if tokens and (tokens[0].startswith(" ") or tokens[0].startswith("\t")):
            sep = tokens[0]
            i = 1
        while i < len(tokens):
            word = tokens[i]
            i += 1
            next_sep = ""
            if i < len(tokens) and (tokens[i].startswith(" ") or tokens[i].startswith("\t")):
                next_sep = tokens[i]
                i += 1
            pairs.append((sep, word))
            sep = next_sep
        if not pairs:
            lines.append(leading + sep)
            continue
        first_sep, first_word = pairs[0]
        current_line = leading + first_sep + first_word
        if font.text_length(current_line, fontsize=font_size) > max_width:
            push_wrapped_word(current_line)
            current_line = ""
        for sep_before, word in pairs[1:]:
            candidate = f"{current_line}{sep_before}{word}" if current_line else word
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

    return lines or [""]


def should_drop_preview_side_padding_for_single_line_text(
    ann: Dict[str, Any],
    text: str,
    rect: fitz.Rect,
    font: fitz.Font,
    font_size: float,
    preserve_extracted_lines: bool,
    use_flush_dirty_promoted_padding: bool,
) -> bool:
    if preserve_extracted_lines or use_flush_dirty_promoted_padding:
        return False
    if bool(ann.get("promotedFromExtraction")):
        return False
    if rect is None or rect.is_empty or rect.width <= 0:
        return False

    normalized_text = sanitize_pdf_text(text)
    if normalized_text.strip() == "":
        return False
    if len(split_text_preserving_manual_line_breaks(normalized_text)) != 1:
        return False

    preview_padding_x = min(6.0, max(1.0, rect.width * 0.03))
    if preview_padding_x <= 0:
        return False

    raw_text_width = font.text_length(normalized_text, fontsize=font_size)
    padded_width = max(1.0, rect.width - (preview_padding_x * 2.0))
    return raw_text_width <= (rect.width + 0.01) and raw_text_width > (padded_width + 0.01)


def should_preserve_user_authored_blank_lines(ann: Dict[str, Any]) -> bool:
    if _boolish(ann.get("userCreated")) or _boolish(ann.get("userAuthored")):
        return True
    if _boolish(ann.get("userForcedRichText")):
        return True
    if str(ann.get("pdfjsEditorMode") or "").strip().lower() == "rich":
        return True
    if _boolish(ann.get("skipPdfjsSourceMask")) and not str(ann.get("pdfjsSourceText") or "").strip():
        return True
    return False


def split_text_preserving_manual_line_breaks(text: str) -> list[str]:
    normalized = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    return normalized.split("\n")


def normalized_pdfjs_visual_lines(ann: Dict[str, Any], text: str) -> list[str]:
    raw_lines = ann.get("pdfjsVisualLines")
    if not isinstance(raw_lines, list):
        return []
    lines = [sanitize_pdf_text(line).strip() for line in raw_lines]
    lines = [line for line in lines if line]
    if len(lines) <= 1:
        return []
    def compare(value: Any) -> str:
        return re.sub(r"-\s+", "-", normalize_pdfjs_compare_text(value))

    if compare(" ".join(lines)) != compare(text):
        return []
    return lines


def should_preserve_pdfjs_source_visual_spacing(ann: Dict[str, Any], text: str) -> bool:
    if not _boolish(ann.get("savedTextOverlay")):
        return False
    if not _boolish(ann.get("pdfjsSourceFidelity")):
        return False
    if (
        _boolish(ann.get("userForcedRichText"))
        or str(ann.get("pdfjsEditorMode") or "").strip().lower() == "rich"
        or str(ann.get("richTextPromotionReason") or "").strip().lower() == "manual-resize"
    ):
        return False
    normalized = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    if "\n" in normalized:
        return False
    return bool(re.search(r" {2,}", normalized))


def should_preserve_pdfjs_moved_source_line(ann: Dict[str, Any], text: str) -> bool:
    if not _boolish(ann.get("movedTextOverlay")):
        return False
    if not _boolish(ann.get("savedTextOverlay")):
        return False
    if not _boolish(ann.get("pdfjsSourceFidelity")):
        return False
    if (
        _boolish(ann.get("userForcedRichText"))
        or str(ann.get("pdfjsEditorMode") or "").strip().lower() == "rich"
        or str(ann.get("richTextPromotionReason") or "").strip().lower() == "manual-resize"
    ):
        if not ann.get("pdfjsSourceSpanRuns"):
            return False
    normalized = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    return "\n" not in normalized


def should_preserve_pdfjs_source_inline_flow(ann: Dict[str, Any], text: str) -> bool:
    if not is_pdfjs_visible_overlay_text(ann):
        return False
    if _boolish(ann.get("pdfjsDeleted")) or _boolish(ann.get("movedTextOverlay")):
        return False
    if not _boolish(ann.get("savedTextOverlay")):
        return False
    if _boolish(ann.get("userSizedTextBox")):
        return False
    if str(ann.get("richTextPromotionReason") or "").strip().lower() == "manual-resize":
        return False
    normalized = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    if "\n" in normalized:
        return False
    source_text = normalize_pdfjs_compare_text(ann.get("pdfjsSourceText") or ann.get("originalText") or "")
    current_text = normalize_pdfjs_compare_text(text)
    if source_text and current_text and source_text != current_text:
        return False
    return has_single_run_rich_text(ann, text)


def rich_text_style_runs_for_exact_text(ann: Dict[str, Any], text: str) -> list[Dict[str, Any]]:
    ops = parse_rich_text_layout_ops(ann)
    if not ops:
        return []
    if _normalize_rich_text_compare_text(_rich_text_layout_ops_to_text(ops)) != _normalize_rich_text_compare_text(text):
        return []
    runs: list[Dict[str, Any]] = []
    for entry in ops:
        if entry.get("type") != "text":
            continue
        run_text = sanitize_pdf_text(entry.get("text") or "")
        if not run_text:
            continue
        runs.append({
            "text": run_text,
            "font_family": entry.get("font_family"),
            "font_source_name": entry.get("font_source_name"),
            "font_weight": entry.get("font_weight"),
            "font_style": entry.get("font_style"),
            "color": entry.get("color"),
            "underline": bool(entry.get("underline")),
        })
    return runs


def rich_text_style_at_offset(rich_runs: list[Dict[str, Any]], offset: int) -> Dict[str, Any]:
    cursor = 0
    fallback: Dict[str, Any] = {}
    for run in rich_runs:
        run_text = str(run.get("text") or "")
        if run_text.strip() and not fallback:
            fallback = run
        end = cursor + len(run_text)
        if cursor <= offset < end:
            return run
        cursor = end
    return fallback


def apply_rich_style_to_pdfjs_source_run(run: Dict[str, Any], style: Dict[str, Any]) -> Dict[str, Any]:
    if not style:
        return run
    updated = dict(run)
    for key in ("font_family", "font_source_name", "font_weight", "font_style", "color", "underline"):
        value = style.get(key)
        if value is not None and value != "":
            updated[key] = value
    return updated


def remap_changed_pdfjs_line_to_source_runs(
    ann: Dict[str, Any],
    text: str,
    runs: list[Dict[str, Any]],
) -> list[Dict[str, Any]]:
    if len(runs) != 2:
        return []
    current_text = sanitize_pdfjs_source_text(text)
    if not current_text:
        return []
    first_source_text = sanitize_pdfjs_source_text(runs[0].get("text") or "").strip()
    if not first_source_text or not current_text.startswith(first_source_text):
        return []
    second_text = current_text[len(first_source_text):].lstrip()
    if not second_text:
        return []

    rich_runs = rich_text_style_runs_for_exact_text(ann, current_text)
    second_offset = len(first_source_text) + (len(current_text[len(first_source_text):]) - len(second_text))
    mapped = [
        apply_rich_style_to_pdfjs_source_run({**runs[0], "text": first_source_text}, rich_text_style_at_offset(rich_runs, 0)),
        apply_rich_style_to_pdfjs_source_run({**runs[1], "text": second_text}, rich_text_style_at_offset(rich_runs, second_offset)),
    ]
    return mapped


def normalize_pdfjs_source_span_run_layout(
    ann: Dict[str, Any],
    text: str,
    current_rect: fitz.Rect,
    base_font_size: float,
) -> list[Dict[str, Any]]:
    if not is_pdfjs_visible_overlay_text(ann):
        return []
    if not _boolish(ann.get("movedTextOverlay")):
        return []
    raw = ann.get("pdfjsSourceSpanRuns")
    if not raw:
        return []
    try:
        items = json.loads(str(raw)) if isinstance(raw, str) else raw
    except Exception:
        return []
    if not isinstance(items, list) or len(items) < 2:
        return []

    runs: list[Dict[str, Any]] = []
    for item in items:
        if not isinstance(item, dict):
            continue
        run_text = sanitize_pdfjs_source_text(item.get("text") or "")
        if not run_text:
            continue
        try:
            left = float(item.get("leftPx"))
            right = float(item.get("rightPx"))
            top = float(item.get("topPx"))
            bottom = float(item.get("bottomPx"))
        except Exception:
            continue
        if right <= left or bottom <= top:
            continue
        try:
            font_size_px = float(item.get("fontSizePx") or 0.0)
        except Exception:
            font_size_px = 0.0
        runs.append({
            "text": run_text,
            "left": left,
            "right": right,
            "top": top,
            "bottom": bottom,
            "font_family": str(item.get("fontFamily") or ann.get("fontFamily") or "Helvetica"),
            "font_source_name": str(item.get("fontFamily") or ann.get("fontSourceName") or ann.get("fontFamily") or "Helvetica"),
            "font_size_px": font_size_px,
            "font_weight": str(item.get("fontWeight") or ann.get("fontWeight") or "400"),
            "font_style": str(item.get("fontStyle") or ann.get("fontStyle") or "normal"),
        })
    if len(runs) < 2:
        return []

    reconstructed = ""
    previous = None
    for run in runs:
        if previous is not None:
            gap = max(0.0, float(run["left"]) - float(previous["right"]))
            space_width = max(1.0, float(previous.get("font_size_px") or run.get("font_size_px") or 8.0) * 0.25)
            if gap > max(1.0, space_width * 0.45):
                reconstructed += " "
        reconstructed += str(run["text"])
        previous = run
    if normalize_pdfjs_compare_text(reconstructed) != normalize_pdfjs_compare_text(text):
        remapped_runs = remap_changed_pdfjs_line_to_source_runs(ann, text, runs)
        if not remapped_runs:
            return []
        runs = remapped_runs

    min_left = min(float(run["left"]) for run in runs)
    max_right = max(float(run["right"]) for run in runs)
    min_top = min(float(run["top"]) for run in runs)
    max_bottom = max(float(run["bottom"]) for run in runs)
    source_width = max(1.0, max_right - min_left)
    source_height = max(1.0, max_bottom - min_top)
    max_font_size_px = max(1.0, max(float(run.get("font_size_px") or 0.0) for run in runs))
    x_scale = float(current_rect.width) / source_width if current_rect.width > 0 else 1.0
    y_scale = float(current_rect.height) / source_height if current_rect.height > 0 else 1.0

    spans: list[Dict[str, Any]] = []
    for run in runs:
        font_size = float(base_font_size or 12.0)
        if float(run.get("font_size_px") or 0.0) > 0:
            font_size *= float(run["font_size_px"]) / max_font_size_px
        rect = fitz.Rect(
            current_rect.x0 + ((float(run["left"]) - min_left) * x_scale),
            current_rect.y0 + ((float(run["top"]) - min_top) * y_scale),
            current_rect.x0 + ((float(run["right"]) - min_left) * x_scale),
            current_rect.y0 + ((float(run["bottom"]) - min_top) * y_scale),
        )
        baseline_y = rect.y0 + (font_size * 0.8)
        spans.append({
            "text": run["text"],
            "rect": rect,
            "baseline_x": rect.x0,
            "baseline_y": baseline_y,
            "font_family": run["font_family"],
            "font_source_name": run["font_source_name"],
            "font_size": font_size,
            "font_weight": run["font_weight"],
            "font_style": run["font_style"],
            "color": run.get("color") or ann.get("textColor") or "#000000",
            "underline": bool(run.get("underline")) if run.get("underline") is not None else bool(ann.get("underline")),
            "span_rotation": 0.0,
        })

    return [{
        "rect": current_rect,
        "rotation": 0.0,
        "spans": spans,
    }]


def fit_moved_pdfjs_line_to_page_bounds(
    page: fitz.Page,
    draw_x: float,
    text_width: float,
    line_scale_x: float,
) -> tuple[float, float]:
    if text_width <= 0:
        return draw_x, line_scale_x

    page_left = float(page.rect.x0)
    page_right = float(page.rect.x1)
    if draw_x < page_left:
        draw_x = page_left
    if draw_x >= page_right:
        draw_x = max(page_left, page_right - 1.0)

    available_width = max(0.01, page_right - draw_x)
    scaled_width = text_width * line_scale_x
    if scaled_width > available_width + 0.01:
        line_scale_x = min(line_scale_x, max(0.001, available_width / text_width))
    return draw_x, line_scale_x


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


def _shrink_redact_rect_to_avoid_neighbors(
    page: fitz.Page,
    rect: fitz.Rect,
    page_words: list,
) -> fitz.Rect:
    """Shrink a redact rect so it no longer intersects any neighboring word
    whose centroid lies outside the ORIGINAL rect.

    fitz.apply_redactions removes any text whose word-bbox intersects the
    redact rect (even by 1pt). Tall heading word-bboxes often descend past
    their visual baseline and clip the next row, wiping unrelated words.

    The centroid check is performed against the IMMUTABLE original rect to
    avoid cascading collapse (Bug 1): if we measured against the shrinking
    rect, an earlier eviction could push a legitimate target outside the
    shrunk bounds and cause it to be evicted in turn.

    If a collateral word's bbox engulfs the rect on all four sides we have
    no edge to shrink to (Bug 2: empty candidates → ValueError). In that
    degenerate case we leave the rect alone for this neighbor; the caller
    accepts that some collateral damage is unavoidable rather than crashing.
    """
    if rect is None or rect.is_empty or not page_words:
        return rect
    original = fitz.Rect(rect)
    shrunk = fitz.Rect(rect)
    for w in page_words:
        try:
            x0, y0, x1, y1 = float(w[0]), float(w[1]), float(w[2]), float(w[3])
        except Exception:
            continue
        cx = (x0 + x1) * 0.5
        cy = (y0 + y1) * 0.5
        # Target classification uses the ORIGINAL rect, not `shrunk`.
        if original.x0 <= cx <= original.x1 and original.y0 <= cy <= original.y1:
            continue
        # Skip if the word no longer intersects what's left of the rect.
        if x1 <= shrunk.x0 or x0 >= shrunk.x1 or y1 <= shrunk.y0 or y0 >= shrunk.y1:
            continue
        # Build edge-shrink candidates, grouped by axis. Each entry is
        # (edge, new_value, cost).
        x_candidates = []
        y_candidates = []
        if y0 >= shrunk.y0:
            y_candidates.append(("y1", y0 - 0.05, shrunk.y1 - y0))
        if y1 <= shrunk.y1:
            y_candidates.append(("y0", y1 + 0.05, y1 - shrunk.y0))
        if x0 >= shrunk.x0:
            x_candidates.append(("x1", x0 - 0.05, shrunk.x1 - x0))
        if x1 <= shrunk.x1:
            x_candidates.append(("x0", x1 + 0.05, x1 - shrunk.x0))
        # Choose the shrink axis from the neighbor's line relationship rather
        # than raw area cost. A neighbor that shares the target's baseline
        # (same y-extent) is an adjacent word on the SAME line: pull the rect
        # in horizontally. A neighbor with an offset baseline is a DIFFERENT
        # line whose bbox merely overlaps because tight leading makes glyph
        # boxes taller than the line pitch: pull the rect in vertically.
        # Picking by area cost (the old behaviour) could squeeze the rect
        # horizontally between two different-line neighbours and leave a stray
        # glyph (e.g. the trailing "n" of "...Instructions On") behind.
        same_line = (
            abs(y0 - original.y0) <= _SHRINK_SAME_LINE_BASELINE_TOL_PTS
            and abs(y1 - original.y1) <= _SHRINK_SAME_LINE_BASELINE_TOL_PTS
        )
        preferred = x_candidates if same_line else y_candidates
        fallback = y_candidates if same_line else x_candidates
        candidates = preferred or fallback
        # If the word engulfs the rect on every side, no edge move can
        # evict it. Skip rather than crash.
        if not candidates:
            continue
        edge, new_value, _ = min(candidates, key=lambda c: c[2])
        if edge == "y1":
            shrunk.y1 = max(shrunk.y0, new_value)
        elif edge == "y0":
            shrunk.y0 = min(shrunk.y1, new_value)
        elif edge == "x1":
            shrunk.x1 = max(shrunk.x0, new_value)
        elif edge == "x0":
            shrunk.x0 = min(shrunk.x1, new_value)
        if shrunk.is_empty:
            return shrunk
    return shrunk


def _apply_redactions_compat(page: fitz.Page) -> None:
    try:
        page.apply_redactions(images=0, graphics=0)
        return
    except TypeError:
        pass

    try:
        page.apply_redactions(images=0)
        return
    except TypeError:
        pass

    page.apply_redactions()


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
    if _boolish(ann.get("movedTextOverlay")):
        source_mask_rect = _pdfjs_explicit_source_mask_rect(page, ann)
        if source_mask_rect is None or source_mask_rect.is_empty:
            return
        current_rect = source_mask_rect
        lines = []
        morph = None
    if current_rect.is_empty:
        return

    background = str(ann.get("backgroundColor") or "").strip().lower()
    has_custom_background = bool(background and background != "transparent")
    fill = hex_to_rgb(background) if has_custom_background else (1.0, 1.0, 1.0)

    # Horizontal extent for the per-line erase rects. We must clear the ENTIRE
    # ORIGINAL source area, not the current overlay box: when the user resizes
    # (or otherwise reflows) a promoted block, `current_rect` shrinks/shifts to
    # the new wrapped width while the original glyphs still sit at their captured
    # positions. Using `current_rect.x0/x1` then leaves the wider/offset original
    # text uncovered. The supplied `lines` carry the captured source-line rects,
    # so use their union span (with a small pad) as the erase width. Fall back to
    # `current_rect` only when no line rects are available (whole-rect erase path).
    line_rects_for_span = [
        line_entry.get("rect")
        for line_entry in lines
        if isinstance(line_entry.get("rect"), fitz.Rect)
        and not line_entry.get("rect").is_empty
    ]
    if line_rects_for_span:
        source_x0 = min(r.x0 for r in line_rects_for_span) - 0.75
        source_x1 = max(r.x1 for r in line_rects_for_span) + 0.75
    else:
        source_x0 = current_rect.x0
        source_x1 = current_rect.x1

    if not has_custom_background and morph is None:
        redact_rects: list[fitz.Rect] = []
        if not lines:
            redact_rects.append(fitz.Rect(current_rect))
        else:
            for line_entry in lines:
                line_rect = line_entry.get("rect")
                if not isinstance(line_rect, fitz.Rect):
                    continue
                erase_rect = fitz.Rect(
                    source_x0,
                    max(page.rect.y0, line_rect.y0 - 0.75),
                    source_x1,
                    min(page.rect.y1, line_rect.y1 + 0.75),
                )
                if not erase_rect.is_empty:
                    redact_rects.append(erase_rect)

        if redact_rects:
            try:
                # Convert each broad erase rect into per-word redact rects
                # centered inside it. This stops fitz's apply_redactions from
                # nuking neighboring rows when our erase rect overlaps them
                # by a few points (e.g. a tall heading whose source line bbox
                # extends ~1-2pt into the next line's text).
                tightened: list[fitz.Rect] = []
                try:
                    page_words = page.get_text("words")
                except Exception:
                    page_words = []
                for erase_rect in redact_rects:
                    matched = False
                    for w in page_words:
                        x0, y0, x1, y1 = float(w[0]), float(w[1]), float(w[2]), float(w[3])
                        cx = (x0 + x1) * 0.5
                        cy = (y0 + y1) * 0.5
                        if (erase_rect.x0 <= cx <= erase_rect.x1
                                and erase_rect.y0 <= cy <= erase_rect.y1):
                            tightened.append(fitz.Rect(x0, y0, x1, y1))
                            matched = True
                    if not matched:
                        tightened.append(erase_rect)
                for erase_rect in tightened:
                    erase_rect = _shrink_redact_rect_to_avoid_neighbors(page, erase_rect, page_words)
                    if erase_rect.is_empty or erase_rect.width <= 0.05 or erase_rect.height <= 0.05:
                        continue
                    page.add_redact_annot(erase_rect, fill=None)
                _apply_redactions_compat(page)
                return
            except Exception:
                pass

    if not lines:
        draw_rotated_rect(page, current_rect, fill=fill, color=None, width=0, opacity=1.0, morph=morph)
        return

    for line_entry in lines:
        line_rect = line_entry.get("rect")
        if not isinstance(line_rect, fitz.Rect):
            continue
        erase_rect = fitz.Rect(
            source_x0,
            max(page.rect.y0, line_rect.y0 - 0.75),
            source_x1,
            min(page.rect.y1, line_rect.y1 + 0.75),
        )
        if erase_rect.is_empty:
            continue
        draw_rotated_rect(page, erase_rect, fill=fill, color=None, width=0, opacity=1.0, morph=morph)


def _resolve_promoted_source_clip_rect(ann: Dict[str, Any]) -> Optional[fitz.Rect]:
    try:
        source_left = float(ann.get("sourceBlockLeft"))
        source_top = float(ann.get("sourceBlockTop"))
        source_width = float(ann.get("sourceBlockWidth"))
        source_height = float(ann.get("sourceBlockHeight"))
    except Exception:
        source_left = source_top = source_width = source_height = None

    if (
        source_left is not None
        and source_top is not None
        and source_width is not None
        and source_height is not None
        and source_width > 0
        and source_height > 0
    ):
        clip_rect = fitz.Rect(
            source_left,
            source_top,
            source_left + source_width,
            source_top + source_height,
        )
        if not clip_rect.is_empty:
            return clip_rect

    source_spans = ann.get("sourceSpans")
    if not isinstance(source_spans, list) or not source_spans:
        return None

    merged_rect = None
    for span in source_spans:
        if not isinstance(span, dict):
            continue
        bbox = span.get("bbox")
        if not isinstance(bbox, (list, tuple)) or len(bbox) < 4:
            continue
        try:
            span_rect = fitz.Rect(float(bbox[0]), float(bbox[1]), float(bbox[2]), float(bbox[3]))
        except Exception:
            continue
        if span_rect.is_empty:
            continue
        merged_rect = fitz.Rect(span_rect) if merged_rect is None else (merged_rect | span_rect)

    return merged_rect if merged_rect is not None and not merged_rect.is_empty else None


def _resolve_promoted_source_line_rects(ann: Dict[str, Any]) -> list[fitz.Rect]:
    raw_boxes = ann.get("sourceLineBBoxes")
    if not isinstance(raw_boxes, list):
        return []

    line_rects: list[fitz.Rect] = []
    for bbox in raw_boxes:
        if not isinstance(bbox, (list, tuple)) or len(bbox) < 4:
            continue
        try:
            rect = fitz.Rect(float(bbox[0]), float(bbox[1]), float(bbox[2]), float(bbox[3]))
        except Exception:
            continue
        if rect.is_empty:
            continue
        line_rects.append(rect)
    return line_rects


def _resolve_promoted_source_page_index(ann: Dict[str, Any], page_count: int) -> Optional[int]:
    for raw_value in (
        ann.get("promotedSourcePage"),
        ann.get("sourcePage"),
        ann.get("sourcePageNumber"),
        ann.get("page"),
    ):
        try:
            page_number = int(raw_value)
        except Exception:
            continue
        if 1 <= page_number <= page_count:
            return page_number - 1
        if 0 <= page_number < page_count:
            return page_number
    try:
        page_index = int(ann.get("pageIndex"))
    except Exception:
        page_index = None
    if page_index is not None and 0 <= page_index < page_count:
        return page_index
    return None


def _promoted_annotation_has_source_operator_data(ann: Dict[str, Any]) -> bool:
    source_spans = ann.get("sourceSpans")
    if isinstance(source_spans, list):
        for span in source_spans:
            if not isinstance(span, dict):
                continue
            source_ops = span.get("source_content_ops")
            if isinstance(source_ops, list) and source_ops:
                return True
    source_ops = ann.get("source_content_ops")
    return isinstance(source_ops, list) and bool(source_ops)


def _is_geometrically_safe_show_pdf_page_candidate(
    ann: Dict[str, Any],
    source_clip_rect: fitz.Rect,
    dest_rect: fitz.Rect,
) -> bool:
    if source_clip_rect.is_empty or dest_rect.is_empty:
        return False

    clip_width = max(0.0, float(source_clip_rect.width))
    clip_height = max(0.0, float(source_clip_rect.height))
    dest_width = max(0.0, float(dest_rect.width))
    dest_height = max(0.0, float(dest_rect.height))
    if clip_width <= 0.0 or clip_height <= 0.0 or dest_width <= 0.0 or dest_height <= 0.0:
        return False

    line_boxes = ann.get("sourceLineBBoxes")
    line_count = 0
    if isinstance(line_boxes, list):
        for bbox in line_boxes:
            if isinstance(bbox, (list, tuple)) and len(bbox) >= 4:
                line_count += 1

    source_spans = ann.get("sourceSpans")
    span_count = len(source_spans) if isinstance(source_spans, list) else 0

    aspect_ratio = clip_width / max(0.1, clip_height)
    single_line = line_count <= 1
    ultra_thin_band = min(clip_height, dest_height) <= 8.5
    very_wide_band = max(clip_width, dest_width) >= 300.0
    sparse_single_line = span_count <= 2

    # Very long, very thin single-line bands are the worst candidates for
    # rectangle replay because tiny clip / scaling differences show up as visible
    # raster drift across the entire line.
    if single_line and ultra_thin_band and very_wide_band and aspect_ratio >= 40.0:
        return False
    if single_line and sparse_single_line and aspect_ratio >= 55.0:
        return False

    return True


def _get_cached_source_pdf(path: str) -> Optional[fitz.Document]:
    normalized_path = os.path.abspath(path)
    cached_doc = SOURCE_PDF_CACHE.get(normalized_path)
    if cached_doc is not None:
        return cached_doc
    if not os.path.isfile(normalized_path):
        return None
    try:
        cached_doc = fitz.open(normalized_path)
    except Exception:
        return None
    SOURCE_PDF_CACHE[normalized_path] = cached_doc
    return cached_doc


def should_replay_promoted_source_block(ann: Dict[str, Any], rect: Optional[fitz.Rect], rotation: float) -> bool:
    if not bool(ann.get("promotedFromExtraction")):
        return False
    if bool(ann.get("promotedDirty")):
        return False
    # Guard: an in-place edited promoted span (text changed from its source)
    # must NOT replay the original source glyphs, or the export shows both the
    # old and the new text (double text). Such edits are stamped + redacted via
    # the visible-overlay path instead.
    if promoted_source_overlay_text_was_edited(ann):
        return False
    if rect is None or rect.is_empty:
        return False
    if abs(rotation) > 1e-6:
        return False
    source_pdf_path = str(ann.get("__sourcePdfPath") or "").strip()
    if not source_pdf_path:
        return False
    source_clip_rect = _resolve_promoted_source_clip_rect(ann)
    if source_clip_rect is None:
        return False
    if _is_geometrically_safe_show_pdf_page_candidate(ann, source_clip_rect, rect):
        return True
    # If we have richer operator-level source data, prefer a more precise text
    # replay path instead of rectangle replay for risky geometries.
    if _promoted_annotation_has_source_operator_data(ann):
        return False
    # Otherwise, clipped source replay is still the closest fallback we have.
    return True


def _map_source_rect_to_dest_rect(
    source_rect: fitz.Rect,
    source_clip_rect: fitz.Rect,
    dest_rect: fitz.Rect,
) -> Optional[fitz.Rect]:
    source_width = max(0.0, float(source_clip_rect.width))
    source_height = max(0.0, float(source_clip_rect.height))
    dest_width = max(0.0, float(dest_rect.width))
    dest_height = max(0.0, float(dest_rect.height))
    if source_width <= 0.0 or source_height <= 0.0 or dest_width <= 0.0 or dest_height <= 0.0:
        return None

    scale_x = dest_width / source_width
    scale_y = dest_height / source_height

    x0 = dest_rect.x0 + ((source_rect.x0 - source_clip_rect.x0) * scale_x)
    y0 = dest_rect.y0 + ((source_rect.y0 - source_clip_rect.y0) * scale_y)
    x1 = dest_rect.x0 + ((source_rect.x1 - source_clip_rect.x0) * scale_x)
    y1 = dest_rect.y0 + ((source_rect.y1 - source_clip_rect.y0) * scale_y)

    mapped_rect = fitz.Rect(x0, y0, x1, y1)
    if mapped_rect.is_empty:
        return None
    return mapped_rect


def replay_promoted_source_block(page: fitz.Page, ann: Dict[str, Any], rect: fitz.Rect) -> bool:
    source_pdf_path = str(ann.get("__sourcePdfPath") or "").strip()
    if not source_pdf_path:
        return False

    source_doc = _get_cached_source_pdf(source_pdf_path)
    if source_doc is None or source_doc.page_count <= 0:
        return False

    source_page_index = _resolve_promoted_source_page_index(ann, source_doc.page_count)
    if source_page_index is None:
        return False
    source_page = source_doc[source_page_index]

    clip_rect = _resolve_promoted_source_clip_rect(ann)
    if clip_rect is None or clip_rect.is_empty:
        return False

    line_rects = _resolve_promoted_source_line_rects(ann)
    replay_pad = max(0.0, float(PROMOTED_SOURCE_REPLAY_PAD))
    source_clip_rect = fitz.Rect(clip_rect)
    dest_rect = fitz.Rect(rect)

    if len(line_rects) >= 2:
        rendered_any_line = False
        try:
            for line_rect in line_rects:
                mapped_rect = _map_source_rect_to_dest_rect(line_rect, source_clip_rect, dest_rect)
                if mapped_rect is None:
                    continue

                source_line_rect = fitz.Rect(line_rect)
                target_line_rect = fitz.Rect(mapped_rect)
                if replay_pad > 0.0:
                    source_line_rect = fitz.Rect(
                        source_line_rect.x0 - replay_pad,
                        source_line_rect.y0 - replay_pad,
                        source_line_rect.x1 + replay_pad,
                        source_line_rect.y1 + replay_pad,
                    ) & source_page.rect
                    target_line_rect = fitz.Rect(
                        target_line_rect.x0 - replay_pad,
                        target_line_rect.y0 - replay_pad,
                        target_line_rect.x1 + replay_pad,
                        target_line_rect.y1 + replay_pad,
                    ) & page.rect
                if source_line_rect.is_empty or target_line_rect.is_empty:
                    continue

                page.show_pdf_page(
                    target_line_rect,
                    source_doc,
                    source_page_index,
                    clip=source_line_rect,
                    overlay=True,
                    keep_proportion=False,
                )
                rendered_any_line = True
            if rendered_any_line:
                return True
        except Exception:
            pass

    if replay_pad > 0.0:
        clip_rect = fitz.Rect(
            source_clip_rect.x0 - replay_pad,
            source_clip_rect.y0 - replay_pad,
            source_clip_rect.x1 + replay_pad,
            source_clip_rect.y1 + replay_pad,
        ) & source_page.rect
        rect = fitz.Rect(
            dest_rect.x0 - replay_pad,
            dest_rect.y0 - replay_pad,
            dest_rect.x1 + replay_pad,
            dest_rect.y1 + replay_pad,
        ) & page.rect

    try:
        page.show_pdf_page(
            rect,
            source_doc,
            source_page_index,
            clip=clip_rect,
            overlay=True,
            keep_proportion=False,
        )
        return True
    except Exception:
        return False


def draw_shape(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = to_rect(page, ann)
    if rect is None:
        return
    rect = snap_rect_to_page_edges(page, rect)

    shape_type = (ann.get("shapeType") or "rect").lower()
    opacity = float(ann.get("opacity", 1.0) or 1.0)
    try:
        stroke_width = max(0.0, float(ann.get("strokeWidth", 2.0)))
    except (TypeError, ValueError):
        stroke_width = 2.0
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

    stroke = None if ann.get("strokeTransparent") or stroke_width <= 0.0 else hex_to_rgb(ann.get("strokeColor") or "#000000")
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

    def draw_background_fill_bleed() -> None:
        if fill is None or fill_opacity < 0.99:
            return
        if abs(rotation) >= 1e-6:
            return
        if stroke is not None and stroke_width > 0.0 and stroke_opacity > 0.15:
            return
        bleed_rect = expand_rect_clipped_to_page(page, rect, SHAPE_BACKGROUND_FILL_BLEED_PTS)
        if bleed_rect.is_empty:
            return
        s = page.new_shape()
        s.draw_rect(bleed_rect)
        s.finish(color=None, fill=fill, width=0, fill_opacity=fill_opacity)
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
        if stroke is None:
            return
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
        start = rp(0.50, 0.90)
        s.draw_bezier(start, rp(0.18, 0.66), rp(0.06, 0.48), rp(0.06, 0.30))
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
        raw_points = ann.get("polygonPoints")
        unit_points = []
        if isinstance(raw_points, list):
            for point in raw_points:
                if not isinstance(point, dict):
                    continue
                try:
                    px = max(0.0, min(1.0, float(point.get("x", 0.0))))
                    py = max(0.0, min(1.0, float(point.get("y", 0.0))))
                except Exception:
                    continue
                unit_points.append((px, py))
        if len(unit_points) < 3:
            unit_points = [(0.50, 0.05), (0.90, 0.27), (0.90, 0.73), (0.50, 0.95), (0.10, 0.73), (0.10, 0.27)]
        points = [rp(px, py) for px, py in unit_points]
        points.append(points[0])
        draw_poly(points, close_path=True, line_join=1)
        return

    if shape_type == "line":
        line_start_x = clamp_line_unit_interval(ann.get("lineStartX"), 0.0)
        line_start_y = clamp_line_unit_interval(ann.get("lineStartY"), 1.0)
        line_end_x = clamp_line_unit_interval(ann.get("lineEndX"), 1.0)
        line_end_y = clamp_line_unit_interval(ann.get("lineEndY"), 0.0)
        if (
            rect.height > 0
            and (rect.width / rect.height) <= 0.25
            and line_start_x > 0.99
            and line_end_x < 0.01
            and line_start_y < 0.01
            and line_end_y > 0.99
        ):
            line_end_x = 1.0
        draw_open_lines([(rp(line_start_x, line_start_y), rp(line_end_x, line_end_y))])
        return

    # Default rectangle: use the full annotation bounds. The editor stores the
    # square/rectangle box as the intended final geometry; insetting by 5% on
    # each side shrinks exported bars relative to the DOM preview.
    if shape_type in ("rect", "rectangle", "square"):
        draw_background_fill_bleed()
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


def should_erase_dirty_promoted_source(ann: Dict[str, Any]) -> bool:
    return (
        bool(ann.get("promotedFromExtraction"))
        and bool(ann.get("promotedDirty"))
        and not _boolish(ann.get("skipPromotedSourceErase"))
        and not _boolish(ann.get("movedTextOverlay"))
    )


def draw_text(
    page: fitz.Page,
    ann: Dict[str, Any],
    *,
    mask_only: bool = False,
    source_masks_already_drawn: bool = False,
) -> None:
    # Skip decorative dot-leader separator annotations — these are visual spacers
    # extracted from the original PDF form structure that must not be redrawn as
    # visible text in the reconstruction.
    _ann_id = str(ann.get("id") or "")
    if "leader-for-" in _ann_id:
        return
    render_ann = resolve_promoted_source_typography_annotation(ann)
    pdfjs_visible_overlay = is_pdfjs_visible_overlay_text(render_ann)
    if pdfjs_visible_overlay and is_redundant_pdfjs_source_overlay(render_ann):
        return
    if pdfjs_visible_overlay:
        render_ann = resolve_pdfjs_visible_overlay_typography(page, render_ann)
    dirty_promoted_source_erase = should_erase_dirty_promoted_source(render_ann)
    if pdfjs_visible_overlay:
        # An edited source span must mask its original glyphs even when
        # `skipPdfjsSourceMask` is set, otherwise the source text shows through
        # under the replacement (double text).
        allow_source_mask = (
            not _boolish(render_ann.get("skipPdfjsSourceMask"))
            or source_overlay_text_was_edited(render_ann)
        )
        if not source_masks_already_drawn and allow_source_mask:
            if pdfjs_source_text_needs_redaction(render_ann):
                erase_pdfjs_deleted_source_text(page, render_ann)
            else:
                draw_pdfjs_source_mask(page, render_ann)
        if mask_only and not dirty_promoted_source_erase:
            return
        if _boolish(render_ann.get("pdfjsDeleted")):
            return
    elif mask_only and _boolish(render_ann.get("pdfjsDeleted")):
        return
    pdfjs_export_metrics = pdfjs_source_edit_export_metrics(render_ann) if pdfjs_visible_overlay else None
    text = pdfjs_export_metrics["replacementText"] if pdfjs_export_metrics else sanitize_pdf_text(render_ann.get("text") or "")
    if not text:
        return
    # Always use `fontSize` (PDF points, set by the editor as px/currentScale) rather than
    # `requestedFontSize` which stores the browser screen-pixel value (fontSize × zoom factor)
    # and must not be treated as PDF points.
    size = float(render_ann.get("fontSize", 12) or 12)
    try:
        line_height = float(render_ann.get("lineHeight") or 0)
    except Exception:
        line_height = 0.0
    color = hex_to_rgb(render_ann.get("textColor") or "#000000")
    background = str(render_ann.get("backgroundColor") or "").strip().lower()
    if (
        pdfjs_visible_overlay
        and _boolish(render_ann.get("movedTextOverlay"))
        and not _boolish(render_ann.get("styleDirty"))
        and not _boolish(render_ann.get("userForcedRichText"))
        and not _boolish(render_ann.get("backgroundColorExplicit"))
        and not _boolish(render_ann.get("hasCustomBackground"))
        and not _boolish(render_ann.get("userCreated"))
    ):
        background = "transparent"
    background_color = None if not background or background == "transparent" else hex_to_rgb(background)
    # PDF.js source overlays are drawn over the original PDF/background masks;
    # the generic white-text placeholder creates bogus dark boxes on colored bands.
    if not _boolish(render_ann.get("skipInvisibleTextPlaceholder")) and not pdfjs_visible_overlay:
        background_color = substitute_display_bg_for_invisible_text(
            render_ann.get("textColor"), background, background_color, str(ann.get("id") or "")
        )
    opacity = normalized_opacity(render_ann.get("opacity", 1.0) or 1.0)
    rotation = normalize_rotation_degrees(render_ann.get("rotation", 0.0))
    fontname = resolve_text_fontname(render_ann)
    align = parse_text_align(render_ann.get("textAlign"))
    preserve_extracted_lines = should_preserve_promoted_source_lines(render_ann, text)
    if preserve_extracted_lines:
        if line_height <= 0:
            line_height = size * 1.2
    else:
        line_height = max(line_height, size * 1.18)
    rect = pdfjs_visible_overlay_render_rect(page, render_ann, to_rect(page, ann))
    custom_font = None
    html_archive = None
    embedded_font_entry = resolve_embedded_font_entry(render_ann)
    prefer_pdf_font_text_rendering = (
        should_prefer_pdf_font_text_rendering(render_ann, embedded_font_entry, text)
        or (
            embedded_font_entry is None
            and should_prefer_pdf_base_font_for_pdfjs_source_overlay(render_ann, text)
        )
    )
    fontfile = None if prefer_pdf_font_text_rendering else resolve_text_fontfile_with_coverage(render_ann, text)
    if fontfile:
        try:
            custom_font = fitz.Font(fontfile=fontfile)
            fontname = resolve_text_font_resource_name(render_ann, fontfile)
            page.insert_font(fontname=fontname, fontfile=fontfile)
        except Exception:
            custom_font = None
            fontname = resolve_text_fontname(render_ann)
    if rect is not None and not rect.is_empty:
        pivot = fitz.Point((rect.x0 + rect.x1) / 2.0, (rect.y0 + rect.y1) / 2.0)
        morph = build_rotation_morph(rotation, pivot)
        if not mask_only and should_replay_promoted_source_block(ann, rect, rotation):
            if replay_promoted_source_block(page, ann, rect):
                return
        if not mask_only and background_color is not None:
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
        pdfjs_visual_lines = normalized_pdfjs_visual_lines(render_ann, text) if pdfjs_visible_overlay else []
        preserve_pdfjs_visual_spacing = (
            pdfjs_visible_overlay
            and should_preserve_pdfjs_source_visual_spacing(render_ann, text)
        )
        preserve_pdfjs_moved_source_line = (
            pdfjs_visible_overlay
            and should_preserve_pdfjs_moved_source_line(render_ann, text)
        )
        preserve_pdfjs_source_inline_flow = (
            pdfjs_visible_overlay
            and should_preserve_pdfjs_source_inline_flow(render_ann, text)
        )
        pdfjs_source_span_run_layout = normalize_pdfjs_source_span_run_layout(
            render_ann,
            text,
            rect,
            size,
        ) if preserve_pdfjs_moved_source_line else []
        if (
            pdfjs_visible_overlay
            and _boolish(render_ann.get("pdfjsUseSourceTypography"))
            and not _boolish(render_ann.get("movedTextOverlay"))
            and not pdfjs_visual_lines
            and not preserve_pdfjs_visual_spacing
        ):
            try:
                baseline_y = rect.y0 + float(render_ann.get("pdfjsSourceBaselineOffsetY") or 0.0)
            except Exception:
                baseline_y = rect.y0 + (size * float(getattr(draw_font, "ascender", 0.8) or 0.8))
            try:
                draw_x = rect.x0 + float(render_ann.get("pdfjsSourceBaselineOffsetX") or 0.0)
            except Exception:
                draw_x = rect.x0
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
                text_width = draw_font.text_length(text, fontsize=size)
                underline_y = baseline_y + max(0.5, size * 0.08)
                underline_end_x = draw_x + text_width
                draw_rotated_line(
                    page,
                    fitz.Point(draw_x, underline_y),
                    fitz.Point(underline_end_x, underline_y),
                    color=color,
                    width=max(0.5, size * 0.06),
                    opacity=opacity,
                    morph=morph,
                )
            return
        exact_source_line_layout = normalize_exact_source_line_layout(
            render_ann,
            text,
            draw_font,
            size,
            current_rect=rect,
        ) if (preserve_extracted_lines and not _boolish(render_ann.get("pdfjsAvoidEmbeddedSourceFont"))) else []
        dirty_promoted_span_layout = build_dirty_promoted_style_mapped_span_layout(
            render_ann,
            text,
            exact_source_line_layout,
        ) if exact_source_line_layout else []
        exact_source_span_layout = normalize_exact_source_span_layout(
            render_ann,
            text,
            size,
            current_rect=rect,
        ) if (
            preserve_extracted_lines
            and not bool(ann.get("promotedDirty"))
            and not _boolish(render_ann.get("pdfjsAvoidEmbeddedSourceFont"))
        ) else []
        if exact_source_line_layout and not source_masks_already_drawn:
            erase_promoted_source_text_region(page, ann, rect, exact_source_line_layout, morph)
        elif (
            not source_masks_already_drawn
            and should_erase_dirty_promoted_source(ann)
        ):
            # The edited text reflowed to a different line count than the
            # original extraction (e.g. flattened to a single line or promoted
            # to rich text), so normalize_exact_source_line_layout could not map
            # the source lines and returned nothing. Without an erase the
            # original glyphs survive underneath the re-stamped text and the
            # paragraph appears duplicated in the download. Fall back to the raw
            # per-line source bounding boxes so the source region is still
            # cleared.
            fallback_source_line_rects = _resolve_promoted_source_line_rects(ann)
            if fallback_source_line_rects:
                erase_promoted_source_text_region(
                    page,
                    ann,
                    rect,
                    [{"rect": line_rect} for line_rect in fallback_source_line_rects],
                    morph,
                )
        if mask_only:
            return
        if (
            pdfjs_source_span_run_layout
            and draw_text_using_exact_source_spans(
                page,
                render_ann,
                pdfjs_source_span_run_layout,
                opacity,
                morph,
            )
        ):
            return
        if (
            dirty_promoted_span_layout
            and draw_text_using_exact_source_spans(
                page,
                render_ann,
                dirty_promoted_span_layout,
                opacity,
                morph,
            )
        ):
            return
        if (
            exact_source_span_layout
            and draw_text_using_exact_source_spans(
                page,
                render_ann,
                exact_source_span_layout,
                opacity,
                morph,
            )
        ):
            return
        if exact_source_line_layout and draw_text_using_exact_source_lines(
            page,
            render_ann,
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
        pdfjs_scale_x = (
            pdfjs_visible_overlay_scale_x(render_ann)
            if preserve_pdfjs_visual_spacing
            else (
                1.0
                if _boolish(render_ann.get("pdfjsUseSourceTypography")) or preserve_pdfjs_moved_source_line
                else (pdfjs_visible_overlay_scale_x(render_ann) if pdfjs_visible_overlay else 1.0)
            )
        )
        if (
            abs(rotation) < 1e-6
            and pdfjs_scale_x == 1.0
            and not _boolish(render_ann.get("pdfjsUseSourceTypography"))
            and not (
                bool(render_ann.get("promotedFromExtraction"))
                and _boolish(render_ann.get("preserveSourceTypography"))
            )
            and should_use_htmlbox_for_text(render_ann, embedded_font_entry)
            and not prefer_pdf_font_text_rendering
        ):
            try:
                # Archive must include BOTH the bundled font dir AND the
                # static-instance cache dir, because variable fonts are
                # pre-instanced into the latter (see
                # _materialize_static_instances_for_variable_fonts).
                html_archive = fitz.Archive()
                html_archive.add(FONT_DIR)
                if os.path.isdir(_STATIC_INSTANCE_CACHE_DIR):
                    html_archive.add(_STATIC_INSTANCE_CACHE_DIR)
            except Exception:
                html_archive = None
        preview_font = custom_font or fitz.Font(fontname)
        use_flush_dirty_promoted_padding = bool(ann.get("promotedFromExtraction")) and bool(ann.get("promotedDirty"))
        use_flush_user_authored_padding = bool(ann.get("userAuthored")) or bool(ann.get("userCreated"))
        use_flush_single_line_padding = should_drop_preview_side_padding_for_single_line_text(
            ann,
            text,
            rect,
            preview_font,
            size,
            preserve_extracted_lines,
            use_flush_dirty_promoted_padding,
        )
        use_flush_preview_padding = (
            pdfjs_visible_overlay
            or preserve_extracted_lines
            or use_flush_dirty_promoted_padding
            or use_flush_user_authored_padding
            or use_flush_single_line_padding
        )
        preview_padding_x = 0.0 if use_flush_preview_padding else min(6.0, max(1.0, rect.width * 0.03))
        preview_padding_top = 0.0 if use_flush_preview_padding else min(2.0, max(0.5, rect.height * 0.01))
        preview_available_width = max(1.0, (rect.width - (preview_padding_x * 2.0)) / pdfjs_scale_x)
        use_pdfjs_source_typography = pdfjs_visible_overlay and _boolish(render_ann.get("pdfjsUseSourceTypography"))
        preserve_user_authored_blank_lines = should_preserve_user_authored_blank_lines(render_ann)
        rich_layout_ops = parse_rich_text_layout_ops(ann)
        rich_layout_text_matches = (
            bool(rich_layout_ops)
            and _normalize_rich_text_compare_text(_rich_text_layout_ops_to_text(rich_layout_ops))
            == _normalize_rich_text_compare_text(text)
        )
        rich_wrapped_lines = (
            wrap_rich_text_layout_ops(rich_layout_ops, preview_available_width)
            if rich_layout_text_matches and not preserve_extracted_lines and not preserve_pdfjs_source_inline_flow
            else []
        )
        explicit_text_lines = split_text_preserving_manual_line_breaks(text)
        preview_lines = (
            pdfjs_visual_lines
            if pdfjs_visual_lines
            else explicit_text_lines
            if (
                preserve_extracted_lines
                or use_pdfjs_source_typography
                or preserve_pdfjs_visual_spacing
                or preserve_pdfjs_moved_source_line
                or preserve_pdfjs_source_inline_flow
            )
            else (
                ["" for _ in rich_wrapped_lines]
                if rich_wrapped_lines
                else wrap_text_to_width(
                    preview_font,
                    text,
                    size,
                    preview_available_width,
                    preserve_blank_lines=preserve_user_authored_blank_lines,
                )
            )
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
        if rich_wrapped_lines:
            rich_span_layout = build_wrapped_rich_text_span_layout(
                ann,
                text_rect,
                preview_available_width,
                preview_padding_x,
                preview_padding_top,
                effective_line_height,
                align,
                ascender,
            )
            rich_span_layout = apply_source_faces_to_rich_span_layout(render_ann, rich_span_layout)
            if rich_span_layout and draw_text_using_exact_source_spans(
                page,
                ann,
                rich_span_layout,
                opacity,
                morph,
            ):
                return
        if html_archive is not None and not preserve_pdfjs_moved_source_line:
            # Rich-html annotations (editor-authored with per-selection
            # formatting) have no horizontal/vertical padding in the browser's
            # rich-html-item overlay — the contenteditable fills the full
            # pdfWidth/pdfHeight box. Using 6pt padding here shrinks the
            # effective text width versus the editor and causes word-wrap
            # points to differ between the on-screen view and the exported
            # PDF (off by roughly one word per line). Zero the padding for
            # that case so the PDF wrap matches what the user saw.
            has_rich_html_payload = bool(
                sanitize_rich_text_html(ann.get("richTextHtml") or "").strip()
            )
            if use_flush_preview_padding or has_rich_html_payload:
                padding_x = 0.0
                padding_y = 0.0
            else:
                padding_x = 6.0
                padding_y = 2.0
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
            # Honor verticalAlign (middle / bottom) by shrinking the htmlbox
            # downward from the top so the rendered text block sits where the
            # editor's flex column-justify-content placed it. MuPDF's HTML
            # engine has no usable flex/table-cell vertical centering, so we
            # pre-compute spare vertical space here.
            v_align = str(ann.get("verticalAlign") or "top").strip().lower()
            if v_align in {"middle", "center", "bottom"}:
                approx_line_count = max(1, len(rich_wrapped_lines or preview_lines or [""]))
                # Use actual rendered glyph block height (ascender+descender +
                # inter-line gaps), not lines*line_height, so the offset never
                # pushes content past inner_rect.y1 and clips the text.
                approx_text_height = (max(0, approx_line_count - 1) * effective_line_height) + (size * (ascender + descender))
                spare = inner_rect.height - approx_text_height
                if spare > 0.5:
                    offset = spare * 0.5 if v_align in {"middle", "center"} else spare
                    inner_rect = fitz.Rect(
                        inner_rect.x0,
                        inner_rect.y0 + offset,
                        inner_rect.x1,
                        inner_rect.y1,
                    )
            if inner_rect.width > 1 and inner_rect.height > 1:
                html_box = build_annotation_htmlbox_markup(render_ann, text)
                html_css = build_annotation_htmlbox_css(render_ann, size, opacity)
                # When the annotation carries per-selection rich HTML
                # (bold/italic/font/colour spans baked in by the editor) we
                # MUST commit to the htmlbox path: falling through to the
                # plain insert_text loop below silently strips every span
                # style.  Allow insert_htmlbox to scale the content down so
                # it still fits the box and always return on success even if
                # the result was scaled or barely overflowed.
                has_rich_html = has_rich_html_payload
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
        if (
            not pdfjs_visual_lines
            and not preserve_extracted_lines
            and not use_pdfjs_source_typography
            and not preserve_pdfjs_visual_spacing
            and not preserve_pdfjs_moved_source_line
            and not preserve_pdfjs_source_inline_flow
            and available_width > 1
        ):
            safe_lines: list[str] = []
            for line in lines:
                if not line or draw_font.text_length(line, fontsize=size) <= (available_width + 0.01):
                    safe_lines.append(line)
                else:
                    safe_lines.extend(wrap_text_to_width(draw_font, line, size, available_width))
            lines = safe_lines or [""]
        baseline_y = text_rect.y0 + padding_top + (size * ascender)
        if (
            pdfjs_visible_overlay
            and _boolish(render_ann.get("pdfjsUseSourceTypography"))
            and not preserve_pdfjs_moved_source_line
        ):
            try:
                baseline_y = text_rect.y0 + float(render_ann.get("pdfjsSourceBaselineOffsetY") or 0.0)
            except Exception:
                baseline_y = text_rect.y0 + (size * ascender)
        # Vertical alignment offset: shift the first-line baseline down so the
        # block of `lines` lands centered or bottom-aligned inside text_rect,
        # matching the editor's flex column-justify-content placement.
        v_align_plain = str(ann.get("verticalAlign") or "top").strip().lower()
        if v_align_plain in {"middle", "center", "bottom"} and lines:
            # Use the actual rendered glyph block height: (n-1) line gaps plus
            # one full line of ascender+descender. Using `lines*line_height`
            # underestimates the visual height when ascender+descender exceeds
            # line_height (eg. Garamond 29pt has ~39pt glyph height vs 34pt
            # line_height) and the resulting offset pushes the baseline so far
            # down that line_baseline_y + descender > text_rect.y1, which trips
            # the per-line break below and yields a blank PDF.
            glyph_block_height = (max(0, len(lines) - 1) * effective_line_height) + (size * (ascender + descender))
            avail = (text_rect.y1 - text_rect.y0) - padding_top
            spare = avail - glyph_block_height
            if spare > 0.5:
                offset = spare * 0.5 if v_align_plain in {"middle", "center"} else spare
                # Clamp so the first line's bottom (baseline + descender) still
                # fits within text_rect.y1 — otherwise the loop's overflow
                # guard breaks out and nothing is drawn.
                max_offset = max(0.0, text_rect.y1 - (baseline_y + size * descender))
                baseline_y += min(offset, max_offset)

        if lines and text_rect.height > 0:
            max_first_baseline_y = text_rect.y1 - max(0.0, size * descender)
            if baseline_y > max_first_baseline_y:
                baseline_y = max_first_baseline_y

        for line_index, line in enumerate(lines):
            line_baseline_y = baseline_y + (line_index * effective_line_height)
            if (line_baseline_y + (size * descender)) > text_rect.y1:
                break

            text_width = draw_font.text_length(line, fontsize=size) if line else 0.0
            draw_x = text_rect.x0 + padding_x
            if (
                pdfjs_visible_overlay
                and _boolish(render_ann.get("pdfjsUseSourceTypography"))
                and not preserve_pdfjs_moved_source_line
            ):
                try:
                    draw_x = text_rect.x0 + float(render_ann.get("pdfjsSourceBaselineOffsetX") or 0.0)
                except Exception:
                    draw_x = text_rect.x0
            if align == 1:
                draw_x = text_rect.x0 + padding_x + max(0.0, (available_width - text_width) / 2.0)
            elif align == 2:
                draw_x = text_rect.x1 - padding_x - text_width

            line_morph = morph
            line_scale_x = pdfjs_scale_x
            if (
                (preserve_pdfjs_moved_source_line or preserve_pdfjs_source_inline_flow)
                and line
                and text_width > (available_width + 0.01)
                and available_width > 1
            ):
                line_scale_x = min(line_scale_x, max(0.2, available_width / max(text_width, 0.0001)))
            if pdfjs_visible_overlay and _boolish(render_ann.get("movedTextOverlay")):
                draw_x, line_scale_x = fit_moved_pdfjs_line_to_page_bounds(
                    page,
                    draw_x,
                    text_width,
                    line_scale_x,
                )
            if line_scale_x != 1.0:
                line_morph = combine_morphs(
                    (fitz.Point(draw_x, line_baseline_y), fitz.Matrix(line_scale_x, 0.0, 0.0, 1.0, 0.0, 0.0)),
                    morph,
                )

            page.insert_text(
                fitz.Point(draw_x, line_baseline_y),
                sanitize_pdfjs_source_text(line) if pdfjs_visible_overlay else sanitize_pdf_text(line),
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
                    fitz.Point(draw_x + text_width, underline_y),
                    color=color,
                    width=max(0.5, size * 0.06),
                    opacity=opacity,
                    morph=line_morph,
                )
        return

    if mask_only:
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
    # Layering for image-backed direct-draw strokes is handled by
    # annotation_layer_order. Defaults place pen strokes below text and white
    # eraser strokes on top; an explicit editor zIndex can override either.
    page.insert_image(
        rect,
        overlay=True,
        keep_proportion=False,
        **direct_draw_image_insert_kwargs(ann, image_bytes),
    )


def is_direct_draw_annotation(ann: Dict[str, Any]) -> bool:
    return str(ann.get("imageToolSource") or "").strip().lower() == "direct-draw"


def is_direct_draw_eraser_annotation(ann: Dict[str, Any]) -> bool:
    return is_direct_draw_annotation(ann) and str(ann.get("directDrawTool") or "").strip().lower() == "eraser"


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


def annotation_uses_white_direct_draw(ann: Dict[str, Any], image: Any) -> bool:
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


def image_looks_like_white_direct_draw(image: Any) -> bool:
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


def annotation_layer_order(ann: Dict[str, Any]) -> tuple[float, int]:
    """Stable layering key for the export draw order.

    Lower values are processed first (drawn underneath). ``zIndex`` is the
    editor's persisted manual layer order. The type key preserves the default
    ordering for annotations that share a layer: shapes, pen strokes, text,
    foreground images, then eraser strokes.
    """
    if not isinstance(ann, dict):
        return (2.0, 2)
    kind = str(ann.get("type") or "").lower()
    if kind in ("shape", "table", "eraser"):
        type_order = 0
        default_z_index = 2.0
    elif kind in ("image", "signature"):
        if is_direct_draw_eraser_annotation(ann):
            type_order = 4
            default_z_index = 1000.0
        elif is_direct_draw_annotation(ann):
            type_order = 1
            default_z_index = 2.0
        else:
            type_order = 3
            default_z_index = 6.0
    else:
        type_order = 2
        default_z_index = 2.0
    try:
        z_index = float(ann.get("zIndex", default_z_index))
    except (TypeError, ValueError):
        z_index = default_z_index
    if not math.isfinite(z_index):
        z_index = default_z_index
    return (z_index, type_order)


def _annotations_overlap_in_page_space(a: Dict[str, Any], b: Dict[str, Any]) -> bool:
    """True when two annotations are on the same page and their pdf bboxes overlap.

    Works in the editor's pdf-lib convention (bottom-origin y); a positive
    overlap in that space is equivalent to a positive overlap on the page.
    """
    try:
        if int(a.get("pageIndex", -1)) != int(b.get("pageIndex", -2)):
            return False
        ax = float(a.get("pdfX") or 0.0)
        ay = float(a.get("pdfY") or 0.0)
        aw = float(a.get("pdfWidth") or 0.0)
        ah = float(a.get("pdfHeight") or 0.0)
        bx = float(b.get("pdfX") or 0.0)
        by = float(b.get("pdfY") or 0.0)
        bw = float(b.get("pdfWidth") or 0.0)
        bh = float(b.get("pdfHeight") or 0.0)
    except (TypeError, ValueError):
        return False
    if aw <= 0 or ah <= 0 or bw <= 0 or bh <= 0:
        return False
    return (ax < bx + bw) and (ax + aw > bx) and (ay < by + bh) and (ay + ah > by)


def _annotation_has_visible_fill(ann: Dict[str, Any]) -> bool:
    kind = str(ann.get("type") or "").lower()
    if kind == "shape":
        if bool(ann.get("fillTransparent")):
            return False
        fill = str(ann.get("fillColor") or "").strip().lower()
        if not fill or fill == "transparent":
            return False
        try:
            return float(ann.get("fillOpacity", ann.get("opacity", 1.0)) or 1.0) > 0.01
        except Exception:
            return True
    if kind == "table":
        if bool(ann.get("fillTransparent")):
            return False
        fill = str(ann.get("fillColor") or "").strip().lower()
        return bool(fill and fill != "transparent")
    return False


def _suppress_invisible_text_placeholder_over_user_fills(annotations: list) -> None:
    """Do not add the slate white-text placeholder when a shape fill is the bg.

    The placeholder is for white text that would otherwise be stamped onto a
    white/transparent page. When the editor has a colored shape behind the text
    (for example invoice header text on a red rectangle), the shape is the real
    background and the placeholder becomes an unwanted extra box.
    """
    if not isinstance(annotations, list):
        return
    for index, ann in enumerate(annotations):
        if not isinstance(ann, dict):
            continue
        if str(ann.get("type") or "").lower() != "text":
            continue
        if not _text_color_is_white_like(ann.get("textColor")):
            continue
        if not _bg_is_effectively_white_or_missing(ann.get("backgroundColor")):
            continue
        for earlier in annotations[:index]:
            if not isinstance(earlier, dict):
                continue
            if not _annotation_has_visible_fill(earlier):
                continue
            if _annotations_overlap_in_page_space(ann, earlier):
                ann["skipInvisibleTextPlaceholder"] = True
                break


def _suppress_promoted_source_erase_over_user_shapes(annotations: list) -> None:
    """Avoid wiping out user-drawn shape backgrounds behind re-stamped text.

    `erase_promoted_source_text_region` redacts the promoted text annotation's
    current bbox with a white (or text-background-coloured) fill before
    re-drawing the glyphs. That's correct when the text re-stamp needs to
    cover its original extracted location, but after we re-sort so shapes
    are drawn before text (see `annotation_layer_order`), the redaction now
    falls on top of any user-drawn shape the text was dragged onto,
    punching a white hole through the shape.

    For each promoted-dirty text annotation that sits on top of a shape
    (or table) annotation drawn earlier in this export pass, mark
    `skipPromotedSourceErase` so the glyphs are stamped straight onto the
    shape fill. The text itself is drawn by a separate code path below the
    redact step, so skipping the redact does not affect text visibility.
    """
    if not isinstance(annotations, list):
        return
    for index, ann in enumerate(annotations):
        if not isinstance(ann, dict):
            continue
        if str(ann.get("type") or "").lower() != "text":
            continue
        if not (bool(ann.get("promotedFromExtraction")) and bool(ann.get("promotedDirty"))):
            continue
        if bool(ann.get("skipPromotedSourceErase")):
            continue
        for earlier in annotations[:index]:
            if not isinstance(earlier, dict):
                continue
            earlier_kind = str(earlier.get("type") or "").lower()
            if earlier_kind not in ("shape", "table"):
                continue
            if _annotations_overlap_in_page_space(ann, earlier):
                ann["skipPromotedSourceErase"] = True
                break


def apply_annotations(pdf_path: str, annotations: list) -> None:
    annotations = normalize_annotations_for_pdf_export(annotations)
    annotations = sorted(annotations, key=annotation_layer_order)
    _suppress_invisible_text_placeholder_over_user_fills(annotations)
    suppress_pdfjs_deleted_masks_owned_by_replacements(annotations)
    doc = fitz.open(pdf_path)
    temp_path = pdf_path + ".ann.tmp"
    try:
        page_annotations: list[list[Dict[str, Any]]] = [[] for _ in range(doc.page_count)]
        for ann in annotations:
            if not isinstance(ann, dict):
                continue
            page_index = clamp_page_index(ann.get("pageIndex"), doc.page_count)
            if page_index is None:
                continue
            page_annotations[page_index].append(ann)

        for page_index, anns in enumerate(page_annotations):
            page = doc[page_index]

            for ann_index, ann in enumerate(anns):
                if is_pdfjs_visible_overlay_text(ann):
                    anns[ann_index] = resolve_pdfjs_visible_overlay_typography(page, ann)

            moved_source_rects: list[tuple[str, fitz.Rect]] = []
            for ann in anns:
                if not (is_pdfjs_visible_overlay_text(ann) and _boolish(ann.get("movedTextOverlay"))):
                    continue
                moved_rect = _pdfjs_source_base_rect(page, ann) or _pdfjs_explicit_source_mask_rect(page, ann)
                if moved_rect is None or moved_rect.is_empty:
                    continue
                moved_source_rects.append((str(ann.get("id") or ""), fitz.Rect(moved_rect)))

            if moved_source_rects:
                for ann in anns:
                    if not (is_pdfjs_visible_overlay_text(ann) and _boolish(ann.get("movedTextOverlay"))):
                        continue
                    ann_id = str(ann.get("id") or "")
                    ann["__pdfjsMovedSourceRects"] = [
                        fitz.Rect(rect)
                        for other_id, rect in moved_source_rects
                        if other_id != ann_id
                    ]

            # Page-level source-mask prepass: every text-source whiteout is
            # finished before any replacement ink is stamped. This prevents a
            # later annotation mask from trimming text drawn by an earlier text
            # annotation on the same page.
            for ann in anns:
                kind = (ann.get("type") or "").lower()
                needs_source_mask = (
                    is_pdfjs_visible_overlay_text(ann)
                    or (
                        kind == "text"
                        and bool(ann.get("promotedFromExtraction"))
                        and bool(ann.get("promotedDirty"))
                        and not bool(ann.get("skipPromotedSourceErase"))
                    )
                )
                if needs_source_mask:
                    draw_text(page, ann, mask_only=True)

            for ann in anns:
                kind = (ann.get("type") or "").lower()
                if kind == "shape":
                    draw_shape(page, ann)
                elif kind == "table":
                    draw_table(page, ann)
                elif kind == "eraser":
                    draw_eraser(page, ann)
                elif kind == "text":
                    draw_text(page, ann, source_masks_already_drawn=True)
                elif kind == "signature":
                    draw_signature(page, ann)
                elif kind == "image":
                    draw_signature(page, ann)

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
        for cached_doc in SOURCE_PDF_CACHE.values():
            try:
                cached_doc.close()
            except Exception:
                pass
        SOURCE_PDF_CACHE.clear()

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
