# PROMPT 01 — `T-001` · Proyecto Laravel + starter kit react

> Hito **H0 · Cimientos** · Tarea `T-001` · Fase `AB` · 2 pts
> Primera tarea del plan. No hay código previo en el repositorio: solo especificaciones.

## 0. Qué es esto

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 87 tareas.

Tu trabajo en este prompt es **únicamente el andamiaje del proyecto**. No implementas ninguna funcionalidad de negocio.

## 1. Lee primero, en este orden

| Documento | Para qué |
|---|---|
| [docs/specs/00-INDEX.md](../specs/00-INDEX.md) | mapa general y cómo se lee la spec |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§3** | versiones fijadas y la lista **exacta** de paquetes Composer (§3.2) y npm (§3.3) |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) | busca `T-001`; ahí está el "hecho cuando" canónico, y lee la sección "Cómo leer este plan" |
| [docs/specs/02-spec-consolidada.md](../specs/02-spec-consolidada.md) **§6** | el árbol de carpetas objetivo — para que sepas a dónde vamos; **no lo crees todavía** |

No necesitas leer los 19 módulos de requisitos para esta tarea.

## 2. Fuente de verdad para la instalación

> I'm building a new Laravel application.
>
> Fetch and follow the instructions from https://laravel.com/for/agents. Treat the returned Markdown as the source of truth for how to install and set up Laravel in this session.

Ese documento manda sobre *cómo* instalar. La spec manda sobre *qué versiones y paquetes*. Si se contradicen, la spec gana en versiones/paquetes y lo reportas.

## 3. Restricciones reales del entorno (verificadas en esta máquina)

Host: **PHP 8.3.29**, Composer 2.10.1, Node 26.3.0, npm 11.17.0, Docker 29.5.2. Windows 11.

Tres cosas que te van a morder si no las sabes de antemano:

1. **El instalador global `laravel` no funciona aquí.** Exige PHP >= 8.4 y aborta con un fatal error de `platform_check`. La spec fija **PHP 8.3** (§3.1) y esa decisión no se cambia para complacer al instalador. Usa la vía de Composer (`composer create-project laravel/react-starter-kit`) o la que las instrucciones oficiales indiquen para este caso. Lo que **no** debes hacer es subir la versión de PHP del proyecto.

2. **El directorio de destino no está vacío.** La raíz ya contiene `.git/`, `docs/`, `.claude/`, `.agents/`, `.mcp.json` y `skills-lock.json`, y `create-project` exige destino vacío. Crea el proyecto en un directorio temporal aparte y **mueve su contenido a la raíz sin tocar lo que ya existe**. El `.gitignore` del starter kit se **fusiona**, no sobreescribe.

3. **Docker no se toca en esta tarea** (es `T-002`). Instala en el host. El contenedor usará Node 22 LTS mientras el host tiene Node 26; `package-lock.json` v3 es compatible con ambos, así que genera el lockfile aquí y no lo regeneres después.

## 4. Alcance

**Sí:**

1. Proyecto **Laravel 12** con el starter kit **`react`** (Inertia 2 + React 19 + TypeScript + Vite + Tailwind 4).
2. **daisyUI 5**. Con Tailwind 4 se registra **desde el CSS** (`@plugin "daisyui";`); no hay `tailwind.config.js` que editar.
3. Los paquetes de **§3.2** y **§3.3**, exactamente esos: ni uno menos, ni uno de más. Respeta también la lista de **paquetes vetados** al final de §3.2 (`spatie/laravel-permission`, `laravel/horizon`, `laravel/cashier`, librerías monetarias) — están vetados por decisiones de diseño, no por olvido.
4. Publica los archivos de configuración/migraciones que cada paquete requiera publicar, pero **no configures nada todavía**.
5. `declare(strict_types=1)` en todo archivo PHP que tú crees (norma `REQ-QAX-001`; su gate automático se instala en `T-003`).
6. Commit inicial con el árbol del proyecto.

**No:**

- **No crees** el árbol de `app/Domain`, `app/Actions`, `app/Support`, etc. Cada carpeta nace cuando su tarea la necesita.
- **No crees** migraciones nuestras (el bloque 1 es `T-004`) ni borres las de Laravel: la de `users` se **sustituye** en `T-008`.
- **No borres el andamiaje de autenticación del starter kit.** `T-010` (registro de organización) y `T-012` (login sin selector de organización) lo **adaptan** y reutilizan su recuperación de contraseña y su rate limiting. Déjalo intacto aunque parezca que sobra.
- **No toques** `.env` más allá de lo que genere el instalador. El `.env.example` completo es `T-002`.
- **No escribas tests todavía.** `T-003` monta la infraestructura de pruebas (Pest, PHPStan, ESLint, Vitest, CI).

## 5. Hecho cuando

- [ ] `php artisan --version` reporta Laravel 12.x.
- [ ] `npm run build` termina sin errores.
- [ ] `composer.json` y `package.json` coinciden con §3.2 y §3.3, sin faltantes ni extras, y sin ningún paquete vetado.
- [ ] daisyUI 5 resuelve: una clase daisyUI (p. ej. `btn btn-primary`) se compila en el build.
- [ ] `docs/`, `.claude/`, `.agents/`, `.mcp.json` y `skills-lock.json` intactos.
- [ ] Un commit limpio; nada de `vendor/` ni `node_modules/` versionado.

## 6. Reporta al terminar

Esto no es opcional: el siguiente prompt (`T-002`) depende de saberlo.

1. Versión exacta de Laravel y del starter kit, y **qué trajo de autenticación**: paquetes (¿Fortify?), rutas, páginas React y migraciones.
2. Cualquier paquete de §3.2/§3.3 que no se haya podido instalar en PHP 8.3, con el motivo.
3. Cualquier desviación entre lo que dice `laravel.com/for/agents` y lo que dice §3 de la spec.
4. Nada más. No propongas siguientes pasos: el plan ya los tiene.
