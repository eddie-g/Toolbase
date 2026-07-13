#!/usr/bin/env python3

import importlib.util
import io
import pathlib
import shutil
import sys
import tempfile
import unittest
from unittest.mock import patch

import fitz
from PIL import Image


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "pdf-editor" / "apply_annotations_direct_new.py"


def load_module():
    module_dir = str(MODULE_PATH.parent)
    if module_dir not in sys.path:
        sys.path.insert(0, module_dir)
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

    class FakeShape:
        def __init__(self, page):
            self.page = page

        def draw_rect(self, rect):
            self.page.shape_draw_rect_calls.append(fitz.Rect(rect))

        def draw_line(self, start, end):
            self.page.shape_draw_line_calls.append((fitz.Point(start), fitz.Point(end)))

        def draw_polyline(self, points):
            self.page.shape_draw_polyline_calls.append([fitz.Point(point) for point in points])

        def finish(self, **kwargs):
            self.page.shape_finish_calls.append(kwargs)

        def commit(self, overlay=True):
            self.page.shape_commit_calls.append({"overlay": overlay})

    class FakePage:
        def __init__(self):
            self.rect = fitz.Rect(0, 0, 612, 792)
            self.draw_rect_calls = []
            self.insert_text_calls = []
            self.draw_line_calls = []
            self.add_redact_annot_calls = []
            self.apply_redactions_calls = []
            self.shape_draw_rect_calls = []
            self.shape_draw_line_calls = []
            self.shape_draw_polyline_calls = []
            self.shape_finish_calls = []
            self.shape_commit_calls = []

        def draw_rect(self, rect, **kwargs):
            self.draw_rect_calls.append((fitz.Rect(rect), kwargs))

        def insert_text(self, point, text, **kwargs):
            self.insert_text_calls.append((fitz.Point(point), text, kwargs))

        def draw_line(self, start, end, **kwargs):
            self.draw_line_calls.append((fitz.Point(start), fitz.Point(end), kwargs))

        def add_redact_annot(self, rect, **kwargs):
            self.add_redact_annot_calls.append((fitz.Rect(rect), kwargs))

        def apply_redactions(self, **kwargs):
            self.apply_redactions_calls.append(kwargs)

        def new_shape(self):
            return ApplyAnnotationsDirectTests.FakeShape(self)

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

        self.assertEqual(len(page.draw_rect_calls) + len(page.shape_draw_rect_calls), 1)
        self.assertEqual(len(page.insert_text_calls), 1)

        background_rect = page.draw_rect_calls[0][0] if page.draw_rect_calls else page.shape_draw_rect_calls[0]
        insert_point, _, _ = page.insert_text_calls[0]
        fontfile = self.module.resolve_text_fontfile(annotation)
        font = fitz.Font(fontfile=fontfile) if fontfile else fitz.Font(self.module.resolve_text_fontname(annotation))
        ascender, descender = self.module.resolve_font_vertical_metrics(font)
        expected_width = font.text_length(annotation["text"], fontsize=annotation["fontSize"])
        expected_top = insert_point.y - (annotation["fontSize"] * ascender)
        expected_bottom = insert_point.y + (annotation["fontSize"] * descender)

        self.assertAlmostEqual(background_rect.x0, insert_point.x - 6.0, places=3)
        self.assertAlmostEqual(background_rect.x1, insert_point.x + expected_width + 6.0, places=3)
        self.assertAlmostEqual(background_rect.y0, expected_top, places=3)
        self.assertAlmostEqual(background_rect.y1, expected_bottom, places=3)
        self.assertGreater(background_rect.y1, insert_point.y)

    def test_guided_lease_helvetica_field_uses_pdf_base_font(self):
        annotation = {
            "type": "text",
            "text": "Jun 28, 2025",
            "pdfX": 295.503,
            "pdfY": 423.57,
            "pdfWidth": 55.8984,
            "pdfHeight": 11.4,
            "fontFamily": "Helvetica",
            "fontSourceName": "Helvetica",
            "fontSize": 9,
            "lineHeight": 10.8,
            "textColor": "#111827",
            "backgroundColor": "transparent",
            "guidedLeaseField": True,
            "savedTextOverlay": True,
            "userCreated": True,
            "userAuthored": True,
            "userSizedTextBox": True,
            "userForcedRichText": True,
            "forceEmbeddedFont": True,
            "pdfjsForceEmbeddedFont": True,
            "skipPdfjsSourceMask": True,
            "pageIndex": 0,
        }

        page = self.FakePage()

        self.module.draw_text(page, annotation)

        self.assertEqual(len(page.insert_text_calls), 1)
        insert_point, _, kwargs = page.insert_text_calls[0]
        self.assertEqual(kwargs.get("fontname"), "helv")
        font = fitz.Font("helv")
        ascender, _ = self.module.resolve_font_vertical_metrics(font)
        expected_unlifted_baseline = (
            self.module.to_rect(page, annotation).y0
            + (annotation["fontSize"] * ascender)
        )
        expected_lift = (
            annotation["fontSize"]
            * self.module.GUIDED_LEASE_FIELD_BASELINE_LIFT_RATIO
        )
        self.assertAlmostEqual(insert_point.y, expected_unlifted_baseline - expected_lift, places=3)

    def test_guided_lease_field_mask_only_clears_field_rect(self):
        annotation = {
            "type": "text",
            "text": "Jul 6, 2026",
            "pdfX": 358.332,
            "pdfY": 680.7,
            "pdfWidth": 54.0384,
            "pdfHeight": 12.75,
            "fontFamily": "Helvetica",
            "fontSize": 9,
            "guidedLeaseField": True,
            "pageIndex": 0,
        }

        page = self.FakePage()

        self.module.draw_text(page, annotation, mask_only=True)

        self.assertEqual(len(page.draw_rect_calls), 1)
        mask_rect, kwargs = page.draw_rect_calls[0]
        self.assertEqual(kwargs.get("fill"), (1, 1, 1))
        self.assertEqual(kwargs.get("width"), 0)
        self.assertAlmostEqual(mask_rect.x0, annotation["pdfX"], places=3)
        self.assertAlmostEqual(mask_rect.x1, annotation["pdfX"] + annotation["pdfWidth"], places=3)

    def test_pdfjs_source_overlay_white_text_does_not_get_placeholder_background(self):
        annotation = {
            "type": "text",
            "text": "Unit Price",
            "savedTextOverlay": True,
            "pdfjsSourceText": "Unit Price",
            "pdfjsSourceX": 320,
            "pdfjsSourceY": 720,
            "pdfjsSourceW": 52,
            "pdfjsSourceH": 20,
            "pdfX": 320,
            "pdfY": 720,
            "pdfWidth": 52,
            "pdfHeight": 20,
            "fontFamily": "Helvetica",
            "fontSize": 8,
            "textColor": "#ffffff",
            "backgroundColor": "transparent",
        }

        page = self.FakePage()

        with patch.object(
            self.module,
            "substitute_display_bg_for_invisible_text",
            side_effect=AssertionError("PDF.js source overlays must not receive placeholder backgrounds"),
        ):
            self.module.draw_text(page, annotation, source_masks_already_drawn=True)
        self.assertEqual(len(page.draw_rect_calls), 0)
        self.assertEqual(len(page.shape_draw_rect_calls), 0)

    def test_promoted_font_only_overlay_white_text_does_not_get_placeholder_background(self):
        annotation = {
            "id": "promoted_1_1",
            "type": "text",
            "text": "1234 Company St.\nCompany Town ST 12345",
            "originalText": "1234 Company St.\nCompany Town ST 12345",
            "pdfjsSourceText": "1234 Company St.\nCompany Town ST 12345",
            "promotedFromExtraction": True,
            "savedTextOverlay": True,
            "userAuthored": True,
            "styleDirty": True,
            "pdfjsSourceX": 24,
            "pdfjsSourceY": 717,
            "pdfjsSourceW": 124,
            "pdfjsSourceH": 22,
            "pdfX": 24,
            "pdfY": 717,
            "pdfWidth": 124,
            "pdfHeight": 22,
            "fontFamily": "Helvetica",
            "fontSize": 9.75,
            "textColor": "#ffffff",
            "backgroundColor": "transparent",
        }

        page = self.FakePage()

        self.assertTrue(self.module.is_pdfjs_visible_overlay_text(annotation))
        self.assertFalse(self.module.is_redundant_pdfjs_source_overlay(annotation))
        with patch.object(
            self.module,
            "substitute_display_bg_for_invisible_text",
            side_effect=AssertionError("font-only promoted overlays must not receive placeholder backgrounds"),
        ):
            self.module.draw_text(page, annotation, source_masks_already_drawn=True)

        self.assertEqual(len(page.draw_rect_calls), 0)
        self.assertEqual(len(page.shape_draw_rect_calls), 0)
        self.assertGreaterEqual(len(page.insert_text_calls), 1)

    def test_draw_text_normalizes_nonbreaking_spaces_before_pdf_write(self):
        annotation = {
            "text": "Spiders\u00A0in\u00A0The\u00A0Lord\u00A0of\u00A0the\u00A0Rings",
            "pdfX": 72,
            "pdfY": 144,
            "fontFamily": "Helvetica",
            "fontSize": 16,
            "textColor": "#000000",
        }

        page = self.FakePage()

        self.module.draw_text(page, annotation)

        self.assertEqual(len(page.insert_text_calls), 1)
        self.assertEqual(
            page.insert_text_calls[0][1],
            "Spiders in The Lord of the Rings",
        )

    def test_pdfjs_moved_source_line_ignores_source_baseline_offset(self):
        text = (
            "Measures and Assessments: Explain how the employer measures and confirms "
            "whether individuals filling positions such as that being filled by the"
        )
        annotation = {
            "id": "pdfjs_4172_2_2:19",
            "type": "text",
            "pageIndex": 0,
            "text": text,
            "originalText": text,
            "pdfX": 46.5,
            "pdfY": 175.61286,
            "pdfWidth": 513.291,
            "pdfHeight": 7.97814,
            "fontFamily": "sans-serif",
            "fontSize": 7.98,
            "lineHeight": 7.98,
            "textColor": "#000000",
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsUseSourceTypography": True,
            "pdfjsSourceBaselineOffsetX": 0,
            "pdfjsSourceBaselineOffsetY": 16.01,
            "pdfjsSourceX": 42.3,
            "pdfjsSourceY": 181.0125,
            "pdfjsSourceW": 513.292,
            "pdfjsSourceH": 7.978,
            "pdfjsSourceText": text,
            "pdfjsSourceMaskX": 42.3,
            "pdfjsSourceMaskY": 181.0125,
            "pdfjsSourceMaskW": 513.292,
            "pdfjsSourceMaskH": 7.978,
            "pdfjsEditorMode": "source",
        }

        doc = fitz.open()
        try:
            page = doc.new_page(width=612, height=792)
            self.module.draw_text(page, annotation, source_masks_already_drawn=True)
            self.assertIn("Measures and Assessments", page.get_text())
        finally:
            doc.close()

    def test_pdfjs_text_clamps_bad_baseline_offset_instead_of_rendering_blank(self):
        annotation = {
            "id": "pdfjs_bad_source_baseline",
            "type": "text",
            "pageIndex": 0,
            "text": "Visible replacement",
            "originalText": "Visible replacement",
            "pdfX": 40,
            "pdfY": 120,
            "pdfWidth": 220,
            "pdfHeight": 8,
            "fontFamily": "sans-serif",
            "fontSize": 8,
            "lineHeight": 8,
            "textColor": "#000000",
            "savedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsUseSourceTypography": True,
            "pdfjsSourceBaselineOffsetX": 0,
            "pdfjsSourceBaselineOffsetY": 24,
        }

        doc = fitz.open()
        try:
            page = doc.new_page(width=612, height=792)
            self.module.draw_text(page, annotation, source_masks_already_drawn=True)
            self.assertIn("Visible replacement", page.get_text())
        finally:
            doc.close()

    def test_user_created_text_is_not_pdfjs_source_overlay(self):
        annotation = {
            "id": "pdfjs_4193_5_new_example",
            "type": "text",
            "text": "5-22-2026",
            "savedTextOverlay": True,
            "userCreated": True,
            "userAuthored": True,
            "skipPdfjsSourceMask": True,
            "pdfjsSourceX": 396.6,
            "pdfjsSourceY": 244.5,
            "pdfjsSourceW": 56.7,
            "pdfjsSourceH": 15,
        }

        self.assertFalse(self.module.is_pdfjs_visible_overlay_text(annotation))

    def test_pdfjs_visible_export_masks_do_not_punch_moved_replacement_glyphs(self):
        source_pdf = pathlib.Path(__file__).resolve().parents[2] / "public" / "spiders.pdf"
        annotations = [
            {
                "id": "pdfjs_overlap_source_3",
                "pdfX": 78.2907,
                "pdfY": 609.9936299999999,
                "text": "Tolkien's spiders embody ancient evil, corruption, and the consuming nature of fdsfsdf.",
                "type": "text",
                "color": "#000000",
                "opacity": 1,
                "fontSize": 11.00001,
                "pdfWidth": 433.4700000000001,
                "fontStyle": "normal",
                "pdfHeight": 11.00157,
                "textColor": "#000000",
                "fontFamily": "sans-serif",
                "fontWeight": "400",
                "lineHeight": 11.00001,
                "originalText": "Tolkien's spiders embody ancient evil, corruption, and the consuming nature of darkness.",
                "pdfjsSourceH": 11.0015625,
                "pdfjsSourceW": 433.46989746093755,
                "pdfjsSourceX": 77.99062500000001,
                "pdfjsSourceY": 627.0937499999999,
                "pdfjsEditorMode": "source",
                "pdfjsSourceText": "Tolkien's spiders embody ancient evil, corruption, and the consuming nature of darkness.",
                "movedTextOverlay": True,
                "savedTextOverlay": True,
                "pdfjsSourceFidelity": True,
                "pdfjsSourceFontStyle": "normal",
                "pdfjsSourceTextColor": "#000000",
                "pdfjsSourceFontFamily": "sans-serif",
                "pdfjsSourceFontSizePx": "36.6667",
                "pdfjsSourceFontWeight": "400",
                "pdfjsSourceTextWidthPx": "1440.061053064827",
                "pdfjsSourceLineHeightPx": "36.6667px",
                "pdfjsSourceTransformScaleX": "1.00336",
            },
            {
                "id": "pdfjs_overlap_source_4",
                "pdfX": 78.2907,
                "pdfY": 627.0516299999999,
                "text": "Their presence connects the main story to deeper mythological roots that stretch back to the",
                "type": "text",
                "color": "#000000",
                "opacity": 1,
                "fontSize": 11.00001,
                "pdfWidth": 448.155,
                "fontStyle": "normal",
                "pdfHeight": 11.00157,
                "textColor": "#000000",
                "fontFamily": "sans-serif",
                "fontWeight": "400",
                "lineHeight": 11.00001,
                "originalText": "Their presence connects the main story to deeper mythological roots that stretch back to the",
                "pdfjsSourceH": 11.0015625,
                "pdfjsSourceW": 448.1548828125,
                "pdfjsSourceX": 77.99062500000001,
                "pdfjsSourceY": 612.0515624999999,
                "pdfjsEditorMode": "source",
                "pdfjsSourceText": "Their presence connects the main story to deeper mythological roots that stretch back to the",
                "movedTextOverlay": True,
                "savedTextOverlay": True,
                "pdfjsSourceFidelity": True,
                "pdfjsSourceFontStyle": "normal",
                "pdfjsSourceTextColor": "#000000",
                "pdfjsSourceFontFamily": "sans-serif",
                "pdfjsSourceFontSizePx": "36.6667",
                "pdfjsSourceFontWeight": "400",
                "pdfjsSourceTextWidthPx": "1493.655434168558",
                "pdfjsSourceLineHeightPx": "36.6667px",
                "pdfjsSourceTransformScaleX": "1.00013",
            },
        ]

        with tempfile.TemporaryDirectory() as tmpdir:
            output_pdf = pathlib.Path(tmpdir) / "spiders_overlap.pdf"
            shutil.copyfile(source_pdf, output_pdf)

            self.module.apply_annotations(str(output_pdf), annotations)

            doc = fitz.open(output_pdf)
            try:
                page = doc[0]
                matching_spans = []
                for block in page.get_text("rawdict").get("blocks", []):
                    for line in block.get("lines", []) or []:
                        for span in line.get("spans", []) or []:
                            chars = span.get("chars") or []
                            span_text = "".join(char.get("c", "") for char in chars)
                            if span_text.startswith("Their presence"):
                                matching_spans.append(span)

                target_span = min(matching_spans, key=lambda span: float(span.get("bbox", [0, 999])[1]), default=None)
                self.assertIsNotNone(target_span)
                scale = 4
                pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)
                image = Image.frombytes("RGB", (pix.width, pix.height), pix.samples)
                chars = target_span["chars"]
                for index in (12, 15, 28, 37, 49, 78, 83):
                    bbox = chars[index]["bbox"]
                    x0, y0, x1, y1 = [int(round(value * scale)) for value in bbox]
                    crop = image.crop((
                        max(0, x0 - 1),
                        max(0, y0 - 1),
                        min(image.width, x1 + 1),
                        min(image.height, y1 + 1),
                    ))
                    pixels = crop.tobytes()
                    dark_pixels = sum(
                        1 for offset in range(0, len(pixels), 3)
                        if pixels[offset] < 120
                        and pixels[offset + 1] < 120
                        and pixels[offset + 2] < 120
                    )
                    self.assertGreater(
                        dark_pixels,
                        25,
                        f"character {index} in moved replacement text was visually erased",
                    )
            finally:
                doc.close()

    def test_pdfjs_source_mask_uses_exact_frontend_source_box(self):
        page = self.FakePage()
        annotation = {
            "id": "pdfjs_exact_source_mask",
            "type": "text",
            "pageIndex": 0,
            "text": "SS-5",
            "savedTextOverlay": True,
            "pdfjsSourceX": 54.2765625,
            "pdfjsSourceY": 734.471125,
            "pdfjsSourceW": 47.09007568359375,
            "pdfjsSourceH": 24.0,
            "pdfjsSourceText": "SS-4",
        }

        self.module.draw_pdfjs_source_mask(page, annotation)

        self.assertEqual(len(page.draw_rect_calls), 1)
        rect, _kwargs = page.draw_rect_calls[0]
        self.assertAlmostEqual(rect.x0, annotation["pdfjsSourceX"], places=5)
        self.assertAlmostEqual(rect.x1, annotation["pdfjsSourceX"] + annotation["pdfjsSourceW"], places=5)
        self.assertAlmostEqual(rect.y0, 792 - (annotation["pdfjsSourceY"] + annotation["pdfjsSourceH"]), places=5)
        self.assertAlmostEqual(rect.y1, 792 - annotation["pdfjsSourceY"], places=5)

    def test_pdfjs_moved_source_mask_avoids_neighbor_text_for_non_promoted_overlay(self):
        page = self.FakePage()
        page.get_text = lambda mode: {
            "blocks": [{
                "lines": [{
                    "spans": [{
                        "chars": [
                            {"c": "f", "bbox": [25, 90, 30, 100]},
                            {"c": "o", "bbox": [31, 90, 36, 100]},
                        ],
                    }],
                }],
            }]
        }
        annotation = {
            "id": "pdfjs_moved_source_mask_non_promoted",
            "type": "text",
            "pageIndex": 0,
            "text": "DrylabNews",
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceMaskX": 10,
            "pdfjsSourceMaskY": 700,
            "pdfjsSourceMaskW": 200,
            "pdfjsSourceMaskH": 50,
            "pdfjsSourceText": "DrylabNews",
        }

        self.module.draw_pdfjs_source_mask(page, annotation)

        self.assertEqual(len(page.draw_rect_calls), 1)
        rect, _kwargs = page.draw_rect_calls[0]
        self.assertLessEqual(rect.y1, 90 - self.module.PDFJS_SOURCE_MASK_NEIGHBOR_GAP_PTS + 0.001)

    def test_pdfjs_moved_source_mask_avoids_overlapping_non_source_line(self):
        page = self.FakePage()
        page.get_text = lambda mode: {
            "blocks": [{
                "lines": [{
                    "spans": [{
                        "chars": [
                            {"c": "D", "bbox": [56.7, 56.7, 92.0, 154.2]},
                            {"c": "r", "bbox": [92.0, 56.7, 122.0, 154.2]},
                        ],
                    }],
                }, {
                    "spans": [{
                        "chars": [
                            {"c": "f", "bbox": [332.8, 141.2, 338.0, 157.1]},
                            {"c": "o", "bbox": [338.0, 141.2, 345.0, 157.1]},
                        ],
                    }],
                }],
            }]
        }
        annotation = {
            "id": "pdfjs_moved_drylab_title_mask",
            "type": "text",
            "pageIndex": 0,
            "text": "DrylabNews",
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceMaskX": 56.7,
            "pdfjsSourceMaskY": 792 - 154.2,
            "pdfjsSourceMaskW": 493.4,
            "pdfjsSourceMaskH": 97.5,
            "pdfjsSourceText": "DrylabNews",
        }

        self.module.draw_pdfjs_source_mask(page, annotation)

        self.assertEqual(len(page.draw_rect_calls), 1)
        rect, _kwargs = page.draw_rect_calls[0]
        self.assertLessEqual(rect.y1, 141.2 - self.module.PDFJS_SOURCE_MASK_NEIGHBOR_GAP_PTS + 0.001)

    def test_pdfjs_moved_source_mask_ignores_other_moved_source_rects(self):
        page = self.FakePage()
        page.get_text = lambda mode: {
            "blocks": [{
                "lines": [{
                    "spans": [{
                        "chars": [
                            {"c": "W", "bbox": [56.23, 33.73, 74.0, 57.73]},
                            {"c": "-", "bbox": [74.0, 33.73, 82.0, 57.73]},
                            {"c": "7", "bbox": [82.0, 33.73, 96.21, 57.73]},
                        ],
                    }],
                }, {
                    "spans": [{
                        "chars": [
                            {"c": "(", "bbox": [36.0, 56.59, 39.0, 63.59]},
                            {"c": "R", "bbox": [39.0, 56.59, 45.0, 63.59]},
                            {"c": "e", "bbox": [45.0, 56.59, 51.0, 63.59]},
                            {"c": "v", "bbox": [51.0, 56.59, 57.0, 63.59]},
                            {"c": ".", "bbox": [57.0, 56.59, 60.0, 63.59]},
                            {"c": " ", "bbox": [60.0, 56.59, 62.0, 63.59]},
                            {"c": "D", "bbox": [62.0, 56.59, 68.0, 63.59]},
                            {"c": "e", "bbox": [68.0, 56.59, 74.0, 63.59]},
                            {"c": "c", "bbox": [74.0, 56.59, 80.0, 63.59]},
                            {"c": "e", "bbox": [80.0, 56.59, 86.0, 63.59]},
                            {"c": "m", "bbox": [86.0, 56.59, 94.0, 63.59]},
                            {"c": "b", "bbox": [94.0, 56.59, 100.0, 63.59]},
                            {"c": "e", "bbox": [100.0, 56.59, 106.0, 63.59]},
                            {"c": "r", "bbox": [106.0, 56.59, 112.0, 63.59]},
                            {"c": " ", "bbox": [112.0, 56.59, 114.0, 63.59]},
                            {"c": "2", "bbox": [114.0, 56.59, 120.0, 63.59]},
                            {"c": "0", "bbox": [120.0, 56.59, 126.0, 63.59]},
                            {"c": "2", "bbox": [126.0, 56.59, 132.0, 63.59]},
                            {"c": "4", "bbox": [132.0, 56.59, 138.0, 63.59]},
                            {"c": ")", "bbox": [138.0, 56.59, 141.0, 63.59]},
                        ],
                    }],
                }],
            }]
        }
        annotation = {
            "id": "pdfjs_4188_0_0:4",
            "type": "text",
            "pageIndex": 0,
            "text": "(Rev. December 2024)",
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceMaskX": 36,
            "pdfjsSourceMaskY": 728.3914375,
            "pdfjsSourceMaskW": 69.5056549,
            "pdfjsSourceMaskH": 6.8364375,
            "pdfjsSourceText": "(Rev. December 2024)",
            "__pdfjsMovedSourceRects": [fitz.Rect(56.23, 33.73, 96.21, 57.73)],
        }

        rect = self.module.pdfjs_source_mask_rect(page, annotation)

        expected_source_y0 = 792 - (annotation["pdfjsSourceMaskY"] + annotation["pdfjsSourceMaskH"])
        expected_padding_y = max(
            self.module.PDFJS_SOURCE_MASK_PADDING_Y_PTS,
            annotation["pdfjsSourceMaskH"] * 0.18,
        )
        self.assertAlmostEqual(rect.y0, expected_source_y0 - expected_padding_y, places=5)

        without_ignored = dict(annotation)
        without_ignored.pop("__pdfjsMovedSourceRects")
        clamped_rect = self.module.pdfjs_source_mask_rect(page, without_ignored)
        self.assertGreater(clamped_rect.y0, rect.y0 + 1.0)

    def test_pdfjs_source_mask_does_not_split_around_glyph_like_rule(self):
        page = self.FakePage()
        page.get_drawings = lambda: [{
            "rect": fitz.Rect(54.2765625, 48.09686, 101.366638, 48.64691),
        }]
        annotation = {
            "id": "pdfjs_exact_source_mask_with_glyph_bar",
            "type": "text",
            "pageIndex": 0,
            "text": "SS-5",
            "savedTextOverlay": True,
            "pdfjsSourceX": 54.2765625,
            "pdfjsSourceY": 734.471125,
            "pdfjsSourceW": 47.09007568359375,
            "pdfjsSourceH": 24.0,
            "pdfjsSourceText": "SS-4",
        }

        self.module.draw_pdfjs_source_mask(page, annotation)

        self.assertEqual(len(page.draw_rect_calls), 1)
        rect, _kwargs = page.draw_rect_calls[0]
        self.assertAlmostEqual(rect.y0, 792 - (annotation["pdfjsSourceY"] + annotation["pdfjsSourceH"]), places=5)
        self.assertAlmostEqual(rect.y1, 792 - annotation["pdfjsSourceY"], places=5)

    def test_pdfjs_source_fidelity_text_uses_source_bbox_for_unmoved_export(self):
        page = self.FakePage()
        page.get_text = lambda mode: {
            "blocks": [{
                "lines": [{
                    "spans": [{
                        "text": "8a",
                        "font": "Helvetica-Bold",
                        "size": 10.0,
                        "color": 0,
                        "bbox": [38.0, 660.0, 52.0, 672.0],
                        "origin": [44.0, 669.0],
                    }],
                }],
            }],
        }
        annotation = {
            "id": "pdfjs_unmoved_source_bbox_anchor",
            "type": "text",
            "pageIndex": 0,
            "text": "99",
            "originalText": "8a",
            "pdfX": 44.0,
            "pdfY": 120.0,
            "pdfWidth": 24.0,
            "pdfHeight": 12.0,
            "fontFamily": "Helvetica",
            "fontSize": 10.0,
            "lineHeight": 10.0,
            "textColor": "#000000",
            "savedTextOverlay": True,
            "pdfjsEditorMode": "source",
            "pdfjsSourceFidelity": True,
            "pdfjsSourceText": "8a",
            "pdfjsSourceX": 30.0,
            "pdfjsSourceY": 120.0,
            "pdfjsSourceW": 14.0,
            "pdfjsSourceH": 12.0,
            "pdfjsSourceFontFamily": "sans-serif",
            "pdfjsSourceFontWeight": "700",
            "pdfjsSourceFontStyle": "normal",
            "pdfjsSourceTransformScaleX": "1",
        }

        self.module.draw_text(page, annotation)

        self.assertEqual(len(page.insert_text_calls), 1)
        insert_point, text, _kwargs = page.insert_text_calls[0]
        self.assertEqual(text, "99")
        self.assertAlmostEqual(insert_point.x, annotation["pdfjsSourceX"], places=5)
        self.assertNotAlmostEqual(insert_point.x, annotation["pdfX"], places=5)

    def test_pdfjs_source_fidelity_uses_editor_width_for_long_unmoved_replacement(self):
        page = self.FakePage()
        page.get_text = lambda mode: {"blocks": []}
        annotation = {
            "id": "pdfjs_unmoved_long_replacement_width",
            "type": "text",
            "pageIndex": 0,
            "text": "If a corporation, name the foreign country (if applicable)",
            "originalText": "8a",
            "pdfX": 44.0,
            "pdfY": 120.0,
            "pdfWidth": 320.0,
            "pdfHeight": 12.0,
            "fontFamily": "Helvetica",
            "fontSize": 8.0,
            "lineHeight": 8.0,
            "textColor": "#000000",
            "savedTextOverlay": True,
            "pdfjsEditorMode": "source",
            "pdfjsSourceFidelity": True,
            "pdfjsSourceText": "8a",
            "pdfjsSourceX": 30.0,
            "pdfjsSourceY": 120.0,
            "pdfjsSourceW": 14.0,
            "pdfjsSourceH": 12.0,
            "pdfjsSourceFontFamily": "sans-serif",
            "pdfjsSourceFontWeight": "400",
            "pdfjsSourceFontStyle": "normal",
            "pdfjsSourceTransformScaleX": "1",
        }

        self.module.draw_text(page, annotation)

        inserted = [call for call in page.insert_text_calls if call[1].strip()]
        self.assertEqual(len(inserted), 1)
        insert_point, text, _kwargs = inserted[0]
        self.assertEqual(text, annotation["text"])
        self.assertAlmostEqual(insert_point.x, annotation["pdfjsSourceX"], places=5)

    def test_pdfjs_explicit_source_mask_is_not_expanded_to_matching_text(self):
        page = self.FakePage()
        annotation = {
            "id": "pdfjs_exact_explicit_mask",
            "type": "text",
            "pageIndex": 0,
            "text": "SS-5",
            "savedTextOverlay": True,
            "pdfjsSourceMaskX": 54.2765625,
            "pdfjsSourceMaskY": 734.471125,
            "pdfjsSourceMaskW": 47.09007568359375,
            "pdfjsSourceMaskH": 24.0,
            "pdfjsSourceText": "SS-4",
        }

        self.module.draw_pdfjs_source_mask(page, annotation)

        self.assertEqual(len(page.draw_rect_calls), 1)
        rect, _kwargs = page.draw_rect_calls[0]
        self.assertAlmostEqual(rect.x0, annotation["pdfjsSourceMaskX"], places=5)
        self.assertAlmostEqual(rect.x1, annotation["pdfjsSourceMaskX"] + annotation["pdfjsSourceMaskW"], places=5)
        self.assertAlmostEqual(rect.y0, 792 - (annotation["pdfjsSourceMaskY"] + annotation["pdfjsSourceMaskH"]), places=5)
        self.assertAlmostEqual(rect.y1, 792 - annotation["pdfjsSourceMaskY"], places=5)

    def test_pdfjs_deleted_source_text_uses_redaction_not_visual_mask_only(self):
        page = self.FakePage()
        annotation = {
            "id": "pdfjs_deleted_pdfjs_4172_0_0:13",
            "type": "text",
            "pdfjsDeleted": True,
            "pdfjsSourceX": 180.309375,
            "pdfjsSourceY": 615.65625,
            "pdfjsSourceW": 74.6729736328125,
            "pdfjsSourceH": 7.978125,
            "pdfjsSourceText": "Degree Was Earned:",
            "text": "",
        }

        self.module.erase_pdfjs_deleted_source_text(page, annotation)

        self.assertEqual(len(page.add_redact_annot_calls), 1)
        self.assertIsNone(page.add_redact_annot_calls[0][1].get("fill"))
        self.assertGreaterEqual(len(page.apply_redactions_calls), 1)
        self.assertEqual(len(page.draw_rect_calls), 0)

    def test_pdfjs_changed_source_text_uses_redaction_before_replacement(self):
        page = self.FakePage()
        annotation = {
            "id": "pdfjs_4173_0_0:2",
            "type": "text",
            "pageIndex": 0,
            "savedTextOverlay": True,
            "pdfjsSourceX": 43.2,
            "pdfjsSourceY": 732.5015625,
            "pdfjsSourceW": 86.73599853515626,
            "pdfjsSourceH": 24.0,
            "pdfjsSourceText": "1040-NR",
            "text": "1030-NR",
            "pdfX": 32.1,
            "pdfY": 761.9016,
            "pdfWidth": 139.536,
            "pdfHeight": 28.2,
            "fontFamily": "Montserrat",
            "fontSize": 18,
            "lineHeight": 21.6,
            "fontWeight": "900",
            "textColor": "#000000",
            "movedTextOverlay": True,
            "styleDirty": True,
            "userForcedRichText": True,
            "pdfjsEditorMode": "rich",
        }

        self.module.draw_text(page, annotation)

        self.assertEqual(len(page.add_redact_annot_calls), 1)
        self.assertIsNone(page.add_redact_annot_calls[0][1].get("fill"))
        self.assertGreaterEqual(len(page.apply_redactions_calls), 1)
        self.assertEqual(len(page.draw_rect_calls), 0)
        self.assertEqual(len(page.insert_text_calls), 1)
        self.assertEqual(page.insert_text_calls[0][1], "1030-NR")

    def test_pdfjs_source_anchor_does_not_shift_to_neighboring_label(self):
        blocks = [{
            "lines": [{
                "spans": [
                    {
                        "text": "Form",
                        "bbox": [36.0, 38.0, 55.0, 50.0],
                        "origin": [36.0, 49.0],
                    },
                    {
                        "text": "SS-4",
                        "bbox": [54.2765625, 20.0, 101.366638, 44.0],
                        "origin": [54.2765625, 43.0],
                    },
                ],
            }],
        }]
        source_rect = fitz.Rect(54.2765625, 33.528875, 101.366638, 57.528875)

        span, _rect = self.module._pdfjs_source_line_anchor_span(blocks, source_rect, "ss-4")

        self.assertIsNotNone(span)
        self.assertEqual(span["text"], "SS-4")
        self.assertAlmostEqual(span["origin"][0], 54.2765625, places=5)

    def test_pdfjs_source_anchor_prefers_exact_span_over_overlapping_revision(self):
        blocks = [{
            "lines": [{
                "spans": [
                    {
                        "text": "Form",
                        "bbox": [36.0, 47.42, 56.23, 54.42],
                        "origin": [36.0, 53.14],
                    },
                    {
                        "text": "W-7",
                        "bbox": [56.23, 33.73, 96.21, 57.73],
                        "origin": [56.23, 53.14],
                    },
                ],
            }, {
                "spans": [{
                    "text": "(Rev. December 2024)",
                    "bbox": [36.0, 56.59, 105.5, 63.59],
                    "origin": [36.0, 62.31],
                }],
            }],
        }]
        source_rect = fitz.Rect(56.2265625, 34.0800, 96.2127136, 57.4969)

        span, _rect = self.module._pdfjs_source_line_anchor_span(blocks, source_rect, "w-7")

        self.assertIsNotNone(span)
        self.assertEqual(span["text"], "W-7")
        self.assertAlmostEqual(span["origin"][0], 56.23, places=5)

    def test_pdfjs_moved_source_line_uses_current_box_metrics(self):
        source_pdf = pathlib.Path(__file__).resolve().parents[2] / "public" / "spiders.pdf"
        annotation = {
            "id": "pdfjs_moved_encounters_alignment",
            "pdfX": 289.1907,
            "pdfY": 381.3000299999999,
            "text": "encounters with spiders especially memorable.",
            "type": "text",
            "color": "#000000",
            "opacity": 1,
            "fontSize": 11.00001,
            "pdfWidth": 228.6234,
            "fontStyle": "normal",
            "pageIndex": 1,
            "pdfHeight": 11.60157,
            "textColor": "#000000",
            "fontFamily": "sans-serif",
            "fontWeight": "400",
            "lineHeight": 11.00001,
            "originalText": "encounters with spiders especially memorable.",
            "pdfjsSourceH": 11.0015625,
            "pdfjsSourceW": 228.02343750000003,
            "pdfjsSourceX": 77.99062500000001,
            "pdfjsSourceY": 554.0999999999999,
            "pdfjsEditorMode": "source",
            "pdfjsSourceText": "encounters with spiders especially memorable.",
            "movedTextOverlay": True,
            "savedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsSourceFontStyle": "normal",
            "pdfjsSourceTextColor": "#000000",
            "pdfjsSourceFontFamily": "sans-serif",
            "pdfjsSourceFontSizePx": "36.6667",
            "pdfjsSourceFontWeight": "400",
            "pdfjsSourceTextWidthPx": "760.0477230910764",
            "pdfjsSourceLineHeightPx": "36.6667px",
            "pdfjsSourceTransform": "matrix(1.00004, 0, 0, 1, 0, 0)",
            "pdfjsSourceTransformOrigin": "0px 0px",
            "pdfjsSourceTransformScaleX": "1.00004",
        }

        with tempfile.TemporaryDirectory() as tmpdir:
            output_pdf = pathlib.Path(tmpdir) / "spiders_alignment.pdf"
            shutil.copyfile(source_pdf, output_pdf)

            self.module.apply_annotations(str(output_pdf), [annotation])

            doc = fitz.open(output_pdf)
            try:
                page = doc[1]
                resilience_rects = page.search_for("resilience, and the enduring power of light.")
                self.assertTrue(resilience_rects)
                moved_rects = [
                    rect
                    for rect in page.search_for("encounters with spiders especially memorable.")
                    if rect.y0 > 350
                ]
                self.assertEqual(len(moved_rects), 1)
                moved_rect = moved_rects[0]

                self.assertLess(abs(moved_rect.x0 - annotation["pdfX"]), 0.05)
                self.assertLess(
                    abs(moved_rect.y0 - resilience_rects[0].y0),
                    0.01,
                    "moved source-backed text must share the target PDF line glyph top",
                )
                self.assertLess(
                    abs(moved_rect.y1 - resilience_rects[0].y1),
                    0.01,
                    "moved source-backed text must share the target PDF line glyph bottom",
                )
            finally:
                doc.close()

    def test_pdfjs_moved_mixed_style_source_line_stays_on_page(self):
        source_pdf = pathlib.Path(__file__).resolve().parents[2] / "public" / "spiders.pdf"
        annotation = {
            "id": "pdfjs_moved_shelob_mixed_style_line",
            "pdfX": 79.19073529411764,
            "pdfY": 276.89475000000004,
            "text": "While Shelob is the most prominent spider in The Lord of the Rings, spiders appear",
            "type": "text",
            "color": "#000000",
            "opacity": 1,
            "fontSize": 11.00001,
            "pdfWidth": 405.15,
            "fontStyle": "normal",
            "pageIndex": 1,
            "pdfHeight": 11.001573529411766,
            "textColor": "#000000",
            "fontFamily": "sans-serif",
            "fontWeight": "400",
            "lineHeight": 11.000007352941177,
            "originalText": "While Shelob is the most prominent spider in The Lord of the Rings, spiders appear",
            "pdfjsSourceH": 11.0015625,
            "pdfjsSourceW": 405.1498168945313,
            "pdfjsSourceX": 77.99062500000001,
            "pdfjsSourceY": 497.0953124999999,
            "pdfjsEditorMode": "source",
            "pdfjsSourceText": "While Shelob is the most prominent spider in The Lord of the Rings, spiders appear",
            "movedTextOverlay": True,
            "savedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsSourceFontStyle": "normal",
            "pdfjsSourceTextColor": "#000000",
            "pdfjsSourceFontFamily": "sans-serif",
            "pdfjsSourceFontSizePx": "36.6667",
            "pdfjsSourceFontWeight": "400",
            "pdfjsSourceTextWidthPx": "1350.3913583397702",
            "pdfjsSourceLineHeightPx": "36.6667px",
            "pdfjsSourceTransform": "matrix(1.00008, 0, 0, 1, 0, 0)",
            "pdfjsSourceTransformOrigin": "0px 0px",
            "pdfjsSourceTransformScaleX": "1.00008",
        }

        with tempfile.TemporaryDirectory() as tmpdir:
            output_pdf = pathlib.Path(tmpdir) / "spiders_mixed_style_line.pdf"
            shutil.copyfile(source_pdf, output_pdf)

            self.module.apply_annotations(str(output_pdf), [annotation])

            doc = fitz.open(output_pdf)
            try:
                page = doc[1]
                rects = [
                    rect
                    for rect in page.search_for(annotation["text"])
                    if rect.y0 > 450
                ]
                self.assertEqual(len(rects), 1)
                moved_rect = rects[0]
                self.assertLessEqual(moved_rect.x1, page.rect.x1 + 0.01)
                self.assertLess(abs(moved_rect.x0 - annotation["pdfX"]), 0.05)

                matching_spans = []
                for block in page.get_text("dict").get("blocks", []):
                    for line in block.get("lines", []) or []:
                        for span in line.get("spans", []) or []:
                            if span.get("text") == annotation["text"]:
                                matching_spans.append(span)
                moved_spans = [span for span in matching_spans if span.get("bbox", [0, 0])[1] > 450]
                self.assertEqual(len(moved_spans), 1)
                self.assertEqual(moved_spans[0].get("font"), "Helvetica")
            finally:
                doc.close()

    def test_pdfjs_moved_source_line_near_right_edge_is_fit_to_page(self):
        source_pdf = pathlib.Path(__file__).resolve().parents[2] / "public" / "spiders.pdf"
        annotation = {
            "id": "pdfjs_moved_shelob_right_edge_guard",
            "pdfX": 500.0,
            "pdfY": 276.89475000000004,
            "text": "While Shelob is the most prominent spider in The Lord of the Rings, spiders appear",
            "type": "text",
            "color": "#000000",
            "opacity": 1,
            "fontSize": 11.00001,
            "pdfWidth": 405.15,
            "fontStyle": "normal",
            "pageIndex": 1,
            "pdfHeight": 11.001573529411766,
            "textColor": "#000000",
            "fontFamily": "sans-serif",
            "fontWeight": "400",
            "lineHeight": 11.000007352941177,
            "originalText": "While Shelob is the most prominent spider in The Lord of the Rings, spiders appear",
            "pdfjsSourceH": 11.0015625,
            "pdfjsSourceW": 405.1498168945313,
            "pdfjsSourceX": 77.99062500000001,
            "pdfjsSourceY": 497.0953124999999,
            "pdfjsEditorMode": "source",
            "pdfjsSourceText": "While Shelob is the most prominent spider in The Lord of the Rings, spiders appear",
            "movedTextOverlay": True,
            "savedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsSourceFontStyle": "normal",
            "pdfjsSourceTextColor": "#000000",
            "pdfjsSourceFontFamily": "sans-serif",
            "pdfjsSourceFontSizePx": "36.6667",
            "pdfjsSourceFontWeight": "400",
            "pdfjsSourceTextWidthPx": "1350.3913583397702",
            "pdfjsSourceLineHeightPx": "36.6667px",
            "pdfjsSourceTransform": "matrix(1.00008, 0, 0, 1, 0, 0)",
            "pdfjsSourceTransformOrigin": "0px 0px",
            "pdfjsSourceTransformScaleX": "1.00008",
        }

        with tempfile.TemporaryDirectory() as tmpdir:
            output_pdf = pathlib.Path(tmpdir) / "spiders_right_edge_guard.pdf"
            shutil.copyfile(source_pdf, output_pdf)

            self.module.apply_annotations(str(output_pdf), [annotation])

            doc = fitz.open(output_pdf)
            try:
                page = doc[1]
                rects = [
                    rect
                    for rect in page.search_for(annotation["text"])
                    if rect.y0 > 450
                ]
                self.assertEqual(len(rects), 1)
                moved_rect = rects[0]
                self.assertGreaterEqual(moved_rect.x0, annotation["pdfX"] - 0.05)
                self.assertLessEqual(
                    moved_rect.x1,
                    page.rect.x1 + 0.01,
                    "moved source-backed PDF.js text must never render past the page edge",
                )
            finally:
                doc.close()

    def test_skip_promoted_source_erase_does_not_paint_background_rect(self):
        annotation = {
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "skipPromotedSourceErase": True,
        }
        page = self.FakePage()
        current_rect = fitz.Rect(100, 100, 180, 124)
        lines = [{"rect": fitz.Rect(100, 100, 180, 124)}]

        self.module.erase_promoted_source_text_region(page, annotation, current_rect, lines)

        self.assertEqual(len(page.draw_rect_calls), 0)
        self.assertEqual(len(page.shape_draw_rect_calls), 0)

    def test_pdfjs_dirty_promoted_mask_prepass_erases_original_source_lines(self):
        annotation = {
            "type": "text",
            "text": "Replacement text",
            "originalText": "Original text",
            "pdfjsSourceText": "Original text",
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "savedTextOverlay": True,
            "userForcedRichText": True,
            "pdfX": 40,
            "pdfY": 690,
            "pdfWidth": 180,
            "pdfHeight": 30,
            "pdfjsSourceX": 40,
            "pdfjsSourceY": 690,
            "pdfjsSourceW": 180,
            "pdfjsSourceH": 30,
            "fontFamily": "Helvetica",
            "fontSize": 10,
            "sourceLineBBoxes": [
                [40, 72, 220, 84],
                [40, 84, 220, 96],
            ],
        }
        page = self.FakePage()

        with (
            patch.object(self.module, "draw_pdfjs_source_mask"),
            patch.object(self.module, "normalize_exact_source_line_layout", return_value=[]),
            patch.object(self.module, "erase_promoted_source_text_region") as erase_source,
        ):
            self.module.draw_text(page, annotation, mask_only=True)

        erase_source.assert_called_once()
        erased_lines = erase_source.call_args.args[3]
        self.assertEqual(
            [list(line["rect"]) for line in erased_lines],
            annotation["sourceLineBBoxes"],
        )

    def test_promoted_source_erase_uses_redaction_when_background_is_transparent(self):
        annotation = {
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "backgroundColor": "transparent",
        }
        page = self.FakePage()
        current_rect = fitz.Rect(100, 100, 180, 124)
        lines = [{"rect": fitz.Rect(110, 102, 170, 118)}]

        self.module.erase_promoted_source_text_region(page, annotation, current_rect, lines)

        self.assertEqual(len(page.draw_rect_calls), 0)
        self.assertEqual(len(page.shape_draw_rect_calls), 0)
        self.assertEqual(len(page.add_redact_annot_calls), 1)
        self.assertEqual(page.add_redact_annot_calls[0][1].get("fill"), None)
        self.assertGreaterEqual(len(page.apply_redactions_calls), 1)

    def test_moved_promoted_source_erase_uses_original_source_mask_not_target(self):
        annotation = {
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "movedTextOverlay": True,
            "backgroundColor": "transparent",
            "pdfjsSourceMaskX": 20,
            "pdfjsSourceMaskY": 700,
            "pdfjsSourceMaskW": 80,
            "pdfjsSourceMaskH": 10,
        }
        page = self.FakePage()
        target_rect = fitz.Rect(100, 100, 180, 124)
        target_lines = [{"rect": fitz.Rect(110, 102, 170, 118)}]

        self.module.erase_promoted_source_text_region(page, annotation, target_rect, target_lines)

        self.assertEqual(len(page.add_redact_annot_calls), 1)
        redact_rect = page.add_redact_annot_calls[0][0]
        self.assertAlmostEqual(redact_rect.x0, 20.0, places=3)
        self.assertAlmostEqual(redact_rect.y0, 82.0, places=3)
        self.assertAlmostEqual(redact_rect.x1, 100.0, places=3)
        self.assertAlmostEqual(redact_rect.y1, 92.0, places=3)

    def test_moved_promoted_source_erase_without_source_mask_does_not_erase_target(self):
        annotation = {
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "movedTextOverlay": True,
            "backgroundColor": "transparent",
        }
        page = self.FakePage()
        target_rect = fitz.Rect(100, 100, 180, 124)
        target_lines = [{"rect": fitz.Rect(110, 102, 170, 118)}]

        self.module.erase_promoted_source_text_region(page, annotation, target_rect, target_lines)

        self.assertEqual(len(page.add_redact_annot_calls), 0)
        self.assertEqual(len(page.draw_rect_calls), 0)
        self.assertEqual(len(page.shape_draw_rect_calls), 0)

    def test_pdfjs_moved_source_span_runs_preserve_superscript_position(self):
        annotation = {
            "type": "text",
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsSourceX": 10,
            "pdfjsSourceY": 20,
            "pdfjsSourceW": 40,
            "pdfjsSourceH": 12,
            "text": "Box3",
            "fontFamily": "Helvetica",
            "fontSize": 10,
            "pdfjsSourceSpanRuns": (
                '[{"text":"Box","leftPx":10,"rightPx":28,"topPx":100,"bottomPx":112,'
                '"fontSizePx":10,"fontFamily":"Helvetica"},'
                '{"text":"3","leftPx":28,"rightPx":33,"topPx":90,"bottomPx":98,'
                '"fontSizePx":6,"fontFamily":"Helvetica"}]'
            ),
        }
        layout = self.module.normalize_pdfjs_source_span_run_layout(
            annotation,
            "Box3",
            fitz.Rect(100, 100, 140, 120),
            10,
        )

        self.assertEqual(len(layout), 1)
        spans = layout[0]["spans"]
        self.assertEqual([span["text"] for span in spans], ["Box", "3"])
        self.assertLess(spans[1]["font_size"], spans[0]["font_size"])
        self.assertLess(spans[1]["baseline_y"], spans[0]["baseline_y"])

    def test_edited_pdfjs_line_never_reuses_stale_hyphen_source_span(self):
        annotation = {
            "id": "pdfjs_4423_0_0:45",
            "type": "text",
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "userSizedTextBox": True,
            "userForcedRichText": True,
            "pdfjsEditorMode": "rich",
            "richTextPromotionReason": "manual-resize",
            "text": "Wherever possible test files avoid to touch more than one aspect of the standard",
            "originalText": "Wherever possible test files avoid to touch more than one aspect of the stan-",
            "pdfjsSourceText": "Wherever possible test files avoid to touch more than one aspect of the stan-",
            "fontFamily": "sans-serif",
            "fontSize": 10,
            "pdfX": 101.7282,
            "pdfY": 209.351,
            "pdfWidth": 384.615,
            "pdfHeight": 14.7,
            "lineHeight": 14.00001,
            "richTextHtml": (
                '<span style="font-family: sans-serif; font-size: 10pt">'
                "Wherever possible test files avoid to touch more than one aspect of the standard"
                "</span>"
            ),
            "pdfjsSourceSpanRunsScale": 3.333333333333333,
            "pdfjsSourceSpanRuns": [
                {
                    "text": "Wherever possible test files avoid to touch more than one aspect of the stan",
                    "leftPx": 339.09375,
                    "rightPx": 1459.2265625,
                    "topPx": 2059.828125,
                    "bottomPx": 2109.21875,
                    "fontSizePx": 33.33333333333333,
                    "fontFamily": "sans-serif",
                },
                {
                    "text": "-",
                    "leftPx": 1459.2265625,
                    "rightPx": 1470.3359375,
                    "topPx": 2059.828125,
                    "bottomPx": 2109.21875,
                    "fontSizePx": 33.33333333333333,
                    "fontFamily": "sans-serif",
                },
            ],
        }
        text = annotation["text"]

        self.assertEqual(
            self.module.normalize_pdfjs_source_span_run_layout(
                annotation,
                text,
                fitz.Rect(101.7282, 617.949, 486.3432, 632.649),
                10,
            ),
            [],
        )

        page = self.FakePage()
        self.module.draw_text(page, annotation, source_masks_already_drawn=True)

        rendered_text = "".join(call[1] for call in page.insert_text_calls)
        self.assertEqual(len(page.insert_text_calls), 1)
        self.assertEqual(rendered_text, text)
        self.assertTrue(rendered_text.endswith("standard"))

    def test_promoted_source_erase_draws_fill_when_custom_background_requested(self):
        annotation = {
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "backgroundColor": "#d9d9d9",
        }
        page = self.FakePage()
        current_rect = fitz.Rect(100, 100, 180, 124)
        lines = [{"rect": fitz.Rect(110, 102, 170, 118)}]

        self.module.erase_promoted_source_text_region(page, annotation, current_rect, lines)

        self.assertEqual(len(page.add_redact_annot_calls), 0)
        self.assertEqual(len(page.apply_redactions_calls), 0)
        self.assertEqual(len(page.shape_draw_rect_calls), 1)

    def test_draw_text_without_rect_uses_editor_left_padding_for_visible_text(self):
        annotation = {
            "text": "This is the world",
            "pdfX": 73.07692307692307,
            "pdfY": 585.207100591716,
            "fontFamily": "Helvetica",
            "fontSize": 16,
            "textAlign": "left",
            "textColor": "#000000",
        }

        page = self.FakePage()

        self.module.draw_text(page, annotation)

        self.assertEqual(len(page.insert_text_calls), 1)
        insert_point, _, _ = page.insert_text_calls[0]
        self.assertAlmostEqual(insert_point.x, annotation["pdfX"] + 6.0, places=3)

    def test_normalize_direct_draw_white_image_bytes_forces_visible_pixels_to_white(self):
        image = Image.new("RGBA", (3, 1))
        image.putdata([
            (64, 64, 64, 48),
            (255, 255, 255, 255),
            (10, 10, 10, 0),
        ])
        encoded = io.BytesIO()
        image.save(encoded, format="PNG")

        normalized = self.module.normalize_direct_draw_white_image_bytes(
            {
                "type": "image",
                "imageToolSource": "direct-draw",
                "drawStrokeColor": "#ffffff",
            },
            encoded.getvalue(),
        )

        with Image.open(io.BytesIO(normalized)) as result:
            rgba = result.convert("RGBA")
            pixel_access = rgba.load()
            pixels = [pixel_access[x, 0] for x in range(rgba.width)]

        self.assertEqual(pixels[0], (255, 255, 255, 48))
        self.assertEqual(pixels[1], (255, 255, 255, 255))
        self.assertEqual(pixels[2], (255, 255, 255, 0))

    def test_normalize_direct_draw_white_image_bytes_preserves_alpha_for_translucent_white_stroke(self):
        image = Image.new("RGBA", (3, 1))
        image.putdata([
            (64, 64, 64, 48),
            (255, 255, 255, 128),
            (10, 10, 10, 0),
        ])
        encoded = io.BytesIO()
        image.save(encoded, format="PNG")

        normalized = self.module.normalize_direct_draw_white_image_bytes(
            {
                "type": "image",
                "imageToolSource": "direct-draw",
                "drawStrokeColor": "#ffffff",
            },
            encoded.getvalue(),
        )

        with Image.open(io.BytesIO(normalized)) as result:
            rgba = result.convert("RGBA")
            pixel_access = rgba.load()
            pixels = [pixel_access[x, 0] for x in range(rgba.width)]

        self.assertEqual(pixels[0], (255, 255, 255, 48))
        self.assertEqual(pixels[1], (255, 255, 255, 128))
        self.assertEqual(pixels[2], (255, 255, 255, 0))

    def test_normalize_direct_draw_white_image_bytes_infers_legacy_white_stroke_without_color_metadata(self):
        image = Image.new("RGBA", (5, 1))
        image.putdata([
            (210, 210, 210, 72),
            (238, 238, 238, 160),
            (255, 255, 255, 255),
            (236, 236, 236, 160),
            (208, 208, 208, 72),
        ])
        encoded = io.BytesIO()
        image.save(encoded, format="PNG")

        normalized = self.module.normalize_direct_draw_white_image_bytes(
            {
                "type": "image",
                "imageToolSource": "direct-draw",
            },
            encoded.getvalue(),
        )

        with Image.open(io.BytesIO(normalized)) as result:
            rgba = result.convert("RGBA")
            pixel_access = rgba.load()
            pixels = [pixel_access[x, 0] for x in range(rgba.width)]

        self.assertEqual(
            pixels,
            [
                (255, 255, 255, 72),
                (255, 255, 255, 160),
                (255, 255, 255, 255),
                (255, 255, 255, 160),
                (255, 255, 255, 72),
            ],
        )

    def test_direct_draw_image_insert_kwargs_uses_white_stream_and_alpha_mask_for_white_stroke(self):
        image = Image.new("RGBA", (3, 1))
        image.putdata([
            (64, 64, 64, 48),
            (255, 255, 255, 255),
            (10, 10, 10, 0),
        ])
        encoded = io.BytesIO()
        image.save(encoded, format="PNG")

        kwargs = self.module.direct_draw_image_insert_kwargs(
            {
                "type": "image",
                "imageToolSource": "direct-draw",
                "drawStrokeColor": "#ffffff",
            },
            encoded.getvalue(),
        )

        self.assertIn("stream", kwargs)
        self.assertIn("mask", kwargs)

        with Image.open(io.BytesIO(kwargs["stream"])) as stream_image:
            rgb = stream_image.convert("RGB")
            stream_pixels = [rgb.getpixel((x, 0)) for x in range(rgb.width)]
        with Image.open(io.BytesIO(kwargs["mask"])) as mask_image:
            mask = mask_image.convert("L")
            mask_pixels = [mask.getpixel((x, 0)) for x in range(mask.width)]

        self.assertEqual(stream_pixels, [(255, 255, 255), (255, 255, 255), (255, 255, 255)])
        self.assertEqual(mask_pixels, [48, 255, 0])

    def test_draw_signature_prefers_direct_draw_vector_over_image_payload(self):
        annotation = {
            "type": "image",
            "imageToolSource": "direct-draw",
            "drawStrokeColor": "#dc2626",
            "dataUrl": "data:image/png;base64,not-used",
            "pageIndex": 0,
            "pdfX": 10,
            "pdfY": 20,
            "pdfWidth": 100,
            "pdfHeight": 50,
            "directDrawVector": {
                "version": 1,
                "width": 50,
                "height": 25,
                "strokes": [{
                    "color": "#dc2626",
                    "opacity": 0.75,
                    "brushSize": 4,
                    "points": [{"x": 5, "y": 5}, {"x": 45, "y": 20}],
                }],
            },
        }
        page = self.FakePage()

        self.module.draw_signature(page, annotation)

        self.assertEqual(len(page.shape_draw_polyline_calls), 1)
        self.assertEqual(len(page.shape_finish_calls), 1)
        finish = page.shape_finish_calls[0]
        self.assertEqual(finish["color"], self.module.hex_to_rgb("#dc2626"))
        self.assertAlmostEqual(finish["stroke_opacity"], 0.75, places=3)
        self.assertEqual(finish["lineCap"], 1)
        self.assertEqual(finish["lineJoin"], 1)
        self.assertGreater(finish["width"], 0)

    def test_direct_draw_eraser_annotation_is_sorted_above_every_other_layer(self):
        annotations = [
            {"type": "shape"},
            {"type": "image", "imageToolSource": "direct-draw", "directDrawTool": "pen"},
            {"type": "text"},
            {"type": "image"},
            {"type": "signature"},
            {"type": "image", "imageToolSource": "direct-draw", "directDrawTool": "eraser"},
        ]

        ordered = sorted(annotations, key=self.module.annotation_layer_order)

        self.assertEqual(ordered[-1]["directDrawTool"], "eraser")
        self.assertEqual(self.module.annotation_layer_order(ordered[-1]), (1000.0, 4))

    def test_direct_draw_manual_z_index_can_move_strokes_around_text(self):
        annotations = [
            {"id": "text", "type": "text", "zIndex": 2},
            {
                "id": "eraser-behind",
                "type": "image",
                "imageToolSource": "direct-draw",
                "directDrawTool": "eraser",
                "zIndex": 1,
            },
            {
                "id": "pen-front",
                "type": "image",
                "imageToolSource": "direct-draw",
                "directDrawTool": "pen",
                "zIndex": 3,
            },
        ]

        ordered = sorted(annotations, key=self.module.annotation_layer_order)

        self.assertEqual([annotation["id"] for annotation in ordered], ["eraser-behind", "text", "pen-front"])

    def test_draw_text_with_manual_heading_break_still_wraps_following_paragraph_to_bounds(self):
        annotation = {
            "text": (
                "What is Lorem Ipsum?\n"
                "Lorem Ipsum is simply dummy text of the printing and typesetting industry. "
                "Lorem Ipsum has been the industry's standard dummy text ever since the 1500s."
            ),
            "pdfX": 18.934911242603548,
            "pdfY": 405.9171597633135,
            "pdfWidth": 290.5325443786982,
            "pdfHeight": 285.20710059171597,
            "fontFamily": "TrebuchetMS",
            "fontSourceName": "TrebuchetMS",
            "fontSize": 13,
            "requestedFontSize": 13,
            "lineHeight": 15.6,
            "textAlign": "left",
            "textColor": "#000000",
            "backgroundColor": "transparent",
            "keepBounds": True,
        }

        page = self.FakePage()

        self.module.draw_text(page, annotation)

        rendered_lines = [call[1] for call in page.insert_text_calls]
        self.assertGreater(len(rendered_lines), 2)
        self.assertEqual(rendered_lines[0], "What is Lorem Ipsum?")
        self.assertTrue(any("Lorem Ipsum is simply dummy text" in line for line in rendered_lines[1:]))

    def test_draw_text_uses_pdf_oblique_variant_for_italic_helvetica(self):
        annotation = {
            "text": "my cool title",
            "pdfX": 73.0,
            "pdfY": 585.0,
            "fontFamily": "Helvetica",
            "fontSize": 16,
            "fontStyle": "italic",
            "textColor": "#000000",
        }

        page = self.FakePage()

        self.module.draw_text(page, annotation)

        self.assertEqual(self.module.resolve_text_fontfile(annotation), None)
        self.assertEqual(len(page.insert_text_calls), 1)
        self.assertEqual(page.insert_text_calls[0][2]["fontname"], "heit")

    def test_dropdown_font_families_resolve_to_supported_export_families(self):
        expectations = {
            "Helvetica": "Helvetica",
            "Verdana": "Verdana",
            "TrebuchetMS": "TrebuchetMS",
            "TimesRoman": "TimesRoman",
            "Georgia": "Georgia",
            "Palatino": "TimesRoman",
            "Garamond": "Garamond",
            "MontserratThin_300wght": "Montserrat",
            "Courier": "Courier",
        }

        for requested_family, normalized_family in expectations.items():
            with self.subTest(font=requested_family):
                annotation = {
                    "text": "font smoke",
                    "fontFamily": requested_family,
                    "fontSize": 14,
                }
                self.assertEqual(
                    self.module.normalize_font_family(requested_family),
                    normalized_family,
                )
                fontfile = self.module.resolve_text_fontfile(annotation)
                if normalized_family in {"Helvetica", "TimesRoman", "Courier"}:
                    self.assertTrue(
                        fontfile is None or pathlib.Path(fontfile).exists()
                    )
                else:
                    self.assertTrue(pathlib.Path(fontfile).exists())

    def test_helvetica_css_font_family_uses_annotation_alias(self):
        self.assertEqual(
            self.module.css_font_family("Helvetica"),
            '"AnnotHelvetica", "Arimo", Arial, sans-serif',
        )

    def test_montserrat_css_font_family_uses_montserrat_alias(self):
        self.assertEqual(
            self.module.css_font_family("MontserratThin_300wght"),
            "Montserrat, Helvetica, Arial, sans-serif",
        )

    def test_montserrat_uses_real_font_file(self):
        annotation = {
            "text": "for investors & friends · May 2017",
            "fontFamily": "MontserratThin_300wght",
            "fontSourceName": "MontserratThin_300wght",
            "fontSize": 13,
            "fontWeight": "300",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("Montserrat-Light.ttf"))

    def test_trebuchet_ms_italic_uses_real_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "TrebuchetMS",
            "fontSize": 16,
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("Verdana-Italic.ttf"))

    def test_trebuchet_ms_bold_italic_uses_real_bold_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "TrebuchetMS",
            "fontSize": 16,
            "fontWeight": "700",
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("Verdana-BoldItalic.ttf"))

    def test_verdana_bold_italic_uses_real_bold_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Verdana",
            "fontSize": 16,
            "fontWeight": "700",
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("Verdana-BoldItalic.ttf"))

    def test_times_roman_italic_uses_real_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "TimesRoman",
            "fontSize": 16,
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("LiberationSerif-Italic.ttf"))

    def test_georgia_italic_uses_real_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Georgia",
            "fontSize": 16,
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("LiberationSerif-Italic.ttf"))

    def test_georgia_bold_italic_uses_real_bold_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Georgia",
            "fontSize": 16,
            "fontWeight": "700",
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("LiberationSerif-BoldItalic.ttf"))

    def test_garamond_italic_uses_real_eb_garamond_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Garamond",
            "fontSize": 16,
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("EBGaramond-Italic.ttf"))

    def test_garamond_bold_italic_uses_real_eb_garamond_bold_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Garamond",
            "fontSize": 16,
            "fontWeight": "700",
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("EBGaramond-BoldItalic.ttf"))

    def test_garamond_bypasses_embedded_font_metadata_and_uses_vendored_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Garamond",
            "fontSourceName": "Garamond",
            "fontSize": 16,
            "__documentId": 1930,
        }

        original_loader = self.module.load_embedded_font_metadata
        try:
            self.module.load_embedded_font_metadata = lambda document_id: {
                "fake-garamond": {
                    "clean_name": "Garamond",
                    "family": "Garamond",
                    "css_weight": "400",
                    "css_style": "normal",
                    "file_path": "/fonts/extracted/fake-garamond.ttf",
                }
            }

            self.assertIsNone(self.module.resolve_embedded_font_entry(annotation))
            fontfile = self.module.resolve_text_fontfile(annotation)
        finally:
            self.module.load_embedded_font_metadata = original_loader

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("EBGaramond-Regular.ttf"))

    def test_montserrat_can_use_embedded_font_metadata(self):
        annotation = {
            "text": "for investors & friends · May 2017",
            "fontFamily": "Montserrat",
            "fontSourceName": "MontserratThin_300wght",
            "fontSize": 13,
            "fontWeight": "300",
            "__documentId": 2518,
        }

        original_loader = self.module.load_embedded_font_metadata
        try:
            self.module.load_embedded_font_metadata = lambda document_id: {
                "fake-montserrat": {
                    "clean_name": "MontserratThin_300wght",
                    "family": "Montserrat",
                    "css_weight": "300",
                    "css_style": "normal",
                    "file_path": "/fonts/extracted/montserrat-thin.ttf",
                }
            }
            original_path_resolver = self.module.embedded_font_public_path_to_absolute
            self.module.embedded_font_public_path_to_absolute = lambda path: str(
                pathlib.Path("python/pdf-editor/fonts/Montserrat-Light.ttf").resolve()
            )

            embedded_entry = self.module.resolve_embedded_font_entry(annotation)
        finally:
            self.module.load_embedded_font_metadata = original_loader
            self.module.embedded_font_public_path_to_absolute = original_path_resolver

        self.assertIsNotNone(embedded_entry)
        self.assertEqual(embedded_entry["clean_name"], "MontserratThin_300wght")

    def test_courier_italic_falls_back_to_pdf_courier_oblique(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Courier",
            "fontSize": 16,
            "fontStyle": "italic",
        }

        self.assertIsNone(self.module.resolve_text_fontfile(annotation))
        self.assertEqual(self.module.resolve_text_fontname(annotation), "coit")

    def test_courier_bold_italic_falls_back_to_pdf_courier_bold_oblique(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Courier",
            "fontSize": 16,
            "fontWeight": "700",
            "fontStyle": "italic",
        }

        self.assertIsNone(self.module.resolve_text_fontfile(annotation))
        self.assertEqual(self.module.resolve_text_fontname(annotation), "cobi")

    def test_palatino_italic_uses_real_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Palatino",
            "fontSize": 16,
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("LiberationSerif-Italic.ttf"))

    def test_palatino_bold_italic_uses_real_bold_italic_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Palatino",
            "fontSize": 16,
            "fontWeight": "700",
            "fontStyle": "italic",
        }

        fontfile = self.module.resolve_text_fontfile(annotation)

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("LiberationSerif-BoldItalic.ttf"))

    def test_palatino_bypasses_embedded_font_metadata_and_uses_vendored_font_file(self):
        annotation = {
            "text": "my cool title",
            "fontFamily": "Palatino",
            "fontSourceName": "Palatino",
            "fontSize": 16,
            "__documentId": 1930,
        }

        original_loader = self.module.load_embedded_font_metadata
        try:
            self.module.load_embedded_font_metadata = lambda document_id: {
                "fake-palatino": {
                    "clean_name": "Palatino",
                    "family": "Palatino",
                    "css_weight": "400",
                    "css_style": "normal",
                    "file_path": "/fonts/extracted/fake-palatino.ttf",
                }
            }

            self.assertIsNone(self.module.resolve_embedded_font_entry(annotation))
            fontfile = self.module.resolve_text_fontfile(annotation)
        finally:
            self.module.load_embedded_font_metadata = original_loader

        self.assertIsNotNone(fontfile)
        self.assertTrue(pathlib.Path(fontfile).exists())
        self.assertTrue(str(fontfile).endswith("LiberationSerif-Regular.ttf"))

    def test_draw_shape_line_uses_saved_endpoints(self):
        annotation = {
            "type": "shape",
            "shapeType": "line",
            "pdfX": 100,
            "pdfY": 200,
            "pdfWidth": 120,
            "pdfHeight": 80,
            "strokeColor": "#7f1d1d",
            "strokeWidth": 2,
            "lineStartX": 0.1,
            "lineStartY": 0.2,
            "lineEndX": 0.9,
            "lineEndY": 0.8,
        }

        page = self.FakePage()

        self.module.draw_shape(page, annotation)

        self.assertEqual(len(page.shape_draw_line_calls), 1)
        start, end = page.shape_draw_line_calls[0]
        rect = self.module.to_rect(page, annotation)

        self.assertAlmostEqual(start.x, rect.x0 + (rect.width * 0.1), places=3)
        self.assertAlmostEqual(start.y, rect.y0 + (rect.height * 0.2), places=3)
        self.assertAlmostEqual(end.x, rect.x0 + (rect.width * 0.9), places=3)
        self.assertAlmostEqual(end.y, rect.y0 + (rect.height * 0.8), places=3)
        self.assertLess(start.x, end.x)
        self.assertLess(start.y, end.y)

    def test_draw_shape_square_uses_full_annotation_bounds(self):
        annotation = {
            "type": "shape",
            "shapeType": "square",
            "pdfX": 32.040514534589285,
            "pdfY": 352.6765862474232,
            "pdfWidth": 544.38569836929,
            "pdfHeight": 48.949172958379506,
            "fillColor": "#935b1b",
            "fillOpacity": 1,
            "strokeColor": "#0f172a",
            "strokeWidth": 1,
            "strokeOpacity": 0,
        }

        page = self.FakePage()

        self.module.draw_shape(page, annotation)

        self.assertEqual(len(page.shape_draw_polyline_calls), 1)
        points = page.shape_draw_polyline_calls[0]
        rect = self.module.to_rect(page, annotation)

        self.assertAlmostEqual(points[0].x, rect.x0, places=3)
        self.assertAlmostEqual(points[0].y, rect.y0, places=3)
        self.assertAlmostEqual(points[1].x, rect.x1, places=3)
        self.assertAlmostEqual(points[1].y, rect.y0, places=3)
        self.assertAlmostEqual(points[2].x, rect.x1, places=3)
        self.assertAlmostEqual(points[2].y, rect.y1, places=3)
        self.assertAlmostEqual(points[3].x, rect.x0, places=3)
        self.assertAlmostEqual(points[3].y, rect.y1, places=3)

    def test_draw_shape_square_snaps_near_page_edges(self):
        annotation = {
            "type": "shape",
            "shapeType": "square",
            "pdfX": 0,
            "pdfY": 699.7752,
            "pdfWidth": 611.097,
            "pdfHeight": 92.2248,
            "fillColor": "#585f5a",
            "fillOpacity": 1,
            "strokeColor": "#0f172a",
            "strokeWidth": 1,
            "strokeOpacity": 0.04,
        }

        page = self.FakePage()

        self.module.draw_shape(page, annotation)

        self.assertEqual(len(page.shape_draw_rect_calls), 1)
        bleed_rect = page.shape_draw_rect_calls[0]
        self.assertAlmostEqual(bleed_rect.x0, 0, places=3)
        self.assertAlmostEqual(bleed_rect.y0, 0, places=3)
        self.assertAlmostEqual(bleed_rect.x1, page.rect.x1, places=3)
        self.assertGreater(bleed_rect.y1, self.module.to_rect(page, annotation).y1)

        self.assertEqual(len(page.shape_draw_polyline_calls), 1)
        points = page.shape_draw_polyline_calls[0]

        self.assertAlmostEqual(points[0].x, 0, places=3)
        self.assertAlmostEqual(points[0].y, 0, places=3)
        self.assertAlmostEqual(points[1].x, page.rect.x1, places=3)
        self.assertAlmostEqual(points[2].x, page.rect.x1, places=3)

    def test_draw_shape_zero_width_keeps_fill_without_stroke(self):
        annotation = {
            "type": "shape",
            "shapeType": "square",
            "pdfX": 40,
            "pdfY": 300,
            "pdfWidth": 120,
            "pdfHeight": 60,
            "fillColor": "#22c55e",
            "fillOpacity": 0.5,
            "strokeColor": "#0f172a",
            "strokeWidth": 0,
        }

        page = self.FakePage()

        self.module.draw_shape(page, annotation)

        self.assertEqual(len(page.shape_finish_calls), 1)
        self.assertIsNone(page.shape_finish_calls[0]["color"])
        self.assertEqual(page.shape_finish_calls[0]["width"], 0)
        self.assertEqual(page.shape_finish_calls[0]["fill"], self.module.hex_to_rgb("#22c55e"))

    def test_draw_shape_zero_width_line_draws_nothing(self):
        annotation = {
            "type": "shape",
            "shapeType": "line",
            "pdfX": 40,
            "pdfY": 300,
            "pdfWidth": 120,
            "pdfHeight": 60,
            "strokeColor": "#0f172a",
            "strokeWidth": 0,
        }

        page = self.FakePage()

        self.module.draw_shape(page, annotation)

        self.assertEqual(len(page.shape_draw_line_calls), 0)
        self.assertEqual(len(page.shape_finish_calls), 0)

    def test_should_preserve_promoted_source_lines_respects_reflow_flag(self):
        annotation = {
            "promotedFromExtraction": True,
            "promotedReflowEnabled": True,
            "sourceLineBBoxes": [
                [0, 0, 100, 12],
                [0, 14, 120, 26],
                [0, 28, 180, 40],
            ],
            "sourceTextLines": [
                "Employment Eligibility Verification",
                "Department of Homeland Security",
                "U.S.Citizenship and Immigration Services",
            ],
        }

        self.assertFalse(
            self.module.should_preserve_promoted_source_lines(
                annotation,
                "Employment Eligibility Verification\nDepartment of Homeland DOGCHENEZ\nU.S.Citizenship and Immigration Services",
            )
        )

    def test_should_preserve_promoted_source_lines_rejects_multiline_text_against_single_source_line(self):
        annotation = {
            "promotedFromExtraction": True,
            "sourceLineBBoxes": [
                [38.09, 109.68, 536.03, 117.35],
            ],
            "sourceTextLines": [
                "ANTI-DISCRIMINATION NOTICE:All employees can choose which acceptable documentation to present for Form I-9. Employers cannot ask",
            ],
        }

        self.assertFalse(
            self.module.should_preserve_promoted_source_lines(
                annotation,
                "ANTI-DISCRIMINATION NOTICE:All employees can choose which acceptable documentation to present for Form I-9. Employers cannot ask\nemp 111111\nSupplement B, Reverification and Rehire. Treating employees differently based on their citizenship, immigration status, or national origin may be illegal.",
            )
        )

    def test_should_use_htmlbox_for_promoted_rich_text_with_multiple_runs(self):
        annotation = {
            "promotedFromExtraction": True,
            "text": "Heading\nBody",
            "richTextHtml": (
                "<span style=\"font-weight:700\">Heading</span><br>"
                "<span>Body</span>"
            ),
        }

        self.assertTrue(
            self.module.should_use_htmlbox_for_text(
                annotation,
                embedded_font_entry={"clean_name": "TimesNewRomanPSMT"},
            )
        )

    def test_promoted_rich_span_layout_maps_saved_runs_to_source_faces(self):
        annotation = {
            "id": "promoted_1_22",
            "promotedFromExtraction": True,
            "preserveSourceTypography": True,
            "text": "5a Mailing address (room, apt., suite no. and street, or P.O. box)",
            "richTextHtml": (
                '<span style="font-weight:700;font-style:normal">5a</span>   '
                '<span style="font-weight:400;font-style:normal">'
                'Mailing address (room, apt., suite no. and street, or P.O. box)'
                '</span>'
            ),
            "fontFamily": "HelveticaNeueLTStd",
            "fontSourceName": "HelveticaNeueLTStd-Bd",
            "fontSize": 8,
            "fontWeight": "700",
            "fontStyle": "normal",
            "textColor": "#000000",
            "sourceSpans": [
                {
                    "text": "5a",
                    "font": "HelveticaNeueLTStd-Bd",
                    "embedded_font_name": "HelveticaNeueLTStd-Bd",
                    "embedded_font_family": "HelveticaNeueLTStd",
                    "font_weight": "700",
                    "font_style": "normal",
                    "font_size": 8,
                },
                {
                    "text": "Mailing address (room, apt., suite no. and street, or P.O. box)",
                    "font": "HelveticaNeueLTStd-Roman",
                    "embedded_font_name": "HelveticaNeueLTStd-Roman",
                    "embedded_font_family": "HelveticaNeueLTStd",
                    "font_weight": "400",
                    "font_style": "normal",
                    "font_size": 8,
                },
            ],
        }

        ops = self.module.parse_rich_text_layout_ops(annotation)
        wrapped = self.module.wrap_rich_text_layout_ops(ops, 1000)
        layout = [{
            "rect": fitz.Rect(0, 0, 1000, 12),
            "rotation": 0,
            "spans": [
                {
                    **run,
                    "rect": fitz.Rect(0, 0, 10, 12),
                    "baseline_x": 0,
                    "baseline_y": 8,
                }
                for run in wrapped[0]
            ],
        }]

        repaired = self.module.apply_source_faces_to_rich_span_layout(annotation, layout)

        self.assertEqual(repaired[0]["spans"][0]["font_source_name"], "HelveticaNeueLTStd-Bd")
        self.assertEqual(repaired[0]["spans"][-1]["font_source_name"], "HelveticaNeueLTStd-Roman")
        self.assertIn("Mailing address", repaired[0]["spans"][-1]["text"])

    def test_drop_preview_side_padding_only_when_padding_causes_single_line_wrap(self):
        font = fitz.Font(fontfile=str(pathlib.Path("python/pdf-editor/fonts/EBGaramond-Regular.ttf")))
        size = 53.25443786982248
        text = "Wolfchenez News"
        rect = fitz.Rect(0, 0, 371.00591715976327, 110.65088757396448)

        self.assertTrue(
            self.module.should_drop_preview_side_padding_for_single_line_text(
                {
                    "text": text,
                    "fontFamily": "Garamond",
                    "fontSize": size,
                },
                text,
                rect,
                font,
                size,
                False,
                False,
            )
        )

    def test_promoted_source_content_insets_match_pdfjs_run_origin(self):
        annotation = {
            "promotedFromExtraction": True,
            "pdfjsSourceSpanRunsScale": "3.333333333333333",
            "pdfjsSourceSpanRuns": (
                '[{"leftPx":359,"topPx":1672.625},'
                '{"leftPx":359,"topPx":1719.21875}]'
            ),
        }
        # Document 4423 promoted_1_10: the resized browser box begins at
        # (105.8499, 495.981), while its captured text starts farther in.
        rect = fitz.Rect(105.8499, 495.981, 540.9159, 546.981)

        left, top = self.module.promoted_source_content_insets(annotation, rect)

        self.assertAlmostEqual(left, 1.8501, places=3)
        self.assertAlmostEqual(top, 5.8065, places=3)

    def test_promoted_source_content_insets_use_browser_24px_cap(self):
        annotation = {
            "promotedFromExtraction": True,
            "pdfjsSourceSpanRunsScale": 2,
            "pdfjsSourceSpanRuns": [{"leftPx":100, "topPx":100}],
        }

        left, top = self.module.promoted_source_content_insets(
            annotation,
            fitz.Rect(0, 0, 200, 100),
        )

        self.assertEqual(left, 12)
        self.assertEqual(top, 12)

    def test_promoted_flowing_textbox_drops_stale_source_run_inset(self):
        annotation = {
            "promotedFromExtraction": True,
            "styleDirty": True,
            "userSizedTextBox": True,
            "userForcedRichText": True,
            "pdfjsEditorMode": "rich",
            "pdfjsSourceSpanRunsScale": 3.333333333333333,
            "pdfjsSourceSpanRuns": [{"leftPx": 359, "topPx": 1672.625}],
        }

        self.assertIsNone(
            self.module.promoted_source_content_insets(
                annotation,
                fitz.Rect(74.6499, 498.981, 520.5159, 549.981),
            )
        )

    def test_pdfjs_source_inline_rich_label_does_not_wrap_on_metric_drift(self):
        annotation = {
            "id": "pdfjs_4184_0_0:23",
            "type": "text",
            "pageIndex": 0,
            "text": "New capital:",
            "originalText": "New capital:",
            "pdfX": 56.6802,
            "pdfY": 212.09137,
            "pdfWidth": 74.4243,
            "pdfHeight": 11.49843,
            "fontFamily": "sans-serif",
            "fontSize": 11.5,
            "fontWeight": "700",
            "fontStyle": "italic",
            "lineHeight": 11.49999,
            "textColor": "#000000",
            "richTextHtml": '<span style="font-style: italic">New capital:</span>',
            "richTextPromotionReason": "font-style",
            "styleDirty": True,
            "userForcedRichText": True,
            "pdfjsEditorMode": "rich",
            "savedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsSourceText": "New capital:",
            "pdfjsSourceX": 56.680125,
            "pdfjsSourceY": 212.0913625,
            "pdfjsSourceW": 74.42415966796875,
            "pdfjsSourceH": 11.4984375,
            "pdfjsSourceTransformScaleX": "1.18851",
            "__documentId": 4184,
        }
        ops = self.module.parse_rich_text_layout_ops(annotation)

        self.assertEqual(
            [[run["text"] for run in line] for line in self.module.wrap_rich_text_layout_ops(ops, annotation["pdfWidth"])],
            [["New "], ["capital:"]],
        )
        self.assertTrue(self.module.should_preserve_pdfjs_source_inline_flow(annotation, annotation["text"]))

        page = self.FakePage()
        self.module.draw_text(page, annotation, source_masks_already_drawn=True)

        self.assertEqual([call[1] for call in page.insert_text_calls], ["New capital:"])

    def test_normalize_exact_source_line_layout_preserves_line_style_metadata(self):
        annotation = {
            "promotedFromExtraction": True,
            "sourceBlockLeft": 100,
            "sourceBlockTop": 20,
            "sourceBlockWidth": 200,
            "sourceLineBBoxes": [
                [120, 20, 280, 35],
                [110, 40, 290, 52],
            ],
            "sourceTextLines": [
                "Employment Eligibility Verification",
                "Department of Homeland DOGCHENEZ",
            ],
            "sourceSpans": [
                {
                    "text": "Employment Eligibility Verification",
                    "bbox": [120, 20, 280, 35],
                    "origin": [120, 32],
                    "font": "TimesNewRomanPS-BoldMT",
                    "fontSize": 14,
                    "fontWeight": "700",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "Department of Homeland Security",
                    "bbox": [110, 40, 290, 52],
                    "origin": [110, 50],
                    "font": "TimesNewRomanPSMT",
                    "fontSize": 11,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "Employment Eligibility Verification\nDepartment of Homeland DOGCHENEZ",
            fitz.Font("helv"),
            11,
            fitz.Rect(100, 20, 300, 60),
        )

        self.assertEqual(len(layout), 2)
        self.assertEqual(layout[0]["align"], 1)
        self.assertAlmostEqual(layout[0]["baseline_x"], 120.0, places=3)
        self.assertEqual(layout[0]["font_weight"], "700")
        self.assertAlmostEqual(layout[0]["font_size"], 14.0, places=3)
        self.assertEqual(layout[1]["align"], 1)
        self.assertAlmostEqual(layout[1]["baseline_x"], 110.0, places=3)
        self.assertEqual(layout[1]["font_source_name"], "TimesNewRomanPSMT")
        self.assertAlmostEqual(layout[1]["font_size"], 11.0, places=3)

    def test_draw_text_using_exact_source_lines_uses_line_specific_font_size(self):
        page = self.FakePage()
        annotation = {
            "fontFamily": "TimesRoman",
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
        }
        lines = [
            {
                "rect": fitz.Rect(120, 20, 280, 35),
                "text": "Employment Eligibility Verification",
                "baseline_x": 120,
                "baseline_y": 32,
                "align": 1,
                "font_family": "TimesNewRomanPS-BoldMT",
                "font_source_name": "TimesNewRomanPS-BoldMT",
                "font_size": 14,
                "font_weight": "700",
                "font_style": "normal",
                "color": "#000000",
                "underline": False,
            },
            {
                "rect": fitz.Rect(110, 40, 290, 52),
                "text": "Department of Homeland DOGCHENEZ",
                "baseline_x": 110,
                "baseline_y": 50,
                "align": 1,
                "font_family": "TimesNewRomanPSMT",
                "font_source_name": "TimesNewRomanPSMT",
                "font_size": 11,
                "font_weight": "400",
                "font_style": "normal",
                "color": "#000000",
                "underline": False,
            },
        ]

        result = self.module.draw_text_using_exact_source_lines(
            page,
            annotation,
            lines,
            fitz.Font("tiro"),
            "tiro",
            11,
            (0.0, 0.0, 0.0),
            1.0,
            0,
            None,
        )

        self.assertTrue(result)
        self.assertEqual(len(page.insert_text_calls), 2)
        self.assertAlmostEqual(page.insert_text_calls[0][0].x, 120.0, places=3)
        self.assertAlmostEqual(page.insert_text_calls[1][0].x, 110.0, places=3)
        self.assertAlmostEqual(page.insert_text_calls[0][2]["fontsize"], 14.0, places=3)
        self.assertAlmostEqual(page.insert_text_calls[1][2]["fontsize"], 11.0, places=3)
        self.assertEqual(
            page.insert_text_calls[0][2]["fontname"],
            self.module.resolve_text_font_resource_name({
                "fontFamily": "TimesNewRomanPS-BoldMT",
                "fontSourceName": "TimesNewRomanPS-BoldMT",
                "fontWeight": "700",
                "fontStyle": "normal",
            }),
        )
        self.assertEqual(
            page.insert_text_calls[1][2]["fontname"],
            self.module.resolve_text_font_resource_name({
                "fontFamily": "TimesNewRomanPSMT",
                "fontSourceName": "TimesNewRomanPSMT",
                "fontWeight": "400",
                "fontStyle": "normal",
            }),
        )

    def test_draw_text_using_exact_source_lines_scales_wide_line_to_fit_source_rect(self):
        page = self.FakePage()
        annotation = {
            "fontFamily": "Helvetica",
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
        }
        line = {
            "rect": fitz.Rect(72, 100, 130, 114),
            "text": "THIS LINE IS TOO WIDE",
            "baseline_x": 72,
            "baseline_y": 111,
            "align": 0,
            "font_family": "Helvetica",
            "font_source_name": "Helvetica",
            "font_size": 12,
            "font_weight": "400",
            "font_style": "normal",
            "color": "#000000",
            "underline": False,
        }

        result = self.module.draw_text_using_exact_source_lines(
            page,
            annotation,
            [line],
            fitz.Font("helv"),
            "helv",
            12,
            (0.0, 0.0, 0.0),
            1.0,
            0,
            None,
        )

        self.assertTrue(result)
        self.assertEqual(len(page.insert_text_calls), 1)
        morph = page.insert_text_calls[0][2]["morph"]
        self.assertIsNotNone(morph)
        self.assertLess(morph[1].a, 1.0)
        self.assertAlmostEqual(morph[0].x, 72.0, places=3)
        self.assertAlmostEqual(morph[0].y, 111.0, places=3)

    def test_draw_text_using_exact_source_lines_preserves_vertical_rotation(self):
        page = self.FakePage()
        annotation = {
            "fontFamily": "Helvetica",
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
        }
        line = {
            "rect": fitz.Rect(80, 120, 96, 210),
            "text": "Type or print clearly.",
            "baseline_x": 93,
            "baseline_y": 208,
            "align": 0,
            "rotation": -90,
            "font_family": "Helvetica",
            "font_source_name": "Helvetica",
            "font_size": 12,
            "font_weight": "400",
            "font_style": "normal",
            "color": "#000000",
            "underline": False,
        }

        result = self.module.draw_text_using_exact_source_lines(
            page,
            annotation,
            [line],
            fitz.Font("helv"),
            "helv",
            12,
            (0.0, 0.0, 0.0),
            1.0,
            0,
            None,
        )

        self.assertTrue(result)
        self.assertEqual(len(page.insert_text_calls), 1)
        morph = page.insert_text_calls[0][2]["morph"]
        self.assertIsNotNone(morph)
        self.assertAlmostEqual(morph[1].a, 0.0, places=3)
        self.assertGreater(morph[1].b, 0.7)
        self.assertLess(morph[1].c, -0.7)
        self.assertAlmostEqual(morph[1].d, 0.0, places=3)

    def test_normalize_exact_source_span_layout_preserves_vertical_rotation(self):
        annotation = {
            "promotedFromExtraction": True,
            "sourceLineBBoxes": [
                [80, 120, 96, 210],
            ],
            "sourceTextLines": [
                "Type or print clearly.",
            ],
            "sourceSpans": [
                {
                    "text": "Type or print clearly.",
                    "bbox": [80, 120, 96, 210],
                    "origin": [93, 208],
                    "font": "Helvetica",
                    "fontSize": 12,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                    "rotation": -90,
                    "direction": [0, -1],
                },
            ],
        }

        layout = self.module.normalize_exact_source_span_layout(
            annotation,
            "Type or print clearly.",
            12,
            fitz.Rect(80, 120, 96, 210),
        )

        self.assertEqual(len(layout), 1)
        self.assertEqual(len(layout[0]["spans"]), 1)
        self.assertEqual(layout[0]["spans"][0]["span_rotation"], -90.0)

    def test_draw_text_using_exact_source_spans_preserves_rotation_and_fits_extent(self):
        page = self.FakePage()
        annotation = {
            "fontFamily": "Helvetica",
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
        }
        layout = [{
            "rect": fitz.Rect(80, 120, 96, 210),
            "rotation": -90,
            "spans": [{
                "text": "Type or print clearly.",
                "rect": fitz.Rect(80, 120, 96, 210),
                "baseline_x": 93,
                "baseline_y": 208,
                "font_family": "Helvetica",
                "font_source_name": "Helvetica",
                "font_size": 12,
                "font_weight": "400",
                "font_style": "normal",
                "color": "#000000",
                "underline": False,
                "span_rotation": -90,
            }],
        }]

        result = self.module.draw_text_using_exact_source_spans(
            page,
            annotation,
            layout,
            1.0,
            None,
        )

        self.assertTrue(result)
        self.assertEqual(len(page.insert_text_calls), 1)
        call = page.insert_text_calls[0]
        self.assertLess(call[2]["fontsize"], 12.0)
        morph = call[2]["morph"]
        self.assertIsNotNone(morph)
        self.assertAlmostEqual(morph[1].a, 0.0, places=3)
        self.assertAlmostEqual(morph[1].b, 1.0, places=3)
        self.assertAlmostEqual(morph[1].c, -1.0, places=3)
        self.assertAlmostEqual(morph[1].d, 0.0, places=3)

    def test_source_mask_matches_operator_boundary_duplicate_to_canonical_text(self):
        source_line = (
            "Fusce efficitur mi ex. Quisque elementum elementum odio, "
            "a accumsan nibh consectetur sed."
        )
        canonical_paragraph = (
            "Fusce efficitur mi ex. Quisque elementum odio, a accumsan nibh consectetur sed.\n"
            "Interdum et malesuada fames ac ante ipsum primis in faucibus."
        )

        self.assertTrue(
            self.module._source_mask_line_matches_source_text(
                source_line,
                canonical_paragraph,
            )
        )

    def test_exact_source_span_layout_rejects_duplicated_boundary_text(self):
        annotation = {
            "sourceBlockLeft": 72,
            "sourceBlockTop": 469,
            "sourceBlockWidth": 470,
            "sourceBlockHeight": 54,
            "sourceLineBBoxes": [[72, 469, 542, 480]],
            "sourceTextLines": [
                "Fusce efficitur mi ex. Quisque elementum odio, a accumsan nibh consectetur sed.",
            ],
            "sourceSpans": [{
                "text": "Fusce efficitur mi ex. Quisque elementum elementum odio, a accumsan nibh consectetur sed.",
                "bbox": [72, 469, 542, 480],
                "origin": [72, 478],
                "font": "Open Sans",
                "fontSize": 10.56,
            }],
        }
        canonical = "Fusce efficitur mi ex. Quisque elementum odio, a accumsan nibh consectetur sed."

        layout = self.module.normalize_exact_source_span_layout(
            annotation,
            canonical,
            10.56,
            fitz.Rect(70, 462, 541, 520),
        )

        self.assertEqual(layout, [])

    def test_resized_moved_paragraph_does_not_reuse_source_run_geometry(self):
        annotation = {
            "movedTextOverlay": True,
            "savedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "pdfWidth": 271.224,
            "pdfHeight": 64.7391,
            "sourceBlockWidth": 470.625,
            "sourceBlockHeight": 24.84,
            "pdfjsSourceSpanRuns": "[{}]",
        }

        self.assertFalse(
            self.module.should_preserve_pdfjs_moved_source_line(
                annotation,
                "A resized paragraph must reflow",
            )
        )

    def test_rich_resize_keeps_user_selected_font_family(self):
        annotation = {
            "promotedFromExtraction": True,
            "preserveSourceTypography": True,
            "fontFamily": "Verdana",
            "fontSourceName": "Verdana",
            "sourceSpans": [{
                "font": "Open Sans",
                "embedded_font_name": "Open Sans",
                "embedded_font_family": "Open Sans",
                "fontSize": 10.56,
                "fontWeight": "400",
                "fontStyle": "normal",
            }],
        }
        layout = [{
            "rect": fitz.Rect(0, 0, 200, 40),
            "spans": [{
                "text": "Resized paragraph",
                "font_family": "Verdana",
                "font_source_name": "Verdana",
                "font_size": 10.6,
                "font_weight": "400",
                "font_style": "normal",
            }],
        }]

        repaired = self.module.apply_source_faces_to_rich_span_layout(annotation, layout)

        self.assertEqual(repaired[0]["spans"][0]["font_family"], "Verdana")
        self.assertEqual(repaired[0]["spans"][0]["font_source_name"], "Verdana")
        self.assertAlmostEqual(repaired[0]["spans"][0]["font_size"], 10.6)

    def test_normalize_exact_source_line_layout_prefers_edited_annotation_color(self):
        annotation = {
            "promotedFromExtraction": True,
            "textColor": "#c45454",
            "sourceBlockLeft": 155.17999267578125,
            "sourceBlockTop": 55.526222229003906,
            "sourceBlockWidth": 309.01568603515625,
            "sourceLineBBoxes": [
                [155.17999267578125, 55.526222229003906, 464.1956787109375, 63.887081146240234],
            ],
            "sourceTextLines": [
                "ADDENDUM CONCERNING RIGHT TO TERMINATE",
            ],
            "sourceSpans": [
                {
                    "text": "ADDENDUM CONCERNING RIGHT TO TERMINATE",
                    "bbox": [155.17999267578125, 54.56551361083984, 460.4667053222656, 65.60551452636719],
                    "origin": [155.17999267578125, 63.719970703125],
                    "font": "Verdana-Bold",
                    "fontSize": 11.039999961853027,
                    "fontWeight": "700",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "ADDENDUM CONCERNING RIGHT TO TERMINATE",
            fitz.Font("helv"),
            11,
            fitz.Rect(166.2721893491124, 52.94674441564941, 480.47337278106504, 69.51479175292752),
        )

        self.assertEqual(len(layout), 1)
        self.assertEqual(layout[0]["color"], "#c45454")
        self.assertEqual(layout[0]["align"], 1)

    def test_normalize_exact_source_line_layout_keeps_center_alignment_after_translation(self):
        annotation = {
            "promotedFromExtraction": True,
            "sourceBlockLeft": 100,
            "sourceBlockTop": 20,
            "sourceBlockWidth": 200,
            "sourceLineBBoxes": [
                [120, 20, 280, 35],
            ],
            "sourceTextLines": [
                "Centered title",
            ],
            "sourceSpans": [
                {
                    "text": "Centered title",
                    "bbox": [120, 20, 280, 35],
                    "origin": [120, 32],
                    "font": "TimesNewRomanPS-BoldMT",
                    "fontSize": 14,
                    "fontWeight": "700",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "Centered title",
            fitz.Font("helv"),
            14,
            fitz.Rect(140, 20, 340, 35),
        )

        self.assertEqual(len(layout), 1)
        self.assertEqual(layout[0]["align"], 1)

    def test_normalize_exact_source_line_layout_prefers_edited_style_over_source_span_style(self):
        annotation = {
            "promotedFromExtraction": True,
            "textColor": "#000000",
            "fontWeight": "700",
            "fontStyle": "italic",
            "underline": True,
            "sourceBlockLeft": 100,
            "sourceBlockTop": 20,
            "sourceBlockWidth": 200,
            "sourceLineBBoxes": [
                [120, 20, 280, 35],
            ],
            "sourceTextLines": [
                "Styled title",
            ],
            "sourceSpans": [
                {
                    "text": "Styled title",
                    "bbox": [120, 20, 280, 35],
                    "origin": [120, 32],
                    "font": "TimesNewRomanPSMT",
                    "fontSize": 12,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "underline": False,
                    "color": "#000000",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "Styled title",
            fitz.Font("helv"),
            12,
            fitz.Rect(120, 20, 280, 35),
        )

        self.assertEqual(len(layout), 1)
        self.assertEqual(layout[0]["font_weight"], "700")
        self.assertEqual(layout[0]["font_style"], "italic")
        self.assertTrue(layout[0]["underline"])

    def test_normalize_exact_source_line_layout_prefers_edited_font_family_over_source_span_font(self):
        annotation = {
            "promotedFromExtraction": True,
            "fontFamily": "TrebuchetMS",
            "fontSourceName": "TrebuchetMS",
            "fontWeight": "700",
            "fontStyle": "italic",
            "sourceBlockLeft": 30,
            "sourceBlockTop": 40,
            "sourceBlockWidth": 220,
            "sourceLineBBoxes": [
                [30, 40, 250, 76],
            ],
            "sourceTextLines": [
                "my cool title",
            ],
            "sourceSpans": [
                {
                    "text": "my cool title",
                    "bbox": [30, 40, 250, 76],
                    "origin": [30, 68],
                    "font": "Cousine-Regular",
                    "fontSize": 36,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#3b72a5",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "my cool title",
            fitz.Font("helv"),
            36,
            fitz.Rect(30, 40, 250, 76),
        )

        self.assertEqual(len(layout), 1)
        self.assertEqual(layout[0]["font_family"], "TrebuchetMS")
        self.assertEqual(layout[0]["font_source_name"], "TrebuchetMS")
        self.assertEqual(layout[0]["font_style"], "italic")

    def test_normalize_exact_source_line_layout_prefers_edited_font_size_over_source_span_size(self):
        annotation = {
            "promotedFromExtraction": True,
            "fontFamily": "Palatino",
            "fontSourceName": "Palatino",
            "fontWeight": "700",
            "fontStyle": "italic",
            "sourceBlockLeft": 30,
            "sourceBlockTop": 40,
            "sourceBlockWidth": 220,
            "sourceLineBBoxes": [
                [30, 40, 250, 76],
            ],
            "sourceTextLines": [
                "my cool title",
            ],
            "sourceSpans": [
                {
                    "text": "my cool title",
                    "bbox": [30, 40, 250, 76],
                    "origin": [30, 68],
                    "font": "SomeOriginalSerif-Regular",
                    "fontSize": 60,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#3b72a5",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "my cool title",
            fitz.Font("helv"),
            80,
            fitz.Rect(30, 40, 250, 76),
        )

        self.assertEqual(len(layout), 1)
        self.assertEqual(layout[0]["font_family"], "Palatino")
        self.assertEqual(layout[0]["font_source_name"], "Palatino")
        self.assertAlmostEqual(layout[0]["font_size"], 80.0, places=3)

    def test_normalize_exact_source_line_layout_uses_leftmost_source_origin_when_span_bbox_is_broken(self):
        annotation = {
            "promotedFromExtraction": True,
            "fontFamily": "TimesNewRomanPSMT",
            "fontSourceName": "TimesNewRomanPSMT",
            "fontWeight": "400",
            "fontStyle": "normal",
            "sourceBlockLeft": 29.947967529296875,
            "sourceBlockTop": 315.726806640625,
            "sourceBlockWidth": 478.4940185546875,
            "sourceLineBBoxes": [
                [29.947967529296875, 315.726806640625, 508.4419860839844, 335.63525390625],
            ],
            "sourceTextLines": [
                "• If you want to file a request for higher-level review, use VA Form 20-0996, Decision Review Request: Higher-Level Review.",
            ],
            "sourceSpans": [
                {
                    "text": ".",
                    "bbox": [29.947967529296875, 315.7542419433594, 508.4419860839844, 335.63525390625],
                    "origin": [499.4419860839844, 322.7030029296875],
                    "font": "TimesNewRomanPSMT",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "•",
                    "bbox": [43.447998046875, 315.726806640625, 52.08799743652344, 324.726806640625],
                    "origin": [43.447998046875, 322.7030029296875],
                    "font": "Symbol",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "If you want to file a request for higher-level review, use VA Form 20-0996,",
                    "bbox": [52.08799743652344, 315.7542419433594, 327.0290222167969, 324.7542419433594],
                    "origin": [52.08799743652344, 322.7030029296875],
                    "font": "TimesNewRomanPSMT",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "Decision Review Request: Higher-Level Review",
                    "bbox": [327.010986328125, 315.78045654296875, 499.4690246582031, 324.78045654296875],
                    "origin": [327.010986328125, 322.7030029296875],
                    "font": "TimesNewRomanPS-ItalicMT",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "italic",
                    "color": "#000000",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "•  If you want to file a request for higher-level review, use VA Form 20-0996, Decision Review Request: Higher-Level Review.",
            fitz.Font("tiro"),
            9,
            fitz.Rect(42.01183431952662, 314.48520710059177, 520.7100591715975, 329.2781065088758),
        )

        self.assertEqual(len(layout), 1)
        self.assertLess(layout[0]["baseline_x"], 60.0)
        self.assertAlmostEqual(layout[0]["baseline_x"], 42.01183431952662, places=3)

    def test_build_dirty_promoted_style_mapped_span_layout_uses_authoritative_single_run_edit_style(self):
        annotation = {
            "text": "THIS FORM SUCKS:",
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "fontFamily": "Verdana",
            "fontSourceName": "Verdana",
            "fontWeight": "400",
            "fontStyle": "normal",
            "fontSize": 9,
            "textColor": "#000000",
            "sourceBlockLeft": 29.947999954223633,
            "sourceBlockTop": 162.17494201660156,
            "sourceBlockWidth": 108.05000114440918,
            "sourceLineBBoxes": [
                [29.947999954223633, 162.17494201660156, 137.9980010986328, 184.17494201660156],
            ],
            "sourceTextLines": [
                "THIS FORM SUCKS:",
            ],
            "sourceSpans": [
                {
                    "text": "When to Use This Form:",
                    "bbox": [29.947999954223633, 162.17494201660156, 137.9980010986328, 184.17494201660156],
                    "origin": [29.947999954223633, 169.80499267578125],
                    "font": "TimesNewRomanPS-BoldMT",
                    "fontSize": 10,
                    "fontWeight": "700",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
            ],
            "richTextHtml": "<span style=\"font-size:15.21px;font-weight:700;font-family:Verdana, Geneva, Arial, sans-serif\">THIS FORM SUCKS:</span>",
        }

        line_layout = self.module.normalize_exact_source_line_layout(
            annotation,
            "THIS FORM SUCKS:",
            fitz.Font("hebo"),
            9,
            fitz.Rect(28.40236686390532, 152.94674556213022, 136.68639053254435, 175.43195266272198),
        )
        dirty_layout = self.module.build_dirty_promoted_style_mapped_span_layout(
            annotation,
            "THIS FORM SUCKS:",
            line_layout,
        )

        self.assertEqual(len(line_layout), 1)
        self.assertEqual(line_layout[0]["font_family"], "Verdana")
        self.assertEqual(line_layout[0]["font_source_name"], "Verdana")
        self.assertEqual(line_layout[0]["font_weight"], "700")
        self.assertEqual(len(dirty_layout), 1)
        self.assertEqual(len(dirty_layout[0]["spans"]), 1)
        self.assertEqual(dirty_layout[0]["spans"][0]["text"], "THIS FORM SUCKS:")
        self.assertEqual(dirty_layout[0]["spans"][0]["font_family"], "Verdana")
        self.assertEqual(dirty_layout[0]["spans"][0]["font_source_name"], "Verdana")
        self.assertEqual(dirty_layout[0]["spans"][0]["font_weight"], "700")

    def test_normalize_exact_source_line_layout_prefers_visible_content_anchor_when_block_left_is_stale(self):
        annotation = {
            "id": "promoted_1_5",
            "text": "•  If you want to file a request for higher-level review, use VA Form 20-0996, Decision Review Request: Higher-Level Review.",
            "fontSize": 9,
            "fontFamily": "Times New Roman",
            "fontSourceName": "TimesNewRomanPSMT",
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "underline": False,
            "sourceBlockLeft": 29.947967529296875,
            "sourceBlockTop": 315.726806640625,
            "sourceBlockWidth": 478.4940185546875,
            "sourceBlockHeight": 19.908447265625,
            "sourceLineBBoxes": [
                [29.947967529296875, 315.726806640625, 508.4419860839844, 335.63525390625],
            ],
            "sourceTextLines": [
                "•  If you want to file a request for higher-level review, use VA Form 20-0996, Decision Review Request: Higher-Level Review.",
            ],
            "sourceSpans": [
                {
                    "text": "•",
                    "bbox": [43.447998046875, 315.726806640625, 52.08799743652344, 324.726806640625],
                    "origin": [43.447998046875, 322.7030029296875],
                    "font": "TimesNewRomanPSMT",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "If you want to file a request for higher-level review, use VA Form 20-0996,",
                    "bbox": [52.08799743652344, 315.7542419433594, 327.0290222167969, 324.7542419433594],
                    "origin": [52.08799743652344, 322.7030029296875],
                    "font": "TimesNewRomanPSMT",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "Decision Review Request: Higher-Level Review",
                    "bbox": [327.010986328125, 315.78045654296875, 499.4690246582031, 324.78045654296875],
                    "origin": [327.010986328125, 322.7030029296875],
                    "font": "TimesNewRomanPS-ItalicMT",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "italic",
                    "color": "#000000",
                },
                {
                    "text": ".",
                    "bbox": [499.4690246582031, 315.8341979980469, 501.7190246582031, 324.8341979980469],
                    "origin": [499.4690246582031, 322.7030029296875],
                    "font": "TimesNewRomanPSMT",
                    "fontSize": 9,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            annotation["text"],
            fitz.Font("tiro"),
            9,
            fitz.Rect(37.869822485207095, 497.0414201183431, 516.5680473372781, 511.83431952662714),
        )

        self.assertEqual(len(layout), 1)
        self.assertAlmostEqual(layout[0]["baseline_x"], 37.869822485207095, places=3)

    def test_normalize_exact_source_line_layout_skips_stale_leading_source_line_for_dirty_promoted_text(self):
        annotation = {
            "id": "promoted_4_26",
            "promotedFromExtraction": True,
            "promotedDirty": True,
            "text": "VETERANS HEALTH ADMINISTRATION (NOTE: If checked, specify in the space provided below, which benefit type you are claiming for VHA. (e.g., Travel/Mileage\nReimbursement, Medical Treatment Reimbursement, Health Care Eligibility, Clothing Allowance, etc.)",
            "fontSize": 7,
            "fontFamily": "ArialMT",
            "fontSourceName": "ArialMT",
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "underline": False,
            "sourceBlockLeft": 49.97999954223633,
            "sourceBlockTop": 209.76365661621097,
            "sourceBlockWidth": 511.9510307312012,
            "sourceBlockHeight": 28.19201660156247,
            "sourceLineBBoxes": [
                [264.6499938964844, 209.76365661621097, 402.3342590332031, 216.76365661621097],
                [49.97999954223633, 223.95567321777344, 561.9310302734375, 231.12698364257812],
                [49.97999954223633, 230.95567321777344, 363.90191650390625, 237.95567321777344],
            ],
            "sourceTextLines": [
                "NATIONAL CEMETERY ADMINISTRATION",
                "VETERANS HEALTH ADMINISTRATION (NOTE: If checked, specify in the space provided below, which benefit type you are claiming for VHA. (e.g., Travel/Mileage",
                "Reimbursement, Medical Treatment Reimbursement, Health Care Eligibility, Clothing Allowance, etc.)",
            ],
            "sourceSpans": [
                {
                    "text": "NATIONAL CEMETERY ADMINISTRATION",
                    "bbox": [264.6499938964844, 209.76365661621097, 402.3342590332031, 216.76365661621097],
                    "origin": [264.6499938964844, 215.09698486328125],
                    "font": "ArialMT",
                    "fontSize": 7,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "VETERANS HEALTH ADMINISTRATION (",
                    "bbox": [49.97999954223633, 223.95567321777344, 183.3860321044922, 230.95567321777344],
                    "origin": [49.97999954223633, 229.28900146484375],
                    "font": "ArialMT",
                    "fontSize": 7,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "NOTE",
                    "bbox": [183.3859710693359, 224.12698364257812, 202.8319091796875, 231.12698364257812],
                    "origin": [183.3859710693359, 229.28900146484375],
                    "font": "Arial-BoldMT",
                    "fontSize": 7,
                    "fontWeight": "700",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": ": If checked, specify in the space provided below, which benefit type you are claiming for VHA. (e.g., Travel/Mileage",
                    "bbox": [202.83197021484375, 223.95567321777344, 561.9310302734375, 230.95567321777344],
                    "origin": [202.83197021484375, 229.28900146484375],
                    "font": "ArialMT",
                    "fontSize": 7,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
                {
                    "text": "Reimbursement, Medical Treatment Reimbursement, Health Care Eligibility, Clothing Allowance, etc.)",
                    "bbox": [49.97999954223633, 230.95567321777344, 363.90191650390625, 237.95567321777344],
                    "origin": [49.97999954223633, 236.28900146484375],
                    "font": "ArialMT",
                    "fontSize": 7,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#000000",
                },
            ],
        }

        layout = self.module.normalize_exact_source_line_layout(
            annotation,
            annotation["text"],
            fitz.Font("helv"),
            7,
            fitz.Rect(49.97999954223633, 223.95567321777344, 561.9310302734375, 237.95567321777344),
        )

        self.assertEqual(len(layout), 2)
        self.assertAlmostEqual(layout[0]["baseline_x"], 49.97999954223633, places=3)
        self.assertAlmostEqual(layout[1]["baseline_x"], 49.97999954223633, places=3)
        self.assertEqual(
            layout[0]["text"],
            "VETERANS HEALTH ADMINISTRATION (NOTE: If checked, specify in the space provided below, which benefit type you are claiming for VHA. (e.g., Travel/Mileage",
        )


if __name__ == "__main__":
    unittest.main()
