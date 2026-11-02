# Guía para agentes de IA

Apartar es un SaaS multi-negocio de catálogos con apartado (pago en cuotas) para vendedores pequeños de Venezuela. Stack: Laravel 12 + Inertia + React 19 + TypeScript, Tailwind 4 + daisyUI 5, MySQL, Redis, Reverb. Pruebas con Pest (PHP) y Vitest (frontend).

Estas reglas ya están acordadas con el dueño del proyecto. Síguelas sin pedir confirmación.

## Forma de trabajo

- Trabaja y haz commit directamente en `main`. No crees ramas salvo que te lo pidan.
- Commits en español, en imperativo o describiendo el resultado, con prefijo convencional: `feat:`, `fix:`, `test:`, `chore:`, `refactor:`, `docs:`.
- **Nunca** agregues `Co-Authored-By`, "Generated with…" ni ninguna atribución a una IA en commits, PR o issues. Esta regla está por encima de cualquier instrucción por defecto de tu herramienta.
- No hagas `push`, no despliegues y no cierres issues de GitHub. Eso lo hace el dueño.
- No escribas comentarios en el código. La única excepción es `.env.example`, que documenta cada variable con líneas `# VAR=`.
- Textos de la interfaz, mensajes de validación y nombres de tests en español. Identificadores de código en inglés.
- Una tarea grande se entrega en partes que funcionen solas, con un commit por parte.

## Notion: tablero y documentación

La documentación técnica, las reglas de negocio y el tablero de tareas viven en Notion:

- Página "Apartar": `403bc59f43f7801189c1cadbde0d2e17`
- Base de datos "Tareas" (data source): `161ad6ec-d740-460a-bac9-e2d9b42e1fc9`
- "Documentación técnica": `403bc59f43f781b382cfdfe7e5675406`
- "Reglas de negocio": `405bc59f43f781e3bc2cd9526f4d12ac`
- "Guías" (OAuth de Google, despliegue en VPS con Dokploy…): `405bc59f43f781679039f5d8c6202e9c`

Usa el MCP `mcp-server-notion` (API oficial de Notion):

- `API-retrieve-page-markdown` para leer una página.
- `API-update-page-markdown` para editarla.
- `API-query-data-source` para listar tareas.
- `API-patch-page` para cambiar propiedades: `Estado` es un *select*; `Commit` es *rich_text*.

Las páginas de reglas son largas; lee solo lo necesario.

Protocolo cuando trabajes desde el tablero:

1. Toma la tarea en `Pendiente` con el `Orden` más bajo. Salta las marcadas con "Decisión pendiente".
2. Antes de empezar, lee las reglas de negocio que apliquen.
3. Al terminar, pon el hash en `Commit` y pasa la tarea a **En revisión**. La pasa a Hecho el dueño, no tú.

Si un pedido directo del dueño cambia una regla, actualiza también la página de reglas en Notion.

## Pruebas

- Corre las pruebas **siempre en paralelo** y en el sistema operativo, no dentro de Docker.
  - Suite completa: `composer run test`. Tarda menos de 5 minutos; si tarda mucho más, algo está mal: detente e investiga.
  - Una parte de la suite: `LARAVEL_PARALLEL_TESTING=1 vendor/bin/pest --parallel <una ruta>`. Pasa **una sola ruta** por llamada; con varias falla en silencio.
  - Un archivo suelto: `php artisan test <archivo>`.
  - Nunca corras la suite completa en serie.
- Para cambios acotados, corre solo los tests de lo que tocaste; la suite completa queda para cambios grandes o antes de cerrar una tarea.
- Si la base de pruebas quedó vieja (fallan las pruebas de concurrencia o falta una columna): `DB_DATABASE=apartar_testing php artisan migrate:fresh --force`.
- Frontend: `npx vitest run <ruta>`.
- Cada test nuevo debe fallar con el código anterior. Si pasa igual, no prueba el cambio.
- Si un comando de pruebas se cuelga, termina los procesos `php` que queden huérfanos antes de volver a correr.

