#!/bin/sh
# Entry point of the production image: picks the container's role.
#
#   web | horizon | scheduler | schedule-run | migrate | healthcheck | <any other command>
set -e

role="${1:-web}"
cd /var/www/html

as_app() { exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }
artisan() { setpriv --reuid=www-data --regid=www-data --init-groups php artisan "$@"; }

# Configuration comes from the container's environment, so the caches are
# built when the container starts, not when the image is built. The check
# comes first: a missing secret stops the container with the list of what to
# fix (App\Support\ProductionConfig) instead of serving errors.
warm_up() {
    if [ "${APP_ENV:-production}" = "production" ] && [ "${PRODUCTION_CONFIG_CHECK:-true}" != "false" ]; then
        artisan app:check-config
    fi
    artisan config:cache
    artisan route:cache
    artisan view:cache
    artisan event:cache
    artisan storage:link >/dev/null 2>&1 || true
}

# The health check has to know what this container is.
case "$role" in web|horizon|scheduler) echo "$role" > /run/netkit-role ;; esac

case "$role" in
    web)
        warm_up
        envsubst '${PHP_FPM_MAX_CHILDREN} ${PHP_FPM_START_SERVERS} ${PHP_FPM_MIN_SPARE} ${PHP_FPM_MAX_SPARE}' \
            < /etc/netkit/fpm-pool.conf.template > "/etc/php/${PHP_VERSION}/fpm/pool.d/netkit.conf"
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/web.conf
        ;;
    horizon)
        warm_up
        as_app php artisan horizon
        ;;
    scheduler)
        warm_up
        as_app php artisan schedule:work
        ;;
    schedule-run)
        # For a platform cron (a Container Apps job every minute) instead of a resident scheduler.
        warm_up
        as_app php artisan schedule:run
        ;;
    migrate)
        artisan config:clear >/dev/null
        as_app php artisan migrate --force
        ;;
    healthcheck)
        case "$(cat /run/netkit-role 2>/dev/null)" in
            # php-fpm answers, through nginx. Laravel's own /up is for the load balancer.
            web) exec curl -fsS -o /dev/null http://127.0.0.1:8080/fpm-ping ;;
            horizon) artisan horizon:status | grep -q "is running" ;;
            *) exit 0 ;;
        esac
        ;;
    *)
        exec "$@"
        ;;
esac
