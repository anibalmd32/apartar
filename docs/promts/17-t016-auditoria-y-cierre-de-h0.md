# PROMPT 17 — `T-016` · Auditoría, y cierre de H0

> Hito **H0 · Cimientos** · Tarea `T-016` · Fase `AB` · 2 pts
> Cierra `REQ-BOF-005`. **Es la última tarea del hito.**
> Depende de `T-015` (`6e91ce8`, cuentas de cobro), `T-014` (`3b827c1`, ajustes) y `T-013`/`T-013b` (equipo completo).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-016: auditoria y cierre de H0`.

## 0. Qué vas a hacer

**Quién hizo qué y cuándo.** En un producto cuya función es conciliar dinero ajeno, la auditoría no es una pestaña más: es lo que permite a un dueño responder a un cliente que reclama, o averiguar qué hizo un empleado que ya no está.

Y como es la última tarea de H0, **saldas dos deudas de arrastre** (§6). Ninguna es de `REQ-BOF-005`; las dos están anotadas en el plan.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/30-backoffice-accesos-y-usuarios.md](../specs/30-backoffice-accesos-y-usuarios.md) → **`REQ-BOF-005`** | el requisito completo: nueve reglas, siete `SP-` y la **lista cerrada** de RB-1 |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-016` | alcance, los dos cierres de H0, y las convenciones del encabezado |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§4 y §8** | dónde va `activity_log` y dónde va cada archivo |

## 2. Alcance

**Sí:**

1. La migración de **`activity_log` con `organization_id`** y su global scope.
2. **`AuditEvent`**, con **toda** la lista cerrada de RB-1 (ver §3).
3. **`LogsSensitiveActivity`** y su cableado en **las `Action` que ya existen** (ver §4).
4. **`AuditLogController`** con la vista de solo lectura, paginada por servidor, con filtros por actor, tipo y rango.
5. Sus tests, incluido el **de fuga entre organizaciones**.
6. **Los dos cierres de H0** de §6.

**No:**

- **No** inventes eventos para módulos que no existen. El enum los declara **todos**; solo se emiten los que tienen Action.
- **No** implementes la exportación de la auditoría ni el sellado: están fuera de alcance por decisión.
- **No** toques la purga: RB-8 fija retención indefinida en v1.
- **No** entres en H1. `T-017` (`Money` y `Currency`) es la tarea siguiente y no es tuya.

## 3. El enum se escribe entero; los emisores, no

`AuditEvent` es una **lista cerrada normativa** (RB-1) y va completa, con sus seis bloques —cobranza, catálogo, clientes, organización, suscripción y plataforma—. Que un evento no tenga aún quién lo emita no lo hace menos real: la lista es la decisión de qué se audita en este producto, y fragmentarla en seis hitos convertiría una decisión tomada en seis decisiones por tomar.

Pero eso deja un problema de verdad: **treinta y tantos casos sin emisor y ningún mecanismo que obligue a conectarlos cuando llegue su tarea.** No sirve una lista escrita a mano de "pendientes": se queda vieja en silencio, que es justo lo que este repositorio no acepta (ver la convención de `Cómo leer este plan`).

La forma que sí funciona: **cada caso declara la clase que lo emite**, y un test recorre el enum y exige que **toda clase que ya existe use `LogsSensitiveActivity`**. Los casos cuya clase todavía no existe no se saltan: simplemente no hay nada que afirmar sobre ellos, y el día que `T-032` cree `PublishCatalogAction` el test empieza a cubrirlo **sin que nadie tenga que acordarse**. Añade una aserción de que hoy cubre al menos los que sabes que existen, para que no pueda volverse vacío sin que se note.

Si encuentras una forma mejor de conseguir lo mismo, úsala y explícala. Lo que no vale es dejarlo al recuerdo de quien escriba `T-032` dentro de veinte tareas.

## 4. Qué se puede auditar hoy

De los seis bloques de RB-1, solo **Organización** tiene acciones escritas: equipo (`T-013`, `T-013b`), ajustes e identidad (`T-014`) y cuentas de cobro (`T-015`). Eso es lo que se cablea. Los demás bloques los conecta cada hito con sus propias Actions.

Dos detalles de ese cableado:

- **`organization_settings.updated_by` ya se escribe desde `T-014`**, y no lo sustituye la auditoría: uno responde "quién lo tiene ahora", la otra "quién lo cambió y desde qué valor". Son preguntas distintas.
- El bloque de **Suscripción** tiene un caso especial: `T-011` creó la suscripción Free dentro del registro. No lo audites retroactivamente; el alta inicial no es un cambio de plan.

## 5. Las tres reglas que hacen que esto sirva

