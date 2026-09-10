# PASO 1-bis — Pivote a SaaS multi-tenant: decisiones y mapa de impacto

> **Estado:** ✅ Cerrado. Las 7 decisiones de §3 fueron aprobadas ("OK a todo") y la capa de cuentas de cliente final de §4 quedó diseñada y aprobada para especificar.
> **Fecha:** 2026-09-10 · Sustituye el encuadre de negocio de [01-asunciones-criticas.md](01-asunciones-criticas.md), que se conserva como registro de las decisiones que **siguen vigentes** (toda la aritmética financiera, el flujo de comprobantes y la infraestructura).

---

## 1. El nuevo producto

Apartar deja de ser **una tienda** y pasa a ser **una plataforma**: cada negocio se registra, carga su inventario, arma catálogos con sistema de apartado y gestiona a sus propios clientes y cobranzas. Monetización por **suscripción con capa gratuita**.

### 1.1 Cambio de encuadre

| | Antes (v0) | Ahora (v1) |
|---|---|---|
| Naturaleza | Tienda propia de un negocio | SaaS multi-tenant B2B2C |
| `/` (raíz) | Catálogo público de productos | **Landing del SaaS** + registro |
| Descubrimiento | El visitante explora todo el catálogo | **No hay exploración global.** Se llega por el enlace de **un** catálogo |
| Alta del admin | Seeder de base de datos | **Registro self-serve** + suscripción |
| Cliente final | Ficha del negocio | **Ficha del negocio (cédula) + cuenta global opcional (Google)** |
| Backoffice | Uno solo | Uno **por organización** + uno **de plataforma** |
| Modelo de ingreso | Venta de productos | Suscripción por planes |

### 1.2 Actores

| Actor | Tabla | Guard | Ruta |
|---|---|---|---|
| `PlatformAdmin` (tú) | `platform_users` | `platform` | `/plataforma` |
| `Organization` (tenant) | `organizations` | — | — |
| `OrganizationUser` (`owner` \| `member`) | `users` + `organization_id` + `role` | `web` | `/backoffice` |
| `Account` (consumidor global, Google) | `accounts` | `account` | `/mi-cuenta` |
| `Customer` (cliente de un negocio) | `customers` + `organization_id` | `customer` | `/mi-cuenta` (acotado) |
| `Visitor` | cookie `visitor_id` | — | `/c/{slug}` |

### 1.3 Superficies

```
apartar.store/                     → landing del SaaS, precios, registro
apartar.store/registro             → alta de organización (plan Free)
apartar.store/backoffice/*         → panel de LA organización (tenant-scoped)
apartar.store/plataforma/*         → panel de plataforma (solo tú)
apartar.store/{org-slug}           → vitrina opcional del negocio (solo SUS catálogos)
apartar.store/c/{catalog-slug}     → EL catálogo compartido (superficie del cliente final)
apartar.store/c/{slug}/apartar     → formulario de apartado dentro del catálogo
apartar.store/mi-cuenta/*          → portal del cliente final (dos modos, §4.4)
ws.apartar.store                   → WebSocket (chat y alertas)
```

---

## 2. Decisiones técnicas ya tomadas

**D-1 · Tenancy: base de datos única con `organization_id`.** *Global scope* de Eloquent vía `BelongsToOrganization`, middleware de resolución del tenant, e `organization_id` en todo índice único de negocio. Descartado el esquema por base de datos por tenant: con capa gratuita, el coste marginal por organización debe ser cercano a cero.

**D-2 · La invariante más importante del sistema pasa a ser el aislamiento.** Ninguna consulta de tabla del tenant se ejecuta sin filtro por `organization_id`. Se convierte en un gate propio (`REQ-QAX-008`): test de fuga cruzada **por cada endpoint**.

**D-3 · Chat enrutado por catálogo → organización.** (Delegado.) El hilo es `(organization_id, customer_id)`: uno permanente por cliente y por negocio, derivado del catálogo desde el que escribe. Una persona con apartados en dos negocios tiene dos hilos aislados. Nunca se cruza un mensaje.

