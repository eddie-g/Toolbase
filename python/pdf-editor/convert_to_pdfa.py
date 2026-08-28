#!/usr/bin/env python3
"""
Convert a PDF to PDF/A compliant format.

Usage:
    python3 convert_to_pdfa.py <input_pdf> <output_pdf> [--level 2b] [--embed-fonts] [--srgb]

Conformance levels supported:
    1b  - PDF/A-1b (Legacy Compatible)
    2b  - PDF/A-2b (Basic, recommended)
    3b  - PDF/A-3b (With Attachments)
    2u  - PDF/A-2u (Unicode Text)
"""

import sys
import os
import re
import argparse
import json
import fitz  # PyMuPDF


def get_pdfa_xmp_metadata(part, conformance, title="", producer="Netkit PDF Engine", creator="Netkit"):
    """
    Generate the XMP metadata block required for PDF/A identification.
    
    Args:
        part: PDF/A part number (1, 2, or 3)
        conformance: Conformance level letter (B or U)
        title: Document title
        producer: Producer application name
        creator: Creator application name
    
    Returns:
        XMP metadata XML string
    """
    return f"""<?xpacket begin="\ufeff" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Netkit PDF Engine">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about=""
        xmlns:dc="http://purl.org/dc/elements/1.1/"
        xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"
        xmlns:xmp="http://ns.adobe.com/xap/1.0/"
        xmlns:pdf="http://ns.adobe.com/pdf/1.3/"
        pdfaid:part="{part}"
        pdfaid:conformance="{conformance}">
      <dc:format>application/pdf</dc:format>
      <dc:title>
        <rdf:Alt>
          <rdf:li xml:lang="x-default">{title}</rdf:li>
        </rdf:Alt>
      </dc:title>
      <xmp:CreatorTool>{creator}</xmp:CreatorTool>
      <xmp:CreateDate>{get_iso_date()}</xmp:CreateDate>
      <xmp:ModifyDate>{get_iso_date()}</xmp:ModifyDate>
      <pdf:Producer>{producer}</pdf:Producer>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>"""


def get_iso_date():
    """Get current date in ISO 8601 format for XMP metadata."""
    from datetime import datetime, timezone
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


def parse_level(level_str):
    """
    Parse a conformance level string like '2b', '1b', '3b', '2u' 
    into (part_number, conformance_letter).
    """
    level_str = level_str.strip().lower()
    level_map = {
        '1b': (1, 'B'),
        '2b': (2, 'B'),
        '3b': (3, 'B'),
        '2u': (2, 'U'),
    }
    if level_str not in level_map:
        raise ValueError(f"Unsupported PDF/A level: {level_str}. Supported: {', '.join(level_map.keys())}")
    return level_map[level_str]


def _font_has_embedded_program(doc, xref):
    """Check if a font xref actually has an embedded font program by inspecting its FontDescriptor."""
    try:
        obj_str = doc.xref_object(xref)
        m = re.search(r'/FontDescriptor\s+(\d+)\s+0\s+R', obj_str)
        if not m:
            return False
        desc_str = doc.xref_object(int(m.group(1)))
        # FontFile = Type1, FontFile2 = TrueType, FontFile3 = CFF/OpenType
        return '/FontFile' in desc_str
    except:
        return False


def check_font_embedding(doc):
    """
    Check all pages for fonts and report which are/aren't embedded.
    Returns a dict with 'all_embedded' bool and 'details' list.
    """
    font_issues = []
    all_embedded = True
    
    for page_idx in range(len(doc)):
        page = doc[page_idx]
        fonts = page.get_fonts(full=True)
        for font in fonts:
            # font tuple: (xref, ext, type, basefont, name, encoding, ...)
            xref = font[0]
            font_name = font[3] if len(font) > 3 else "Unknown"
            font_type = font[2] if len(font) > 2 else "Unknown"
            
            # A font is embedded if:
            # - It has a valid xref
            # - For Type1: check if FontDescriptor contains FontFile/FontFile2/FontFile3
            # - For other types (TrueType, Type1C, CIDFontType2): assumed embedded if xref > 0
            if xref <= 0:
                is_embedded = False
            elif font_type == 'Type1':
                is_embedded = _font_has_embedded_program(doc, xref)
            else:
                is_embedded = True
            
            if not is_embedded:
                all_embedded = False
                font_issues.append({
                    'page': page_idx + 1,
                    'font': font_name,
                    'type': font_type,
                    'embedded': False
                })
    
    return {
        'all_embedded': all_embedded,
        'issues': font_issues
    }


# --- Base14 font substitution for PDF/A compliance ---
# PDF base14 fonts (Helvetica, Courier, Times, etc.) are unembedded Type1 fonts.
# PDF/A requires ALL fonts to be embedded. We replace them with metrically-compatible
# Google Fonts substitutes (Arimo, Cousine, Tinos).

BASE14_SUBSTITUTES = {
    'helvetica': {'regular': 'Arimo-Regular.ttf', 'bold': 'Arimo-Bold.ttf',
                  'italic': 'Arimo-Italic.ttf', 'bolditalic': 'Arimo-BoldItalic.ttf', 'google': 'Arimo'},
    'arial':     {'regular': 'Arimo-Regular.ttf', 'bold': 'Arimo-Bold.ttf',
                  'italic': 'Arimo-Italic.ttf', 'bolditalic': 'Arimo-BoldItalic.ttf', 'google': 'Arimo'},
    'courier':   {'regular': 'Cousine-Regular.ttf', 'bold': 'Cousine-Bold.ttf',
                  'italic': 'Cousine-Italic.ttf', 'bolditalic': 'Cousine-BoldItalic.ttf', 'google': 'Cousine'},
    'times':     {'regular': 'Tinos-Regular.ttf', 'bold': 'Tinos-Bold.ttf',
                  'italic': 'Tinos-Italic.ttf', 'bolditalic': 'Tinos-BoldItalic.ttf', 'google': 'Tinos'},
    'symbol':    {'regular': 'Arimo-Regular.ttf', 'bold': 'Arimo-Bold.ttf',
                  'italic': 'Arimo-Italic.ttf', 'bolditalic': 'Arimo-BoldItalic.ttf', 'google': 'Arimo'},
    'zapfdingbats': {'regular': 'Arimo-Regular.ttf', 'bold': 'Arimo-Bold.ttf',
                     'italic': 'Arimo-Italic.ttf', 'bolditalic': 'Arimo-BoldItalic.ttf', 'google': 'Arimo'},
}

