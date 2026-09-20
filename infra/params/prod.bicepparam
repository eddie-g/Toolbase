using '../main.bicep'

param env = 'prod'
param addressPrefix = '10.30'
// The Namecheap API only answers whitelisted addresses: production sends all
// outbound traffic through one static IP (output `egressIp`).
param fixedEgress = true
param logDailyCapGb = 2