**D-4 · Roles de organización: `owner` y `member`.** `owner` gestiona suscripción, facturación y equipo; `member` opera. Sin RBAC granular. El primer `owner` nace del registro.

**D-5 · La plataforma es un dominio de identidad separado.** `platform_users` y guard `platform` propios, sin relación con `users`. No comparten tabla, así que no hay escalada posible entre ambos. El `PlatformAdmin` inicial sí nace por seeder — único uso que sobrevive del antiguo `REQ-BOF-002`.

**D-6 · La tasa de cambio es global; la fuente la elige cada organización.** `exchange_rates` es tabla de plataforma. Toda la aritmética de [13](13-bimoneda-y-tasa-de-cambio.md) sobrevive intacta.

**D-7 · Nace `PaymentAccount` (cuenta de cobro).** Cada organización declara dónde le pagan (banco, titular, cédula/RIF, teléfono de pago móvil, correo de Zelle, wallet) y el catálogo se lo muestra al cliente al apartar. En una tienda propia esto era implícito; en la plataforma es obligatorio.

**D-8 · El email no identifica a nadie en la capa comercial.** Obligatorios en `Customer`: `document_number` y `phone`. `email` opcional y sin unicidad.

**D-9 · `settings` se parte:** `organization_settings` (por tenant) y `platform_settings` (globales).

**D-10 · Numeración:** el bloque `0x` pasa a ser "proceso y fundamentos" (entra `04`), el `1x` incorpora `15`, y nace el bloque `5x` para la capa de plataforma. Ningún archivo existente se renumera.

---

## 3. Decisiones de negocio aprobadas

| # | Decisión | Resolución |
|---|---|---|
| **Q1** | Alcance del cliente final | **`Customer` por organización**: `UNIQUE(organization_id, document_number)`. Sin registro global de cédulas. La capa global es la cuenta voluntaria de §4, y su clave es Google, **no** el documento |
| **Q2** | Acceso al portal sin email fiable | **Documento + código de apartado** como vía universal, magic link por email cuando exista, Google como vinculación. OTP por WhatsApp diferido a plan pago |
| **Q3** | Cobro de la suscripción | **Conciliación manual** con el mismo mecanismo de comprobantes del producto. Pasarela cuando el volumen lo justifique |
| **Q4** | URL del catálogo | **`apartar.store/c/{slug}`** con slug único global, más vitrina opcional del negocio en `apartar.store/{org-slug}`. Dominio propio en plan Business |
| **Q5** | Acceso de plataforma a datos personales | **Métricas agregadas + acceso de soporte auditado**, con motivo, caducidad y registro visible en la auditoría de la organización |
| **Q6** | Planes y límites | Matriz de §5 aprobada. Free **sí** incluye apartados, con tope de 15 activos |
| **Q7** | Exceso de límite y baja de plan | **Bloqueo blando, nunca destructivo.** Y regla no negociable: el impago de una suscripción **nunca** interrumpe un apartado en curso ni el portal del cliente final |

---

## 4. Capa de cuentas de cliente final, suscripción a catálogos y feed

Esta es la pieza nueva. La idea original —"que el cliente final tenga perfil, se suscriba a catálogos y tenga un feed"— se vuelve implementable al separar **dos identidades que hasta ahora estaban mezcladas**.

### 4.1 El insight: dos identidades, dos propósitos

| | `Customer` (identidad comercial) | `Account` (identidad de consumidor) |
|---|---|---|
| ¿Qué representa? | Una **deuda con un negocio concreto** | Una **persona que navega la plataforma** |
| Clave | `(organization_id, document_number)` | `google_sub` (vía `account_identities`) |
| Alcance | Una organización | Global |
| Datos | Documento, teléfono, nombre, email opcional | Nombre, email de Google, avatar. **Sin documento ni teléfono** |
| Es dueña de | Apartados, cuotas, comprobantes, pagos, balance, hilo de chat | Suscripciones a catálogos, feed, preferencias de aviso |
| ¿Obligatoria? | Sí, para apartar | **No.** Solo para suscribirse y usar el feed |
| ¿Quién la ve? | Su organización | Nadie más que la propia persona (§4.7) |

