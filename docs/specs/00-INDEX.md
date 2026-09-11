# Apartar — Índice de Especificaciones (SDD)

> Fuente de verdad de la especificación funcional y técnica de **Apartar**: plataforma SaaS multi-tenant donde un negocio publica catálogos compartibles con sistema de apartado y cobra en cuotas a sus propios clientes.
> Metodología: **Spec-Driven Development (SDD)**. Los gates se verifican con Pest, Vitest, PHPStan y `tenancy:audit` ([02 §8](02-spec-consolidada.md)); `indexion` fue descartado.
> Regla de oro: **ningún código de implementación se escribe antes de que el módulo de spec correspondiente esté aprobado.**

## Estado del flujo

| Fase | Artefacto | Estado | Fecha |
|------|-----------|--------|-------|
| PASO 0 | Prompt fundacional — [00-first-promt.md](../promts/00-first-promt.md) | ✅ Recibido | 2026-09-10 |
| PASO 1 | Asunciones críticas — [01-asunciones-criticas.md](01-asunciones-criticas.md) | ✅ Aprobada (62 asunciones) | 2026-09-10 |
| PASO 1-bis | **Pivote a SaaS** — [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md) | ✅ Aprobado (7 decisiones + capa de cuentas) | 2026-09-10 |
| PASO 2 | Especificación consolidada — [02-spec-consolidada.md](02-spec-consolidada.md) + 19 módulos | ✅ **Compilada (118 requisitos)** | 2026-09-10 |
| PASO 2b | Espejo de alineación — [.align/requirements.en.md](.align/requirements.en.md) | ✅ Generado | 2026-09-10 |
| PASO 3 | Diseño técnico — [05-design-tecnico.md](05-design-tecnico.md) | ✅ **Listo** (migraciones, colaboradores, rutas, hitos) | 2026-09-10 |
| PASO 4 | Plan de tareas — [06-plan-de-tareas.md](06-plan-de-tareas.md) | ✅ **Listo** (87 tareas, 10 hitos) | 2026-09-10 |
| PASO 5 | **Implementación** — empezar por `T-001` | ⏳ Siguiente | — |

## Mapa de módulos

Orden de lectura recomendado: fundamentos → cliente final → negocio → plataforma → transversales.

| Archivo | Módulo | IDs | N |
|---|---|---|---|
| [02-spec-consolidada.md](02-spec-consolidada.md) | **Spec maestra**: alcance, actores, glosario, modelo de datos, arquitectura, gates, ajustes | — | — |
| [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md) | Tenancy, organizaciones y equipo | `REQ-ORG-001…008` | 8 |
| [50-planes-y-suscripciones.md](50-planes-y-suscripciones.md) | Planes, límites y facturación | `REQ-SUB-001…008` | 8 |
| [10-identidad-y-autenticacion.md](10-identidad-y-autenticacion.md) | Identidad comercial del cliente | `REQ-IDN-001…008` | 8 |
| [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md) | Cuentas de consumidor, suscripción y feed | `REQ-ACC-001…007` | 7 |
| [11-catalogo-y-productos.md](11-catalogo-y-productos.md) | Productos, taxonomía y ficha técnica | `REQ-CAT-002…007` | 6 |
| [12-catalogos-curados.md](12-catalogos-curados.md) | El catálogo como tienda | `REQ-CAT-021…023` | 3 |
| [13-bimoneda-y-tasa-de-cambio.md](13-bimoneda-y-tasa-de-cambio.md) | Bimoneda, `Money` y tasa de cambio | `REQ-FX-001…006` | 6 |
| [20-apartado-y-cronograma.md](20-apartado-y-cronograma.md) | Apartado, estados y amortización | `REQ-LAY-001…011` | 11 |
| [21-portal-del-cliente.md](21-portal-del-cliente.md) | Portal privado del cliente | `REQ-LAY-020…023` | 4 |
| [22-comprobantes-y-conciliacion.md](22-comprobantes-y-conciliacion.md) | Comprobantes, pagos y balances | `REQ-PAY-001…009` | 9 |
| [30-backoffice-accesos-y-usuarios.md](30-backoffice-accesos-y-usuarios.md) | Acceso del equipo y auditoría | `REQ-BOF-001`, `REQ-BOF-005` | 2 |
| [31-backoffice-inventario.md](31-backoffice-inventario.md) | Inventario, fichas y catálogos | `REQ-BOF-020…024` | 5 |
| [32-backoffice-clientes-y-cobranza.md](32-backoffice-clientes-y-cobranza.md) | Clientes, cobranza y exportaciones | `REQ-BOF-040…045` | 6 |
| [40-notificaciones.md](40-notificaciones.md) | Web Push y email transaccional | `REQ-NOT-001…005` | 5 |
| [41-chat-soporte.md](41-chat-soporte.md) | Chat cliente ↔ negocio | `REQ-CHT-001…005` | 5 |
| [42-telemetria.md](42-telemetria.md) | Telemetría, privacidad y analítica | `REQ-TLM-001…005` | 5 |
| [51-backoffice-de-plataforma.md](51-backoffice-de-plataforma.md) | Backoffice de plataforma | `REQ-PLT-001…006` | 6 |
| [90-infraestructura-y-entorno.md](90-infraestructura-y-entorno.md) | Docker, Traefik, colas, Reverb | `REQ-INF-001…006` | 6 |
| [91-calidad-y-gates.md](91-calidad-y-gates.md) | Estándares, pruebas y definición de hecho | `REQ-QAX-001…008` | 8 |
| | | **Total** | **118** |

