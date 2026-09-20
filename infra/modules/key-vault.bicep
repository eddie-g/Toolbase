// Key Vault for one environment's secrets. Access is by Entra role (RBAC), never
// by access policy. Purge protection is on: a deleted vault or secret can be
// recovered for 90 days, and the vault name stays taken for that long.

param location string
param env string
param suffix string
param tags object

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' = {
  name: 'kv-netkit-${env}-${suffix}'
  location: location
  tags: tags
  properties: {
    tenantId: subscription().tenantId
    sku: { family: 'A', name: 'standard' }
    enableRbacAuthorization: true
    enableSoftDelete: true
    softDeleteRetentionInDays: 90
    enablePurgeProtection: true
    // Reads are gated by Entra roles. The network is narrowed to the apps subnet
    // once the apps read from it (Asana: Cloud 3.4).
    publicNetworkAccess: 'Enabled'
    networkAcls: {
      defaultAction: 'Allow'
      bypass: 'AzureServices'
    }
  }
}

output name string = vault.name
output id string = vault.id
output uri string = vault.properties.vaultUri
