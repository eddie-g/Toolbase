"""NK_18: a rotated drawing has to reach the exported PDF rotated.

The editor keeps a rotated drawing's box axis-aligned and turns only its
content, so the export has to turn the raster and grow the rect to match.
These cover the geometry of that, since getting the sign or the centre wrong
would move the drawing somewhere the user never put it.
"""

import io
import math
import sys
import unittest
from pathlib import Path

import fitz
from PIL import Image


PDF_EDITOR_DIR = Path(__file__).resolve().parents[1]
if str(PDF_EDITOR_DIR) not in sys.path:
    sys.path.insert(0, str(PDF_EDITOR_DIR))

from apply_annotations_direct_new import rotate_direct_draw_raster  # noqa: E402


def build_marked_raster() -> bytes:
    """A 100x40 transparent PNG with an opaque block in its TOP-LEFT quadrant."""
    image = Image.new("RGBA", (100, 40), (255, 255, 255, 0))
    for y in range(0, 20):
        for x in range(0, 50):
            image.putpixel((x, y), (255, 0, 0, 255))
    output = io.BytesIO()
    image.save(output, format="PNG")
    return output.getvalue()


def heaviest_quadrant(image: Image.Image) -> str:
    width, height = image.size
    quadrants = {
        "TL": (0, 0, width // 2, height // 2),
        "TR": (width // 2, 0, width, height // 2),
        "BL": (0, height // 2, width // 2, height),
        "BR": (width // 2, height // 2, width, height),
    }
    totals = {}
    for name, (x0, y0, x1, y1) in quadrants.items():
        totals[name] = sum(
            image.getpixel((x, y))[3]
            for y in range(y0, y1)
            for x in range(x0, x1)
        )
    return max(totals, key=totals.get)


class DirectDrawRotationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.raster = build_marked_raster()
        # 200 x 80, centred on (200, 140).
        self.rect = fitz.Rect(100, 100, 300, 180)

    def test_no_rotation_leaves_the_drawing_untouched(self) -> None:
        for angle in (0, 0.0, None, "", "nonsense"):
            image_bytes, rect = rotate_direct_draw_raster(self.raster, self.rect, angle)
            self.assertIs(image_bytes, self.raster, f"angle {angle!r} rewrote the raster")
            self.assertEqual(rect, self.rect, f"angle {angle!r} moved the rect")

    def test_quarter_turn_swaps_the_extent_and_keeps_the_centre(self) -> None:
        image_bytes, rect = rotate_direct_draw_raster(self.raster, self.rect, 90)

        with Image.open(io.BytesIO(image_bytes)) as turned:
            self.assertEqual(turned.size, (40, 100), "A quarter turn must swap the pixel extent")

        self.assertAlmostEqual(rect.width, 80.0, places=6)
        self.assertAlmostEqual(rect.height, 200.0, places=6)
        self.assertAlmostEqual((rect.x0 + rect.x1) / 2.0, 200.0, places=6)
        self.assertAlmostEqual((rect.y0 + rect.y1) / 2.0, 140.0, places=6)

    def test_rotation_is_clockwise_like_the_editor(self) -> None:
        """A positive angle is clockwise, matching build_rotation_morph and CSS.

        PIL turns anti-clockwise for a positive angle, so a flipped sign in the
        helper is the whole point of this check: get it wrong and every rotated
        drawing exports mirrored about the angle.
        """
        image_bytes, _ = rotate_direct_draw_raster(self.raster, self.rect, 90)
        with Image.open(io.BytesIO(image_bytes)) as turned:
            self.assertEqual(
                heaviest_quadrant(turned.convert("RGBA")),
                "TR",
                "A clockwise quarter turn moves a top-left mark to the top right",
            )

        image_bytes, _ = rotate_direct_draw_raster(self.raster, self.rect, -90)
        with Image.open(io.BytesIO(image_bytes)) as turned:
            self.assertEqual(
                heaviest_quadrant(turned.convert("RGBA")),
                "BL",
                "An anti-clockwise quarter turn moves it to the bottom left",
            )

    def test_arbitrary_angle_grows_the_rect_by_the_pixel_ratio(self) -> None:
        angle = 35.0
        image_bytes, rect = rotate_direct_draw_raster(self.raster, self.rect, angle)

        radians = math.radians(angle)
        expected_width = (100 * abs(math.cos(radians))) + (40 * abs(math.sin(radians)))
        expected_height = (100 * abs(math.sin(radians))) + (40 * abs(math.cos(radians)))

        with Image.open(io.BytesIO(image_bytes)) as turned:
            self.assertAlmostEqual(turned.width, expected_width, delta=2)
            self.assertAlmostEqual(turned.height, expected_height, delta=2)

            # The rect has to grow by exactly the ratio the bitmap grew by, or
            # the drawing is scaled as well as turned.
            self.assertAlmostEqual(rect.width / self.rect.width, turned.width / 100.0, places=6)
            self.assertAlmostEqual(rect.height / self.rect.height, turned.height / 40.0, places=6)

        self.assertAlmostEqual((rect.x0 + rect.x1) / 2.0, 200.0, places=6)
        self.assertAlmostEqual((rect.y0 + rect.y1) / 2.0, 140.0, places=6)

    def test_a_broken_raster_costs_the_rotation_not_the_drawing(self) -> None:
        broken = b"not a png"
        image_bytes, rect = rotate_direct_draw_raster(broken, self.rect, 35)
        self.assertIs(image_bytes, broken)
        self.assertEqual(rect, self.rect)


if __name__ == "__main__":
    unittest.main()