Por qué importa: **la deuda es de una relación bilateral y debe seguir aislada por negocio** (Q1), mientras que **la audiencia es de la plataforma**. La cuenta global no crea un registro nacional de cédulas —mi objeción en Q1—: su clave es una cuenta de Google voluntaria, y el documento nunca sale del tenant.

### 4.2 El vínculo entre ambas

`customers.account_id` (nullable, con `UNIQUE(organization_id, account_id)`). Una cuenta se relaciona con **como máximo una** ficha por organización, y con N organizaciones en total.

Tres vías de vinculación, y **solo tres**:

1. **Automática al apartar** — si la persona tiene sesión de cuenta al apartar, la ficha creada o encontrada queda vinculada.
2. **Reclamo de una ficha previa** — quien apartó sin cuenta y luego crea una, reclama su historial con **documento + código de apartado**. Es exactamente la prueba de Q2, reutilizada.
3. **Nunca por coincidencia de email, y nunca por documento a solas.** La cédula en Venezuela es semi pública: aceptar el documento como única prueba permitiría reclamar la deuda —y el historial— de otra persona.

### 4.3 Suscripción a un catálogo

- El catálogo compartido muestra un CTA **"Seguir este catálogo"** que **exige** iniciar sesión con Google (así lo pediste, y es coherente: la suscripción necesita una identidad durable, y Google la da sin gestionar contraseñas).
- `catalog_subscriptions` (`account_id`, `catalog_id`, `subscribed_at`, `muted_at?`) con `UNIQUE(account_id, catalog_id)`.
- **Navegar y apartar NO exigen cuenta.** Esto es deliberado y no se negocia: el apartado sin registro era el requisito de conversión desde la primera iteración, y meter un login delante del formulario de apartado mataría la venta. Google se exige **solo** para seguir un catálogo y para el feed.
- Al apartar, la persona con cuenta **queda suscrita automáticamente** a ese catálogo, informándolo en la confirmación y con baja de un clic. Quien acaba de comprometerse a un plan de pagos con un negocio tiene una relación con él; forzar un segundo clic solo pierde audiencia.

### 4.4 El portal, con dos modos de sesión

Ambos viven en `/mi-cuenta` y comparten pantallas, pero no capacidades:

| | Sesión de **cuenta** (Google) | Sesión **acotada** (documento + código) |
|---|---|---|
| Ve apartados de | **Todos** los negocios donde tiene ficha vinculada, agrupados por negocio | **Solo** el negocio de ese apartado |
| Suscripciones y feed | Sí | No |
| Chat | Todos sus hilos, uno por negocio | Solo el hilo de ese negocio |
| Fricción de entrada | Login de Google | Cédula + código |
| Upsell | — | "Conecta tu Google para ver todo en un solo lugar" |

La sesión acotada existe para que **nadie quede fuera de su propia información** por no tener Gmail. Es la red de seguridad de Q2.

### 4.5 El feed

`GET /mi-cuenta/novedades`. Contenido: productos publicados de los catálogos que la cuenta sigue, ordenados por su incorporación al catálogo, descendente, paginados por cursor.

Reglas que lo hacen honesto:

- Solo contiene **contenido de catálogos que la persona eligió seguir** y que ya es público de por sí. El feed no expone nada que el enlace del catálogo no expusiera.
- Si un catálogo se despublica o expira, sus productos salen del feed y la suscripción queda marcada como inactiva, sin borrarse.
- Un producto presente en dos catálogos del mismo negocio aparece **una vez**.
- El filtro del feed es **por catálogo y por búsqueda de texto**, no por categoría: las categorías pertenecen a cada organización, así que una categoría "Motores" de un negocio no es comparable con la de otro. El filtro por categoría se mantiene **dentro** de cada catálogo, donde sí tiene sentido.
- Sin motor de recomendación (sigue fuera de alcance). El orden es cronológico y explicable.

### 4.6 Por qué el feed no es un adorno: el bucle del producto

