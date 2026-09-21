// The identity the container apps run as, and the two things it may do:
// pull images from the shared registry and read secrets from its own vault.
//
// Deployed by the subscription OWNER only, once per environment:
//
//   az deployment group create -g netkit-stage -f infra/access.bicep -p env=stage suffix=iedp
//
// The pipeline's deploy identities are Contributor and cannot grant roles, which
// is why this is not part of main.bicep.

targetScope = 'resourceGroup'

@allowed(['stage', 'prod'])
param env string
param suffix string
param location string = resourceGroup().location
param sharedResourceGroup string = 'netkit-shared'

var keyVaultSecretsUser = '4633458b-17de-408a-b874-0445c86b69e6'

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-netkit-${env}-app'
  location: location
  tags: { app: 'netkit', env: env, owner: 'eddie' }
}

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' existing = {
  name: 'kv-netkit-${env}-${suffix}'
}

resource readSecrets 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: vault
  name: guid(vault.id, identity.id, keyVaultSecretsUser)
  properties: {
    principalId: identity.properties.principalId
    principalType: 'ServicePrincipal'
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', keyVaultSecretsUser)
  }
}

module pullImages 'modules/registry-pull.bicep' = {
  name: 'registry-pull-${env}'
  scope: resourceGroup(sharedResourceGroup)
  params: {
    registryName: 'acrnetkit${suffix}'
    principalId: identity.properties.principalId
  }
}

output identityId string = identity.id
output clientId string = identity.properties.clientId
