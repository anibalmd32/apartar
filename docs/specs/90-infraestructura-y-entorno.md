# Módulo 90 — Infraestructura, Docker, Traefik y colas (`INF`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Asunciones aplicadas: `ASU-INF-01` … `ASU-INF-06`
> Requisitos: `REQ-INF-001` … `REQ-INF-006`
>
> **Efecto del pivote:** la topología no cambia — una sola instalación sirve a todas las organizaciones, que es justo la ventaja de haber elegido tenancy por fila (`D-1`). Lo que sí cambia es la operación: las colas y el scheduler ahora recorren N organizaciones sin fugas de tenant (`REQ-INF-003` RB-1b) y respetando la zona horaria de cada una (RB-5).
>
> ⚠️ **Alcance de esta iteración: solo entorno local.** No hay dominio comprado ni servidor, así que `REQ-INF-002` (Traefik, dominios y TLS) y la parte de producción de `REQ-INF-006` quedan **diferidas**: se conservan especificadas para no perder el trabajo, pero no se implementan todavía. El entorno local **no usa Traefik**: publica puertos directamente, y eso es posible porque las URL de catálogo son por ruta (`/c/{slug}`) y no por subdominio — la decisión `Q4` es la que hace trivial el desarrollo local. Detalle en [05-design-tecnico.md](05-design-tecnico.md) §9.

## Contratos compartidos del módulo

| Servicio | Imagen base | Rol |
|---|---|---|
| `app` | `php:8.3-fpm-alpine` (imagen propia multi-stage) | PHP-FPM de la aplicación |
| `nginx` | `nginx:1.27-alpine` | Servidor web, `fastcgi` a `app` |
| `mysql` | `mysql:8.0` | Base de datos |
| `redis` | `redis:7-alpine` | Caché, sesiones, colas y broadcast |
| `queue` | misma imagen que `app` | `queue:work` + `schedule:work` |
| `reverb` | misma imagen que `app` | `reverb:start` (WebSocket) |
| `node` | `node:22-alpine` | **Solo en desarrollo**: Vite HMR |

Redes: `traefik_proxy` (externa, solo `nginx` y `reverb`) e `internal` (interna, sin salida, para `mysql` y `redis`).

---

### REQ-INF-001 · Topología de `docker-compose`

**Historia de usuario**
> Como responsable del despliegue, quiero levantar todo el sistema con un solo comando y con los servicios aislados, para desplegar y reproducir el entorno sin sorpresas.

**Contratos de datos**
- `docker-compose.yml` (base), `docker-compose.override.yml` (desarrollo), `docker-compose.prod.yml` (producción).
- `Dockerfile` multi-stage; `docker/` con `nginx.conf`, `php.ini`, `opcache.ini`, `entrypoint.sh`.
- Volúmenes nombrados: `mysql_data`, `redis_data`, `app_storage`.

