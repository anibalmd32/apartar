# PROMPT 02 — `T-002` · Entorno local dockerizado

> Hito **H0 · Cimientos** · Tarea `T-002` · Fase `AB` · 3 pts
> Cierra `REQ-INF-001`, `REQ-INF-003` (parcial) y `REQ-INF-004`.
> Depende de `T-001`, ya cerrado (commit `6731492`): Laravel 12.69.2 + starter kit `react` v1.0.1 instalado en el host, sin Docker.

## 0. Qué vas a hacer

El stack completo en local: **siete servicios en Docker**, `http://localhost:8080` respondiendo, colas priorizadas, `.env.example` completo y un `Makefile` con seis comandos.

**Nada de producción.** No hay dominio comprado, no hay Traefik, no hay TLS. `docker-compose.prod.yml` está **diferido a `T-087`** y no se crea en esta tarea.

## 1. Lee primero, en este orden

| Documento | Para qué |
|---|---|
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-002` | alcance exacto, "hecho cuando" y los **hallazgos de `T-001` que se resuelven aquí** |
| [docs/specs/90-infraestructura-y-entorno.md](../specs/90-infraestructura-y-entorno.md) | `REQ-INF-001` (topología), `REQ-INF-003` (colas y schedule), `REQ-INF-004` (drivers y entornos), `REQ-INF-005` (Reverb). **Salta `REQ-INF-002`: está `🕓 DIFERIDO`** |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§9** | puertos, variables de entorno y comandos del `Makefile` en forma de tabla |

`REQ-INF-006` (build y despliegue) **no** es de esta tarea.

## 2. Alcance

**Sí:**

1. **`Dockerfile` multi-stage** y `docker/` con `nginx.conf`, `php.ini`, `opcache.ini`, `entrypoint.sh`.
2. **`docker-compose.yml` + `docker-compose.override.yml`** con los siete servicios, tres volúmenes nombrados y **una sola red** (`apartar`; en local no existe `traefik_proxy` — `REQ-INF-001` RB-0 y RB-3).
3. **`.env.example` completo**, agrupado por dominio (`REQ-INF-004` RB-5), con los valores de §9.2.
4. Los **cinco drivers** de `REQ-INF-004` RB-1 (`redis` para caché, sesión y cola; `reverb` para broadcast; `local` para filesystem) y la zona `America/Caracas` con locale `es`.
5. Servicio `queue` con las **cuatro colas priorizadas** en orden estricto, `schedule:work`, y `routes/console.php` **vacío**: las catorce entradas del schedule entran en la tarea de cada job, no aquí.
6. `app/Jobs/Concerns/RunsInTenantContext.php` **solo si `T-005`/`T-006` ya existen**; si no, déjalo para `T-006` como dice el plan.
7. **`Makefile`** con los seis comandos de §9.4.
8. Los **tests** que exige el gate de `REQ-INF-001` y `REQ-INF-004`, en particular el de **completitud de `.env.example`** contra las variables usadas en `config/`.

**No:**

- **No** crees `docker-compose.prod.yml`, etiquetas de Traefik, red `traefik_proxy` ni nada de TLS.
- **No** añadas entradas al schedule todavía (punto 5).
- **No** crees migraciones nuestras (`T-004`) ni modelos.
- **No** toques el andamiaje de autenticación del starter kit.

## 3. Lo que `T-001` dejó pendiente y aquí se cierra

Tres cosas concretas, ya anotadas en el plan:

1. **Extensiones de PHP en el `Dockerfile`.** En el host hubo que habilitar `exif`, `zip` e `intl`; el contenedor debe traerlas de fábrica junto al resto: `pdo_mysql`, **`bcmath`** (módulo 13 RB-5 convierte a VES con `bcmul` escala 8), `exif` y `gd` (medialibrary), `zip` (excel), `intl`, `pcntl` (señales de `queue:work`), `opcache` y la extensión `redis`. Si falta alguna, el fallo aparecerá tarde y lejos.
2. **Elimina `laravel/sail`.** No está en [05 §3.2](../specs/05-design-tecnico.md) y su stack Docker queda sustituido por el nuestro. Tener los dos garantiza que alguien levante el equivocado.
3. **Publica `config/broadcasting.php` y fija las variables `REVERB_*` / `VITE_REVERB_*`.** `T-001` no lo hizo porque esos comandos escriben en `.env`, y el `.env` es alcance de **esta** tarea. Ojo con la asimetría de §9.2: `REVERB_HOST=reverb` y `REVERB_PORT=8080` son **internos** (para difundir desde PHP); `VITE_REVERB_HOST=localhost` y `VITE_REVERB_PORT=8081` son los **públicos** (para el navegador del host). Confundirlos es el error clásico y deja el WebSocket muerto sin error visible.

## 4. Dos detalles que suelen salir mal

- **`migrate --force` corre solo en el servicio `app`**, nunca en `queue` ni en `reverb` (`REQ-INF-001` RB-7 y SP-3). El `entrypoint` decide por el rol del servicio, y ese `entrypoint` es el único artefacto con lógica real del módulo: espera activa a la BD, migración condicionada, arranque. El gate espera que supere cuatro líneas.
- **En local no se cachea la configuración.** `config:cache` en desarrollo produce el clásico "cambié el `.env` y no toma efecto" (`REQ-INF-004` SP-4 y RB-6). El `Makefile` no debe incluirlo en el flujo de trabajo. `optimize` es solo de producción.

## 5. Hecho cuando

- [ ] `make up` levanta los siete servicios y `http://localhost:8080` responde.
- [ ] `docker compose config` valida los dos archivos sin error.
- [ ] `healthcheck` declarado en `mysql`, `redis` y `app`, con `depends_on: condition: service_healthy`.
- [ ] `restart: unless-stopped` y límite de memoria en todos los servicios.
- [ ] Test: `migrate` corre solo en `app`.
- [ ] Test: **`.env.example` cubre todas las variables referenciadas en `config/`**.
- [ ] Test: los cinco drivers resuelven a lo esperado y la zona horaria efectiva es `America/Caracas`.
- [ ] Test: arrancar con `APP_DEBUG=true` y `APP_ENV=production` **aborta** en el `entrypoint` (`REQ-INF-004` RB-4).
- [ ] Test: cada una de las cuatro colas está declarada y el worker las consume en orden de prioridad.
- [ ] `laravel/sail` ya no está en `composer.json`.
- [ ] Ningún secreto versionado; `.env` en `.gitignore`.

> Los tests de esta tarea corren con el Pest que `T-001` instaló. `phpunit.xml` definitivo, PHPStan, ESLint y CI son de `T-003`: si algo de eso te falta, escribe los tests de todas formas y no montes la infraestructura de calidad por adelantado.

## 6. Reporta al terminar

1. Salida de `make up` y de `docker compose config` (resumidas), y confirmación de que `localhost:8080` sirve la página del starter kit.
2. Las extensiones de PHP que quedaron compiladas en la imagen.
3. Si algún servicio necesitó una imagen o versión distinta a §9.1, cuál y por qué.
4. Qué variables de `.env.example` quedaron **vacías a propósito** (credenciales de Google, VAPID, superadmin) — para saber qué hay que rellenar antes de las tareas que dependen de ellas.
5. Nada más. No propongas siguientes pasos.
