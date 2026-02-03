# PDF Text Extraction Scripts

Python scripts to extract text from PDF files and save to database.

## Prerequisites

Install system dependencies:

```bash
# For PyMuPDF (fast, recommended)
pip install PyMuPDF

# Install all Python dependencies
pip install -r requirements.txt
```

## Scripts

### extract_pdf_pymupdf.py (⚡ Fast - Recommended)

Uses PyMuPDF to extract native PDF text with positioning data.

**Usage:**
```bash
python extract_pdf_pymupdf.py /path/to/document.pdf [document_id]
```

**Features:**
- 10-100x faster than OCR
- Extracts text with precise coordinates
- Captures font, size, color, bold/italic
- Saves to `pdf_extractions_fitz` table

## Automatic Processing

When you upload a PDF, PyMuPDF extraction runs automatically for fast, accurate text extraction with font and positioning data.

## Database Tables

### pdf_extractions_fitz (PyMuPDF)
- Native PDF text extraction
- Font and styling information
- Fast processing


## Output

The script extracts:
- Full text from each page
- Word-level details (text, position, confidence)
- Bounding boxes for each word
- Page dimensions

All data is stored in JSON format in the `extraction_data` column for easy parsing.
