using '../main.bicep'

param env = 'stage'
param addressPrefix = '10.20'
// Stage talks to the Namecheap sandbox, so it needs no whitelisted address.
param fixedEgress = false
param logDailyCapGb = 1

param suffix = 'iedp'
param mysqlSku = 'Standard_B1ms'
param mysqlStorageGb = 20
param mysqlBackupDays = 7
// Set MYSQL_ADMIN_PASSWORD only for the deployment that first creates the server.
param mysqlAdminPassword = readEnvironmentVariable('MYSQL_ADMIN_PASSWORD', '')
