// One Netkit environment (stage or prod), deployed into its resource group:
//
//   az deployment group create -g netkit-stage -f infra/main.bicep -p infra/params/stage.bicepparam
//
// Stage and production differ only in their parameter file.

targetScope = 'resourceGroup'

@allowed(['stage', 'prod'])
param env string

param location string = resourceGroup().location

@description('First two octets of the environment\'s /16.')
param addressPrefix string

@description('NAT gateway with a static outbound IP (Namecheap whitelist). About $32 a month.')
param fixedEgress bool

param logDailyCapGb int

@description('Short suffix that makes globally unique names unique (Key Vault, MySQL, storage).')
param suffix string

param mysqlSku string
param mysqlStorageGb int
param mysqlBackupDays int

@description('Only for the deployment that first creates the MySQL server. Leave empty afterwards.')
@secure()
param mysqlAdminPassword string = ''

var tags = {
  app: 'netkit'
  env: env
  owner: 'eddie'
}

module network 'modules/network.bicep' = {
  name: 'network'
  params: {
    location: location
    env: env
    tags: tags
    addressPrefix: addressPrefix
    fixedEgress: fixedEgress
  }
}

module apps 'modules/container-apps-env.bicep' = {
  name: 'container-apps-env'
  params: {
    location: location
    env: env
    tags: tags
    appsSubnetId: network.outputs.appsSubnetId
    logDailyCapGb: logDailyCapGb
  }
}

module vault 'modules/key-vault.bicep' = {
  name: 'key-vault'
  params: {
    location: location
    env: env
    suffix: suffix
    tags: tags
  }
}

module mysql 'modules/mysql.bicep' = {
  name: 'mysql'
  params: {
    location: location
    env: env
    suffix: suffix
    tags: tags
    subnetId: network.outputs.mysqlSubnetId
    privateDnsZoneId: network.outputs.mysqlZoneId
    keyVaultName: vault.outputs.name
    skuName: mysqlSku
    storageGb: mysqlStorageGb
    backupRetentionDays: mysqlBackupDays
    adminPassword: mysqlAdminPassword
  }
}

output environmentId string = apps.outputs.environmentId
output defaultDomain string = apps.outputs.defaultDomain
output ingressIp string = apps.outputs.staticIp
output egressIp string = network.outputs.egressIp
output mysqlSubnetId string = network.outputs.mysqlSubnetId
output privateSubnetId string = network.outputs.privateSubnetId
output mysqlZoneId string = network.outputs.mysqlZoneId
output redisZoneId string = network.outputs.redisZoneId
output keyVaultName string = vault.outputs.name
output mysqlHost string = mysql.outputs.host
output mysqlDatabase string = mysql.outputs.database
