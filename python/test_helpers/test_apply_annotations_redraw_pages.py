#!/usr/bin/env python3

import importlib.util
import pathlib
import sys
import tempfile
import unittest

import fitz
from PIL import Image, ImageChops, ImageStat


PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
PDF_EDITOR_DIR = PROJECT_ROOT / "python" / "pdf-editor"
EXTRACT_MODULE_PATH = PDF_EDITOR_DIR / "extract_pdf_pymupdf.py"
REDRAW_MODULE_PATH = PDF_EDITOR_DIR / "apply_annotations_redraw_pages.py"
FW8BEN_FIXTURE_PATH = PROJECT_ROOT / "public" / "fw8ben.pdf"


def load_module(module_path: pathlib.Path, module_name: str):
    module_dir = str(module_path.parent)
    if module_dir not in sys.path:
        sys.path.insert(0, module_dir)
    spec = importlib.util.spec_from_file_location(module_name, module_path)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError(f"Unable to load module from {module_path}")
    spec.loader.exec_module(module)
    return module


def normalize_pdf_word_text(value: str) -> str:
    return (
        str(value or "")
        .replace("\u00ad", "-")
        .replace("\u2010", "-")
        .replace("\u2011", "-")
        .replace("\xa0", " ")
        .strip()
    )


def collect_word_bboxes(page: fitz.Page, clip: fitz.Rect) -> dict[str, list[fitz.Rect]]:
    result: dict[str, list[fitz.Rect]] = {}
    for word in page.get_text("words", clip=clip):
        text = normalize_pdf_word_text(word[4])
        if not text:
            continue
        rect = fitz.Rect(float(word[0]), float(word[1]), float(word[2]), float(word[3]))
        result.setdefault(text, []).append(rect)
    return result


class ApplyAnnotationsRedrawPagesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.extract_module = load_module(EXTRACT_MODULE_PATH, "extract_pdf_pymupdf")
        cls.redraw_module = load_module(REDRAW_MODULE_PATH, "apply_annotations_redraw_pages")
        extraction_result = cls.extract_module.extract_text_with_pymupdf(str(FW8BEN_FIXTURE_PATH))
        cls.fw8ben_extraction_data = extraction_result["extraction_data"]

    def test_fw8ben_page_redraw_preserves_header_geometry(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            output_pdf_path = pathlib.Path(tmp_dir) / "fw8ben_redraw.pdf"
            self.redraw_module.rebuild_pages_with_annotations(
                str(FW8BEN_FIXTURE_PATH),
                self.fw8ben_extraction_data,
                [],
                str(output_pdf_path),
                [0],
                str(FW8BEN_FIXTURE_PATH),
            )

            self.assertTrue(output_pdf_path.exists(), str(output_pdf_path))

            original_doc = fitz.open(str(FW8BEN_FIXTURE_PATH))
            rebuilt_doc = fitz.open(str(output_pdf_path))
            try:
                clip = fitz.Rect(20, 20, 140, 110)
                original_words = collect_word_bboxes(original_doc[0], clip)
                rebuilt_words = collect_word_bboxes(rebuilt_doc[0], clip)

                for token in [
                    "Form",
                    "W-8BEN",
                    "(Rev.",
                    "October",
                    "2021)",
                    "Department",
                    "Treasury",
                    "Internal",
                    "Revenue",
                    "Service",
                ]:
                    self.assertIn(token, original_words)
                    self.assertIn(token, rebuilt_words)
                    original_rect = original_words[token][0]
                    rebuilt_rect = rebuilt_words[token][0]
                    self.assertLessEqual(abs(rebuilt_rect.x0 - original_rect.x0), 2.5, token)
                    self.assertLessEqual(abs(rebuilt_rect.y0 - original_rect.y0), 0.5, token)
                    self.assertLessEqual(abs(rebuilt_rect.x1 - original_rect.x1), 4.0, token)
                    self.assertLessEqual(abs(rebuilt_rect.y1 - original_rect.y1), 0.5, token)

                original_pix = original_doc[0].get_pixmap(matrix=fitz.Matrix(4, 4), clip=clip, alpha=False)
                rebuilt_pix = rebuilt_doc[0].get_pixmap(matrix=fitz.Matrix(4, 4), clip=clip, alpha=False)
                original_image = Image.frombytes(
                    "RGB",
                    (original_pix.width, original_pix.height),
                    original_pix.samples,
                )
                rebuilt_image = Image.frombytes(
                    "RGB",
                    (rebuilt_pix.width, rebuilt_pix.height),
                    rebuilt_pix.samples,
                )
                diff = ImageChops.difference(original_image, rebuilt_image)
                mean_channels = ImageStat.Stat(diff).mean
                self.assertLessEqual(max(mean_channels), 7.0, mean_channels)
            finally:
                rebuilt_doc.close()
                original_doc.close()


if __name__ == "__main__":
    unittest.main()
