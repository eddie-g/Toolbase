"""Font files whose cmap aliases U+00A0 / U+00AD onto the space and hyphen
glyphs make MuPDF's generated ToUnicode reverse-map those glyphs to the
alias, so text drawn with them extracts as "Note: The" and "1099­K"
and can no longer be searched or copied. Hand the writers a cached copy
of the face without the aliases (NK_36)."""
from __future__ import annotations

import hashlib
import os
import tempfile
from typing import Optional

_ALIASED_CODEPOINTS = {
    0x00A0: 0x0020,  # NO-BREAK SPACE -> SPACE
    0x00AD: 0x002D,  # SOFT HYPHEN -> HYPHEN-MINUS
    0x2011: 0x002D,  # NON-BREAKING HYPHEN -> HYPHEN-MINUS
}

_CACHE: dict[str, str] = {}


def _cache_dir() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    project_root = os.path.abspath(os.path.join(here, os.pardir, os.pardir))
    for candidate in (
        os.path.join(project_root, "storage", "app", "temp", "font-instances", "cmap-sanitized"),
        os.path.join(tempfile.gettempdir(), "netkit-font-cmap-sanitized"),
    ):
        try:
            os.makedirs(candidate, exist_ok=True)
            probe = os.path.join(candidate, ".write-test")
            with open(probe, "w", encoding="utf-8") as fh:
                fh.write("ok")
            os.remove(probe)
            return candidate
        except Exception:
            continue
    return tempfile.gettempdir()


def font_file_aliases_whitespace_glyphs(path: str) -> bool:
    """True when the face maps a no-break space or soft hyphen to the same
    glyph as the plain space or hyphen."""
    try:
        from fontTools.ttLib import TTFont
    except Exception:
        return False
    try:
        tt = TTFont(path, lazy=True)
        try:
            cmap = tt.getBestCmap() or {}
        finally:
            tt.close()
    except Exception:
        return False
    return any(
        alias in cmap and cmap.get(alias) == cmap.get(plain)
        for alias, plain in _ALIASED_CODEPOINTS.items()
    )


def sanitized_font_file(path: Optional[str]) -> Optional[str]:
    """The font file to hand MuPDF: the original when its cmap is clean,
    otherwise a cached copy with the aliased codepoints dropped."""
    if not path:
        return path
    cached = _CACHE.get(path)
    if cached and os.path.exists(cached):
        return cached
    if not os.path.isfile(path) or not font_file_aliases_whitespace_glyphs(path):
        _CACHE[path] = path
        return path
    try:
        from fontTools.ttLib import TTFont
        stat = os.stat(path)
        digest = hashlib.sha1(f"{path}|{stat.st_size}|{int(stat.st_mtime)}".encode("utf-8")).hexdigest()[:16]
        # Keep the face's own file name (callers and tests key on it); the
        # per-source folder carries the cache identity instead.
        target_dir = os.path.join(_cache_dir(), digest)
        os.makedirs(target_dir, exist_ok=True)
        target = os.path.join(target_dir, os.path.basename(path))
        if not os.path.exists(target):
            tt = TTFont(path)
            try:
                for table in tt["cmap"].tables:
                    for alias in _ALIASED_CODEPOINTS:
                        table.cmap.pop(alias, None)
                tmp = f"{target}.{os.getpid()}.tmp"
                tt.save(tmp)
                os.replace(tmp, target)
            finally:
                tt.close()
        _CACHE[path] = target
        return target
    except Exception:
        _CACHE[path] = path
        return path