El feed no compite con una red social por la atención. Se apoya en algo que la plataforma **ya provoca**:

```
El cliente aparta  →  debe una cuota cada 15 días
                   →  vuelve al portal a ver su saldo y subir su comprobante   ← visita recurrente garantizada
                   →  en el portal ve novedades de los negocios que ya le fiaron
                   →  aparta otra cosa
```

El ciclo de cobranza **fabrica** la visita recurrente; el feed la convierte en venta. De ahí una consecuencia de diseño concreta: **la portada del portal no es el feed, es "lo que debo" + "novedades"** en la misma pantalla. Primero el motivo por el que vino, después el motivo por el que se queda.

Y de ahí también el incentivo del negocio: cada suscriptor es audiencia propia a la que puede avisar cuando publica. Por eso **avisar a suscriptores es función de plan pago** (§5), mientras que **el número de suscriptores es ilimitado en todos los planes**: limitar el crecimiento de la audiencia sería limitar el valor de tu propia red.

### 4.7 Privacidad del suscriptor frente al negocio

La organización ve:

- El **conteo** de suscriptores y su evolución.
- El **nombre público** que expone Google (ej. "Pedro M.") en el listado de suscriptores.
- Una herramienta para **avisarles** de novedades sin acceder a sus datos de contacto.

La organización **no** ve el email ni el teléfono de un suscriptor que no sea además su cliente. Cuando esa persona aparta, se convierte en `Customer` y entonces el negocio ve todo, como siempre — porque ahí sí hay una relación comercial que lo justifica.

> **Decisión reversible:** si prefieres el modelo Instagram (el negocio ve la lista completa con email), es un cambio de una regla en `REQ-ACC-007`. Recomiendo empezar cerrado: entregar contactos de gente que solo pulsó "seguir" es un vector de spam y el tipo de cosa que cuesta caro deshacer.

### 4.8 Límite honesto que debes conocer

Al eliminar el descubrimiento global, **la única vía de adquisición de suscriptores es que el negocio comparta su enlace**. El feed no tiene valor hasta que una persona sigue dos o más catálogos, y su estado vacío no puede ofrecer "explora catálogos" porque eso es justo lo que quitamos.

Dos mitigaciones ya incorporadas, ambas sin reintroducir el índice global: la **vitrina del negocio** en `apartar.store/{org-slug}` (Q4), que permite seguir varios catálogos del mismo negocio desde un solo enlace, y la **suscripción automática al apartar** (§4.3), que convierte a cada comprador en suscriptor. Un directorio público de negocios es la evolución natural cuando haya volumen, y el modelo de datos queda preparado para ello sin migraciones.

---

## 5. Matriz de planes aprobada

| Límite / Función | **Free** | **Pro** | **Business** |
|---|---|---|---|
| Usuarios del equipo | 1 | 5 | 15 |
| Catálogos publicados | 1 | 10 | 50 |
| Productos en inventario | 30 | 300 | 2.000 |
| Productos por catálogo | 20 | 100 | 500 |
| Apartados activos | 15 | 200 | 2.000 |
| Almacenamiento (imágenes + comprobantes) | 200 MB | 5 GB | 25 GB |
| Cuentas de cobro | 1 | 5 | 15 |
| **Suscriptores de catálogo** | **ilimitados** | **ilimitados** | **ilimitados** |
| **Avisos de novedades a suscriptores** | no | sí | sí |
| Sello "Hecho con Apartar" en el catálogo | visible | oculto | oculto |
| Branding (logo y color) | no | sí | sí |
| Exportación a Excel | no | sí | sí |
| Web Push a clientes | no | sí | sí |
| Chat con clientes | sí (historial 30 días) | sí | sí |
| Analítica de catálogo y suscriptores | básica | completa | completa |
| Dominio propio | no | no | sí |
| Retención de telemetría | 30 días | 180 días | 365 días |

---

## 6. Mapa de impacto sobre los 93 requisitos

Leyenda: **=** sobrevive · **+org** añade `organization_id` y aislamiento · **↻** se reescribe · **✖** se elimina

