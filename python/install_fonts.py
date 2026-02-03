#!/usr/bin/env python3
"""
Install fonts from PDF extraction data.
This script attempts to download and install fonts found in PDFs with their specific weights.
"""

import sys
import json
import subprocess
import os
import tempfile
import urllib.request
import urllib.parse
from pathlib import Path

def get_font_name(raw_font_name):
    """Extract clean font name from PDF font name."""
    if not raw_font_name:
        return None
    
    # Remove PDF font prefixes (e.g., "ABCDEF+FontName")
    cleaned = raw_font_name
    if '+' in cleaned:
        parts = cleaned.split('+', 1)
        if len(parts[0]) == 6 and parts[0].isupper():
            cleaned = parts[1]
    
    # Remove 6-character prefix without '+' (e.g., "PdbpbbLato" -> "Lato")
    if len(cleaned) > 6:
        # Check if first 6 chars look like a random prefix
        prefix = cleaned[:6]
        # If it's mixed case or looks random, and followed by uppercase letter
        if prefix.lower() != prefix and prefix.upper() != prefix and len(cleaned) > 6:
            if cleaned[6].isupper():
                cleaned = cleaned[6:]
    
    # Extract base family name (remove style suffixes like -Bold, -Italic, Thin, Light, etc.)
    base_name = cleaned.split('-')[0].split('_')[0].split(',')[0]
    
    # Remove common weight suffixes that are part of font name
    weight_suffixes = ['Thin', 'ExtraLight', 'Light', 'Regular', 'Medium', 'SemiBold', 'Bold', 'ExtraBold', 'Black']
    for suffix in weight_suffixes:
        if base_name.endswith(suffix) and len(base_name) > len(suffix):
            base_name = base_name[:-len(suffix)]
            break
    
    # Filter out invalid font names that are just weight/style descriptors
    invalid_names = ['regular', 'bold', 'italic', 'light', 'medium', 'thin', 'black', 'semibold', 'demibold']
    if base_name.lower() in invalid_names:
        return None
    
    return base_name

def map_to_google_font(font_name):
    """Map PDF font names to Google Fonts equivalents."""
    font_mappings = {
        'TimesNewRoman': 'Tinos',
        'Times': 'Tinos',
        'TimesRoman': 'Tinos',
        'Arial': 'Arial',
        'ArialMT': 'Arial',
        'Helvetica': 'Arial',
        'HelveticaNeue': 'Arial',
        'Courier': 'Courier Prime',
        'CourierNew': 'Courier Prime',
        'Calibri': 'Carlito',
        'Verdana': 'Verdana',
        'Georgia': 'Georgia',
        'Palatino': 'Palatino',
        'Garamond': 'EB Garamond',
        'BookmanOldStyle': 'Bookman',
        'ComicSansMS': 'Comic Neue',
        'Impact': 'Impact',
        'TrebuchetMS': 'Trebuchet MS',
        'Roboto': 'Roboto',
        'OpenSans': 'Open Sans',
        'Lato': 'Lato',
        'Montserrat': 'Montserrat',
        'SourceSansPro': 'Source Sans Pro',
        'PTSans': 'PT Sans',
        'Raleway': 'Raleway',
        'NotoSans': 'Noto Sans',
    }
    
    # Check for direct mapping (case-insensitive)
    for key, value in font_mappings.items():
        if font_name.lower() == key.lower():
            return value
    
    # If no mapping found, return the font name as-is
    # Google Fonts might have it under the exact name
    return font_name

def get_font_weights(extraction_data):
    """Extract unique font families and their weights from extraction data."""
    fonts = {}
    
    for page_data in extraction_data:
        if 'blocks' in page_data:
            for block in page_data['blocks']:
                if 'font' in block and block['font']:
                    raw_font = block['font']
                    font_name = get_font_name(raw_font)
                    if font_name:
                        mapped_font = map_to_google_font(font_name)
                        
                        if mapped_font not in fonts:
                            fonts[mapped_font] = {
                                'regular': False,
                                'bold': False,
                                'italic': False,
                                'bold_italic': False
                            }
                        
                        # Determine weight from block properties AND font name
                        is_bold = block.get('bold', False) or 'Bold' in raw_font or 'Black' in raw_font
                        is_italic = block.get('italic', False) or 'Italic' in raw_font or 'Oblique' in raw_font
                        
                        # Thin/Light variants should be treated as regular for Google Fonts
                        if 'Thin' in raw_font or 'Light' in raw_font or 'ExtraLight' in raw_font:
                            fonts[mapped_font]['regular'] = True
                        elif is_bold and is_italic:
                            fonts[mapped_font]['bold_italic'] = True
                        elif is_bold:
                            fonts[mapped_font]['bold'] = True
                        elif is_italic:
                            fonts[mapped_font]['italic'] = True
                        else:
                            fonts[mapped_font]['regular'] = True
        
        if 'words' in page_data:
            for word in page_data['words']:
                if 'font' in word and word['font']:
                    raw_font = word['font']
                    font_name = get_font_name(raw_font)
                    if font_name:
                        mapped_font = map_to_google_font(font_name)
                        
                        if mapped_font not in fonts:
                            fonts[mapped_font] = {
                                'regular': False,
                                'bold': False,
                                'italic': False,
                                'bold_italic': False
                            }
                        
                        # For words, just mark as regular weight
                        fonts[mapped_font]['regular'] = True
    
    return fonts