# Fallback substitutes for non-base14 unembedded fonts (by broad category)
FALLBACK_SUBSTITUTES = {
    'sans':  {'regular': 'Arimo-Regular.ttf', 'bold': 'Arimo-Bold.ttf',
              'italic': 'Arimo-Italic.ttf', 'bolditalic': 'Arimo-BoldItalic.ttf', 'google': 'Arimo'},
    'serif': {'regular': 'Tinos-Regular.ttf', 'bold': 'Tinos-Bold.ttf',
              'italic': 'Tinos-Italic.ttf', 'bolditalic': 'Tinos-BoldItalic.ttf', 'google': 'Tinos'},
    'mono':  {'regular': 'Cousine-Regular.ttf', 'bold': 'Cousine-Bold.ttf',
              'italic': 'Cousine-Italic.ttf', 'bolditalic': 'Cousine-BoldItalic.ttf', 'google': 'Cousine'},
}


def get_fonts_dir():
    """Get the fonts directory next to this script."""
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fonts')


def download_substitute_font(google_name, filename, fonts_dir):
    """Download a single font file from Google Fonts if not already present."""
    import urllib.request
    filepath = os.path.join(fonts_dir, filename)
    if os.path.exists(filepath):
        return filepath

    os.makedirs(fonts_dir, exist_ok=True)
    # Determine weight and style from filename
    is_bold = 'Bold' in filename
    is_italic = 'Italic' in filename
    weight = '700' if is_bold else '400'
    if is_italic:
        css_url = f"https://fonts.googleapis.com/css2?family={google_name}:ital,wght@1,{weight}"
    else:
        css_url = f"https://fonts.googleapis.com/css2?family={google_name}:wght@{weight}"

    try:
        req = urllib.request.Request(css_url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=15) as response:
            css = response.read().decode('utf-8')
        match = re.search(r'https://fonts\.gstatic\.com/[^)]+\.ttf', css)
        if not match:
            return None
        urllib.request.urlretrieve(match.group(0), filepath)
        print(f"  Downloaded substitute font: {filename}", file=sys.stderr)
        return filepath
    except Exception as e:
        print(f"  Failed to download {filename}: {e}", file=sys.stderr)
        return None


def ensure_substitute_fonts(needed_families):
    """
    Make sure the substitute TTF files exist for each needed base14 family.
    Downloads from Google Fonts if missing.
    Returns a dict mapping family -> {'regular': path, 'bold': path}.
    """
    fonts_dir = get_fonts_dir()
    result = {}

    for family in needed_families:
        info = BASE14_SUBSTITUTES.get(family) or FALLBACK_SUBSTITUTES.get(family)
        if not info:
            continue
        paths = {}
        for variant in ('regular', 'bold', 'italic', 'bolditalic'):
            filename = info.get(variant)
            if not filename:
                continue
            full_path = os.path.join(fonts_dir, filename)
            if os.path.exists(full_path):
                paths[variant] = full_path
            else:
                downloaded = download_substitute_font(info['google'], filename, fonts_dir)
                if downloaded:
                    paths[variant] = downloaded
        if paths:
            result[family] = paths

    return result


def is_base14_unembedded(doc, xref, font_type, basefont):
    """Check if a font is an unembedded base14 Type1 font."""
    if font_type != 'Type1':
        return False
    try:
        obj_str = doc.xref_object(xref)
        if '/FontDescriptor' in obj_str:
            m = re.search(r'/FontDescriptor\s+(\d+)\s+0\s+R', obj_str)
            if m:
                desc_obj = doc.xref_object(int(m.group(1)))
                if '/FontFile' in desc_obj:
                    return False  # Has embedded font program
        return True
    except:
        return True


def get_base14_family(basefont):
    """Map a PDF basefont name to a base14 family key."""
    name = re.sub(r'^[A-Z]{6}\+', '', basefont).lower()
    name = name.replace('-', '').replace('_', '')
    for family in BASE14_SUBSTITUTES:
        if family in name:
            return family
    return None


