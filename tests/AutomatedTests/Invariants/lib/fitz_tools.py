#!/usr/bin/env python3
"""PyMuPDF helpers for the invariant tester. One JSON document on stdout.

  words <pdf> <page1based>                       per-word boxes (pt, displayed/rotated space) + font/size/colour
  render <pdf> <page> <rect json> <png> <scale>  region render at `scale` px/pt
  bgcheck <pdf> <page> <oldrect> <exclude json> <shot.png> <scale> <redact words json>
                                                 leftover-ink check of a moved block's old place (invariant 6)
  exportdiff <base.pdf> <edited.pdf> <spec.json> untouched-row / edited-row comparison (invariant 7)
"""
import json
import re
import sys

import fitz

WS = re.compile(r'[\s ­​-‍⁠﻿]+')


def _words(page):
    """Words from rawdict chars (accurate per-char boxes), in displayed (rotated) coordinates."""
    rot = page.rotation_matrix
    out = []
    d = page.get_text('rawdict')
    for b in d['blocks']:
        if b['type'] != 0:
            continue
        for li, l in enumerate(b['lines']):
            cur = None
            for s in l['spans']:
                for ch in s['chars']:
                    c = ch['c']
                    if not c.strip() or c == '­':
                        if cur:
                            out.append(cur)
                            cur = None
                        continue
                    r = fitz.Rect(ch['bbox']) * rot
                    if cur is None:
                        # Baseline (glyph origin): font ascent/descent metrics differ
                        # between the PDF font and the browser's, so box centres are
                        # not comparable across them; baselines are.
                        origin = fitz.Point(ch['origin']) * rot
                        cur = {'t': '', 'x': r.x0, 'r': r.x1, 'y': r.y0, 'bot': r.y1, 'base': origin.y, 'size': round(s['size'], 2),
                               'font': s['font'], 'color': '#%06x' % s['color']}
                    cur['t'] += c
                    cur['x'] = min(cur['x'], r.x0); cur['r'] = max(cur['r'], r.x1)
                    cur['y'] = min(cur['y'], r.y0); cur['bot'] = max(cur['bot'], r.y1)
            if cur:
                out.append(cur)
    for w in out:
        for k in ('x', 'r', 'y', 'bot', 'base'):
            w[k] = round(w[k], 2)
    return out


