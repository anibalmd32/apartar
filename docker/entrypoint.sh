#!/bin/sh
# Apartar — arranque de los contenedores app, queue y reverb.
#
# El rol llega en CONTAINER_ROLE y decide tres cosas: quién migra (solo `app`,
# REQ-INF-001 RB-7 y SP-3), quién levanta el scheduler (solo `queue`,
# REQ-INF-003 RB-8) y qué proceso queda en primer plano.
#
# Modo de prueba: con ENTRYPOINT_DRY_RUN=1 el script evalúa las guardas y
# anuncia cada paso con el prefijo "plan:" sin tocar la base de datos ni
# arrancar nada. Es lo que ejercitan los tests de tests/Feature/Infrastructure.

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

# --- Guardas -----------------------------------------------------------------
# Se evalúan antes de cualquier efecto: un arranque mal configurado falla aquí
# y no a medias.

case "$ROLE" in
    app|queue|reverb) ;;
    *) abort "CONTAINER_ROLE desconocido: '$ROLE' (esperado app, queue o reverb)" ;;
esac

# REQ-INF-004 RB-4: producción con el depurador encendido no arranca.
if [ "$APP_ENV" = "production" ]; then
    case "$APP_DEBUG" in
        true|TRUE|True|1|on|yes)
            abort "APP_DEBUG=$APP_DEBUG con APP_ENV=production. Pon APP_DEBUG=false antes de desplegar."
            ;;
    esac

    if [ "$SESSION_SECURE_COOKIE" != "true" ]; then
        abort "SESSION_SECURE_COOKIE debe ser true en producción (REQ-INF-004 RB-4)."
    fi
fi

# REQ-INF-004 SP-1.
if [ -z "$APP_KEY" ]; then
    abort "APP_KEY vacía. Ejecuta 'php artisan key:generate' y vuelve a levantar."
fi

# --- Espera activa a la base de datos ---------------------------------------
# depends_on con service_healthy ya ordena el arranque, pero el healthcheck de
# mysql da por sano al servidor antes de aceptar la conexión de la aplicación
# (REQ-INF-001 SP-1). Esta espera cubre esa ventana.

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

# --- Preparación -------------------------------------------------------------

prepare() {
    # REQ-INF-006 RB-7: storage/ vive en un volumen, el enlace es idempotente.
    if [ "$ROLE" = "app" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            plan "storage:link"
        else
            php artisan storage:link --quiet || true
        fi
    fi

    # REQ-INF-001 RB-7: solo `app` migra. queue y reverb esperan al esquema.
    if [ "$ROLE" = "app" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            plan "migrate --force"
        else
            log "aplicando migraciones"
            php artisan migrate --force
        fi
    else
        log "rol $ROLE: no migra (REQ-INF-001 RB-7)"
    fi

    # REQ-INF-004 RB-6: en local no se cachea la configuración.
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

# --- Arranque ----------------------------------------------------------------

default_command() {
    case "$ROLE" in
        app) echo "php-fpm" ;;
        queue) echo "php artisan queue:work redis --queue=high,default,exports,telemetry --tries=3 --max-time=3600" ;;
        reverb) echo "php artisan reverb:start --host=0.0.0.0 --port=8080" ;;
    esac
}

if [ "$#" -eq 0 ]; then
    # shellcheck disable=SC2086
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

# REQ-INF-003 RB-8: un solo servicio ejecuta worker y scheduler. El worker
# queda en primer plano; el scheduler lo acompaña y muere con él.
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
