// The running application of one environment, on top of main.bicep and access.bicep:
//
//   redis       Redis as a container, reachable only inside the environment
//   web         nginx + php-fpm, public ingress
//   horizon     queue workers
//   scheduler   job, every minute: php artisan schedule:run
//   migrate     job, started by hand or by the deploy: php artisan migrate --force
//
//   az deployment group create -g netkit-stage -f infra/apps.bicep -p infra/params/stage.apps.bicepparam
//
// One image, a role per container (docker/entrypoint.sh). A deploy changes
// `imageTag` and nothing else.

targetScope = 'resourceGroup'

@allowed(['stage', 'prod'])
param env string
param suffix string
param location string = resourceGroup().location

@description('Tag of acrnetkit<suffix>.azurecr.io/netkit to run, e.g. the commit sha.')
param imageTag string
param redisImageTag string = '7.4-alpine'

@description('Non-secret settings: infra/env/<env>.json.')
param settings object

@description('Environment variable -> Key Vault secret name. Every secret listed must exist in the vault.')
param vaultEnv object

@description('Public host name of the site. Empty = the web app\'s default Container Apps host.')
param appHost string = ''

param web object
param horizon object
param redisMemoryMb int

var registry = 'acrnetkit${suffix}.azurecr.io'
var image = '${registry}/netkit:${imageTag}'
var tags = { app: 'netkit', env: env, owner: 'eddie' }

resource cae 'Microsoft.App/managedEnvironments@2024-03-01' existing = {
  name: 'cae-netkit-${env}'
}

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' existing = {
  name: 'id-netkit-${env}-app'
}

var vaultUri = 'https://kv-netkit-${env}-${suffix}${environment().suffixes.keyvaultDns}/'

var host = empty(appHost) ? 'web.${cae.properties.defaultDomain}' : appHost

// Secrets are Key Vault references read with the app identity: no value is ever
// in this template, in a parameter file or in the deployment history.
var secretNames = union(map(items(vaultEnv), s => s.value), [])
var secrets = [
  for name in secretNames: {
    name: name
    keyVaultUrl: '${vaultUri}secrets/${name}'
    identity: identity.id
  }
]

var appEnv = concat(
  map(items(union(settings, { APP_URL: 'https://${host}' })), s => { name: s.key, value: s.value }),
  map(items(vaultEnv), s => { name: s.key, secretRef: s.value })
)

var registries = [{ server: registry, identity: identity.id }]
var userIdentity = { type: 'UserAssigned', userAssignedIdentities: { '${identity.id}': {} } }

// www-data is uid 33 in the image. SMB has no ownership of its own, and nobrl
// stops byte-range lock requests that SMB would refuse.
var smbOptions = 'uid=33,gid=33,dir_mode=0770,file_mode=0660,mfsymlinks,nobrl'
var volumes = [
  { name: 'app-files', storageType: 'AzureFile', storageName: 'app-files', mountOptions: smbOptions }
  { name: 'fonts-files', storageType: 'AzureFile', storageName: 'fonts-files', mountOptions: smbOptions }
]
var volumeMounts = [
  { volumeName: 'app-files', mountPath: '/var/www/html/storage/app' }
  { volumeName: 'fonts-files', mountPath: '/var/www/html/public/fonts/runtime-extracted' }
]

resource redis 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'redis'
  location: location
  tags: tags
  identity: userIdentity
  properties: {
    environmentId: cae.id
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      registries: registries
      secrets: [
        { name: 'redis-password', keyVaultUrl: '${vaultUri}secrets/redis-password', identity: identity.id }
      ]
      // TCP, internal: only apps in this environment can reach redis:6379.
      ingress: {
        external: false
        transport: 'tcp'
        targetPort: 6379
        exposedPort: 6379
      }
    }
    template: {
      containers: [
        {
          name: 'redis'
          image: '${registry}/redis:${redisImageTag}'
          // Queued jobs have no TTL and must never be evicted (compose.prod.yaml).
          // No persistence: like the Basic managed tier, a restart starts empty.
          command: ['/bin/sh', '-c']
          args: [
            'exec redis-server --requirepass "$REDIS_PASSWORD" --maxmemory ${redisMemoryMb}mb --maxmemory-policy noeviction --save "" --appendonly no'
          ]
          env: [{ name: 'REDIS_PASSWORD', secretRef: 'redis-password' }]
          resources: { cpu: json('0.25'), memory: '0.5Gi' }
          probes: [
            { type: 'Liveness', tcpSocket: { port: 6379 }, periodSeconds: 10, failureThreshold: 3 }
          ]
        }
      ]
      scale: { minReplicas: 1, maxReplicas: 1 }
    }
  }
}

