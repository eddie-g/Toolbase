"""Check that an uploaded file is a PDF the editor can work with.

usage: probe_pdf.py FILE.pdf

Prints one JSON object and exits 0 whenever it could answer:
  {"ok": true,  "pages": 12, "encrypted": false}
  {"ok": false, "reason": "needs_password" | "unreadable" | "no_pages", "detail": "..."}

Run before anything is stored or queued, with a short timeout: a file that
hangs the parser here would hang the extraction for minutes.
"""
import json
import sys

import fitz


def main() -> int:
    path = sys.argv[1]
    try:
        doc = fitz.open(path)
    except Exception as exc:  # corrupt, truncated, not a PDF at all
        print(json.dumps({"ok": False, "reason": "unreadable", "detail": str(exc)[:200]}))
        return 0

    try:
        if not doc.is_pdf:
            print(json.dumps({"ok": False, "reason": "unreadable", "detail": "not a PDF"}))
            return 0
        # needs_pass stays true for a PDF that cannot be opened without a user
        # password. An owner-password-only PDF opens fine and is accepted.
        if doc.needs_pass:
            print(json.dumps({"ok": False, "reason": "needs_password"}))
            return 0
        pages = doc.page_count
        if pages < 1:
            print(json.dumps({"ok": False, "reason": "no_pages"}))
            return 0
        # Touch the first and last page: a broken page tree passes page_count.
        doc.load_page(0)
        doc.load_page(pages - 1)
        print(json.dumps({"ok": True, "pages": pages, "encrypted": bool(doc.is_encrypted)}))
        return 0
    except Exception as exc:
        print(json.dumps({"ok": False, "reason": "unreadable", "detail": str(exc)[:200]}))
        return 0
    finally:
        doc.close()


if __name__ == "__main__":
    sys.exit(main())
