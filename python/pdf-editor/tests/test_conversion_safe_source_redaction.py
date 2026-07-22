import sys
import tempfile
import unittest
from pathlib import Path

import fitz


PDF_EDITOR_DIR = Path(__file__).resolve().parents[1]
if str(PDF_EDITOR_DIR) not in sys.path:
    sys.path.insert(0, str(PDF_EDITOR_DIR))

from apply_annotations_direct_new import apply_annotations  # noqa: E402


class ConversionSafeSourceRedactionTest(unittest.TestCase):
    source_lines = (
        "Alpha source paragraph first line.\n"
        "Alpha source paragraph second line."
    )

    def make_source_pdf(self, path: Path) -> None:
        document = fitz.open()
        page = document.new_page(width=460, height=300)
        page.insert_text((40, 50), "Alpha source paragraph first line.", fontsize=12)
        page.insert_text((40, 68), "Alpha source paragraph second line.", fontsize=12)
        page.insert_text((40, 125), "Omega neighbor paragraph must remain.", fontsize=12)
        document.save(path)
        document.close()

    def annotation(self, conversion_safe: bool) -> dict:
        return {
            "id": "promoted_1_0",
            "type": "text",
            "pageIndex": 0,
            "text": self.source_lines,
            "originalText": self.source_lines,
            "pdfjsSourceText": self.source_lines,
            "pdfX": 40,
            "pdfY": 220,
            "pdfWidth": 340,
            "pdfHeight": 55,
            "pdfjsSourceX": 40,
            "pdfjsSourceY": 220,
            "pdfjsSourceW": 340,
            "pdfjsSourceH": 55,
            "pdfjsSourceMaskX": 40,
            "pdfjsSourceMaskY": 220,
            "pdfjsSourceMaskW": 340,
            "pdfjsSourceMaskH": 55,
            "fontFamily": "Helvetica",
            "fontSourceName": "Helvetica",
            "fontSize": 12,
            "lineHeight": 18,
            "textColor": "#a75252",
            "savedTextOverlay": True,
            "promotedFromExtraction": True,
            "styleDirty": True,
            "userAuthored": True,
            "conversionSafeSourceRedaction": conversion_safe,
        }

    def extracted_text_after_export(self, conversion_safe: bool) -> str:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "source.pdf"
            self.make_source_pdf(path)
            apply_annotations(str(path), [self.annotation(conversion_safe)])
            document = fitz.open(path)
            text = document[0].get_text("text")
            document.close()
            return text

    def test_default_export_removes_hidden_source_text(self) -> None:
        text = self.extracted_text_after_export(conversion_safe=False)
        self.assertEqual(2, text.count("Alpha source paragraph"))
        self.assertEqual(1, text.count("Omega neighbor paragraph"))

    def test_explicit_conversion_safe_export_removes_source_words_only(self) -> None:
        text = self.extracted_text_after_export(conversion_safe=True)
        self.assertEqual(2, text.count("Alpha source paragraph"))
        self.assertEqual(1, text.count("Omega neighbor paragraph"))

    def test_deleted_source_is_removed_even_when_client_omits_source_text(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "source.pdf"
            self.make_source_pdf(path)
            annotation = self.annotation(conversion_safe=False)
            annotation.update({
                "text": "",
                "originalText": "",
                "pdfjsSourceText": "",
                "pdfjsDeleted": True,
            })
            apply_annotations(str(path), [annotation])
            document = fitz.open(path)
            text = document[0].get_text("text")
            document.close()

        self.assertEqual(0, text.count("Alpha source paragraph"))
        self.assertEqual(1, text.count("Omega neighbor paragraph"))

    def test_overlapping_title_survives_source_word_redaction(self) -> None:
        """A short edited row must not redact a taller overlapping heading."""
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "overlapping-heading.pdf"
            document = fitz.open()
            page = document.new_page(width=220, height=300)
            page.insert_text((55.3, 58.7), "4506-T", fontsize=24, fontname="hebo")
            page.insert_text((62.5, 63.7), "(April 2025)", fontsize=7.2)
            document.save(path)
            document.close()

            annotation = {
                "id": "pdfjs_test_0_0:4",
                "type": "text",
                "pageIndex": 0,
                "text": "(April 1025)",
                "originalText": "(April 2025)",
                "pdfjsSourceText": "(April 2025)",
                "pdfX": 66.4,
                "pdfY": 227.3,
                "pdfWidth": 40,
                "pdfHeight": 9,
                "pdfjsSourceX": 62.5,
                "pdfjsSourceY": 236.3,
                "pdfjsSourceW": 40,
                "pdfjsSourceH": 9,
                "pdfjsSourceMaskX": 62.5,
                "pdfjsSourceMaskY": 236.3,
                "pdfjsSourceMaskW": 40,
                "pdfjsSourceMaskH": 9,
                "fontFamily": "Helvetica",
                "fontSize": 7.2,
                "lineHeight": 7.2,
                "textColor": "#000000",
                "savedTextOverlay": True,
                "movedTextOverlay": True,
                "userAuthored": True,
            }
            apply_annotations(str(path), [annotation])

            document = fitz.open(path)
            page = document[0]
            text = page.get_text("text")
            title_matches = page.search_for("4506-T")
            document.close()

        self.assertEqual(1, len(title_matches), text)
        self.assertNotIn("(April 2025)", text)
        self.assertIn("(April 1025)", text)


if __name__ == "__main__":
    unittest.main()
