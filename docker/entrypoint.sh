#!/bin/sh

set -e

ROLE="${CONTAINER_ROLE:-app}"
DRY_RUN="${ENTRYPOINT_DRY_RUN:-0}"

log() {
    echo "[entrypoint][$ROLE] $*"
}

abort() {
    echo "[entrypoint][$ROLE] ERROR: $*" >&2
    exit 1
}

plan() {
    echo "plan: $*"
}

case "$ROLE" in
    app|queue|reverb|pulse) ;;
    *) abort "CONTAINER_ROLE desconocido: '$ROLE' (esperado app, queue, reverb o pulse)" ;;
esac

if [ "$APP_ENV" = "production" ]; then
    case "$APP_DEBUG" in
        true|TRUE|True|1|on|yes)
            abort "APP_DEBUG=$APP_DEBUG con APP_ENV=production. Pon APP_DEBUG=false antes de desplegar."
            ;;
    esac

    if [ "$SESSION_SECURE_COOKIE" != "true" ]; then
        abort "SESSION_SECURE_COOKIE debe ser true en producción."
    fi
fi

if [ -z "$APP_KEY" ]; then
    abort "APP_KEY vacía. Ejecuta 'php artisan key:generate' y vuelve a levantar."
fi

wait_for_database() {
    attempt=1
    max_attempts="${DB_WAIT_ATTEMPTS:-60}"

    while [ "$attempt" -le "$max_attempts" ]; do
        if php -r '
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s",
                getenv("DB_HOST") ?: "mysql",
                getenv("DB_PORT") ?: "3306",
                getenv("DB_DATABASE") ?: "apartar"
            );

            try {
                new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
                exit(0);
            } catch (Throwable $e) {
                exit(1);
            }
        ' 2>/dev/null; then
            log "base de datos disponible tras $attempt intento(s)"
            return 0
        fi

        log "esperando a la base de datos ($attempt/$max_attempts)..."
        attempt=$((attempt + 1))
        sleep 2
    done

    abort "la base de datos no respondió tras $max_attempts intentos"
}

prepare() {
    if [ "$ROLE" = "app" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            plan "storage:link"
        else
            php artisan storage:link --quiet || true
        fi
    fi

    if [ "$ROLE" = "app" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            plan "migrate --force"
        else
            log "aplicando migraciones"
            php artisan migrate --force
        fi
    else
        log "rol $ROLE: no migra"
    fi

    if [ "$APP_ENV" = "production" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            plan "optimize"
        else
            php artisan optimize
        fi
    else
        log "entorno $APP_ENV: sin optimize, la configuración se lee en cada petición"
    fi
}

default_command() {
    case "$ROLE" in
        app) echo "php-fpm" ;;
        queue) echo "php artisan queue:work redis --queue=high,default,exports,telemetry --tries=3 --max-time=3600" ;;
        reverb) echo "php artisan reverb:start --host=0.0.0.0 --port=8080" ;;
        pulse) echo "php artisan pulse:check" ;;
    esac
}

if [ "$#" -eq 0 ]; then
    set -- $(default_command)
fi

if [ "$DRY_RUN" = "1" ]; then
    if [ "$ROLE" != "app" ]; then
        plan "sin migraciones"
    fi

    prepare

    if [ "$ROLE" = "queue" ]; then
        plan "schedule:work (segundo plano)"
    fi

    plan "exec $*"
    exit 0
fi

wait_for_database
prepare

if [ "$ROLE" = "queue" ]; then
    php artisan schedule:work &
    scheduler_pid=$!

    trap 'kill -TERM "$scheduler_pid" 2>/dev/null; exit 143' INT TERM

    log "arrancando: $*"
    "$@" &
    worker_pid=$!

    wait "$worker_pid"
    status=$?

    kill -TERM "$scheduler_pid" 2>/dev/null || true
    exit "$status"
fi

log "arrancando: $*"
exec "$@"
