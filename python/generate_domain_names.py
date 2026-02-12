#!/usr/bin/env python3
"""
Domain Name Generator
Generate creative domain name ideas from a seed/prefix word.
"""

import argparse
import json
import random
import sys

# ─── Suffixes organized by category ─────────────────────────────────────────

TECH_SUFFIXES = [
    "ai", "io", "app", "dev", "hub", "lab", "labs", "net", "sys", "bit",
    "byte", "code", "data", "tech", "wire", "core", "node", "sync", "link",
    "grid", "base", "cloud", "stack", "ware", "ops", "api", "bot", "cpu",
    "pixel", "logic", "cyber", "nano", "meta", "digital", "matrix",
]

POWER_SUFFIXES = [
    "forge", "fire", "bolt", "blast", "force", "fury", "storm", "strike",
    "pulse", "rush", "wave", "surge", "spark", "blaze", "flare", "glow",
    "beam", "ray", "flash", "shock", "power", "energy", "thrust", "drive",
]

NATURE_SUFFIXES = [
    "bloom", "leaf", "root", "seed", "stone", "river", "field", "wind",
    "sky", "sun", "moon", "peak", "vale", "grove", "reef", "coast",
    "shore", "lake", "wood", "frost", "ice", "snow", "dawn", "dusk",
    "tide", "crest", "ridge", "canyon", "sea", "ocean", "eagle", "hawk",
    "wolf", "fox", "bear", "lion",
]

ABSTRACT_SUFFIXES = [
    "ify", "ly", "ful", "zen", "ora", "ity", "ous", "ium", "eon", "ix",
    "ux", "ex", "ist", "ism", "ive", "ant", "ent", "ary", "ory", "ica",
    "ella", "ello", "ino", "ina", "ova", "ero", "ara", "ura",
]

ACTION_SUFFIXES = [
    "go", "run", "fly", "hop", "leap", "rise", "lift", "shift", "flow",
    "dash", "zoom", "snap", "tap", "click", "flip", "spin", "turn",
    "push", "pull", "drop", "pop", "grab", "hunt", "seek", "find",
    "scout", "quest", "reach", "call", "cast", "craft", "build", "make",
]

PLACE_SUFFIXES = [
    "land", "ville", "city", "town", "port", "haven", "nest", "den",
    "hive", "dock", "yard", "camp", "fort", "keep", "hall", "tower",
    "gate", "bridge", "path", "way", "lane", "trail", "space", "zone",
    "realm", "world", "verse", "scape", "domain",
]

BUSINESS_SUFFIXES = [
    "co", "inc", "pro", "plus", "max", "prime", "works", "group",
    "team", "crew", "squad", "clan", "guild", "pack", "collective",
    "studio", "shop", "store", "market", "trade", "vault", "stock",
    "fund", "bank", "mint",
]

# ─── Prefixes that can go before the seed word ───────────────────────────────

PREFIXES = [
    "go", "my", "get", "try", "use", "the", "hey", "hi", "re", "un",
    "one", "all", "any", "top", "big", "new", "pro", "ultra", "super",
    "mega", "hyper", "omni", "neo", "ever", "true",
]

ALL_SUFFIXES = (
    TECH_SUFFIXES + POWER_SUFFIXES + NATURE_SUFFIXES + ABSTRACT_SUFFIXES +
    ACTION_SUFFIXES + PLACE_SUFFIXES + BUSINESS_SUFFIXES
)


def generate_names(seed: str, count: int = 100) -> list[str]:
    """Generate creative domain name ideas from a seed word."""
    seed = seed.strip().lower()
    names = set()

    # 1. seed + suffix (main strategy — most names)
    for suffix in ALL_SUFFIXES:
        names.add(seed + suffix)

    # 2. prefix + seed
    for prefix in PREFIXES:
        names.add(prefix + seed)

    # 3. seed + single vowel/consonant endings (short brandable)
    for ch in "aeiouxyz":
        names.add(seed + ch)

    # 4. doubled last letter + common endings
    if seed:
        for ending in ["er", "ly", "ed", "le", "ie", "ey", "ar", "or"]:
            names.add(seed + ending)

    # 5. seed with minor mutations (drop last vowel, etc.)
    if len(seed) > 3 and seed[-1] in "aeiou":
        base = seed[:-1]
        for suffix in ["ify", "io", "ly", "er", "ix", "al"]:
            names.add(base + suffix)

    # Remove the seed itself and anything too short
    names.discard(seed)
    names = [n for n in names if len(n) >= 4 and len(n) <= 20 and n.isalnum()]

    # Sort alphabetically then pick up to count
    names.sort()
    if len(names) > count:
        # Keep a good mix: take every Nth to spread across alphabet
        step = len(names) / count
        picked = []
        i = 0.0
        while len(picked) < count and int(i) < len(names):
            picked.append(names[int(i)])
            i += step
        names = sorted(picked)

    return names[:count]


def main():
    parser = argparse.ArgumentParser(
        description="Generate domain name ideas from a seed word",
    )
    parser.add_argument("seed", help="The seed/prefix word (e.g., 'star')")
    parser.add_argument(
        "-n", "--count",
        type=int,
        default=100,
        help="Number of names to generate (default: 100)",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Output as JSON array",
    )

    args = parser.parse_args()
    names = generate_names(args.seed, args.count)

    if args.json:
        print(json.dumps(names))
    else:
        print(f"\n  Generated {len(names)} domain ideas for '{args.seed}':\n")
        for i, name in enumerate(names, 1):
            print(f"  {i:3}. {name}")
        print()


if __name__ == "__main__":
    main()