**Reglas de negocio y precondiciones**
- RB-0 **El entorno local es el objetivo de esta iteración y no usa Traefik.** `nginx` publica `8080:80`, `reverb` `8080` interno como `8081:8080`, `vite` `5173`, y `mysql`/`redis` publican sus puertos al host para poder inspeccionarlos con un cliente. La aplicación vive en `http://localhost:8080`, sin TLS y sin DNS comodín. Esto es posible porque las URL públicas son **por ruta** (`/c/{slug}`, `/{org-slug}`) y no por subdominio: la decisión `Q4` es la que elimina la necesidad de un proxy en desarrollo. La red `traefik_proxy` y las etiquetas solo existen en `docker-compose.prod.yml`, que queda diferido (`REQ-INF-002`).
- RB-1 Servicios aislados según la tabla de contratos (`ASU-INF-02`): un contenedor por responsabilidad.
- RB-2 `mysql` y `redis` publican puertos al host **solo en local**; en producción no los publican y viven solo en la red `internal`.
- RB-3 En producción, `nginx` y `reverb` son los únicos servicios en `traefik_proxy`; en local no hay `traefik_proxy` en absoluto.
- RB-4 `app_storage` es un volumen nombrado y persistente compartido por `app`, `queue` y `nginx`: contiene los comprobantes (`REQ-PAY-002`), las imágenes y los archivos de exportación. **Perderlo pierde evidencia**, y así se documenta.
- RB-5 `healthcheck` obligatorio en `mysql`, `redis` y `app`; `depends_on` con `condition: service_healthy` para que las migraciones no arranquen antes de la BD.
- RB-6 Todos los servicios llevan `restart: unless-stopped` y límites de memoria declarados.
- RB-6c **El override de desarrollo añade un octavo servicio, `mysql_test`** (`T-003b`), que no está en la tabla de contratos porque no se despliega: es el MySQL de la suite, con el `datadir` en `tmpfs` de 1 GB y `mem_limit` de 1,5 GB. Va **aparte del `mysql` de siempre** y no como un `tmpfs` colgado de él: memoria rápida y volátil es justo lo que quiere una base que `RefreshDatabase` borra entera en cada ejecución, y justo lo que no quiere la de desarrollo, donde están los datos sembrados con los que se mira la aplicación. Su `command` afloja durabilidad —`innodb-flush-log-at-trx-commit=2`, sin doublewrite— **porque la base es desechable**, y apaga `performance-schema` porque nadie la consulta. El mismo override sube `app` a 3 GB: el techo de producción son 768 MB y es el correcto para servir peticiones, pero ahí dentro no se sirven peticiones sino que se corren las herramientas, y veinte procesos de la suite recolectando cobertura no caben — el síntoma es un `Exit Code: 137` de un *worker* cualquiera, que no se parece a quedarse sin memoria. Dónde vive cada cosa lo comprueba `DockerComposeTest`.
- RB-6b **La imagen compila `gd` con `--with-webp`**, y no es un detalle de empaquetado: sin ese flag `imagewebp()` no existe y **las tres conversiones de `REQ-CAT-005` fallan en el *worker***, no en el request — ninguna prueba del código lo nota, y lo que se ve es un catálogo sirviendo originales sin que nadie sepa por qué. Detectado y arreglado en `T-029`, con un test que genera las tres conversiones de verdad. Los límites de memoria declarados en RB-6 son un techo **del contenedor**, y el de `app` (768 MB) convive con herramientas que piden 1 GB por proceso: ver la nota de `composer analyse` en [91](91-calidad-y-gates.md).
- RB-7 El `entrypoint` de `app` espera a la BD, ejecuta `migrate --force` (solo en el servicio `app`, nunca en `queue` ni en `reverb`, para evitar migraciones concurrentes) y luego arranca PHP-FPM.
- RB-8 Los secretos viajan por `.env`, nunca en el `docker-compose.yml`; `.env.example` documenta cada variable.

**Happy path**
1. `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`.
2. `mysql` y `redis` pasan su healthcheck; `app` migra y arranca; `queue` y `reverb` se conectan.
3. Traefik detecta las etiquetas y publica el sitio con TLS.

**Sad paths y edge cases**
- SP-1 `mysql` tarda en arrancar → `app` espera por el healthcheck en lugar de fallar en bucle.
- SP-2 Migración fallida → `app` sale con código distinto de 0 y no sirve tráfico roto; el despliegue anterior sigue en pie si se usa `--no-deps` por servicio.
- SP-3 `queue` y `app` arrancando a la vez → solo `app` migra (RB-7); `queue` espera a que exista el esquema.
- SP-4 Red `traefik_proxy` inexistente → el `up` falla con un mensaje claro; se documenta el comando para crearla.
- SP-5 Volumen `app_storage` recreado por error → comprobantes perdidos: el runbook exige backup del volumen antes de cualquier operación destructiva.
- SP-6 Contenedor sin límite de memoria consumiendo el host → prevenido por RB-6.
- SP-7 `node` presente en producción por usar el override equivocado → el archivo de producción no lo define y el runbook fija el orden exacto de los `-f`.

**Fuera de alcance inmediato**
- Kubernetes y orquestación multi-nodo.
- Réplicas de MySQL y alta disponibilidad.
- Backups automatizados (se documenta el procedimiento manual).
- Registro de imágenes privado.

