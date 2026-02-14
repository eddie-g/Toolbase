#!/usr/bin/env python3
"""
Split a PDF into individual page files or by page range.

Usage:
    python3 split_pdf.py <input_pdf> <output_dir> --mode <mode> [options] --json

Modes:
    each       - Split every page into a separate PDF
    specific   - Extract a single page:  --page <N>
    range      - Extract a range:        --from <N> --to <N>
    custom     - Comma-separated list:   --pages "1,3,5-8,12"

Output:
    JSON line: { "success": true, "files": [...], "total_pages": N }
    or         { "success": false, "error": "..." }
"""

import argparse
import json
import os
import sys

try:
    import fitz  # PyMuPDF
except ImportError:
    print(json.dumps({"success": False, "error": "PyMuPDF (fitz) is not installed"}))
    sys.exit(1)


def parse_custom_pages(spec: str, total: int) -> list[int]:
    """Parse a custom page specification like '1,3,5-8,12' into a sorted list of 0-based page indices."""
    pages = set()
    for part in spec.split(","):
        part = part.strip()
        if not part:
            continue
        if "-" in part:
            start_str, end_str = part.split("-", 1)
            start = int(start_str.strip())
            end = int(end_str.strip())
            if start < 1 or end > total or start > end:
                raise ValueError(f"Invalid range {part} (document has {total} pages)")
            for p in range(start, end + 1):
                pages.add(p - 1)  # 0-based
        else:
            p = int(part)
            if p < 1 or p > total:
                raise ValueError(f"Page {p} out of range (document has {total} pages)")
            pages.add(p - 1)
    return sorted(pages)


def split_pdf(input_path: str, output_dir: str, mode: str, **kwargs) -> dict:
    if not os.path.isfile(input_path):
        return {"success": False, "error": f"Input file not found: {input_path}"}

    os.makedirs(output_dir, exist_ok=True)

    try:
        doc = fitz.open(input_path)
    except Exception as e:
        return {"success": False, "error": f"Failed to open PDF: {e}"}

    total = doc.page_count
    base_name = os.path.splitext(os.path.basename(input_path))[0]
    output_files = []

    try:
        if mode == "each":
            # One PDF per page
            for i in range(total):
                out_doc = fitz.open()
                out_doc.insert_pdf(doc, from_page=i, to_page=i)
                out_path = os.path.join(output_dir, f"{base_name}_page_{i + 1}.pdf")
                out_doc.save(out_path, garbage=3, deflate=True)
                out_doc.close()
                output_files.append({"path": out_path, "page": i + 1, "name": os.path.basename(out_path)})

        elif mode == "specific":
            page_num = kwargs.get("page", 1)
            if page_num < 1 or page_num > total:
                return {"success": False, "error": f"Page {page_num} out of range (1-{total})"}
            out_doc = fitz.open()
            out_doc.insert_pdf(doc, from_page=page_num - 1, to_page=page_num - 1)
            out_path = os.path.join(output_dir, f"{base_name}_page_{page_num}.pdf")
            out_doc.save(out_path, garbage=3, deflate=True)
            out_doc.close()
            output_files.append({"path": out_path, "page": page_num, "name": os.path.basename(out_path)})

        elif mode == "range":
            from_page = kwargs.get("from_page", 1)
            to_page = kwargs.get("to_page", total)
            if from_page < 1 or to_page > total or from_page > to_page:
                return {"success": False, "error": f"Invalid range {from_page}-{to_page} (document has {total} pages)"}
            out_doc = fitz.open()
            out_doc.insert_pdf(doc, from_page=from_page - 1, to_page=to_page - 1)
            out_path = os.path.join(output_dir, f"{base_name}_pages_{from_page}-{to_page}.pdf")
            out_doc.save(out_path, garbage=3, deflate=True)
            out_doc.close()
            output_files.append({
                "path": out_path,
                "pages": list(range(from_page, to_page + 1)),
                "name": os.path.basename(out_path),
            })

        elif mode == "custom":
            pages_spec = kwargs.get("pages", "")
            page_indices = parse_custom_pages(pages_spec, total)
            if not page_indices:
                return {"success": False, "error": "No valid pages specified"}

            out_doc = fitz.open()
            for idx in page_indices:
                out_doc.insert_pdf(doc, from_page=idx, to_page=idx)

            # Build a short label from the pages
            page_nums = [idx + 1 for idx in page_indices]
            if len(page_nums) <= 4:
                label = ",".join(str(p) for p in page_nums)
            else:
                label = f"{page_nums[0]}-{page_nums[-1]}"
            out_path = os.path.join(output_dir, f"{base_name}_pages_{label}.pdf")
            out_doc.save(out_path, garbage=3, deflate=True)
            out_doc.close()
            output_files.append({
                "path": out_path,
                "pages": page_nums,
                "name": os.path.basename(out_path),
            })
        else:
            return {"success": False, "error": f"Unknown mode: {mode}"}

    except ValueError as e:
        doc.close()
        return {"success": False, "error": str(e)}
    except Exception as e:
        doc.close()
        return {"success": False, "error": f"Split failed: {e}"}

    doc.close()

    return {
        "success": True,
        "files": output_files,
        "total_pages": total,
        "file_count": len(output_files),
    }


def main():
    parser = argparse.ArgumentParser(description="Split a PDF")
    parser.add_argument("input", help="Input PDF path")
    parser.add_argument("output_dir", help="Output directory for split files")
    parser.add_argument("--mode", required=True, choices=["each", "specific", "range", "custom"])
    parser.add_argument("--page", type=int, help="Page number (specific mode)")
    parser.add_argument("--from", dest="from_page", type=int, help="Start page (range mode)")
    parser.add_argument("--to", dest="to_page", type=int, help="End page (range mode)")
    parser.add_argument("--pages", type=str, help="Page spec e.g. '1,3,5-8' (custom mode)")
    parser.add_argument("--json", action="store_true", help="JSON output")
    args = parser.parse_args()

    result = split_pdf(
        args.input,
        args.output_dir,
        args.mode,
        page=args.page,
        from_page=args.from_page,
        to_page=args.to_page,
        pages=args.pages,
    )

    if args.json:
        print(json.dumps(result))
    else:
        if result["success"]:
            print(f"Split complete: {result['file_count']} file(s) created")
            for f in result["files"]:
                print(f"  {f['name']}")
        else:
            print(f"Error: {result['error']}", file=sys.stderr)
            sys.exit(1)


if __name__ == "__main__":
    main()
