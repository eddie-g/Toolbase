#!/usr/bin/env python3
"""
PDF/A Compliance Test Runner

Runs every PDF in the Isartor PDFA-1b test suite through the PDF/A converter,
generates a compliance report for each, and outputs a JSON summary.

Usage:
    python3 run_compliance_tests.py [--test-dir DIR] [--output results.json] [--level 1b]
"""

import sys
import os
import json
import argparse
import tempfile
import traceback
from datetime import datetime, timezone

# Add parent directory so we can import convert_to_pdfa
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'python'))
from convert_to_pdfa import convert_to_pdfa, generate_compliance_report

# Known test descriptions from the Isartor test suite (all 204 tests)
TEST_DESCRIPTIONS = {
    # --- 6.1.2 File Header ---
    "isartor-6-1-2-t01-fail-a": "Document does not start with % character",
    "isartor-6-1-2-t02-fail-a": "File header line not followed by % and 4 characters > 127",
    # --- 6.1.3 File Trailer ---
    "isartor-6-1-3-t01-fail-a": "The trailer dictionary does not contain ID",
    "isartor-6-1-3-t02-fail-a": "Trailer dictionary contains Encrypt",
    "isartor-6-1-3-t03-fail-a": "Data after last EOF marker",
    "isartor-6-1-3-t04-fail-a": "Linearized file: ID in 1st page and last trailer different",
    # --- 6.1.4 Cross Reference Table ---
    "isartor-6-1-4-t01-fail-a": "Subsection header: starting object number and range not separated by a single space",
    "isartor-6-1-4-t02-fail-a": "'xref' and cross reference subsection header not separated by a single EOL marker",
    # --- 6.1.6 String Objects ---
    "isartor-6-1-6-t01-fail-a": "Invalid hexadecimal strings used",
    # --- 6.1.7 Stream Objects ---
    "isartor-6-1-7-t01-fail-a": "The 'stream' token is not followed by CR and LF or a single LF",
    "isartor-6-1-7-t02-fail-a": "The 'endstream' token is not preceded by EOL",
    "isartor-6-1-7-t03-fail-a": "The value of Length does not match the number of bytes",
    "isartor-6-1-7-t04-fail-a": "Stream with F used",
    "isartor-6-1-7-t04-fail-b": "Stream with F used; Stream with FFilter used",
    "isartor-6-1-7-t04-fail-c": "Stream with F used; Stream with FFilter used; Stream with FDecodeParms used",
    # --- 6.1.8 Indirect Objects ---
    "isartor-6-1-8-t01-fail-a": "Object number and generation number not separated by single white-space",
    "isartor-6-1-8-t02-fail-a": "Generation number and 'obj' not separated by single white-space",
    "isartor-6-1-8-t03-fail-a": "Object number not preceded by EOL marker",
    "isartor-6-1-8-t04-fail-a": "'endobj' not preceded by EOL marker",
    "isartor-6-1-8-t05-fail-a": "'obj' not followed by EOL marker",
    "isartor-6-1-8-t06-fail-a": "'endobj' not followed by EOL marker",
    # --- 6.1.10 Filters ---
    "isartor-6-1-10-t01-fail-a": "LZW compression used for image XObject",
    "isartor-6-1-10-t01-fail-b": "LZW compression used for inline image",
    "isartor-6-1-10-t01-fail-c": "LZW compression used in thumbnail",
    # --- 6.1.11 Embedded Files ---
    "isartor-6-1-11-t01-fail-a": "EmbeddedFiles shall not be used",
    "isartor-6-1-11-t02-fail-a": "EmbeddedFiles shall not be used; EF dictionary shall not be used",
    # --- 6.1.12 Implementation Limits ---
    "isartor-6-1-12-t01-fail-a": "Array contains more than 8191 elements",
    "isartor-6-1-12-t01-fail-b": "String length exceeds 65535 bytes",
    "isartor-6-1-12-t01-fail-c": "Name length exceeds 127 bytes",
    "isartor-6-1-12-t01-fail-d": "Number of indirect objects exceeds maximum",
    # --- 6.1.13 Optional Content ---
    "isartor-6-1-13-t01-fail-a": "Optional content (OCProperties) used",
    # --- 6.2.2 Output Intent ---
    "isartor-6-2-2-t01-fail-a": "No OutputIntents defined",
    "isartor-6-2-2-t02-fail-a": "OutputIntent subtype not GTS_PDFA1",
    "isartor-6-2-2-t02-fail-b": "OutputIntent DestOutputProfile missing",
    "isartor-6-2-2-t03-fail-a": "Multiple OutputIntents with different DestOutputProfileRef",
    # --- 6.2.3.3 Uncalibrated Colour Spaces ---
    "isartor-6-2-3-3-t01-fail-a": "Uncalibrated DeviceRGB color space used",
    "isartor-6-2-3-3-t02-fail-a": "Uncalibrated DeviceGray in page content",
    "isartor-6-2-3-3-t02-fail-b": "Uncalibrated DeviceGray in Type3 glyph",
    "isartor-6-2-3-3-t02-fail-c": "Uncalibrated DeviceCMYK in page content",
    "isartor-6-2-3-3-t02-fail-d": "Uncalibrated DeviceCMYK in Type3 glyph",
    "isartor-6-2-3-3-t02-fail-e": "Uncalibrated DeviceRGB in page content",
    "isartor-6-2-3-3-t02-fail-f": "Uncalibrated DeviceRGB in Type3 glyph",
    "isartor-6-2-3-3-t02-fail-g": "Uncalibrated DeviceGray in tiling pattern",
    "isartor-6-2-3-3-t02-fail-h": "Uncalibrated DeviceCMYK in tiling pattern",
    "isartor-6-2-3-3-t02-fail-i": "Uncalibrated DeviceRGB in tiling pattern",
    "isartor-6-2-3-3-t02-fail-j": "Uncalibrated DeviceGray in Form XObject",
    "isartor-6-2-3-3-t03-fail-a": "Uncalibrated DeviceCMYK in Form XObject",
    "isartor-6-2-3-3-t03-fail-b": "Uncalibrated DeviceRGB in Form XObject",
    "isartor-6-2-3-3-t03-fail-c": "Uncalibrated DeviceGray in image XObject",
    "isartor-6-2-3-3-t03-fail-d": "Uncalibrated DeviceCMYK in image XObject",
    "isartor-6-2-3-3-t03-fail-e": "Uncalibrated DeviceRGB in image XObject",
    "isartor-6-2-3-3-t04-fail-a": "Uncalibrated DeviceGray in annotations",
    "isartor-6-2-3-3-t04-fail-b": "Uncalibrated DeviceCMYK in annotations",
    "isartor-6-2-3-3-t04-fail-c": "Uncalibrated DeviceRGB in annotations",
    "isartor-6-2-3-3-t04-fail-d": "Uncalibrated DeviceGray in annotation AP",
    "isartor-6-2-3-3-t05-fail-a": "Alternate color space in ICCBased profile is uncalibrated",
    "isartor-6-2-3-3-t05-fail-b": "Alternate color space in ICCBased profile is uncalibrated (CMYK)",
    # --- 6.2.3.4 Separation and DeviceN Colour Spaces ---
    "isartor-6-2-3-4-t01-fail-a": "Separation color space with alternate not in OutputIntent",
    "isartor-6-2-3-4-t01-fail-b": "DeviceN color space with alternate not in OutputIntent",
    # --- 6.2.4 Images ---
    "isartor-6-2-4-t01-fail-a": "Image XObject with Alternates key",
    "isartor-6-2-4-t02-fail-a": "Image XObject with OPI key",
    "isartor-6-2-4-t03-fail-a": "Image XObject with Interpolate set to true",
    "isartor-6-2-4-t04-fail-a": "Inline image with Intent key",
    # --- 6.2.5 Form XObjects ---
    "isartor-6-2-5-t01-fail-a": "Form XObject with OPI key",
    # --- 6.2.6 Reference XObjects ---
    "isartor-6-2-6-t01-fail-a": "Reference XObject used",
    # --- 6.2.7 PostScript XObjects ---
    "isartor-6-2-7-t01-fail-a": "PostScript XObject in page content",
    "isartor-6-2-7-t02-fail-a": "PostScript XObject in Form XObject",
    # --- 6.2.8 Extended Graphics State ---
    "isartor-6-2-8-t01-fail-a": "ExtGState with TR used",
    "isartor-6-2-8-t01-fail-b": "ExtGState with TR2 used (not Default)",
    "isartor-6-2-8-t01-fail-c": "ExtGState with TR used in Form XObject",
    "isartor-6-2-8-t01-fail-d": "ExtGState with TR2 used in Form XObject (not Default)",
    "isartor-6-2-8-t02-fail-a": "ExtGState with HTP (halftone transfer) key",
    "isartor-6-2-8-t02-fail-b": "ExtGState with HT (halftone) key with non-Default value",
    "isartor-6-2-8-t02-fail-c": "ExtGState with HTP and HT keys combined",
    # --- 6.2.9 Rendering Intents ---
    "isartor-6-2-9-t01-fail-a": "Invalid rendering intent used",
    # --- 6.2.10 Content Streams ---
    "isartor-6-2-10-t01-fail-a": "Content stream with operator not in PDF Reference Table A.1",
    "isartor-6-2-10-t01-fail-b": "Content stream with BX/EX containing non-standard operator",
    "isartor-6-2-10-t01-fail-c": "Form XObject content stream with invalid operator",
    # --- 6.3.2 Font Types ---
    "isartor-6-3-2-t01-fail-a": "Font not embedded (Type1)",
    "isartor-6-3-2-t01-fail-b": "Font not embedded (TrueType)",
    "isartor-6-3-2-t01-fail-c": "Font not embedded (CIDFont Type0)",
    # --- 6.3.3 Font Subsets ---
    "isartor-6-3-3-t01-fail-a": "CIDFont subset missing CIDSet",
    "isartor-6-3-3-t01-fail-b": "TrueType subset font missing glyph for character",
    "isartor-6-3-3-t02-fail-a": "Embedded Type1 font: CharSet incomplete",
    "isartor-6-3-3-t03-fail-a": "CIDToGIDMap not Identity for CIDFont subset",
    "isartor-6-3-3-1-t01-fail-a": "Subset tag prefix missing from font name (Type1)",
    "isartor-6-3-3-1-t01-fail-b": "Subset tag prefix missing from font name (TrueType)",
    "isartor-6-3-3-2-t01-fail-a": "CIDFont missing CIDSet entry in font descriptor",
    "isartor-6-3-3-3-t01-fail-a": "CMap not one of predefined Identity CMaps",
    "isartor-6-3-3-3-t02-fail-a": "CMap references external CMap (UseCMap)",
    # --- 6.3.4 Embedded Font Programs ---
    "isartor-6-3-4-t01-fail-a": "Font uses .notdef glyph",
    "isartor-6-3-4-t01-fail-b": "Font program missing (Type1 non-standard font)",
    "isartor-6-3-4-t01-fail-c": "Font program missing (TrueType non-standard font)",
    "isartor-6-3-4-t01-fail-d": "Font program missing (CIDFont Type0 font)",
    "isartor-6-3-4-t01-fail-e": "Font program missing (CIDFont Type2 font)",
    "isartor-6-3-4-t01-fail-f": "Font program truncated (Type1 font)",
    "isartor-6-3-4-t01-fail-g": "Font program truncated (TrueType font)",
    "isartor-6-3-4-t01-fail-h": "Font program corrupted (invalid font data)",
    "isartor-6-3-4-t02-fail-a": "Character code maps to .notdef via ToUnicode",
    # --- 6.3.5 Font Subsets ---
    "isartor-6-3-5-t01-fail-a": "Symbolic TrueType has Encoding entry",
    "isartor-6-3-5-t01-fail-b": "Symbolic TrueType has Encoding with Differences array",
    "isartor-6-3-5-t01-fail-c": "Non-symbolic TrueType missing encoding (no WinAnsiEncoding or MacRomanEncoding)",
    "isartor-6-3-5-t01-fail-d": "Non-symbolic TrueType with custom Encoding Differences",
    "isartor-6-3-5-t02-fail-a": "Non-symbolic TrueType missing MacRomanEncoding or WinAnsiEncoding",
    "isartor-6-3-5-t03-fail-a": "Font subset CIDSet stream does not match actual glyph set",
    # --- 6.3.6 Font Metrics ---
    "isartor-6-3-6-t01-fail-a": "Non-symbolic TrueType missing cmap subtable 3,1 or 1,0",
    "isartor-6-3-6-t01-fail-b": "TrueType font Widths array inconsistent with glyph widths",
    "isartor-6-3-6-t01-fail-c": "Type1 font Widths array inconsistent with glyph widths",
    # --- 6.3.7 Character Encodings ---
    "isartor-6-3-7-t01-fail-a": "Font file missing from composite CIDFont",
    "isartor-6-3-7-t01-fail-b": "Font file missing from Type1 font",
    "isartor-6-3-7-t01-fail-c": "Font file missing from TrueType font",
    "isartor-6-3-7-t02-fail-a": "ToUnicode CMap missing for font without standard encoding",
    "isartor-6-3-7-t03-fail-a": "ToUnicode CMap contains invalid mapping entries",
    # --- 6.4 Transparency ---
    "isartor-6-4-t01-fail-a": "ExtGState with SMask not None",
    "isartor-6-4-t01-fail-b": "ExtGState with SMask referencing luminosity soft mask",
    "isartor-6-4-t02-fail-a": "Page Group transparency with S/Transparency",
    "isartor-6-4-t03-fail-a": "Image XObject with SMask reference",
    "isartor-6-4-t04-fail-a": "Blend mode not Normal or Compatible",
    "isartor-6-4-t05-fail-a": "Transparency group in Form XObject",
    # --- 6.5.2 Annotation Types ---
    "isartor-6-5-2-t01-fail-a": "Annotation missing AP entry",
    "isartor-6-5-2-t01-fail-b": "Annotation missing AP entry (Link annotation)",
    "isartor-6-5-2-t01-fail-c": "Annotation missing AP entry (FreeText annotation)",
    "isartor-6-5-2-t01-fail-d": "Annotation missing AP entry (Line annotation)",
    "isartor-6-5-2-t01-fail-e": "Annotation missing AP entry (Square annotation)",
    "isartor-6-5-2-t01-fail-f": "Annotation missing AP entry (Circle annotation)",
    "isartor-6-5-2-t01-fail-g": "Annotation missing AP entry (Highlight annotation)",
    "isartor-6-5-2-t01-fail-h": "Annotation missing AP entry (Stamp annotation)",
    "isartor-6-5-2-t02-fail-a": "Annotation missing F flag (bit 4 - Print)",
    "isartor-6-5-2-t02-fail-b": "Annotation with Invisible flag set (F bit 1)",
    "isartor-6-5-2-t02-fail-c": "Annotation with NoView flag set (F bit 5)",
    "isartor-6-5-2-t03-fail-a": "Annotation with Hidden flag set",
    "isartor-6-5-2-t04-fail-a": "Annotation type not permitted (FileAttachment)",
    "isartor-6-5-2-t04-fail-b": "Annotation type not permitted (Sound)",
    "isartor-6-5-2-t04-fail-c": "Annotation type not permitted (Movie)",
    # --- 6.5.3 Annotation Dictionaries ---
    "isartor-6-5-3-t01-fail-a": "Widget annotation CA not 1.0",
    "isartor-6-5-3-t02-fail-a": "Widget annotation with AA entry",
    "isartor-6-5-3-t02-fail-b": "Widget annotation with AA entry (E event)",
    "isartor-6-5-3-t02-fail-c": "Widget annotation with AA entry (X event)",
    "isartor-6-5-3-t02-fail-d": "Widget annotation with AA entry (D event)",
    "isartor-6-5-3-t02-fail-e": "Widget annotation with AA entry (U event)",
    "isartor-6-5-3-t03-fail-a": "Annotation AP dict: N entry missing",
    "isartor-6-5-3-t03-fail-b": "Annotation AP dict: N entry is not a stream",
    "isartor-6-5-3-t03-fail-c": "Annotation AP dict: R entry present but invalid",
    "isartor-6-5-3-t03-fail-d": "Annotation AP dict: D entry present but invalid",
    "isartor-6-5-3-t04-fail-a": "Annotation C (color) entry uses non-DeviceGray/DeviceRGB/DeviceCMYK",
    "isartor-6-5-3-t04-fail-b": "Annotation IC (interior color) entry uses non-standard color space",
    "isartor-6-5-3-t04-fail-c": "Annotation C entry with uncalibrated color space",
    "isartor-6-5-3-t04-fail-d": "Annotation IC entry with uncalibrated color space",
    # --- 6.6.1 Actions ---
    "isartor-6-6-1-t01-fail-a": "Named action not NextPage/PrevPage/FirstPage/LastPage",
    "isartor-6-6-1-t01-fail-b": "Named action Print not permitted",
    "isartor-6-6-1-t01-fail-c": "Named action SaveAs not permitted",
    "isartor-6-6-1-t01-fail-d": "Named action Quit not permitted",
    "isartor-6-6-1-t01-fail-e": "Named action Close not permitted",
    "isartor-6-6-1-t01-fail-f": "Named action Find not permitted",
    "isartor-6-6-1-t01-fail-g": "Named action GoToPage not permitted",
    "isartor-6-6-1-t01-fail-h": "Named action ZoomTo not permitted",
    "isartor-6-6-1-t01-fail-i": "Named action FullScreen not permitted",
    "isartor-6-6-1-t02-fail-a": "Action type set-state (deprecated)",
    "isartor-6-6-1-t02-fail-b": "Action type no-op (deprecated)",
    "isartor-6-6-1-t02-fail-c": "Action type Launch",
    "isartor-6-6-1-t02-fail-d": "Action type Sound",
    "isartor-6-6-1-t02-fail-e": "Action type Movie",
    "isartor-6-6-1-t02-fail-f": "Action type ResetForm",
    "isartor-6-6-1-t02-fail-g": "Action type ImportData",
    "isartor-6-6-1-t02-fail-h": "Action type Hide",
    "isartor-6-6-1-t02-fail-i": "Action type set-state in annotation",
    "isartor-6-6-1-t03-fail-a": "JavaScript action used",
    "isartor-6-6-1-t03-fail-b": "JavaScript action in annotation",
    "isartor-6-6-1-t03-fail-c": "JavaScript action in form field",
    "isartor-6-6-1-t03-fail-d": "JavaScript action in page additional actions",
    "isartor-6-6-1-t03-fail-e": "JavaScript action in document catalog additional actions",
    "isartor-6-6-1-t03-fail-f": "JavaScript action in Named dictionary",
    "isartor-6-6-1-t03-fail-g": "JavaScript action embedded in link annotation",
    "isartor-6-6-1-t03-fail-h": "JavaScript action with Next chain",
    "isartor-6-6-1-t03-fail-i": "JavaScript action in bookmark (outline item)",
    "isartor-6-6-1-t04-fail-a": "Launch action with external file reference",
    "isartor-6-6-1-t04-fail-b": "URI action with external URL",
    "isartor-6-6-1-t04-fail-c": "Sound action referencing external sound file",
    "isartor-6-6-1-t04-fail-d": "Movie action referencing external movie file",
    "isartor-6-6-1-t04-fail-e": "GoToR action referencing external PDF file",
    "isartor-6-6-1-t04-fail-f": "SubmitForm action with external URL",
    "isartor-6-6-1-t04-fail-g": "ImportData action referencing external FDF file",
    "isartor-6-6-1-t04-fail-h": "Launch action in annotation with external file",
    "isartor-6-6-1-t04-fail-i": "GoToE action referencing embedded file in external document",
    # --- 6.6.2 Additional Actions ---
    "isartor-6-6-2-t01-fail-a": "AA entry on catalog (document-level trigger)",
    "isartor-6-6-2-t02-fail-a": "AA entry on page",
    # --- 6.7.2 Metadata Properties ---
    "isartor-6-7-2-t01-fail-a": "Metadata stream: Bytes attribute present",
    "isartor-6-7-2-t02-fail-a": "Metadata stream filter used (non-identity)",
    "isartor-6-7-2-t02-fail-b": "Metadata stream with FlateDecode filter",
    "isartor-6-7-2-t02-fail-c": "Metadata stream with ASCIIHexDecode filter",
    "isartor-6-7-2-t03-fail-a": "Document information and XMP metadata mismatch",
    # --- 6.7.3 Document Information Dictionary ---
    "isartor-6-7-3-t01-fail-a": "XMP pdfaid:part missing",
    "isartor-6-7-3-t01-fail-b": "XMP dc:title missing when Info Title is present",
    "isartor-6-7-3-t01-fail-c": "XMP xmp:CreateDate missing when Info CreationDate is present",
    "isartor-6-7-3-t02-fail-a": "XMP pdfaid:conformance missing",
    "isartor-6-7-3-t03-fail-a": "XMP pdfaid:part value incorrect",
    "isartor-6-7-3-t04-fail-a": "XMP pdfaid:conformance value incorrect",
    # --- 6.7.5 XMP Header ---
    "isartor-6-7-5-t01-fail-a": "XMP metadata packet does not begin with xpacket processing instruction",
    "isartor-6-7-5-t02-fail-a": "XMP metadata packet missing proper encoding declaration",
    # --- 6.7.8 Extension Schemas ---
    "isartor-6-7-8-t01-fail-a": "XMP extension schema not properly declared",
    "isartor-6-7-8-t02-fail-a": "Extension schema: namespaceURI missing",
    "isartor-6-7-8-t02-fail-b": "Extension schema: prefix missing",
    "isartor-6-7-8-t02-fail-c": "Extension schema: property definition missing valueType",
    "isartor-6-7-8-t02-fail-d": "Extension schema: property definition missing description",
    "isartor-6-7-8-t02-fail-e": "Extension schema: property definition missing name",
    "isartor-6-7-8-t02-fail-f": "Extension schema: schema description missing",
    "isartor-6-7-8-t02-fail-g": "Extension schema: property uses unregistered namespace",
    "isartor-6-7-8-t02-fail-h": "Extension schema: property uses wrong valueType",
    "isartor-6-7-8-t02-fail-i": "Extension schema: duplicate schema definitions",
    "isartor-6-7-8-t02-fail-j": "Extension schema: duplicate property definitions",
    "isartor-6-7-8-t02-fail-k": "Extension schema: empty schemas bag",
    # --- 6.7.9 Validation ---
    "isartor-6-7-9-t01-fail-a": "XMP metadata is not well-formed XML",
    # --- 6.7.11 Version and Conformance Level Identification ---
    "isartor-6-7-11-t01-fail-a": "XMP Metadata not present",
    "isartor-6-7-11-t01-fail-b": "XMP pdfaid:part value is not an integer",
    "isartor-6-7-11-t01-fail-c": "XMP pdfaid:conformance value is empty",
    "isartor-6-7-11-t01-fail-d": "XMP pdfaid namespace not declared in metadata",
    # --- 6.9 Interactive Forms ---
    "isartor-6-9-t01-fail-a": "Interactive form with XFA entry",
    "isartor-6-9-t02-fail-a": "Interactive form without NeedAppearances set to true",
    "isartor-6-9-t02-fail-b": "Interactive form NeedAppearances explicitly set to false",
}


