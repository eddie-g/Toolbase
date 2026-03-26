#!/usr/bin/env python3

import importlib.util
import pathlib
import shutil
import sys
import tempfile
import unittest

import fitz


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "pdf-editor" / "apply_annotations_redraw_pages.py"
ROOT_PATH = pathlib.Path(__file__).resolve().parents[2]


def load_module():
    module_dir = str(MODULE_PATH.parent)
    if module_dir not in sys.path:
        sys.path.insert(0, module_dir)
    spec = importlib.util.spec_from_file_location("apply_annotations_redraw_pages", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError(f"Unable to load module from {MODULE_PATH}")
    spec.loader.exec_module(module)
    return module


class ApplyAnnotationsRedrawPagesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = load_module()

    def test_should_skip_word_only_when_geometry_overlaps_active_edit_rect(self):
        mask_rect = fitz.Rect(95, 95, 155, 125)

        edited_word = {
            "text": "PARTIAL",
            "block_num": 9,
            "left": 100,
            "top": 100,
            "width": 48,
            "height": 12,
        }
        sibling_word = {
            "text": "Addendum",
            "block_num": 9,
            "left": 210,
            "top": 100,
            "width": 62,
            "height": 12,
        }

        self.assertTrue(
            self.module.should_skip_word(edited_word, [mask_rect], [])
        )
        self.assertFalse(
            self.module.should_skip_word(sibling_word, [mask_rect], [])
        )

    def test_deleted_source_key_line_mask_targets_only_requested_line_range(self):
        page_data = {
            "blocks": [
                {
                    "block_num": 9,
                    "left": 30,
                    "top": 80,
                    "width": 300,
                    "height": 80,
                    "line_bboxes": [
                        [30, 80, 330, 96],
                        [30, 102, 330, 118],
                        [30, 124, 330, 140],
                    ],
                }
            ],
            "words": [],
        }

        rects = self.module.get_deleted_source_mask_rects(
            page_data,
            "block-1-9-lines-1-1",
        )

        self.assertEqual(len(rects), 1)
        self.assertAlmostEqual(rects[0].x0, 29.25, places=2)
        self.assertAlmostEqual(rects[0].y0, 101.25, places=2)
        self.assertAlmostEqual(rects[0].x1, 330.75, places=2)
        self.assertAlmostEqual(rects[0].y1, 118.75, places=2)

    def test_should_skip_word_keeps_widget_guard(self):
        word = {
            "text": "State",
            "left": 400,
            "top": 200,
            "width": 30,
            "height": 12,
        }
        widget_rect = fitz.Rect(398, 198, 460, 230)

        self.assertTrue(
            self.module.should_skip_word(word, [], [widget_rect])
        )

    def test_redrawn_text_annotations_skip_secondary_promoted_source_erase(self):
        captured_annotations = []
        original_draw_text = self.module.draw_text

        def fake_draw_text(page, annotation):
            captured_annotations.append(dict(annotation))

        self.module.draw_text = fake_draw_text
        try:
            self.module.draw_annotation(object(), {
                "type": "text",
                "promotedFromExtraction": True,
                "promotedDirty": True,
            })
        finally:
            self.module.draw_text = original_draw_text

        self.assertEqual(len(captured_annotations), 1)
        self.assertTrue(captured_annotations[0].get("skipPromotedSourceErase"))

    def test_rebuild_preserves_untouched_acroform_pages(self):
        source_pdf_path = ROOT_PATH / "public" / "i-9.pdf"
        self.assertTrue(source_pdf_path.exists(), f"Missing fixture PDF: {source_pdf_path}")

        with tempfile.TemporaryDirectory() as temp_dir:
            clean_pdf_path = pathlib.Path(temp_dir) / "clean.pdf"
            output_pdf_path = pathlib.Path(temp_dir) / "output.pdf"
            shutil.copyfile(source_pdf_path, clean_pdf_path)

            annotations = [
                {
                    "id": "probe_title",
                    "type": "text",
                    "pageIndex": 0,
                    "pdfX": 198.8,
                    "pdfY": 750.8,
                    "pdfWidth": 218.3,
                    "pdfHeight": 16.5,
                    "text": "probe title",
                    "opacity": 1,
                    "fontSize": 11,
                    "rotation": 0,
                    "fontStyle": "normal",
                    "textAlign": "left",
                    "textColor": "#231f20",
                    "underline": False,
                    "fontFamily": "TimesRoman",
                    "fontWeight": "400",
                    "lineHeight": 13.2,
                }
            ]

            self.module.rebuild_pages_with_annotations(
                str(clean_pdf_path),
                [],
                annotations,
                str(output_pdf_path),
                [0],
                str(source_pdf_path),
                [],
            )

            rebuilt = fitz.open(output_pdf_path)
            widget_counts = [len(list(rebuilt[page_index].widgets() or [])) for page_index in range(rebuilt.page_count)]

            self.assertEqual(widget_counts[0], 52)
            self.assertEqual(widget_counts[2], 39)
            self.assertEqual(widget_counts[3], 39)


if __name__ == "__main__":
    unittest.main()