**Gate de validación**
- Superficie esperada: `docker-compose.yml`, `docker-compose.override.yml`, `docker-compose.prod.yml`, `Dockerfile`, `docker/entrypoint.sh`, `docker/nginx.conf`, `.env.example`.
- Tipos esperados: los siete servicios de la tabla, tres volúmenes nombrados, dos redes.
- Anti-`SHALLOW`: `docker/entrypoint.sh` (espera activa a la BD, migración condicionada por rol de servicio, `optimize`, arranque) supera 4 líneas y es el artefacto con lógica del módulo.
- Tests: `docker compose config` valida los tres archivos; healthchecks declarados en los tres servicios; `mysql`/`redis` sin puertos publicados en producción; solo `nginx` y `reverb` en `traefik_proxy`; `migrate` solo en `app`; `.env.example` cubre todas las variables usadas (test que compara `config()` con el ejemplo).

---

### REQ-INF-002 · Integración con Traefik, dominios y TLS · 🕓 DIFERIDO

> **Diferido a una iteración posterior.** No hay dominio ni servidor todavía. El requisito se conserva íntegro porque sigue siendo la topología objetivo, pero **no entra en el plan de tareas** ([06-plan-de-tareas.md](06-plan-de-tareas.md)). El entorno local publica puertos directamente sin Traefik (`REQ-INF-001` RB-0).

**Historia de usuario**
> Como responsable del despliegue, quiero que Traefik publique el sitio con HTTPS automático a partir de etiquetas, para no gestionar certificados a mano.

**Contratos de datos**
- Etiquetas `traefik.*` en los servicios `nginx` y `reverb` (`ASU-INF-01`).
- Red externa `traefik_proxy`; instancia de Traefik **fuera** de este compose.
- `TrustProxies` configurado para la red de Traefik.

**Reglas de negocio y precondiciones**
- RB-1 Traefik es un **stack externo compartido** (`ASU-INF-01`): este proyecto **no** define el servicio Traefik, solo declara `networks: traefik_proxy: external: true` y publica etiquetas.
- RB-2 Routers declarados:
  - `apartar.store` y `www.apartar.store` → servicio `nginx` (con redirección de `www` a apex).
  - `ws.apartar.store` → servicio `reverb` (WebSocket, con `sticky` no necesario al ser un solo nodo).
- RB-3 TLS por Let's Encrypt con **HTTP-01 challenge** y `certresolver` del Traefik externo; redirección global de HTTP a HTTPS.
- RB-4 Middlewares de Traefik: `redirect-to-https`, `www-to-apex`, cabeceras de seguridad (`HSTS` con 1 año, `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`) y compresión.
- RB-5 `TrustProxies` de Laravel confía **solo** en la subred de `traefik_proxy`, para que `X-Forwarded-For` sea fiable (`REQ-IDN-005` SP-3) y las URLs se generen en `https`.
- RB-6 En `local`, Traefik publica `apartar.localhost` **sin TLS** y `ws.apartar.localhost`; no se emiten certificados en desarrollo.
- RB-7 El servicio `nginx` declara explícitamente su puerto interno en las etiquetas (`loadbalancer.server.port=80`) para no depender de la inferencia.
- RB-8 Las rutas de archivos privados (`REQ-PAY-002`) pasan por la aplicación, nunca por `nginx` como estático.

**Happy path**
1. Se levanta el stack con las etiquetas correctas.
2. Traefik descubre `nginx`, solicita el certificado por HTTP-01 y publica `https://apartar.store`.
3. `wss://ws.apartar.store` queda operativo para el chat.

**Sad paths y edge cases**
- SP-1 DNS no apuntando al servidor → el challenge falla; Traefik sirve su certificado por defecto y el sitio da aviso de TLS. Se documenta la comprobación de DNS previa.
- SP-2 Límite de emisión de Let's Encrypt alcanzado por reintentos → se documenta el uso del entorno de staging de ACME durante las pruebas.
- SP-3 `X-Forwarded-For` no confiado → las IPs registradas serían las de Traefik; RB-5 lo previene y hay test que lo verifica.
- SP-4 WebSocket cortado por un middleware de compresión mal aplicado → el router de `reverb` no aplica compresión.
- SP-5 Traefik externo reiniciado → el sitio queda inaccesible momentáneamente; los contenedores de la aplicación siguen sanos.
- SP-6 Cambio del nombre de la red externa en el servidor → el `up` falla con mensaje claro; el nombre es una variable documentada.
- SP-7 Peticiones directas al contenedor `nginx` saltándose Traefik → imposibles al no publicar puertos al host en producción.