## Cómo leer un módulo

Cada requisito sigue la misma plantilla de ocho secciones:

1. **ID y título** — identificador trazable `REQ-<DOMINIO>-<NNN>`.
2. **Historia de usuario** — formato *Como / Quiero / Para*.
3. **Contratos de datos** — entidades, interfaces PHP y tipos TypeScript involucrados.
4. **Reglas de negocio y precondiciones** — restricciones duras, numeradas `RB-n`.
5. **Happy path** — secuencia exitosa paso a paso.
6. **Sad paths y edge cases** — fallos numerados `SP-n`.
7. **Fuera de alcance inmediato** — exclusiones explícitas de esta iteración.
8. **Gate de validación** — superficie de código esperada, tipos esperados, función **anti-`SHALLOW`** y pruebas que cierran el gate.

## Convenciones

**Nombres de archivo.** Numeración estable por bloques: `0x` proceso y fundamentos · `1x` identidad, cuentas y catálogo · `2x` apartado y cobranza · `3x` backoffice del negocio · `4x` comunicación y datos · `5x` plataforma · `9x` transversales. **Un archivo nunca se renumera.**

**Identificadores trazables.** `REQ-<DOMINIO>-<NNN>`:

| Código | Dominio |
|--------|---------|
| `ORG` | Tenancy, organizaciones y equipo |
| `SUB` | Planes, límites y suscripciones |
| `IDN` | Identidad comercial del cliente y autenticación |
| `ACC` | Cuentas de consumidor, suscripción a catálogos y feed |
| `CAT` | Productos, taxonomía y catálogos |
| `FX`  | Bimoneda y tasa de cambio |
| `LAY` | Apartado, cronograma y portal del cliente |
| `PAY` | Comprobantes, conciliación y balances |
| `BOF` | Backoffice del negocio |
| `NOT` | Notificaciones (Web Push / email) |
| `CHT` | Chat cliente ↔ negocio |
| `TLM` | Telemetría y analítica |
| `PLT` | Backoffice de plataforma |
| `INF` | Infraestructura, Docker, Traefik, colas |
| `QAX` | Calidad, testing y definición de hecho |

**IDs retirados.** `REQ-CAT-001` (listado público global), `REQ-CAT-020` (sección pública de catálogos) y `REQ-BOF-002` (seeder de superadmin) fueron **eliminados** por el pivote. `REQ-BOF-003`, `REQ-BOF-004` y `REQ-BOF-006` fueron **trasladados** a `REQ-ORG-004`, `REQ-ORG-005` y `REQ-ORG-006`. Ninguno de los seis IDs se reutiliza: quedan como huecos permanentes.

**Idioma.** Narrativa en español; **toda** entidad, campo, tipo, enum, ruta, clase y función en inglés. El espejo [.align/requirements.en.md](.align/requirements.en.md) reúne los identificadores esperados de los 118 requisitos y funciona como **contrato de nombres** del equipo (`REQ-QAX-005`).

## Invariantes del sistema

Las reglas que ninguna implementación puede violar, con su requisito de origen. Las cinco primeras son las que sostienen el modelo multi-tenant y no admiten excepción.

