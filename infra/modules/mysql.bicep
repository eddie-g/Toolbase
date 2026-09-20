// MySQL Flexible Server inside the environment's VNet. In this mode the server
// has no public endpoint at all; it resolves through the private DNS zone.
//
// The admin password is only needed when the server is first created. Later
// deployments leave `adminPassword` empty, which keeps the existing password and
// the existing Key Vault secret untouched.

param location string
param env string
param suffix string
param tags object

param subnetId string
param privateDnsZoneId string
param keyVaultName string

@description('Burstable SKU, e.g. Standard_B1ms (stage) or Standard_B2ms (production).')
param skuName string
param storageGb int
param backupRetentionDays int

@secure()
param adminPassword string

var adminLogin = 'netkitadmin'
var databaseName = 'netkit'

resource server 'Microsoft.DBforMySQL/flexibleServers@2024-12-30' = {
  name: 'mysql-netkit-${env}-${suffix}'
  location: location
  tags: tags
  sku: {
    name: skuName
    tier: 'Burstable'
  }
  properties: {
    // Same major version as the dev box and CI.
    version: '8.4'
    administratorLogin: adminLogin
    administratorLoginPassword: empty(adminPassword) ? null : adminPassword
    storage: {
      storageSizeGB: storageGb
      autoGrow: 'Enabled'
    }
    backup: {
      backupRetentionDays: backupRetentionDays
      geoRedundantBackup: 'Disabled'
    }
    highAvailability: { mode: 'Disabled' }
    network: {
      delegatedSubnetResourceId: subnetId
      privateDnsZoneResourceId: privateDnsZoneId
      publicNetworkAccess: 'Disabled'
    }
  }
}

// The dev database is utf8mb4 / utf8mb4_unicode_ci, which is also what
// config/database.php asks for.
resource database 'Microsoft.DBforMySQL/flexibleServers/databases@2023-12-30' = {
  parent: server
  name: databaseName
  properties: {
    charset: 'utf8mb4'
    collation: 'utf8mb4_unicode_ci'
  }
}

var settings = [
  { name: 'require_secure_transport', value: 'ON' }
  { name: 'slow_query_log', value: 'ON' }
  { name: 'long_query_time', value: '1' }
]

// Server parameters cannot be changed in parallel.
@batchSize(1)
resource configuration 'Microsoft.DBforMySQL/flexibleServers/configurations@2023-12-30' = [
  for setting in settings: {
    parent: server
    name: setting.name
    properties: {
      value: setting.value
      source: 'user-override'
    }
    dependsOn: [database]
  }
]

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' existing = {
  name: keyVaultName
}

resource adminPasswordSecret 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = if (!empty(adminPassword)) {
  parent: vault
  name: 'mysql-admin-password'
  properties: {
    value: adminPassword
    contentType: 'MySQL admin (${adminLogin}). Not used by the application.'
  }
}

output host string = server.properties.fullyQualifiedDomainName
output adminLogin string = adminLogin
output database string = databaseName