**Fuera de alcance inmediato**
- DNS-01 challenge y certificados wildcard.
- WAF y protección DDoS.
- Traefik gestionado dentro de este compose.
- Balanceo entre varios nodos de aplicación.

**Gate de validación**
- Superficie esperada: etiquetas en `docker-compose.prod.yml`, `app/Http/Middleware/TrustProxies.php` (o `bootstrap/app.php` en Laravel 12), `docker/nginx.conf`.
- Tipos esperados: routers para apex, `www` y `ws`; los cuatro middlewares de RB-4.
- Anti-`SHALLOW`: la declaración de etiquetas y middlewares es configuración; el artefacto con lógica es la confianza de proxies y la generación de URLs `https`, verificada por test de feature.
- Tests: `url()` genera `https` detrás del proxy simulado; `request()->ip()` devuelve la IP del cliente y no la del proxy; redirección de `www` a apex; router de `reverb` sin compresión; etiquetas presentes en los dos servicios y en ningún otro.

---

### REQ-INF-003 · Colas priorizadas y tareas programadas

**Historia de usuario**
> Como responsable del negocio, quiero que una exportación grande no retrase las notificaciones de cobro, para que lo urgente salga primero.

**Contratos de datos**
- Colas por prioridad: `high` > `default` > `exports` > `telemetry` (`ASU-INF-02`).
- Servicio `queue`: `queue:work redis --queue=high,default,exports,telemetry --tries=3 --max-time=3600`.
- `schedule:work` en el mismo servicio.

**Reglas de negocio y precondiciones**
- RB-1 Asignación de colas:
  | Cola | Jobs |
  |---|---|
  | `high` | `FetchExchangeRate`, `ProcessSubscriptionDunning` |
  | `default` | notificaciones, `SendWebPush`, `DispatchCatalogDigests`, `TranscodeProductImage`, `CompressReceiptImage`, `NotifyUnreadMessage`, expiración y detección de mora, `ReconcileLayaways` (añadido en `T-064`: no figuraba; es integridad de apartados, como las dos anteriores) |
  | `exports` | `GenerateExport`, `PurgeExpiredExports` |
  | `telemetry` | `ProcessTelemetryBatch`, `LinkVisitorEventsToCustomer`, `LinkVisitorEventsToAccount`, `PurgeTelemetry`, `RecalculateUsage` |
- RB-1b **Todo job que toque una tabla del tenant recibe la organización como parámetro explícito** y la fija con `TenantContext::setOrganization()` al arrancar, limpiándola en el `finally` (`REQ-ORG-002` RB-5 y SP-7). Un job sin tenant fijado que toque una tabla del tenant **debe fallar**, no operar sin filtro. Los jobs que recorren varias organizaciones (`ExpireLayawaysJob`, `DetectOverdueLayawaysJob`, `DispatchReminders`, `ReconcileLayaways`, `ProcessTelemetryBatch`, `RecalculateUsage`, `DispatchCatalogDigests`) usan `withoutTenantScope()` para enumerar y fijan el tenant **por elemento o por lote**, nunca uno para toda la ejecución.
- RB-2 El worker consume en orden estricto de prioridad: mientras haya trabajos en `high`, no toca `default`.
- RB-3 `--max-time=3600` recicla el proceso cada hora para evitar fugas de memoria; `restart: unless-stopped` lo relanza.
- RB-4 `tries = 3` con backoff exponencial por defecto; los jobs que agotan reintentos van a `failed_jobs` y **nunca** bloquean la cola.
- RB-5 Tareas programadas y su horario. El scheduler corre en **UTC** y cada comando resuelve la hora local **de cada organización** cuando su semántica lo exige (`REQ-ORG-006` RB-7): los marcados con ⏰ se ejecutan cada hora y actúan solo sobre las organizaciones cuya hora local coincide con la programada.
  | Comando | Frecuencia | Zona |
  |---|---|---|
  | `FetchExchangeRate` | cada 30 min | plataforma |
  | `layaways:expire` | cada hora | por organización |
  | `layaways:detect-overdue` | ⏰ 06:00 | por organización |
  | `notifications:dispatch-reminders` | ⏰ 08:00 | por organización |
  | `catalogs:dispatch-digests` | ⏰ 10:00 | por organización |
  | `layaways:reconcile` | diario 05:30 | plataforma |
  | `subscriptions:process-dunning` | diario 07:00 | plataforma |
  | `subscriptions:issue-renewals` | diario 07:15 | plataforma |
  | `subscriptions:apply-downgrades` | diario 07:30 | plataforma |
  | `subscriptions:recalculate-usage` | diario 02:00 | plataforma |
  | `telemetry:purge` | diario 03:00 | plataforma |
  | `exports:purge` | diario 04:00 | plataforma |
  | `push:purge-stale` | semanal, lunes 04:30 | plataforma |
  | `chat:recount` | semanal, lunes 05:00 | plataforma |