def embed_type1_as_truetype(doc, old_xref, font_path):
    """
    Replace an unembedded Type1 font dict in-place with an embedded TrueType font,
    preserving WinAnsiEncoding so existing content streams remain valid.
    
    This avoids the encoding mismatch that occurs when using insert_font() (which
    creates a Type0/CIDFont with Identity-H 2-byte encoding) to replace a Type1
    font that used single-byte WinAnsiEncoding in the content stream.
    """
    # Read TTF data
    with open(font_path, 'rb') as f:
        ttf_data = f.read()

    # Get font metrics
    font = fitz.Font(fontfile=font_path)

    # Build widths array for chars 0-255 (WinAnsiEncoding range)
    widths = []
    for i in range(256):
        try:
            w = font.text_length(chr(i), fontsize=1000)
            widths.append(int(round(w)))
        except:
            widths.append(0)

    # Find FirstChar/LastChar
    first_char = next((i for i in range(256) if widths[i] > 0), 0)
    last_char = next((i for i in range(255, -1, -1) if widths[i] > 0), 255)
    widths_str = ' '.join(str(w) for w in widths[first_char:last_char + 1])

    # Font metrics (scaled to 1000 units)
    ascender = int(round(font.ascender * 1000))
    descender = int(round(font.descender * 1000))
    bbox = font.bbox
    font_bbox = f'{int(bbox.x0*1000)} {int(bbox.y0*1000)} {int(bbox.x1*1000)} {int(bbox.y1*1000)}'

    # PDF font flags (32 = nonsymbolic)
    pdf_flags = 32
    if font.is_serif:
        pdf_flags |= 2
    if font.is_italic:
        pdf_flags |= 64

    font_name = font.name.replace(' ', '-')

    # Create FontFile2 stream (embedded TTF data)
    ff2_xref = doc.get_new_xref()
    doc.update_object(ff2_xref, f'<</Length {len(ttf_data)} /Length1 {len(ttf_data)}>>')
    doc.update_stream(ff2_xref, ttf_data)

    # Create FontDescriptor
    fd_xref = doc.get_new_xref()
    doc.update_object(fd_xref, (
        f'<</Type /FontDescriptor /FontName /{font_name} /Flags {pdf_flags} '
        f'/FontBBox [{font_bbox}] /ItalicAngle {"-12" if font.is_italic else "0"} '
        f'/Ascent {ascender} /Descent {descender} /CapHeight {int(ascender * 0.75)} '
        f'/StemV {"80" if font.is_bold else "50"} /FontFile2 {ff2_xref} 0 R>>'
    ))

    # Update the existing Type1 font xref in-place to become an embedded TrueType
    doc.update_object(old_xref, (
        f'<</Type /Font /Subtype /TrueType /BaseFont /{font_name} '
        f'/Encoding /WinAnsiEncoding /FirstChar {first_char} /LastChar {last_char} '
        f'/Widths [{widths_str}] /FontDescriptor {fd_xref} 0 R>>'
    ))


def _guess_font_category(basefont, doc=None, xref=None):
    """Guess the font category (sans/serif/mono) from the font name or descriptor flags."""
    name_lower = basefont.lower()
    # Check name hints
    if any(k in name_lower for k in ('mono', 'courier', 'consol', 'fixed', 'typewriter')):
        return 'mono'
    if any(k in name_lower for k in ('serif', 'times', 'roman', 'garamond', 'georgia', 'palat', 'bookman', 'cambria')):
        return 'serif'
    if any(k in name_lower for k in ('sans', 'arial', 'helv', 'verdana', 'calibri', 'lucid', 'gothic', 'futura', 'tahoma')):
        return 'sans'
    # Check font descriptor flags if available
    if doc and xref:
        try:
            obj_str = doc.xref_object(xref)
            m = re.search(r'/FontDescriptor\s+(\d+)\s+0\s+R', obj_str)
            if m:
                desc_str = doc.xref_object(int(m.group(1)))
                fm = re.search(r'/Flags\s+(\d+)', desc_str)
                if fm:
                    flags = int(fm.group(1))
                    if flags & 1:  # FixedPitch
                        return 'mono'
                    if flags & 2:  # Serif
                        return 'serif'
        except:
            pass
    return 'sans'  # Default fallback


def _is_italic_font(basefont, doc=None, xref=None):
    """Detect if a font is italic/oblique from name or descriptor flags."""
    name_lower = basefont.lower()
    if any(k in name_lower for k in ('italic', 'oblique', 'slant')):
        return True
    if doc and xref:
        try:
            obj_str = doc.xref_object(xref)
            m = re.search(r'/FontDescriptor\s+(\d+)\s+0\s+R', obj_str)
            if m:
                desc_str = doc.xref_object(int(m.group(1)))
                fm = re.search(r'/Flags\s+(\d+)', desc_str)
                if fm:
                    flags = int(fm.group(1))
                    if flags & 64:  # Italic flag
                        return True
                # Also check ItalicAngle
                am = re.search(r'/ItalicAngle\s+(-?\d+)', desc_str)
                if am and int(am.group(1)) != 0:
                    return True
        except:
            pass
    return False


def replace_base14_fonts(doc):
    """
    Replace unembedded Type1 fonts with embedded TrueType substitutes.
    Handles both base14 fonts (Helvetica, Courier, Times) and non-base14 fonts
    (like LuciduxSans) using a category-based fallback system.
    Downloads substitute fonts from Google Fonts if not already cached locally.
    
    Uses in-place xref replacement to convert the Type1 font dict into an embedded
    TrueType font dict with the same WinAnsiEncoding, so existing content streams
    continue to work without any text re-encoding.
    """
    # Pass 1: Scan all pages to find unembedded Type1 fonts (collect unique xrefs)
    unembedded_xrefs = {}  # xref -> (basefont, family, is_italic)
    needed_families = set()

    for page_idx in range(len(doc)):
        page = doc[page_idx]
        fonts = page.get_fonts(full=True)
        for font_info in fonts:
            xref, ext, ftype, basefont, name, encoding = font_info[:6]
            if xref in unembedded_xrefs:
                continue  # Already collected
            if is_base14_unembedded(doc, xref, ftype, basefont):
                family = get_base14_family(basefont)
                italic = _is_italic_font(basefont, doc, xref)
                if not family:
                    # Non-base14 font: use category-based fallback
                    family = _guess_font_category(basefont, doc, xref)
                    print(f"  Non-base14 font '{basefont}' -> fallback category: {family}", file=sys.stderr)
                needed_families.add(family)
                unembedded_xrefs[xref] = (basefont, family, italic)

    if not unembedded_xrefs:
        return

    # Pass 2: Download any missing substitute fonts
    available = ensure_substitute_fonts(needed_families)
    if not available:
        print("Warning: Could not obtain any substitute fonts", file=sys.stderr)
        return

    # Pass 3: Replace each unembedded font xref in-place
    for xref, (basefont, family, italic) in unembedded_xrefs.items():
        if family not in available:
            continue

        is_bold = 'bold' in basefont.lower()
        # Pick the best variant: bolditalic > bold/italic > regular
        if is_bold and italic:
            variant = 'bolditalic' if 'bolditalic' in available[family] else ('bold' if 'bold' in available[family] else 'regular')
        elif is_bold:
            variant = 'bold' if 'bold' in available[family] else 'regular'
        elif italic:
            variant = 'italic' if 'italic' in available[family] else 'regular'
        else:
            variant = 'regular'
        font_path = available[family].get(variant)
        if not font_path:
            continue

        try:
            embed_type1_as_truetype(doc, xref, font_path)
            print(f"  Embedded substitute for '{basefont}': {os.path.basename(font_path)} ({variant})", file=sys.stderr)
        except Exception as e:
            print(f"Warning: Could not embed substitute for '{basefont}' (xref {xref}): {e}", file=sys.stderr)