def derive_test_category(filename):
    """Derive the test category from the filename pattern like isartor-6-1-2-t01..."""
    parts = filename.replace('.pdf', '').split('-')
    # Pattern: isartor-6-X-Y-... => section 6.X
    if len(parts) >= 3 and parts[0] == 'isartor':
        section = f"{parts[1]}.{parts[2]}"
        if len(parts) >= 4 and not parts[3].startswith('t'):
            section += f".{parts[3]}"
        return section
    return "unknown"


SECTION_NAMES = {
    "6.1": "File Structure",
    "6.1.2": "File Header",
    "6.1.3": "File Trailer",
    "6.1.4": "Cross Reference Table",
    "6.1.6": "String Objects",
    "6.1.7": "Stream Objects",
    "6.1.8": "Indirect Objects",
    "6.1.10": "Filters",
    "6.1.11": "Embedded Files",
    "6.1.12": "Implementation Limits",
    "6.1.13": "Optional Content",
    "6.2": "Graphics",
    "6.2.2": "Output Intent",
    "6.2.3": "Colour Spaces",
    "6.2.4": "Images",
    "6.2.5": "Form XObjects",
    "6.2.6": "Reference XObjects",
    "6.2.7": "PostScript XObjects",
    "6.2.8": "Extended Graphics State",
    "6.2.9": "Rendering Intents",
    "6.2.10": "Content Streams",
    "6.3": "Fonts",
    "6.3.2": "Font Types",
    "6.3.3": "Font Subsets",
    "6.3.4": "Glyph Usage",
    "6.3.5": "Font Encoding",
    "6.3.6": "Font CMap",
    "6.3.7": "Embedded Font Programs",
    "6.4": "Transparency",
    "6.5": "Annotations",
    "6.5.2": "Annotation Flags",
    "6.5.3": "Widget Annotations",
    "6.6": "Actions",
    "6.6.1": "Action Types",
    "6.6.2": "Additional Actions",
    "6.7": "Metadata",
    "6.7.2": "Metadata Streams",
    "6.7.3": "PDF/A Identification",
    "6.7.11": "XMP Metadata",
    "6.9": "Interactive Forms",
}


