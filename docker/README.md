# The production image

`docker/Dockerfile.prod` builds one image from tracked files only: nginx and
php-fpm, the PDF toolchain, and the built front end. Development keeps using
Sail (`compose.yaml`); nothing here reads `vendor/`.

```
docker build -f docker/Dockerfile.prod -t netkit:prod .
```

## Roles

The same image runs every part of the deployment. The role is the container's
command (`docker/entrypoint.sh`):

| Command | What runs | Suggested size |
|---|---|---|
| `web` (default) | nginx on `:8080` in front of php-fpm | 1 vCPU, 2 GiB, 2 or more replicas |
| `horizon` | `php artisan horizon` | 2 vCPU, 4 GiB |
| `scheduler` | `php artisan schedule:work` | 0.25 vCPU, 0.5 GiB, exactly one |
| `schedule-run` | `php artisan schedule:run`, then exits | for a platform cron job every minute, instead of `scheduler` |
| `migrate` | `php artisan migrate --force`, then exits | run once per release, before the others start |

On Azure Container Apps: `web` and `horizon` are apps, `migrate` and the
scheduler are jobs. `compose.prod.yaml` is the same layout for one machine.

Every long-lived role first runs `php artisan app:check-config` and stops with
the list of what to fix if a setting is unsafe or a secret is missing. Then it
caches config, routes, views and events: configuration comes from the
container's environment, so the caches are built at start, not in the image.

## Configuration

Everything in `.env.example` is an environment variable of the container.
There is no `.env` file in the image. Set by the image itself:

- `APP_ENV=production`, `LOG_CHANNEL=stderr`
- `PYTHON_BINARY=/opt/venv/bin/python` (what `PythonRunner` uses)
- `PHP_FPM_MAX_CHILDREN=20`, `PHP_FPM_START_SERVERS=4`, `PHP_FPM_MIN_SPARE=2`,
  `PHP_FPM_MAX_SPARE=8`: size these from a load test. A worker settles around
  60 to 90 MB; leave room for the Python processes requests start
  (`PYTHON_MAX_CONCURRENT`).

The Python extractor connects to MySQL itself and reads `DB_HOST`,
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_PORT` and `MYSQL_ATTR_SSL_CA`
from the environment.

## Storage

Two directories hold state and must be the same files in `web` and `horizon`
(one shared volume each, Azure Files, until documents move to object storage):

- `/var/www/html/storage/app`: documents, previews, exports, working files.
- `/var/www/html/public/fonts/runtime-extracted`: the fonts embedded in each
  uploaded PDF. The extractor writes them in `horizon`; nginx serves them to
  the editor from `web`. Without the shared volume the editor falls back to
  substitute fonts.

Everything else under `storage/` is per container and disposable.

## Limits that belong together

| Where | Setting | Value |
|---|---|---|
| app | `PDF_UPLOAD_MAX_KB`, `PDF_AUTOSAVE_MAX_BODY_KB` | 20 MB |
| `docker/php/php.ini` | `upload_max_filesize` / `post_max_size` | 25 MB / 32 MB |
| `docker/nginx/site.conf` | `client_max_body_size` | 32 MB |
| `config/python.php` | longest Python process in a request | 180 s |
| `docker/nginx/site.conf` | `fastcgi_read_timeout` | 200 s |
| `docker/php/fpm-pool.conf.template` | `request_terminate_timeout` | 210 s |
| load balancer | idle / request timeout | at least 210 s |

## Python packages

The image installs `python/requirements-prod.txt`: what the scripts the PHP
code calls need. `python/requirements.txt` adds the offline dictionary and
training tools (torch, transformers, gensim), which no request or job runs.
The build runs `docker/check-python-imports.py`, which finds every `python/*.py`
the PHP code names, follows their local imports and imports each third-party
module: if a script starts using a package that is not in
`requirements-prod.txt`, the build fails.

## Health, errors and logs

- Container health check: php-fpm answers a ping through nginx (`web`);
  `horizon:status` (`horizon`).
- Load balancer: `GET /up` (Laravel). It only proves PHP answers, on purpose:
  a replica should not leave rotation because Horizon, elsewhere, is down.
- Monitor: `GET /health/deep` with `Authorization: Bearer $HEALTH_CHECK_TOKEN`.
  200, or 503 naming the check that failed: MySQL, every Redis connection,
  Horizon running and not paused, Python importing PyMuPDF, a write to
  storage, and (with `BACKUP_ENABLED`) a backup from the last 26 hours. The
  same from a shell: `php artisan app:health`.
- Errors: set `SENTRY_LARAVEL_DSN`. Exceptions, failed jobs and the editor's
  browser errors (`POST /client-errors`) arrive tagged with the release, the
  request id, the route or job and the document id. No IPs, cookies, bodies or
  e-mail addresses are sent, and API keys are redacted from messages.
  Build with `--build-arg APP_RELEASE=$(git rev-parse --short HEAD)`.
- Logs: JSON lines on stderr at `warning`. Every line of a request, and of the
  jobs it queued, has the same `extra.request_id`; the response has it as
  `X-Request-Id`, and JSON error responses carry it as `request_id` in place of
  Python output and traces. Requests over `SLOW_REQUEST_MS` are logged as
  `Slow request` with their route.

Alerts to set up on the platform (none of this is in the app):

| Signal | Alert when |
|---|---|
| `GET /health/deep` every minute | not 200 twice in a row |
| `GET /up` from outside | not 200, or slower than 2 s |
| `GET /login`, `GET /pdf-editor` from outside | not 200, or p95 over 2 s for 5 minutes |
| log lines `Slow request` with route `documents.saveAnnotationState` or `documents.downloadAnnotatedPdf` | more than 20 in 5 minutes |
| log lines `Queued job failed`, `Client error` | a jump over the usual rate |
| Sentry | new issue, or an issue over 50 events an hour |

## Backups

`php artisan db:backup` writes a compressed dump and a manifest (checksum,
tables, row counts) to `BACKUP_DISK` and removes dumps older than
`BACKUP_KEEP_DAYS`, always keeping the newest three.
`php artisan db:restore-drill` restores the newest dump into
`<DB_DATABASE>_restore_drill`, compares tables and row counts with the
manifest, and drops it again; it exits 1 and logs an error if the backup
does not restore. With `BACKUP_ENABLED=true` the `scheduler` role runs the
backup nightly at 02:15 UTC and the drill on Sundays at 03:15.

- The database user needs `CREATE`, `DROP` and the usual rights on the drill
  database: `GRANT ALL ON <db>_restore_drill.* TO '<user>'@'%';`
- Use a disk that is not the app's volume (`BACKUP_DISK=s3`).
- On Azure Flexible Server keep the service's point-in-time restore on as the
  first line; this dump is the copy that survives losing the server.
- To restore for real: `gunzip -c db-….sql.gz | mysql <database>` into an
  empty database, point `DB_DATABASE` at it, run the `migrate` role.
- Measured on the development database (2,500 documents, 108,000 state rows,
  257 MB compressed): 38 s to dump, 128 s to restore and verify.

## Not in the image

Node and Playwright (the QA suites and the admin test runner), xdebug, pcov,
and the development tools Sail installs.
