#!/usr/bin/env python3

import importlib.util
import pathlib
import sys
import unittest

import fitz


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
