#!/usr/bin/env python3
"""
Auto-download fonts from Google Fonts based on PDF font names
"""
import sys
import os
import re
import urllib.request
import fitz  # PyMuPDF

# Common font mappings: PDF font name patterns -> Google Font family
GOOGLE_FONT_MAPPINGS = {
    # Serif fonts
    'gelasio': 'Gelasio',
    'georgia': 'Gelasio',  # Georgia substitute
    'times': 'Tinos',  # Times New Roman substitute (metrically compatible)
    'timesnewroman': 'Tinos',
    'palatino': 'EB+Garamond',
    'garamond': 'EB+Garamond',
    'cambria': 'Merriweather',
    'charter': 'Libre+Baskerville',
    'bookman': 'Libre+Baskerville',
    'century': 'Libre+Baskerville',
    'liberation serif': 'Tinos',
    
    # Sans-serif fonts
    'arimo': 'Arimo',
    'arial': 'Arimo',  # Arial substitute (metrically compatible)
    'helvetica': 'Arimo',  # Helvetica substitute
    'roboto': 'Roboto',
    'opensans': 'Open+Sans',
    'open sans': 'Open+Sans',
    'lato': 'Lato',
    'montserrat': 'Montserrat',
    'poppins': 'Poppins',
    'nunito': 'Nunito',
    'raleway': 'Raleway',
    'oswald': 'Oswald',
    'sourcesans': 'Source+Sans+3',
    'source sans': 'Source+Sans+3',
    'inter': 'Inter',
    'calibri': 'Carlito',  # Calibri substitute (metrically compatible)
    'verdana': 'Arimo',  # Verdana substitute
    'tahoma': 'Arimo',  # Tahoma substitute
    'tahomaunicode': 'Arimo',
    'liberation sans': 'Arimo',
    
    # Monospace fonts
    'courier': 'Cousine',  # Courier substitute (metrically compatible)
    'couriernew': 'Cousine',
    'consolas': 'Inconsolata',
    'monaco': 'Fira+Code',
    'menlo': 'Fira+Mono',
    'liberation mono': 'Cousine',
    
    # Display fonts
    'impact': 'Anton',
    'comicsans': 'Comic+Neue',
    'comic sans': 'Comic+Neue',
}

def get_fonts_dir():
    """Get the fonts directory path"""
    script_dir = os.path.dirname(os.path.abspath(__file__))
    fonts_dir = os.path.join(script_dir, 'fonts')
    os.makedirs(fonts_dir, exist_ok=True)
    return fonts_dir

def extract_font_names(pdf_path):
    """Extract all font names used in a PDF"""
    doc = fitz.open(pdf_path)
    fonts = set()
    
    for page in doc:
        page_fonts = page.get_fonts(full=True)
        for font_info in page_fonts:
            xref, ext, ftype, basefont, name, encoding = font_info[:6]
            # Clean up font name - remove subset prefix (e.g., "ABCDEF+FontName")
            clean_name = re.sub(r'^[A-Z]{6}\+', '', basefont)
            fonts.add(clean_name)
    
    doc.close()
    return list(fonts)

def find_google_font(font_name):
    """Find the Google Font family name for a PDF font"""
    # Clean up font name
    clean = font_name.lower().replace('-', '').replace('_', '').replace(' ', '')
    
    # Remove common suffixes
    for suffix in ['regular', 'bold', 'italic', 'light', 'medium', 'semibold', 'black', 'thin', 'extrabold', 'extralight']:
        clean = clean.replace(suffix, '')
    
    # Remove weight numbers
    clean = re.sub(r'\d+wght', '', clean)
    clean = re.sub(r'\d+', '', clean)
    
    # Look for mapping
    for pattern, google_font in GOOGLE_FONT_MAPPINGS.items():
        if pattern in clean:
            return google_font
    
    # Try the font name directly (might be a Google Font already)
    return font_name.split('-')[0].split('_')[0]

def get_font_weights(font_name):
    """Determine what weights to download based on font name"""
    name_lower = font_name.lower()
    weights = ['400']  # Always get regular
    
    if 'bold' in name_lower or '700' in name_lower:
        weights.append('700')
    if 'light' in name_lower or '300' in name_lower:
        weights.append('300')
    if 'medium' in name_lower or '500' in name_lower:
        weights.append('500')
    if 'semibold' in name_lower or '600' in name_lower:
        weights.append('600')
    
    # Always include bold for flexibility
    if '700' not in weights:
        weights.append('700')
    
    return weights

