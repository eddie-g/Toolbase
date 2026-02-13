#!/usr/bin/env python3
"""
Score dictionary words across multiple categories (space, fantasy, tech, romance, scifi).
Uses a free dictionary API and local transformers model for semantic scoring.
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from pathlib import Path
from typing import List, Tuple, Optional

import requests
from tqdm import tqdm

try:
    from transformers import pipeline
except ImportError:
    print("Error: transformers library not installed.", file=sys.stderr)
    print("Install with: pip install --break-system-packages transformers torch", file=sys.stderr)
    sys.exit(1)

try:
    import mysql.connector
except ImportError:
    print("Error: mysql-connector-python not installed.", file=sys.stderr)
    sys.exit(1)


def get_db_connection():
    """Create direct MySQL connection using environment variables."""
    return mysql.connector.connect(
        host=os.getenv('MYSQL_HOST', 'mysql'),
        user=os.getenv('MYSQL_USER', 'sail'),
        password=os.getenv('MYSQL_PASSWORD', 'password'),
        database=os.getenv('MYSQL_DATABASE', 'toolbase')
    )


def fetch_words(limit: int) -> List[Tuple[int, str]]:
    """Fetch words from dictionary table that haven't been scanned yet."""
    conn = get_db_connection()
    cursor = conn.cursor()
    
    cursor.execute(
        "SELECT id, word FROM dictionary WHERE scanned IS NULL ORDER BY id ASC LIMIT %s",
        (limit,)
    )
    words = cursor.fetchall()
    
    cursor.close()
    conn.close()
    
    return words


def update_category_scores(results: List[Tuple[int, str, str, float, dict]], threshold: float = 0.3) -> int:
    """Update dictionary table with category scores (stores actual confidence values)."""
    conn = get_db_connection()
    cursor = conn.cursor()
    
    categories = ["space", "fantasy", "tech", "romance", "scifi",
                   "mystery", "thriller", "horror", "adventure", "historical", "drama", "action"]
    updated = 0
    
    for word_id, word, best_category, best_score, scores_dict in results:
        # Update all category scores at once with actual confidence values
        # Round to 3 decimal places to match DECIMAL(5,3) column type
        set_parts = []
        params = []
        for cat in categories:
            set_parts.append(f"category_{cat} = %s")
            params.append(round(scores_dict.get(cat, 0), 3))
        set_parts.append("scanned = NOW()")
        params.append(word_id)
        cursor.execute(
            f"UPDATE dictionary SET {', '.join(set_parts)} WHERE id = %s",
            params
        )
        
        # Count as updated if any category meets the threshold
        if any(scores_dict[cat] >= threshold for cat in categories):
            updated += 1
    
    conn.commit()
    cursor.close()
    conn.close()
    
    return updated


def is_abbreviation(definitions: List[str]) -> bool:
    """
    Check if a word is an abbreviation by examining its definitions.
    
    Returns True if any definition contains abbreviation-related keywords.
    """
    abbr_keywords = [
        'abbreviation',
        'initialism',
        'acronym',
        'short for',
        'abbr.',
        'contraction of',
        'shortened form',
        'symbol for',
    ]
    
    for definition in definitions[:3]:  # Check first 3 definitions
        definition_lower = definition.lower()
        if any(keyword in definition_lower for keyword in abbr_keywords):
            return True
    
    return False


def get_word_definitions(word: str) -> Optional[List[str]]:
    """
    Fetch word definitions from Free Dictionary API.
    
    Returns: List of individual definitions or None if not found.
    """
    try:
        url = f"https://api.dictionaryapi.dev/api/v2/entries/en/{word}"
        response = requests.get(url, timeout=5)
        
        if response.status_code == 200:
            data = response.json()
            
            # Extract ALL definitions from all meanings
            definitions = []
            for entry in data:
                for meaning in entry.get('meanings', []):
                    for definition in meaning.get('definitions', []):
                        def_text = definition.get('definition', '')
                        if def_text:
                            definitions.append(def_text)
            
            return definitions if definitions else None
        
        return None
        
    except Exception as e:
        print(f"API error for '{word}': {e}", file=sys.stderr)
        return None


