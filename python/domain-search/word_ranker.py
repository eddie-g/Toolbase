#!/usr/bin/env python3
"""
Word Ranking System for NetKit

Scores dictionary words against thematic baskets using two pillars:

  Score(w, B) = Similarity(w, B) × Weight_popularity(w)

Pillar A — Semantic Similarity
  Uses FastText word vectors (handles out-of-vocabulary words via sub-word
  embeddings). Each basket is represented as a centroid vector computed by
  averaging its anchor words. Similarity is cosine similarity between the
  target word and the basket centroid.

Pillar B — Popularity Weighting
  Uses the WordFreq library (Zipf scale 0–8). Raw Zipf scores are normalised
  to a 0.1–1.0 multiplier so obscure words are penalised but never zeroed.

Usage:
  python3 word_ranker.py --limit 500 --update-db
  python3 word_ranker.py --limit 50 --show 20 --model glove-wiki-gigaword-100
  python3 word_ranker.py --word abyss
  python3 word_ranker.py --words abyss nebula absinths love

Dependencies:
  pip install gensim wordfreq numpy mysql-connector-python tqdm
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from typing import Dict, List, Optional, Tuple

import numpy as np
from tqdm import tqdm

# ---------------------------------------------------------------------------
# Lazy imports with friendly error messages
# ---------------------------------------------------------------------------

try:
    import gensim.downloader as gensim_api
    from gensim.models import KeyedVectors
except ImportError:
    print("Error: gensim not installed.", file=sys.stderr)
    print("Install with: pip install gensim", file=sys.stderr)
    sys.exit(1)

try:
    from wordfreq import zipf_frequency
except ImportError:
    print("Error: wordfreq not installed.", file=sys.stderr)
    print("Install with: pip install wordfreq", file=sys.stderr)
    sys.exit(1)

try:
    import mysql.connector
except ImportError:
    mysql = None  # DB features disabled; CLI word scoring still works

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

# Baskets: each key is a category, value is a list of anchor words whose
# vectors are averaged to form the basket centroid.
BASKETS: Dict[str, List[str]] = {
    "space": [
        "galaxy", "stars", "vacuum", "orbit", "void", "nebula", "comet",
        "asteroid", "planet", "cosmos", "satellite", "rocket", "lunar",
        "solar", "gravity",
    ],
    "fantasy": [
        "dragon", "wizard", "magic", "sword", "castle", "quest", "elf",
        "knight", "kingdom", "mythical", "sorcery", "enchanted", "throne",
        "legend", "fairy",
    ],
    "tech": [
        "computer", "software", "algorithm", "data", "network", "server",
        "code", "digital", "processor", "internet", "cloud", "hardware",
        "binary", "encryption", "programming",
    ],
    "romance": [
        "love", "heart", "passion", "kiss", "romance", "desire", "embrace",
        "tender", "affection", "intimate", "devotion", "sweetheart",
        "soulmate", "charming", "dating",
    ],
    "scifi": [
        "android", "cyborg", "spaceship", "dystopia", "teleport", "alien",
        "laser", "hologram", "warp", "mutant", "clone", "dimension",
        "futuristic", "quantum", "cyberpunk",
    ],
}

# Zipf scale boundaries for normalisation.
# zipf_frequency returns ~0 for unknown words, up to ~7–8 for "the".
ZIPF_MIN = 1.0   # Below this → floor multiplier
ZIPF_MAX = 7.0   # Above this → ceiling multiplier
POP_FLOOR = 0.1  # Minimum popularity multiplier
POP_CEIL = 1.0   # Maximum popularity multiplier

# Default FastText model — handles OOV via sub-word embeddings.
# Alternatives: "glove-wiki-gigaword-100", "glove-wiki-gigaword-300",
#               "fasttext-wiki-news-subwords-300"
DEFAULT_MODEL = "fasttext-wiki-news-subwords-300"

# ---------------------------------------------------------------------------
# Database helpers (reuse pattern from score_dictionary_space.py)
# ---------------------------------------------------------------------------


def get_db_connection():
    """Create MySQL connection using environment variables."""
    if mysql is None or mysql.connector is None:
        raise RuntimeError(
            "mysql-connector-python is not installed. "
            "Install with: pip install mysql-connector-python"
        )
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "mysql"),
        user=os.getenv("MYSQL_USER", "sail"),
        password=os.getenv("MYSQL_PASSWORD", "password"),
        database=os.getenv("MYSQL_DATABASE", "toolbase"),
    )


def fetch_words(limit: int) -> List[Tuple[int, str]]:
    """Fetch words from dictionary table that haven't been ranked yet."""
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute(
        "SELECT id, word FROM dictionary WHERE word_ranker_scan IS NULL ORDER BY id ASC LIMIT %s",
        (limit,),
    )
    words = cursor.fetchall()
    cursor.close()
    conn.close()
    return words


