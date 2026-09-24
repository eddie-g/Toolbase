# Azure infrastructure

Bicep for one Netkit environment. Stage and production are the same code with a
different parameter file; each deploys into its own resource group
(`netkit-stage`, `netkit-prod`) in `eastus2`.

| File | What it creates |
| --- | --- |
| `main.bicep` | The environment's infrastructure: network, Container Apps environment, Key Vault, MySQL, storage |
| `access.bicep` | The identity the apps run as, with AcrPull and Key Vault Secrets User. **Owner only**, once per environment |
| `apps.bicep` | The running application: `redis`, `web`, `horizon` and the `scheduler` / `migrate` jobs. Deployed on every release with a new `imageTag` |
| `modules/network.bicep` | VNet (`apps`, `mysql`, `private` subnets), private DNS zones, optional NAT gateway with a static outbound IP |
| `modules/container-apps-env.bicep` | Log Analytics workspace (daily cap) and the Container Apps environment |
| `modules/key-vault.bicep` | Key Vault (RBAC, purge protection) |
| `modules/mysql.bicep` | MySQL Flexible Server 8.4 inside the VNet (no public endpoint), database `netkit`, slow query log, admin password stored in Key Vault |
| `modules/storage.bicep` | Storage account open to the apps subnet only, file shares `app` and `fonts`, and the environment mounts `app-files` / `fonts-files` |
| `params/stage.bicepparam` | `10.20.0.0/16`, no NAT gateway, 1 GB/day log cap, MySQL B1ms / 20 GB / 7-day backups, shares 50 + 5 GB |
| `params/stage.apps.bicepparam` | Sizes of the stage apps; loads `env/stage.json` and `env/stage.secrets.json` |
| `params/prod.bicepparam` | `10.30.0.0/16`, NAT gateway (Namecheap whitelist), 2 GB/day log cap, MySQL B2ms / 64 GB / 14-day backups, shares 200 + 20 GB |

## Deploy

Always look at the preview first:

```bash
az deployment group what-if -g netkit-stage -f infra/main.bicep -p infra/params/stage.bicepparam
az deployment group create  -g netkit-stage -f infra/main.bicep -p infra/params/stage.bicepparam
```

The deployment is idempotent: running it again changes nothing unless the code
or the parameters changed.

## First deployment of an environment

The MySQL admin password is needed once, when the server is created. Generate
it in the shell, never type it, and let the deployment store it in Key Vault
(secret `mysql-admin-password`):

```bash
export MYSQL_ADMIN_PASSWORD="$(openssl rand -base64 48 | tr -d '/+=' | cut -c1-40)aA1"
az deployment group create -g netkit-stage -f infra/main.bicep -p infra/params/stage.bicepparam
unset MYSQL_ADMIN_PASSWORD
```

Every later deployment runs without the variable: the password and the secret
are left as they are. The application never uses the admin login; it gets its
own least-privilege users.

## Order for a new environment

1. `main.bicep` (with `MYSQL_ADMIN_PASSWORD` set, see below)
2. `scripts/push_secrets.py <env> <dotenv> --apply`
3. `access.bicep` as the subscription owner, then wait a minute for the roles to propagate
4. `apps.bicep`
5. `az containerapp job start -g netkit-<env> -n migrate`

A release afterwards is steps 4 and 5 with a new image tag.

## Configuration and secrets

| File | What it holds |
| --- | --- |
| `env/stage.json` | Every non-secret setting of the stage apps (committed; becomes container environment variables) |
| `env/secrets.json` | Which variables are secrets and where each value comes from. Names only |
| `env/stage.secrets.json` | Variable -> Key Vault secret name for the secrets stage really has; the apps get them as Key Vault references |
| `scripts/push_secrets.py` | Copies the `dotenv` secrets from a local `.env` into the environment's Key Vault |

```bash
python3 infra/scripts/push_secrets.py stage .env            # dry run: prints names and what would happen
python3 infra/scripts/push_secrets.py stage .env --apply
```

Values are never printed or put on a command line. `APP_KEY` is generated once
per environment and never overwritten: rotating it needs `APP_PREVIOUS_KEYS`.
Stage refuses live Stripe keys and skips the Namecheap keys (both Namecheap APIs
need a whitelisted IP; stage has no fixed outbound IP and uses `DOMAIN_LOOKUP=whois`).
A secret's Key Vault name is the variable lower-cased with dashes
(`STRIPE_SECRET` -> `stripe-secret`).

## Cost notes

- The VNet, subnets, service endpoints and the Container Apps environment are free.
  Private DNS zones are about $0.50 a month each.
- Storage and Key Vault are reached over service endpoints (free).
- `fixedEgress = true` adds a NAT gateway: about $32 a month plus $0.045 per GB.
  Production only.
- MySQL B1ms is about $13 a month plus storage (about $2 for 20 GB); B2ms about $63 with a 1-year
  reservation. A stopped server bills storage only and restarts by itself after 30 days.
- Redis runs as a container (0.25 vCPU / 0.5 GiB, always on): roughly $10 to $20 a month.
  Azure Cache for Redis can no longer be created on new subscriptions, and Azure Managed Redis
  only has database 0 while the app uses 0-3 (and `cache:clear` is a FLUSHDB): see Asana story 5.
- Container Apps bill per vCPU-second and GiB-second. An HTTP app that is idle bills at a much
  lower rate; Horizon and Redis are never idle.
- Standard file shares bill for the GB actually stored (about $0.06 per GB) plus transactions; the
  quota is only a ceiling.
- Log Analytics bills per GB ingested (the first 5 GB a month are free); the
  daily cap stops a log storm from running up the bill.

## Shared resources (not in this code)

Created once by hand in `netkit-shared`: the container registry
`acrnetkitiedp` and the two deploy identities used by GitHub Actions.
