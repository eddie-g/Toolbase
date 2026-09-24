#!/usr/bin/env python3
"""Pick a varied corpus of real PDFs for the invariant tester.

Reads the `documents` table (one candidate per distinct file size, so the
hundreds of test uploads of the same fixture collapse to one), copies each
candidate OUT of the Sail container with `docker cp` (the originals are never
opened in place, let alone modified), classifies every copy with PyMuPDF and
greedily picks ~20 files so that every class is covered at least twice where
possible. drylab.pdf and the nk8131 source.pdf are always included.

Usage:
  .venv/bin/python tests/AutomatedTests/Invariants/pick_corpus.py \
      [--dest DIR] [--out corpus.json] [--target 20] [--max-mb 5] [--max-pages 40]
"""
import argparse
import json
import os
import re
import shutil
import statistics
import subprocess
import sys

import fitz

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
CONTAINER = 'netkit-laravel.test-1'
MYSQL = ['docker', 'exec', 'netkit-mysql-1', 'mysql', '-usail', '-ppassword', 'toolbase',
         '--default-character-set=utf8mb4', '-N', '-B', '-e']
DEFAULT_DEST = os.path.join(HERE, 'corpus')  # local copies of stored uploads; git-ignored
ALWAYS = [
    os.path.join(ROOT, 'tests', 'OverlayEditor', 'drylab.pdf'),
    os.path.join(DEFAULT_DEST, 'isartor_source.pdf'),
    # regression PDFs (copied into the corpus folder): SS-5 p3 promoted_3_4 (NK_59 edited-row origin,
    # Asana 1218808718696261) and the c4611 Courier block (d8409)
    os.path.join(DEFAULT_DEST, 'ss-5.pdf'),
    os.path.join(DEFAULT_DEST, 'd8409_c4611_courier.pdf'),
]
QUICK = {'ss-5.pdf'}  # always part of --quick
MAX_FORMS = 6
ARTEFACT_RE = re.compile(r'(tool_test|automated_test|nk\d|nk8131|nkv|resilience|shot_|repro|fix\d|^m\.pdf|^part\.pdf|^why\.pdf|test_pdf|fifty_|isartor|isator|drylab)', re.I)


def family(name):
    n = name.lower()
    n = re.sub(r'(_split|_guided|_filled_out|_page_?\d+|\d+)', '', n)
    return re.sub(r'[^a-z]+', '', n)


CLASSES = ['justified', 'list', 'hanging_indent', 'table', 'multi_column', 'form', 'subset_fonts',
           'embedded_fonts', 'soft_hyphen', 'superscript', 'rotated', 'scanned', 'mixed_fonts', 'coloured_text']
BULLET_RE = re.compile(r'^\s*([•●▪■◦–—·\-\*o]|\(?\d{1,2}[.)]|\(?[a-zA-Z][.)])(\s|$)')


def candidates():
    sql = ("SELECT MIN(id), path, original_name, size_bytes FROM documents "
           "WHERE mime_type LIKE '%pdf%' AND deleted_at IS NULL AND pdf_password_hash IS NULL "
           "GROUP BY size_bytes, path, original_name ORDER BY size_bytes, MIN(id)")
    out = subprocess.run(MYSQL + [sql], capture_output=True, text=True, check=True).stdout
    seen = set()
    rows = []
    for line in out.splitlines():
        parts = line.split('\t')
        if len(parts) != 4:
            continue
        doc_id, path, name, size = int(parts[0]), parts[1], parts[2], int(parts[3])
        if name.startswith('inv_'):  # this tester's own uploads
            continue
        if size in seen:
            continue
        seen.add(size)
        rows.append({'doc_id': doc_id, 'path': path, 'name': name, 'size': size})
    return rows


def copy_out(row, dest):
    safe = re.sub(r'[^\w.-]+', '_', row['name'])[:60]
    target = os.path.join(dest, f"{row['size']}_{safe}")
    if not target.lower().endswith('.pdf'):
        target += '.pdf'
    if os.path.exists(target):
        return target
    for base in ('storage/app/private', 'storage/app'):
        src = f"{CONTAINER}:/var/www/html/{base}/{row['path']}"
        r = subprocess.run(['docker', 'cp', src, target], capture_output=True, text=True)
        if r.returncode == 0 and os.path.exists(target):
            os.chmod(target, 0o444)
            return target
    return None