def update_scores(results: List[dict]) -> int:
    """
    Write scored results back to the dictionary table.

    Each result dict contains:
        word_id, word, scores (dict of basket→float), popularity, best_basket, best_score
    """
    conn = get_db_connection()
    cursor = conn.cursor()
    updated = 0

    categories = list(BASKETS.keys())

    for r in results:
        cursor.execute(
            """UPDATE dictionary
               SET space = %s, fantasy = %s, tech = %s, romance = %s, scifi = %s,
                   popularity = %s, word_ranker_scan = NOW()
               WHERE id = %s""",
            (
                round(r["scores"].get("space", 0), 3),
                round(r["scores"].get("fantasy", 0), 3),
                round(r["scores"].get("tech", 0), 3),
                round(r["scores"].get("romance", 0), 3),
                round(r["scores"].get("scifi", 0), 3),
                round(r["popularity"], 4),
                r["word_id"],
            ),
        )
        updated += 1

    conn.commit()
    cursor.close()
    conn.close()
    return updated


# ---------------------------------------------------------------------------
# Core scoring logic
# ---------------------------------------------------------------------------


def load_model(model_name: str) -> KeyedVectors:
    """Load a gensim KeyedVectors model (downloads on first use)."""
    print(f"\n📦 Loading model: {model_name} ...")
    model = gensim_api.load(model_name)
    print(f"✓  Model loaded ({len(model.key_to_index):,} vectors, {model.vector_size}d)\n")
    return model


def build_basket_centroids(
    model: KeyedVectors, baskets: Dict[str, List[str]]
) -> Dict[str, np.ndarray]:
    """
    For each basket, average its anchor word vectors to produce a centroid.
    Skips anchor words not in the model vocabulary.
    """
    centroids: Dict[str, np.ndarray] = {}
    for name, anchors in baskets.items():
        vecs = []
        missing = []
        for w in anchors:
            if w in model:
                vecs.append(model[w])
            else:
                missing.append(w)
        if missing:
            print(f"  ⚠  Basket '{name}': anchor words not in model: {missing}")
        if not vecs:
            print(f"  ❌ Basket '{name}': no anchor words found — skipping")
            continue
        centroids[name] = np.mean(vecs, axis=0)
    return centroids


def cosine_similarity(a: np.ndarray, b: np.ndarray) -> float:
    """Cosine similarity between two vectors (1.0 = identical)."""
    denom = np.linalg.norm(a) * np.linalg.norm(b)
    if denom == 0:
        return 0.0
    return float(np.dot(a, b) / denom)


def popularity_weight(word: str, lang: str = "en") -> float:
    """
    Map a word's Zipf frequency to a 0.1–1.0 multiplier.

    Zipf scale (wordfreq library):
        0   → word not in corpus
        1–3 → rare / specialised
        4–5 → common everyday word
        6–7 → extremely common (function words)

    We clamp to [ZIPF_MIN, ZIPF_MAX] and linearly scale to [POP_FLOOR, POP_CEIL].
    """
    z = zipf_frequency(word, lang)
    if z <= 0:
        return POP_FLOOR
    clamped = max(ZIPF_MIN, min(z, ZIPF_MAX))
    normalised = (clamped - ZIPF_MIN) / (ZIPF_MAX - ZIPF_MIN)
    return POP_FLOOR + normalised * (POP_CEIL - POP_FLOOR)


def score_word(
    word: str,
    model: KeyedVectors,
    centroids: Dict[str, np.ndarray],
) -> Optional[dict]:
    """
    Score a single word against all baskets.

    Returns dict with:
        word, scores (basket→weighted_score), similarity (basket→raw),
        popularity, best_basket, best_score
    Or None if the word is not in the model.
    """
    if word not in model:
        return None

    vec = model[word]
    pop = popularity_weight(word)

    similarities: Dict[str, float] = {}
    weighted: Dict[str, float] = {}

    for basket_name, centroid in centroids.items():
        sim = cosine_similarity(vec, centroid)
        similarities[basket_name] = round(sim, 4)
        weighted[basket_name] = round(sim * pop, 4)

    best_basket = max(weighted, key=weighted.get)  # type: ignore[arg-type]

    return {
        "word": word,
        "similarity": similarities,
        "scores": weighted,
        "popularity": pop,
        "best_basket": best_basket,
        "best_score": weighted[best_basket],
    }


# ---------------------------------------------------------------------------
# Batch processing
# ---------------------------------------------------------------------------


