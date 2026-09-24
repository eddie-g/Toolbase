# Load testing

k6 scenarios in `tests/Load`, run against a staging stack — never production: the seeded accounts share a known password, and the scripts sign in thousands of times from one address.

## Scenarios

| Script | What it drives | Knobs |
| --- | --- | --- |
| `login.js` | Sign-in storm through the real form, arrivals per second | `RATE`, `DURATION`, `LOAD_USERS` |
| `editor.js` | Signed-in editors that open their document and autosave every 2 s | `EDITORS`, `ANNOTATIONS`, `DURATION`, `DELTA_SAVES=1` once delta saves are deployed (PR #119) |
| `uploads.js` | Concurrent PDF uploads, each queueing an extraction | `UPLOADERS` |
| `exports.js` | Queue a download, poll to completion, fetch the file | `EXPORTERS` |

Each script's header carries a full `k6 run` line and its thresholds (the SLOs it fails on).

## Setting up the stack

1. Seed the accounts and their documents (one small real PDF each, from `resources/load-tests/invoice.pdf`):

   ```sh
   php artisan load:seed --users=1000
   ```

   `loadtest+<n>@netkit.test`, verified, password `load-test-password-2026` unless `--password` is given. The command refuses to run with `APP_ENV=production`. `php artisan load:seed --remove` purges the accounts and their documents afterwards.

2. Relax the sign-in throttle, which is 5 a minute per account and address, 20 an hour per account and 50 an hour per address:

   ```
   LOGIN_RATE_LIMIT_SCALE=100
   ```

   It multiplies all three and is ignored when `APP_ENV=production`.

3. Make sure Horizon runs workers. `config/horizon.php` gives every environment without a plan of its own (`'*'`) the production worker plan, so a staging stack processes extraction and export jobs instead of leaving them queued for ever.

## Running

```sh
k6 run -e BASE_URL=https://staging.example -e LOAD_USERS=1000 -e RATE=20 tests/Load/login.js
```

`LOAD_USERS` must not exceed the number of seeded accounts. Set `LOAD_PASSWORD` if the seed used another password. When k6 runs inside the app's Docker network against `localhost:8081`, `RESOLVE=localhost:8081=<container ip>:80` sends the requests to the container while keeping the cookie host.