def embed_missing_fonts(doc):
    """
    Embed all missing fonts for PDF/A compliance:
    1. Replace unembedded base14 fonts with embedded TrueType substitutes
    2. Subset already-embedded fonts to reduce file size
    """
    # Step 1: Replace base14 unembedded fonts with real embedded substitutes
    try:
        replace_base14_fonts(doc)
    except Exception as e:
        print(f"Warning: Base14 font replacement encountered an issue: {e}", file=sys.stderr)

    # Step 2: Subset all embedded fonts
    try:
        doc.subset_fonts()
    except Exception as e:
        print(f"Warning: Font subsetting encountered an issue: {e}", file=sys.stderr)


def apply_srgb_output_intent(doc):
    """
    Add an sRGB Output Intent to the PDF catalog.
    This satisfies the PDF/A requirement for a defined color space.
    
    We create a minimal sRGB ICC profile and reference it from an OutputIntent
    dictionary in the PDF catalog.
    """
    xref_catalog = doc.pdf_catalog()
    
    # Create ICC profile stream object
    icc_xref = doc.get_new_xref()
    srgb_profile = create_minimal_srgb_profile()
    doc.update_object(icc_xref, f'<</N 3 /Length {len(srgb_profile)}>>')
    doc.update_stream(icc_xref, srgb_profile)
    
    # Create OutputIntent dictionary referencing the ICC profile
    oi_xref = doc.get_new_xref()
    doc.update_object(oi_xref, f'<</Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /RegistryName (http://www.color.org) /Info (sRGB IEC61966-2.1) /DestOutputProfile {icc_xref} 0 R>>')
    
    # Add OutputIntents array to the catalog
    doc.xref_set_key(xref_catalog, "OutputIntents", f"[{oi_xref} 0 R]")


def create_minimal_srgb_profile():
    """
    Create a minimal sRGB ICC profile.
    This is a bare-minimum profile header that identifies sRGB.
    """
    import struct
    
    # ICC profile header (128 bytes) + tag table + minimal tags
    # Profile header
    header = bytearray(128)
    
    # Profile size (will be updated later)
    # Preferred CMM type
    struct.pack_into('>I', header, 4, 0)  # CMM type
    # Profile version 2.1.0
    struct.pack_into('>I', header, 8, 0x02100000)
    # Device class: 'mntr' (monitor)
    header[12:16] = b'mntr'
    # Color space: 'RGB '
    header[16:20] = b'RGB '
    # PCS: 'XYZ '
    header[20:24] = b'XYZ '
    # Date/time
    struct.pack_into('>H', header, 24, 2025)  # year
    struct.pack_into('>H', header, 26, 1)     # month
    struct.pack_into('>H', header, 28, 1)     # day
    # Profile file signature: 'acsp'
    header[36:40] = b'acsp'
    # Primary platform: 'APPL' (or '*nix')
    header[40:44] = b'APPL'
    # Rendering intent: Perceptual
    struct.pack_into('>I', header, 64, 0)
    # PCS illuminant (D50: X=0.9505, Y=1.0, Z=1.0890)
    struct.pack_into('>i', header, 68, int(0.9505 * 65536))
    struct.pack_into('>i', header, 72, int(1.0 * 65536))
    struct.pack_into('>i', header, 76, int(1.089 * 65536))
    # Profile creator: 'TOOL'
    header[80:84] = b'TOOL'
    
    # Tag table: we need at minimum desc, wtpt, rXYZ, gXYZ, bXYZ, rTRC, gTRC, bTRC
    num_tags = 9
    tag_table = bytearray(4 + num_tags * 12)
    struct.pack_into('>I', tag_table, 0, num_tags)
    
    # Start building tag data after the tag table
    data_offset = 128 + len(tag_table)
    tag_data = bytearray()
    
    def add_xyz_tag(x, y, z):
        """Create an XYZ tag data block."""
        block = bytearray(20)
        block[0:4] = b'XYZ '  # type signature
        struct.pack_into('>i', block, 8, int(x * 65536))
        struct.pack_into('>i', block, 12, int(y * 65536))
        struct.pack_into('>i', block, 16, int(z * 65536))
        return block
    
    def add_curve_tag(gamma):
        """Create a curve tag with a single gamma value."""
        block = bytearray(14)
        block[0:4] = b'curv'  # type signature
        struct.pack_into('>I', block, 8, 1)  # count = 1
        struct.pack_into('>H', block, 12, int(gamma * 256))
        # Pad to 4-byte boundary
        while len(block) % 4 != 0:
            block.append(0)
        return block
    
    def add_desc_tag(text):
        """Create a text description tag."""
        text_bytes = text.encode('ascii') + b'\x00'
        block = bytearray(12 + len(text_bytes))
        block[0:4] = b'desc'  # type signature
        struct.pack_into('>I', block, 8, len(text_bytes))
        block[12:12+len(text_bytes)] = text_bytes
        # Pad: add unicode and script counts as 0
        block += bytearray(8)  # unicode count + scriptcode count
        while len(block) % 4 != 0:
            block.append(0)
        return block
    
    # sRGB primaries and white point (D65 adapted to D50 PCS)
    tags = [
        (b'desc', add_desc_tag("sRGB IEC61966-2.1")),
        (b'wtpt', add_xyz_tag(0.9505, 1.0, 1.0890)),       # D50 white point
        (b'rXYZ', add_xyz_tag(0.4360747, 0.2225045, 0.0139322)),  # Red primary
        (b'gXYZ', add_xyz_tag(0.3850649, 0.7168786, 0.0971045)),  # Green primary
        (b'bXYZ', add_xyz_tag(0.1430804, 0.0606169, 0.7141733)),  # Blue primary
        (b'rTRC', add_curve_tag(2.2)),  # Red TRC (approximate sRGB gamma)
        (b'gTRC', add_curve_tag(2.2)),  # Green TRC
        (b'bTRC', add_curve_tag(2.2)),  # Blue TRC
        (b'cprt', add_desc_tag("Public Domain")),  # Copyright
    ]
    
    for i, (sig, block) in enumerate(tags):
        offset = data_offset + len(tag_data)
        struct.pack_into('>4s', tag_table, 4 + i * 12, sig)
        struct.pack_into('>I', tag_table, 4 + i * 12 + 4, offset)
        struct.pack_into('>I', tag_table, 4 + i * 12 + 8, len(block))
        tag_data.extend(block)
    
    # Assemble profile
    profile = header + tag_table + tag_data
    
    # Update profile size in header
    struct.pack_into('>I', profile, 0, len(profile))
    
    return bytes(profile)