def run_single_test(pdf_path, level='1b'):
    """
    Run a single PDF through the PDF/A converter and return the test result.
    
    Returns a dict with:
        filename, description, test_category, section_name,
        status ('pass'/'fail'/'error'), details (JSON), checks, error
    """
    filename = os.path.splitext(os.path.basename(pdf_path))[0]
    description = TEST_DESCRIPTIONS.get(filename, f"Unknown test: {filename}")
    section = derive_test_category(filename)
    section_name = SECTION_NAMES.get(section, section)
    
    result = {
        'filename': filename,
        'description': description,
        'test_category': section,
        'section_name': section_name,
        'status': 'error',
        'conversion_success': False,
        'checks': [],
        'checks_passed': 0,
        'checks_total': 0,
        'compliance_status': None,
        'error': None,
        'warnings': [],
        'file_size_input': 0,
        'file_size_output': 0,
    }
    
    try:
        result['file_size_input'] = os.path.getsize(pdf_path)
    except:
        pass
    
    # Create temp output file
    fd, temp_output = tempfile.mkstemp(suffix='.pdf', prefix='compliance_test_')
    os.close(fd)
    
    try:
        # Run conversion
        conv_result = convert_to_pdfa(
            pdf_path,
            temp_output,
            level=level,
            embed_fonts=True,
            srgb_profile=True
        )
        
        result['conversion_success'] = conv_result.get('success', False)
        result['warnings'] = conv_result.get('warnings', [])
        
        if conv_result.get('success') and conv_result.get('report'):
            report = conv_result['report']
            result['checks'] = report.get('checks', [])
            result['checks_passed'] = sum(1 for c in result['checks'] if c.get('result') == 'PASS')
            result['checks_total'] = len(result['checks'])
            result['compliance_status'] = report.get('status', 'Unknown')
            result['file_size_output'] = report.get('file_size', 0)
            
            # The test PASSES if the converter produced a compliant PDF/A
            # (i.e., it successfully fixed/handled the known defect)
            if report.get('status') == 'Compliant':
                result['status'] = 'pass'
            else:
                result['status'] = 'fail'
        else:
            # Conversion itself failed - the file was too broken to process
            result['status'] = 'fail'
            result['error'] = conv_result.get('error', 'Conversion returned no report')
    
    except Exception as e:
        result['status'] = 'fail'
        result['error'] = str(e)
        result['traceback'] = traceback.format_exc()
    
    finally:
        # Clean up temp file
        try:
            if os.path.exists(temp_output):
                os.unlink(temp_output)
        except:
            pass
    
    return result


