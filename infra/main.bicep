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

output environmentId string = apps.outputs.environmentId
output defaultDomain string = apps.outputs.defaultDomain
output ingressIp string = apps.outputs.staticIp
output egressIp string = network.outputs.egressIp
output mysqlSubnetId string = network.outputs.mysqlSubnetId
output privateSubnetId string = network.outputs.privateSubnetId
output mysqlZoneId string = network.outputs.mysqlZoneId
output redisZoneId string = network.outputs.redisZoneId
