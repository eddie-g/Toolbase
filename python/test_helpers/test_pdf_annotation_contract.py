#!/usr/bin/env python3

import importlib.util
import pathlib
import sys
import unittest


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "pdf-editor" / "pdf_annotation_contract.py"


def load_module():
    module_dir = str(MODULE_PATH.parent)
    if module_dir not in sys.path:
        sys.path.insert(0, module_dir)
    spec = importlib.util.spec_from_file_location("pdf_annotation_contract", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError(f"Unable to load module from {MODULE_PATH}")
    spec.loader.exec_module(module)
    return module


class PdfAnnotationContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = load_module()

    def test_normalize_annotation_for_pdf_export_sanitizes_text_fields(self):
        annotation = {
            "id": "title-1",
            "type": "text",
            "text": "Spiders\u00A0in\u00A0The\u00A0Lord\u00A0of\u00A0the\u00A0Rings",
            "richTextHtml": "<span>Spiders&nbsp;in&#160;The&#xA0;Lord</span>",
            "sourceTextLines": ["Line\u00A01"],
            "sourceSpans": [
                {"text": "Span\u00A01", "rawText": "Raw\u00A01"},
            ],
        }

        normalized = self.module.normalize_annotation_for_pdf_export(annotation)

        self.assertEqual(normalized["text"], "Spiders in The Lord of the Rings")
        self.assertEqual(normalized["richTextHtml"], "<span>Spiders in The Lord</span>")
        self.assertEqual(normalized["sourceTextLines"], ["Line 1"])
        self.assertEqual(normalized["sourceSpans"][0]["text"], "Span 1")
        self.assertEqual(normalized["sourceSpans"][0]["rawText"], "Raw 1")

    def test_assert_text_annotations_redraw_contract_rejects_out_of_band_page(self):
        annotations = [
            {"id": "text-1", "type": "text", "pageIndex": 1, "text": "Hello"},
            {"id": "shape-1", "type": "shape", "pageIndex": 0},
        ]

        with self.assertRaisesRegex(ValueError, "text-1@page:1"):
            self.module.assert_text_annotations_redraw_contract(annotations, [0])

    def test_pdfjs_source_edit_requires_redaction_for_changed_source_text(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "savedTextOverlay": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceY": 261.928125,
            "pdfjsSourceW": 538.6199707031251,
            "pdfjsSourceH": 6.6,
            "pdfjsSourceText": "or a maximum rental charge of $1,242.20 plus applicable taxes",
            "text": "or a maximum rental charge of $2,999.20 plus applicable taxes",
        }

        self.assertTrue(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_pdfjs_source_edit_does_not_require_redaction_for_unchanged_source_text(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "savedTextOverlay": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceText": "Unchanged text",
            "text": "Unchanged text",
        }

        self.assertFalse(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_pdfjs_source_edit_requires_redaction_for_deleted_source_text(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "pdfjsDeleted": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceText": "Deleted source text",
            "text": "",
        }

        self.assertTrue(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_pdfjs_source_edit_requires_redaction_for_moved_promoted_source_text(self):
        annotation = {
            "id": "promoted_1_44",
            "type": "text",
            "promotedFromExtraction": True,
            "savedTextOverlay": True,
            "movedTextOverlay": True,
            "pdfjsSourceX": 73.2,
            "pdfjsSourceY": 192.79,
            "pdfjsSourceW": 40,
            "pdfjsSourceH": 8,
            "pdfjsSourceText": "Real estate",
            "text": "Real estate",
        }

        self.assertTrue(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_pdfjs_source_edit_requires_redaction_for_edited_promoted_source_text(self):
        # Regression: editing a promoted (extraction) span in place — not
        # deleting, not moving — must redact the original glyphs or the export
        # shows both the source and the replacement (double text).
        annotation = {
            "id": "promoted_1_9",
            "type": "text",
            "promotedFromExtraction": True,
            "savedTextOverlay": True,
            "pdfjsSourceX": 523.7,
            "pdfjsSourceY": 20.7,
            "pdfjsSourceW": 35.1,
            "pdfjsSourceH": 12.1,
            "pdfjsAnchorUid": "u9",
            "pdfjsSourceText": "USCIS",
            "text": "ZZZZZ",
        }

        self.assertTrue(self.module.promoted_source_overlay_text_was_edited(annotation))
        self.assertTrue(self.module.is_pdfjs_promoted_source_backed_text_annotation(annotation))
        self.assertTrue(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_unedited_promoted_source_text_not_treated_as_source_backed(self):
        # Unedited promoted overlay (text == source) must keep legacy behaviour:
        # not source-backed, no redaction, so the original PDF text shows through.
        annotation = {
            "id": "promoted_1_9",
            "type": "text",
            "promotedFromExtraction": True,
            "savedTextOverlay": True,
            "pdfjsSourceX": 523.7,
            "pdfjsAnchorUid": "u9",
            "pdfjsSourceText": "USCIS",
            "text": "USCIS",
        }

        self.assertFalse(self.module.promoted_source_overlay_text_was_edited(annotation))
        self.assertFalse(self.module.is_pdfjs_promoted_source_backed_text_annotation(annotation))
        self.assertFalse(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_style_only_promoted_source_text_is_source_backed_without_redaction(self):
        annotation = {
            "id": "promoted_1_1",
            "type": "text",
            "promotedFromExtraction": True,
            "savedTextOverlay": True,
            "userAuthored": True,
            "styleDirty": True,
            "pdfjsSourceX": 23.65,
            "pdfjsSourceY": 716.9,
            "pdfjsSourceW": 123.97,
            "pdfjsSourceH": 22.0,
            "pdfjsSourceText": "1234 Company St.\nCompany Town ST 12345",
            "text": "1234 Company St.\nCompany Town ST 12345",
        }

        self.assertTrue(self.module.promoted_source_overlay_has_visible_change(annotation))
        self.assertTrue(self.module.is_pdfjs_promoted_source_backed_text_annotation(annotation))
        self.assertFalse(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_pdfjs_source_edit_skip_mask_disables_redaction(self):
        # `skipPdfjsSourceMask` only disables redaction when there is NO real
        # underlying source text to mask (a genuine standalone user text box).
        annotation = {
            "id": "user-text-1",
            "type": "text",
            "savedTextOverlay": True,
            "skipPdfjsSourceMask": True,
            "pdfjsSourceX": 47.953125,
            "text": "Changed",
        }

        self.assertFalse(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_pdfjs_source_edit_skip_mask_does_not_block_edited_source_text(self):
        # Regression (doc 4199, "EVGENIY SLUSAR" -> "sdfdsfsdf"): an edited
        # source span carrying real `pdfjsSourceText` must STILL redact the
        # original even though the editor mis-flagged it userCreated /
        # skipPdfjsSourceMask, or the export shows both (double text).
        annotation = {
            "id": "pdfjs_4199_0_0:0",
            "type": "text",
            "userCreated": True,
            "savedTextOverlay": True,
            "skipPdfjsSourceMask": True,
            "pdfjsSourceX": 49.026,
            "pdfjsSourceY": 746.5,
            "pdfjsSourceW": 192.237,
            "pdfjsSourceH": 27.577,
            "pdfjsAnchorUid": "0:0",
            "pdfjsSourceText": "EVGENIY SLUSAR",
            "text": "sdfdsfsdf",
        }

        self.assertTrue(self.module.source_overlay_text_was_edited(annotation))
        self.assertTrue(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_standalone_user_text_box_is_not_edited_source(self):
        # A genuine user text box (no pdfjsSourceText) is never an edited source
        # span and must not trigger source redaction.
        annotation = {
            "id": "user-text-2",
            "type": "text",
            "userCreated": True,
            "skipPdfjsSourceMask": True,
            "text": "Hello world",
        }

        self.assertFalse(self.module.source_overlay_text_was_edited(annotation))
        self.assertFalse(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_cleared_source_span_to_empty_requires_redaction(self):
        # Regression (doc 4368, pdfjs_4368_0_0:10, address redaction): clearing
        # a source-backed overlay to empty IS a redaction. The empty replacement
        # must still redact the original glyphs, otherwise the downloaded PDF
        # keeps the original text (selectable + visible = "NOT REDACTED").
        annotation = {
            "id": "pdfjs_4368_0_0:10",
            "type": "text",
            "savedTextOverlay": True,
            "userCreated": False,
            "skipPdfjsSourceMask": False,
            "pdfjsSourceX": 126.61875,
            "pdfjsSourceY": 571.3592,
            "pdfjsSourceW": 225.9800,
            "pdfjsSourceH": 15.0525,
            "pdfjsSourceText": "1810 Dublin Drive, League City, TX, 77573",
            "originalText": "1810 Dublin Drive, League City, TX, 77573",
            "text": "",
        }

        self.assertTrue(self.module.source_overlay_text_was_edited(annotation))
        self.assertTrue(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_cleared_source_span_to_empty_redacts_even_when_skip_mask_misflagged(self):
        # Same as above but the editor mis-flagged skipPdfjsSourceMask: an
        # emptied source-backed span must still redact the original glyphs.
        annotation = {
            "id": "pdfjs_4368_0_0:10",
            "type": "text",
            "userCreated": True,
            "savedTextOverlay": True,
            "skipPdfjsSourceMask": True,
            "pdfjsAnchorUid": "0:10",
            "pdfjsSourceX": 126.61875,
            "pdfjsSourceText": "1810 Dublin Drive, League City, TX, 77573",
            "text": "",
        }

        self.assertTrue(self.module.source_overlay_text_was_edited(annotation))
        self.assertTrue(self.module.pdfjs_source_edit_requires_redaction(annotation))

    def test_pdfjs_source_edit_replacement_text_uses_saved_text_only(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "savedTextOverlay": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceText": "or a maximum rental charge of $1,242.20 plus applicable taxes",
            "originalText": "or a maximum rental charge of $1,242.20 plus applicable taxes",
            "text": "or a maximum rental charge of $2,999.20 plus applicable taxes",
        }

        self.assertEqual(
            self.module.pdfjs_source_edit_replacement_text(annotation),
            "or a maximum rental charge of $2,999.20 plus applicable taxes",
        )

    def test_pdfjs_source_edit_replacement_text_never_falls_back_to_source_text(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "savedTextOverlay": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceText": "source text must not be drawn",
            "originalText": "original text must not be drawn",
        }

        self.assertEqual(self.module.pdfjs_source_edit_replacement_text(annotation), "")

    def test_pdfjs_source_edit_replacement_text_is_empty_for_deleted_source_text(self):
        annotation = {
            "id": "pdfjs_deleted_pdfjs_4037_0_source:0:53",
            "type": "text",
            "pdfjsDeleted": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceText": "deleted source text must not be redrawn",
            "text": "stale replacement must not be drawn",
        }

        self.assertEqual(self.module.pdfjs_source_edit_replacement_text(annotation), "")

    def test_pdfjs_source_edit_export_metrics_uses_saved_browser_source_metrics(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "savedTextOverlay": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceY": 261.928125,
            "pdfjsSourceW": 538.6199707031251,
            "pdfjsSourceH": 6.6,
            "pdfjsSourceText": "or a maximum rental charge of $1,242.20 plus applicable taxes",
            "text": "or a maximum rental charge of $1,942.20 plus applicable taxes",
            "pdfjsSourceFidelity": True,
            "pdfjsSourceFontFamily": "sans-serif",
            "pdfjsSourceFontWeight": "700",
            "pdfjsSourceFontStyle": "normal",
            "pdfjsSourceFontSizePx": "22",
            "pdfjsSourceLineHeightPx": "22px",
            "pdfjsSourceTextWidthPx": "1389.262811909985",
            "pdfjsSourceTextColor": "#303030",
            "pdfjsSourceTransform": "matrix(1.29234, 0, 0, 1, 0, 0)",
            "pdfjsSourceTransformScaleX": "1.29234",
            "pdfjsSourceTransformOrigin": "0px 0px",
        }

        metrics = self.module.pdfjs_source_edit_export_metrics(annotation)

        self.assertEqual(
            metrics["sourceRect"],
            {"x": 47.953125, "y": 261.928125, "w": 538.6199707031251, "h": 6.6},
        )
        self.assertEqual(metrics["sourceText"], "or a maximum rental charge of $1,242.20 plus applicable taxes")
        self.assertEqual(metrics["replacementText"], "or a maximum rental charge of $1,942.20 plus applicable taxes")
        self.assertEqual(metrics["transformScaleX"], 1.29234)
        self.assertEqual(metrics["sourceFontFamily"], "sans-serif")
        self.assertEqual(metrics["sourceFontWeight"], "700")
        self.assertEqual(metrics["sourceFontStyle"], "normal")
        self.assertEqual(metrics["sourceTextColor"], "#303030")
        self.assertTrue(metrics["sourceFidelity"])

    def test_pdfjs_source_edit_transform_scale_falls_back_to_transform_matrix(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "savedTextOverlay": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceTransform": "matrix(1.29234, 0, 0, 1, 0, 0)",
        }

        self.assertEqual(self.module.pdfjs_source_edit_transform_scale_x(annotation), 1.29234)

    def test_pdfjs_source_edit_transform_scale_ignores_source_scale_for_rich_mode(self):
        annotation = {
            "id": "pdfjs_4037_0_source:0:53",
            "type": "text",
            "savedTextOverlay": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsEditorMode": "rich",
            "pdfjsSourceTransformScaleX": "1.29234",
        }

        self.assertEqual(self.module.pdfjs_source_edit_transform_scale_x(annotation), 1.0)


if __name__ == "__main__":
    unittest.main()
