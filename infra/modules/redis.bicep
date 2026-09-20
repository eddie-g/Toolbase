// Azure Cache for Redis for sessions, cache, the rate limiter and Horizon queues.
// The app uses logical databases 0 to 3 (config/database.php), which the
// non-clustered Basic/Standard tiers support.
//
// Redis has no VNet service endpoint, so it is reached through a private
// endpoint and refuses the public network entirely.

param location string
param env string
param suffix string
param tags object

param privateSubnetId string
param privateDnsZoneId string
param keyVaultName string

@description('Basic or Standard. Basic is a single node with no SLA: a patch restart drops sessions and queued jobs.')
@allowed(['Basic', 'Standard'])
param skuName string

@description('Cache size within the C family: 0 = 250 MB, 1 = 1 GB.')
param capacity int

resource redis 'Microsoft.Cache/redis@2024-03-01' = {
  name: 'redis-netkit-${env}-${suffix}'
  location: location
  tags: tags
  properties: {
    sku: {
      name: skuName
      family: 'C'
      capacity: capacity
    }
    enableNonSslPort: false
    minimumTlsVersion: '1.2'
    publicNetworkAccess: 'Disabled'
    redisConfiguration: {
      // Queued jobs live here: never evict, fail writes instead (compose.prod.yaml does the same).
      'maxmemory-policy': 'noeviction'
    }
  }
}

resource endpoint 'Microsoft.Network/privateEndpoints@2024-05-01' = {
  name: 'pe-redis-netkit-${env}'
  location: location
  tags: tags
  properties: {
    subnet: { id: privateSubnetId }
    privateLinkServiceConnections: [
      {
        name: 'redis'
        properties: {
          privateLinkServiceId: redis.id
          groupIds: ['redisCache']
        }
      }
    ]
  }
}

resource endpointDns 'Microsoft.Network/privateEndpoints/privateDnsZoneGroups@2024-05-01' = {
  parent: endpoint
  name: 'default'
  properties: {
    privateDnsZoneConfigs: [
      { name: 'redis', properties: { privateDnsZoneId: privateDnsZoneId } }
    ]
  }
}

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' existing = {
  name: keyVaultName
}

resource passwordSecret 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: vault
  name: 'redis-password'
  properties: {
    value: redis.listKeys().primaryKey
    contentType: 'REDIS_PASSWORD (primary access key of ${redis.name})'
  }
}

output host string = redis.properties.hostName
output sslPort int = redis.properties.sslPort
