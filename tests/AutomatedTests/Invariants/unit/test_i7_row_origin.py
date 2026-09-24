#!/usr/bin/env python3
"""Unit check for the I7 edited-row origin geometry (Asana 1218808718696261, NK_59).

SS-5 p3 promoted_3_4, "123 " typed before "Contact" on row 4. Fixtures (unit/fixtures/):
  ss5_untouched_download.pdf             untouched download of a fresh upload (real endpoint)
  ss5_server_download_123_contact.pdf    the edited download from the real endpoint (bundle main-EjJpCrGK.js, exporter at 4db22b2+)
  ss5_old_exporter_4db22b2parent.pdf     the same payload (ss5_payload.json) through the PRE-fix exporter
                                         (git show 4db22b2^:python/pdf-editor/apply_annotations_direct_new.py),
                                         replayed offline by offline_export.py (TrimStrings applied)
  ss5_spec.json                          block rects + editor text written by the session

Asserts: the old exporter's row 4 fails the origin check with dx ~ -55 pt; the server download keeps every
row's first glyph and baseline within 0.5 pt. Prints the prefix-word dx of the edited row (measured, not asserted).
Regenerate fixtures: see README (Unit check).
"""
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'lib'))
import fitz_tools as F  # noqa: E402

FX = os.path.join(HERE, 'fixtures')
spec = json.load(open(os.path.join(FX, 'ss5_spec.json')))
base = os.path.join(FX, 'ss5_untouched_download.pdf')
TOL = 0.5
fails = 0


def geo(pdf):
    return F.row_geometry(base, os.path.join(FX, pdf), spec['page'], spec['block_orig'], spec['block_now'], TOL)


old = geo('ss5_old_exporter_4db22b2parent.pdf')
row4 = next((r for r in old['bad'] if r.get('row') == 4), None)
print('old exporter rows:', [(r['row'], r.get('dx'), r.get('dy'), r.get('prefixMaxDx')) for r in old['rows']])
if not row4 or not (-60 <= row4['dx'] <= -50):
    print('FAIL: old exporter output should violate the edited-row origin with dx ~ -55pt on row 4')
    fails += 1
else:
    print(f"ok: old exporter row 4 flagged, dx {row4['dx']}pt (locus: exporter edited-row origin)")

new = geo('ss5_server_download_123_contact.pdf')
print('server rows:', [(r['row'], r.get('dx'), r.get('dy'), r.get('prefixMaxDx')) for r in new['rows']])
origin_bad = [r for r in new['rows'] if r.get('missing') or abs(r['dx']) > TOL or abs(r['dy']) > TOL]
if origin_bad:
    print('FAIL: server download moved a row origin', origin_bad)
    fails += 1
else:
    print('ok: server download keeps every row\'s first glyph and baseline within 0.5pt')
pre = [r for r in new['rows'] if r.get('edited')]
for r in pre:
    print(f"measured: edited row {r['row']} words before the edit point max dx {r['prefixMaxDx']}pt "
          f"({'within' if abs(r['prefixMaxDx']) <= TOL else 'OUTSIDE'} {TOL}pt)")
sys.exit(1 if fails else 0)
