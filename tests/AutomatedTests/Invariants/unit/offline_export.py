#!/usr/bin/env python3
"""OFFLINE exporter replay -- only for the I7 negative unit check.

Everything else in the tester downloads through the real /download-annotated-pdf
endpoint. This replays a captured download payload through a given copy of
apply_annotations_direct_new.py (e.g. the pre-4db22b2 exporter extracted with
`git show 4db22b2^:python/pdf-editor/apply_annotations_direct_new.py`), after
applying what the server does to the request on the way in that matters here:
Laravel's TrimStrings middleware trims every string (so a source span that opens
with ~18 spaces reaches Python without them). It does NOT reproduce the rest of
the controller (asset enrichment, embedded-font metadata, AcroForm), so its
output is only meaningful for the row-origin check.

usage: offline_export.py <exporter.py> <source.pdf> <payload.json> <out.pdf>
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile


def trim(v):
    if isinstance(v, str):
        return v.strip()
    if isinstance(v, list):
        return [trim(x) for x in v]
    if isinstance(v, dict):
        return {k: trim(x) for k, x in v.items()}
    return v


def main():
    exporter, source, payload_path, out = sys.argv[1:5]
    payload = trim(json.load(open(payload_path)))
    anns = payload.get('annotations') or []
    shutil.copyfile(source, out)
    with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False) as fh:
        json.dump(anns, fh)
        ann_path = fh.name
    try:
        # sibling modules (font_cmap_sanitizer, ...) and fonts come from the repo's pdf-editor folder, read-only
        repo_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..', 'python', 'pdf-editor'))
        exp_dir = os.path.dirname(os.path.abspath(exporter))
        for name in os.listdir(repo_dir):
            dst = os.path.join(exp_dir, name)
            if name != os.path.basename(exporter) and not os.path.exists(dst) and name != '__pycache__':
                os.symlink(os.path.join(repo_dir, name), dst)
        env = dict(os.environ, PYTHONPATH=exp_dir)
        r = subprocess.run([sys.executable, exporter, out, ann_path], capture_output=True, text=True, timeout=300,
                           cwd=exp_dir, env=env)
        if r.returncode != 0:
            sys.stderr.write(r.stdout[-2000:] + r.stderr[-2000:])
            sys.exit(r.returncode)
    finally:
        os.unlink(ann_path)
    print(json.dumps({'out': out, 'annotations': len(anns)}))


if __name__ == '__main__':
    main()
