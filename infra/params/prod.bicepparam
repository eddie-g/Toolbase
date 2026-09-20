using '../main.bicep'

param env = 'prod'
param addressPrefix = '10.30'
// The Namecheap API only answers whitelisted addresses: production sends all
// outbound traffic through one static IP (output `egressIp`).
param fixedEgress = true
param logDailyCapGb = 2

param suffix = 'iedp'
param mysqlSku = 'Standard_B2ms'
param mysqlStorageGb = 64
param mysqlBackupDays = 14
param redisSku = 'Basic'
param redisCapacity = 1
param appShareQuotaGb = 200
param fontsShareQuotaGb = 20

// Set MYSQL_ADMIN_PASSWORD only for the deployment that first creates the server.
param mysqlAdminPassword = readEnvironmentVariable('MYSQL_ADMIN_PASSWORD', '')
