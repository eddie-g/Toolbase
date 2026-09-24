# CI

`.github/workflows/ci.yml` runs on every pull request and on every push to
`dev` and `main`.

| Job | What it proves |
|---|---|
| **PHP** | `composer install` from the lock file; every migration runs on an empty MySQL 8.4; `php artisan test` with MySQL, Redis, the production Python requirements and a fresh Vite build |
| **Editor (JS)** | `npm run test:unit` (every `*.test.mjs`), `npm run build` with the bundle budget, and uploads `public/build` as the artifact `public-build-<sha>` |
| **Python** | `python/test_helpers` unit tests, two exporter regressions on a fixture PDF, and that every script PHP calls can import what it needs from `python/requirements-prod.txt` |
| **Dependency audits** | `composer audit` and `npm audit --omit=dev`. Reports in the run summary and does not block yet: there are known advisories to work through. Make it required once it is clean |

The production image builds its own assets (`docker/Dockerfile.prod`); the
artifact is for a deploy that does not use the image.

## Running the same thing locally

```
docker exec -e DB_DATABASE=testing_ci netkit-laravel.test-1 php artisan test
npm run test:unit && npm run build
PATH=$PWD/.venv/bin:$PATH python -m unittest discover -s python/test_helpers -p "test_*.py"
```

Two `RefreshDatabase` runs against the same database at the same time break
each other (each starts with `migrate:fresh`). A second session should use its
own database, as above: create it once with
`CREATE DATABASE testing_ci; GRANT ALL ON testing_ci.* TO 'sail'@'%';`.

## Tests under `tests/`

`/tests/*` is in `.gitignore`. A new test file is only in CI if it was added
with `git add -f`. Check with `git ls-files tests/Feature`.

## Required checks

Once this workflow is on `dev` and `main` and every open pull request has been
updated from its base (a branch without the workflow never reports, and a
required check that never reports blocks the merge):

```
for branch in dev main; do
  gh api -X PUT repos/eddie-g/Toolbase/branches/$branch/protection --input - <<'JSON'
{
  "required_status_checks": { "strict": false, "contexts": ["PHP", "Editor (JS)", "Python"] },
  "enforce_admins": false,
  "required_pull_request_reviews": null,
  "restrictions": null
}
JSON
done
```

## Nightly browser suites

`.github/workflows/nightly-e2e.yml` runs the Playwright suites in
`tests/AutomatedTests` against a running site at 04:30 UTC, or on demand. It
needs the repository variable `E2E_BASE_URL` and the secrets `E2E_ADMIN_EMAIL`
and `E2E_ADMIN_PASSWORD` (the QA admin of that site); without the variable it
says so and passes. The Signature suite is not in the default list. The
`tests/OverlayEditor` regression runners read the local database and are not
part of it.