- RB-5b Los comandos ⏰ son el precio de ser multi-tenant con organizaciones en husos distintos: programar un único envío diario a una hora fija enviaría de madrugada a parte de los clientes. Ejecutarlos cada hora y filtrar por hora local es la solución simple, y su idempotencia (RB-6) garantiza que no dupliquen.
- RB-6 Todo comando programado usa `withoutOverlapping()` y `onOneServer()` donde aplique, y es **idempotente** por diseño (requisito repetido en cada módulo).
- RB-7 Los jobs que dependen de una transacción usan `afterCommit` (`REQ-NOT-003` RB-3).
- RB-8 Un solo servicio `queue` ejecuta worker y scheduler; escalar a más réplicas exige revisar `onOneServer()` y se documenta como extensión.

**Happy path**
1. Se encolan 20 jobs de exportación y 3 notificaciones.
2. El worker procesa primero las notificaciones (`default`) y después las exportaciones (`exports`).
3. A las 08:00 el scheduler despacha los recordatorios del día.

**Sad paths y edge cases**
- SP-1 Job que falla 3 veces → `failed_jobs`; el resto de la cola sigue.
- SP-2 Exportación de una hora bloqueando el worker → al ser un único worker, sí bloquea las colas inferiores mientras corre; **se acepta** en v1 y se documenta que la mitigación es una réplica dedicada a `exports`.
- SP-3 Worker muerto sin reiniciar → los jobs se acumulan; se detecta por el retraso visible en la UI (exportaciones en `queued`, `REQ-BOF-043` SP-8).
- SP-4 Redis reiniciado con jobs en vuelo → los jobs no confirmados se pierden; los idempotentes se recuperan en el siguiente ciclo programado, que es la razón de RB-6.
- SP-5 Dos contenedores `queue` por un escalado accidental → `withoutOverlapping` evita el solapamiento de los comandos programados; los jobs sí se reparten (deseable).
- SP-6 Reloj del contenedor en UTC → el scheduler usa la zona configurada en la aplicación, no la del sistema.

**Fuera de alcance inmediato**
- Laravel Horizon y su panel.
- Réplicas dedicadas por cola.
- Alertas automáticas por `failed_jobs`.
- Reintento de jobs fallidos desde la UI.

**Gate de validación**
- Superficie esperada: `routes/console.php`, `config/queue.php`, `app/Jobs/Concerns/RunsInTenantContext.php`, la declaración de `$queue` en cada job, `docker-compose.yml` (comando del servicio `queue`).
- Tipos esperados: los cuatro nombres de cola declarados; los catorce comandos programados; `RunsInTenantContext`.
- Anti-`SHALLOW`: la definición del schedule (catorce entradas con frecuencia, filtro de hora local y `withoutOverlapping`) y `RunsInTenantContext` (fijación y limpieza del tenant con `finally`, fallo si falta) superan 4 líneas en sus archivos.
- Tests: cada job se encola en la cola esperada (un test por job); el schedule registra los catorce comandos con su frecuencia; los comandos ⏰ actúan solo sobre las organizaciones cuya hora local coincide (test con dos zonas); `withoutOverlapping` presente; `tries = 3`; jobs transaccionales con `afterCommit`; **un job sin tenant fijado que toca una tabla del tenant falla** y **el tenant se limpia entre ejecuciones** (RB-1b, tests de fuga).

---

### REQ-INF-004 · Configuración de entornos y drivers