def download_google_font(google_font_name, weights, fonts_dir):
    """Download font from Google Fonts"""
    downloaded = []
    
    for weight in weights:
        weight_name = {
            '300': 'Light',
            '400': 'Regular',
            '500': 'Medium',
            '600': 'SemiBold',
            '700': 'Bold',
        }.get(weight, weight)
        
        # Get the font URL from Google Fonts CSS API
        css_url = f"https://fonts.googleapis.com/css2?family={google_font_name}:wght@{weight}"
        
        try:
            req = urllib.request.Request(css_url, headers={'User-Agent': 'Mozilla/5.0'})
            with urllib.request.urlopen(req, timeout=10) as response:
                css = response.read().decode('utf-8')
            
            # Extract TTF URL from CSS
            match = re.search(r'https://fonts\.gstatic\.com/[^)]+\.ttf', css)
            if not match:
                continue
            
            ttf_url = match.group(0)
            
            # Create filename
            clean_name = google_font_name.replace('+', '')
            filename = f"{clean_name}-{weight_name}.ttf"
            filepath = os.path.join(fonts_dir, filename)
            
            # Skip if already exists
            if os.path.exists(filepath):
                print(f"  ✓ {filename} already exists")
                downloaded.append(filename)
                continue
            
            # Download the font
            urllib.request.urlretrieve(ttf_url, filepath)
            print(f"  ✓ Downloaded {filename}")
            downloaded.append(filename)
            
        except Exception as e:
            print(f"  ✗ Failed to download {google_font_name} weight {weight}: {e}")
    
    return downloaded

def auto_download_fonts_for_pdf(pdf_path):
    """Main function: extract fonts from PDF and download Google Font equivalents"""
    print(f"Analyzing PDF: {pdf_path}")
    
    # Extract font names
    pdf_fonts = extract_font_names(pdf_path)
    print(f"Found {len(pdf_fonts)} fonts in PDF:")
    for f in pdf_fonts:
        print(f"  - {f}")
    
    fonts_dir = get_fonts_dir()
    print(f"\nDownloading to: {fonts_dir}")
    
    # Track downloaded fonts
    all_downloaded = []
    font_mapping = {}
    
    for pdf_font in pdf_fonts:
        google_font = find_google_font(pdf_font)
        weights = get_font_weights(pdf_font)
        
        print(f"\n{pdf_font} -> Google Font: {google_font} (weights: {weights})")
        
        downloaded = download_google_font(google_font, weights, fonts_dir)
        all_downloaded.extend(downloaded)
        
        # Build mapping for the simple script
        for filename in downloaded:
            # Map various forms of the PDF font name to the downloaded file
            base = pdf_font.split('-')[0].split('_')[0]
            if 'Bold' in filename or '700' in pdf_font.lower():
                font_mapping[f"{base}_700wght"] = filename
                font_mapping[f"{base}-Bold"] = filename
            else:
                font_mapping[base] = filename
                font_mapping[f"{base}-Regular"] = filename
    
    print(f"\n✓ Downloaded {len(all_downloaded)} font files")
    
    # Update the font mapping in apply_pdf_edits_simple.py
    update_font_mapping(font_mapping, fonts_dir)
    
    return all_downloaded

def update_font_mapping(new_mappings, fonts_dir):
    """Update the FONT_FILES mapping in apply_pdf_edits_simple.py"""
    script_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'apply_pdf_edits_simple.py')
    
    if not os.path.exists(script_path):
        return
    
    # Read existing mappings
    with open(script_path, 'r') as f:
        content = f.read()
    
    # Find existing FONT_FILES dict
    match = re.search(r'FONT_FILES\s*=\s*\{([^}]+)\}', content, re.DOTALL)
    if not match:
        return
    
    # Parse existing entries
    existing = {}
    for line in match.group(1).split('\n'):
        m = re.match(r"\s*['\"]([^'\"]+)['\"]\s*:\s*['\"]([^'\"]+)['\"]", line)
        if m:
            existing[m.group(1)] = m.group(2)
    
    # Add new mappings
    for key, filename in new_mappings.items():
        existing[key] = f"fonts/{filename}"
    
    # Build new dict string
    entries = [f"    '{k}': '{v}'," for k, v in sorted(existing.items())]
    new_dict = "FONT_FILES = {\n" + "\n".join(entries) + "\n}"
    
    # Replace in content
    new_content = re.sub(r'FONT_FILES\s*=\s*\{[^}]+\}', new_dict, content, flags=re.DOTALL)
    
    with open(script_path, 'w') as f:
        f.write(new_content)
    
    print(f"✓ Updated font mappings in apply_pdf_edits_simple.py")

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: auto_download_fonts.py <pdf_path>")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    if not os.path.exists(pdf_path):
        print(f"Error: File not found: {pdf_path}")
        sys.exit(1)
    
    auto_download_fonts_for_pdf(pdf_path)
