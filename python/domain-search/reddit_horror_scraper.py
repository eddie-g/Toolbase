#!/usr/bin/env python3
"""
Reddit Horror Word Scraper
============================
Scrapes top posts (title + body) from:
  - r/nosleep
  - r/horror
  - r/creepypasta

Filters stop words via NLTK, counts horror-corpus frequencies,
then scores each word:

    score = horror_freq / (10 ** general_zipf_freq + 1)

High score = common in horror posts, rare in general English.

Usage:
    python reddit_horror_scraper.py [--output horror_words.json] [--top 1000]
                                    [--posts-per-sub 500] [--cache-dir .reddit_cache]

Output (flat JSON array):
    [
      { "word": "whispered", "score": 0.01234, "horror_freq": 812, "general_freq": 3.21 },
      ...
    ]
"""

from __future__ import annotations

import argparse
import json
import re
import time
from collections import Counter
from pathlib import Path

import nltk
import requests
from tqdm import tqdm
from wordfreq import zipf_frequency

# ── NLTK stop words ────────────────────────────────────────────────────────────
nltk.download("stopwords", quiet=True)
from nltk.corpus import stopwords  # noqa: E402

STOP_WORDS = set(stopwords.words("english"))

# ── Config ─────────────────────────────────────────────────────────────────────

SUBREDDITS     = ["nosleep", "horror", "creepypasta"]
MIN_WORD_LEN   = 4
REQUEST_DELAY  = 2.0   # polite delay between Reddit API calls (seconds)
MAX_RETRIES    = 3
REDDIT_HEADERS = {
    "User-Agent": "HorrorWordScraper/2.0 (educational; domain word scoring)"
}


# ── Helpers ────────────────────────────────────────────────────────────────────

def get_session() -> requests.Session:
    s = requests.Session()
    s.headers.update(REDDIT_HEADERS)
    return s


def fetch_posts(
    session: requests.Session,
    subreddit: str,
    max_posts: int,
    cache_dir: Path,
) -> list[dict]:
    """
    Fetch up to max_posts top-all-time posts from a subreddit.
    Caches each page of results as JSON so re-runs are instant.
    Returns list of {title, body} dicts.
    """
    cache_file = cache_dir / f"{subreddit}.json"
    if cache_file.exists():
        print(f"    Loading r/{subreddit} from cache …")
        return json.loads(cache_file.read_text())

    print(f"    Fetching r/{subreddit} …")
    posts: list[dict] = []
    after: str | None = None
    page = 0

    while len(posts) < max_posts:
        url = f"https://www.reddit.com/r/{subreddit}/top.json?limit=100&t=all"
        if after:
            url += f"&after={after}"

        for attempt in range(1, MAX_RETRIES + 1):
            try:
                resp = session.get(url, timeout=20)
                if resp.status_code == 429:
                    wait = int(resp.headers.get("Retry-After", 60))
                    print(f"      Rate-limited — waiting {wait}s …")
                    time.sleep(wait)
                    continue
                resp.raise_for_status()
                break
            except requests.RequestException as e:
                if attempt == MAX_RETRIES:
                    print(f"      ⚠  Failed after {MAX_RETRIES} attempts: {e}")
                    after = None
                    break
                time.sleep(REQUEST_DELAY * attempt)
        else:
            break

        data = resp.json().get("data", {})
        children = data.get("children", [])
        if not children:
            break

        for child in children:
            p = child.get("data", {})
            title    = p.get("title", "") or ""
            selftext = p.get("selftext", "") or ""
            # Skip removed/deleted
            if selftext in ("[removed]", "[deleted]"):
                selftext = ""
            posts.append({"title": title, "body": selftext})

        page += 1
        after = data.get("after")
        print(f"      Page {page}: {len(children)} posts fetched (total: {len(posts)})")

        if not after or len(posts) >= max_posts:
            break

        time.sleep(REQUEST_DELAY)

    # Trim to requested limit
    posts = posts[:max_posts]

    # Cache to disk
    cache_file.write_text(json.dumps(posts, ensure_ascii=False))
    print(f"      Cached {len(posts)} posts → {cache_file}")

    return posts


def clean_text(text: str) -> list[str]:
    """
    Lowercase → strip non-alpha → split → remove stop words + short words.
    """
    text = text.lower()
    text = re.sub(r"[^a-z\s]", " ", text)
    return [
        w for w in text.split()
        if w not in STOP_WORDS and len(w) >= MIN_WORD_LEN
    ]


def horror_score(horror_freq: int, general_freq: float) -> float:
    """score = horror_freq / (10 ** general_zipf_freq + 1)"""
    return horror_freq / (10 ** general_freq + 1)


# ── Main ───────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(description="Reddit horror subreddit word scorer")
    parser.add_argument("--output",        default="horror_words.json")
    parser.add_argument("--top",           type=int,   default=1000, help="Words to emit")
    parser.add_argument("--posts-per-sub", type=int,   default=500,  help="Max posts per subreddit")
    parser.add_argument("--cache-dir",     default=".reddit_cache",  help="Cache dir")
    parser.add_argument("--min-zipf",      type=float, default=2.0,
                        help="Min wordfreq zipf score — filters out made-up words, usernames, "
                             "and character names (default 2.0; range ~1-8)")
    args = parser.parse_args()

    cache_dir = Path(args.cache_dir)
    cache_dir.mkdir(exist_ok=True)

    session = get_session()

    # ── 1. Scrape subreddits ───────────────────────────────────────────────────
    all_words: list[str] = []
    total_posts = 0

    for sub in SUBREDDITS:
        print(f"\n==> r/{sub}")
        posts = fetch_posts(session, sub, args.posts_per_sub, cache_dir)
        total_posts += len(posts)

        sub_words: list[str] = []
        for p in posts:
            combined = p["title"] + " " + p["body"]
            sub_words.extend(clean_text(combined))

        all_words.extend(sub_words)
        print(f"     → {len(posts)} posts | {len(sub_words):,} filtered tokens")

    print(f"\n==> Total: {total_posts} posts | {len(all_words):,} filtered tokens")

    # ── 2. Count & score ───────────────────────────────────────────────────────
    print("\n==> Counting word frequencies …")
    counts: Counter = Counter(all_words)

    print(f"==> Scoring {len(counts):,} unique words (min zipf={args.min_zipf}) …")
    results = []
    for word, freq in tqdm(counts.items(), desc="Scoring"):
        # Only pure-alpha words
        if not re.fullmatch(r"[a-z]+", word):
            continue
        g_freq = zipf_frequency(word, "en")
        # Skip words not recognised by general English (usernames, made-up names, etc.)
        if g_freq < args.min_zipf:
            continue
        score  = horror_score(freq, g_freq)
        results.append({
            "word":         word,
            "score":        round(score, 8),
            "horror_freq":  freq,
            "general_freq": g_freq,
        })

    results.sort(key=lambda x: x["score"], reverse=True)
    top_words = results[: args.top]

    # ── 3. Write JSON ──────────────────────────────────────────────────────────
    out_path = Path(args.output)
    out_path.write_text(json.dumps(top_words, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\n==> Saved {len(top_words):,} words → {out_path.resolve()}")

    # Preview top 40
    print(f"\nTop 40 horror words:")
    print(f"{'WORD':<22} {'HORROR_FREQ':>12}  {'GENERAL_FREQ':>12}  {'SCORE':>14}")
    print("-" * 66)
    for e in top_words[:40]:
        print(
            f"{e['word']:<22} {e['horror_freq']:>12,}  "
            f"{e['general_freq']:>12.2f}  {e['score']:>14.8f}"
        )


if __name__ == "__main__":
    main()
