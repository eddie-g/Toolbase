#!/usr/bin/env python3

import importlib.util
import pathlib
import sys
import tempfile
import unittest

import fitz


PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = PROJECT_ROOT / "python" / "pdf-editor" / "create_clean_pdf.py"


def load_module():
    module_dir = str(MODULE_PATH.parent)
    if module_dir not in sys.path:
        sys.path.insert(0, module_dir)
    spec = importlib.util.spec_from_file_location("create_clean_pdf", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError(f"Unable to load module from {MODULE_PATH}")
    spec.loader.exec_module(module)
    return module


class CreateCleanPdfTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = load_module()

    def test_clean_pdf_preserves_decorative_caution_cluster(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            source_path = pathlib.Path(tmp_dir) / "source.pdf"
            output_path = pathlib.Path(tmp_dir) / "clean.pdf"

            doc = fitz.open()
            page = doc.new_page(width=250, height=180)
            icon_rect = fitz.Rect(20, 20, 60, 60)
            page.draw_rect(icon_rect, color=(0, 0, 0), fill=(0, 0, 0))
            page.insert_text((36, 42), "!", fontsize=18, color=(1, 1, 1))
            page.insert_text((22, 56), "CAUTION", fontsize=7, color=(1, 1, 1))
            page.insert_text((120, 45), "HELLO", fontsize=12, color=(0, 0, 0))
            doc.save(source_path)
            doc.close()

            source_doc = fitz.open(source_path)
            source_page = source_doc[0]
            caution_rects = source_page.search_for("CAUTION")
            bang_rects = source_page.search_for("!")
            hello_rects = source_page.search_for("HELLO")
            source_doc.close()

            self.assertTrue(caution_rects)
            self.assertTrue(bang_rects)
            self.assertTrue(hello_rects)

            caution_rect = caution_rects[0]
            bang_rect = bang_rects[0]
            hello_rect = hello_rects[0]

            extraction_data = [{
                "page_number": 1,
                "words": [
                    {
                        "text": "!",
                        "left": bang_rect.x0,
                        "top": bang_rect.y0,
                        "width": bang_rect.width,
                        "height": bang_rect.height,
                    },
                    {
                        "text": "CAUTION",
                        "left": caution_rect.x0,
                        "top": caution_rect.y0,
                        "width": caution_rect.width,
                        "height": caution_rect.height,
                    },
                    {
                        "text": "HELLO",
                        "left": hello_rect.x0,
                        "top": hello_rect.y0,
                        "width": hello_rect.width,
                        "height": hello_rect.height,
                    },
                ],
                "blocks": [
                    {
                        "block_num": 1,
                        "text": "!",
                        "text_lines": ["!"],
                        "line_bboxes": [[bang_rect.x0, bang_rect.y0, bang_rect.x1, bang_rect.y1]],
                        "left": bang_rect.x0,
                        "top": bang_rect.y0,
                        "width": bang_rect.width,
                        "height": bang_rect.height,
                    },
                    {
                        "block_num": 2,
                        "text": "CAUTION",
                        "text_lines": ["CAUTION"],
                        "line_bboxes": [[caution_rect.x0, caution_rect.y0, caution_rect.x1, caution_rect.y1]],
                        "left": caution_rect.x0,
                        "top": caution_rect.y0,
                        "width": caution_rect.width,
                        "height": caution_rect.height,
                    },
                    {
                        "block_num": 3,
                        "text": "HELLO",
                        "text_lines": ["HELLO"],
                        "line_bboxes": [[hello_rect.x0, hello_rect.y0, hello_rect.x1, hello_rect.y1]],
                        "left": hello_rect.x0,
                        "top": hello_rect.y0,
                        "width": hello_rect.width,
                        "height": hello_rect.height,
                    },
                ],
            }]

            success = self.module.create_clean_pdf(str(source_path), extraction_data, str(output_path))
            self.assertTrue(success)
            self.assertTrue(output_path.exists())

            clean_doc = fitz.open(output_path)
            clean_page = clean_doc[0]
            try:
                clean_words = {word[4] for word in clean_page.get_text("words")}
            finally:
                clean_doc.close()

            self.assertIn("CAUTION", clean_words)
            self.assertIn("!", clean_words)
            self.assertNotIn("HELLO", clean_words)


if __name__ == "__main__":
    unittest.main()