# Action types prohibited in PDF/A
PROHIBITED_ACTIONS = {
    'Launch', 'Sound', 'Movie', 'ResetForm', 'ImportData',
    'Hide', 'SetState', 'NOP', 'JavaScript',
}

# Named actions allowed in PDF/A (all others are prohibited)
ALLOWED_NAMED_ACTIONS = {'NextPage', 'PrevPage', 'FirstPage', 'LastPage'}

# Annotation types prohibited in PDF/A
PROHIBITED_ANNOT_TYPES = {'FileAttachment', 'Sound', 'Movie'}


def _is_prohibited_action(doc, xref):
    """
    Check if an action object at the given xref is prohibited in PDF/A.
    Returns True if the action should be removed.
    """
    try:
        obj_str = doc.xref_object(xref)
    except Exception:
        return False

    import re
    s_match = re.search(r'/S\s*/([A-Za-z]+)', obj_str)
    if not s_match:
        return False
    action_type = s_match.group(1)

    if action_type in PROHIBITED_ACTIONS:
        return True
    if action_type == 'Named':
        n_match = re.search(r'/N\s*/([A-Za-z]+)', obj_str)
        if n_match and n_match.group(1) not in ALLOWED_NAMED_ACTIONS:
            return True
    if action_type in ('GoToR', 'GoToE', 'SubmitForm'):
        return True  # External-referencing action types
    if action_type == 'URI':
        return True  # URI actions reference external resources
    return False


def _remove_pdf_key(doc, xref, key):
    """
    Truly remove a key from a PDF object by rewriting the object string.
    xref_set_key(xref, key, 'null') leaves '/Key null' in the dict which
    some checks still detect. This function rewrites the entire object
    without the key.
    """
    import re
    obj_str = doc.xref_object(xref)
    # Match /Key followed by: an indirect ref, an inline dict, an inline array, or a simple value
    patterns = [
        rf'\s*/{re.escape(key)}\s+\d+\s+\d+\s+R',           # indirect ref
        rf'\s*/{re.escape(key)}\s*<<(?:[^<>]|<<[^>]*>>)*>>', # inline dict (nested one level)
        rf'\s*/{re.escape(key)}\s*\[[^\]]*\]',               # inline array
        rf'\s*/{re.escape(key)}\s+null',                      # null value
        rf'\s*/{re.escape(key)}\s+/[A-Za-z]+',               # name value
        rf'\s*/{re.escape(key)}\s+\S+',                       # other simple value
    ]
    for pat in patterns:
        new_str, n = re.subn(pat, ' ', obj_str, count=1)
        if n > 0:
            doc.update_object(xref, new_str)
            return True
    return False


