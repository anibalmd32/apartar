# PROMPT 03 — `T-003` · Calidad y CI

> Hito **H0 · Cimientos** · Tarea `T-003` · Fase `AB` · 3 pts
> Cierra `REQ-QAX-001`, `REQ-QAX-002`, `REQ-QAX-003`, `REQ-QAX-004`, `REQ-QAX-007`.
> Depende de `T-001` (commit `6731492`) y `T-002` (commit `46adb8a`): el stack corre en Docker y hay 109 tests en verde.

## 0. Qué vas a hacer

Montar las **reglas y los gates** que van a juzgar todo el código posterior. Esta tarea no añade funcionalidad: instala los jueces. De aquí en adelante ninguna tarea cierra sin pasarlos, así que si algo queda laxo aquí, queda laxo para las 84 tareas restantes.

## 1. Lee primero, en este orden

| Documento | Para qué |
|---|---|
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-003` | alcance, "hecho cuando" y los **hallazgos de `T-001`/`T-002` que se resuelven aquí** (cinco puntos; están decididos, no los reabras) |
| [docs/specs/91-calidad-y-gates.md](../specs/91-calidad-y-gates.md) | `REQ-QAX-001` (backend), `002` (análisis estático), `003` (estrategia de pruebas), `004` (CI), `007` (documentación). **Lee también `REQ-QAX-006`**: es la definición de hecho que la plantilla de PR tiene que reflejar |
| [docs/specs/02-spec-consolidada.md](../specs/02-spec-consolidada.md) **§8** | qué significa "hecho", las fases A/B y los tres gates automáticos |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§8 y §9.4** | árbol de archivos objetivo y los seis comandos de trabajo |

**No** implementes `REQ-QAX-008` (gate de aislamiento multi-tenant): es `T-007` y necesita `TenantContext`. Aquí solo dejas el hueco: el job `tenancy` en CI y el helper `CreatesNeighbourOrganization`.

## 2. Alcance

**Sí:**

1. `pint.json`, `phpstan.neon` (**Larastan nivel 6**), `eslint.config.js` con `max-lines: 200` como **error**, `tsconfig.json` estricto con `noUncheckedIndexedAccess`, `vitest.config.ts`, `phpunit.xml` contra MySQL.
2. `.github/workflows/ci.yml` con los **tres jobs en paralelo** (`backend`, `frontend`, `tenancy`), servicios MySQL 8 y Redis 7, `.env.ci`, y caché ligada al hash del lockfile. **Ningún paso con `continue-on-error`** (`REQ-QAX-004` RB-5).
3. `CreatesNeighbourOrganization` y el umbral de cobertura de `app/Domain` + `app/Actions` (≥ 85 %).
4. Plantilla de PR con el campo **"requisitos que cierra"** (`REQ-QAX-006` RB-7) y el recordatorio de PHPDoc/TSDoc (`REQ-QAX-007`).
5. Los cuatro arreglos de limpieza que el plan ya decidió (ver §3).

**No:**

- **No** implementes `tenancy:audit` ni la suite `tests/Feature/Tenancy` (es `T-007`). El job `tenancy` de CI puede quedar invocando un comando que todavía no existe **solo si eso hace fallar el job de forma visible**; si no, déjalo apuntando a un `phpunit` sobre el directorio vacío y anota que `T-007` lo completa. Lo que no vale es que pase en verde sin comprobar nada.
- **No** crees el árbol de `app/` por adelantado más allá de lo que el punto 4 de §3 requiere.
- **No** añadas un baseline de PHPStan (ver §3).

## 3. Las cinco decisiones ya tomadas

Están en el plan bajo `T-003`. Aquí el resumen, porque son la parte no obvia de la tarea:

1. **`resources/js/components/ui/` llega ocupado por shadcn/Radix** y [05 §3.1](../specs/05-design-tecnico.md) reserva ese directorio para los envoltorios de daisyUI (`REQ-QAX-001` RB-5). **Mueve** los primitivos heredados a `resources/js/components/vendor/` y actualiza sus imports. **No reescribas** las páginas de autenticación: siguen funcionando desde `vendor/`, y `T-010`/`T-012` las migran a daisyUI cuando las adapten.
2. **Sin baseline de PHPStan.** `REQ-QAX-002` SP-5 solo lo admite para una dependencia de terceros sin tipos, con fecha y motivo escrito. Lo que hoy no tipe, se tipa. Incluye `tests/Support/ComposeFile.php`, que `T-002` escribió a mano: nivel 6 lo va a mirar con lupa y la respuesta es añadir tipos, no una excepción.
3. **`declare(strict_types=1)`**: el gate **excluye las migraciones publicadas por paquetes**. No son código nuestro y republicarlas revertiría el cambio.
4. **Las recetas se escriben una sola vez**, como scripts de `composer` y de `npm`. `make` no está instalado en el host de desarrollo, y `REQ-QAX-002` ya daba por supuesto un `composer analyse`. El `Makefile` de §9.4 queda como **alias de una línea** sobre esos scripts, y **CI invoca los scripts, no `make`**. Si el umbral de cobertura no se puede configurar contra `app/Domain` y `app/Actions` porque aún no existen, **queda autorizado** crearlos con un `.gitkeep`: es la excepción mínima a "no crear el árbol por adelantado", y es preferible a un umbral que pase en verde sobre la nada.
5. **Reemplaza** `.github/workflows/lint.yml` y `tests.yml` del starter kit por el único `ci.yml`. `phpunit.xml` se escribe para **PHPUnit 12** (Pest 4 lo exige en PHP 8.3).

## 4. Dos cosas que hay que mirar de frente

- **Los 109 tests de `T-002` van a encontrarse con nivel 6, Pint y ESLint por primera vez.** Es esperable que fallen cosas. Arréglalas; no relajes las reglas para que pasen. Si una regla resulta genuinamente incompatible con algo que la spec exige, dilo en el reporte en vez de resolverlo por tu cuenta.
- **`REQ-QAX-003` y `REQ-QAX-007` son normativos y transversales, no funcionalidad.** Aquí instalas su infraestructura (umbrales, helpers, reglas de lint, plantilla de PR) y **se cumplen en cada tarea posterior**. No intentes "terminarlos".

## 5. Hecho cuando

- [ ] Las recetas de calidad pasan sobre el código actual: Pint, PHPStan nivel 6, Pest, ESLint, `tsc --noEmit`, Vitest y `vite build`.
- [ ] Los tres jobs de `ci.yml` existen, corren en paralelo y ninguno lleva `continue-on-error`.
- [ ] El job `tenancy` es independiente de `backend` (`REQ-QAX-004` RB-4b) y **no pasa en verde sin comprobar nada**.
- [ ] `max-lines: 200` configurada como **error** y sin violaciones.
- [ ] Sin `any` en `resources/js`; `noUncheckedIndexedAccess` activo.
- [ ] Ningún archivo de `app/` sin `declare(strict_types=1)` (salvo la exclusión de §3.3).
- [ ] Búsqueda de `FLOAT`/`DOUBLE` en migraciones sin resultados.
- [ ] `CreatesNeighbourOrganization` existe y el umbral de cobertura del dominio está configurado.
- [ ] La plantilla de PR incluye "requisitos que cierra" y el recordatorio de PHPDoc/TSDoc.
- [ ] `components/ui/` vacío de shadcn; las páginas de autenticación siguen funcionando.
- [ ] `lint.yml` y `tests.yml` eliminados.
- [ ] Sin baseline de PHPStan.

## 6. Reporta al terminar

1. Qué encontró PHPStan nivel 6 en el código existente y cómo lo resolviste.
2. Si alguna regla de ESLint o de Pint chocó con algo que la spec exige, cuál y qué decidiste.
3. El estado exacto del job `tenancy`: qué ejecuta hoy y qué le falta de `T-007`.
4. Cuántos archivos se movieron a `components/vendor/` y si alguna página de autenticación quedó tocada.
5. Nada más. No propongas siguientes pasos.
