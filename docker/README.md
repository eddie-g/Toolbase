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

What is where, and who can read it:

| Files | Disk | Served by |
|---|---|---|
| documents, originals, previews, exports, working files | private (`storage/app/private`) | the app, to whoever may open the document; finished exports through a signed link that expires in 15 minutes |
| images and signatures placed on documents | private (`annotation-assets/`) | `documents.annotationAsset`, to the document's owner, `Cache-Control: private` |
| generated logos, image previews, the admin stamp previews | public (`storage/app/public`, `/storage/...`) | nginx, to anyone with the URL |

After deploying the release that moved annotation assets, run
`php artisan documents:migrate-annotation-assets` once (it copies, checks,
then deletes, and can be run again). nginx refuses `/storage/annotation-assets/`
either way.

Retention, by the `scheduler` role (each has `--dry-run`):

| Command | When | Removes |
|---|---|---|
| `documents:cleanup-temp` | hourly | finished exports past their link, working files leaked by a killed process, temp files of deleted documents |
| `documents:prune-guests` | daily | documents of visitors without an account, `PDF_GUEST_DOCUMENT_LIFETIME_DAYS` after their last visit |
| `documents:prune` | daily | documents in the trash for `PDF_TRASH_RETENTION_DAYS` (30; the trash page says so), rows left behind by deleted documents, live-save renders after 7 days, admin stamp previews after 24 hours |

The process umask is only relaxed inside the Sail development container.

### Object storage: a decision still to make

The working PDF of a document is edited in place by the Python tools (about
150 places in the code hand a local path to a process), so documents stay on a
shared volume for now, which is what lets several `web` and `horizon`
containers serve the same documents. Moving them to object storage means
download, edit, upload around every edit, plus a lock per document: a design
of its own. What can move without that redesign, because it is written once
and only read afterwards: finished exports, previews, database backups
(`BACKUP_DISK`). On Azure the object store is Blob Storage, which is not
S3-compatible: it needs a Blob adapter for Laravel's filesystem rather than
the S3 one.

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

## Health

- Container health check: php-fpm answers a ping through nginx (`web`);
  `horizon:status` (`horizon`).
- Load balancer: `GET /up` (Laravel).

## Fonts

The editor's 38 font families and the 12 typed-signature families are hosted by
the app (`public/fonts/editor`, 14 MB, committed): the same woff2 files and
`unicode-range` subsets Google Fonts serves, written by
`node scripts/fetch-editor-fonts.mjs` from `resources/fonts/editor-fonts.json`.
Run it again to add a family or pick up new versions. nginx serves
`/fonts/editor/files/` as immutable. Licences: `public/fonts/editor/README.md`.

## Not in the image

Node and Playwright (the QA suites and the admin test runner), xdebug, pcov,
and the development tools Sail installs.
