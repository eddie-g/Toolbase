#!/usr/bin/env python3
"""
Scan public/fonts/runtime-extracted/ for TrueType files whose cmap-mapped
glyphs are mostly empty (the silent-text-vanishing failure mode documented
in /memories/repo/edit-new-broken-runtime-extracted-fonts.md).

By default just lists offenders. Pass --delete to remove the broken files
(the editor will then fall back to system fonts on next page load). Pass
--reextract DOC_ID to re-run extract_embedded_fonts for a specific document
(requires storage/app/private/temp/clean_<DOC_ID>.pdf to exist).

The browser-side guard in resources/views/documents/edit-new.blade.php
also catches these at runtime, so this script is for housekeeping only.
"""
from __future__ import annotations
import argparse
import glob
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from extract_pdf_pymupdf import (  # noqa: E402
    _is_truetype_glyph_outline_broken,
    _truetype_glyph_health,
    extract_embedded_fonts,
)


def scan(root: str):
    broken = []
    for ttf in sorted(glob.glob(os.path.join(root, '*', '*.ttf'))):
        try:
            with open(ttf, 'rb') as fh:
                data = fh.read()
        except OSError:
            continue
        cmap, empty, filled = _truetype_glyph_health(data)
        if cmap == 0:
            continue
        if _is_truetype_glyph_outline_broken(data):
            broken.append((ttf, cmap, empty, filled))
    return broken


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--root', default='public/fonts/runtime-extracted',
                    help='Directory to scan (default: %(default)s)')
    ap.add_argument('--delete', action='store_true',
                    help='Delete broken files (editor will fall back to system fonts).')
    ap.add_argument('--reextract', type=int, metavar='DOC_ID',
                    help='Re-run extract_embedded_fonts for the given document.')
    args = ap.parse_args()

    if args.reextract is not None:
        pdf = os.path.join('storage', 'app', 'private', 'temp', f'clean_{args.reextract}.pdf')
        if not os.path.isfile(pdf):
            print(f'PDF not found: {pdf}', file=sys.stderr)
            return 1
        extract_embedded_fonts(pdf, args.reextract)
        return 0

    broken = scan(args.root)
    print(f'Scanned {args.root}: {len(broken)} broken TTF file(s).')
    for path, cmap, empty, filled in broken:
        print(f'  {path}  cmap={cmap} empty={empty} filled={filled}')
    if args.delete and broken:
        for path, *_ in broken:
            try:
                os.unlink(path)
                print(f'  deleted: {path}')
            except OSError as e:
                print(f'  failed to delete {path}: {e}', file=sys.stderr)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
