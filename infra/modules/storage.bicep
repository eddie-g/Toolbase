// Shared files for web, Horizon and the jobs. About 150 call sites hand local
// paths to Python, so documents live on a mounted file share rather than in
// object storage. Two trees are shared state (see compose.prod.yaml):
//
//   app    -> /var/www/html/storage/app
//   fonts  -> /var/www/html/public/fonts/runtime-extracted
//
// The account only accepts traffic from the apps subnet (service endpoint).

param location string
param env string
param suffix string
param tags object

param appsSubnetId string
param environmentName string

@description('Share quotas in GB. Standard shares bill for what is used, the quota is only a ceiling.')
param appShareQuotaGb int
param fontsShareQuotaGb int

resource account 'Microsoft.Storage/storageAccounts@2023-05-01' = {
  name: 'stnetkit${env}${suffix}'
  location: location
  tags: tags
  kind: 'StorageV2'
  sku: { name: 'Standard_LRS' }
  properties: {
    minimumTlsVersion: 'TLS1_2'
    supportsHttpsTrafficOnly: true
    allowBlobPublicAccess: false
    // The Container Apps SMB mount authenticates with the account key.
    allowSharedKeyAccess: true
    publicNetworkAccess: 'Enabled'
    networkAcls: {
      defaultAction: 'Deny'
      bypass: 'AzureServices'
      virtualNetworkRules: [
        { id: appsSubnetId, action: 'Allow' }
      ]
    }
  }
}

resource files 'Microsoft.Storage/storageAccounts/fileServices@2023-05-01' = {
  parent: account
  name: 'default'
  properties: {
    shareDeleteRetentionPolicy: {
      enabled: true
      days: 14
    }
  }
}

var shares = [
  { name: 'app', quota: appShareQuotaGb }
  { name: 'fonts', quota: fontsShareQuotaGb }
]

resource fileShares 'Microsoft.Storage/storageAccounts/fileServices/shares@2023-05-01' = [
  for share in shares: {
    parent: files
    name: share.name
    properties: {
      shareQuota: share.quota
      accessTier: 'TransactionOptimized'
      enabledProtocols: 'SMB'
    }
  }
]

resource environment 'Microsoft.App/managedEnvironments@2024-03-01' existing = {
  name: environmentName
}

// Named mounts the container apps refer to in their volume definitions.
resource mounts 'Microsoft.App/managedEnvironments/storages@2024-03-01' = [
  for (share, i) in shares: {
    parent: environment
    name: '${share.name}-files'
    properties: {
      azureFile: {
        accountName: account.name
        accountKey: account.listKeys().keys[0].value
        shareName: fileShares[i].name
        accessMode: 'ReadWrite'
      }
    }
  }
]

output accountName string = account.name
output mountNames array = [for share in shares: '${share.name}-files']
