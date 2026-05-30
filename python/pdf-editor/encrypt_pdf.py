#!/usr/bin/env python3
import argparse
import json
import os
import sys
import fitz


def encryption_constant(algorithm: str) -> int:
    if algorithm == "aes-256":
        return fitz.PDF_ENCRYPT_AES_256
    return fitz.PDF_ENCRYPT_AES_128


def main() -> int:
    parser = argparse.ArgumentParser(description="Encrypt a PDF with an opening password")
    parser.add_argument("input_pdf")
    parser.add_argument("output_pdf")
    parser.add_argument("--password", required=True)
    parser.add_argument("--algorithm", choices=["aes-128", "aes-256"], default="aes-128")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    try:
        if not os.path.exists(args.input_pdf):
            raise FileNotFoundError("Input PDF not found")
        if not args.password:
            raise ValueError("Password is required")

        doc = fitz.open(args.input_pdf)
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
        doc.save(
            args.output_pdf,
            garbage=4,
            deflate=True,
            encryption=encryption_constant(args.algorithm),
            owner_pw=args.password,
            user_pw=args.password,
            permissions=permissions,
        )
        page_count = doc.page_count
        doc.close()

        result = {
            "success": True,
            "algorithm": args.algorithm,
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
        result = {"success": False, "error": str(exc)}
        print(json.dumps(result) if args.json else result)
        return 1


if __name__ == "__main__":
    sys.exit(main())
