#!/usr/bin/env python3

import importlib.util
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
        self.assertEqual(permanent["text"], " permanent")
        self.assertEqual(permanent["font_weight"], "700")
        self.assertEqual(addition["color"], "#b23857")

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
        finally:
            module.load_embedded_font_metadata = original_loader
            module._embedded_font_covers_text = original_covers
            module.embedded_font_public_path_to_absolute = original_abs
            module.EMBEDDED_FONT_METADATA_CACHE.pop("9999", None)

if __name__ == "__main__":
    unittest.main()
