import io
import sys
import tempfile
import unittest
from pathlib import Path

import fitz
from PIL import Image, ImageDraw


PDF_EDITOR_DIR = Path(__file__).resolve().parents[1]
if str(PDF_EDITOR_DIR) not in sys.path:
    sys.path.insert(0, str(PDF_EDITOR_DIR))

from prepare_pdf_for_adobe import prepare_pdf  # noqa: E402


class PreparePdfForAdobeTest(unittest.TestCase):
    def test_rotated_page_keeps_native_text_vector_shapes_and_images(self) -> None:
        with tempfile.TemporaryDirectory(prefix="toolbase_adobe_prepare_test_") as directory:
            source = Path(directory) / "edited-rotated.pdf"
            output = Path(directory) / "adobe-input.pdf"

            signature = Image.new("RGBA", (120, 40), (255, 255, 255, 0))
            signature_draw = ImageDraw.Draw(signature)
            signature_draw.line([(8, 30), (45, 8), (112, 25)], fill=(20, 20, 20, 255), width=4)
            signature_bytes = io.BytesIO()
            signature.save(signature_bytes, format="PNG")

            document = fitz.open()
            page = document.new_page(width=460, height=300)
            page.insert_text(
                (40, 55),
                "VISIBLE EDITED TEXT",
                fontsize=18,
                color=(1, 0, 0),
            )
            shape = page.new_shape()
            shape.draw_polyline([
                fitz.Point(260, 80),
                fitz.Point(220, 190),
                fitz.Point(340, 190),
                fitz.Point(260, 80),
            ])
            shape.finish(color=(0.05, 0.1, 0.2), fill=(0.75, 0.95, 0.8), width=3)
            shape.commit()
            page.insert_image(
                fitz.Rect(40, 90, 160, 130),
                stream=signature_bytes.getvalue(),
                keep_proportion=False,
            )
            page.set_rotation(90)
            document.save(source)
            document.close()

            result = prepare_pdf(str(source), str(output))

            prepared = fitz.open(output)
            try:
                prepared_page = prepared[0]
                self.assertEqual(prepared_page.rotation, 0)
                self.assertAlmostEqual(prepared_page.rect.width, 300, delta=0.1)
                self.assertAlmostEqual(prepared_page.rect.height, 460, delta=0.1)
                self.assertIn("VISIBLE EDITED TEXT", prepared_page.get_text("text"))
                self.assertGreaterEqual(len(prepared_page.get_drawings()), 1)
                self.assertGreaterEqual(len(prepared_page.get_images(full=True)), 1)
            finally:
                prepared.close()

            self.assertTrue(result["prepared_for_adobe"])
            self.assertTrue(result["native_text_preserved"])
            self.assertTrue(result["vector_shapes_preserved"])
            self.assertTrue(result["image_layers_preserved"])
            self.assertEqual(result["pages"], 1)


if __name__ == "__main__":
    unittest.main()
