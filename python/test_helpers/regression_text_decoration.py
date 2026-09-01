#!/usr/bin/env python3
"""
NK_7 regression: text decorations in the exported PDF.

Underline and strikeout are two tokens of one CSS property in the editor, and
two separate strokes in the written PDF. This checks the writer end of that:

  * a plain annotation draws no rule at all;
  * `underline` draws one rule at or just below the baseline;
  * `strikeout` draws one rule that crosses the glyph body;
  * both together draw two distinct rules, strike above underline;
  * the same holds for a rich-text annotation that carries the decoration in
    its HTML rather than in the boolean flag.

Run with an interpreter that has PyMuPDF:

    python/venv/bin/python python/test_helpers/regression_text_decoration.py

Exit code is non-zero on any failure.
"""

import sys
from pathlib import Path
from typing import Any, Dict, List, Tuple

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT / "python" / "pdf-editor"))

import fitz  # noqa: E402

import new_annotation_writer as writer  # noqa: E402

BASE_ANNOTATION: Dict[str, Any] = {
    "type": "text",
    "pageIndex": 0,
    "text": "Strikeout check",
    "pdfX": 72,
    "pdfY": 700,
    "pdfWidth": 300,
    "pdfHeight": 20,
    "fontSize": 14,
    "fontFamily": "Helvetica",
    "fontWeight": "400",
    "fontStyle": "normal",
    "textColor": "#000000",
    "backgroundColor": "transparent",
    "opacity": 1,
    "textAlign": "left",
    "verticalAlign": "top",
    "userCreated": True,
    "userAuthored": True,
    "savedTextOverlay": True,
}


def render(overrides: Dict[str, Any]) -> Tuple[List[float], Dict[str, Any]]:
    """Draw one annotation and return its horizontal rules plus the text span."""
    doc = fitz.open()
    page = doc.new_page(width=612, height=792)
    writer.draw_text(page, {**BASE_ANNOTATION, **overrides})

    rules = set()
    for drawing in page.get_drawings():
        for item in drawing.get("items", []):
            # ("l", start, end) — keep the horizontal ones. PyMuPDF reports the
            # same stroked path more than once, so collapse by y.
            if item[0] == "l" and abs(item[1].y - item[2].y) < 0.5:
                rules.add(round(item[1].y, 2))

    spans = [
        span
        for block in page.get_text("dict")["blocks"]
        for line in block.get("lines", [])
        for span in line.get("spans", [])
    ]
    doc.close()
    return sorted(rules), (spans[0] if spans else {})


def main() -> int:
    failures: List[str] = []

    def check(label: str, condition: bool, detail: str = "") -> None:
        if condition:
            print(f"  ok   {label}")
        else:
            print(f"  FAIL {label} {detail}")
            failures.append(f"{label} {detail}".strip())

    print("plain text annotation")
    rules, span = render({})
    check("draws no rule", rules == [], f"got {rules}")

    glyph_top = span.get("bbox", (0, 0, 0, 0))[1]
    baseline = span.get("origin", (0, 0))[1]

    print("underline only")
    underline_rules, _ = render({"underline": True})
    check("draws exactly one rule", len(underline_rules) == 1, f"got {underline_rules}")
    if underline_rules:
        check(
            "sits at or below the baseline",
            underline_rules[0] >= baseline,
            f"rule {underline_rules[0]:.2f} vs baseline {baseline:.2f}",
        )

    print("strikeout only")
    strike_rules, _ = render({"strikeout": True})
    check("draws exactly one rule", len(strike_rules) == 1, f"got {strike_rules}")
    if strike_rules:
        check(
            "crosses the glyph body",
            glyph_top < strike_rules[0] < baseline,
            f"rule {strike_rules[0]:.2f} vs glyphs {glyph_top:.2f}..{baseline:.2f}",
        )

    print("underline + strikeout")
    both_rules, _ = render({"underline": True, "strikeout": True})
    check("draws two distinct rules", len(both_rules) == 2, f"got {both_rules}")
    if len(both_rules) == 2:
        check(
            "strike sits above the underline",
            both_rules[0] < both_rules[1],
            f"got {both_rules}",
        )

    print("decoration carried in single-run rich text")
    html_rules, _ = render({
        "richTextHtml": '<span style="text-decoration-line: line-through">Strikeout check</span>',
    })
    check("resolves strikeout from the HTML", len(html_rules) == 1, f"got {html_rules}")

    if failures:
        print(f"\n{len(failures)} failure(s).")
        return 1

    print("\nAll text-decoration regression cases passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
