using '../apps.bicep'

param env = 'stage'
param suffix = 'iedp'

// The deploy passes the tag it built: -p imageTag=<sha>
param imageTag = readEnvironmentVariable('NETKIT_IMAGE_TAG', 'cc06329')

param settings = loadJsonContent('../env/stage.json')
// Only secrets that exist in kv-netkit-stage-iedp. DB_PASSWORD points at the
// admin password until the least-privilege users exist (Asana: Cloud 4.4).
param vaultEnv = loadJsonContent('../env/stage.secrets.json')

param web = {
  cpu: '0.5'
  memory: '1Gi'
  minReplicas: 1
  maxReplicas: 2
}
param horizon = {
  cpu: '1.0'
  memory: '2Gi'
}
param redisMemoryMb = 384
