#!/usr/bin/env python3
"""Copy an environment's secrets from a dotenv file into its Key Vault.

    python3 infra/scripts/push_secrets.py stage /path/to/.env            # dry run: names only
    python3 infra/scripts/push_secrets.py stage /path/to/.env --apply

Which variables are copied is decided by infra/env/secrets.json. A value is
never printed, logged or put on a command line: it travels in a 0600 temp file
that is deleted straight away. Secrets are written through Azure Resource
Manager, so the caller needs Owner or Contributor on the vault, not a
data-plane role.

Rules
  - "generated" secrets (APP_KEY) are created once and never overwritten.
  - stage refuses live Stripe keys.
  - entries marked "stage": "skip" are not copied to stage.
"""

import base64
import json
import os
import secrets
import subprocess
import sys
import tempfile
from pathlib import Path

SUBSCRIPTION = "63fcf4cc-793e-4111-bc31-73eec94be73c"
SUFFIX = "iedp"
AZ = os.environ.get("AZ", str(Path.home() / ".azcli/bin/az"))
API = "2023-07-01"


def parse_dotenv(path):
    values = {}
    for raw in Path(path).read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[len("export "):]
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        elif " #" in value:
            value = value.split(" #", 1)[0].rstrip()
        values[key.strip()] = "" if value.lower() == "null" else value
    return values


def secret_name(variable, entry):
    return entry.get("secret") or variable.lower().replace("_", "-")


def vault_url(env, name=""):
    base = (
        f"https://management.azure.com/subscriptions/{SUBSCRIPTION}/resourceGroups/netkit-{env}"
        f"/providers/Microsoft.KeyVault/vaults/kv-netkit-{env}-{SUFFIX}/secrets"
    )
    return f"{base}/{name}?api-version={API}" if name else f"{base}?api-version={API}"


def existing_secrets(env):
    out = subprocess.run(
        [AZ, "rest", "--method", "get", "--url", vault_url(env), "--query", "value[].name", "-o", "json"],
        check=True, capture_output=True, text=True,
    ).stdout
    return set(json.loads(out or "[]"))


def put_secret(env, name, value, variable):
    body = {"properties": {"value": value, "contentType": variable}}
    fd, path = tempfile.mkstemp(prefix="kv-", suffix=".json")
    try:
        with os.fdopen(fd, "w") as handle:  # mkstemp creates the file 0600
            json.dump(body, handle)
        subprocess.run(
            [AZ, "rest", "--method", "put", "--url", vault_url(env, name), "--body", f"@{path}", "-o", "none"],
            check=True, capture_output=True, text=True,
        )
    finally:
        os.unlink(path)


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    apply = "--apply" in sys.argv
    if len(args) != 2 or args[0] not in ("stage", "prod"):
        sys.exit(__doc__)
    env, dotenv_path = args

    manifest = json.loads((Path(__file__).parent.parent / "env/secrets.json").read_text())
    manifest.pop("_comment", None)
    dotenv = parse_dotenv(dotenv_path)
    present = existing_secrets(env)

    rows, missing_required = [], []
    for variable, entry in manifest.items():
        name = secret_name(variable, entry)
        source = entry["source"]

        if source == "generated":
            if name in present:
                rows.append((variable, name, "kept (already in the vault, never overwritten)"))
                continue
            value = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
            action = "generated"
        elif source == "dotenv":
            if env == "stage" and entry.get("stage") == "skip":
                rows.append((variable, name, "skipped for stage"))
                continue
            value = dotenv.get(variable, "")
            if not value:
                rows.append((variable, name, "EMPTY in the dotenv file"))
                missing_required.append(variable)
                continue
            if env == "stage" and entry.get("stage") == "test-only" and "_live_" in value[:12]:
                rows.append((variable, name, "REFUSED: live Stripe key, stage takes test keys only"))
                missing_required.append(variable)
                continue
            action = "updated" if name in present else "created"
        else:
            state = "in the vault" if name in present else "not there yet"
            rows.append((variable, name, f"{source}: {state}"))
            continue

        if apply:
            put_secret(env, name, value, variable)
            rows.append((variable, name, action))
        else:
            rows.append((variable, name, f"would be {action}"))
        del value

    width = max(len(r[0]) for r in rows)
    print(f"Key Vault kv-netkit-{env}-{SUFFIX}  ({'APPLIED' if apply else 'dry run, nothing written'})\n")
    for variable, name, status in rows:
        print(f"  {variable.ljust(width)}  {name.ljust(34)}  {status}")
    if missing_required:
        print("\nNot available for this environment: " + ", ".join(missing_required))
        print("Features that need them stay off; list their config keys in PRODUCTION_CONFIG_OPTIONAL"
              " (infra/env/<env>.json) or the app refuses to boot.")
    if not apply:
        print("\nRe-run with --apply to write.")


if __name__ == "__main__":
    main()
