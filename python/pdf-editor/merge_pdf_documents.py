#!/usr/bin/env python3
"""Merge complete PDF documents in manifest order and emit one JSON result."""

from __future__ import annotations

import json
import os
import sys
from typing import Any

import fitz


fitz.TOOLS.mupdf_display_errors(False)


def fail(message: str) -> dict[str, Any]:
    return {"success": False, "error": message}


def merge_from_manifest(manifest_path: str) -> dict[str, Any]:
    opened: list[fitz.Document] = []
    output: fitz.Document | None = None

    try:
        with open(manifest_path, "r", encoding="utf-8") as handle:
            manifest = json.load(handle)

        inputs = manifest.get("inputs")
        output_path = str(manifest.get("output") or "")
        max_pages = int(manifest.get("max_pages") or 1000)
        if not isinstance(inputs, list) or len(inputs) < 2:
            return fail("At least two PDF documents are required.")
        if not output_path:
            return fail("The merge output path is missing.")

        seen_ids: set[str] = set()
        groups: list[dict[str, Any]] = []
        total_pages = 0
        current_metadata: dict[str, str] = {}
        combined_toc: list[list[Any]] = []

        for entry in inputs:
            if not isinstance(entry, dict):
                return fail("The merge manifest contains an invalid input.")
            input_id = str(entry.get("id") or "")
            path = str(entry.get("path") or "")
            if not input_id or input_id in seen_ids:
                return fail("The merge manifest contains a missing or duplicate input ID.")
            if not path or not os.path.isfile(path):
                return fail(f"PDF input '{input_id}' is missing.")
            seen_ids.add(input_id)

            source = fitz.open(path)
            opened.append(source)
            if source.needs_pass:
                return fail(f"PDF input '{input_id}' is password protected.")
            page_count = source.page_count
            if page_count < 1:
                return fail(f"PDF input '{input_id}' has no pages.")
            if total_pages + page_count > max_pages:
                return fail(f"The merged PDF exceeds the {max_pages}-page limit.")

            start_page = total_pages
            groups.append({
                "id": input_id,
                "start_page": start_page,
                "page_count": page_count,
            })
            total_pages += page_count

            if input_id == "current":
                current_metadata = dict(source.metadata or {})

            try:
                for row in source.get_toc(simple=True):
                    copied = list(row)
                    if len(copied) >= 3 and isinstance(copied[2], int) and copied[2] > 0:
                        copied[2] += start_page
                    combined_toc.append(copied)
            except Exception:
                # A malformed outline must not prevent otherwise valid pages
                # from being merged.
                pass

        output = fitz.open()
        for source in opened:
            output.insert_pdf(source, links=True, annots=True, widgets=True, join_duplicates=False)

        if current_metadata:
            output.set_metadata(current_metadata)
        if combined_toc:
            try:
                output.set_toc(combined_toc)
            except Exception:
                combined_toc = []

        os.makedirs(os.path.dirname(output_path), exist_ok=True)
        output.save(output_path, garbage=3, deflate=True)
        output.close()
        output = None

        verification = fitz.open(output_path)
        try:
            if verification.page_count != total_pages:
                return fail("Merged PDF verification failed.")
        finally:
            verification.close()

        current_group = next(group for group in groups if group["id"] == "current")
        return {
            "success": True,
            "total_pages": total_pages,
            "current_document_start_page": current_group["start_page"],
            "documents": groups,
            "outlines_copied": len(combined_toc),
        }
    except (fitz.FileDataError, RuntimeError, ValueError, OSError, json.JSONDecodeError) as error:
        return fail(str(error) or "PDF merge failed.")
    except Exception:
        return fail("PDF merge failed unexpectedly.")
    finally:
        if output is not None:
            output.close()
        for document in opened:
            document.close()


if __name__ == "__main__":
    if len(sys.argv) != 2:
        print(json.dumps(fail("Usage: merge_pdf_documents.py <manifest.json>")))
        raise SystemExit(1)

    result = merge_from_manifest(sys.argv[1])
    print(json.dumps(result))
    raise SystemExit(0 if result.get("success") else 1)
