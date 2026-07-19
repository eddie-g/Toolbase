import json
import subprocess
import tempfile
import unittest
from pathlib import Path

import fitz


ROOT = Path(__file__).resolve().parents[2]
GENERATOR = ROOT / "python" / "pdf-editor" / "generate_template.py"


def generate_security_deposit(payload):
    data = {
        "template_type": "realestate",
        "template_slug": "security_deposit_return",
        **payload,
    }
    temp = tempfile.NamedTemporaryFile(suffix=".pdf", delete=False)
    temp.close()
    output = Path(temp.name)
    subprocess.run(
        ["python3", str(GENERATOR), str(output)],
        input=json.dumps(data),
        text=True,
        check=True,
        capture_output=True,
        cwd=ROOT,
    )
    return output


def widget_locations(document):
    locations = {}
    for page_index, page in enumerate(document):
        for widget in page.widgets() or []:
            locations[widget.field_name] = (page_index, tuple(widget.rect))
    return locations


class SecurityDepositReturnLayoutTest(unittest.TestCase):
    def test_added_deduction_rows_push_following_content_onto_new_pages(self):
        base_path = generate_security_deposit({
            "deductions": [{"type": "Cleaning", "description": "Base row", "cost": "10"}],
        })
        expanded_path = generate_security_deposit({
            "deductions": [
                {"type": "Cleaning", "description": "Base row", "cost": "10"},
                {"type": "Repair", "description": "Added row", "cost": "20"},
            ],
        })
        try:
            with fitz.open(base_path) as base, fitz.open(expanded_path) as expanded:
                base_widgets = widget_locations(base)
                expanded_widgets = widget_locations(expanded)

                self.assertEqual(1, base.page_count)
                self.assertGreater(expanded.page_count, base.page_count)
                self.assertEqual(0, base_widgets["sdr_signature_landlord"][0])
                self.assertGreater(expanded_widgets["sdr_signature_landlord"][0], 0)
                self.assertIn("sdr_deduction_1_cost", expanded_widgets)
        finally:
            base_path.unlink(missing_ok=True)
            expanded_path.unlink(missing_ok=True)

    def test_custom_property_rows_and_many_deductions_flow_across_pages(self):
        property_rows = [
            {"label": f"Property label {index + 1}", "value": f"Property value {index + 1}"}
            for index in range(30)
        ]
        deductions = [
            {"type": "Repair", "description": f"Deduction {index + 1}", "cost": str(index + 1)}
            for index in range(60)
        ]
        output = generate_security_deposit({
            "property_rows": property_rows,
            "deductions": deductions,
        })
        try:
            with fitz.open(output) as document:
                widgets = widget_locations(document)

                self.assertGreaterEqual(document.page_count, 4)
                self.assertIn("sdr_property_row_29_label", widgets)
                self.assertIn("sdr_property_row_29_value", widgets)
                self.assertIn("sdr_deduction_59_cost", widgets)
                self.assertGreater(widgets["sdr_deduction_59_cost"][0], 0)
                self.assertGreaterEqual(
                    widgets["sdr_summary_total_deductions"][0],
                    widgets["sdr_deduction_59_cost"][0],
                )
                self.assertGreaterEqual(
                    widgets["sdr_signature_landlord"][0],
                    widgets["sdr_summary_total_deductions"][0],
                )
        finally:
            output.unlink(missing_ok=True)


if __name__ == "__main__":
    unittest.main()