def cmd_words(pdf, pno):
    doc = fitz.open(pdf)
    page = doc[int(pno) - 1]
    sizes = sorted(w['size'] for w in _words(page)) or [0]
    print(json.dumps({'rotation': page.rotation, 'w': page.rect.width if page.rotation % 180 == 0 else page.rect.height,
                      'h': page.rect.height if page.rotation % 180 == 0 else page.rect.width,
                      'median_size': sizes[len(sizes) // 2], 'words': _words(page)}))


def _unrotate(page, rect):
    """Displayed-space rect -> unrotated page space (for clips and redactions)."""
    inv = ~page.rotation_matrix
    return fitz.Rect(rect) * inv


def cmd_render(pdf, pno, rect, png, scale):
    doc = fitz.open(pdf)
    page = doc[int(pno) - 1]
    r = _unrotate(page, json.loads(rect))
    pix = page.get_pixmap(matrix=fitz.Matrix(float(scale), float(scale)).prerotate(page.rotation), clip=r)
    pix.save(png)
    print(json.dumps({'ok': True, 'png': png}))


def cmd_bgcheck(pdf, pno, oldrect, exclude, shot, scale, redact):
    """Compare a screenshot of the old place against the original page rendered
    WITHOUT the moved block's glyphs (redacted). Ink = pixels clearly darker in
    the screenshot than the darkest expected pixel in a 5x5 neighbourhood (so a
    1 px registration offset and anti-aliasing don't count)."""
    import numpy as np
    from PIL import Image
    doc = fitz.open(pdf)
    page = doc[int(pno) - 1]
    scale = float(scale)
    old = fitz.Rect(json.loads(oldrect))
    words = json.loads(redact)
    for w in words:
        page.add_redact_annot(_unrotate(page, fitz.Rect(w['x'], w['y'], w['r'], w['bot'])))
    page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE, graphics=fitz.PDF_REDACT_LINE_ART_NONE,
                          text=fitz.PDF_REDACT_TEXT_REMOVE)
    pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale).prerotate(page.rotation), clip=_unrotate(page, old))
    exp = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.h, pix.w, pix.n)[:, :, :3].astype(np.float32)
    got = np.asarray(Image.open(shot).convert('RGB')).astype(np.float32)
    h = min(exp.shape[0], got.shape[0]); w = min(exp.shape[1], got.shape[1])
    exp = exp[:h, :w]; got = got[:h, :w]
    luma = lambda a: 0.299 * a[:, :, 0] + 0.587 * a[:, :, 1] + 0.114 * a[:, :, 2]
    le, lg = luma(exp), luma(got)
    # 5x5 minimum: pdf.js and MuPDF anti-alias glyph edges differently (a 3x3 window still
    # left ~175 'ink' pixels on an unmoved paragraph; 5x5 leaves 0), while a leftover sliver
    # on blank background is still far darker than anything within 2 px of it.
    pad = np.pad(le, 2, mode='edge')
    emin = np.min(np.stack([pad[dy:dy + h, dx:dx + w] for dy in range(5) for dx in range(5)]), axis=0)
    ink = lg < emin - 60
    # mask out excluded rects (the block's new place, other boxes) given in pt relative to old rect origin
    for ex in json.loads(exclude):
        x0 = int(max(0, (ex[0] - old.x0) * scale - 1)); y0 = int(max(0, (ex[1] - old.y0) * scale - 1))
        x1 = int(min(w, (ex[2] - old.x0) * scale + 1)); y1 = int(min(h, (ex[3] - old.y0) * scale + 1))
        if x1 > x0 and y1 > y0:
            ink[y0:y1, x0:x1] = False
    n = int(ink.sum())
    ys, xs = np.nonzero(ink)
    bbox = [int(xs.min()), int(ys.min()), int(xs.max()), int(ys.max())] if n else None
    Image.fromarray((np.where(ink[:, :, None], [255, 0, 0], got)).astype(np.uint8)).save(shot.replace('.png', '_ink.png'))
    pix.save(shot.replace('.png', '_expected.png'))
    print(json.dumps({'inkPixels': n, 'area': int(h * w), 'inkBBoxPx': bbox}))


def _lines(pdf):
    doc = fitz.open(pdf)
    pages = []
    for page in doc:
        rot = page.rotation_matrix
        out = []
        d = page.get_text('dict')
        for b in d['blocks']:
            if b['type'] != 0:
                continue
            for l in b['lines']:
                spans = [s for s in l['spans'] if s['text'].strip()]
                if not spans:
                    continue
                bb = fitz.Rect(l['bbox']) * rot
                out.append({'text': ''.join(s['text'] for s in l['spans']).strip(),
                            'bbox': [round(v, 2) for v in bb],
                            'spans': [{'text': s['text'], 'font': s['font'], 'size': round(s['size'], 2),
                                       'color': '#%06x' % s['color'],
                                       'bbox': [round(v, 2) for v in fitz.Rect(s['bbox']) * rot]} for s in spans]})
        pages.append(out)
    return pages