| Módulo | Veredicto | Cuenta |
|---|---|---|
| [10 · IDN](10-identidad-y-autenticacion.md) | `IDN-001` ↻ clave `(org, documento)`, teléfono obligatorio · `IDN-002` ↻ **Google pasa a crear `Account`, no identidad comercial** · `IDN-003` ↻ vía secundaria · `IDN-004` ↻ dos guards de portal · `IDN-005` = · `IDN-006` +org · `IDN-007` ↻ cédula **y RIF** · **`IDN-008` nuevo** sesión acotada por documento + código | 7 → 8 |
| [11 · CAT](11-catalogo-y-productos.md) | `CAT-001` ✖ · `CAT-002` ↻ búsqueda dentro del catálogo · `CAT-003` ↻ + cuentas de cobro · `CAT-004` = · `CAT-005` +org + cuota · `CAT-006` ↻ taxonomía por organización, navegación dentro del catálogo · `CAT-007` +org | 7 → 6 |
| [12 · CAT curados](12-catalogos-curados.md) | `CAT-020` ✖ · `CAT-021` ↻ **el catálogo es la tienda** + CTA de suscripción · `CAT-022` ↻ slug global, sello y branding por plan · `CAT-023` +org + tope de plan | 4 → 3 |
| **[15 · ACC](15-cuentas-de-cliente-y-feed.md)** | **nuevo**: cuenta Google, suscripción, feed, vinculación y reclamo, dos modos de sesión, avisos de novedades, privacidad del suscriptor | 0 → 7 |
| [13 · FX](13-bimoneda-y-tasa-de-cambio.md) | `FX-003` ↻ fuente por organización · `FX-004` +org · resto = | 6 → 6 |
| [20 · LAY](20-apartado-y-cronograma.md) | Todos +org · `LAY-001` ↻ nace en un catálogo, valida tope del plan, vincula cuenta y suscribe · `LAY-004`/`006`/`007` ↻ ajustes por organización | 11 → 11 |
| [21 · Portal](21-portal-del-cliente.md) | Todos ↻: dos modos de sesión, apartados agrupados por negocio, portada "lo que debo + novedades" | 4 → 4 |
| [22 · PAY](22-comprobantes-y-conciliacion.md) | Todos +org · `PAY-001` ↻ elige cuenta de cobro · `PAY-002` ↻ cuota de plan · `PAY-003` ↻ **unicidad de referencia por organización**, no global | 9 → 9 |
| [30 · BOF](30-backoffice-accesos-y-usuarios.md) | `BOF-001` ↻ · `BOF-002` ✖ → pasa a `PLT` · `BOF-003` ↻ tope de plan · `BOF-004` ↻ `owner`/`member` · `BOF-005` +org · `BOF-006` ↻ `organization_settings` | 6 → 5 |
| [31 · Inventario](31-backoffice-inventario.md) | Todos +org + topes de plan | 5 → 5 |
| [32 · Cobranza](32-backoffice-clientes-y-cobranza.md) | Todos +org · `BOF-043` ↻ función de plan · `BOF-044` ↻ + consumo del plan y suscriptores | 6 → 6 |
| [40 · NOT](40-notificaciones.md) | Todos +org · `NOT-001` ↻ **notificable polimórfico** (`Account` \| `Customer`) · `NOT-004` ↻ función de plan | 5 → 5 |
| [41 · CHT](41-chat-soporte.md) | Todos ↻ por D-3; con cuenta, la persona ve todos sus hilos | 5 → 5 |
| [42 · TLM](42-telemetria.md) | Todos +org · `TLM-001` ↻ + dimensión de catálogo y eventos de suscripción y feed | 5 → 5 |
| **[04 · ORG](04-tenancy-y-organizaciones.md)** | **nuevo**: registro, resolución del tenant, aislamiento, roles, equipo, ajustes, branding, cuentas de cobro, suspensión | 0 → 8 |
| **[50 · SUB](50-planes-y-suscripciones.md)** | **nuevo**: planes, alta en Free, medición, límites, cambio de plan, cobro por comprobante, impago, degradación | 0 → 8 |
| **[51 · PLT](51-backoffice-de-plataforma.md)** | **nuevo**: acceso, directorio de organizaciones, métricas, suscripciones, soporte auditado, salud | 0 → 6 |
| [90 · INF](90-infraestructura-y-entorno.md) | `INF-003` ↻ colas nuevas · resto = | 6 → 6 |
| [91 · QAX](91-calidad-y-gates.md) | Todos = · **`QAX-008` nuevo**: gate de aislamiento multi-tenant | 7 → 8 |

