// Virtual network for one environment.
//
//   apps     Container Apps environment (delegated). Storage and Key Vault are
//            reached over service endpoints, which are free; only Redis needs a
//            private endpoint because it has no service endpoint.
//   mysql    MySQL Flexible Server, VNet-integrated (delegated), no public access.
//   private  private endpoints (Redis).
//
// A NAT gateway gives the apps subnet one fixed outbound IP. Production needs it
// because the Namecheap API only answers whitelisted addresses; stage uses the
// Namecheap sandbox and goes without.

param location string
param env string
param tags object

@description('First two octets of the /16, e.g. "10.20".')
param addressPrefix string

@description('Attach a NAT gateway with a static public IP to the apps subnet.')
param fixedEgress bool

resource egressIp 'Microsoft.Network/publicIPAddresses@2024-05-01' = if (fixedEgress) {
  name: 'pip-netkit-${env}-egress'
  location: location
  tags: tags
  sku: { name: 'Standard' }
  properties: { publicIPAllocationMethod: 'Static' }
}

resource nat 'Microsoft.Network/natGateways@2024-05-01' = if (fixedEgress) {
  name: 'nat-netkit-${env}'
  location: location
  tags: tags
  sku: { name: 'Standard' }
  properties: {
    idleTimeoutInMinutes: 4
    publicIpAddresses: [{ id: egressIp.id }]
  }
}

resource vnet 'Microsoft.Network/virtualNetworks@2024-05-01' = {
  name: 'vnet-netkit-${env}'
  location: location
  tags: tags
  properties: {
    addressSpace: { addressPrefixes: ['${addressPrefix}.0.0/16'] }
    privateEndpointVNetPolicies: 'Disabled'
    subnets: [
      {
        name: 'apps'
        properties: {
          addressPrefix: '${addressPrefix}.0.0/23'
          delegations: [
            { name: 'container-apps', properties: { serviceName: 'Microsoft.App/environments' } }
          ]
          serviceEndpoints: [
            { service: 'Microsoft.Storage' }
            { service: 'Microsoft.KeyVault' }
          ]
          natGateway: fixedEgress ? { id: nat.id } : null
        }
      }
      {
        name: 'mysql'
        properties: {
          addressPrefix: '${addressPrefix}.2.0/27'
          delegations: [
            { name: 'mysql-flexible', properties: { serviceName: 'Microsoft.DBforMySQL/flexibleServers' } }
          ]
        }
      }
      {
        name: 'private'
        properties: {
          addressPrefix: '${addressPrefix}.3.0/27'
        }
      }
    ]
  }
}

// Zones the later stories register into: MySQL (story 4) and Redis (story 5).
var privateZones = [
  { name: 'netkit-${env}.private.mysql.database.azure.com', privateLink: false }
  { name: 'privatelink.redis.cache.windows.net', privateLink: true }
]

resource zones 'Microsoft.Network/privateDnsZones@2024-06-01' = [
  for zone in privateZones: {
    name: zone.name
    location: 'global'
    tags: tags
  }
]

resource zoneLinks 'Microsoft.Network/privateDnsZones/virtualNetworkLinks@2024-06-01' = [
  for (zone, i) in privateZones: {
    parent: zones[i]
    name: 'vnet-netkit-${env}'
    location: 'global'
    properties: union(
      {
        registrationEnabled: false
        virtualNetwork: { id: vnet.id }
      },
      // Only private-link zones carry a resolution policy.
      zone.privateLink ? { resolutionPolicy: 'Default' } : {}
    )
  }
]

output vnetId string = vnet.id
output appsSubnetId string = vnet.properties.subnets[0].id
output mysqlSubnetId string = vnet.properties.subnets[1].id
output privateSubnetId string = vnet.properties.subnets[2].id
output mysqlZoneId string = zones[0].id
output redisZoneId string = zones[1].id
output egressIp string = fixedEgress ? egressIp!.properties.ipAddress : ''