def _rows(pdf, pno, rect):
    """Visual rows inside rect (displayed space): first-glyph x, baseline y and word x0s,
    from rawdict chars (first NON-SPACE glyph: a row may open with a run of spaces)."""
    doc = fitz.open(pdf)
    page = doc[pno - 1]
    rot = page.rotation_matrix
    rows = []
    for b in page.get_text('rawdict')['blocks']:
        if b['type'] != 0:
            continue
        for l in b['lines']:
            bb = list(fitz.Rect(l['bbox']) * rot)
            if not _hits(bb, [rect], pad=-0.5):
                continue
            words = []; cur = None; ys = []
            for sp in l['spans']:
                for ch in sp['chars']:
                    c = ch['c']
                    if not c.strip() or c == '\u00ad':
                        cur = None
                        continue
                    r = fitz.Rect(ch['bbox']) * rot
                    o = fitz.Point(ch['origin']) * rot
                    ys.append(o.y)
                    if cur is None:
                        cur = {'t': '', 'x0': r.x0}
                        words.append(cur)
                    cur['t'] += c
            if not words:
                continue
            ys.sort()
            base = ys[len(ys) // 2]
            row = next((r for r in rows if abs(r['baseline'] - base) < 1.5), None)
            if row is None:
                row = {'baseline': base, 'words': []}
                rows.append(row)
            row['words'].extend(words)
    for r in rows:
        r['words'].sort(key=lambda w: w['x0'])
        r['x0'] = r['words'][0]['x0']
        r['text'] = ' '.join(w['t'] for w in r['words'])
    rows.sort(key=lambda r: r['baseline'])
    return rows


def row_geometry(base_pdf, edited_pdf, pno, orig, now, tol):
    """Every row of the block in the edited download vs the same row in the untouched
    download: first-glyph x, baseline, and x of the words before the first changed word.
    A moved block is compared after removing the move offset."""
    B = _rows(base_pdf, pno, orig)
    E = _rows(edited_pdf, pno, now)
    offx = now[0] - orig[0]; offy = now[1] - orig[1]
    out = []; bad = []
    used = set()
    for i, b in enumerate(B):
        # pair by baseline (after the move offset), nearest within 60% of the row pitch
        pitch = (B[i + 1]['baseline'] - b['baseline']) if i + 1 < len(B) else ((b['baseline'] - B[i - 1]['baseline']) if i else 12)
        best = None; bd = 1e9
        for j, e in enumerate(E):
            if j in used:
                continue
            d = abs(e['baseline'] - offy - b['baseline'])
            if d < bd:
                best, bd = j, d
        if best is None or bd > 0.6 * abs(pitch or 12):
            out.append({'row': i + 1, 'text': b['text'][:70], 'missing': True})
            bad.append(out[-1])
            continue
        used.add(best)
        e = E[best]
        dx = e['x0'] - offx - b['x0']
        dy = e['baseline'] - offy - b['baseline']
        bt = [w['t'] for w in b['words']]; et = [w['t'] for w in e['words']]
        k = 0
        while k < min(len(bt), len(et)) and bt[k] == et[k]:
            k += 1
        edited = bt != et
        prefix = [e['words'][q]['x0'] - offx - b['words'][q]['x0'] for q in range(k)] if edited else []
        pmax = max(prefix, key=abs) if prefix else 0.0
        rec = {'row': i + 1, 'edited': edited, 'text': e['text'][:70], 'baseText': b['text'][:70],
               'x0Base': round(b['x0'], 2), 'x0Edited': round(e['x0'] - offx, 2), 'dx': round(dx, 2), 'dy': round(dy, 2),
               'prefixWords': k if edited else None, 'prefixMaxDx': round(pmax, 2) if edited else None}
        out.append(rec)
        if abs(dx) > tol or abs(dy) > tol or abs(pmax) > tol:
            bad.append(rec)
    return {'rows': out, 'bad': bad, 'rowsBase': len(B), 'rowsEdited': len(E), 'offset': [round(offx, 2), round(offy, 2)]}


def _hits(bbox, rects, pad=1.0):
    for r in rects:
        if bbox[0] < r[2] + pad and bbox[2] > r[0] - pad and bbox[1] < r[3] + pad and bbox[3] > r[1] - pad:
            return True
    return False


def cmd_exportdiff(base, edited, spec_path):
    """spec: {page: 1-based, touched: [[x0,y0,x1,y1] pt...], block_now: rect, block_orig: rect,
              editor_text: str, pos_tol: pt}"""
    spec = json.load(open(spec_path))
    tol = float(spec.get('pos_tol', 0.5))
    B = _lines(base); E = _lines(edited)
    res = {'pages': len(B), 'pagesEdited': len(E), 'untouchedLines': 0, 'untouchedChanged': [], 'extraLines': [],
           'editedText': None, 'editorText': None, 'textMatch': None, 'colourLost': []}
    if len(B) != len(E):
        res['pageCountChanged'] = True
    tpage = int(spec['page'])
    for pi in range(min(len(B), len(E))):
        touched = spec['touched'] if pi + 1 == tpage else []
        used = set()
        for bl in B[pi]:
            if _hits(bl['bbox'], touched):
                continue
            res['untouchedLines'] += 1
            best = None; bestd = 1e9
            for j, el in enumerate(E[pi]):
                if j in used or WS.sub('', el['text']) != WS.sub('', bl['text']):
                    continue
                d = max(abs(a - b) for a, b in zip(el['bbox'], bl['bbox']))
                if d < bestd:
                    best, bestd = j, d
            if best is None:
                res['untouchedChanged'].append({'page': pi + 1, 'text': bl['text'][:80], 'why': 'missing', 'bbox': bl['bbox']})
                continue
            used.add(best)
            el = E[pi][best]
            fonts_b = [(s['font'], s['color']) for s in bl['spans']]
            fonts_e = [(s['font'], s['color']) for s in el['spans']]
            if bestd > tol or fonts_b != fonts_e:
                res['untouchedChanged'].append({'page': pi + 1, 'text': bl['text'][:80], 'why': 'moved' if bestd > tol else 'style',
                                                'maxDeltaPt': round(bestd, 2), 'base': fonts_b[:4], 'edited': fonts_e[:4]})
        for j, el in enumerate(E[pi]):
            if j in used or _hits(el['bbox'], touched):
                continue
            res['extraLines'].append({'page': pi + 1, 'text': el['text'][:80], 'bbox': el['bbox']})
    if 1 <= tpage <= len(E):
        now = spec.get('block_now')
        orig = spec.get('block_orig')
        # reading order (top-to-bottom, then left-to-right): an exporter may emit an edited row
        # as a separate text block after the rows below it
        order = lambda l: (round((l['bbox'][1] + l['bbox'][3]) / 4), l['bbox'][0])
        el = sorted([l for l in E[tpage - 1] if now and _hits(l['bbox'], [now], pad=-0.5)], key=order)
        bl = sorted([l for l in B[tpage - 1] if orig and _hits(l['bbox'], [orig], pad=-0.5)], key=order)
        got = WS.sub('', ''.join(l['text'] for l in el))
        want = WS.sub('', spec.get('editor_text') or '')
        res['editedText'] = got[:400]; res['editorText'] = want[:400]
        res['textMatch'] = got == want
        if not res['textMatch']:
            # contained? (the block rect can also catch a neighbour's row)
            res['textContained'] = want in got
        for b_line, e_line in zip(bl, el):
            bw = b_line['text'].split()[:1]; ew = e_line['text'].split()[:1]
            if bw and bw == ew and b_line['spans'][0]['color'] != e_line['spans'][0]['color']:
                res['colourLost'].append({'text': b_line['text'][:60], 'base': b_line['spans'][0]['color'], 'edited': e_line['spans'][0]['color']})
        if now and orig:
            g = row_geometry(base, edited, tpage, orig, now, float(spec.get('row_tol', tol)))
            res['rowGeometry'] = g['rows']
            res['rowOriginBad'] = g['bad']
            res['rowCounts'] = [g['rowsBase'], g['rowsEdited']]
    print(json.dumps(res))


def cmd_inkcount(png, threshold='140'):
    """Dark-ish pixels in a screenshot (moved text renders at its new place)."""
    pix = fitz.Pixmap(png)
    n = pix.n
    data = pix.samples
    t = float(threshold)
    dark = 0
    for i in range(0, len(data) - n + 1, n):
        if 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2] < t:
            dark += 1
    print(json.dumps({'dark': dark, 'area': pix.w * pix.h}))


if __name__ == '__main__':
    cmd, *args = sys.argv[1:]
    {'words': cmd_words, 'render': cmd_render, 'bgcheck': cmd_bgcheck, 'exportdiff': cmd_exportdiff, 'inkcount': cmd_inkcount}[cmd](*args)
