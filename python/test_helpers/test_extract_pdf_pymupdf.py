#!/usr/bin/env python3

import importlib.util
import pathlib
import sys
import unittest


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "pdf-editor" / "extract_pdf_pymupdf.py"
FIXTURE_PATH = pathlib.Path(__file__).resolve().parents[2] / "public" / "2026-593.pdf"
FW8BEN_FIXTURE_PATH = pathlib.Path(__file__).resolve().parents[2] / "public" / "fw8ben.pdf"


def load_module():
    module_dir = str(MODULE_PATH.parent)
    if module_dir not in sys.path:
        sys.path.insert(0, module_dir)
    spec = importlib.util.spec_from_file_location("extract_pdf_pymupdf", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError(f"Unable to load module from {MODULE_PATH}")
    spec.loader.exec_module(module)
    return module


class ExtractPdfPyMuPdfTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = load_module()

    def test_x_gap_split_keeps_stacked_same_column_lines_in_one_group(self):
        candidate = [
            {
                "x0": 56.7,
                "bbox": (56.7, 439.6, 289.0, 454.0),
                "max_size": 12,
            },
            {
                "x0": 56.7,
                "bbox": (56.7, 456.4, 283.2, 470.8),
                "max_size": 12,
            },
            {
                "x0": 56.7,
                "bbox": (56.7, 473.2, 287.5, 487.6),
                "max_size": 12,
            },
        ]

        groups = self.module._split_candidate_lines_by_x_gap(candidate, [], [])

        self.assertEqual(len(groups), 1)
        self.assertEqual(len(groups[0]), 3)

    def test_x_gap_split_still_separates_same_row_wide_gap_columns(self):
        candidate = [
            {
                "x0": 56.7,
                "bbox": (56.7, 616.0, 167.0, 630.0),
                "max_size": 12,
            },
            {
                "x0": 303.6,
                "bbox": (303.6, 616.2, 412.0, 630.2),
                "max_size": 12,
            },
        ]

        groups = self.module._split_candidate_lines_by_x_gap(candidate, [], [])

        self.assertEqual(len(groups), 2)
        self.assertEqual(len(groups[0]), 1)
        self.assertEqual(len(groups[1]), 1)

    def test_x_gap_split_separates_stacked_different_columns(self):
        candidate = [
            {
                "x0": 86.4,
                "bbox": (86.4, 215.4, 326.1, 227.3),
                "max_size": 12,
                "line": {"spans": [{"text": "Identification of Beneficial Owner  (see instructions)"}]},
            },
            {
                "x0": 380.4,
                "bbox": (380.4, 227.9, 471.5, 237.5),
                "max_size": 12,
                "line": {"spans": [{"text": "2     Country of citizenship"}]},
            },
        ]

        groups = self.module._split_candidate_lines_by_x_gap(candidate, [], [])

        self.assertEqual(len(groups), 2)

    def test_x_gap_split_keeps_dot_leader_row_together(self):
        candidate = [
            {
                "x0": 36.0,
                "bbox": (36.0, 111.1, 146.2, 120.4),
                "max_size": 9,
                "line": {"spans": [{"text": "• You are NOT an individual ."}]},
            },
            {
                "x0": 156.0,
                "bbox": (156.0, 111.1, 158.2, 120.4),
                "max_size": 9,
                "line": {"spans": [{"text": "."}]},
            },
            {
                "x0": 528.0,
                "bbox": (528.0, 111.1, 577.6, 120.4),
                "max_size": 9,
                "line": {"spans": [{"text": ".  W-8BEN-E"}]},
            },
        ]

        groups = self.module._split_candidate_lines_by_x_gap(candidate, [], [])

        self.assertEqual(len(groups), 1)
        self.assertEqual(len(groups[0]), 3)

    def test_merge_same_row_lines_merges_left_number_with_right_text_despite_y_jitter(self):
        line_items = [
            {
                "line_num": 0,
                "line": {
                    "spans": [
                        {"text": "I certify that the beneficial owner is a resident of", "bbox": (64.8, 433.1, 235.8, 442.4), "size": 8},
                    ],
                },
                "bbox": (64.8, 433.1, 235.8, 442.4),
                "x0": 64.8,
                "y0": 433.1,
                "max_size": 8,
            },
            {
                "line_num": 1,
                "line": {
                    "spans": [
                        {"text": "9", "bbox": (45.95, 433.4, 50.4, 442.95), "size": 8},
                    ],
                },
                "bbox": (45.95, 433.4, 50.4, 442.95),
                "x0": 45.95,
                "y0": 433.4,
                "max_size": 8,
            },
        ]

        merged = self.module._merge_same_row_lines(line_items, [], [])

        self.assertEqual(len(merged), 1)
        self.assertEqual(
            [span["text"] for span in merged[0]["line"]["spans"]],
            ["9", "I certify that the beneficial owner is a resident of"],
        )

    def test_same_row_barrier_split_keeps_dot_leader_row_together(self):
        group_items = [
            {
                "x0": 36.0,
                "bbox": (36.0, 111.1, 146.2, 120.4),
                "max_size": 9,
                "line": {"spans": [{"text": "• You are NOT an individual ."}]},
            },
            {
                "x0": 156.0,
                "bbox": (156.0, 111.1, 158.2, 120.4),
                "max_size": 9,
                "line": {"spans": [{"text": "."}]},
            },
            {
                "x0": 528.0,
                "bbox": (528.0, 111.1, 577.6, 120.4),
                "max_size": 9,
                "line": {"spans": [{"text": ".  W-8BEN-E"}]},
            },
        ]

        groups = self.module._split_same_row_group_by_barriers(group_items, [], [])

        self.assertEqual(len(groups), 1)
        self.assertEqual(len(groups[0]), 3)

    def test_stacked_paragraph_merge_preserves_heading_like_followup_paragraphs(self):
        page_blocks = [
            {
                "block_num": 0,
                "left": 56.7,
                "top": 439.6,
                "width": 232.3,
                "height": 165.1,
                "line_count": 10,
                "avg_line_height": 12.0,
                "line_height": 12.0,
                "font": "Helvetica",
                "font_size": 12,
                "font_weight": "400",
                "bold": False,
                "italic": False,
                "hex_color": "#000000",
                "text": "Welcome to our first newsletter.\nBody line two.\nBody line three.",
                "text_lines": [
                    "Welcome to our first newsletter.",
                    "Body line two.",
                    "Body line three.",
                ],
                "line_bboxes": [
                    [56.7, 439.6, 289.0, 451.6],
                    [56.7, 456.4, 283.2, 468.4],
                    [56.7, 473.2, 287.5, 485.2],
                ],
                "source_content_ops": [],
            },
            {
                "block_num": 1,
                "left": 56.7,
                "top": 617.9,
                "width": 214.6,
                "height": 28.9,
                "line_count": 2,
                "avg_line_height": 12.0,
                "line_height": 12.0,
                "font": "Helvetica",
                "font_size": 12,
                "font_weight": "400",
                "bold": False,
                "italic": False,
                "hex_color": "#000000",
                "text": "New capital: The investment round was\nsuccessful.",
                "text_lines": [
                    "New capital: The investment round was",
                    "successful.",
                ],
                "line_bboxes": [
                    [56.7, 617.9, 271.3, 629.9],
                    [56.7, 634.9, 282.1, 646.9],
                ],
                "source_content_ops": [],
            },
        ]

        merged = self.module._merge_stacked_paragraph_blocks(page_blocks, [], [])

        self.assertEqual(len(merged), 2)

    def test_2026_593_header_uses_visible_trace_text_not_corrupted_dict_text(self):
        result = self.module.extract_text_with_pymupdf(str(FIXTURE_PATH))
        page0_blocks = result["extraction_data"][0]["blocks"]
        block_texts = [str(block.get("text") or "") for block in page0_blocks]

        self.assertIn("TAXABLE YEAR", block_texts)
        self.assertIn("2026", block_texts)
        self.assertIn("Real Estate Withholding Statement", block_texts)
        self.assertIn("CALIFORNIA FORM", block_texts)
        self.assertIn("593", block_texts)
        self.assertTrue(
            any("Part I Remitter Information" in text for text in block_texts)
        )
        self.assertIn("•  REEP   Qualified Intermediary   Buyer/Transferee   Other", block_texts)
        self.assertIn("AMENDED: •", block_texts)
        self.assertIn("Escrow or Exchange No.", block_texts)
        self.assertIn("Initial", block_texts)
        self.assertIn("Last name", block_texts)
        self.assertIn("Last name/Grantor", block_texts)

        self.assertNotIn(
            "TAXABLE YEAR 2026. Real Estate Withholding Statement. CALIFORNIA FORM",
            block_texts,
        )
        self.assertNotIn(
            ". Real Estate Withholding Statement. CALIFORNIA FORM 593",
            block_texts,
        )
        self.assertNotIn(
            "Part I Remitter Information    Part I Remitter Information",
            block_texts,
        )
        self.assertNotIn(
            "Part I Remitter Information •  REEP   Qualified Intermediary   Buyer/Transferee   Other",
            block_texts,
        )
        self.assertNotIn("AMENDED: • •", block_texts)
        self.assertNotIn(
            "Escrow or Exchange No. _________________________",
            block_texts,
        )
        self.assertNotIn("Initial Last name", block_texts)
        self.assertNotIn("Initial | Last name/Grantor", block_texts)

    def test_fw8ben_keeps_part_header_separate_and_preserves_part_ii_numbers(self):
        result = self.module.extract_text_with_pymupdf(str(FW8BEN_FIXTURE_PATH))
        page0 = result["extraction_data"][0]
        block_texts = [str(block.get("text") or "") for block in page0.get("blocks", [])]
        line_texts = [str(line.get("text") or "") for line in page0.get("lines", [])]
        normalized_blocks = [" ".join(text.split()) for text in block_texts]
        normalized_lines = [" ".join(text.split()) for text in line_texts]

        self.assertIn("Part I", normalized_blocks)
        self.assertIn("Identification of Beneficial Owner (see instructions)", normalized_blocks)
        self.assertIn("1 Name of individual who is the beneficial owner", normalized_blocks)
        self.assertFalse(
            any("Part I" in text and "1 Name of individual who is the beneficial owner" in text for text in normalized_blocks),
            f"Part I should not be grouped with line 1: {normalized_blocks}",
        )
        self.assertIn(
            "9 I certify that the beneficial owner is a resident of",
            normalized_lines,
        )
        self.assertIn(
            "10 Special rates and conditions (if applicable—see instructions): The beneficial owner is claiming the provisions of Article and paragraph",
            normalized_lines,
        )


if __name__ == "__main__":
    unittest.main()
