// Log Analytics workspace and the Container Apps environment that web, Horizon
// and the scheduler/migrate jobs run in. Consumption profile only: the
// environment itself costs nothing until an app runs in it.

param location string
param env string
param tags object
param appsSubnetId string

@description('Daily ingestion cap in GB, so a log storm cannot run up the bill.')
param logDailyCapGb int

resource logs 'Microsoft.OperationalInsights/workspaces@2023-09-01' = {
  name: 'log-netkit-${env}'
  location: location
  tags: tags
  properties: {
    sku: { name: 'PerGB2018' }
    retentionInDays: 30
    workspaceCapping: { dailyQuotaGb: logDailyCapGb }
  }
}

resource environment 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: 'cae-netkit-${env}'
  location: location
  tags: tags
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: logs.properties.customerId
        sharedKey: logs.listKeys().primarySharedKey
      }
    }
    vnetConfiguration: {
      infrastructureSubnetId: appsSubnetId
      internal: false
    }
    infrastructureResourceGroup: 'netkit-${env}-cae-infra'
    workloadProfiles: [
      { name: 'Consumption', workloadProfileType: 'Consumption' }
    ]
    zoneRedundant: false
  }
}

output environmentId string = environment.id
output defaultDomain string = environment.properties.defaultDomain
output staticIp string = environment.properties.staticIp
output logWorkspaceId string = logs.id
