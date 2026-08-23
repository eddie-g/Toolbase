#!/usr/bin/env python3

import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest.mock import patch

import fitz
from fontTools import subset
from fontTools.ttLib import TTFont


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "pdf-editor" / "apply_annotations_direct_new.py"


def load_module():
    module_dir = str(MODULE_PATH.parent)
    if module_dir not in sys.path:
        sys.path.insert(0, module_dir)
    spec = importlib.util.spec_from_file_location("apply_annotations_direct_new", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError(f"Unable to load module from {MODULE_PATH}")
    spec.loader.exec_module(module)
    return module


class ApplyAnnotationsDirectNewPdfjsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = load_module()

    def test_malformed_extracted_font_cmap_is_rejected_instead_of_drawing_boxes(self):
        module = self.module
        font_path = "/tmp/malformed-runtime-extracted-font.ttf"
        module._FONT_CMAP_CACHE.pop(font_path, None)

        with patch("fontTools.ttLib.TTFont", side_effect=AssertionError("corrupt cmap")):
            self.assertFalse(module._embedded_font_covers_text(font_path, "Overview"))

        # The failed validation is cached as unsafe, so later runs cannot
        # silently reinterpret the same corrupt subset as having full coverage.
        self.assertIn(font_path, module._FONT_CMAP_CACHE)
        self.assertIsNone(module._FONT_CMAP_CACHE[font_path])
        self.assertFalse(module._embedded_font_covers_text(font_path, "Overview"))
        module._FONT_CMAP_CACHE.pop(font_path, None)

    def test_mupdf_validates_bundled_font_when_fonttools_is_unavailable(self):
        module = self.module
        font_path = module.FONT_FILE_VARIANTS["Helvetica"]["normal"]
        module._FONT_CMAP_CACHE.pop(font_path, None)

        with patch("fontTools.ttLib.TTFont", side_effect=ImportError("fontTools unavailable")):
            self.assertTrue(module._embedded_font_covers_text(font_path, "Overview®"))

        module._FONT_CMAP_CACHE.pop(font_path, None)

    def test_source_mask_matches_legacy_symbol_registered_marks_to_unicode(self):
        self.assertTrue(self.module._source_mask_line_matches_source_text(
            "display of bookmarks in Adobe\uf8e8 Acrobat\uf8e8 Reader",
            "display of bookmarks in Adobe® Acrobat® Reader",
        ))

    def test_draw_smooth_curve_tool_exports_cubic_bezier_segments(self):
        document = fitz.open()
        try:
            page = document.new_page(width=240, height=200)
            annotation = {
                "type": "image",
                "imageToolSource": "direct-draw",
                "directDrawTool": "smooth-curve",
                "drawStrokeColor": "#2563eb",
                "opacity": 1,
                "directDrawVector": {
                    "version": 1,
                    "width": 180,
                    "height": 100,
                    "strokes": [{
                        "color": "#2563eb",
                        "opacity": 1,
                        "brushSize": 3,
                        "smoothCurve": True,
                        "points": [
                            {"x": 0, "y": 50},
                            {"x": 45, "y": 10},
                            {"x": 125, "y": 85},
                            {"x": 180, "y": 50},
                        ],
                    }],
                },
            }

            drew = self.module.draw_direct_draw_vector_annotation(
                page,
                annotation,
                fitz.Rect(20, 40, 200, 140),
            )

            self.assertTrue(drew)
            drawings = page.get_drawings()
            self.assertEqual(len(drawings), 1)
            operators = [item[0] for item in drawings[0]["items"]]
            self.assertEqual(operators.count("c"), 3)
            self.assertNotIn("l", operators)
            self.assertAlmostEqual(drawings[0]["width"], 3, places=3)
        finally:
            document.close()

    def test_f1040_form_rule_below_source_bbox_is_not_owned_as_underline(self):
        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            page.draw_line(
                fitz.Point(64.8, 612.0),
                fitz.Point(417.85, 612.0),
                color=(0, 0, 0),
                width=1.0,
            )
            annotation = {
                "id": "pdfjs_4505_0_0:115",
                "movedTextOverlay": True,
                "pdfjsSourceHasDrawnUnderline": True,
                "pdfjsSourceMaskX": 64.8,
                "pdfjsSourceMaskY": 181.42218,
                "pdfjsSourceMaskW": 370.152062,
                "pdfjsSourceMaskH": 9.0,
                "pdfjsSourceUnderlineSegments": [
                    {"x0": 64.8, "x1": 302.65, "y": 612.0, "width": 1.0},
                    {"x0": 302.15, "x1": 417.85, "y": 612.0, "width": 1.0},
                ],
            }

            segments = self.module._pdfjs_source_drawn_underline_segments(
                page,
                annotation,
            )

            self.assertEqual(segments, [])
        finally:
            document.close()

    def test_f1040_title_annotation_underline_overrides_unstyled_exact_source_span(self):
        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            title = "Additional Income and Adjustments to Income"
            source_rect = fitz.Rect(65, 32, 451, 56)
            annotation = {
                "id": "pdfjs_5304_0_0:2",
                "type": "text",
                "text": title,
                "fontFamily": "Helvetica",
                "fontSize": 14,
                "textColor": "#7f3f35",
                "underline": True,
                "savedTextOverlay": True,
                "pdfjsSourceText": title,
            }
            exact_source_lines = [{
                "rect": source_rect,
                "rotation": 0,
                "spans": [{
                    "text": title,
                    "rect": source_rect,
                    "baseline_x": 65,
                    "baseline_y": 50,
                    "font_family": "Helvetica",
                    "font_source_name": "Helvetica",
                    "font_size": 14,
                    "font_weight": "400",
                    "font_style": "normal",
                    "color": "#7f3f35",
                    # This is the original PDF style. It must not override the
                    # annotation-level underline applied in the editor.
                    "underline": False,
                }],
            }]

            drew = self.module.draw_text_using_exact_source_spans(
                page,
                annotation,
                exact_source_lines,
                1.0,
                inherit_annotation_underline=True,
            )

            with tempfile.NamedTemporaryFile(suffix=".pdf") as output_file:
                document.save(output_file.name)
                exported = fitz.open(output_file.name)
                try:
                    underline_paths = [
                        drawing
                        for drawing in exported[0].get_drawings()
                        if float(drawing["rect"].width) > 200
                        and float(drawing["rect"].height) < 0.01
                    ]
                finally:
                    exported.close()
            self.assertTrue(drew)
            self.assertEqual(len(underline_paths), 1)
            self.assertAlmostEqual(float(underline_paths[0]["rect"].x0), 65, places=2)
            self.assertGreater(float(underline_paths[0]["rect"].x1), 300)
            self.assertGreater(float(underline_paths[0]["rect"].y0), 50)
        finally:
            document.close()

    def test_current_rich_span_can_remove_inherited_annotation_underline(self):
        document = fitz.open()
        try:
            page = document.new_page(width=240, height=120)
            annotation = {
                "type": "text",
                "text": "partially plain",
                "fontFamily": "Helvetica",
                "fontSize": 12,
                "underline": True,
            }
            lines = [{
                "rect": fitz.Rect(20, 20, 150, 40),
                "spans": [{
                    "text": "partially plain",
                    "rect": fitz.Rect(20, 20, 150, 40),
                    "baseline_x": 20,
                    "baseline_y": 35,
                    "font_family": "Helvetica",
                    "font_size": 12,
                    "underline": False,
                    "underline_is_current_style": True,
                }],
            }]

            self.module.draw_text_using_exact_source_spans(
                page,
                annotation,
                lines,
                1.0,
                inherit_annotation_underline=True,
            )

            self.assertEqual(page.get_drawings(), [])
        finally:
            document.close()

    def test_moved_source_without_underline_hint_does_not_scan_page_rules(self):
        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            page.draw_line(
                fitz.Point(64.8, 608.0),
                fitz.Point(417.85, 608.0),
                color=(0, 0, 0),
                width=1.0,
            )
            annotation = {
                "id": "pdfjs_moved_without_underline_hint",
                "movedTextOverlay": True,
                "pdfjsSourceMaskX": 64.8,
                "pdfjsSourceMaskY": 181.42218,
                "pdfjsSourceMaskW": 370.152062,
                "pdfjsSourceMaskH": 9.0,
            }

            segments = self.module._pdfjs_source_drawn_underline_segments(
                page,
                annotation,
            )

            self.assertEqual(segments, [])
        finally:
            document.close()

    def test_explicit_underline_segments_do_not_require_a_source_baseline(self):
        document = fitz.open()
        try:
            page = document.new_page(width=240, height=200)
            annotation = {
                "id": "pdfjs_explicit_underline_without_text",
                "movedTextOverlay": True,
                "pdfjsSourceMaskX": 20,
                "pdfjsSourceMaskY": 135,
                "pdfjsSourceMaskW": 80,
                "pdfjsSourceMaskH": 15,
                "pdfjsSourceUnderlineSegments": [
                    {"x0": 30, "x1": 90, "y": 62, "width": 0.5},
                ],
            }

            segments = self.module._pdfjs_source_drawn_underline_segments(
                page,
                annotation,
            )

            self.assertEqual(len(segments), 1)
            self.assertEqual(segments[0]["x0"], 30)
            self.assertEqual(segments[0]["x1"], 90)
            self.assertEqual(segments[0]["y"], 62)
        finally:
            document.close()

    def test_real_ss5_underline_hint_rediscovers_owned_partial_stroke(self):
        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            source_text = "Paperwork Reduction Act of 1995"
            page.insert_text(
                fitz.Point(159.26, 354.0),
                source_text,
                fontsize=8,
                fontname="helv",
            )
            page.draw_line(
                fitz.Point(159.26, 355.905),
                fitz.Point(323.132, 355.905),
                color=(0, 0, 0),
                width=0.37,
            )
            annotation = {
                "id": "pdfjs_ss5_real_underline",
                "movedTextOverlay": True,
                "pdfjsSourceHasDrawnUnderline": True,
                "pdfjsSourceMaskX": 18.0,
                "pdfjsSourceMaskY": 434.71,
                "pdfjsSourceMaskW": 577.841,
                "pdfjsSourceMaskH": 10.45,
                "pdfjsSourceText": source_text,
            }

            segments = self.module._pdfjs_source_drawn_underline_segments(
                page,
                annotation,
            )

            self.assertEqual(len(segments), 1)
            self.assertAlmostEqual(segments[0]["x0"], 159.26, places=2)
            self.assertAlmostEqual(segments[0]["x1"], 323.132, places=2)
            self.assertAlmostEqual(segments[0]["y"], 355.905, places=3)
        finally:
            document.close()

    def test_deleted_f1040_name_row_does_not_claim_form_border_as_underline(self):
        target_text = "Name(s) shown on Form 1040, 1040-SR, or 1040-NR"
        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            page.insert_text(
                fitz.Point(36.0, 91.711),
                target_text,
                fontsize=8,
                fontname="helv",
            )
            page.draw_line(
                fitz.Point(35.75, 84.002),
                fitz.Point(489.85, 84.002),
                color=(0, 0, 0),
                width=1.0,
            )
            annotation = {
                "id": "pdfjs_deleted_pdfjs_4506_0_0:12",
                "type": "text",
                "pageIndex": 0,
                "text": "",
                "originalText": target_text,
                "savedTextOverlay": True,
                "pdfjsDeleted": True,
                "pdfjsDeletedAnnotationId": "pdfjs_4506_0_0:12",
                "pdfjsSourceX": 36.0,
                "pdfjsSourceY": 792 - 93.423,
                "pdfjsSourceW": 189.392,
                "pdfjsSourceH": 9.328,
                "pdfjsSourcePageHeight": 792,
                "pdfjsSourceText": target_text,
                "pdfjsAnchorUid": "0:12",
            }

            segments = self.module._pdfjs_source_drawn_underline_segments(
                page,
                annotation,
            )

            self.assertEqual(segments, [])
        finally:
            document.close()

    def test_deleted_single_word_cell_preserves_split_rule_through_draw_text(self):
        target_text = "Name"
        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            page.insert_text(
                fitz.Point(36.0, 91.711),
                target_text,
                fontsize=8,
                fontname="helv",
            )
            source_rect = page.search_for(target_text)[0]
            rule_y = 84.002
            page.draw_line(
                fitz.Point(35.75, rule_y),
                fitz.Point(source_rect.x1, rule_y),
                color=(0, 0, 0),
                width=1.0,
            )
            page.draw_line(
                fitz.Point(source_rect.x1 - 0.5, rule_y),
                fitz.Point(120.0, rule_y),
                color=(0, 0, 0),
                width=1.0,
            )
            annotation = {
                "id": "pdfjs_deleted_single_word_cell",
                "type": "text",
                "pageIndex": 0,
                "text": "",
                "originalText": target_text,
                "savedTextOverlay": True,
                "pdfjsDeleted": True,
                "pdfjsDeletedAnnotationId": "pdfjs_single_word_cell",
                "pdfX": source_rect.x0,
                "pdfY": page.rect.height - source_rect.y1,
                "pdfWidth": source_rect.width,
                "pdfHeight": source_rect.height,
                "fontSize": 8,
                "pdfjsSourceX": source_rect.x0,
                "pdfjsSourceY": page.rect.height - source_rect.y1,
                "pdfjsSourceW": source_rect.width,
                "pdfjsSourceH": source_rect.height,
                "pdfjsSourcePageHeight": page.rect.height,
                "pdfjsSourceText": target_text,
                "pdfjsAnchorUid": "0:single-word",
            }

            self.module.draw_text(page, annotation, mask_only=True)

            self.assertEqual(page.search_for(target_text), [])
            horizontal_drawings = [
                drawing
                for drawing in page.get_drawings()
                if abs(float(drawing["rect"].y1) - float(drawing["rect"].y0)) < 0.1
                and abs(float(drawing["rect"].y0) - rule_y) < 0.1
            ]
            self.assertEqual(len(horizontal_drawings), 2)
            self.assertAlmostEqual(
                float(horizontal_drawings[0]["rect"].x0),
                35.75,
                places=2,
            )
            self.assertAlmostEqual(
                float(horizontal_drawings[0]["rect"].x1),
                float(source_rect.x1),
                places=2,
            )
            self.assertAlmostEqual(
                float(horizontal_drawings[1]["rect"].x1),
                120.0,
                places=2,
            )
        finally:
            document.close()

    def test_moved_source_keeps_serialized_editor_color_over_white_source_color(self):
        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            page.draw_rect(
                fitz.Rect(36, 108, 79.2, 120),
                color=None,
                fill=(0, 0, 0),
                width=0,
            )
            page.insert_text(
                fitz.Point(44.825, 117.139),
                "Part I",
                fontsize=10,
                fontname="helv",
                color=(1, 1, 1),
            )
            source_rect = page.search_for("Part I")[0]
            annotation = {
                "id": "pdfjs_4506_0_0:14",
                "type": "text",
                "pageIndex": 0,
                "text": "Part I",
                "originalText": "Part I",
                "savedTextOverlay": True,
                "movedTextOverlay": True,
                "pdfX": 12,
                "pdfY": 620,
                "pdfWidth": source_rect.width,
                "pdfHeight": source_rect.height,
                "fontSize": 10,
                "fontFamily": "sans-serif",
                "fontWeight": "700",
                "textColor": "#000000",
                "color": "#000000",
                "pdfjsSourceX": source_rect.x0,
                "pdfjsSourceY": page.rect.height - source_rect.y1,
                "pdfjsSourceW": source_rect.width,
                "pdfjsSourceH": source_rect.height,
                "pdfjsSourceText": "Part I",
                "pdfjsAnchorUid": "0:14",
            }

            resolved = self.module.resolve_pdfjs_visible_overlay_typography(
                page,
                annotation,
            )

            self.assertEqual(resolved["textColor"], "#000000")
            self.assertEqual(resolved["color"], "#000000")

            unmoved = dict(annotation)
            unmoved["movedTextOverlay"] = False
            unmoved["pdfX"] = annotation["pdfjsSourceX"]
            unmoved["pdfY"] = annotation["pdfjsSourceY"]
            resolved_unmoved = self.module.resolve_pdfjs_visible_overlay_typography(
                page,
                unmoved,
            )
            self.assertEqual(resolved_unmoved["textColor"], "#ffffff")
        finally:
            document.close()

    def test_moved_legacy_color_only_value_reaches_renderer_as_text_color(self):
        document = fitz.open()
        try:
            page = document.new_page(width=240, height=200)
            page.draw_rect(
                fitz.Rect(20, 20, 80, 40),
                color=None,
                fill=(0, 0, 0),
                width=0,
            )
            page.insert_text(
                fitz.Point(28, 35),
                "Part I",
                fontsize=10,
                fontname="helv",
                color=(1, 1, 1),
            )
            source_rect = page.search_for("Part I")[0]
            annotation = {
                "id": "pdfjs_legacy_color_only",
                "type": "text",
                "pageIndex": 0,
                "text": "Part I",
                "originalText": "Part I",
                "savedTextOverlay": True,
                "movedTextOverlay": True,
                "pdfX": 24,
                "pdfY": 70,
                "pdfWidth": source_rect.width,
                "pdfHeight": source_rect.height,
                "fontSize": 10,
                "fontFamily": "sans-serif",
                "fontWeight": "400",
                "color": "#ff0000",
                "backgroundColor": "transparent",
                "pdfjsSourceX": source_rect.x0,
                "pdfjsSourceY": page.rect.height - source_rect.y1,
                "pdfjsSourceW": source_rect.width,
                "pdfjsSourceH": source_rect.height,
                "pdfjsSourceText": "Part I",
                "pdfjsAnchorUid": "0:legacy-color",
            }

            self.module.draw_text(page, annotation)

            rendered_spans = [
                span
                for block in page.get_text("dict").get("blocks", [])
                for line in block.get("lines", [])
                for span in line.get("spans", [])
                if span.get("text", "").strip() == "Part I"
            ]
            self.assertEqual(len(rendered_spans), 1)
            self.assertEqual(rendered_spans[0]["color"], 0xFF0000)
        finally:
            document.close()

    def test_moved_source_keeps_partial_underline_and_removes_original_stroke(self):
        text = (
            "amended by section 2 of the Paperwork Reduction Act of 1995. "
            "You do not need"
        )
        source_top = 346.84
        source_bottom = 357.29
        source_underline_y = 355.905
        runs = [
            {
                "text": "amended by section 2 of the ",
                "leftPx": 18.0,
                "rightPx": 159.26,
                "topPx": source_top,
                "bottomPx": source_bottom,
                "fontFamily": "Helvetica",
                "fontSizePx": 11,
                "fontWeight": "400",
                "fontStyle": "normal",
                "underline": False,
            },
            {
                "text": "Paperwork Reduction Act of 1995",
                "leftPx": 159.26,
                "rightPx": 323.132,
                "topPx": source_top,
                "bottomPx": source_bottom,
                "fontFamily": "Helvetica",
                "fontSizePx": 11,
                "fontWeight": "400",
                "fontStyle": "normal",
                "underline": True,
            },
            {
                "text": ". You do not need",
                "leftPx": 323.132,
                "rightPx": 595.841,
                "topPx": source_top,
                "bottomPx": source_bottom,
                "fontFamily": "Helvetica",
                "fontSizePx": 11,
                "fontWeight": "400",
                "fontStyle": "normal",
                "underline": False,
            },
        ]
        annotation = {
            "id": "pdfjs_partial_underline",
            "type": "text",
            "pageIndex": 0,
            "text": text,
            "originalText": text,
            "pdfX": 18.0,
            "pdfY": 331.55,
            "pdfWidth": 577.841,
            "pdfHeight": 10.45,
            "fontSize": 11,
            "lineHeight": 13.2,
            "fontFamily": "Helvetica",
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "backgroundColor": "transparent",
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsSourceX": 18.0,
            "pdfjsSourceY": 434.71,
            "pdfjsSourceW": 577.841,
            "pdfjsSourceH": 10.45,
            "pdfjsSourceMaskX": 18.0,
            "pdfjsSourceMaskY": 434.71,
            "pdfjsSourceMaskW": 577.841,
            "pdfjsSourceMaskH": 10.45,
            "pdfjsSourceText": text,
            "pdfjsAnchorUid": "3:20",
            "pdfjsSourceSpanRuns": runs,
            "pdfjsSourceSpanRunsScale": "1",
            # Document 4460 was extracted before precise underline geometry
            # was persisted. The writer must rediscover the owned vector
            # stroke from this legacy boolean hint.
            "pdfjsSourceHasDrawnUnderline": True,
        }

        with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
            document = fitz.open()
            page = document.new_page(width=612, height=792)
            page.insert_text(
                fitz.Point(18, 354.892),
                text,
                fontsize=11,
                fontname="helv",
            )
            page.draw_line(
                fitz.Point(159.26, source_underline_y),
                fitz.Point(323.132, source_underline_y),
                color=(0, 0, 0),
                width=0.37,
            )
            document.save(handle.name)
            document.close()

            self.module.apply_annotations(handle.name, [annotation])

            exported = fitz.open(handle.name)
            exported_page = exported[0]
            horizontal_drawings = [
                drawing
                for drawing in exported_page.get_drawings()
                if abs(float(drawing["rect"].y1) - float(drawing["rect"].y0)) < 0.1
            ]
            self.assertFalse(any(
                abs(float(drawing["rect"].y0) - source_underline_y) < 0.5
                for drawing in horizontal_drawings
            ))
            moved_underlines = [
                drawing
                for drawing in horizontal_drawings
                if 458.0 <= float(drawing["rect"].y0) <= 461.0
            ]
            self.assertEqual(len(moved_underlines), 1)
            self.assertAlmostEqual(float(moved_underlines[0]["rect"].x0), 159.26, places=1)
            self.assertAlmostEqual(float(moved_underlines[0]["rect"].x1), 323.132, places=1)
            self.assertEqual(exported_page.get_text().count("Paperwork Reduction Act of 1995"), 1)
            exported.close()

    def test_deleted_source_without_style_metadata_removes_owned_underline_only(self):
        target_text = (
            "amended by section 2 of the Paperwork Reduction Act of 1995. "
            "You do not need to answer these questions unless we"
        )
        source_underline_y = 355.905
        annotation = {
            "id": "pdfjs_deleted_pdfjs_test_3_3:20",
            "type": "text",
            "pageIndex": 0,
            "text": "",
            "originalText": target_text,
            "pdfX": 17.4,
            "pdfY": 434.46,
            "pdfWidth": 575.17,
            "pdfHeight": 14.07,
            "fontSize": 11,
            "savedTextOverlay": True,
            "pdfjsDeleted": True,
            "pdfjsDeletedAnnotationId": "pdfjs_test_3_3:20",
            "pdfjsSourceX": 17.4,
            "pdfjsSourceY": 434.46,
            "pdfjsSourceW": 575.17,
            "pdfjsSourceH": 14.07,
            "pdfjsSourcePageHeight": 792,
            "pdfjsSourceText": target_text,
            "pdfjsAnchorUid": "3:20",
            "pdfjsSourceOccurrence": "0",
        }

        with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
            document = fitz.open()
            page = document.new_page(width=612, height=792)
            page.insert_text(fitz.Point(18, 340.8), "SURVIVOR 19", fontsize=11, fontname="helv")
            page.insert_text(fitz.Point(18, 354.892), target_text, fontsize=11, fontname="helv")
            page.insert_text(fitz.Point(18, 368.7), "SURVIVOR 21", fontsize=11, fontname="helv")
            page.draw_line(
                fitz.Point(159.26, source_underline_y),
                fitz.Point(323.132, source_underline_y),
                color=(0, 0, 0),
                width=0.37,
            )
            document.save(handle.name)
            document.close()

            self.module.apply_annotations(handle.name, [annotation])

            exported = fitz.open(handle.name)
            exported_page = exported[0]
            exported_text = exported_page.get_text()
            horizontal_drawings = [
                drawing
                for drawing in exported_page.get_drawings()
                if abs(float(drawing["rect"].y1) - float(drawing["rect"].y0)) < 0.1
            ]
            self.assertNotIn(target_text, exported_text)
            self.assertIn("SURVIVOR 19", exported_text)
            self.assertIn("SURVIVOR 21", exported_text)
            self.assertFalse(any(
                abs(float(drawing["rect"].y0) - source_underline_y) < 0.5
                for drawing in horizontal_drawings
            ))
            exported.close()

    def test_legacy_zoom_px_rich_text_restores_breaks_and_per_run_point_sizes(self):
        annotation = {
            "type": "text",
            "text": (
                "Thank you for your order!\n"
                "If you have any questions about your order, you can email us at support@example.com"
            ),
            "fontFamily": "Helvetica",
            "fontSize": 10,
            "lineHeight": 12,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "pdfjsRichTextHtmlScale": "3.333333333333333",
            "richTextHtml": (
                '<span style="font-size:46.6667px;line-height:56px">'
                '<span style="font-size:50px;line-height:60px">Thank you for your order!</span>'
                '</span>'
                '<span style="font-size:30px;line-height:36px">'
                '<span style="font-size:36.6667px;line-height:44px">'
                '<span style="font-size:33.3333px;line-height:40px">'
                'If you have any questions about your order, you can email us at support@example.com'
                '</span></span></span>'
            ),
        }

        ops = self.module.parse_rich_text_layout_ops(annotation)

        self.assertEqual(self.module._rich_text_layout_ops_to_text(ops), annotation["text"])
        text_ops = [op for op in ops if op.get("type") == "text"]
        self.assertEqual(len(text_ops), 2)
        self.assertAlmostEqual(text_ops[0]["font_size"], 15.0, places=3)
        self.assertAlmostEqual(text_ops[0]["line_height"], 18.0, places=3)
        self.assertAlmostEqual(text_ops[1]["font_size"], 10.0, places=3)
        self.assertAlmostEqual(text_ops[1]["line_height"], 12.0, places=3)

    def test_structured_rich_text_runs_preserve_complete_effective_styles(self):
        annotation = {
            "type": "text",
            "text": "Large red\nSmall blue",
            "fontFamily": "Helvetica",
            "fontSize": 10,
            "lineHeight": 12,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "richTextVersion": 2,
            "richTextRuns": [
                {
                    "type": "text",
                    "text": "Large red",
                    "fontFamily": "Verdana",
                    "fontSourceName": "Verdana",
                    "fontSize": 18,
                    "lineHeight": 22,
                    "fontWeight": "700",
                    "fontStyle": "italic",
                    "color": "#cc1122",
                    "underline": True,
                },
                {"type": "break"},
                {
                    "type": "text",
                    "text": "Small blue",
                    "fontFamily": "Courier",
                    "fontSize": 9,
                    "lineHeight": 11,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#2244cc",
                    "underline": False,
                },
            ],
            # Deliberately stale HTML: version-2 runs must be authoritative.
            "richTextHtml": "<span>wrong</span>",
        }

        ops = self.module.parse_rich_text_layout_ops(annotation)

        self.assertEqual(self.module._rich_text_layout_ops_to_text(ops), annotation["text"])
        first, second = [op for op in ops if op.get("type") == "text"]
        self.assertEqual(first["font_family"], "Verdana")
        self.assertEqual(first["font_source_name"], "Verdana")
        self.assertEqual(first["font_size"], 18)
        self.assertEqual(first["line_height"], 22)
        self.assertEqual(first["font_weight"], "700")
        self.assertEqual(first["font_style"], "italic")
        self.assertEqual(first["color"], "#cc1122")
        self.assertTrue(first["underline"])
        self.assertEqual(second["font_family"], "Courier")
        self.assertEqual(second["font_size"], 9)
        self.assertEqual(second["color"], "#2244cc")

    def test_structured_rich_text_runs_repair_boundary_space_without_losing_styles(self):
        annotation = {
            "type": "text",
            "text": (
                "business income deduction (QBID) permanent. In\n"
                "addition, beginning in 2026"
            ),
            "fontFamily": "Helvetica",
            "fontSize": 7,
            "lineHeight": 11.053,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#231f20",
            "richTextVersion": 2,
            "richTextRuns": [
                {
                    "type": "text",
                    "text": "business income deduction (QBID)",
                    "fontWeight": "400",
                    "color": "#231f20",
                },
                {
                    # Contenteditable dropped this styled run's leading space,
                    # while the annotation's plain text remained authoritative.
                    "type": "text",
                    "text": "permanent",
                    "fontWeight": "700",
                    "color": "#231f20",
                },
                {
                    "type": "text",
                    "text": ". In",
                    "fontWeight": "400",
                    "color": "#231f20",
                },
                {"type": "break"},
                {
                    "type": "text",
                    "text": "addition, beginning in 2026",
                    "fontWeight": "400",
                    "color": "#b23857",
                },
            ],
        }

        ops = self.module.parse_rich_text_layout_ops(annotation)

        self.assertEqual(self.module._rich_text_layout_ops_to_text(ops), annotation["text"])
        text_ops = [op for op in ops if op.get("type") == "text"]
        permanent = next(op for op in text_ops if "permanent" in op.get("text", ""))
        addition = next(op for op in text_ops if "addition" in op.get("text", ""))
        self.assertEqual(permanent["text"], "permanent")
        self.assertEqual(permanent["font_weight"], "700")
        self.assertTrue(text_ops[0]["text"].endswith(" "))
        self.assertEqual(addition["color"], "#b23857")

    def test_promoted_1_7_boundary_spaces_stay_outside_emphasized_runs(self):
        annotation = {
            "type": "text",
            "text": "All formalities associated with this process are now finalized.",
            "fontFamily": "PdbpbbLato-Regular",
            "fontSourceName": "PdbpbbLato-Regular",
            "fontSize": 12,
            "lineHeight": 14.4,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "richTextVersion": 2,
            "richTextRuns": [
                {"type": "text", "text": "All formalities", "fontWeight": "400"},
                {"type": "text", "text": "associated", "fontWeight": "700"},
                {"type": "text", "text": "with this", "fontWeight": "400"},
                {"type": "text", "text": "process", "fontStyle": "italic"},
                {"type": "text", "text": "are now", "fontWeight": "400"},
                {"type": "text", "text": "finalized", "underline": True},
                {"type": "text", "text": ".", "fontWeight": "400"},
            ],
        }

        ops = self.module.parse_rich_text_layout_ops(annotation)
        text_ops = [op for op in ops if op.get("type") == "text"]

        self.assertEqual(self.module._rich_text_layout_ops_to_text(ops), annotation["text"])
        self.assertEqual(next(op for op in text_ops if op["text"] == "associated")["font_weight"], "700")
        self.assertEqual(next(op for op in text_ops if op["text"] == "process")["font_style"], "italic")
        finalized = next(op for op in text_ops if op.get("underline"))
        self.assertEqual(finalized["text"], "finalized")
        self.assertFalse(finalized["text"].startswith(" "))
        self.assertTrue(any(op["text"].endswith(" ") and not op.get("underline") for op in text_ops))

    def test_multiline_moved_overlay_ignores_single_line_source_baseline_offset(self):
        lines = [f"Paragraph line {index}" for index in range(1, 10)]
        text = "\n".join(lines)
        annotation = {
            "id": "promoted_multiline_baseline_regression",
            "type": "text",
            "pageIndex": 0,
            "text": text,
            "originalText": text,
            "pdfX": 40,
            "pdfY": 100,
            "pdfWidth": 260,
            "pdfHeight": 116,
            "fontFamily": "Helvetica",
            "fontSourceName": "Helvetica",
            "fontSize": 10,
            "lineHeight": 12,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "textAlign": "left",
            "verticalAlign": "top",
            "opacity": 1,
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsUseSourceTypography": True,
            "pdfjsSourceBaselineOffsetX": 0,
            # This is a valid source-row anchor for one line, but applying it
            # to all nine lines starts the paragraph 68pt down and clips five.
            "pdfjsSourceBaselineOffsetY": 68,
            "pdfjsSourceX": 40,
            "pdfjsSourceY": 110,
            "pdfjsSourceW": 260,
            "pdfjsSourceH": 96,
            "pdfjsSourcePageHeight": 300,
            "pdfjsSourceText": text,
            "pdfjsVisualLines": lines,
            "sourceBlockWidth": 260,
            "sourceBlockHeight": 96,
        }

        document = fitz.open()
        try:
            page = document.new_page(width=340, height=300)
            self.module.draw_text(page, annotation, source_masks_already_drawn=True)
            rendered_text = page.get_text()
            rendered_lines = [
                line
                for block in page.get_text("dict").get("blocks", [])
                for line in block.get("lines", [])
            ]
        finally:
            document.close()

        for line in lines:
            self.assertIn(line, rendered_text)
        self.assertEqual(len(rendered_lines), len(lines))
        self.assertLess(rendered_lines[0]["bbox"][1], 100)

    def test_structured_mixed_paragraph_writes_distinct_pdf_span_styles(self):
        annotation = {
            "id": "pdfjs_rich_mixed_regression",
            "type": "text",
            "pageIndex": 0,
            "text": "Large red green italic\nSmall blue",
            "pdfX": 72,
            "pdfY": 620,
            "pdfWidth": 468,
            "pdfHeight": 80,
            "fontFamily": "Helvetica",
            "fontSize": 10,
            "lineHeight": 12,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "textAlign": "left",
            "verticalAlign": "top",
            "opacity": 1,
            "userCreated": True,
            "userAuthored": True,
            "savedTextOverlay": True,
            "skipPdfjsSourceMask": True,
            "richTextVersion": 2,
            "richTextRuns": [
                {
                    "type": "text", "text": "Large red ",
                    "fontFamily": "Verdana", "fontSize": 18, "lineHeight": 22,
                    "fontWeight": "700", "fontStyle": "normal",
                    "color": "#ff0000", "underline": True,
                },
                {
                    "type": "text", "text": "green italic",
                    "fontFamily": "Verdana", "fontSize": 12, "lineHeight": 16,
                    "fontWeight": "400", "fontStyle": "italic",
                    "color": "#00aa00", "underline": False,
                },
                {"type": "break"},
                {
                    "type": "text", "text": "Small blue",
                    "fontFamily": "Courier", "fontSize": 9, "lineHeight": 11,
                    "fontWeight": "400", "fontStyle": "normal",
                    "color": "#0000ff", "underline": False,
                },
            ],
            "richTextHtml": (
                '<span style="font:700 18pt Verdana;color:#ff0000">Large red </span>'
                '<span style="font:italic 12pt Verdana;color:#00aa00">green italic</span><br>'
                '<span style="font:9pt Courier;color:#0000ff">Small blue</span>'
            ),
        }

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = pathlib.Path(temp_dir) / "mixed.pdf"
            document = fitz.open()
            document.new_page(width=612, height=792)
            document.save(pdf_path)
            document.close()

            self.module.apply_annotations(str(pdf_path), [annotation])

            output = fitz.open(pdf_path)
            try:
                spans = [
                    span
                    for block in output[0].get_text("dict").get("blocks", [])
                    for line in block.get("lines", [])
                    for span in line.get("spans", [])
                    if span.get("text", "").strip()
                ]
                drawings = output[0].get_drawings()
            finally:
                output.close()

        by_text = {span["text"].replace("\u00a0", " ").strip(): span for span in spans}
        self.assertIn("Large red", by_text)
        self.assertIn("green italic", by_text)
        self.assertIn("Small blue", by_text)
        self.assertAlmostEqual(by_text["Large red"]["size"], 18, delta=0.15)
        self.assertAlmostEqual(by_text["green italic"]["size"], 12, delta=0.15)
        self.assertAlmostEqual(by_text["Small blue"]["size"], 9, delta=0.15)
        self.assertEqual(by_text["Large red"]["color"], 0xFF0000)
        self.assertEqual(by_text["green italic"]["color"], 0x00AA00)
        self.assertEqual(by_text["Small blue"]["color"], 0x0000FF)
        self.assertLess(by_text["Large red"]["bbox"][1], by_text["Small blue"]["bbox"][1])
        self.assertTrue(any(drawing.get("color") == (1.0, 0.0, 0.0) for drawing in drawings))

    def test_structured_hyperlink_exports_blue_underlined_clickable_uri(self):
        annotation = {
            "id": "pdfjs_rich_hyperlink_regression",
            "type": "text",
            "pageIndex": 0,
            "text": "Visit OpenAI today",
            "pdfX": 72,
            "pdfY": 700,
            "pdfWidth": 240,
            "pdfHeight": 32,
            "fontFamily": "Helvetica",
            "fontSize": 12,
            "lineHeight": 15,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "textAlign": "left",
            "verticalAlign": "top",
            "opacity": 1,
            "userCreated": True,
            "userAuthored": True,
            "savedTextOverlay": True,
            "skipPdfjsSourceMask": True,
            "pdfjsRemovedLinkRects": [
                {"x": 100, "y": 712, "w": 72, "h": 20},
            ],
            "richTextVersion": 2,
            "richTextRuns": [
                {"type": "text", "text": "Visit ", "color": "#000000", "underline": False},
                {
                    "type": "text",
                    "text": "OpenAI",
                    "color": "#0563c1",
                    "underline": True,
                    "linkUrl": "https://openai.com/",
                },
                {"type": "text", "text": " today", "color": "#000000", "underline": False},
            ],
        }

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = pathlib.Path(temp_dir) / "hyperlink.pdf"
            document = fitz.open()
            page = document.new_page(width=612, height=792)
            page.insert_link({
                "kind": fitz.LINK_URI,
                "from": fitz.Rect(100, 60, 172, 80),
                "uri": "https://old.example/",
            })
            document.save(pdf_path)
            document.close()

            self.module.apply_annotations(str(pdf_path), [annotation])

            output = fitz.open(pdf_path)
            try:
                links = output[0].get_links()
                spans = [
                    span
                    for block in output[0].get_text("dict").get("blocks", [])
                    for line in block.get("lines", [])
                    for span in line.get("spans", [])
                    if span.get("text", "").strip()
                ]
            finally:
                output.close()

        self.assertEqual(len(links), 1)
        self.assertEqual(links[0].get("kind"), fitz.LINK_URI)
        self.assertEqual(links[0].get("uri"), "https://openai.com/")
        linked_span = next(span for span in spans if span.get("text") == "OpenAI")
        self.assertEqual(linked_span.get("color"), 0x0563C1)

    def test_removed_hyperlink_deletes_original_pdf_hotspot(self):
        annotation = {
            "id": "pdfjs_removed_hyperlink_regression",
            "type": "text",
            "pageIndex": 0,
            "text": "Visit OpenAI today",
            "pdfX": 72,
            "pdfY": 700,
            "pdfWidth": 240,
            "pdfHeight": 32,
            "fontFamily": "Helvetica",
            "fontSize": 12,
            "lineHeight": 15,
            "textColor": "#000000",
            "userCreated": True,
            "userAuthored": True,
            "savedTextOverlay": True,
            "skipPdfjsSourceMask": True,
            "pdfjsRemovedLinkRects": [
                {"x": 100, "y": 712, "w": 72, "h": 20},
            ],
        }

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = pathlib.Path(temp_dir) / "removed-hyperlink.pdf"
            document = fitz.open()
            page = document.new_page(width=612, height=792)
            page.insert_link({
                "kind": fitz.LINK_URI,
                "from": fitz.Rect(100, 60, 172, 80),
                "uri": "https://old.example/",
            })
            document.save(pdf_path)
            document.close()

            self.module.apply_annotations(str(pdf_path), [annotation])

            output = fitz.open(pdf_path)
            try:
                links = output[0].get_links()
            finally:
                output.close()

        self.assertEqual(links, [])

    def test_hyperlink_destination_rejects_unsafe_protocols(self):
        self.assertEqual(
            self.module.normalize_hyperlink_destination("javascript:alert(1)"),
            "",
        )
        self.assertEqual(
            self.module.normalize_hyperlink_destination("example.com/path"),
            "https://example.com/path",
        )

    def test_promoted_source_block_geometry_is_a_search_safe_source_anchor(self):
        annotation = {
            "id": "promoted_1_8",
            "type": "text",
            "pageIndex": 0,
            "text": "Edited promoted paragraph",
            "originalText": "Original promoted paragraph",
            "savedTextOverlay": True,
            "promotedFromExtraction": True,
            "userAuthored": True,
            # Promoted extraction records predate the per-span pdfjsSource*
            # fields. Their top-origin source block is still authoritative.
            "sourceBlockLeft": 27,
            "sourceBlockTop": 367.35095,
            "sourceBlockWidth": 558.32605,
            "sourceBlockHeight": 113.2,
        }

        document = fitz.open()
        try:
            page = document.new_page(width=612, height=792)
            source_rect = self.module._pdfjs_source_base_rect(page, annotation)
        finally:
            document.close()

        self.assertTrue(self.module.requires_search_safe_source_redaction(annotation))
        self.assertIsNotNone(source_rect)
        self.assertAlmostEqual(source_rect.x0, 27, places=4)
        self.assertAlmostEqual(source_rect.y0, 367.35095, places=4)
        self.assertAlmostEqual(source_rect.x1, 585.32605, places=4)
        self.assertAlmostEqual(source_rect.y1, 480.55095, places=4)

    def test_promoted_1_8_text_edit_replaces_source_without_losing_spacing_or_bold_run(self):
        source_lines = [
            "I understand the information to be released may include my past, present, or future health information including billing, treatment, records related to",
            "behavior and mental health care, alcohol and drug abuse treatment, HIV/AIDS, and genetics. This authorization may be revoked at any time except to",
            "the extent that action has been taken in reliance upon it. Revocation must be made in writing to the provider or facility releasing the information.",
            "The provider or facility will not condition treatment on whether I sign the authorization. I may be charged for copies in accordance with",
            "state law. Information used or disclosed pursuant to this authorization may be subject to re-disclosure by the recipient and may no longer be",
            "protected by federal law. The patient has a right to inspect and receive a copy of the material disclosed. This authorization will not affect any",
            "previous authorizations that I have signed. If I want to change any previous authorizations, I must submit a request in writing to have any previous",
            "authorizations revoked.",
            "This authorization will not expire unless revoked by you or your legal representative or upon notification of death.",
        ]
        source_text = "\n".join(source_lines)
        edited_text = " ".join(source_lines) + " test"
        bold_text = "I may be charged for copies in accordance with state law."
        bold_start = edited_text.index(bold_text)
        bold_end = bold_start + len(bold_text)

        regular_path = pathlib.Path(self.module.FONT_DIR) / "RobotoCondensed-Regular.ttf"
        bold_path = pathlib.Path(self.module.FONT_DIR) / "Barlow-Bold.ttf"
        source_top = 100.0
        source_height = 113.2
        source_width = 558.32605
        page_height = 400.0
        source_line_boxes = [
            [27.0, source_top + (index * 12.0), 27.0 + source_width, source_top + (index * 12.0) + 10.0]
            for index in range(len(source_lines))
        ]
        annotation = {
            "id": "promoted_1_8",
            "type": "text",
            "pageIndex": 0,
            "text": edited_text,
            "originalText": source_text,
            "pdfX": 27.0,
            "pdfY": page_height - (source_top + source_height),
            "pdfWidth": source_width,
            "pdfHeight": source_height,
            "fontFamily": "PromotedCondensed",
            "fontSourceName": "PromotedCondensed-Regular",
            "fontSize": 10.0,
            "lineHeight": 11.8,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#231f20",
            "textAlign": "left",
            "verticalAlign": "top",
            "opacity": 1,
            "savedTextOverlay": True,
            "promotedFromExtraction": True,
            # This is the production failure mode: the browser marked the
            # paragraph user-authored but retained the old false dirty flag.
            "promotedDirty": False,
            "userAuthored": True,
            "pdfjsEditorMode": "rich",
            "promotedReflowEnabled": True,
            "conversionSafeSourceRedaction": True,
            "__documentId": "promoted-1-8-regression",
            "sourceBlockLeft": 27.0,
            "sourceBlockTop": source_top,
            "sourceBlockWidth": source_width,
            "sourceBlockHeight": source_height,
            "sourceLineBBoxes": source_line_boxes,
            "sourceTextLines": source_lines,
            "sourceSpans": [
                {
                    "text": edited_text[:bold_start],
                    "bbox": [27.0, source_top, 27.0 + source_width, source_top + 42.0],
                    "font": "PromotedCondensed-Regular",
                    "embedded_font_name": "PromotedCondensed-Regular",
                    "embedded_font_family": "PromotedCondensed",
                    "fontSize": 10.0,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "hex_color": "#231f20",
                },
                {
                    "text": bold_text,
                    "bbox": [27.0, source_top + 42.0, 280.0, source_top + 64.0],
                    "font": "PromotedCondensed-Bold",
                    "embedded_font_name": "PromotedCondensed-Bold",
                    "embedded_font_family": "PromotedCondensed",
                    "fontSize": 10.0,
                    "fontWeight": "700",
                    "fontStyle": "normal",
                    "hex_color": "#231f20",
                },
                {
                    "text": edited_text[bold_end:],
                    "bbox": [27.0, source_top + 54.0, 27.0 + source_width, source_top + source_height],
                    "font": "PromotedCondensed-Regular",
                    "embedded_font_name": "PromotedCondensed-Regular",
                    "embedded_font_family": "PromotedCondensed",
                    "fontSize": 10.0,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "hex_color": "#231f20",
                },
            ],
            "richTextVersion": 2,
            "richTextRuns": [
                {
                    "type": "text",
                    "text": edited_text[:bold_start],
                    "fontFamily": "PromotedCondensed",
                    "fontSourceName": "PromotedCondensed-Regular",
                    "fontSize": 10.0,
                    "lineHeight": 11.8,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#231f20",
                    "underline": False,
                },
                {
                    "type": "text",
                    "text": bold_text,
                    "fontFamily": "PromotedCondensed",
                    "fontSourceName": "PromotedCondensed-Bold",
                    "fontSize": 10.0,
                    "lineHeight": 11.8,
                    "fontWeight": "700",
                    "fontStyle": "normal",
                    "color": "#231f20",
                    "underline": False,
                },
                {
                    "type": "text",
                    "text": edited_text[bold_end:],
                    "fontFamily": "PromotedCondensed",
                    "fontSourceName": "PromotedCondensed-Regular",
                    "fontSize": 10.0,
                    "lineHeight": 11.8,
                    "fontWeight": "400",
                    "fontStyle": "normal",
                    "color": "#231f20",
                    "underline": False,
                },
            ],
            "richTextHtml": (
                f"<span>{edited_text[:bold_start]}</span>"
                f"<strong>{bold_text}</strong>"
                f"<span>{edited_text[bold_end:]}</span>"
            ),
        }
        metadata = {
            "PromotedCondensed-Regular": {
                "clean_name": "PromotedCondensed-Regular",
                "family": "PromotedCondensed",
                "css_weight": "400",
                "css_style": "normal",
                "css_stretch": "condensed",
                "file_path": str(regular_path),
            },
            "PromotedCondensed-Bold": {
                "clean_name": "PromotedCondensed-Bold",
                "family": "PromotedCondensed",
                "css_weight": "700",
                "css_style": "normal",
                "css_stretch": "condensed",
                "file_path": str(bold_path),
            },
        }
        normalized_annotation = self.module.normalize_annotations_for_pdf_export([annotation])[0]
        rich_ops = self.module.parse_rich_text_layout_ops(normalized_annotation)
        self.assertEqual(
            self.module._rich_text_layout_ops_to_text(rich_ops),
            edited_text,
        )
        self.assertTrue(any(
            op.get("font_weight") == "700" and bold_text in op.get("text", "")
            for op in rich_ops
        ))

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = pathlib.Path(temp_dir) / "promoted-1-8.pdf"
            document = fitz.open()
            try:
                page = document.new_page(width=612, height=page_height)
                page.insert_font(fontname="SourceCondensed", fontfile=str(regular_path))
                for index, line in enumerate(source_lines):
                    page.insert_text(
                        fitz.Point(27.0, source_top + 8.5 + (index * 12.0)),
                        line,
                        fontsize=9.5,
                        fontname="SourceCondensed",
                        color=(0.137, 0.122, 0.125),
                    )
                page.insert_text(
                    fitz.Point(27.0, source_top + source_height + 8.0),
                    "Attention",
                    fontsize=10.0,
                    fontname="helv",
                )
                document.save(pdf_path)
            finally:
                document.close()

            span_layouts = []
            resolved_layout_fonts = []
            original_span_drawer = self.module.draw_text_using_exact_source_spans

            def record_span_layout(page, ann, lines, opacity, morph=None):
                span_layouts.append(lines)
                for line in lines:
                    for span in line.get("spans", []):
                        if span.get("font_weight") == "700":
                            resolved_layout_fonts.append((
                                span.get("font_source_name"),
                                span.get("font_family"),
                                span.get("font_weight"),
                                self.module._resolve_style_run_font(
                                    span.get("text", ""),
                                    span,
                                ).name,
                            ))
                return original_span_drawer(page, ann, lines, opacity, morph)

            with patch.object(
                self.module,
                "load_embedded_font_metadata",
                return_value=metadata,
            ), patch.object(
                self.module,
                "embedded_font_public_path_to_absolute",
                side_effect=lambda value: str(value or ""),
            ), patch.object(
                self.module,
                "draw_text_using_exact_source_spans",
                side_effect=record_span_layout,
            ):
                self.module.apply_annotations(str(pdf_path), [annotation])

            output = fitz.open(pdf_path)
            try:
                page = output[0]
                normalized_output_text = " ".join(page.get_text().split())
                paragraph_occurrences = page.search_for("I understand the information")
                source_tail_occurrences = page.search_for("except to")
                attention_rects = page.search_for("Attention")
                paragraph_spans = [
                    span
                    for block in page.get_text("dict").get("blocks", [])
                    for line in block.get("lines", [])
                    for span in line.get("spans", [])
                    if span.get("text", "").strip()
                    and "Attention" not in span.get("text", "")
                ]
            finally:
                output.close()

        self.assertIn("notification of death. test", normalized_output_text)
        self.assertNotIn("notification of death.test", normalized_output_text)
        self.assertEqual(len(paragraph_occurrences), 1)
        self.assertEqual(len(source_tail_occurrences), 1)
        self.assertEqual(len(attention_rects), 1)
        self.assertTrue(span_layouts, "structured rich-text layout was not rendered")
        rendered_layout_spans = [
            span
            for layout in span_layouts
            for line in layout
            for span in line.get("spans", [])
        ]
        self.assertTrue(any(
            span.get("font_weight") == "700"
            for span in rendered_layout_spans
        ), [(span.get("text"), span.get("font_source_name"), span.get("font_weight")) for span in rendered_layout_spans])
        self.assertTrue(
            any("bold" in entry[-1].lower() for entry in resolved_layout_fonts),
            resolved_layout_fonts,
        )
        self.assertTrue(any(
            "I may be charged" in span.get("text", "")
            and "bold" in span.get("font", "").lower()
            for span in paragraph_spans
        ), [(span.get("text"), span.get("font")) for span in paragraph_spans])
        self.assertTrue(any(
            "understand the information" in span.get("text", "")
            and "regular" in span.get("font", "").lower()
            for span in paragraph_spans
        ), [(span.get("text"), span.get("font")) for span in paragraph_spans])
        self.assertLess(
            max(float(span["bbox"][3]) for span in paragraph_spans),
            float(attention_rects[0].y0),
        )

    def test_preserved_pdfjs_source_runs_use_current_mixed_rich_styles(self):
        text = "Foundation Account: 06039461"
        annotation = {
            "type": "text",
            "text": text,
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "fontFamily": "sans-serif",
            "fontSize": 9,
            "fontWeight": "700",
            "fontStyle": "normal",
            "textColor": "#303038",
            "richTextHtml": (
                '<span style="font-weight:700;color:#303038">Foundation Account: </span>'
                '<span style="font-weight:400;color:#a68930">06039461</span>'
            ),
            "pdfjsSourceSpanRunsScale": 1,
            "pdfjsSourceSpanRuns": [
                {
                    "text": "Foundation Account:",
                    "leftPx": 0,
                    "rightPx": 90,
                    "topPx": 0,
                    "bottomPx": 12,
                    "fontSizePx": 9,
                    "fontFamily": "sans-serif",
                    "fontWeight": "400",
                    "fontStyle": "normal",
                },
                {
                    "text": "06039461",
                    "leftPx": 95,
                    "rightPx": 135,
                    "topPx": 0,
                    "bottomPx": 12,
                    "fontSizePx": 9,
                    "fontFamily": "sans-serif",
                    "fontWeight": "400",
                    "fontStyle": "normal",
                },
            ],
        }

        layout = self.module.normalize_pdfjs_source_span_run_layout(
            annotation,
            text,
            fitz.Rect(20, 20, 155, 32),
            9,
        )

        self.assertEqual(len(layout), 1)
        spans = layout[0]["spans"]
        self.assertEqual([span["text"] for span in spans], ["Foundation Account:", "06039461"])
        self.assertEqual([span["font_weight"] for span in spans], ["700", "400"])
        self.assertEqual([span["color"] for span in spans], ["#303038", "#a68930"])

    def test_single_line_moved_source_runs_use_resolved_destination_baseline(self):
        text = "Prepared by:"
        annotation = {
            "type": "text",
            "text": text,
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "pdfjsSourceBaselineOffsetY": 12.0473,
            "fontFamily": "sans-serif",
            "fontSize": 11.0391,
            "fontWeight": "700",
            "pdfjsSourceSpanRunsScale": 2.533333333333333,
            "pdfjsSourceSpanRuns": [
                {
                    "text": text,
                    "leftPx": 160.5781,
                    "rightPx": 330.6641,
                    "topPx": 476.8125,
                    "bottomPx": 504.7813,
                    "fontSizePx": 27.968,
                    "fontFamily": "g_d0_f3",
                    "pdfjsFontName": "HOEPNL+Arial,Bold",
                    "fontWeight": "700",
                },
            ],
        }
        current_rect = fitz.Rect(140.9518, 162.1780, 209.2752, 176.2938)

        layout = self.module.normalize_pdfjs_source_span_run_layout(
            annotation,
            text,
            current_rect,
            11.0391,
        )

        self.assertEqual(len(layout), 1)
        self.assertEqual(len(layout[0]["spans"]), 1)
        self.assertAlmostEqual(
            layout[0]["spans"][0]["baseline_y"],
            current_rect.y0 + 12.0473,
            places=4,
        )

    def test_style_dirty_rich_overlay_reflows_instead_of_reusing_stale_source_rects(self):
        annotation = {
            "movedTextOverlay": True,
            "savedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "styleDirty": True,
            "userForcedRichText": True,
            "pdfjsEditorMode": "rich",
            "pdfjsSourceSpanRuns": [
                {"text": "Styled"},
                {"text": "text"},
            ],
        }

        self.assertFalse(
            self.module.should_preserve_pdfjs_moved_source_line(
                annotation,
                "Styled text",
            )
        )

    def test_multiline_moved_source_runs_preserve_inline_bold_face(self):
        text = "First source row\ndisplay in Adobe® Acrobat® Reader by clicking Window > Show Bookmarks ."
        annotation = {
            "type": "text",
            "text": text,
            "promotedFromExtraction": True,
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "fontFamily": "Arial",
            "fontSize": 11,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "pdfjsSourceSpanRunsScale": 1,
            "pdfjsSourceSpanRuns": [
                {
                    "text": "First source row",
                    "leftPx": 0,
                    "rightPx": 82,
                    "topPx": 0,
                    "bottomPx": 12,
                    "fontSizePx": 11,
                    "fontFamily": "g_d0_f1",
                    "pdfjsFontName": "HOEPAP+Arial",
                    "fontWeight": "400",
                    "fontStyle": "normal",
                },
                {
                    "text": "display in Adobe® Acrobat® Reader by clicking",
                    "leftPx": 0,
                    "rightPx": 236,
                    "topPx": 13,
                    "bottomPx": 25,
                    "fontSizePx": 11,
                    "fontFamily": "g_d0_f1",
                    "pdfjsFontName": "HOEPAP+Arial",
                    "fontWeight": "400",
                    "fontStyle": "normal",
                },
                {
                    "text": "Window > Show Bookmarks",
                    "leftPx": 240,
                    "rightPx": 365,
                    "topPx": 13,
                    "bottomPx": 25,
                    "fontSizePx": 11,
                    "fontFamily": "g_d0_f3",
                    "pdfjsFontName": "HOEPNL+Arial,Bold",
                    "fontWeight": "700",
                    "fontStyle": "normal",
                },
                {
                    "text": ".",
                    "leftPx": 367,
                    "rightPx": 370,
                    "topPx": 13,
                    "bottomPx": 25,
                    "fontSizePx": 11,
                    "fontFamily": "g_d0_f1",
                    "pdfjsFontName": "HOEPAP+Arial",
                    "fontWeight": "400",
                    "fontStyle": "normal",
                },
            ],
        }

        self.assertTrue(self.module.should_preserve_pdfjs_moved_source_line(annotation, text))
        layout = self.module.normalize_pdfjs_source_span_run_layout(
            annotation,
            text,
            fitz.Rect(20, 20, 390, 45),
            11,
        )

        self.assertEqual(len(layout), 1)
        bold_run = next(
            span for span in layout[0]["spans"]
            if "Show Bookmarks" in span["text"]
        )
        self.assertEqual(bold_run["font_weight"], "700")
        self.assertEqual(bold_run["font_source_name"], "HOEPNL+Arial,Bold")

    def _doc5293_moved_source_header_annotation(self, text, rich_runs=None):
        """Doc 5293 (ss-5.pdf) header overlay `pdfjs_5293_0_0:0`, moved + edited.

        The stored source runs still describe the pristine "Form SS-5 (12-2024) UF"
        line, so the exact-span layout is (correctly) unusable once the token is
        retyped. The rich runs are the only remaining record of the bold token.
        """
        source_runs = [
            {
                "text": "Form", "fontFamily": "Helvetica", "fontWeight": "400",
                "fontStyle": "normal", "pdfjsFontName": "ArialMT",
                "fontSizePx": 25.3333, "leftPx": 49.1875, "rightPx": 108.3045,
                "topPx": 48.4531, "bottomPx": 73.7812,
            },
            {
                "text": "SS-5", "fontFamily": "Helvetica", "fontWeight": "700",
                "fontStyle": "normal", "pdfjsFontName": "Arial-BoldMT",
                "fontSizePx": 25.3333, "leftPx": 115.3281, "rightPx": 171.6444,
                "topPx": 48.4531, "bottomPx": 73.7812,
            },
            {
                "text": "(12-2024) UF", "fontFamily": "Helvetica", "fontWeight": "400",
                "fontStyle": "normal", "pdfjsFontName": "ArialMT",
                "fontSizePx": 25.3333, "leftPx": 178.6875, "rightPx": 332.2325,
                "topPx": 48.4531, "bottomPx": 73.7812,
            },
        ]

        annotation = {
            "id": "pdfjs_5293_0_0:0",
            "type": "text",
            "pageIndex": 0,
            "text": text,
            "originalText": "Form SS-5 (12-2024) UF",
            "sourceTextLines": [text],
            "pdfX": 357.9027,
            "pdfY": 763.2042,
            "pdfWidth": 111.7661,
            "pdfHeight": 13.0263,
            "fontFamily": "ArialMT",
            "fontSourceName": "ArialMT",
            "fontSize": 10,
            "lineHeight": 12,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#000000",
            "textAlign": "left",
            "verticalAlign": "top",
            "opacity": 1,
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "userAuthored": True,
            "forceEmbeddedFont": True,
            "pdfjsForceEmbeddedFont": True,
            "pdfjsSourceFidelity": True,
            "pdfjsEditorMode": "rich",
            "pdfjsSourceText": "Form SS-5 (12-2024) UF",
            "pdfjsSourceX": 18.8240,
            "pdfjsSourceY": 762.2305,
            "pdfjsSourceW": 111.7661,
            "pdfjsSourceH": 12.8157,
            "pdfjsSourceMaskX": 18.8240,
            "pdfjsSourceMaskY": 762.2305,
            "pdfjsSourceMaskW": 111.7661,
            "pdfjsSourceMaskH": 12.8157,
            "pdfjsSourcePageHeight": 792,
            "sourcePageHeight": 792,
            "pdfjsSourceFontFamily": "ArialMT",
            "pdfjsSourceFontSizePx": "25.3333",
            "pdfjsSourceFontWeight": "400",
            "pdfjsSourceFontStyle": "normal",
            "pdfjsSourceTextColor": "#000000",
            "pdfjsSourceSpanRunsScale": "2.533333333333333",
            "pdfjsSourceSpanRuns": json.dumps(source_runs),
            "skipPdfjsSourceMask": True,
        }

        if rich_runs:
            annotation.update({
                "richTextVersion": 2,
                "userForcedRichText": True,
                "richTextPromotionReason": "mixed-source-edit",
                "richTextRuns": [
                    {
                        "type": "text", "text": run_text, "color": "#000000",
                        "fontSize": 10, "lineHeight": 12, "fontStyle": "normal",
                        "underline": False, "fontFamily": family,
                        "fontSourceName": family, "fontWeight": weight,
                    }
                    for run_text, weight, family in rich_runs
                ],
                "richTextHtml": "".join(
                    '<span style="font-family: {family}; font-size: 10pt; line-height: 12pt;'
                    ' font-weight: {weight}; font-style: normal; color: rgb(0, 0, 0);">'
                    "{text}</span>".format(family=family, weight=weight, text=run_text)
                    for run_text, weight, family in rich_runs
                ),
            })

        return annotation

    def _export_header_spans(self, annotation):
        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = pathlib.Path(temp_dir) / "moved_source_header.pdf"
            document = fitz.open()
            document.new_page(width=612, height=792)
            document.save(pdf_path)
            document.close()

            self.module.apply_annotations(str(pdf_path), [annotation])

            output = fitz.open(pdf_path)
            try:
                return [
                    {
                        # Substituted faces re-encode "-" as U+00AD on write.
                        "text": span.get("text", "").replace("\u00ad", "-"),
                        "font": span.get("font", ""),
                        "bold": bool(int(span.get("flags") or 0) & int(fitz.TEXT_FONT_BOLD)),
                        "bbox": span.get("bbox"),
                    }
                    for block in output[0].get_text("dict").get("blocks", [])
                    for line in block.get("lines", [])
                    for span in line.get("spans", [])
                    if span.get("text", "").strip()
                ]
            finally:
                output.close()

    def test_moved_pdfjs_source_line_exports_inline_bold_run(self):
        """Doc 5293: retyping `SS-5` as a bold `13-7` must stay bold in the PDF.

        Moved source lines used to fall through to the uniform single-font
        renderer, which silently flattened every rich run to one face.
        """
        annotation = self._doc5293_moved_source_header_annotation(
            "Form 13-7 (12-2024) UF",
            rich_runs=[
                ("Form", "400", "ArialMT"),
                ("13-7", "700", "Arial-BoldMT"),
                ("(12-2024) UF", "400", "ArialMT"),
            ],
        )

        spans = self._export_header_spans(annotation)
        joined = "".join(span["text"] for span in spans)
        self.assertIn("Form", joined)
        self.assertIn("13-7", joined)
        self.assertIn("(12-2024) UF", joined)

        bold_spans = [span for span in spans if "13-7" in span["text"]]
        self.assertTrue(bold_spans, f"no span carried the retyped token: {spans}")
        for span in bold_spans:
            self.assertTrue(
                span["bold"] or "bold" in span["font"].lower(),
                f"retyped token lost its bold face: {span}",
            )

        for span in spans:
            if "13-7" in span["text"]:
                continue
            self.assertFalse(
                span["bold"] or "bold" in span["font"].lower(),
                f"neighbouring run should stay regular: {span}",
            )

        # The styled runs must still render as one squeezed line, not wrap.
        tops = {round(span["bbox"][1], 1) for span in spans}
        self.assertEqual(len(tops), 1, f"styled runs wrapped onto extra lines: {spans}")

    def test_uniform_moved_pdfjs_source_line_still_exports_as_one_run(self):
        """A moved source line without mixed styles keeps the single-run renderer."""
        annotation = self._doc5293_moved_source_header_annotation(
            "Form 13-7 (12-2024) UF",
            rich_runs=[
                ("Form", "400", "ArialMT"),
                ("13-7", "400", "ArialMT"),
                ("(12-2024) UF", "400", "ArialMT"),
            ],
        )

        spans = self._export_header_spans(annotation)
        self.assertEqual(len(spans), 1, f"uniform moved line was split: {spans}")
        self.assertEqual(spans[0]["text"].strip(), "Form 13-7 (12-2024) UF")
        self.assertFalse(spans[0]["bold"])

    def test_rich_style_boundary_splits_a_preserved_pdfjs_source_run_generically(self):
        text = "AlphaBeta Tail"
        annotation = {
            "type": "text",
            "text": text,
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceFidelity": True,
            "fontFamily": "sans-serif",
            "fontSize": 10,
            "fontWeight": "400",
            "fontStyle": "normal",
            "textColor": "#111111",
            "richTextHtml": (
                '<span style="font-weight:700;color:#112233">Alpha</span>'
                '<span style="font-style:italic;color:#445566">Beta</span> Tail'
            ),
            "pdfjsSourceSpanRunsScale": 1,
            "pdfjsSourceSpanRuns": [
                {
                    "text": "AlphaBeta",
                    "leftPx": 0,
                    "rightPx": 72,
                    "topPx": 0,
                    "bottomPx": 12,
                    "fontSizePx": 10,
                    "fontFamily": "sans-serif",
                    "fontWeight": "400",
                    "fontStyle": "normal",
                },
                {
                    "text": "Tail",
                    "leftPx": 77,
                    "rightPx": 100,
                    "topPx": 0,
                    "bottomPx": 12,
                    "fontSizePx": 10,
                    "fontFamily": "sans-serif",
                    "fontWeight": "400",
                    "fontStyle": "normal",
                },
            ],
        }

        layout = self.module.normalize_pdfjs_source_span_run_layout(
            annotation,
            text,
            fitz.Rect(20, 20, 120, 32),
            10,
        )

        spans = layout[0]["spans"]
        self.assertEqual([span["text"] for span in spans], ["Alpha", "Beta", "Tail"])
        self.assertEqual([span["font_weight"] for span in spans], ["700", "400", "400"])
        self.assertEqual([span["font_style"] for span in spans], ["normal", "italic", "normal"])
        self.assertEqual([span["color"] for span in spans], ["#112233", "#445566", "#111111"])
        self.assertAlmostEqual(spans[0]["rect"].x1, spans[1]["rect"].x0)
        self.assertAlmostEqual(spans[1]["rect"].x1, 92.0)

    def test_pdfjs_visual_lines_preserve_typographic_quotes(self):
        text = 'Alpha “quoted text” omega'
        annotation = {
            "pdfjsVisualLines": [
                'Alpha “quoted',
                'text” omega',
            ],
        }

        self.assertEqual(
            self.module.normalized_pdfjs_visual_lines(annotation, text),
            ['Alpha “quoted', 'text” omega'],
        )

    def test_pdfjs_visual_lines_draw_with_curly_quote_only_font_subset(self):
        text = 'Alpha “quoted text” omega'
        font_source = pathlib.Path(self.module.FONT_DIR) / "Verdana-Regular.ttf"

        with tempfile.TemporaryDirectory() as temp_dir:
            subset_path = pathlib.Path(temp_dir) / "curly-quote-subset.ttf"
            font = TTFont(font_source)
            subsetter = subset.Subsetter()
            subsetter.populate(text=text)
            subsetter.subset(font)
            font.save(subset_path)
            font.close()

            subset_font = TTFont(subset_path)
            try:
                cmap = subset_font.getBestCmap() or {}
                self.assertIn(ord('“'), cmap)
                self.assertIn(ord('”'), cmap)
                self.assertNotIn(ord('"'), cmap)
            finally:
                subset_font.close()

            pdf_path = pathlib.Path(temp_dir) / "visual-lines.pdf"
            doc = fitz.open()
            try:
                doc.new_page(width=320, height=180)
                doc.save(pdf_path)
            finally:
                doc.close()

            annotation = {
                "id": "generic_smart_quote_paragraph",
                "type": "text",
                "pageIndex": 0,
                "text": text,
                "originalText": "Original paragraph",
                "pdfjsSourceText": "Original paragraph",
                "pdfjsSourceX": 25,
                "pdfjsSourceY": 30,
                "pdfjsSourceW": 250,
                "pdfjsSourceH": 40,
                "pdfjsSourcePageHeight": 180,
                "pdfX": 25,
                "pdfY": 30,
                "pdfWidth": 250,
                "pdfHeight": 40,
                "fontSize": 12,
                "lineHeight": 16,
                "fontFamily": "Verdana",
                "fontWeight": "400",
                "fontStyle": "normal",
                "textColor": "#000000",
                "promotedFromExtraction": True,
                "promotedDirty": True,
                "savedTextOverlay": True,
                "movedTextOverlay": True,
                "userForcedRichText": True,
                "pdfjsEditorMode": "rich",
                "pdfjsSourceFidelity": True,
                "pdfjsVisualLines": [
                    'Alpha “quoted',
                    'text” omega',
                ],
            }

            with patch.object(
                self.module,
                "resolve_text_fontfile_with_coverage",
                return_value=str(subset_path),
            ):
                self.module.apply_annotations(str(pdf_path), [annotation])

            output = fitz.open(pdf_path)
            try:
                rendered_text = output[0].get_text()
            finally:
                output.close()

            self.assertIn('“quoted\ntext”', rendered_text)
            self.assertNotIn("\x00", rendered_text)

    def test_pdfjs_redaction_uses_search_occurrence_when_browser_rect_drifted(self):
        doc = fitz.open()
        try:
            page = doc.new_page(width=612, height=792)
            text = "July 17th, 2024 - 1810 Dublin Drive, League city, TX"
            page.insert_text(fitz.Point(125, 75), text, fontsize=12)

            browser_rect = fitz.Rect(219, 792 - (90.8 + 15.05), 219 + 274.89, 792 - 90.8)
            annotation = {
                "id": "pdfjs_4368_0_0:64",
                "pdfjsSourceText": text,
                "pdfjsSourceOccurrence": "0",
            }

            redaction_rects = self.module.pdfjs_source_text_redaction_rects(page, annotation, browser_rect)

            self.assertEqual(len(redaction_rects), 1)
            self.assertLess(redaction_rects[0].y0, 100)
            self.assertNotEqual(round(redaction_rects[0].y0, 1), round(browser_rect.y0, 1))
        finally:
            doc.close()

    def test_moved_pdfjs_source_overlay_redacts_original_before_stamping_replacement(self):
        text = "Severodvinsk, Russia"
        with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
            doc = fitz.open()
            try:
                page = doc.new_page(width=612, height=792)
                page.insert_text(fitz.Point(221.85, 639.0), text, fontsize=11.85)
                doc.save(handle.name)
            finally:
                doc.close()

            annotation = {
                "id": "pdfjs_4368_0_0:55",
                "type": "text",
                "pageIndex": 0,
                "text": text,
                "originalText": text,
                "pdfX": 242.2041,
                "pdfY": 149.3055,
                "pdfWidth": 115.0134,
                "pdfHeight": 15.0525,
                "fontSize": 11.85,
                "requestedFontSize": 11.85,
                "lineHeight": 11.85,
                "fontFamily": "sans-serif",
                "fontWeight": "400",
                "fontStyle": "normal",
                "textColor": "#000000",
                "color": "#000000",
                "savedTextOverlay": True,
                "skipPdfjsSourceMask": False,
                "movedTextOverlay": True,
                "pdfjsSourceX": 221.20425,
                "pdfjsSourceY": 149.3045625,
                "pdfjsSourceW": 115.013436,
                "pdfjsSourceH": 15.0525,
                "pdfjsSourcePageHeight": 792,
                "pdfjsSourceText": text,
                "pdfjsAnchorUid": "0:55",
                "pdfjsSourceOccurrence": "0",
                "pdfjsEditorMode": "source",
                "pdfjsSourceFidelity": True,
                "pdfjsSourceFontSizePx": "39.5",
                "pdfjsSourceLineHeightPx": "39.5px",
                "pdfjsSourceTextColor": "#000000",
            }

            self.module.apply_annotations(handle.name, [annotation])

            out_doc = fitz.open(handle.name)
            try:
                matches = out_doc[0].search_for(text)
                self.assertEqual(len(matches), 1)
                self.assertGreater(matches[0].x0, 240)
            finally:
                out_doc.close()

    def test_pdfjs_source_mask_preserves_form_rule_cutout(self):
        doc = fitz.open()
        try:
            page = doc.new_page(width=300, height=120)
            page.draw_line(fitz.Point(0, 60), fitz.Point(300, 60), color=(0, 0, 0), width=1)
            annotation = {
                "id": "pdfjs_rule_mask",
                "type": "text",
                "pdfjsSourceMaskX": 20,
                "pdfjsSourceMaskY": 50,
                "pdfjsSourceMaskW": 260,
                "pdfjsSourceMaskH": 20,
            }

            self.module.draw_pdfjs_source_mask(page, annotation)

            pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), clip=fitz.Rect(0, 59, 300, 61), alpha=False)
            samples = pix.samples
            channels = max(1, int(getattr(pix, "n", 3) or 3))
            dark_pixels = 0
            for offset in range(0, len(samples), channels):
                r = int(samples[offset])
                g = int(samples[offset + 1])
                b = int(samples[offset + 2])
                luminance = (0.299 * r) + (0.587 * g) + (0.114 * b)
                if luminance < 80:
                    dark_pixels += 1

            self.assertGreater(dark_pixels, 900)
        finally:
            doc.close()

    def test_moved_pdfjs_overlay_drops_inherited_source_background(self):
        text = "15"
        with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
            doc = fitz.open()
            try:
                page = doc.new_page(width=300, height=200)
                page.draw_rect(fitz.Rect(40, 70, 90, 120), color=None, fill=(0.86, 0.88, 0.93), width=0)
                page.insert_text(fitz.Point(48, 108), text, fontsize=28)
                doc.save(handle.name)
            finally:
                doc.close()

            annotation = {
                "id": "pdfjs_4373_4_4:109",
                "type": "text",
                "pageIndex": 0,
                "text": text,
                "originalText": text,
                "pdfX": 150,
                "pdfY": 70,
                "pdfWidth": 45,
                "pdfHeight": 34,
                "fontSize": 28,
                "requestedFontSize": 28,
                "lineHeight": 34,
                "fontFamily": "Helvetica",
                "fontWeight": "400",
                "fontStyle": "normal",
                "textColor": "#000000",
                "color": "#000000",
                "backgroundColor": "#dbe1ef",
                "savedTextOverlay": True,
                "movedTextOverlay": True,
                "skipPdfjsSourceMask": False,
                "styleDirty": False,
                "userCreated": False,
                "pdfjsSourceX": 40,
                "pdfjsSourceY": 80,
                "pdfjsSourceW": 50,
                "pdfjsSourceH": 34,
                "pdfjsSourcePageHeight": 200,
                "pdfjsSourceText": text,
                "pdfjsAnchorUid": "4:109",
                "pdfjsSourceOccurrence": "0",
                "pdfjsEditorMode": "source",
            }

            self.module.apply_annotations(handle.name, [annotation])

            out_doc = fitz.open(handle.name)
            try:
                page = out_doc[0]
                pix = page.get_pixmap(clip=fitz.Rect(188, 126, 189, 127), alpha=False)
                r, g, b = pix.samples[:3]
                self.assertGreater(r, 245)
                self.assertGreater(g, 245)
                self.assertGreater(b, 245)
            finally:
                out_doc.close()

    def test_redact_shrink_evicts_trailing_glyph_between_stacked_lines(self):
        # Tight-leading forms stack consecutive lines ~8pt apart while glyph
        # bboxes are ~15pt tall, so the line above ("...Allowed To") and the
        # word being redacted ("...Instructions On") have overlapping bboxes.
        # Shrinking the redact rect horizontally to dodge BOTH same-row-above
        # neighbours used to squeeze it into a sliver and strand the trailing
        # "n". The fix shrinks vertically instead, so the whole word is removed
        # while the line above stays intact.
        doc = fitz.open()
        try:
            page = doc.new_page(width=612, height=792)
            page.insert_text(fitz.Point(354.7, 219.0), "Legal Alien Not Allowed To", fontsize=11)
            page.insert_text(fitz.Point(354.7, 227.0), "Work(See Instructions On", fontsize=11)
            words = page.get_text("words")

            on_word = next(w for w in words if w[4] == "On")
            on_rect = fitz.Rect(on_word[0], on_word[1], on_word[2], on_word[3])

            shrunk = self.module._shrink_redact_rect_to_avoid_neighbors(page, fitz.Rect(on_rect), list(words))

            # Full horizontal extent preserved (vertical shrink only).
            self.assertAlmostEqual(shrunk.x0, on_rect.x0, places=1)
            self.assertAlmostEqual(shrunk.x1, on_rect.x1, places=1)
            self.assertGreater(shrunk.y0, on_rect.y0)

            # The trailing "n" glyph must be covered by the (shrunk) redact rect.
            last_n = None
            for block in page.get_text("rawdict")["blocks"]:
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        for ch in span.get("chars", []):
                            if ch["c"] == "n" and ch["bbox"][0] > 460:
                                last_n = fitz.Rect(ch["bbox"])
            self.assertIsNotNone(last_n)
            self.assertFalse((last_n & shrunk).is_empty)

            # The line above must NOT be touched by the shrunk rect.
            to_word = next(w for w in words if w[4] == "To")
            to_rect = fitz.Rect(to_word[0], to_word[1], to_word[2], to_word[3])
            self.assertTrue((to_rect & shrunk).is_empty)
        finally:
            doc.close()

    def test_deleted_overprint_source_text_fully_removed(self):
        # Faux-bold/overprint source headings paint each glyph twice with a
        # small horizontal offset. The text-search redaction path covers only
        # one set of glyphs and strands the offset duplicates at word edges
        # (user-reported stray "P I r i" for a deleted "Part II ..." heading).
        # A deleted source span must wipe every glyph in the captured rect
        # while leaving the row below intact.
        text = "Part II Seller/Transferor Information"
        with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
            doc = fitz.open()
            try:
                page = doc.new_page(width=612, height=792)
                baseline = fitz.Point(40, 220)
                # Overprint: same text painted twice, offset to fake bold.
                page.insert_text(baseline, text, fontsize=11)
                page.insert_text(fitz.Point(baseline.x + 0.4, baseline.y), text, fontsize=11)
                # Neighbouring row just below that must survive.
                page.insert_text(fitz.Point(40, 236), "First name/Grantor", fontsize=8)
                doc.save(handle.name)
            finally:
                doc.close()

            doc = fitz.open(handle.name)
            try:
                page = doc[0]
                source_match = page.search_for(text)[0]
                ph = page.rect.height
                annotation = {
                    "id": "pdfjs_deleted_pdfjs_0_0_0:35",
                    "type": "text",
                    "pageIndex": 0,
                    "text": None,
                    "originalText": text,
                    "pdfjsDeleted": True,
                    "savedTextOverlay": True,
                    "skipPdfjsSourceMask": False,
                    "fontSize": 11,
                    "pdfX": source_match.x0,
                    "pdfY": ph - source_match.y1,
                    "pdfWidth": source_match.width,
                    "pdfHeight": source_match.height,
                    "pdfjsSourceX": source_match.x0,
                    "pdfjsSourceY": ph - source_match.y1,
                    "pdfjsSourceW": source_match.width,
                    "pdfjsSourceH": source_match.height,
                    "pdfjsSourcePageHeight": ph,
                    "pdfjsSourceText": text,
                    "pdfjsAnchorUid": "0:35",
                    "pdfjsSourceOccurrence": "0",
                }
            finally:
                doc.close()

            self.module.apply_annotations(handle.name, [annotation])

            out_doc = fitz.open(handle.name)
            try:
                page = out_doc[0]
                # No fragment of the deleted heading may survive.
                self.assertEqual(page.search_for("Part"), [])
                self.assertEqual(page.search_for("Information"), [])

                # No ink in the deleted source rect.
                clip = fitz.Rect(
                    source_match.x0, source_match.y0, source_match.x1, source_match.y1
                ) & page.rect
                pix = page.get_pixmap(matrix=fitz.Matrix(4, 4), clip=clip, alpha=False)
                samples = pix.samples
                channels = max(1, int(getattr(pix, "n", 3) or 3))
                dark = 0
                for offset in range(0, len(samples), channels):
                    r = int(samples[offset])
                    g = int(samples[offset + 1])
                    b = int(samples[offset + 2])
                    if (0.299 * r) + (0.587 * g) + (0.114 * b) < 180:
                        dark += 1
                self.assertEqual(dark, 0)

                # The neighbouring row below must remain.
                self.assertNotEqual(page.search_for("First name/Grantor"), [])
            finally:
                out_doc.close()

    def test_rich_span_bold_resolves_sibling_embedded_face(self):
        """A rich-text span that requests bold while the base source font is a
        regular runtime-extracted face must resolve to the bold sibling within
        the same family + stretch (e.g. HelveticaLTStd-Cond -> -BoldCond),
        instead of silently rendering regular because the exact-name match
        dominates the weight preference."""
        module = self.module
        metadata = {
            "HelveticaLTStd-Cond": {
                "clean_name": "HelveticaLTStd-Cond",
                "family": "HelveticaLTStd",
                "css_weight": "400",
                "css_style": "normal",
                "css_stretch": "condensed",
                "file_path": "/fonts/runtime-extracted/9999/HelveticaLTStd-Cond.otf",
            },
            "HelveticaLTStd-BoldCond": {
                "clean_name": "HelveticaLTStd-BoldCond",
                "family": "HelveticaLTStd",
                "css_weight": "700",
                "css_style": "normal",
                "css_stretch": "condensed",
                "file_path": "/fonts/runtime-extracted/9999/HelveticaLTStd-BoldCond.otf",
            },
            "HelveticaLTStd-Bold": {
                "clean_name": "HelveticaLTStd-Bold",
                "family": "HelveticaLTStd",
                "css_weight": "700",
                "css_style": "normal",
                "css_stretch": "normal",
                "file_path": "/fonts/runtime-extracted/9999/HelveticaLTStd-Bold.otf",
            },
        }
        original_loader = module.load_embedded_font_metadata
        module.EMBEDDED_FONT_METADATA_CACHE.pop("9999", None)
        module.load_embedded_font_metadata = lambda document_id: metadata
        # Avoid filesystem coverage checks: the runtime-extracted otf files do
        # not exist in this synthetic test, so treat every face as covering.
        original_covers = module._embedded_font_covers_text
        module._embedded_font_covers_text = lambda fontfile, text: True
        # The public-path -> absolute resolver would look on disk; stub it to
        # return a deterministic absolute path for the assertions.
        original_abs = module.embedded_font_public_path_to_absolute
        module.embedded_font_public_path_to_absolute = lambda web_path: str(web_path or "")
        try:
            base = {
                "fontFamily": "HelveticaLTStd-Cond",
                "fontSourceName": "HelveticaLTStd-Cond",
                "forceEmbeddedFont": True,
                "pdfjsForceEmbeddedFont": True,
                "savedTextOverlay": True,
                "pdfjsAnchorUid": "1:48",
                "__documentId": "9999",
            }

            bold_ann = dict(base, fontWeight="700", text="13.")
            bold_entry = module.resolve_embedded_font_entry(bold_ann)
            self.assertIsNotNone(bold_entry)
            self.assertEqual(bold_entry["clean_name"], "HelveticaLTStd-BoldCond")
            self.assertGreaterEqual(int(bold_entry["css_weight"]), 600)

            regular_ann = dict(base, fontWeight="400", text="Selling price")
            regular_entry = module.resolve_embedded_font_entry(regular_ann)
            self.assertIsNotNone(regular_entry)
            self.assertEqual(regular_entry["clean_name"], "HelveticaLTStd-Cond")
            self.assertLess(int(regular_entry["css_weight"]), 600)

            promoted_edit_ann = dict(
                base,
                promotedFromExtraction=True,
                promotedDirty=False,
                userAuthored=True,
                fontWeight="400",
                text="Selling price test",
            )
            promoted_edit_entry = module.resolve_embedded_font_entry(promoted_edit_ann)
            self.assertIsNotNone(promoted_edit_entry)
            self.assertEqual(promoted_edit_entry["clean_name"], "HelveticaLTStd-Cond")
        finally:
            module.load_embedded_font_metadata = original_loader
            module._embedded_font_covers_text = original_covers
            module.embedded_font_public_path_to_absolute = original_abs
            module.EMBEDDED_FONT_METADATA_CACHE.pop("9999", None)

    def test_rich_lato_style_override_rejects_regular_only_embedded_face(self):
        module = self.module
        metadata = {
            "PdbpbbLato-Regular": {
                "clean_name": "PdbpbbLato-Regular",
                "family": "PdbpbbLato",
                "css_weight": "400",
                "css_style": "normal",
                "css_stretch": "normal",
                "file_path": "/fonts/runtime-extracted/5217/PdbpbbLato-Regular.ttf",
            },
        }
        base = {
            "fontFamily": "PdbpbbLato-Regular",
            "fontSourceName": "PdbpbbLato-Regular",
            "forceEmbeddedFont": True,
            "pdfjsForceEmbeddedFont": True,
            "promotedFromExtraction": True,
            "userAuthored": True,
            "__documentId": "5217",
        }

        with patch.object(module, "load_embedded_font_metadata", return_value=metadata):
            regular = module.resolve_embedded_font_entry(
                dict(base, fontWeight="400", fontStyle="normal", text="formalities")
            )
            bold = module.resolve_embedded_font_entry(
                dict(base, fontWeight="700", fontStyle="normal", text="associated")
            )
            italic = module.resolve_embedded_font_entry(
                dict(base, fontWeight="400", fontStyle="italic", text="process")
            )

        self.assertIsNotNone(regular)
        self.assertEqual(regular["clean_name"], "PdbpbbLato-Regular")
        self.assertIsNone(bold)
        self.assertIsNone(italic)
        self.assertEqual(
            pathlib.Path(module.resolve_text_fontfile(
                dict(base, fontWeight="700", fontStyle="normal", text="associated")
            )).name,
            "Lato-Bold.ttf",
        )
        self.assertIn(
            "NotoSans-Italic",
            pathlib.Path(module.resolve_text_fontfile(
                dict(base, fontWeight="400", fontStyle="italic", text="process")
            )).name,
        )

    def test_user_authored_promoted_edit_rejects_embedded_subset_missing_new_glyph(self):
        module = self.module
        subset_path = "/fonts/runtime-extracted/9998/DocumentSubset-Regular.otf"
        metadata = {
            "DocumentSubset-Regular": {
                "clean_name": "DocumentSubset-Regular",
                "family": "DocumentSubset",
                "css_weight": "400",
                "css_style": "normal",
                "css_stretch": "normal",
                "file_path": subset_path,
            },
        }
        annotation = {
            "type": "text",
            "text": "original plus Ω",
            "fontFamily": "DocumentSubset",
            "fontSourceName": "DocumentSubset-Regular",
            "fontWeight": "400",
            "fontStyle": "normal",
            "promotedFromExtraction": True,
            "userAuthored": True,
            "__documentId": "9998",
        }

        with patch.object(
            module,
            "load_embedded_font_metadata",
            return_value=metadata,
        ), patch.object(
            module,
            "embedded_font_public_path_to_absolute",
            side_effect=lambda value: str(value or ""),
        ), patch.object(
            module,
            "_embedded_font_covers_text",
            return_value=False,
        ):
            entry = module.resolve_embedded_font_entry(annotation)

        self.assertIsNone(entry)

    def test_bold_substitute_materializes_variable_font_at_requested_weight(self):
        module = self.module
        bold_entry = module.resolve_substitute_font_entry(
            "HelveticaNeueLTStd-BdCn",
            {
                "fontWeight": "700",
                "fontStyle": "normal",
            },
        )
        regular_entry = module.resolve_substitute_font_entry(
            "HelveticaNeueLTStd-Cn",
            {
                "fontWeight": "400",
                "fontStyle": "normal",
            },
        )

        self.assertIsNotNone(bold_entry)
        self.assertIsNotNone(regular_entry)
        self.assertNotEqual(bold_entry["fontfile"], regular_entry["fontfile"])
        self.assertIn("_w700", pathlib.Path(bold_entry["fontfile"]).stem)
        self.assertIn("_w400", pathlib.Path(regular_entry["fontfile"]).stem)
        self.assertGreaterEqual(int(bold_entry["css_weight"]), 600)
        self.assertLess(int(regular_entry["css_weight"]), 600)
        bold_font = fitz.Font(fontfile=bold_entry["fontfile"])
        regular_font = fitz.Font(fontfile=regular_entry["fontfile"])
        self.assertTrue(bold_font.is_bold)
        self.assertFalse(regular_font.is_bold)
        self.assertIn("bold", bold_font.name.lower())

if __name__ == "__main__":
    unittest.main()
