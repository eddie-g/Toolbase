#!/usr/bin/env python3

import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest

import fitz


PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
SCRIPT_PATH = PROJECT_ROOT / "python" / "pdf-editor" / "encrypt_pdf.py"


class EncryptPdfTests(unittest.TestCase):
    def create_pdf(self, path: pathlib.Path) -> None:
        document = fitz.open()
        page = document.new_page(width=300, height=200)
        page.insert_text((40, 80), "Password regression", fontsize=14)
        document.save(path)
        document.close()

    def run_action(
        self,
        input_path: pathlib.Path,
        output_path: pathlib.Path,
        *,
        action: str,
        password: str = "",
        current_password: str = "",
    ) -> tuple[subprocess.CompletedProcess[str], dict]:
        password_path = output_path.with_suffix(".password.json")
        password_path.write_text(
            json.dumps({
                "password": password,
                "current_password": current_password,
            }),
            encoding="utf-8",
        )
        os.chmod(password_path, 0o600)
        try:
            completed = subprocess.run(
                [
                    sys.executable,
                    str(SCRIPT_PATH),
                    str(input_path),
                    str(output_path),
                    "--action",
                    action,
                    "--password-file",
                    str(password_path),
                    "--algorithm",
                    "aes-128",
                    "--json",
                ],
                check=False,
                capture_output=True,
                text=True,
            )
        finally:
            password_path.unlink(missing_ok=True)

        lines = [line for line in completed.stdout.splitlines() if line.strip()]
        result = json.loads(lines[-1]) if lines else {}
        return completed, result

    def test_set_update_and_remove_password_round_trip(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            source = temp_path / "source.pdf"
            protected = temp_path / "protected.pdf"
            updated = temp_path / "updated.pdf"
            unlocked = temp_path / "unlocked.pdf"
            self.create_pdf(source)

            set_process, set_result = self.run_action(
                source,
                protected,
                action="set",
                password="first-secret",
            )
            self.assertEqual(0, set_process.returncode, set_process.stderr)
            self.assertTrue(set_result["success"])
            self.assertTrue(set_result["encrypted"])
            self.assertFalse(set_result["source_was_encrypted"])

            protected_document = fitz.open(protected)
            self.assertTrue(protected_document.needs_pass)
            self.assertEqual(0, protected_document.authenticate("wrong-secret"))
            self.assertGreater(protected_document.authenticate("first-secret"), 0)
            protected_document.close()

            update_process, update_result = self.run_action(
                protected,
                updated,
                action="set",
                password="second-secret",
                current_password="first-secret",
            )
            self.assertEqual(0, update_process.returncode, update_process.stderr)
            self.assertTrue(update_result["success"])
            self.assertTrue(update_result["source_was_encrypted"])

            updated_document = fitz.open(updated)
            self.assertEqual(0, updated_document.authenticate("first-secret"))
            self.assertGreater(updated_document.authenticate("second-secret"), 0)
            updated_document.close()

            remove_process, remove_result = self.run_action(
                updated,
                unlocked,
                action="remove",
                current_password="second-secret",
            )
            self.assertEqual(0, remove_process.returncode, remove_process.stderr)
            self.assertTrue(remove_result["success"])
            self.assertFalse(remove_result["encrypted"])

            unlocked_document = fitz.open(unlocked)
            try:
                self.assertFalse(unlocked_document.needs_pass)
                self.assertIn("Password regression", unlocked_document[0].get_text())
            finally:
                unlocked_document.close()

    def test_wrong_current_password_is_rejected_without_output(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            source = temp_path / "source.pdf"
            protected = temp_path / "protected.pdf"
            output = temp_path / "output.pdf"
            self.create_pdf(source)
            set_process, _ = self.run_action(
                source,
                protected,
                action="set",
                password="correct-secret",
            )
            self.assertEqual(0, set_process.returncode)

            process, result = self.run_action(
                protected,
                output,
                action="remove",
                current_password="incorrect-secret",
            )

            self.assertNotEqual(0, process.returncode)
            self.assertFalse(result["success"])
            self.assertEqual("incorrect_password", result["code"])
            self.assertFalse(output.exists())

    def test_remove_rejects_an_unprotected_pdf(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            source = temp_path / "source.pdf"
            output = temp_path / "output.pdf"
            self.create_pdf(source)

            process, result = self.run_action(
                source,
                output,
                action="remove",
                current_password="anything",
            )

            self.assertNotEqual(0, process.returncode)
            self.assertFalse(result["success"])
            self.assertEqual("not_encrypted", result["code"])
            self.assertFalse(output.exists())


if __name__ == "__main__":
    unittest.main()