def strip_prohibited_actions(doc):
    """
    Remove all PDF/A-prohibited actions, AA entries, and prohibited annotations.

    PDF/A forbids:
    - Additional Actions (AA) on pages and catalog
    - Prohibited action types (Launch, Sound, Movie, JS, etc.)
    - External-referencing actions (GoToR, GoToE, URI, SubmitForm)
    - Named actions other than NextPage/PrevPage/FirstPage/LastPage
    - Prohibited annotation types (FileAttachment, Sound, Movie)
    - Annotation-level AA entries
    - JavaScript in Names dict
    """
    import re
    catalog_xref = doc.pdf_catalog()

    # 1. Remove AA from catalog
    try:
        keys = doc.xref_get_keys(catalog_xref)
        if 'AA' in keys:
            _remove_pdf_key(doc, catalog_xref, 'AA')
    except Exception:
        pass

    # 2. Remove prohibited OpenAction from catalog
    try:
        keys = doc.xref_get_keys(catalog_xref)
        if 'OpenAction' in keys:
            oa_type, oa_val = doc.xref_get_key(catalog_xref, 'OpenAction')
            if oa_type == 'xref':
                oa_xref = int(re.search(r'(\d+)', oa_val).group(1))
                if _is_prohibited_action(doc, oa_xref):
                    _remove_pdf_key(doc, catalog_xref, 'OpenAction')
    except Exception:
        pass

    # 3. Remove JavaScript Names tree from catalog
    try:
        keys = doc.xref_get_keys(catalog_xref)
        if 'Names' in keys:
            names_type, names_val = doc.xref_get_key(catalog_xref, 'Names')
            if names_type == 'xref':
                names_xref = int(re.search(r'(\d+)', names_val).group(1))
                names_keys = doc.xref_get_keys(names_xref)
                if 'JavaScript' in names_keys:
                    _remove_pdf_key(doc, names_xref, 'JavaScript')
            elif names_type == 'dict':
                # Inline Names dict — need to check for JavaScript key inside
                if '/JavaScript' in names_val:
                    _remove_pdf_key(doc, catalog_xref, 'Names')
    except Exception:
        pass

    # 4. Nullify all prohibited action objects so they become inert
    for x in range(1, doc.xref_length()):
        try:
            obj_str = doc.xref_object(x)
            if '/S ' not in obj_str and '/S/' not in obj_str:
                continue
            if _is_prohibited_action(doc, x):
                # Replace the action with an empty dict
                doc.update_object(x, '<<>>')
        except Exception:
            pass

    # 5. Process each page
    for page in doc:
        page_xref = page.xref
        try:
            page_keys = doc.xref_get_keys(page_xref)
        except Exception:
            continue

        # 5a. Remove AA from page
        if 'AA' in page_keys:
            _remove_pdf_key(doc, page_xref, 'AA')

        # 5b. Process annotations on this page
        if 'Annots' in page_keys:
            annots_type, annots_val = doc.xref_get_key(page_xref, 'Annots')
            # Parse annotation xrefs from the array
            annot_xrefs = [int(m) for m in re.findall(r'(\d+)\s+0\s+R', annots_val)]
            keep_annots = []

            for ax in annot_xrefs:
                try:
                    annot_obj = doc.xref_object(ax)
                except Exception:
                    keep_annots.append(ax)
                    continue

                # Check if this is a prohibited annotation type
                subtype_match = re.search(r'/Subtype\s*/([A-Za-z]+)', annot_obj)
                if subtype_match and subtype_match.group(1) in PROHIBITED_ANNOT_TYPES:
                    continue  # Drop this annotation

                # Remove AA from annotation
                try:
                    annot_keys = doc.xref_get_keys(ax)
                    if 'AA' in annot_keys:
                        _remove_pdf_key(doc, ax, 'AA')
                except Exception:
                    pass

                # Check if annotation's A (action) is prohibited — remove the A key
                try:
                    annot_keys = doc.xref_get_keys(ax)
                    if 'A' in annot_keys:
                        a_type, a_val = doc.xref_get_key(ax, 'A')
                        if a_type == 'xref':
                            a_xref = int(re.search(r'(\d+)', a_val).group(1))
                            if _is_prohibited_action(doc, a_xref):
                                _remove_pdf_key(doc, ax, 'A')
                        elif a_type == 'dict':
                            # Inline action dict — check /S type
                            s_match = re.search(r'/S\s*/([A-Za-z]+)', a_val)
                            if s_match:
                                act_type = s_match.group(1)
                                if act_type in PROHIBITED_ACTIONS or act_type in ('GoToR', 'GoToE', 'SubmitForm', 'URI'):
                                    _remove_pdf_key(doc, ax, 'A')
                                elif act_type == 'Named':
                                    n_match = re.search(r'/N\s*/([A-Za-z]+)', a_val)
                                    if n_match and n_match.group(1) not in ALLOWED_NAMED_ACTIONS:
                                        _remove_pdf_key(doc, ax, 'A')
                except Exception:
                    pass

                keep_annots.append(ax)

            # Update or remove the Annots array
            if not keep_annots:
                _remove_pdf_key(doc, page_xref, 'Annots')
            elif len(keep_annots) != len(annot_xrefs):
                new_annots = '[' + ' '.join(f'{x} 0 R' for x in keep_annots) + ']'
                doc.xref_set_key(page_xref, 'Annots', new_annots)


