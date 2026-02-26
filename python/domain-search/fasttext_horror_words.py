#!/usr/bin/env python3
"""
fastText Semantic Word Finder (multi-category)
================================================
Uses pre-trained fastText word vectors (fasttext-wiki-news-subwords-300)
to find the top N words closest to a centroid of curated seed words for
a given category (horror, fantasy, scifi, romance, mystery, thriller, etc.)

Steps:
  1. Load fasttext-wiki-news-subwords-300 via gensim (downloads ~1 GB once, then cached)
  2. Average seed-word vectors → category centroid
  3. Retrieve top N most-similar words from the full vocabulary
  4. Filter: stop words, profanity, short words, non-alphabetic
  5. Rank by cosine similarity score
  6. Save to JSON

Usage:
    python fasttext_horror_words.py --category horror  [--output fasttext_horror.json]  [--top 5000]
    python fasttext_horror_words.py --category fantasy [--output fasttext_fantasy.json] [--top 5000]

Output (flat JSON array, descending similarity):
    [
      { "word": "haunted",   "similarity": 0.743 },
      { "word": "possessed", "similarity": 0.731 },
      ...
    ]
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

import nltk
import numpy as np

nltk.download("stopwords", quiet=True)
from nltk.corpus import stopwords  # noqa: E402

from better_profanity import profanity as profanity_filter
from spellchecker import SpellChecker

profanity_filter.load_censor_words()
PROFANITY = profanity_filter.CENSOR_WORDSET

STOP_WORDS = set(stopwords.words("english"))

SPELL = SpellChecker()

# ── Category seed words ───────────────────────────────────────────────────────
SEEDS: dict[str, list[str]] = {
    "horror": [
        "vampire", "ghost", "demon", "werewolf", "zombie", "specter", "wraith",
        "poltergeist", "banshee", "revenant", "phantom", "ghoul", "shade",
        "nightmare", "monster", "creature", "beast",
        "haunted", "possessed", "cursed", "terrified", "screamed", "lurking",
        "stalked", "whispered", "shuddered", "trembled", "fled", "crawled",
        "clawed", "devoured", "slaughtered", "tortured",
        "darkness", "shadow", "dread", "sinister", "ominous", "eerie", "macabre",
        "haunting", "supernatural", "occult", "forsaken", "decrepit",
        "gloomy", "foreboding", "sepulchral", "abyssal", "eldritch", "grotesque",
        "death", "blood", "terror", "fear", "horror", "doom", "despair",
        "torment", "agony", "suffering", "violence", "malevolent", "evil",
        "wicked", "diabolical", "hellish", "infernal", "monstrous", "deadly",
        "crypt", "grave", "coffin", "cemetery", "graveyard", "dungeon", "abyss",
        "ritual", "sacrifice", "forbidden", "ancient", "undead",
    ],

    "fantasy": [
        # Races & beings
        "elf", "dwarf", "wizard", "sorcerer", "witch", "warlock", "druid",
        "paladin", "ranger", "bard", "necromancer", "enchantress", "mage",
        "dragon", "unicorn", "griffin", "phoenix", "faerie", "nymph",
        "goblin", "troll", "ogre", "centaur", "minotaur", "kraken", "hydra",
        # Magic & power
        "magic", "spell", "enchantment", "potion", "artifact", "rune",
        "arcane", "mythical", "legendary", "celestial", "divine", "mystical",
        "sorcery", "prophecy", "ancient", "sacred", "cursed", "blessed",
        "ethereal", "enchanted", "mystical", "runic", "eldritch", "arcane",
        # Places & things
        "realm", "kingdom", "castle", "dungeon", "fortress", "citadel",
        "portal", "amulet", "talisman", "grimoire", "tome", "scroll",
        "throne", "crown", "sword", "blade", "shield", "quest", "guild",
        "tavern", "forge", "vault", "labyrinth", "oracle", "altar",
        # Atmosphere
        "epic", "heroic", "mythical", "legendary", "ancient", "vast",
        "majestic", "glorious", "noble", "valiant", "brave", "courageous",
        "mystical", "wondrous", "fantastical", "mythical", "fabled",
        # LotR / Tolkien-verse
        "elves", "elvish", "elven", "dwarf", "dwarven", "dwarves", "hobbit",
        "orc", "orcs", "shire", "mordor", "gondor", "rohan", "rivendell",
        "tolkien", "mirkwood", "lothlorien", "isengard", "mithril", "palantir",
        "balrog", "nazgul", "ringbearer", "fellowship", "silmaril",
        # Other high-fantasy universes (D&D, Wheel of Time, etc.)
        "dragonborn", "tiefling", "drow", "illithid", "beholder", "lichdom",
        "aiel", "aes sedai", "darkfriend", "forsaken", "whitecloak",
    ],

    "scifi": [
        "spaceship", "robot", "android", "cyborg", "alien", "extraterrestrial",
        "galaxy", "nebula", "cosmos", "universe", "quantum", "plasma", "laser",
        "wormhole", "hyperspace", "starship", "spacecraft", "satellite",
        "cybernetic", "synthetic", "artificial", "neural", "hologram",
        "teleport", "cloning", "mutation", "radiation", "singularity",
        "dystopia", "utopia", "corporation", "surveillance", "hacker",
        "implant", "nanobots", "genetic", "bionic", "exoplanet",
        "terraforming", "cryogenic", "interstellar", "warp", "reactor",
    ],

    "mystery": [
        "detective", "clue", "suspect", "murder", "crime", "investigation",
        "evidence", "alibi", "motive", "interrogation", "witness", "culprit",
        "conspiracy", "secret", "hidden", "concealed", "deception", "betrayal",
        "enigma", "puzzle", "riddle", "labyrinth", "cryptic", "obscure",
        "shadowy", "intrigue", "corrupt", "sinister", "elusive", "fugitive",
        "forensic", "surveillance", "informant", "undercover", "blackmail",
    ],

    "thriller": [
        "assassin", "espionage", "agent", "operative", "covert", "classified",
        "mission", "target", "pursuit", "escape", "chase", "ambush",
        "sniper", "sabotage", "conspiracy", "surveillance", "deception",
        "danger", "threat", "tension", "suspense", "paranoia", "adrenaline",
        "explosive", "hostage", "ransom", "brutal", "lethal", "ruthless",
    ],

    "romance": [
        "love", "passion", "desire", "longing", "devotion", "affection",
        "embrace", "tender", "heartfelt", "intimate", "sensual", "adore",
        "cherish", "soulmate", "beloved", "enchanted", "captivating",
        "alluring", "charming", "irresistible", "smoldering", "breathless",
        "yearning", "infatuation", "seduction", "romance", "flirtation",
        "vulnerable", "trust", "connection", "chemistry", "magnetic",
    ],

    "adventure": [
        "expedition", "explorer", "journey", "voyage", "quest", "treasure",
        "discovery", "wilderness", "jungle", "mountain", "ocean", "desert",
        "survival", "danger", "escape", "legendary", "ancient", "relic",
        "compass", "map", "trail", "cliff", "rapids", "storm", "daring",
        "fearless", "intrepid", "bold", "heroic", "perilous", "uncharted",
    ],

    "mtg": [
        # Mana & colors
        "mana", "plains", "island", "swamp", "mountain", "forest",
        "white", "blue", "black", "red", "green", "colorless", "multicolor",
        "aura", "enchantment", "artifact", "sorcery", "instant",
        # Card types & mechanics
        "creature", "planeswalker", "legendary", "token", "counter",
        "flying", "trample", "haste", "vigilance", "deathtouch", "lifelink",
        "hexproof", "indestructible", "flash", "reach", "menace",
        "proliferate", "cascade", "convoke", "delve", "affinity",
        "kicker", "flashback", "cycling", "madness", "morph", "scry",
        "devour", "exalted", "infect", "wither", "annihilator", "dredge",
        "storm", "splice", "replicate", "buyback", "phasing", "banding",
        # Iconic creatures & tribes
        "dragon", "angel", "demon", "zombie", "vampire", "merfolk",
        "goblin", "elf", "wizard", "shaman", "warrior", "knight",
        "elemental", "sphinx", "hydra", "wurm", "phoenix", "titan",
        "dragonlord", "archon", "praetor", "avatar", "incarnation",
        "sliver", "eldrazi", "phyrexian", "shapeshifter", "berserker",
        "cleric", "rogue", "artificer", "druid", "assassin", "soldier",
        # Planeswalkers
        "jace", "liliana", "gideon", "chandra", "nissa", "sorin",
        "elspeth", "garruk", "ajani", "teferi", "karn", "nicol",
        "vraska", "ral", "angrath", "huatli", "saheeli", "samut",
        "kiora", "tamiyo", "tibalt", "nahiri", "xenagos", "domri",
        # Planes & locations
        "ravnica", "zendikar", "innistrad", "theros", "dominaria",
        "mirrodin", "kamigawa", "alara", "lorwyn", "shadowmoor",
        "kaladesh", "amonkhet", "ixalan", "eldraine", "strixhaven",
        "kaldheim", "fiora", "tarkir", "shandalar", "phyrexia",
        "mercadia", "ulgrotha", "rath", "serra", "conclave",
        # Guilds & factions
        "dimir", "gruul", "boros", "selesnya", "golgari", "izzet",
        "orzhov", "rakdos", "simic", "azorius", "abzan", "jeskai",
        "sultai", "mardu", "temur", "esper", "jund", "naya",
        "grixis", "bant", "nephilim", "syndicate",
        # Spells & effects
        "counterspell", "lightning", "wrath", "exile", "destroy",
        "reanimate", "proliferate", "tutor", "scry", "loot", "ramp",
        "sacrifice", "discard", "mill", "bounce", "flicker", "blink",
        "transmute", "cipher", "overload", "evolve", "unleash",
        # Lore & atmosphere
        "eternal", "undying", "undead", "necromancy", "prophecy",
        "apocalypse", "revival", "ascension", "dominion", "tempest",
        "weatherlight", "compleation", "praetor", "elder", "ancient",
        "omnipotence", "sovereignty", "covenant", "crusade", "judgment",
        "exodus", "stronghold", "urza", "mishra", "yawgmoth", "emrakul",
        "kozilek", "ulamog", "bolas", "urabrask", "sheoldred",
    ],

    "tech": [
        # Hardware / compute
        "cpu", "gpu", "ram", "ssd", "nvme", "processor", "chipset", "transistor",
        "motherboard", "heatsink", "overclock", "benchmark", "gigabyte", "terabyte",
        "megabyte", "kilobyte", "bitrate", "register", "cache", "socket",
        # Networking / infrastructure
        "router", "ethernet", "bandwidth", "latency", "packet", "subnet", "gateway",
        "firewall", "proxy", "dns", "http", "https", "tcp", "udp", "ping",
        "vpn", "cdn", "server", "datacenter", "cluster", "node", "rack",
        # Storage & cloud
        "cloud", "storage", "drive", "bucket", "blob", "volume", "snapshot",
        "backup", "replication", "sharding", "partition", "filesystem", "mount",
        # Dev tools & version control
        "git", "github", "bitbucket", "gitlab", "commit", "branch", "merge",
        "repository", "dockerfile", "container", "kubernetes", "deployment",
        "pipeline", "compiler", "debugger", "linter", "runtime", "interpreter",
        # Programming concepts
        "algorithm", "database", "query", "index", "schema", "api", "endpoint",
        "payload", "parser", "token", "authentication", "encryption", "hash",
        "binary", "hexadecimal", "integer", "boolean", "pointer", "memory",
        "recursion", "iterator", "callback", "async", "thread", "process",
        # Software layers
        "firmware", "kernel", "bootloader", "driver", "daemon", "socket", "buffer",
    ],
}

MIN_WORD_LEN   = 4
CANDIDATE_POOL = 50_000   # How many vocab words to score (gensim top-N)


def build_centroid(model, seeds: list[str]) -> np.ndarray:
    """Average the vectors of all seed words present in the model vocabulary."""
    vecs = []
    missing = []
    for word in seeds:
        try:
            vecs.append(model[word])
        except KeyError:
            missing.append(word)

    if missing:
        print(f"  Seed words not in vocab: {missing}")

    if not vecs:
        print("ERROR: no seed words found in model vocabulary", file=sys.stderr)
        sys.exit(1)

    centroid = np.mean(vecs, axis=0)
    # Normalise to unit vector
    centroid = centroid / np.linalg.norm(centroid)
    print(f"  Centroid built from {len(vecs)}/{len(seeds)} seed words")
    return centroid


def is_valid(word: str) -> bool:
    """Return True if the word should be kept."""
    w = word.lower()
    if len(w) < MIN_WORD_LEN:
        return False
    if not re.fullmatch(r"[a-z]+", w):
        return False
    if w in STOP_WORDS:
        return False
    if w in PROFANITY:
        return False
    # Skip all-caps abbreviations (model vocab sometimes includes them)
    if word.isupper() and len(word) > 2:
        return False
    # Skip misspellings — fastText subword embeddings cluster typos near real words
    if not SPELL.known([w]):
        return False
    return True


def main() -> None:
    parser = argparse.ArgumentParser(description="fastText semantic word finder (multi-category)")
    parser.add_argument("--category", default="horror", choices=list(SEEDS.keys()),
                        help="Word category / genre (default: horror)")
    parser.add_argument("--output", default=None,
                        help="Output JSON file (default: fasttext_<category>.json)")
    parser.add_argument("--top",    type=int, default=5000, help="Max words in output")
    args = parser.parse_args()

    out_file = args.output or f"fasttext_{args.category}.json"
    seed_words = SEEDS[args.category]

    # ── 1. Load model ──────────────────────────────────────────────────────────
    print("==> Loading fasttext-wiki-news-subwords-300 …")
    print("    (Downloads ~1 GB on first run, then cached locally)")
    import gensim.downloader as api
    model = api.load("fasttext-wiki-news-subwords-300")
    print(f"    Vocab size: {len(model):,} | Vector dims: {model.vector_size}")

    # ── 2. Build centroid ──────────────────────────────────────────────────────
    print(f"\n==> Building '{args.category}' centroid from {len(seed_words)} seed words …")
    centroid = build_centroid(model, seed_words)

    # ── 3. Find most similar words ─────────────────────────────────────────────
    print(f"\n==> Finding top {CANDIDATE_POOL:,} words closest to centroid …")
    candidates = model.similar_by_vector(centroid, topn=CANDIDATE_POOL)
    print(f"    Retrieved {len(candidates):,} candidates")

    # ── 4. Filter ──────────────────────────────────────────────────────────────
    print("\n==> Filtering stop words, profanity, short words …")
    filtered = [
        {"word": word.lower(), "similarity": round(float(score), 6)}
        for word, score in candidates
        if is_valid(word)
    ]
    seen: set[str] = set()
    deduped = []
    for entry in filtered:
        if entry["word"] not in seen:
            seen.add(entry["word"])
            deduped.append(entry)

    print(f"    After filtering: {len(deduped):,} words")
    top_words = deduped[: args.top]

    # ── 5. Write JSON ──────────────────────────────────────────────────────────
    out_path = Path(out_file)
    out_path.write_text(json.dumps(top_words, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\n==> Saved {len(top_words):,} words → {out_path.resolve()}")

    print(f"\nTop 50 '{args.category}' words by semantic similarity:")
    print(f"{'WORD':<22} {'SIMILARITY':>10}")
    print("-" * 34)
    for e in top_words[:50]:
        print(f"{e['word']:<22} {e['similarity']:>10.4f}")

    print(f"\nBottom 10 of top-{args.top} (quality floor):")
    print(f"{'WORD':<22} {'SIMILARITY':>10}")
    print("-" * 34)
    for e in top_words[-10:]:
        print(f"{e['word']:<22} {e['similarity']:>10.4f}")


if __name__ == "__main__":
    main()