def classify(path):
    doc = fitz.open(path)
    info = {'pages': doc.page_count, 'classes': [], 'stats': {}}
    if doc.needs_pass or doc.is_encrypted:
        info['error'] = 'encrypted'
        return info
    cls = set()
    if doc.is_form_pdf:
        cls.add('form')
    fonts = set()
    text_chars = 0
    scanned_pages = 0
    justified_blocks = list_rows = hanging = super_spans = soft = colours = grid_pages = multi_col_pages = 0
    for page in doc:
        if page.rotation:
            cls.add('rotated')
        for f in page.get_fonts(full=True):
            name, ext = f[3], f[1]
            fonts.add(name)
            if '+' in name:
                cls.add('subset_fonts')
            if ext and ext != 'n/a':
                cls.add('embedded_fonts')
        if any(True for _ in page.widgets()):
            cls.add('form')
        d = page.get_text('dict', flags=fitz.TEXT_PRESERVE_WHITESPACE)
        page_text = 0
        blocks = [b for b in d['blocks'] if b['type'] == 0]
        for b in blocks:
            lines = b['lines']
            sizes = [s['size'] for l in lines for s in l['spans'] if s['text'].strip()]
            med = statistics.median(sizes) if sizes else 0
            for l in lines:
                t = ''.join(s['text'] for s in l['spans'])
                page_text += len(t.strip())
                if '­' in t:
                    soft += 1
                if BULLET_RE.match(t):
                    list_rows += 1
                for s in l['spans']:
                    if not s['text'].strip():
                        continue
                    if (s['flags'] & 1) or (med and s['size'] < 0.72 * med and s['bbox'][3] < l['bbox'][3] - 0.2 * med):
                        super_spans += 1
                    if s['color'] not in (0, 0x231f20, 0x221e1f):
                        colours += 1
            if len(lines) >= 3:
                rights = [l['bbox'][2] for l in lines[:-1]]
                lefts = [l['bbox'][0] for l in lines]
                if len(rights) >= 2 and max(rights) - min(rights) < 1.0 and lines[-1]['bbox'][2] < max(rights) - 5:
                    justified_blocks += 1
            if len(lines) >= 2:
                first, rest = lines[0]['bbox'][0], [l['bbox'][0] for l in lines[1:]]
                if rest and min(rest) - first > 6 and max(rest) - min(rest) < 1.5:
                    hanging += 1
            for i, l in enumerate(lines[:-1]):
                t = ''.join(s['text'] for s in l['spans']).rstrip()
                nxt = ''.join(s['text'] for s in lines[i + 1]['spans']).lstrip()
                if t.endswith('-') and nxt[:1].islower():
                    soft += 1
        text_chars += page_text
        # side-by-side text blocks -> multi-column
        side = 0
        for i, a in enumerate(blocks):
            for c in blocks[i + 1:]:
                ya = (a['bbox'][1], a['bbox'][3]); yc = (c['bbox'][1], c['bbox'][3])
                overlap = min(ya[1], yc[1]) - max(ya[0], yc[0])
                if overlap > 20 and (a['bbox'][2] < c['bbox'][0] - 8 or c['bbox'][2] < a['bbox'][0] - 8):
                    side += 1
        if side >= 3:
            multi_col_pages += 1
        try:
            segs = 0
            for dr in page.get_drawings():
                for it in dr['items']:
                    if it[0] == 'l':
                        p, q = it[1], it[2]
                        if abs(p.x - q.x) < 0.5 or abs(p.y - q.y) < 0.5:
                            segs += 1
                    elif it[0] == 're':
                        segs += 1
            if segs >= 12:
                grid_pages += 1
        except Exception:
            pass
        if page_text < 20:
            imgs = page.get_images()
            if imgs:
                scanned_pages += 1
    if justified_blocks:
        cls.add('justified')
    if list_rows >= 2:
        cls.add('list')
    if hanging:
        cls.add('hanging_indent')
    if grid_pages:
        cls.add('table')
    if multi_col_pages:
        cls.add('multi_column')
    if soft:
        cls.add('soft_hyphen')
    if super_spans:
        cls.add('superscript')
    if colours >= 3:
        cls.add('coloured_text')
    if len(fonts) >= 3:
        cls.add('mixed_fonts')
    if scanned_pages and scanned_pages >= doc.page_count / 2:
        cls.add('scanned')
    info['classes'] = sorted(cls)
    info['stats'] = {'text_chars': text_chars, 'fonts': len(fonts), 'justified_blocks': justified_blocks,
                     'list_rows': list_rows, 'hanging_blocks': hanging, 'superscript_spans': super_spans,
                     'soft_hyphens': soft, 'grid_pages': grid_pages, 'multi_col_pages': multi_col_pages,
                     'scanned_pages': scanned_pages}
    return info


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dest', default=DEFAULT_DEST)
    ap.add_argument('--out', default=os.path.join(HERE, 'corpus.json'))
    ap.add_argument('--target', type=int, default=22)
    ap.add_argument('--max-mb', type=float, default=5)
    ap.add_argument('--max-pages', type=int, default=40)
    args = ap.parse_args()
    os.makedirs(args.dest, exist_ok=True)
    pool = []
    for row in candidates():
        if row['size'] > args.max_mb * 1024 * 1024:
            continue
        path = copy_out(row, args.dest)
        if not path:
            continue
        try:
            info = classify(path)
        except Exception as e:  # damaged / unsupported file
            print(f"skip {row['name']}: {e}", file=sys.stderr)
            continue
        if info.get('error') or info['pages'] > args.max_pages or info['pages'] == 0:
            continue
        pool.append({'path': path, 'name': row['name'], 'source_doc_id': row['doc_id'], 'size': row['size'], **info})
    chosen = []
    for fixed in ALWAYS:
        if os.path.exists(fixed):
            info = classify(fixed)
            chosen.append({'path': fixed, 'name': os.path.basename(fixed), 'source_doc_id': None,
                           'size': os.path.getsize(fixed), **info, **({'quick': True} if os.path.basename(fixed) in QUICK else {})})
    names = {os.path.basename(c['path']).lower() for c in chosen}
    # drop duplicates of the fixed files (same name)
    pool = [p for p in pool if not any(p['name'].lower() == n or p['name'].lower().endswith(n) for n in names)]
    fixed_sizes = {c['size'] for c in chosen}
    pool = [p for p in pool if p['size'] not in fixed_sizes]  # the same file under another name
    # drop our own test artefacts (tool-suite outputs, repro copies) and keep one file per family;
    # an artefact is still used when it is the only carrier of a class (e.g. the rotated page)
    full_pool = list(pool)
    pool = [p for p in pool if not ARTEFACT_RE.search(p['name'])]
    for k in CLASSES:
        if not any(k in p['classes'] for p in pool) and not any(k in c['classes'] for c in chosen):
            extra = sorted((p for p in full_pool if k in p['classes']), key=lambda p: (p['pages'], p['size']))
            if extra:
                chosen.append(extra[0])
    fam = {}
    for p in sorted(pool, key=lambda p: (-len(p['classes']), p['pages'])):
        fam.setdefault(family(p['name']), p)
    pool = sorted(fam.values(), key=lambda p: (p['pages'], p['size']))
    cover = {c: 0 for c in CLASSES}
    for c in chosen:
        for k in c['classes']:
            cover[k] = cover.get(k, 0) + 1
    while len(chosen) < args.target and pool:
        forms = sum(1 for c in chosen if 'form' in c['classes'])

        def gain(p):
            g = sum(max(0, 2 - cover.get(k, 0)) for k in p['classes'])
            # diversity: prefer class sets unlike anything chosen, and cap government forms
            sim = max((len(set(p['classes']) & set(c['classes'])) / max(1, len(set(p['classes']) | set(c['classes'])))
                       for c in chosen), default=0)
            g += 1.5 * (1 - sim)
            if 'form' in p['classes'] and forms >= MAX_FORMS:
                g -= 5
            if p['stats'].get('text_chars', 0) < 50 and 'scanned' not in p['classes']:
                g -= 3
            return g
        best = max(pool, key=lambda p: (gain(p), -p['pages']))
        pool.remove(best)
        chosen.append(best)
        for k in best['classes']:
            cover[k] = cover.get(k, 0) + 1
    out = {'generated_by': 'pick_corpus.py', 'coverage': cover, 'pdfs': chosen}
    with open(args.out, 'w') as fh:
        json.dump(out, fh, indent=1)
    print(json.dumps({'chosen': len(chosen), 'coverage': cover}, indent=1))
    for c in chosen:
        print(f"{c['pages']:>3}p {os.path.basename(c['path'])[:50]:<50} {','.join(c['classes'])}")


if __name__ == '__main__':
    main()
