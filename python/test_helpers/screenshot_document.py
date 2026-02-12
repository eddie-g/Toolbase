#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Screenshot Document Script

Takes a screenshot of a document's edit page using Playwright.

Usage (with venv):
    source python/venv/bin/activate
    python python/screenshot_document.py <document_id> [page_number]
    
Or directly:
    python/venv/bin/python python/screenshot_document.py <document_id> [page_number]
    
Arguments:
    document_id: The ID of the document to screenshot
    page_number: Optional page number for filename (default: 1)
    
Output:
    Saves screenshot to python/screenshots/<document_id>_page_<page>.png
"""

import sys
import os
import argparse
from pathlib import Path

def take_screenshot_playwright(document_id: int, page_num: int = 1, base_url: str = "http://localhost:8081", full_url: str = None, suffix: str = None):
    """
    Take a screenshot using Playwright (recommended - faster and more reliable)
    """
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("Playwright not installed. Install with: pip install playwright && playwright install")
        return False
    
    script_dir = Path(__file__).parent
    screenshots_dir = script_dir / "screenshots"
    screenshots_dir.mkdir(exist_ok=True)
    
    # Use full_url if provided, otherwise construct from base_url
    url = full_url if full_url else f"{base_url}/documents/{document_id}/edit"
    
    # Build filename with optional suffix (before/after)
    if suffix:
        screenshot_path = screenshots_dir / f"{document_id}_page_{page_num}_{suffix}.png"
    else:
        screenshot_path = screenshots_dir / f"{document_id}_page_{page_num}.png"
    
    print(f"📸 Taking screenshot of document {document_id}")
    print(f"   URL: {url}")
    
    with sync_playwright() as p:
        # Launch browser - use headed mode for debugging if needed
        browser = p.chromium.launch(headless=True)
        
        # Create a new page with a reasonable viewport
        page = browser.new_page(viewport={"width": 1920, "height": 1080})
        
        try:
            # Navigate to the document edit page
            print(f"   Navigating to page...")
            response = page.goto(url, wait_until="networkidle", timeout=60000)
            
            # Check if we got redirected (e.g., to login page)
            if response:
                print(f"   Status: {response.status}, URL: {page.url}")
            
            # Check if we're on a login page
            if 'login' in page.url.lower():
                print("   ⚠️  Redirected to login page - authentication required")
                print("   Taking screenshot of login page instead...")
                page.screenshot(path=str(screenshot_path))
                print(f"✅ Screenshot saved to: {screenshot_path}")
                browser.close()
                return True
            
            # Check if the page has an iframe (fullscreen view uses iframe)
            print(f"   Checking for iframe...")
            iframe_element = page.query_selector("iframe.viewer")
            
            if iframe_element:
                print("   ✓ Found iframe viewer, waiting for iframe to load...")
                # Wait for iframe to be attached
                page.wait_for_timeout(2000)
                
                # Get the iframe and wait for its content
                frame = page.frame_locator("iframe.viewer")
                try:
                    # Wait for some content in the iframe (PDF object or embed)
                    frame.locator("embed, object, img, canvas, body").first.wait_for(timeout=15000)
                    print("   ✓ Iframe content loaded")
                except Exception:
                    print("   ⚠️  Could not detect iframe content")
                
                # Give extra time for PDF to render in iframe
                print("   Waiting 8 seconds for PDF to render in iframe...")
                page.wait_for_timeout(8000)
            else:
                # Regular page - wait for content to load
                print(f"   Waiting for page to render...")
                
                # For overlay editor, wait for canvas to be visible and have content
                try:
                    print("   Checking for canvas element...")
                    page.wait_for_selector("#pdf-canvas", timeout=10000)
                    print("   ✓ Canvas found")
                    
                    # Wait for canvas to have dimensions (meaning it's been rendered)
                    page.wait_for_function("""
                        () => {
                            const canvas = document.getElementById('pdf-canvas');
                            return canvas && canvas.width > 0 && canvas.height > 0;
                        }
                    """, timeout=10000)
                    print("   ✓ Canvas has dimensions")
                    
                    # Extra wait for rendering to complete
                    page.wait_for_timeout(2000)
                except Exception as e:
                    print(f"   ⚠️  Canvas check failed: {e}, using fallback wait")
                    page.wait_for_timeout(3000)
            
            # Scroll to ensure content is visible (sometimes helps with lazy loading)
            page.evaluate("window.scrollTo(0, 0)")
            page.wait_for_timeout(500)
            
            # Try to screenshot just the canvas/overlay element instead of full page
            target_element = None
            
            # Look for the page container with canvas and overlay
            selectors_to_try = [
                "#pdf-container",       # Overlay editor container
                ".page-container",      # Container with canvas + overlay
                ".overlay",             # Overlay div
                "#viewer .page",        # PDF.js page
                "canvas[width][height]", # Canvas with dimensions
                ".viewer",              # Viewer container
            ]
            
            for selector in selectors_to_try:
                element = page.query_selector(selector)
                if element:
                    print(f"   ✓ Found target element: {selector}")
                    target_element = element
                    break
            
            if target_element:
                # Screenshot just the element
                target_element.screenshot(path=str(screenshot_path))
                print(f"✅ Element screenshot saved to: {screenshot_path}")
            else:
                # Fallback to viewport screenshot
                print("   ⚠️  Could not find target element, taking viewport screenshot")
                page.screenshot(path=str(screenshot_path), full_page=False)
                print(f"✅ Screenshot saved to: {screenshot_path}")
            
        except Exception as e:
            print(f"❌ Error during navigation/screenshot: {e}")
            # Take a screenshot anyway to see what happened
            error_path = screenshots_dir / f"{document_id}_error.png"
            page.screenshot(path=str(error_path))
            print(f"   Error screenshot saved to: {error_path}")
            browser.close()
            return False
        
        browser.close()
    
    return True


def take_screenshot_selenium(document_id: int, page_num: int = 1, base_url: str = "http://localhost:8081", full_url: str = None, suffix: str = None):
    """
    Take a screenshot using Selenium (fallback option)
    """
    try:
        from selenium import webdriver
        from selenium.webdriver.chrome.options import Options
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC
    except ImportError:
        print("Selenium not installed. Install with: pip install selenium")
        return False
    
    script_dir = Path(__file__).parent
    screenshots_dir = script_dir / "screenshots"
    screenshots_dir.mkdir(exist_ok=True)
    
    # Use full_url if provided, otherwise construct from base_url
    url = full_url if full_url else f"{base_url}/documents/{document_id}/edit"
    
    # Build filename with optional suffix (before/after)
    if suffix:
        screenshot_path = screenshots_dir / f"{document_id}_page_{page_num}_{suffix}.png"
    else:
        screenshot_path = screenshots_dir / f"{document_id}_page_{page_num}.png"
    
    print(f"📸 Taking screenshot of document {document_id} (Selenium)")
    print(f"   URL: {url}")
    
    # Set up Chrome options
    chrome_options = Options()
    chrome_options.add_argument("--headless")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.add_argument("--window-size=1920,1080")
    
    driver = webdriver.Chrome(options=chrome_options)
    
    try:
        # Navigate to the document edit page
        print(f"   Navigating to page...")
        driver.get(url)
        
        # Wait for the PDF viewer to load
        print(f"   Waiting for PDF to render...")
        wait = WebDriverWait(driver, 15)
        wait.until(EC.presence_of_element_located((By.CLASS_NAME, "viewer")))
        
        # Give extra time for PDF.js to render
        import time
        time.sleep(2)
        
        # Take the screenshot
        driver.save_screenshot(str(screenshot_path))
        print(f"✅ Screenshot saved to: {screenshot_path}")
        
    except Exception as e:
        print(f"❌ Error during navigation/screenshot: {e}")
        # Take a screenshot anyway to see what happened
        error_path = screenshots_dir / f"{document_id}_error.png"
        driver.save_screenshot(str(error_path))
        print(f"   Error screenshot saved to: {error_path}")
        driver.quit()
        return False
    
    driver.quit()
    return True


def main():
    parser = argparse.ArgumentParser(
        description="Take a screenshot of a document's edit page",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
    python screenshot_document.py 42
    python screenshot_document.py 42 --page 2
    python screenshot_document.py 42 --driver selenium
    python screenshot_document.py 42 --url http://myserver:8080
        """
    )
    parser.add_argument("document_id", type=int, help="The document ID to screenshot")
    parser.add_argument("--page", "-p", type=int, default=1, help="Page number for filename (default: 1)")
    parser.add_argument("--driver", "-d", choices=["playwright", "selenium"], default="playwright",
                        help="Which browser driver to use (default: playwright)")
    parser.add_argument("--url", "-u", default="http://localhost:8081",
                        help="Base URL of the application (default: http://localhost:8081)")
    parser.add_argument("--full-url", "-f", default=None,
                        help="Full URL to screenshot (overrides --url and document_id path construction)")
    parser.add_argument("--suffix", "-s", default=None,
                        help="Suffix to add to filename (e.g., 'before' or 'after')")
    
    args = parser.parse_args()
    
    if args.driver == "playwright":
        success = take_screenshot_playwright(args.document_id, args.page, args.url, args.full_url, args.suffix)
    else:
        success = take_screenshot_selenium(args.document_id, args.page, args.url, args.full_url, args.suffix)
    
    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
