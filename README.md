# Apartar

Catálogos con apartado en cuotas para negocios y vendedores independientes. Laravel 12 + Inertia + React, MySQL, Redis y Reverb.

Las reglas de negocio, la arquitectura y el tablero de tareas viven en Notion (privado).

Hay dos maneras de levantar el proyecto en local:

| | Docker | Fuera de Docker |
| --- | --- | --- |
| Para qué | Probar la app como en producción, con todos los servicios | Programar y correr los tests rápido |
| Código | Copiado en la imagen: tras cambiar código hay que reconstruir | El del disco: los cambios se ven al instante |
| Assets | Compilados (`vite build`) | Vite en caliente |
| URL | <http://localhost:8080> | <http://localhost:8000> |

Las dos usan el mismo `.env`: el override de Docker fija los hosts, credenciales y drivers de sus servicios, así que el `.env` se escribe para trabajar fuera de Docker.

## Docker (como producción)

Requisito: Docker Desktop con Compose.

### Primera vez

```bash
cp .env.example .env
docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show
```

Copia la clave que imprime en `APP_KEY` del `.env` y después:

```bash
make up
make fresh
```

`make up` construye las imágenes y levanta el stack. El contenedor `app` aplica las migraciones al arrancar. `make fresh` siembra el escenario de prueba (ver [Datos de prueba](#datos-de-prueba)).

### Día a día

| Comando | Qué hace |
| --- | --- |
| `make up` | Reconstruye las imágenes con el código actual y levanta todo. **Hay que repetirlo tras cambiar código**: la imagen lleva el código y los assets dentro, como en producción |
| `make down` | Baja el stack conservando los volúmenes (base de datos, Redis, archivos subidos) |
| `make logs` | Sigue los logs de todos los servicios |
| `make sh` | Shell dentro del contenedor `app` |
| `make fresh` | Borra todo y siembra el escenario de prueba |
| `make empty` | Borra todo y deja la base vacía, como para producción |
| `docker compose exec app php artisan …` | Cualquier comando de Artisan dentro de Docker |

Sin `make` (PowerShell no lo trae: `winget install ezwinports.make`), cada receta es un comando:

| `make` | Comando |
| --- | --- |
| `make up` | `docker compose up -d --build --remove-orphans` |
| `make down` | `docker compose down --remove-orphans` (tras `make dev`: `docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.dev.yml --profile dev down --remove-orphans`) |
| `make dev` | `docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.dev.yml --profile dev up -d --build --remove-orphans` |
| `make logs` | `docker compose logs -f --tail=100` |
| `make fresh` | `docker compose exec app php artisan db:reset-demo --force` |
| `make empty` | `docker compose exec app php artisan db:reset-empty` |
| `make sh` | `docker compose exec app sh` |

### Servicios y puertos

| Servicio | Qué es | Desde el host |
| --- | --- | --- |
| `nginx` | Sirve los assets compilados y pasa PHP a `app` | <http://localhost:8080> |
| `app` | PHP-FPM; migra al arrancar | — |
| `queue` | Worker de colas y `schedule:work` | — |
| `reverb` | WebSocket | `localhost:8081` |
| `mysql` | Base de datos (`apartar` / `secret`, root `root`) | `localhost:3307` |
| `redis` | Caché, sesiones y colas | `localhost:6380` |

Son los mismos servicios que en producción: `docker-compose.override.yml` solo publica los puertos y fija credenciales y hosts locales.

Volúmenes: `mysql_data`, `redis_data` y `app_storage` (comprobantes, fotos y exportaciones).

### Modo desarrollo con recarga en caliente (opcional)

```bash
make dev
```

Monta el código del disco en los contenedores y arranca Vite (`localhost:5173`), así los cambios se ven sin reconstruir. Añade también `mysql_test`, un MySQL en tmpfs (`localhost:3308`) para correr Pest dentro del contenedor. En Windows es **mucho más lento** porque cada archivo cruza del disco de Windows a la máquina virtual de Docker: para programar es mejor trabajar fuera de Docker. `make down` lo baja.

## Fuera de Docker

### Requisitos

- **PHP 8.4.27** con las extensiones `bcmath`, `curl`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql` y `zip`, y `memory_limit` de al menos 1 GB. En Windows con pvm: `pvm install 8.4.27 x64 nts`, `pvm use 8.4.27` y activa las extensiones en el `php.ini` de esa versión (vienen todas comentadas).
- **Composer 2**.
- **Node 22** y npm.
- **MySQL 8** con dos bases: `apartar_db` (o el nombre que pongas en `DB_DATABASE`) y `apartar_testing` (la de los tests, fijada en `phpunit.xml`).

```sql
CREATE DATABASE apartar_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE apartar_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

No hace falta Redis: fuera de Docker la caché y las sesiones van a archivo y la cola a la base de datos.

### `.env`

Partiendo de `.env.example`, cambia esto:

```dotenv
APP_URL=http://localhost:8000

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apartar_db
DB_USERNAME=root
DB_PASSWORD=tu-contraseña
DB_TIMEZONE=-04:00

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database

REVERB_HOST=127.0.0.1
REVERB_PORT=8082
VITE_REVERB_PORT=8082
```

- `DB_TIMEZONE=-04:00`: un MySQL instalado en Windows no trae las tablas de zonas horarias y rechaza `America/Caracas`. Venezuela está fija en UTC−4.
- `REVERB_PORT=8082` y `VITE_REVERB_PORT=8082`: Reverb escucha en el 8082 fuera de Docker, porque el 8081 lo publica el Reverb de Docker. Así puedes tener los dos levantados a la vez.
- Docker ignora todo esto: su override fija sus propios valores, incluidas las variables `VITE_*` con las que compila los assets.

### Primera vez

```bash
composer install
npm ci
php artisan key:generate
php artisan storage:link
php artisan db:reset-demo
```

### Día a día

```bash
composer run dev
```

Levanta en una sola terminal los cinco procesos (`Ctrl+C` los para todos):

| Proceso | Comando | Qué hace |
| --- | --- | --- |
| server | `php artisan serve` | La app en <http://localhost:8000> |
| queue | `php artisan queue:work --queue=high,default,exports,telemetry` | Correos, conversiones de imágenes, exportaciones |
| schedule | `php artisan schedule:work` | Tareas programadas (tasa de cambio, recordatorios, vencimientos) |
| reverb | `php artisan reverb:start --port=8082` | WebSocket del chat y la bandeja de comprobantes |
| vite | `npm run dev` | Assets en caliente |

Cada uno se puede lanzar suelto en su propia terminal con el comando de la tabla.

## Datos de prueba

| Comando | Atajo en Docker | Qué deja |
| --- | --- | --- |
| `php artisan db:reset-demo` | `make fresh` | Dos negocios completos para probar todo. Se niega a correr en producción |
| `php artisan db:reset-empty` | `make empty` | Solo planes, tasa inicial y el operador de plataforma de `PLATFORM_ADMIN_*` |

Los dos borran **todos** los datos, los archivos subidos y la caché, y piden confirmación (`--force` para saltarla).

Contraseña de todas las cuentas del escenario de prueba: `Apartar2026demo`.

| Cuenta | Correo | Entrada |
| --- | --- | --- |
| Operador de plataforma | `operador@apartar.test` | `/plataforma/login` |
| Moda Valentina, dueña | `valentina@apartar.test` | `/backoffice/login` |
| Moda Valentina, miembro | `ana@apartar.test` | `/backoffice/login` |
| Tecno Hogar, dueño | `carlos@apartar.test` | `/backoffice/login` |
| Tecno Hogar, miembro | `luis@apartar.test` | `/backoffice/login` |

- Catálogos públicos: `/c/moda-valentina-temporada` y `/c/tecno-hogar-temporada`.
- Los dos negocios están en plan Pro: Moda Valentina con la factura pagada y Tecno Hogar con el comprobante por revisar en la plataforma.
- Cada negocio tiene 8 apartados en todos los estados: completado, en mora, cancelado, vencido, activos y pendientes de inicial, algunos con comprobantes por revisar.
- **Portal del cliente** (`/mi-cuenta/acceso`): cédula + código del apartado. Los códigos cambian en cada siembra; el comando los imprime al final con su estado.
- Los correos no se envían: se escriben en `storage/logs/laravel.log` (`MAIL_MAILER=log`).

## Tests y calidad

Se corren **fuera de Docker**: la suite completa tarda ~1 minuto en local y ~5 en el CI.

| Comando | Qué comprueba |
| --- | --- |
| `composer run test` | Pest: Unit + Feature + Tenancy en paralelo y después Concurrency |
| `vendor/bin/pest tests/Feature/Layaway` | Una carpeta o archivo (con `--parallel`, una sola ruta) |
| `composer run lint` | Pint |
| `composer run analyse` | PHPStan nivel 6 |
| `composer run tenancy` | Auditoría de aislamiento multi-tenant + su suite |
| `npm run lint`, `npm run types`, `npm run test` | ESLint, `tsc` y Vitest |
| `composer run check` y `npm run check` | Todo lo anterior, como en el CI |

La primera vez la suite migra una base por proceso; las siguientes las reutiliza mientras no cambie ninguna migración.

## Problemas conocidos

| Síntoma | Causa y arreglo |
| --- | --- |
| `Unknown or incorrect time zone: 'America/Caracas'` | MySQL sin tablas de zonas horarias: `DB_TIMEZONE=-04:00` en el `.env` |
| `Class "Redis" not found` fuera de Docker | El PHP local no tiene `phpredis`: usa `CACHE_STORE=file`, `SESSION_DRIVER=file` y `QUEUE_CONNECTION=database` |
| La app en Docker no muestra un cambio | La imagen lleva el código: `make up` para reconstruir, o `make dev` |
| Las fotos no se ven fuera de Docker | Falta `php artisan storage:link` |
| `make` no se reconoce en PowerShell | `winget install ezwinports.make` y abre otra terminal, o usa el comando equivalente de la sección «Día a día» |
