#!/usr/bin/env python3
"""
Convert HTML to PDF using WeasyPrint.

Usage: echo '{"html":"<body>...</body>"}' | python3 html_to_pdf.py <output_path>
"""

import json
import sys
from weasyprint import HTML, CSS


def html_to_pdf(html: str, output_path: str, css: str = ""):
    """Convert an HTML string to a PDF document using WeasyPrint."""

    # Default CSS for better rendering
    default_css = """
    @page {
        size: Letter;
        margin: 0;
    }
    body {
        font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        color: #1f2937;
        font-size: 11px;
        line-height: 1.4;
        margin: 0;
        padding: 0;
    }
    table {
        border-collapse: collapse;
    }
    img {
        max-width: 100%;
    }
    """

    full_css = default_css + "\n" + css

    # Wrap in a full HTML document if it's just a <body>
    if not html.strip().lower().startswith('<!doctype') and not html.strip().lower().startswith('<html'):
        html = f'<!DOCTYPE html><html><head><meta charset="utf-8"></head>{html}</html>'

    html_doc = HTML(string=html)
    css_sheet = CSS(string=full_css)
    html_doc.write_pdf(output_path, stylesheets=[css_sheet])


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: html_to_pdf.py <output_path>", file=sys.stderr)
        sys.exit(1)

    output_path = sys.argv[1]

    try:
        data = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        print(f"Invalid JSON input: {e}", file=sys.stderr)
        sys.exit(1)

    html = data.get("html", "")
    css = data.get("css", "")

    if not html.strip():
        print("No HTML content provided.", file=sys.stderr)
        sys.exit(1)

    try:
        html_to_pdf(html, output_path, css)
        print(json.dumps({"success": True}))
    except Exception as e:
        print(f"PDF generation failed: {e}", file=sys.stderr)
        sys.exit(1)
