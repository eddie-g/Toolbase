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


if __name__ == "__main__":
    unittest.main()