**Historia de usuario**
> Como desarrollador, quiero que el entorno local se comporte como producción en lo esencial, para no descubrir problemas solo al desplegar.

**Contratos de datos**
- `.env.example` completo y documentado.
- `config/*.php` con los drivers de `ASU-INF-04`.

**Reglas de negocio y precondiciones**
- RB-1 Drivers en todos los entornos: `cache=redis`, `session=redis`, `queue=redis`, `broadcast=reverb`, `filesystem=local` (privado). **No** se usa el driver `database` para colas ni el `file` para caché, ni en local (evita divergencias de comportamiento).
- RB-2 `APP_TIMEZONE=America/Caracas`, `APP_LOCALE=es`, `APP_FALLBACK_LOCALE=es`. Toda fecha se persiste en UTC y se presenta en la zona local (`REQ-LAY-007` SP-6).
- RB-3 Entornos: `local` (`http://localhost:8080`, `APP_DEBUG=true`, Vite HMR, **sin Traefik** — `REQ-INF-001` RB-0) y `production` (`apartar.store`, `APP_DEBUG=false`, `optimize` aplicado). Sin staging (`ASU-INF-03`).
- RB-3b El host local es `localhost` y **no** `apartar.localhost`: al ser las URL públicas por ruta (`/c/{slug}`, `/{org-slug}`, decisión `Q4`) no hace falta DNS comodín ni proxy en desarrollo. `ASU-INF-03` y `REQ-INF-002` RB-6 conservan el nombre de dominio porque describen el escenario **diferido** con Traefik.
- RB-4 En `production` es obligatorio: `APP_DEBUG=false`, `APP_KEY` presente, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`. Un arranque con `APP_DEBUG=true` en producción se **bloquea** en el `entrypoint`.
- RB-5 Variables obligatorias documentadas en `.env.example`, agrupadas por dominio (app, BD, Redis, Reverb, VAPID, OAuth Google, tasa de cambio, correo, superadmin).
- RB-6 `config:cache`, `route:cache`, `view:cache` y `event:cache` se aplican en producción vía `optimize`; en local no se cachea la configuración (evita el clásico "cambié el `.env` y no toma efecto").
- RB-7 Vite en local sirve por HMR desde el contenedor `node` con el host correcto para que funcione desde el navegador del host.
- RB-8 Ninguna clave, contraseña o secreto se versiona; `.env` está en `.gitignore` y `.env.example` solo contiene valores de ejemplo evidentes.

**Happy path**
1. El desarrollador copia `.env.example` a `.env`, ajusta credenciales y levanta el compose de desarrollo.
2. Vite sirve con HMR; la aplicación usa Redis para caché, sesiones y colas, igual que en producción.
3. En producción, `optimize` cachea todo y `APP_DEBUG` está en `false`.

**Sad paths y edge cases**
- SP-1 `APP_KEY` ausente → el `entrypoint` falla con instrucción de `key:generate`.
- SP-2 `APP_DEBUG=true` en producción → arranque bloqueado (RB-4).
- SP-3 Redis caído → sesiones y caché fallan; la aplicación devuelve `503`. Se documenta como dependencia dura (a diferencia de los ajustes, que sí tienen fallback a BD, `REQ-ORG-006` SP-7).
- SP-4 `config:cache` ejecutado en local → cambios en `.env` ignorados; el runbook lo advierte y el `Makefile` no lo incluye en el flujo de desarrollo.
- SP-5 HMR inaccesible desde el navegador del host → `VITE_HOST` y `server.host` documentados con el valor correcto.
- SP-6 Zona horaria mal configurada → todos los cálculos de vencimiento se desplazan; hay test que verifica la zona efectiva de la aplicación.
- SP-7 Falta una variable de `.env.example` usada en `config/` → detectado por el test de completitud de `REQ-INF-001`.

**Fuera de alcance inmediato**
- Gestor de secretos (Vault, SSM).
- Entorno de staging.
- Feature flags por entorno.

**Gate de validación**
- Superficie esperada: `.env.example`, `config/cache.php`, `config/session.php`, `config/queue.php`, `config/broadcasting.php`, `config/filesystems.php`, `config/app.php`, `docker/entrypoint.sh`, `vite.config.ts`.
- Tipos esperados: los cinco drivers de RB-1; zona y locale de RB-2.
- Anti-`SHALLOW`: las guardas del `entrypoint` (RB-4) concentran la lógica; el resto es configuración verificada por tests.
- Tests: los cinco drivers resuelven a lo esperado; zona horaria efectiva es `America/Caracas`; `APP_DEBUG=true` en producción aborta; `.env.example` cubre todas las variables referenciadas en `config/`; cookies seguras en producción.

---

### REQ-INF-005 · Reverb como servicio de WebSocket

**Historia de usuario**
> Como responsable del despliegue, quiero el WebSocket como un servicio propio y aislado, para poder reiniciarlo sin afectar al sitio web.

**Contratos de datos**
- Servicio `reverb` (misma imagen que `app`, comando `php artisan reverb:start --host=0.0.0.0 --port=8080`).
- `config/reverb.php`, `config/broadcasting.php` con conexión `reverb`.
- Variables `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_SCHEME`.

**Reglas de negocio y precondiciones**
- RB-1 `reverb` corre en su propio contenedor y se expone por Traefik en `ws.apartar.store` (`REQ-INF-002` RB-2).
- RB-2 La aplicación difunde eventos hacia Reverb usando el host **interno** (`reverb:8080`), no el público, para no salir y volver a entrar por Traefik.
- RB-3 El cliente conecta al host **público** con `wss` en producción y `ws` en local.
- RB-4 Reiniciar `reverb` **no** afecta a `nginx` ni a `app`; los clientes caen a polling durante la interrupción (`REQ-CHT-003` RB-3).
- RB-5 Reverb usa Redis como backend de escalado horizontal, pero en v1 hay **un solo nodo** (`ASU-INF-02`).
- RB-6 Si las variables de Reverb están ausentes, el broadcast se degrada a `log` y el chat funciona en polling sin errores (`REQ-CHT-003` RB-7).
- RB-7 Autorización de canales por `routes/channels.php` con los dos guards (`REQ-CHT-003` RB-1); el endpoint de autorización es de la aplicación, no de Reverb.
- RB-8 El servicio declara healthcheck sobre su puerto y límite de memoria.

**Happy path**
1. `reverb` arranca y Traefik publica `wss://ws.apartar.store`.
2. La aplicación difunde `MessageSent` hacia `reverb:8080` por la red interna.
3. El navegador del cliente lo recibe por `wss` y actualiza el chat.