**1. Escribir la auditoría no puede romper la operación** (RB-4, SP-3). Se registra dentro de la transacción, pero un fallo al serializar `properties` **se degrada a una descripción mínima** en lugar de abortar. Es la inversión deliberada de la regla de fallo ruidoso que rige el resto del proyecto, y el motivo es concreto: perder el registro de que se aprobó un comprobante es malo; **no aprobar el comprobante porque el registro falló es peor**. El fallo va al log de errores, donde alguien lo ve.

**2. `properties` nunca contiene contraseñas, tokens, contenido de archivos ni `account_id`** (RB-5). Un `account_id` en la auditoría de una organización filtra la identidad global de un consumidor a un negocio que solo debería conocer su ficha de cliente (`REQ-ACC-007` RB-5). Es una fuga entre las dos identidades del diseño (`D-8`), no un descuido de formato.

**3. Las acciones de la plataforma sobre una organización son visibles en la auditoría de esa organización** (RB-6). Es lo que hace defendible el modelo de soporte: el negocio siempre puede ver cuándo alguien de Apartar miró o tocó sus datos. Hoy no hay `PlatformUser` actuando —`REQ-PLT-005` es H8—, pero **el `causer` polimórfico tiene que admitirlo desde ya**, junto al `causer` nulo del sistema (RB-7, SP-1). Si el esquema solo contempla `User`, H8 tendrá que migrar la tabla.

Y la fuga (SP-6): `activity_log` lleva `organization_id` con global scope, y su caso va en `tests/Feature/Tenancy` como cualquier otra consulta del tenant.

## 6. Los dos cierres de H0

Las dos están anotadas en el plan bajo `T-016` con su razonamiento completo. En corto:

**1. `npm run format:check` no lo invoca nadie.** Existe desde `T-003` y ni `npm run check` ni CI lo ejecutan, así que hoy **23 archivos** incumplen el `.prettierrc` que `REQ-QAX-002` declara en sus contratos de datos. Añádelo a `npm run check` y al trabajo `frontend` de CI, formatea los cinco nuestros, y excluye `resources/js/components/vendor/**` en un `.prettierignore` — por el mismo motivo por el que `eslint.config.js` ya lo exime de `max-lines`: es andamiaje heredado en retirada y reformatearlo es ruido sobre código que se va a borrar. Queda como `REQ-QAX-002` RB-10.

**2. `PublishedCatalogs::exist()` falla en silencio.** Hoy comprueba que la tabla `catalogs` no exista y devuelve `false`; **`T-024` crea esa tabla ocho tareas antes de que `T-032` rellene el cuerpo**, y en esa ventana `REQ-ORG-006` SP-5 —el bloqueo del slug con catálogos publicados— queda apagado sin un solo test en rojo. Inviértela: si la tabla existe y el cuerpo no se ha sustituido, **lanza**, con el mensaje que diga qué consulta escribir. `T-015` ya dejó `RecordedReceipts::existFor()` hecho así; cópialo.

## 7. Hecho cuando

- [ ] `activity_log` con **`organization_id`** y global scope; su caso en la suite de tenancy.
- [ ] `AuditEvent` con la **lista cerrada completa** de RB-1.
- [ ] Un mecanismo que **obligue a conectar cada evento cuando exista su Action**, sin lista escrita a mano.
- [ ] Las Actions de **equipo, ajustes y cuentas de cobro** auditan, con `properties` de **antes y después**.
- [ ] `causer` admite `User`, `PlatformUser` y **nulo**; job sin actor deja el nombre del proceso en la descripción.
- [ ] Un **fallo de auditoría no revierte la operación**: se degrada a descripción mínima y va al log de errores.
- [ ] `properties` **sin** contraseñas, tokens, contenido de archivos ni `account_id`.
- [ ] La vista es de solo lectura, paginada por servidor, con sus filtros; un `member` recibe **`403`**.
- [ ] **Fuga:** una organización no ve la auditoría de otra.
- [ ] `format:check` en `npm run check` y en CI; `.prettierignore` para `vendor/**`; el repo limpio.
- [ ] `PublishedCatalogs` **lanza** cuando exista la tabla sin cuerpo sustituido.
- [ ] Toda ruta nueva declara **superficie** y **si sobrevive a la suspensión**; `tenancy:audit` y la suite en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Qué mecanismo usaste para que un evento sin emisor no se quede olvidado, y cómo se comporta cuando aparezca su Action.
2. Cómo degradas un fallo de auditoría sin romper la operación, y cómo lo probaste.
3. Qué forma tiene `causer` para admitir los tres tipos de actor sin que H8 tenga que migrar.
4. Qué eventos quedan hoy sin emisor y de qué tarea es cada bloque.
5. Cómo quedaron los dos cierres de H0, y si al formatear apareció algo que no fuera ruido.
6. **H0 queda cerrado con esta tarea:** di cuáles de sus 16 requisitos quedan cerrados de verdad y cuáles siguen parcialmente abiertos, con el motivo. No lo des por bueno sin comprobarlo.
7. Nada más. No propongas siguientes pasos.