def score_words(words: List[Tuple[int, str]], model_name: str, show: int, threshold: float = 0.3) -> List[Tuple[int, str, str, float, dict]]:
    """Score words against all categories using their definitions and return results."""
    print(f"\n📦 Loading model: {model_name}...")
    classifier = pipeline(
        "zero-shot-classification",
        model=model_name,
        device=-1  # CPU
    )
    print("✓ Model loaded successfully\n")

    categories = ["space", "fantasy", "tech", "romance", "scifi",
                   "mystery", "thriller", "horror", "adventure", "historical", "drama", "action"]
    hypothesis = "This definition describes something related to {}."

    results = []
    printed = 0
    scored_count = 0
    skipped_no_def = 0
    skipped_abbr = 0
    
    # Create progress bar
    pbar = tqdm(words, desc="Processing words", unit="word")
    
    for word_id, word in pbar:
        # Fetch all definitions from dictionary API
        definitions = get_word_definitions(word)
        
        if not definitions:
            skipped_no_def += 1
            pbar.set_postfix({"scored": scored_count, "skipped": skipped_no_def + skipped_abbr})
            continue
        
        # Skip abbreviations
        if is_abbreviation(definitions):
            skipped_abbr += 1
            pbar.set_postfix({"scored": scored_count, "skipped": skipped_no_def + skipped_abbr})
            continue
        
        # Score each definition and track max scores per category
        max_scores = {cat: 0.0 for cat in categories}
        
        for definition in definitions[:10]:  # Limit to 10 definitions for performance
            result = classifier(definition, categories, hypothesis_template=hypothesis)
            
            # Update max scores for each category
            for i, label in enumerate(result['labels']):
                score = result['scores'][i]
                if score > max_scores[label]:
                    max_scores[label] = score
        
        # Find the best matching category
        best_category = max(max_scores, key=max_scores.get)
        best_score = max_scores[best_category]
        
        # Store result
        results.append((word_id, word, best_category, best_score, max_scores))
        scored_count += 1
        
        # Update progress bar with current stats
        pbar.set_postfix({"scored": scored_count, "skipped": skipped_no_def + skipped_abbr})
        
        # Format scores for display - mark categories that meet threshold
        scores_str = " | ".join([
            f"{cat}: {max_scores[cat]:.3f}{'✓' if max_scores[cat] >= threshold else ''}" 
            for cat in categories
        ])
        
        if printed < show:
            # Show which categories would be flagged
            flagged = [cat for cat in categories if max_scores[cat] >= threshold]
            flagged_str = f" [{', '.join(flagged)}]" if flagged else " [none]"
            pbar.write(f"✓ {word}\t{best_category}\t{best_score:.4f}\t{scores_str}{flagged_str}")
            printed += 1
        
        # Small delay to be respectful to the API
        time.sleep(0.1)
    
    pbar.close()
    
    # Print summary
    print(f"\n📊 Processing Summary:")
    print(f"   Words processed: {len(words)}")
    print(f"   Successfully scored: {scored_count}")
    print(f"   Skipped (no definitions): {skipped_no_def}")
    print(f"   Skipped (abbreviations): {skipped_abbr}")
    
    if printed >= show and len(results) > show:
        print(f"\n--- Showing first {show} results ---")
    
    return results


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Score dictionary words across multiple categories (space, fantasy, tech, romance, scifi)",
    )
    parser.add_argument("--limit", type=int, default=10, help="How many words to scan")
    parser.add_argument("--show", type=int, default=5, help="How many results to print")
    parser.add_argument(
        "--model",
        default="valhalla/distilbart-mnli-12-1",
        help="HuggingFace model name",
    )
    parser.add_argument(
        "--threshold",
        type=float,
        default=0.5,
        help="Minimum score to mark category (default: 0.5)",
    )
    parser.add_argument(
        "--update-db",
        action="store_true",
        help="Update database with category assignments",
    )
    args = parser.parse_args()

    print("\n" + "="*60)
    print("🔍 Dictionary Scoring System")
    print("="*60)
    
    words = fetch_words(args.limit)
    if not words:
        print("❌ No words found to process.")
        return

    print(f"\n📝 Configuration:")
    print(f"   Words to process: {len(words)}")
    print(f"   Categories: space, fantasy, tech, romance, scifi")
    print(f"   Threshold: {args.threshold}")
    print(f"   Update database: {'Yes' if args.update_db else 'No'}")
    
    results = score_words(words, args.model, args.show, args.threshold)
    
    if not results:
        print("\n❌ No words could be scored.")
        return
    
    if args.update_db:
        print(f"\n💾 Updating database...")
        updated = update_category_scores(results, args.threshold)
        print(f"✓ Updated {updated} words in database (≥{args.threshold} threshold)")
    else:
        print(f"\n⚠️  Database not updated (use --update-db to save results)")
    
    print("\n" + "="*60)
    print("✓ Processing complete!")
    print("="*60 + "\n")


if __name__ == "__main__":
    main()