**Sad paths y edge cases**
- SP-1 `reverb` caído → el broadcast falla; el evento no se pierde en su efecto (el mensaje está en BD) y los clientes caen a polling.
- SP-2 Claves de Reverb desincronizadas entre `app` y `reverb` → la autenticación de canal falla; test de humo del despliegue lo detecta.
- SP-3 Cliente detrás de un proxy que bloquea WebSocket → fallback (`REQ-CHT-003` SP-1).
- SP-4 Muchas conexiones simultáneas en un solo nodo → límite documentado; escalar exige más nodos y Redis como backend (RB-5).
- SP-5 Traefik aplicando compresión al router de `reverb` → prohibido por `REQ-INF-002` SP-4.
- SP-6 `REVERB_HOST` público usado por el servidor para difundir → latencia y posible fallo de TLS interno; RB-2 lo previene.

**Fuera de alcance inmediato**
- Escalado horizontal con varios nodos de Reverb.
- Presencia y canales de presencia.
- Métricas de conexiones.

**Gate de validación**
- Superficie esperada: servicio `reverb` en el compose, `config/reverb.php`, `config/broadcasting.php`, `routes/channels.php`, `resources/js/lib/echo.ts`.
- Tipos esperados: conexión `reverb` declarada; las cinco variables de entorno; healthcheck del servicio.
- Anti-`SHALLOW`: la autorización de canales de `routes/channels.php` (dos guards, tres canales) es el artefacto con lógica del módulo.
- Tests: el broadcast usa el host interno y el cliente el público; sin variables el driver cae a `log` sin excepción; autorización de los tres canales (`conversation.{id}`, `backoffice.receipts`, `backoffice.chat`) con los guards correctos.

---

### REQ-INF-006 · Build, despliegue y migraciones

**Historia de usuario**
> Como responsable del despliegue, quiero un procedimiento repetible para publicar cambios, para desplegar sin improvisar y poder volver atrás si algo sale mal.