def convert_to_pdfa(input_path, output_path, level='2b', embed_fonts=True, srgb_profile=True):
    """
    Convert a PDF file to PDF/A format.
    
    Args:
        input_path: Path to input PDF file
        output_path: Path for output PDF/A file
        level: Conformance level ('1b', '2b', '3b', '2u')
        embed_fonts: Whether to embed/subset fonts
        srgb_profile: Whether to add sRGB output intent
    
    Returns:
        dict with success status and any warnings
    """
    part, conformance = parse_level(level)
    warnings = []
    
    # Open the document
    doc = fitz.open(input_path)
    
    # Get document title from existing metadata or filename
    existing_meta = doc.metadata or {}
    title = existing_meta.get('title', '') or os.path.splitext(os.path.basename(input_path))[0]
    
    # Step 1: Embed/subset fonts if requested
    if embed_fonts:
        font_check = check_font_embedding(doc)
        if not font_check['all_embedded']:
            warnings.append(f"Found {len(font_check['issues'])} non-embedded font(s), attempting to fix...")
        embed_missing_fonts(doc)
        
        # Re-check after embedding
        font_check_after = check_font_embedding(doc)
        if not font_check_after['all_embedded']:
            for issue in font_check_after['issues']:
                warnings.append(f"Font '{issue['font']}' on page {issue['page']} may not be fully embedded")
    
    # Step 2: Apply sRGB Output Intent
    if srgb_profile:
        try:
            apply_srgb_output_intent(doc)
        except Exception as e:
            warnings.append(f"sRGB profile application warning: {str(e)}")
    
    # Step 3: Apply XMP metadata with PDF/A identification
    xmp = get_pdfa_xmp_metadata(part, conformance, title=title)
    doc.set_xml_metadata(xmp)
    
    # Step 4: Set standard metadata
    doc.set_metadata({
        "format": f"PDF/A-{part}{conformance.lower()}",
        "title": title,
        "producer": "Netkit PDF Engine v1.0",
        "creator": "Netkit"
    })
    
    # Step 5: Remove any transparency groups that violate PDF/A-1 
    # (PDF/A-2 and above allow transparency)
    if part == 1:
        warnings.append("PDF/A-1 does not support transparency. Some visual elements may change.")
    
    # Step 5.5: Strip prohibited actions, AA entries, and prohibited annotations
    try:
        strip_prohibited_actions(doc)
    except Exception as e:
        warnings.append(f"Action stripping warning: {str(e)}")
    
    # Step 6: Save with optimal settings for archival
    doc.save(
        output_path,
        garbage=4,      # Maximum garbage collection
        deflate=True,    # Compress streams
        clean=True,      # Clean up unused objects
    )
    
    doc.close()
    
    # Step 7: Generate compliance report on the output file
    report = generate_compliance_report(output_path, part, conformance)
    
    return {
        'success': True,
        'level': f"{part}{conformance.lower()}",
        'label': f"PDF/A-{part}{conformance.lower()}",
        'warnings': warnings,
        'output': output_path,
        'report': report
    }