def find_test_pdfs(test_dir):
    """Recursively find all PDF files in the test directory."""
    pdfs = []
    for root, dirs, files in os.walk(test_dir):
        for f in sorted(files):
            if f.lower().endswith('.pdf'):
                pdfs.append(os.path.join(root, f))
    return sorted(pdfs)


def run_all_tests(test_dir, level='1b'):
    """Run all tests and return results."""
    pdfs = find_test_pdfs(test_dir)
    
    if not pdfs:
        print(f"No PDF files found in {test_dir}", file=sys.stderr)
        return []
    
    results = []
    total = len(pdfs)
    passed = 0
    failed = 0
    errors = 0
    
    for idx, pdf_path in enumerate(pdfs, 1):
        filename = os.path.splitext(os.path.basename(pdf_path))[0]
        print(f"[{idx}/{total}] Testing: {filename}...", end=' ', flush=True, file=sys.stderr)
        
        result = run_single_test(pdf_path, level=level)
        results.append(result)
        
        if result['status'] == 'pass':
            passed += 1
            print("PASS", file=sys.stderr)
        elif result['status'] == 'fail':
            failed += 1
            print(f"FAIL ({result.get('error', 'non-compliant')})", file=sys.stderr)
        else:
            errors += 1
            print(f"ERROR ({result.get('error', 'unknown')})", file=sys.stderr)
    
    print(f"\n{'='*60}", file=sys.stderr)
    print(f"Results: {passed} passed, {failed} failed, {errors} errors out of {total}", file=sys.stderr)
    print(f"{'='*60}", file=sys.stderr)
    
    return results