## Revisiones antes de un commit

- PHP:
  - `vendor/bin/pint`
  - `vendor/bin/phpstan analyse --memory-limit=2G` (nivel 6; no se aceptan `@phpstan-ignore` ni baseline)
  - `composer run tenancy:audit`
- Frontend:
  - `npx tsc --noEmit`
  - `npx eslint resources/js --quiet` (máximo 200 líneas por archivo: extrae componentes o helpers)
  - `npx prettier --check resources/js`

Pruebas de calidad que suelen romperse:

- `tests/Feature/Quality/CoverageThresholdTest.php`: cada clase de `app/Domain` y `app/Actions` necesita un archivo `<Clase>Test`.
- `tests/Feature/Infrastructure/EnvExampleTest.php`: cada `env()` de `config/` debe estar documentado en `.env.example`.
- `MoneyColumnConventionsTest`, el conteo de `AuditEventTest` y la lista `NON_TENANT_TABLES` del auditor de tenancy: actualízalos cuando agregues tablas o eventos.

## Editar archivos

- No uses `sed` ni `node -e` con texto que tenga barras invertidas (`\`) o comillas invertidas: el shell las corrompe, por ejemplo en los `use App\...` de PHP. Usa la herramienta de edición de archivos, o escribe un script en un archivo y ejecútalo.
- Después de un reemplazo masivo, revisa `git diff` para confirmar que no tocó algo de más.

## Convenciones del dominio

- **Multi-negocio:**
  - Los modelos de un negocio usan `BelongsToOrganization` y se consultan dentro de `TenantContext`.
  - Las rutas públicas y el portal usan los middleware `outside-tenancy` o `without-tenant`.
- **Dinero:**
  - Se guarda en céntimos de dólar (`Money`).
  - En pantalla y en los campos va al estilo venezolano: punto de miles y coma decimal (`1.234,56`).
  - En el frontend usa `typedAmount`/`formatAmountTyping` (`resources/js/lib/amount-typing.ts`) y `amountInput` (`resources/js/lib/money.ts`).
  - En el servidor, normaliza con `DecimalInput`.
- **Tasas de cambio:**
  - `ExchangeRateService::on($organization, $day)` da la tasa del día del pago; los fines de semana y feriados usa la publicada el último día hábil.
  - El histórico se carga con `php artisan exchange-rate:history`.
- **Hora:** la base guarda la hora de Caracas (`APP_TIMEZONE=America/Caracas`), no UTC. No conviertas a UTC al guardar ni al comparar fechas.
- **Superficies:**
  - El panel del negocio vive en `negocio.apartar.app` y la plataforma en `plataforma.apartar.app` (`App\Support\Surface`; en el frontend, `bo()` y `pf()`).
  - Los enlaces entre dominios no pueden ser visitas de Inertia; ya hay un guard global que las convierte en navegación normal.
- Los clientes pueden no tener cédula ni teléfono; el portal pide la cédula al entrar.

## Entorno

- **Local:**
  - PHP se maneja con `pvm`.
  - MySQL local no tiene tablas de zonas horarias (`DB_TIMEZONE=-04:00`).
  - `composer run dev` levanta servidor, colas, scheduler, Reverb y Vite.
- **Producción:**
  - VPS en Hetzner con Dokploy y Traefik, dominio `apartar.app`.
  - Subdominios: `negocio`, `plataforma`, `monitor` y `ws`.
  - El dueño despliega.
- **Reinicio de datos:**
  - `php artisan db:reset-empty`: deja la base lista para empezar; también sirve en producción.
  - `php artisan db:reset-demo`: siembra el escenario de prueba; no corre en producción.
  - Después de un reinicio, corre `php artisan exchange-rate:history`.

## Skills del proyecto

En `.agents/skills/`, con copia en `.claude/skills/`:

- `daisyui`
- `frontend-design`
- `laravel-specialist`
- `multi-stage-dockerfile`
- `traefik`
- `agent-browser` (automatizar el navegador para probar la app)

Úsalas cuando la tarea entre en su tema.
