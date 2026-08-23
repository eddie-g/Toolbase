#!/usr/bin/env python3
"""Re-author an edited PDF for Adobe without rasterizing whole pages.

The editor's saved PDF can retain stale structure information or source-page
objects that Adobe prefers over later visible edits. Copying each displayed
page into a fresh, untagged PDF makes the visible content authoritative while
keeping native text, vector shapes, and image layers intact.
"""

import argparse
import json
import os
import sys
import traceback

import fitz


def prepare_pdf(input_path: str, output_path: str) -> dict:
    source = fitz.open(input_path)
    output = fitz.open()
    try:
        # Word has no useful interactive-PDF equivalent. Bake standard PDF
        # annotations and widgets into page content before rebuilding pages.
        if hasattr(source, "bake"):
            source.bake(annots=True, widgets=True)

        for page_index, source_page in enumerate(source):
            display_rect = source_page.rect
            target_page = output.new_page(
                width=display_rect.width,
                height=display_rect.height,
            )
            target_page.show_pdf_page(
                target_page.rect,
                source,
                page_index,
                keep_proportion=False,
                overlay=True,
            )

        # A newly authored document intentionally has no stale tagged-content
        # tree for Adobe to use instead of the visible edited page content.
        output.set_metadata({})
        output.save(output_path, garbage=4, deflate=True, clean=True)
    finally:
        output.close()
        source.close()

    with fitz.open(output_path) as prepared:
        page_count = prepared.page_count
        text_characters = sum(len(page.get_text("text")) for page in prepared)

    return {
        "success": True,
        "output": output_path,
        "pages": page_count,
        "file_size": os.path.getsize(output_path),
        "text_characters": text_characters,
        "prepared_for_adobe": True,
        "native_text_preserved": True,
        "vector_shapes_preserved": True,
        "image_layers_preserved": True,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("input_pdf")
    parser.add_argument("output_pdf")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    try:
        result = prepare_pdf(args.input_pdf, args.output_pdf)
        print(json.dumps(result) if args.json else result["output"])
    except Exception as exception:
        result = {
            "success": False,
            "error": str(exception),
            "traceback": traceback.format_exc(),
        }
        print(json.dumps(result) if args.json else result["error"], file=sys.stderr)
        raise SystemExit(1)


if __name__ == "__main__":
    main()
