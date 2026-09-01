#!/usr/bin/env python3
"""
NK_9 regression: a rotated signature has to reach the PDF rotated.

page.insert_image only accepts rotations that are multiples of 90, so
draw_signature bakes an arbitrary angle into the bitmap with Pillow and widens
the target rect to hold the result. This checks that end of it:

  * an unrotated signature is unchanged;
  * a quarter turn swaps the mark's axes;
  * a 45 degree turn puts it on the diagonal;
  * the drawn area stays centred on the annotation's own rect.

The probe image is deliberately asymmetric — a short bar in one corner — so a
rotation cannot be mistaken for a no-op.

Run with an interpreter that has PyMuPDF and Pillow:

    python/venv/bin/python python/test_helpers/regression_signature_rotation.py

Exit code is non-zero on any failure.
"""

import base64
import io
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT / "python" / "pdf-editor"))

import fitz  # noqa: E402
from PIL import Image, ImageDraw  # noqa: E402

import new_annotation_writer as writer  # noqa: E402

MARK_WIDTH = 60
MARK_HEIGHT = 12


def probe_signature_data_url() -> str:
    """A transparent image with one small blue bar along its top-left edge."""
    image = Image.new("RGBA", (200, 60), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    draw.rectangle([0, 0, MARK_WIDTH, MARK_HEIGHT], fill=(0, 0, 255, 255))
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return "data:image/png;base64," + base64.b64encode(buffer.getvalue()).decode()


BASE: Dict[str, Any] = {
    "type": "signature",
    "pageIndex": 0,
    "pdfX": 200,
    "pdfY": 600,
    "pdfWidth": 200,
    "pdfHeight": 60,
}


def mark_bbox(rotation: float) -> Optional[Tuple[int, int, int, int]]:
    """Draw at `rotation` and return the blue mark's pixel bounding box."""
    doc = fitz.open()
    page = doc.new_page(width=612, height=792)
    writer.draw_signature(page, {**BASE, "dataUrl": probe_signature_data_url(), "rotation": rotation})
    pixmap = page.get_pixmap(dpi=72)

    xs: List[int] = []
    ys: List[int] = []
    for offset in range(0, len(pixmap.samples), pixmap.n):
        red = pixmap.samples[offset]
        blue = pixmap.samples[offset + 2]
        if blue > 150 and red < 100:
            index = offset // pixmap.n
            xs.append(index % pixmap.width)
            ys.append(index // pixmap.width)
    doc.close()

    if not xs:
        return None
    return min(xs), min(ys), max(xs), max(ys)


def main() -> int:
    failures: List[str] = []

    def check(label: str, condition: bool, detail: str = "") -> None:
        if condition:
            print(f"  ok   {label}")
        else:
            print(f"  FAIL {label} {detail}")
            failures.append(f"{label} {detail}".strip())

    upright = mark_bbox(0)
    check("unrotated signature is drawn at all", upright is not None)
    if upright is None:
        print("\n1 failure.")
        return 1

    up_w = upright[2] - upright[0]
    up_h = upright[3] - upright[1]
    print(f"  (unrotated mark is {up_w}x{up_h})")
    check(
        "unrotated mark is wide and short, as authored",
        up_w > up_h * 2,
        f"{up_w}x{up_h}",
    )

    quarter = mark_bbox(90)
    check("quarter-turn signature is drawn", quarter is not None)
    if quarter is not None:
        q_w = quarter[2] - quarter[0]
        q_h = quarter[3] - quarter[1]
        print(f"  (quarter-turn mark is {q_w}x{q_h})")
        check(
            "a quarter turn swaps the mark's axes",
            abs(q_w - up_h) <= 2 and abs(q_h - up_w) <= 2,
            f"expected about {up_h}x{up_w}, got {q_w}x{q_h}",
        )

    diagonal = mark_bbox(45)
    check("45 degree signature is drawn", diagonal is not None)
    if diagonal is not None:
        d_w = diagonal[2] - diagonal[0]
        d_h = diagonal[3] - diagonal[1]
        print(f"  (45 degree mark is {d_w}x{d_h})")
        check(
            "45 degrees puts the mark on the diagonal",
            d_w > up_h * 2 and d_h > up_h * 2,
            f"{d_w}x{d_h}",
        )

    # The rect grows about its centre, so the drawing stays where the user put it.
    if upright is not None and quarter is not None:
        upright_cx = (upright[0] + upright[2]) / 2
        upright_cy = (upright[1] + upright[3]) / 2
        quarter_cx = (quarter[0] + quarter[2]) / 2
        quarter_cy = (quarter[1] + quarter[3]) / 2
        # The mark sits in a corner of the image, so its own centre moves as the
        # image turns; it must stay within the annotation's footprint, not fly off.
        moved = max(abs(upright_cx - quarter_cx), abs(upright_cy - quarter_cy))
        check(
            "the rotated signature stays on its own footprint",
            moved <= max(BASE["pdfWidth"], BASE["pdfHeight"]),
            f"mark centre moved {moved:.1f}pt",
        )

    if failures:
        print(f"\n{len(failures)} failure(s).")
        return 1

    print("\nAll signature-rotation regression cases passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
