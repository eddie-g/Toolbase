#!/usr/bin/env python3

import importlib.util
import pathlib
import unittest

import fitz


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "pdf-editor" / "apply_annotations_direct.py"


def load_module():
    spec = importlib.util.spec_from_file_location("apply_annotations_direct", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError(f"Unable to load module from {MODULE_PATH}")
    spec.loader.exec_module(module)
    return module


class ApplyAnnotationsDirectTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = load_module()

    class FakePage:
        def __init__(self):
            self.rect = fitz.Rect(0, 0, 612, 792)
            self.draw_rect_calls = []
            self.insert_text_calls = []
            self.draw_line_calls = []

        def draw_rect(self, rect, **kwargs):
            self.draw_rect_calls.append((fitz.Rect(rect), kwargs))

        def insert_text(self, point, text, **kwargs):
            self.insert_text_calls.append((fitz.Point(point), text, kwargs))

        def draw_line(self, start, end, **kwargs):
            self.draw_line_calls.append((fitz.Point(start), fitz.Point(end), kwargs))

        def insert_font(self, *args, **kwargs):
            return None

    def test_draw_text_with_custom_font_and_underline_without_rect(self):
        annotation = {
            "text": "Underline check",
            "pdfX": 72,
            "pdfY": 144,
            "fontFamily": "TimesRoman",
            "fontSize": 12,
            "underline": True,
            "textColor": "#000000",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)
        self.assertTrue(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertEqual(
            self.module.resolve_text_font_resource_name(annotation),
            "AnnotTimesRomanRegular",
        )

        doc = fitz.open()
        try:
            page = doc.new_page()
            self.module.draw_text(page, annotation)
            self.assertGreater(len(doc.tobytes()), 0)
        finally:
            doc.close()

    def test_draw_text_without_rect_uses_full_background_line_box(self):
        annotation = {
            "text": "asasdasdsadsad",
            "pdfX": 110.3550295857988,
            "pdfY": 692.8994082840236,
            "fontFamily": "Garamond",
            "fontSize": 36,
            "textAlign": "left",
            "textColor": "#bc1010",
            "backgroundColor": "#1f0909",
        }

        page = self.FakePage()

        self.module.draw_text(page, annotation)

        self.assertEqual(len(page.draw_rect_calls), 1)
        self.assertEqual(len(page.insert_text_calls), 1)

        background_rect, _ = page.draw_rect_calls[0]
        insert_point, _, _ = page.insert_text_calls[0]
        fontfile = self.module.resolve_text_fontfile(annotation)
        font = fitz.Font(fontfile=fontfile) if fontfile else fitz.Font(self.module.resolve_text_fontname(annotation))
        ascender, descender = self.module.resolve_font_vertical_metrics(font)
        expected_width = font.text_length(annotation["text"], fontsize=annotation["fontSize"])
        expected_top = insert_point.y - (annotation["fontSize"] * ascender)
        expected_bottom = insert_point.y + (annotation["fontSize"] * descender)

        self.assertAlmostEqual(background_rect.x0, insert_point.x, places=3)
        self.assertAlmostEqual(background_rect.x1, insert_point.x + expected_width, places=3)
        self.assertAlmostEqual(background_rect.y0, expected_top, places=3)
        self.assertAlmostEqual(background_rect.y1, expected_bottom, places=3)
        self.assertGreater(background_rect.y1, insert_point.y)


if __name__ == "__main__":
    unittest.main()