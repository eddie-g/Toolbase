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

    def test_pdfjs_source_edit_skip_mask_disables_redaction(self):
        annotation = {
            "id": "user-text-1",
            "type": "text",
            "savedTextOverlay": True,
            "skipPdfjsSourceMask": True,
            "pdfjsSourceX": 47.953125,
            "pdfjsSourceText": "Original",
            "text": "Changed",
        }

        self.assertFalse(self.module.pdfjs_source_edit_requires_redaction(annotation))

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