| Invariante | Origen |
|---|---|
| **Ninguna consulta de tabla del tenant se ejecuta sin filtro por `organization_id`** | `REQ-ORG-002` |
| **Todo índice único de negocio incluye `organization_id`**, salvo tres excepciones declaradas y en lista blanca | `REQ-ORG-002`, `REQ-QAX-008` |
| **Una organización nunca sabe que su cliente o su suscriptor también opera con otra** | `REQ-ACC-007` |
| **El `account_id` nunca se expone a una organización** en props, JSON ni exportaciones | `REQ-ACC-007` |
| **Un job sin tenant fijado que toca una tabla del tenant falla**, en lugar de operar sin filtro | `REQ-INF-003` |
| El cliente final se identifica por `(organization_id, document_number)`; el email nunca identifica | `REQ-IDN-001` |
| El teléfono del cliente es obligatorio: es el canal de comunicación del negocio | `REQ-IDN-001` |
| Reclamar una ficha exige documento **y** código de apartado; el documento a solas nunca basta | `REQ-ACC-004` |
| Navegar y apartar nunca exigen cuenta; solo suscribirse y el feed la exigen | `REQ-ACC-002` |
| El feed solo contiene contenido de catálogos que la persona eligió seguir | `REQ-ACC-003` |
| **El impago de una suscripción nunca interrumpe un apartado en curso ni el portal del cliente** | `REQ-SUB-007` |
| Superar un límite de plan bloquea la creación; nunca destruye ni oculta datos existentes | `REQ-SUB-008` |
| Un comprobante de pago de un cliente nunca se rechaza por la cuota de almacenamiento del negocio | `REQ-SUB-008`, `REQ-PAY-001` |
| Todo importe es `BIGINT` de centavos USD; `FLOAT` prohibido | `REQ-FX-001` |
| `sum(Money::allocateEvenly(n)) === cents` | `REQ-FX-001` |
| `sum(installments.amount_cents) === total − inicial` | `REQ-LAY-004` |
| `sum(allocations) === payment.amount − unallocated` | `REQ-PAY-005` |
| `remaining_cents === total_cents − paid_cents`, nunca negativo | `REQ-PAY-008` |
| La deuda se denomina en USD; la tasa se congela por pago y es inmutable | `REQ-FX-006` |
| El apartado congela sus condiciones comerciales al crearse | `REQ-LAY-002` |
| Solo `LayawayBalanceRecalculator` escribe el balance materializado | `REQ-PAY-008` |
| Toda transición de estado pasa por `LayawayStateMachine` | `REQ-LAY-005` |
| `CollectionFilters` es la única fuente de verdad de la query de cobranza | `REQ-BOF-042` |
| `LimitEnforcer` es el único punto de decisión sobre límites y funciones de plan | `REQ-SUB-004` |
| El stock nunca queda negativo; la reserva se hace bajo bloqueo pesimista | `REQ-LAY-003` |
| Los comprobantes nunca se borran: los archivos son evidencia | `REQ-PAY-002` |
| La IP nunca se persiste en claro | `REQ-TLM-005` |
| El `PlatformAdmin` accede a datos personales solo con grant de soporte auditado y visible | `REQ-PLT-005` |
| `platform_users` y `users` nunca se cruzan | `REQ-ORG-003`, `REQ-PLT-001` |

## Estado de los pendientes

Los tres que estaban abiertos quedaron resueltos:

| Pendiente | Resolución |
|---|---|
| `indexion` | **Descartado.** Los gates pasan a Pest + Vitest + PHPStan + `tenancy:audit` + revisión de PR ([02 §8](02-spec-consolidada.md)). `REQ-QAX-005` y `REQ-QAX-006` reescritos |
| Red de Traefik en producción | **Diferido.** Sin dominio ni servidor todavía. Esta iteración es **solo local** sin proxy ni TLS; `REQ-INF-002` marcado como diferido y sacado del plan de tareas |
| Proveedor de tasa de cambio | **DolarApi** (`ve.dolarapi.com/v1/dolares`, fuente BCV). Una petición trae oficial y paralelo; contrato completo en [05 §6](05-design-tecnico.md) |

Queda una **decisión reversible** marcada en la spec: la visibilidad del suscriptor frente al negocio (`REQ-ACC-007` RB-1 y RB-7). Hoy está cerrada — el negocio ve nombre público, no contacto.
