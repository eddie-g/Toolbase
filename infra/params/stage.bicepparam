using '../main.bicep'

param env = 'stage'
param addressPrefix = '10.20'
// Stage talks to the Namecheap sandbox, so it needs no whitelisted address.
param fixedEgress = false
param logDailyCapGb = 1