resource webApp 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'web'
  location: location
  tags: tags
  identity: userIdentity
  properties: {
    environmentId: cae.id
    workloadProfileName: 'Consumption'
    configuration: {
      // Single for now; traffic splitting between revisions (Pipelines 8) needs Multiple.
      activeRevisionsMode: 'Single'
      registries: registries
      secrets: secrets
      ingress: {
        external: true
        transport: 'auto'
        targetPort: 8080
        allowInsecure: false
        stickySessions: { affinity: 'none' }
      }
    }
    template: {
      containers: [
        {
          name: 'web'
          image: image
          args: ['web']
          env: appEnv
          resources: { cpu: json(web.cpu), memory: web.memory }
          volumeMounts: volumeMounts
          probes: [
            // The container builds its config, route and view caches before php-fpm starts.
            { type: 'Startup', httpGet: { path: '/fpm-ping', port: 8080 }, periodSeconds: 5, failureThreshold: 48 }
            { type: 'Liveness', httpGet: { path: '/fpm-ping', port: 8080 }, periodSeconds: 15, failureThreshold: 4 }
            { type: 'Readiness', httpGet: { path: '/up', port: 8080 }, periodSeconds: 10, failureThreshold: 3 }
          ]
        }
      ]
      volumes: volumes
      scale: {
        minReplicas: web.minReplicas
        maxReplicas: web.maxReplicas
        rules: [{ name: 'http', http: { metadata: { concurrentRequests: '40' } } }]
      }
    }
  }
  dependsOn: [redis]
}

resource horizonApp 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'horizon'
  location: location
  tags: tags
  identity: userIdentity
  properties: {
    environmentId: cae.id
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      registries: registries
      secrets: secrets
    }
    template: {
      // Longer than the longest job (export 240 s, extraction 300 s): a new
      // revision lets running jobs finish.
      terminationGracePeriodSeconds: 330
      containers: [
        {
          name: 'horizon'
          image: image
          args: ['horizon']
          env: appEnv
          resources: { cpu: json(horizon.cpu), memory: horizon.memory }
          volumeMounts: volumeMounts
        }
      ]
      volumes: volumes
      scale: { minReplicas: 1, maxReplicas: 1 }
    }
  }
  dependsOn: [redis]
}

resource schedulerJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'scheduler'
  location: location
  tags: tags
  identity: userIdentity
  properties: {
    environmentId: cae.id
    workloadProfileName: 'Consumption'
    configuration: {
      triggerType: 'Schedule'
      scheduleTriggerConfig: { cronExpression: '* * * * *', parallelism: 1, replicaCompletionCount: 1 }
      replicaTimeout: 900
      replicaRetryLimit: 0
      registries: registries
      secrets: secrets
    }
    template: {
      containers: [
        {
          name: 'scheduler'
          image: image
          args: ['schedule-run']
          env: appEnv
          resources: { cpu: json('0.25'), memory: '0.5Gi' }
          volumeMounts: volumeMounts
        }
      ]
      volumes: volumes
    }
  }
}

resource migrateJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'migrate'
  location: location
  tags: tags
  identity: userIdentity
  properties: {
    environmentId: cae.id
    workloadProfileName: 'Consumption'
    configuration: {
      triggerType: 'Manual'
      manualTriggerConfig: { parallelism: 1, replicaCompletionCount: 1 }
      replicaTimeout: 1800
      replicaRetryLimit: 0
      registries: registries
      secrets: secrets
    }
    template: {
      containers: [
        {
          name: 'migrate'
          image: image
          args: ['migrate']
          env: appEnv
          resources: { cpu: json('0.5'), memory: '1Gi' }
          volumeMounts: volumeMounts
        }
      ]
      volumes: volumes
    }
  }
}

output url string = 'https://${host}'
output webFqdn string = webApp.properties.configuration.ingress.fqdn
