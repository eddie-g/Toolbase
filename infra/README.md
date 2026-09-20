# Azure infrastructure

Bicep for one Netkit environment. Stage and production are the same code with a
different parameter file; each deploys into its own resource group
(`netkit-stage`, `netkit-prod`) in `eastus2`.

| File | What it creates |
| --- | --- |
| `main.bicep` | Entry point, resource-group scope |
| `modules/network.bicep` | VNet (`apps`, `mysql`, `private` subnets), private DNS zones, optional NAT gateway with a static outbound IP |
| `modules/container-apps-env.bicep` | Log Analytics workspace (daily cap) and the Container Apps environment |
| `modules/key-vault.bicep` | Key Vault (RBAC, purge protection) |
| `modules/mysql.bicep` | MySQL Flexible Server 8.4 inside the VNet (no public endpoint), database `netkit`, slow query log, admin password stored in Key Vault |
| `modules/redis.bicep` | Azure Cache for Redis (TLS only, `noeviction`), private endpoint, access key stored in Key Vault as `redis-password` |
| `modules/storage.bicep` | Storage account open to the apps subnet only, file shares `app` and `fonts`, and the environment mounts `app-files` / `fonts-files` |
| `params/stage.bicepparam` | `10.20.0.0/16`, no NAT gateway, 1 GB/day log cap, MySQL B1ms / 20 GB / 7-day backups, Redis Basic C0, shares 50 + 5 GB |
| `params/prod.bicepparam` | `10.30.0.0/16`, NAT gateway (Namecheap whitelist), 2 GB/day log cap, MySQL B2ms / 64 GB / 14-day backups, Redis Basic C1, shares 200 + 20 GB |

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

## Cost notes

- The VNet, subnets, service endpoints and the Container Apps environment are free.
  Private DNS zones are about $0.50 a month each.
- Storage and Key Vault are reached over service endpoints (free). Redis has no
  service endpoint, so it gets a private endpoint (about $7 a month) in its own story.
- `fixedEgress = true` adds a NAT gateway: about $32 a month plus $0.045 per GB.
  Production only.
- MySQL B1ms is about $13 a month plus storage (about $2 for 20 GB); B2ms about $63 with a 1-year
  reservation. A stopped server bills storage only and restarts by itself after 30 days.
- Redis Basic C0 (250 MB) is about $16 a month, C1 (1 GB) about $41; its private endpoint about $7.
- Standard file shares bill for the GB actually stored (about $0.06 per GB) plus transactions; the
  quota is only a ceiling.
- Log Analytics bills per GB ingested (the first 5 GB a month are free); the
  daily cap stops a log storm from running up the bill.

## Shared resources (not in this code)

Created once by hand in `netkit-shared`: the container registry
`acrnetkitiedp` and the two deploy identities used by GitHub Actions.