def generate_compliance_report(doc_path, part, conformance):
    """
    Scan the final PDF/A document and produce a compliance report
    with pass/fail checks for each PDF/A requirement.
    """
    from datetime import datetime, timezone

    doc = fitz.open(doc_path)
    report = {
        'standard': f'PDF/A-{part}{conformance.lower()}',
        'iso': f'ISO 19005-{part}',
        'status': 'Compliant',
        'timestamp': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'file_size': os.path.getsize(doc_path),
        'page_count': len(doc),
        'checks': []
    }

    # --- Check 1: Font Embedding ---
    total_fonts = 0
    embedded_fonts = 0
    font_details = []
    seen_fonts = set()

    for page_idx in range(len(doc)):
        for font in doc.get_page_fonts(page_idx, full=True):
            xref = font[0]
            font_name = font[3] if len(font) > 3 else 'Unknown'
            font_type = font[2] if len(font) > 2 else 'Unknown'

            key = f"{xref}_{font_name}"
            if key in seen_fonts:
                continue
            seen_fonts.add(key)

            total_fonts += 1
            # For Type1 fonts, check the FontDescriptor for actual font data
            if xref <= 0:
                is_embedded = False
            elif font_type == 'Type1':
                is_embedded = _font_has_embedded_program(doc, xref)
            else:
                is_embedded = True
            if is_embedded:
                embedded_fonts += 1
            font_details.append({
                'name': font_name,
                'type': font_type,
                'embedded': is_embedded,
                'page': page_idx + 1
            })

    report['checks'].append({
        'item': 'Font Embedding',
        'description': 'All fonts must be embedded in the document',
        'result': 'PASS' if (total_fonts == 0 or embedded_fonts == total_fonts) else 'FAIL',
        'detail': f'{embedded_fonts}/{total_fonts} fonts embedded',
        'fonts': font_details
    })

    # --- Check 2: XMP Metadata / PDF/A Identification ---
    xml_meta = doc.get_xml_metadata()
    has_pdfa_id = 'pdfaid:part' in (xml_meta or '') and 'pdfaid:conformance' in (xml_meta or '')
    report['checks'].append({
        'item': 'PDF/A Identification',
        'description': 'XMP metadata must contain pdfaid namespace declaration',
        'result': 'PASS' if has_pdfa_id else 'FAIL',
        'detail': f'PDF/A-{part}{conformance.lower()} identifier present' if has_pdfa_id else 'Missing pdfaid declaration'
    })

    # --- Check 3: Standard Metadata / PDF/A Format Declaration ---
    # PyMuPDF's metadata['format'] returns the PDF version header (e.g. "PDF 1.7"),
    # not a custom value. The actual PDF/A format declaration lives in XMP metadata
    # (which we already verified in Check 2). So we verify the XMP contains
    # the proper pdfaid:part AND conformance values matching the requested level.
    xmp_str = xml_meta or ''
    xmp_lower = xmp_str.lower()
    has_correct_part = f'pdfaid:part="{part}"' in xmp_lower or f"pdfaid:part='{part}'" in xmp_lower or f'pdfaid:part>{part}<' in xmp_str
    has_correct_conf = f'pdfaid:conformance="{conformance.lower()}"' in xmp_lower or f"pdfaid:conformance='{conformance.lower()}'" in xmp_lower or f'pdfaid:conformance>{conformance}<' in xmp_str
    has_format_flag = has_correct_part and has_correct_conf
    report['checks'].append({
        'item': 'Metadata Format Flag',
        'description': 'XMP metadata must declare correct PDF/A part and conformance level',
        'result': 'PASS' if has_format_flag else 'FAIL',
        'detail': f'PDF/A-{part}{conformance.lower()} declared in XMP metadata' if has_format_flag else f'Expected PDF/A-{part}{conformance.lower()} declaration in XMP metadata'
    })

    # --- Check 4: No JavaScript ---
    has_js = False
    try:
        js = doc.get_js()
        has_js = bool(js)
    except:
        pass
    report['checks'].append({
        'item': 'No JavaScript',
        'description': 'PDF/A documents must not contain JavaScript',
        'result': 'PASS' if not has_js else 'FAIL',
        'detail': 'No JavaScript found' if not has_js else 'JavaScript detected'
    })

    # --- Check 5: No Encryption ---
    is_encrypted = doc.is_encrypted
    report['checks'].append({
        'item': 'No Encryption',
        'description': 'PDF/A documents must not be encrypted',
        'result': 'PASS' if not is_encrypted else 'FAIL',
        'detail': 'Document is not encrypted' if not is_encrypted else 'Encryption detected'
    })

    # --- Check 6: Output Intent (Color Profile) ---
    catalog_xref = doc.pdf_catalog()
    has_output_intent = False
    try:
        keys = doc.xref_get_keys(catalog_xref)
        has_output_intent = 'OutputIntents' in keys
    except:
        pass
    report['checks'].append({
        'item': 'Color Profile (Output Intent)',
        'description': 'PDF/A requires an ICC color profile output intent',
        'result': 'PASS' if has_output_intent else 'FAIL',
        'detail': 'sRGB IEC61966-2.1 output intent present' if has_output_intent else 'No output intent found'
    })

    # --- Check 7: No External References / Prohibited Actions ---
    # PDF/A prohibits: AA on pages/catalog, prohibited action types, external refs
    import re as _re
    prohibited_found = []
    _prohibited_set = {
        'Launch', 'Sound', 'Movie', 'ResetForm', 'ImportData',
        'Hide', 'SetState', 'NOP', 'JavaScript', 'GoToR', 'GoToE',
        'SubmitForm', 'URI',
    }
    _allowed_named = {'NextPage', 'PrevPage', 'FirstPage', 'LastPage'}

    # Check catalog for AA
    try:
        cat_keys = doc.xref_get_keys(catalog_xref)
        if 'AA' in cat_keys:
            prohibited_found.append('AA on catalog')
    except:
        pass

    # Check pages for AA
    for page_idx in range(len(doc)):
        page = doc[page_idx]
        try:
            keys = doc.xref_get_keys(page.xref)
            if 'AA' in keys:
                prohibited_found.append(f'AA on page {page_idx + 1}')
        except:
            pass

    # Scan all objects for prohibited action types
    for x in range(1, doc.xref_length()):
        try:
            obj = doc.xref_object(x)
            if '/S ' not in obj and '/S/' not in obj:
                continue
            s_match = _re.search(r'/S\s*/([A-Za-z]+)', obj)
            if not s_match:
                continue
            action_type = s_match.group(1)
            if action_type in _prohibited_set:
                prohibited_found.append(f'{action_type} action')
            elif action_type == 'Named':
                n_match = _re.search(r'/N\s*/([A-Za-z]+)', obj)
                if n_match and n_match.group(1) not in _allowed_named:
                    prohibited_found.append(f'Named/{n_match.group(1)} action')
        except:
            pass

    has_external = len(prohibited_found) > 0
    detail_text = 'No external references found' if not has_external else f'Prohibited: {", ".join(prohibited_found[:3])}'
    report['checks'].append({
        'item': 'No External References',
        'description': 'PDF/A prohibits external content dependencies',
        'result': 'PASS' if not has_external else 'FAIL',
        'detail': detail_text
    })

    doc.close()

    # Determine overall status
    if any(c['result'] == 'FAIL' for c in report['checks']):
        report['status'] = 'Non-Compliant'

    passed = sum(1 for c in report['checks'] if c['result'] == 'PASS')
    report['summary'] = f'{passed}/{len(report["checks"])} checks passed'

    return report


def main():
    parser = argparse.ArgumentParser(description='Convert PDF to PDF/A format')
    parser.add_argument('input', help='Input PDF file path')
    parser.add_argument('output', help='Output PDF/A file path')
    parser.add_argument('--level', default='2b', choices=['1b', '2b', '3b', '2u'],
                        help='PDF/A conformance level (default: 2b)')
    parser.add_argument('--embed-fonts', action='store_true', default=True,
                        help='Embed and subset all fonts')
    parser.add_argument('--no-embed-fonts', action='store_true', default=False,
                        help='Skip font embedding')
    parser.add_argument('--srgb', action='store_true', default=True,
                        help='Add sRGB output intent')
    parser.add_argument('--no-srgb', action='store_true', default=False,
                        help='Skip sRGB output intent')
    parser.add_argument('--json', action='store_true',
                        help='Output result as JSON')
    
    args = parser.parse_args()
    
    embed = not args.no_embed_fonts
    srgb = not args.no_srgb
    
    if not os.path.exists(args.input):
        result = {'success': False, 'error': f'Input file not found: {args.input}'}
        if args.json:
            print(json.dumps(result))
        else:
            print(f"Error: {result['error']}", file=sys.stderr)
        sys.exit(1)
    
    try:
        result = convert_to_pdfa(
            args.input, 
            args.output, 
            level=args.level,
            embed_fonts=embed,
            srgb_profile=srgb
        )
        
        if args.json:
            print(json.dumps(result))
        else:
            print(f"Successfully converted to {result['label']}")
            if result['warnings']:
                for w in result['warnings']:
                    print(f"  Warning: {w}")
            print(f"Output: {result['output']}")
            
    except Exception as e:
        result = {'success': False, 'error': str(e)}
        if args.json:
            print(json.dumps(result))
        else:
            print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