**Balance real tras la reescritura:** 93 − 7 eliminados o trasladados + 3 nuevos en módulos existentes + 29 en módulos nuevos = **118 requisitos**.

> **Ajuste sobre la estimación inicial.** Predije 121; el número final es 118. La diferencia viene de una decisión tomada al escribir el módulo de tenancy: `REQ-BOF-003` (invitación de administradores), `REQ-BOF-004` (desactivación) y `REQ-BOF-006` (ajustes globales) encontraron un hogar mejor en `ORG-004`, `ORG-005` y `ORG-006`, porque la gestión del equipo y de los ajustes es inseparable de los límites del plan y de la identidad del tenant. Duplicarlos en dos módulos habría sido peor que moverlos. El módulo 30 queda con dos requisitos —acceso y auditoría— y los IDs trasladados quedan como huecos permanentes, sin reutilizarse.

Detalle final por módulo:

| Módulo | N | | Módulo | N |
|---|---|---|---|---|
| 04 · ORG | 8 | | 32 · BOF cobranza | 6 |
| 10 · IDN | 8 | | 40 · NOT | 5 |
| 11 · CAT | 6 | | 41 · CHT | 5 |
| 12 · CAT catálogo | 3 | | 42 · TLM | 5 |
| 13 · FX | 6 | | 50 · SUB | 8 |
| 15 · ACC | 7 | | 51 · PLT | 6 |
| 20 · LAY | 11 | | 90 · INF | 6 |
| 21 · LAY portal | 4 | | 91 · QAX | 8 |
| 22 · PAY | 9 | | | |
| 30 · BOF acceso | 2 | | **Total** | **118** |
| 31 · BOF inventario | 5 | | | |

El núcleo financiero ([13](13-bimoneda-y-tasa-de-cambio.md) y la amortización de [20](20-apartado-y-cronograma.md)/[22](22-comprobantes-y-conciliacion.md)) sobrevive casi sin tocarse. El pivote cambia el envoltorio, no la aritmética.

---

## 7. Nuevas invariantes del sistema

| Invariante | Origen |
|---|---|
| Ninguna consulta de tabla del tenant se ejecuta sin filtro por `organization_id` | `ORG-002` |
| Todo índice único de negocio incluye `organization_id` | `ORG-002` |
| El cliente final se identifica por `(organization_id, document_number)`; el email nunca identifica | `IDN-001` |
| El teléfono del cliente es obligatorio: es el canal de comunicación del negocio | `IDN-001` |
| **Una `Account` nunca da acceso a datos de apartado sin un vínculo verificado** | `ACC-004` |
| **Reclamar una ficha exige documento + código de apartado; el documento a solas nunca basta** | `ACC-004` |
| **Una organización nunca sabe que su cliente también opera con otra organización** | `ACC-007` |
| **El feed solo contiene contenido de catálogos que la persona eligió seguir** | `ACC-003` |
| **Navegar y apartar nunca exigen cuenta; solo suscribirse y el feed la exigen** | `ACC-002` |
| Un catálogo pertenece a una organización, y de ahí se deriva todo el contexto del cliente final | `ORG-002`, `CHT-001` |
| El impago de una suscripción nunca interrumpe un apartado en curso ni el portal del cliente | `SUB-007` |
| Superar un límite bloquea la creación; nunca destruye ni oculta datos existentes | `SUB-008` |
| El `PlatformAdmin` accede a datos personales solo con acceso de soporte auditado y visible | `PLT-005` |
| `platform_users` y `users` nunca se cruzan | `ORG-003`, `PLT-001` |
