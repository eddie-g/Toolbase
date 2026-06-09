#!/usr/bin/env python3

import importlib.util
import pathlib
import sys
import tempfile
import unittest

import fitz


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

if __name__ == "__main__":
    unittest.main()
