import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import fitz


PROJECT_ROOT = Path(__file__).resolve().parents[3]
ROTATE_SCRIPT = PROJECT_ROOT / "python" / "pdf-editor" / "rotate_pdf_page.py"
SS5_FIXTURE = PROJECT_ROOT / "public" / "ss-5.pdf"


class RotatePdfPageTest(unittest.TestCase):
    def run_rotation(self, source: Path, output: Path, degrees: int) -> None:
        result = subprocess.run(
            [sys.executable, str(ROTATE_SCRIPT), str(source), str(output), "1", str(degrees)],
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertIn("SUCCESS:", result.stdout)

    def test_ss5_right_rotation_preserves_page_coordinate_system_and_content(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            rotated_path = Path(temp_dir) / "ss-5-right.pdf"
            self.run_rotation(SS5_FIXTURE, rotated_path, 90)

            source = fitz.open(SS5_FIXTURE)
            rotated = fitz.open(rotated_path)
            try:
                self.assertEqual(len(rotated), len(source))
                self.assertEqual(rotated[0].rotation, 90)
                self.assertAlmostEqual(rotated[0].mediabox.width, source[0].mediabox.width, places=4)
                self.assertAlmostEqual(rotated[0].mediabox.height, source[0].mediabox.height, places=4)
                self.assertAlmostEqual(rotated[0].rect.width, source[0].rect.height, places=4)
                self.assertAlmostEqual(rotated[0].rect.height, source[0].rect.width, places=4)
                self.assertEqual(rotated[0].get_text("text"), source[0].get_text("text"))
                self.assertEqual(len(rotated[0].get_links()), len(source[0].get_links()))
            finally:
                rotated.close()
                source.close()

    def test_ss5_right_then_left_returns_page_to_zero_degrees(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            right_path = Path(temp_dir) / "ss-5-right.pdf"
            restored_path = Path(temp_dir) / "ss-5-restored.pdf"
            self.run_rotation(SS5_FIXTURE, right_path, 90)
            self.run_rotation(right_path, restored_path, -90)

            restored = fitz.open(restored_path)
            source = fitz.open(SS5_FIXTURE)
            try:
                self.assertEqual(restored[0].rotation, 0)
                self.assertEqual(restored[0].get_text("text"), source[0].get_text("text"))
                self.assertAlmostEqual(restored[0].rect.width, source[0].rect.width, places=4)
                self.assertAlmostEqual(restored[0].rect.height, source[0].rect.height, places=4)
            finally:
                source.close()
                restored.close()


if __name__ == "__main__":
    unittest.main()