def score_batch(
    words: List[Tuple[int, str]],
    model: KeyedVectors,
    centroids: Dict[str, np.ndarray],
    show: int = 10,
) -> List[dict]:
    """Score a list of (id, word) tuples. Returns list of result dicts."""
    results: List[dict] = []
    skipped = 0
    printed = 0

    pbar = tqdm(words, desc="Scoring words", unit="word")
    categories = list(centroids.keys())

    for word_id, word in pbar:
        r = score_word(word, model, centroids)
        if r is None:
            skipped += 1
            pbar.set_postfix(scored=len(results), skipped=skipped)
            continue

        r["word_id"] = word_id
        results.append(r)
        pbar.set_postfix(scored=len(results), skipped=skipped)

        if printed < show:
            scores_str = " | ".join(
                f"{cat}: {r['scores'][cat]:.3f}" for cat in categories
            )
            pbar.write(
                f"  ✓ {word:<20} pop={r['popularity']:.2f}  "
                f"best={r['best_basket']}({r['best_score']:.3f})  {scores_str}"
            )
            printed += 1

    pbar.close()

    print(f"\n📊 Batch Summary:")
    print(f"   Total words:  {len(words)}")
    print(f"   Scored:       {len(results)}")
    print(f"   Skipped (OOV):{skipped}")

    return results


# ---------------------------------------------------------------------------
# CLI – single-word mode
# ---------------------------------------------------------------------------


def print_word_report(
    word: str,
    model: KeyedVectors,
    centroids: Dict[str, np.ndarray],
) -> None:
    """Pretty-print a full score report for a single word."""
    from wordfreq import zipf_frequency as zf

    r = score_word(word, model, centroids)
    if r is None:
        print(f"  ❌ '{word}' not found in model vocabulary.")
        return

    zipf = zf(word, "en")
    print(f"\n  Word: {word}")
    print(f"  Zipf frequency: {zipf:.2f}  →  popularity weight: {r['popularity']:.3f}")
    print(f"  {'Basket':<12} {'Similarity':>12} {'Weighted Score':>16}")
    print(f"  {'─' * 42}")
    for basket in centroids:
        sim = r["similarity"][basket]
        sc = r["scores"][basket]
        marker = " ◀ best" if basket == r["best_basket"] else ""
        print(f"  {basket:<12} {sim:>12.4f} {sc:>16.4f}{marker}")
    print()


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Score dictionary words against thematic baskets using "
        "FastText similarity × popularity weighting.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""\
Examples:
  %(prog)s --word abyss                    Score a single word
  %(prog)s --words abyss nebula love       Score multiple words
  %(prog)s --limit 500 --update-db         Batch-score from DB and write back
  %(prog)s --limit 100 --show 20           Batch-score, show top 20 in terminal
  %(prog)s --model glove-wiki-gigaword-100 Use a different model
""",
    )
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--word", type=str, help="Score a single word and print a detailed report."
    )
    mode.add_argument(
        "--words", nargs="+", type=str, help="Score multiple words and print reports."
    )
    mode.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Number of unranked words to pull from the database.",
    )
    parser.add_argument(
        "--show",
        type=int,
        default=10,
        help="How many scored results to print during batch mode (default: 10).",
    )
    parser.add_argument(
        "--model",
        type=str,
        default=DEFAULT_MODEL,
        help=f"Gensim model name (default: {DEFAULT_MODEL}).",
    )
    parser.add_argument(
        "--update-db",
        action="store_true",
        help="Write scores back to the database (batch mode only).",
    )
    parser.add_argument(
        "--threshold",
        type=float,
        default=0.3,
        help="Minimum weighted score to consider relevant (default: 0.3).",
    )

    args = parser.parse_args()

    print("\n" + "=" * 60)
    print("🏷️  NetKit Word Ranker")
    print("=" * 60)

    # Load model and build centroids
    model = load_model(args.model)
    centroids = build_basket_centroids(model, BASKETS)

    if not centroids:
        print("❌ No valid basket centroids — check anchor words.")
        sys.exit(1)

    # ── Single-word mode ──────────────────────────────────────────
    if args.word:
        print_word_report(args.word, model, centroids)
        return

    # ── Multi-word mode ───────────────────────────────────────────
    if args.words:
        for w in args.words:
            print_word_report(w, model, centroids)
        return

    # ── Batch / DB mode ───────────────────────────────────────────
    limit = args.limit or 100

    print(f"\n📝 Configuration:")
    print(f"   Model:        {args.model}")
    print(f"   Baskets:      {', '.join(centroids.keys())}")
    print(f"   Batch size:   {limit}")
    print(f"   Threshold:    {args.threshold}")
    print(f"   Update DB:    {'Yes' if args.update_db else 'No'}")

    words = fetch_words(limit)
    if not words:
        print("\n❌ No unranked words found in database.")
        return

    print(f"   Words loaded: {len(words)}\n")

    results = score_batch(words, model, centroids, show=args.show)

    if not results:
        print("\n❌ No words could be scored.")
        return

    # Show top results above threshold
    above = [r for r in results if r["best_score"] >= args.threshold]
    print(f"\n   Words above threshold ({args.threshold}): {len(above)}")

    if args.update_db:
        print(f"\n💾 Writing scores to database...")
        updated = update_scores(results)
        print(f"   ✓ Updated {updated} rows")
    else:
        print(f"\n⚠️  Database not updated (use --update-db to save results)")

    print("\n" + "=" * 60)
    print("✓  Ranking complete!")
    print("=" * 60 + "\n")


if __name__ == "__main__":
    main()
