#!/usr/bin/env python3
import argparse
import json
import os
import sys
import fitz


class PasswordActionError(RuntimeError):
    def __init__(self, message: str, code: str):
        super().__init__(message)
        self.code = code


def encryption_constant(algorithm: str) -> int:
    if algorithm == "aes-256":
        return fitz.PDF_ENCRYPT_AES_256
    return fitz.PDF_ENCRYPT_AES_128


def password_values(args: argparse.Namespace) -> tuple[str, str]:
    values: dict[str, object] = {}
    if args.password_file:
        with open(args.password_file, "r", encoding="utf-8") as handle:
            loaded = json.load(handle)
        if not isinstance(loaded, dict):
            raise ValueError("Password payload is invalid")
        values = loaded

    password = str(values.get("password") or args.password or "")
    current_password = str(
        values.get("current_password") or args.current_password or ""
    )
    return password, current_password


def main() -> int:
    parser = argparse.ArgumentParser(description="Set, update, or remove a PDF opening password")
    parser.add_argument("input_pdf")
    parser.add_argument("output_pdf")
    parser.add_argument("--action", choices=["set", "remove"], default="set")
    parser.add_argument("--password")
    parser.add_argument("--current-password")
    parser.add_argument("--password-file")
    parser.add_argument("--algorithm", choices=["aes-128", "aes-256"], default="aes-128")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    try:
        if not os.path.exists(args.input_pdf):
            raise FileNotFoundError("Input PDF not found")
        password, current_password = password_values(args)
        if args.action == "set" and not password:
            raise ValueError("Password is required")

        doc = fitz.open(args.input_pdf)
        was_encrypted = bool(doc.needs_pass)
        if was_encrypted:
            if not current_password:
                doc.close()
                raise PasswordActionError(
                    "Current password is required.",
                    "current_password_required",
                )
            if int(doc.authenticate(current_password)) <= 0:
                doc.close()
                raise PasswordActionError(
                    "Current password is incorrect.",
                    "incorrect_password",
                )
        elif args.action == "remove":
            doc.close()
            raise PasswordActionError(
                "The PDF is not password protected.",
                "not_encrypted",
            )

        page_count = doc.page_count
        permissions = (
            fitz.PDF_PERM_ACCESSIBILITY
            | fitz.PDF_PERM_PRINT
            | fitz.PDF_PERM_PRINT_HQ
            | fitz.PDF_PERM_COPY
            | fitz.PDF_PERM_ANNOTATE
            | fitz.PDF_PERM_FORM
            | fitz.PDF_PERM_MODIFY
            | fitz.PDF_PERM_ASSEMBLE
        )
        if args.action == "remove":
            doc.save(
                args.output_pdf,
                garbage=4,
                deflate=True,
                encryption=fitz.PDF_ENCRYPT_NONE,
            )
        else:
            doc.save(
                args.output_pdf,
                garbage=4,
                deflate=True,
                encryption=encryption_constant(args.algorithm),
                owner_pw=password,
                user_pw=password,
                permissions=permissions,
            )
        doc.close()

        verification_doc = fitz.open(args.output_pdf)
        output_encrypted = bool(verification_doc.needs_pass)
        if args.action == "set":
            authenticated = output_encrypted and int(verification_doc.authenticate(password)) > 0
            verification_doc.close()
            if not authenticated:
                raise RuntimeError("Encrypted PDF verification failed")
        else:
            verification_doc.close()
            if output_encrypted:
                raise RuntimeError("Password removal verification failed")

        result = {
            "success": True,
            "action": args.action,
            "algorithm": args.algorithm,
            "encrypted": output_encrypted,
            "source_was_encrypted": was_encrypted,
            "page_count": page_count,
            "file_size": os.path.getsize(args.output_pdf),
        }
        print(json.dumps(result) if args.json else result)
        return 0
    except Exception as exc:
        if os.path.exists(args.output_pdf):
            try:
                os.unlink(args.output_pdf)
            except OSError:
                pass
        result = {
            "success": False,
            "error": str(exc),
            "code": getattr(exc, "code", "password_action_failed"),
        }
        print(json.dumps(result) if args.json else result)
        return 1


if __name__ == "__main__":
    sys.exit(main())