def download_google_font(font_family, weights):
    """Download font from Google Fonts API."""
    # Build weight specification for Google Fonts API
    weight_specs = []
    if weights['regular']:
        weight_specs.append('0,400')
    if weights['bold']:
        weight_specs.append('0,700')
    if weights['italic']:
        weight_specs.append('1,400')
    if weights['bold_italic']:
        weight_specs.append('1,700')
    
    if not weight_specs:
        weight_specs.append('0,400')  # Default to regular
    
    # Construct Google Fonts API URL
    font_family_encoded = urllib.parse.quote(font_family)
    weights_param = ';'.join(weight_specs)
    url = f"https://fonts.googleapis.com/css2?family={font_family_encoded}:ital,wght@{weights_param}&display=swap"
    
    try:
        # Download CSS to get font file URLs
        with urllib.request.urlopen(url, timeout=10) as response:
            css_content = response.read().decode('utf-8')
        
        print(f"✓ Found {font_family} on Google Fonts")
        return css_content
    except urllib.error.HTTPError as e:
        if e.code == 404:
            print(f"✗ {font_family} not found on Google Fonts (404)")
        else:
            print(f"✗ HTTP error downloading {font_family}: {e.code}")
        return None
    except urllib.error.URLError as e:
        print(f"✗ Network error downloading {font_family}: {e.reason}")
        return None
    except Exception as e:
        print(f"✗ Could not download {font_family}: {e}")
        return None

def install_font_system(font_family):
    """Attempt to install font via system package manager."""
    # Try common Linux package managers
    try:
        # Ubuntu/Debian
        subprocess.run(['apt-cache', 'search', f'fonts-{font_family.lower()}'], 
                      capture_output=True, timeout=5)
        print(f"→ Font {font_family} may be available via apt-get")
    except:
        pass
    
    try:
        # Arch Linux
        subprocess.run(['pacman', '-Ss', font_family.lower()], 
                      capture_output=True, timeout=5)
        print(f"→ Font {font_family} may be available via pacman")
    except:
        pass

def main():
    """Main function to install fonts from extraction data."""
    if len(sys.argv) < 2:
        print("Usage: python install_fonts.py <extraction_data.json> [output_css_path]")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_css_path = sys.argv[2] if len(sys.argv) > 2 else None
    
    try:
        with open(input_file, 'r') as f:
            extraction_data = json.load(f)
    except FileNotFoundError:
        result = {'success': False, 'error': f'File not found: {input_file}'}
        print(json.dumps(result))
        sys.exit(1)
    except json.JSONDecodeError as e:
        result = {'success': False, 'error': f'Invalid JSON in file: {str(e)}'}
        print(json.dumps(result))
        sys.exit(1)
    except Exception as e:
        result = {'success': False, 'error': f'Error reading extraction data: {str(e)}'}
        print(json.dumps(result))
        sys.exit(1)
    
    print("Analyzing fonts in PDF...")
    fonts = get_font_weights(extraction_data)
    
    if not fonts:
        result = {
            'success': False,
            'error': 'No fonts found in extraction data',
            'total_fonts': 0,
            'loaded_fonts': 0,
            'font_results': {}
        }
        print(json.dumps(result))
        sys.exit(0)
    
    print(f"\nFound {len(fonts)} unique font families:")
    for font_family, weights in fonts.items():
        weight_list = []
        if weights['regular']:
            weight_list.append('Regular')
        if weights['bold']:
            weight_list.append('Bold')
        if weights['italic']:
            weight_list.append('Italic')
        if weights['bold_italic']:
            weight_list.append('Bold Italic')
        
        print(f"  • {font_family}: {', '.join(weight_list)}")
    
    print("\nAttempting to load fonts from Google Fonts...")
    
    # Create CSS import file
    css_imports = []
    success_count = 0
    font_results = {}
    
    for font_family, weights in fonts.items():
        css_content = download_google_font(font_family, weights)
        if css_content:
            css_imports.append(css_content)
            success_count += 1
            font_results[font_family] = {'status': 'success', 'weights': list(weights.keys())}
        else:
            font_results[font_family] = {'status': 'failed', 'weights': list(weights.keys()), 'reason': 'Not found on Google Fonts'}
            # Try system installation as fallback
            install_font_system(font_family)
    
    # Save CSS imports to a file for optional use
    if css_imports:
        if output_css_path:
            output_css = output_css_path
        else:
            output_css = os.path.join(os.path.dirname(input_file), 'loaded_fonts.css')
        
        try:
            with open(output_css, 'w') as f:
                f.write('\n\n'.join(css_imports))
            print(f"\n✓ Successfully loaded {success_count}/{len(fonts)} fonts")
            print(f"✓ CSS saved to: {output_css}")
        except PermissionError:
            print(f"\n✗ Permission denied writing to: {output_css}")
            # Try fallback location in temp directory
            fallback_path = os.path.join(tempfile.gettempdir(), 'loaded_fonts.css')
            try:
                with open(fallback_path, 'w') as f:
                    f.write('\n\n'.join(css_imports))
                print(f"✓ CSS saved to fallback location: {fallback_path}")
            except Exception as e:
                result = {
                    'success': False,
                    'error': f'Failed to write CSS file: {str(e)}',
                    'total_fonts': len(fonts),
                    'loaded_fonts': success_count,
                    'font_results': font_results
                }
                print(json.dumps(result))
                sys.exit(1)
        except Exception as e:
            result = {
                'success': False,
                'error': f'Failed to write CSS file: {str(e)}',
                'total_fonts': len(fonts),
                'loaded_fonts': success_count,
                'font_results': font_results
            }
            print(json.dumps(result))
            sys.exit(1)
    else:
        print("\n✗ Could not load any fonts")
    
    # Return JSON result
    result = {
        'success': True,
        'total_fonts': len(fonts),
        'loaded_fonts': success_count,
        'font_results': font_results
    }
    
    print(json.dumps(result))

if __name__ == '__main__':
    main()
