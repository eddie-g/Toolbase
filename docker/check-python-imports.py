"""Fail the image build if a script the app runs imports something missing.

usage: check-python-imports.py APP_DIR

Finds every python/...py path named in the PHP code, parses each script and
the local modules it pulls in, and imports every third-party module they use.
A requirements-prod.txt that has fallen behind the scripts stops the build
here instead of failing an export in production.
"""
import ast
import importlib
import re
import sys
from pathlib import Path

app_dir = Path(sys.argv[1]).resolve()
php_sources = list((app_dir / "app").rglob("*.php")) + list((app_dir / "config").rglob("*.php"))
scripts = set()
for source in php_sources:
    for match in re.findall(r"python/[A-Za-z0-9_./-]+\.py", source.read_text(encoding="utf-8", errors="ignore")):
        path = app_dir / match
        # Test tooling (Playwright screenshots) is not part of the production image.
        if path.exists() and "test_helpers" not in path.parts:
            scripts.add(path)

local_dirs = [app_dir / "python" / name for name in ("pdf-editor", "test_helpers", "domain-search")]
seen, modules = set(), {}
queue = sorted(scripts)
while queue:
    script = queue.pop()
    if script in seen:
        continue
    seen.add(script)
    try:
        tree = ast.parse(script.read_text(encoding="utf-8", errors="ignore"))
    except SyntaxError as error:
        print(f"SYNTAX ERROR {script.relative_to(app_dir)}: {error}")
        sys.exit(1)
    for node in ast.walk(tree):
        names = []
        if isinstance(node, ast.Import):
            names = [alias.name for alias in node.names]
        elif isinstance(node, ast.ImportFrom) and node.level == 0 and node.module:
            names = [node.module]
        for name in names:
            top = name.split(".")[0]
            # Local modules: beside the script, or in one of the project's
            # Python directories, which several scripts add to sys.path.
            local = next((d / f"{top}.py" for d in [script.parent, *local_dirs] if (d / f"{top}.py").exists()), None)
            if local is not None:
                queue.append(local)
            else:
                modules.setdefault(top, script)

missing = []
for module, script in sorted(modules.items()):
    if module in sys.stdlib_module_names:
        continue
    try:
        importlib.import_module(module)
    except Exception as error:  # ImportError, or a native library that is not installed
        missing.append(f"{module} (used by {script.relative_to(app_dir)}): {error}")

print(f"checked {len(seen)} scripts, {len(modules)} imported modules")
if missing:
    print("MISSING:\n  " + "\n  ".join(missing))
    sys.exit(1)
