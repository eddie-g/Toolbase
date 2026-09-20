# Azure infrastructure

Bicep for one Netkit environment. Stage and production are the same code with a
different parameter file; each deploys into its own resource group
(`netkit-stage`, `netkit-prod`) in `eastus2`.

| File | What it creates |
| --- | --- |
| `main.bicep` | Entry point, resource-group scope |
| `modules/network.bicep` | VNet (`apps`, `mysql`, `private` subnets), private DNS zones, optional NAT gateway with a static outbound IP |
| `modules/container-apps-env.bicep` | Log Analytics workspace (daily cap) and the Container Apps environment |
| `params/stage.bicepparam` | `10.20.0.0/16`, no NAT gateway, 1 GB/day log cap |
| `params/prod.bicepparam` | `10.30.0.0/16`, NAT gateway (Namecheap whitelist), 2 GB/day log cap |

## Deploy

Always look at the preview first:

```bash
az deployment group what-if -g netkit-stage -f infra/main.bicep -p infra/params/stage.bicepparam
az deployment group create  -g netkit-stage -f infra/main.bicep -p infra/params/stage.bicepparam
```

The deployment is idempotent: running it again changes nothing unless the code
or the parameters changed.

## Cost notes

- The VNet, subnets, service endpoints and the Container Apps environment are free.
  Private DNS zones are about $0.50 a month each.
- Storage and Key Vault are reached over service endpoints (free). Redis has no
  service endpoint, so it gets a private endpoint (about $7 a month) in its own story.
- `fixedEgress = true` adds a NAT gateway: about $32 a month plus $0.045 per GB.
  Production only.
- Log Analytics bills per GB ingested (the first 5 GB a month are free); the
  daily cap stops a log storm from running up the bill.

## Shared resources (not in this code)

Created once by hand in `netkit-shared`: the container registry
`acrnetkitiedp` and the two deploy identities used by GitHub Actions.