**Contratos de datos**
- `Dockerfile` multi-stage: `vendor` (composer), `assets` (node + `vite build`), `runtime` (php-fpm + artefactos).
- `docker/entrypoint.sh`, `Makefile` con los comandos del runbook.
- `docs/runbook-despliegue.md` (artefacto derivado de este requisito).

**Reglas de negocio y precondiciones**
- RB-1 Build multi-stage: las dependencias de desarrollo (composer `--no-dev` en runtime, `node_modules`) **no** llegan a la imagen final.
- RB-2 Los assets se compilan **en el build**, no en el arranque: la imagen es autocontenida y el despliegue no depende de `npm` en el servidor.
- RB-3 Despliegue manual (`ASU-INF-06`): `build` → `up -d` → `migrate --force` → `optimize`. Sin pipeline de CD automático en v1.
- RB-4 Las migraciones se ejecutan **una sola vez** por despliegue, desde el servicio `app` (`REQ-INF-001` RB-7); nunca desde `queue` ni `reverb`.
- RB-5 Toda migración debe ser **compatible hacia atrás** dentro de un mismo despliegue (añadir columnas nullable antes de usarlas; no renombrar ni borrar columnas en el mismo paso que el código que las deja de usar), para que un rollback de imagen no rompa el esquema.
- RB-6 El rollback es de **imagen**, no de esquema: se documenta que las migraciones no se revierten automáticamente y que `migrate:rollback` es una operación manual y evaluada caso por caso.
- RB-7 `storage/` vive en el volumen `app_storage`, nunca en la imagen; `php artisan storage:link` se ejecuta en el `entrypoint`.
- RB-8 El runbook incluye: comprobación previa de DNS, backup del volumen y de la BD, comandos de despliegue, verificación posterior (humo: home, PDP, login del backoffice, WebSocket, un job encolado) y procedimiento de rollback.
- RB-9 La imagen se etiqueta con el hash corto del commit para poder volver a una versión concreta.

**Happy path**
1. Se hace `make build` con el commit etiquetado.
2. `make deploy` levanta los servicios, migra y aplica `optimize`.
3. Se ejecuta la verificación de humo del runbook y todo responde.

**Sad paths y edge cases**
- SP-1 Migración que falla a mitad → `app` no sirve; se restaura el backup de BD y se vuelve a la imagen anterior según el runbook.
- SP-2 Build de assets fallido → el build se detiene y **no** se produce imagen; producción sigue con la anterior.
- SP-3 Despliegue con migración destructiva y rollback de imagen → esquema incompatible; RB-5 es la regla que lo previene.
- SP-4 `storage:link` ejecutado sobre un enlace existente → idempotente, no falla.
- SP-5 Despliegue concurrente por dos personas → se documenta que el despliegue es una operación exclusiva; no hay bloqueo técnico en v1.
- SP-6 Volumen de storage sin backup antes de una operación destructiva → pérdida de comprobantes; el runbook lo marca como paso obligatorio (`REQ-INF-001` SP-5).
- SP-7 Caché de configuración obsoleta tras cambiar `.env` en producción → `optimize` forma parte del despliegue (RB-3) y el runbook lo exige tras cualquier cambio de entorno.

**Fuera de alcance inmediato**
- CD automático desde `main`.
- Despliegue sin interrupción (blue-green, rolling).
- Backups automatizados y verificación de restauración.
- Registro de imágenes privado.

**Gate de validación**
- Superficie esperada: `Dockerfile`, `Makefile`, `docker/entrypoint.sh`, `docs/runbook-despliegue.md`.
- Tipos esperados: los tres stages del `Dockerfile`; los comandos del `Makefile` del runbook.
- Anti-`SHALLOW`: `docker/entrypoint.sh` (compartido con `REQ-INF-001`) y el `Dockerfile` multi-stage concentran la lógica del módulo.
- Tests: la imagen final no contiene `node_modules` ni dependencias de desarrollo de composer; los assets están compilados en la imagen; `storage:link` es idempotente; la verificación de humo del runbook existe y es ejecutable; revisión de que ninguna migración del repositorio borra o renombra columnas en uso (comprobación en revisión de PR, documentada en `REQ-QAX-006`).
