#!/usr/bin/env python3

import importlib.util
import pathlib
import sys
import unittest

import fitz


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "pdf-editor" / "extract_pdf_pymupdf.py"
FIXTURE_PATH = pathlib.Path(__file__).resolve().parents[2] / "public" / "2026-593.pdf"
FW8BEN_FIXTURE_PATH = pathlib.Path(__file__).resolve().parents[2] / "public" / "fw8ben.pdf"
DRYLAB_FIXTURE_PATH = pathlib.Path(__file__).resolve().parents[2] / "public" / "drylab_page_1.pdf"


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

    def test_drawn_underline_segments_keep_partial_span_geometry(self):
        bbox = (18.0, 346.84, 595.841, 357.29)
        horizontal_lines = [
            (159.26, 323.132, 355.905, 0.37),
            # A short decorative stroke does not qualify as this span's
            # underline and must not be attached to the moved annotation.
            (25.0, 35.0, 355.9, 0.5),
        ]

        segments = self.module._span_drawn_underline_segments(
            bbox,
            horizontal_lines,
            origin=(18.0, 354.892),
            font_size=10.45,
        )

        self.assertEqual(len(segments), 1)
        self.assertAlmostEqual(segments[0]["x0"], 159.26)
        self.assertAlmostEqual(segments[0]["x1"], 323.132)
        self.assertAlmostEqual(segments[0]["y"], 355.905)
        self.assertAlmostEqual(segments[0]["width"], 0.37)
        self.assertTrue(self.module._span_has_drawn_underline(
            bbox,
            horizontal_lines,
            origin=(18.0, 354.892),
            font_size=10.45,
        ))

    def test_drawn_underline_segments_reject_f1040_form_rule_below_text(self):
        bbox = (64.799995, 601.57782, 434.952057, 610.57782)
        horizontal_lines = [
            (35.75, 302.65, 612.0, 1.0),
            (302.15, 417.85, 612.0, 1.0),
            (417.35, 576.25, 612.0, 1.0),
        ]

        segments = self.module._span_drawn_underline_segments(
            bbox,
            horizontal_lines,
            origin=(64.799995, 608.926025),
            font_size=9,
        )

        self.assertEqual(segments, [])
        self.assertFalse(self.module._span_has_drawn_underline(
            bbox,
            horizontal_lines,
            origin=(64.799995, 608.926025),
            font_size=9,
        ))

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

    def test_merge_same_row_lines_merges_close_bare_subitem_label_with_row_text(self):
        line_items = [
            {
                "line_num": 0,
                "line": {
                    "spans": [
                        {"text": "a", "bbox": (40.0, 100.0, 45.0, 110.0), "size": 10},
                    ],
                },
                "bbox": (40.0, 100.0, 45.0, 110.0),
                "x0": 40.0,
                "y0": 100.0,
                "max_size": 10,
            },
            {
                "line_num": 1,
                "line": {
                    "spans": [
                        {"text": "General business credit. Attach Form 3800", "bbox": (59.0, 100.2, 258.0, 110.2), "size": 10},
                    ],
                },
                "bbox": (59.0, 100.2, 258.0, 110.2),
                "x0": 59.0,
                "y0": 100.2,
                "max_size": 10,
            },
        ]

        merged = self.module._merge_same_row_lines(line_items, [], [])

        self.assertEqual(len(merged), 1)
        self.assertEqual(
            [span["text"] for span in merged[0]["line"]["spans"]],
            ["a", "General business credit. Attach Form 3800"],
        )

    def test_merge_same_row_lines_keeps_bare_subitem_label_separate_across_wide_gutter(self):
        line_items = [
            {
                "line_num": 0,
                "line": {
                    "spans": [
                        {"text": "b", "bbox": (170.3, 100.0, 178.6, 113.5), "size": 10},
                    ],
                },
                "bbox": (170.3, 100.0, 178.6, 113.5),
                "x0": 170.3,
                "y0": 100.0,
                "max_size": 10,
            },
            {
                "line_num": 1,
                "line": {
                    "spans": [
                        {"text": "Household employee wages not reported on Form(s) W-2", "bbox": (195.3, 100.1, 518.0, 113.6), "size": 10},
                    ],
                },
                "bbox": (195.3, 100.1, 518.0, 113.6),
                "x0": 195.3,
                "y0": 100.1,
                "max_size": 10,
            },
        ]

        merged = self.module._merge_same_row_lines(line_items, [], [])

        self.assertEqual(len(merged), 2)
        self.assertEqual(
            [''.join(span["text"] for span in item["line"]["spans"]) for item in merged],
            ["b", "Household employee wages not reported on Form(s) W-2"],
        )

    def test_merge_same_row_lines_merges_bare_subitem_label_when_text_y_sorts_first(self):
        line_items = [
            {
                "line_num": 0,
                "line": {
                    "spans": [
                        {"text": "District of Columbia first-time homebuyer credit", "bbox": (60.0, 100.0, 292.0, 110.0), "size": 10},
                    ],
                },
                "bbox": (60.0, 100.0, 292.0, 110.0),
                "x0": 60.0,
                "y0": 100.0,
                "max_size": 10,
            },
            {
                "line_num": 1,
                "line": {
                    "spans": [
                        {"text": "h", "bbox": (42.0, 100.2, 47.0, 110.2), "size": 10},
                    ],
                },
                "bbox": (42.0, 100.2, 47.0, 110.2),
                "x0": 42.0,
                "y0": 100.2,
                "max_size": 10,
            },
        ]

        merged = self.module._merge_same_row_lines(line_items, [], [])

        self.assertEqual(len(merged), 1)
        self.assertEqual(
            [span["text"] for span in merged[0]["line"]["spans"]],
            ["h", "District of Columbia first-time homebuyer credit"],
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

    def test_widget_overlap_preserves_url_like_span_on_wide_widget_rect(self):
        self.assertFalse(
            self.module._should_skip_widget_intersecting_span(
                "www.irs.gov/businesses/get-a-business-tax-transcript",
                (360.0, 372.0, 548.0, 380.0),
                [(356.4, 370.8, 550.8, 381.6)],
            )
        )

    def test_widget_overlap_still_skips_short_compact_widget_ghost_text(self):
        self.assertTrue(
            self.module._should_skip_widget_intersecting_span(
                "X",
                (100.0, 100.0, 112.0, 112.0),
                [(99.0, 99.0, 113.0, 113.0)],
            )
        )

    def test_build_missing_link_span_for_line_uses_link_annotation_uri(self):
        link_regions = [{
            "index": 0,
            "rect": fitz.Rect(356.4, 370.8, 550.8, 381.6),
            "uri": "https://www.irs.gov/businesses/get-a-business-tax-transcript",
            "kind": "2",
            "page": None,
        }]
        synthetic = self.module._build_missing_link_span_for_line(
            "list of the tax return transcripts that are available for a business can be found at .",
            (64.7, 372.3, 556.5, 379.7),
            [{
                "bbox": [64.7, 372.3, 359.3, 379.7],
                "text": "list of the tax return transcripts that are available for a business can be found at ",
            }, {
                "bbox": [552.1, 377.2, 556.5, 378.1],
                "text": ". ",
            }],
            {
                "font": "HelveticaNeueLTStd-Roman",
                "font_size": 8,
                "font_weight": "400",
                "color": 0,
                "hex_color": "#000000",
                "direction": [1, 0],
                "writing_mode": 0,
                "rotation": 0,
            },
            link_regions,
            set(),
        )

        self.assertIsNotNone(synthetic)
        self.assertEqual(
            synthetic["text"],
            "www.irs.gov/businesses/get-a-business-tax-transcript",
        )
        self.assertTrue(synthetic["is_link"])
        self.assertEqual(
            synthetic["link_uri"],
            "https://www.irs.gov/businesses/get-a-business-tax-transcript",
        )

    def test_build_missing_link_span_for_line_requires_inline_link_trigger_phrase(self):
        synthetic = self.module._build_missing_link_span_for_line(
            "Transcript. Available for current year and 3 prior tax years.",
            (64.7, 372.3, 556.5, 379.7),
            [{
                "bbox": [64.7, 372.3, 320.0, 379.7],
                "text": "Transcript. Available for current year and 3 prior tax years.",
            }],
            {
                "font": "HelveticaNeueLTStd-Roman",
                "font_size": 8,
            },
            [{
                "index": 0,
                "rect": fitz.Rect(356.4, 370.8, 550.8, 381.6),
                "uri": "https://www.irs.gov/businesses/get-a-business-tax-transcript",
            }],
            set(),
        )

        self.assertIsNone(synthetic)

    def test_build_missing_link_span_for_line_skips_when_line_already_has_link_span(self):
        synthetic = self.module._build_missing_link_span_for_line(
            "list of the tax return transcripts can be found at .",
            (64.7, 372.3, 556.5, 379.7),
            [{
                "bbox": [356.4, 370.8, 550.8, 381.6],
                "text": "www.irs.gov/businesses/get-a-business-tax-transcript",
                "is_link": True,
            }],
            {
                "font": "HelveticaNeueLTStd-Roman",
                "font_size": 8,
            },
            [{
                "index": 0,
                "rect": fitz.Rect(356.4, 370.8, 550.8, 381.6),
                "uri": "https://www.irs.gov/businesses/get-a-business-tax-transcript",
            }],
            set(),
        )

        self.assertIsNone(synthetic)

    def test_trace_rebuild_dedupes_overlapped_footer_glyph_layers(self):
        doc = fitz.open(DRYLAB_FIXTURE_PATH)
        page = doc[0]
        trace_chars = self.module._build_texttrace_char_records(page)
        widget_rects = self.module._collect_widget_rects(page)
        raw = page.get_text("dict")

        rebuilt = {}
        for block in raw.get("blocks", []):
            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    text = span.get("text", "")
                    if text not in {"meetings", "NY · SF", "LA · L", "LA · LV"}:
                        continue
                    rebuilt[text, tuple(span.get("bbox", ()))] = self.module._rebuild_visible_text_from_trace_bbox(
                        trace_chars,
                        span.get("bbox"),
                        span.get("font"),
                        span.get("size"),
                        widget_rects,
                    )["text"]

        self.assertIn("meetings", rebuilt.values())
        self.assertIn("NY · SF", rebuilt.values())
        self.assertIn("LA · L", rebuilt.values())
        self.assertIn("LA · LV", rebuilt.values())
        for value in rebuilt.values():
            self.assertNotIn("mmeeeettiinnggss", value)
            self.assertNotIn("NNYY", value)
            self.assertNotIn("LLAA", value)

    def test_block_line_dedupe_prefers_richer_overlapped_layer(self):
        block = {
            "text_lines": ["meetings", "meetings", "NY · SF", "NY · SF", "LA · L", "LA · LV"],
            "line_bboxes": [
                [56.69, 706.29, 112.68, 723.09],
                [56.97, 706.29, 112.95, 723.09],
                [62.70, 724.29, 106.66, 741.09],
                [62.98, 724.29, 106.94, 741.09],
                [63.99, 742.29, 97.13, 759.09],
                [64.27, 742.29, 105.37, 759.09],
            ],
            "spans": [
                {"text": "meetings", "bbox": [56.69, 706.29, 112.68, 723.09], "font": "PdbpbbLato-Regular", "font_size": 14},
                {"text": "meetings", "bbox": [56.97, 706.29, 112.95, 723.09], "font": "PdbpbbLato-Regular", "font_size": 14},
                {"text": "NY · SF", "bbox": [62.70, 724.29, 106.66, 741.09], "font": "PdbpbbLato-Regular", "font_size": 14},
                {"text": "NY · SF", "bbox": [62.98, 724.29, 106.94, 741.09], "font": "PdbpbbLato-Regular", "font_size": 14},
                {"text": "LA · L", "bbox": [63.99, 742.29, 97.13, 759.09], "font": "PdbpbbLato-Regular", "font_size": 14},
                {"text": "LA · LV", "bbox": [64.27, 742.29, 105.37, 759.09], "font": "PdbpbbLato-Regular", "font_size": 14},
            ],
        }

        deduped = self.module._dedupe_block_text_lines(block)

        self.assertEqual(deduped["text_lines"], ["meetings", "NY · SF", "LA · LV"])
        self.assertEqual(deduped["text"], "meetings\nNY · SF\nLA · LV")
        self.assertEqual([span["text"] for span in deduped["spans"]], ["meetings", "NY · SF", "LA · LV"])

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

    def test_numbered_list_item_starts_new_block_before_following_note_paragraph(self):
        current_groups = [[{
            "bbox": (28.0, 100.0, 520.0, 118.0),
            "line": {"spans": [{"text": "9. I received the refund check and signed it."}]},
        }]]
        candidate_group = [{
            "bbox": (28.0, 121.0, 560.0, 156.0),
            "line": {"spans": [{"text": "NOTE: The law doesn't allow us to issue a replacement check if you endorsed it."}]},
        }]

        self.assertTrue(
            self.module._should_start_new_form_block(current_groups, candidate_group, [], [], []),
        )

    def test_numbered_list_item_keeps_attached_followup_line_without_callout_label(self):
        current_groups = [[{
            "bbox": (28.0, 100.0, 520.0, 118.0),
            "line": {"spans": [{"text": "9. I received the refund check and signed it."}]},
        }]]
        candidate_group = [{
            "bbox": (52.0, 121.0, 560.0, 139.0),
            "line": {"spans": [{"text": "Someone other than you cashed the check."}]},
        }]

        self.assertFalse(
            self.module._should_start_new_form_block(current_groups, candidate_group, [], [], []),
        )

    def test_split_blocks_on_list_marker_callouts_splits_number_markers_and_note(self):
        page_blocks = [{
            "block_num": 15,
            "font": "ArialMT",
            "font_size": 9,
            "font_weight": 400,
            "bold": False,
            "italic": False,
            "hex_color": "#000000",
            "text_lines": [
                "8.",
                "9.",
                "NOTE:  The law doesn’t allow us to issue a replacement check if you endorsed it and someone other than you cashed the check, since",
                "that person didn’t forge your signature.",
            ],
            "line_bboxes": [
                [36.0, 575.29, 43.50, 584.29],
                [36.0, 589.69, 43.50, 598.69],
                [35.99, 605.89, 572.74, 615.14],
                [35.99, 616.69, 190.07, 625.69],
            ],
            "spans": [
                {"text": "8.", "bbox": [36.0, 575.29, 43.50, 584.29], "font": "ArialMT", "font_size": 9, "bold": False, "italic": False},
                {"text": "9.", "bbox": [36.0, 589.69, 43.50, 598.69], "font": "ArialMT", "font_size": 9, "bold": False, "italic": False},
                {"text": "NOTE: ", "bbox": [35.99, 606.14, 66.49, 615.14], "font": "Arial-BoldMT", "font_size": 9, "bold": True, "italic": False},
                {"text": "The law doesn’t allow us to issue a replacement check if you endorsed it and someone other than you cashed the check, since ", "bbox": [66.49, 605.89, 572.74, 614.89], "font": "ArialMT", "font_size": 9, "bold": False, "italic": False},
                {"text": "that person didn’t forge your signature.", "bbox": [35.99, 616.69, 190.07, 625.69], "font": "ArialMT", "font_size": 9, "bold": False, "italic": False},
            ],
            "source_content_ops": [],
        }]
        page_lines = [
            {"text": "8.", "left": 36.0, "top": 575.29, "width": 7.5, "height": 9.0, "block_num": 15},
            {"text": "9.", "left": 36.0, "top": 589.69, "width": 7.5, "height": 9.0, "block_num": 15},
            {"text": "NOTE:  The law doesn’t allow us to issue a replacement check if you endorsed it and someone other than you cashed the check, since", "left": 35.99, "top": 605.89, "width": 536.75, "height": 9.25, "block_num": 15},
            {"text": "that person didn’t forge your signature.", "left": 35.99, "top": 616.69, "width": 154.08, "height": 9.0, "block_num": 15},
        ]
        page_words = [
            {"text": "8.", "left": 36.0, "top": 575.29, "width": 7.5, "height": 9.0, "block_num": 15},
            {"text": "9.", "left": 36.0, "top": 589.69, "width": 7.5, "height": 9.0, "block_num": 15},
            {"text": "NOTE:", "left": 35.99, "top": 606.14, "width": 30.5, "height": 9.0, "block_num": 15},
            {"text": "The", "left": 66.49, "top": 605.89, "width": 15.0, "height": 9.0, "block_num": 15},
            {"text": "that", "left": 35.99, "top": 616.69, "width": 18.0, "height": 9.0, "block_num": 15},
        ]

        split_blocks = self.module._split_blocks_on_list_marker_callouts(page_blocks, page_words, page_lines)

        self.assertEqual([block["text_lines"] for block in split_blocks], [
            ["8."],
            ["9."],
            [
                "NOTE:  The law doesn’t allow us to issue a replacement check if you endorsed it and someone other than you cashed the check, since",
                "that person didn’t forge your signature.",
            ],
        ])
        self.assertEqual([line["block_num"] for line in page_lines], [0, 1, 2, 2])
        self.assertEqual([word["block_num"] for word in page_words], [0, 1, 2, 2, 2])

    def test_split_block_on_horizontal_barriers_and_heading_label(self):
        block = {
            "block_num": 2,
            "font": "TimesNewRomanPSMT",
            "font_size": 12,
            "font_weight": 400,
            "bold": False,
            "italic": False,
            "hex_color": "#000000",
            "text_lines": [
                "Note : A supplemental claim is a new review of an issue previously decided by VA based on",
                "submission of new and relevant evidence.",
                "This form should only be",
                "used if you DISAGREE with a decision you received.",
                "• If you feel your condition has worsened and is no longer accurately reflected by VA, use",
                "VA Form 21-526EZ to request an increased evaluation.",
                "You may also submit your claim online.",
                "• If you want to file a request for higher-level review, use VA Form 20-0996.",
                "• You can also appeal to the BVA by using VA Form 10182.",
                "For additional information on these different options, visit www.va.gov/decision-reviews/.",
                "Where to Submit This Form :",
                "Submit your supplemental claim to the address shown below.",
                "Keep a copy of all completed forms and materials you give to VA.",
            ],
            "line_bboxes": [
                [29.95, 218.47, 520.00, 228.47],
                [29.95, 229.27, 400.00, 239.27],
                [29.95, 240.07, 220.00, 250.07],
                [29.95, 250.87, 260.00, 260.87],
                [43.45, 272.44, 510.00, 282.44],
                [29.95, 283.35, 430.00, 293.35],
                [29.95, 294.15, 280.00, 304.15],
                [29.95, 315.73, 470.00, 325.73],
                [43.45, 337.41, 430.00, 347.41],
                [29.95, 359.12, 360.00, 369.12],
                [29.95, 379.52, 180.00, 389.52],
                [30.60, 406.93, 420.00, 416.93],
                [30.60, 417.73, 430.00, 427.73],
            ],
            "spans": [
                {"text": "Note : A supplemental claim is a new review of an issue previously decided by VA based on", "bbox": [29.95, 218.47, 520.00, 228.47], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "submission of new and relevant evidence.", "bbox": [29.95, 229.27, 400.00, 239.27], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "This form should only be", "bbox": [29.95, 240.07, 220.00, 250.07], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "used if you DISAGREE with a decision you received.", "bbox": [29.95, 250.87, 260.00, 260.87], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "• If you feel your condition has worsened and is no longer accurately reflected by VA, use", "bbox": [43.45, 272.44, 510.00, 282.44], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "VA Form 21-526EZ to request an increased evaluation.", "bbox": [29.95, 283.35, 430.00, 293.35], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "You may also submit your claim online.", "bbox": [29.95, 294.15, 280.00, 304.15], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "• If you want to file a request for higher-level review, use VA Form 20-0996.", "bbox": [29.95, 315.73, 470.00, 325.73], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "• You can also appeal to the BVA by using VA Form 10182.", "bbox": [43.45, 337.41, 430.00, 347.41], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "For additional information on these different options, visit www.va.gov/decision-reviews/.", "bbox": [29.95, 359.12, 360.00, 369.12], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "Where to Submit This Form :", "bbox": [29.95, 379.52, 180.00, 389.52], "font": "TimesNewRomanPS-BoldMT", "font_size": 12, "bold": True, "italic": False},
                {"text": "Submit your supplemental claim to the address shown below.", "bbox": [30.60, 406.93, 420.00, 416.93], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
                {"text": "Keep a copy of all completed forms and materials you give to VA.", "bbox": [30.60, 417.73, 430.00, 427.73], "font": "TimesNewRomanPSMT", "font_size": 12, "bold": False, "italic": False},
            ],
            "source_content_ops": [],
        }

        horizontal_lines = [
            (28.71, 587.15, 264.60, 0.72),
            (28.71, 587.36, 312.20, 0.72),
            (29.21, 586.37, 335.72, 0.72),
            (28.71, 587.15, 356.50, 0.72),
        ]

        split_blocks = self.module._split_blocks_on_list_marker_callouts(
            [block],
            [],
            [],
            horizontal_lines=horizontal_lines,
        )

        self.assertEqual([segment["text_lines"] for segment in split_blocks], [
            [
                "Note : A supplemental claim is a new review of an issue previously decided by VA based on",
                "submission of new and relevant evidence.",
                "This form should only be",
                "used if you DISAGREE with a decision you received.",
            ],
            [
                "• If you feel your condition has worsened and is no longer accurately reflected by VA, use",
                "VA Form 21-526EZ to request an increased evaluation.",
                "You may also submit your claim online.",
            ],
            [
                "• If you want to file a request for higher-level review, use VA Form 20-0996.",
            ],
            [
                "• You can also appeal to the BVA by using VA Form 10182.",
            ],
            [
                "For additional information on these different options, visit www.va.gov/decision-reviews/.",
            ],
            [
                "Where to Submit This Form :",
            ],
            [
                "Submit your supplemental claim to the address shown below.",
                "Keep a copy of all completed forms and materials you give to VA.",
            ],
        ])


if __name__ == "__main__":
    unittest.main()