def main():
    parser = argparse.ArgumentParser(description='PDF/A Compliance Test Runner')
    parser.add_argument('--test-dir', default=None,
                        help='Directory containing test PDFs (default: tests/Compliance/PDFA-1b)')
    parser.add_argument('--output', '-o', default=None,
                        help='Output JSON results file (default: stdout)')
    parser.add_argument('--level', default='1b', choices=['1b', '2b', '3b', '2u'],
                        help='PDF/A conformance level to test against (default: 1b)')
    parser.add_argument('--json', action='store_true', default=True,
                        help='Output as JSON')
    parser.add_argument('--single-file', default=None,
                        help='Run a single test file and output JSON result')
    parser.add_argument('--list-files', action='store_true', default=False,
                        help='List all test files in the test directory as JSON')
    
    args = parser.parse_args()
    
    # Determine test directory
    if args.test_dir:
        test_dir = args.test_dir
    else:
        # Auto-detect: look relative to this script's location
        script_dir = os.path.dirname(os.path.abspath(__file__))
        project_root = os.path.dirname(script_dir)
        test_dir = os.path.join(project_root, 'tests', 'Compliance', 'PDFA-1b')
    
    # Single file mode: run one test and return JSON result
    if args.single_file:
        pdf_path = args.single_file
        if not os.path.isfile(pdf_path):
            print(json.dumps({'error': f'File not found: {pdf_path}'}))
            sys.exit(1)
        result = run_single_test(pdf_path, level=args.level)
        # Remove traceback from output (internal detail)
        result.pop('traceback', None)
        print(json.dumps(result, default=str))
        sys.exit(0)
    
    # List files mode: return JSON array of test file paths
    if args.list_files:
        if not os.path.isdir(test_dir):
            print(json.dumps({'error': f'Test directory not found: {test_dir}'}))
            sys.exit(1)
        pdfs = find_test_pdfs(test_dir)
        files = []
        for pdf_path in pdfs:
            filename = os.path.splitext(os.path.basename(pdf_path))[0]
            files.append({
                'path': pdf_path,
                'filename': filename,
                'description': TEST_DESCRIPTIONS.get(filename, f"Unknown test: {filename}"),
                'test_category': derive_test_category(filename),
                'section_name': SECTION_NAMES.get(derive_test_category(filename), derive_test_category(filename)),
            })
        print(json.dumps({'total': len(files), 'files': files}, indent=2))
        sys.exit(0)
    
    if not os.path.isdir(test_dir):
        print(f"Error: Test directory not found: {test_dir}", file=sys.stderr)
        sys.exit(1)
    
    print(f"Running PDF/A-{args.level} compliance tests from: {test_dir}", file=sys.stderr)
    print(f"{'='*60}", file=sys.stderr)
    
    results = run_all_tests(test_dir, level=args.level)
    
    # Build summary
    summary = {
        'test_suite': 'Isartor PDF/A-1b',
        'level': args.level,
        'test_dir': test_dir,
        'timestamp': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'total': len(results),
        'passed': sum(1 for r in results if r['status'] == 'pass'),
        'failed': sum(1 for r in results if r['status'] == 'fail'),
        'errors': sum(1 for r in results if r['status'] == 'error'),
        'results': results,
    }
    
    output_json = json.dumps(summary, indent=2, default=str)
    
    if args.output:
        with open(args.output, 'w') as f:
            f.write(output_json)
        print(f"\nResults written to: {args.output}", file=sys.stderr)
    else:
        print(output_json)


if __name__ == '__main__':
    main()
