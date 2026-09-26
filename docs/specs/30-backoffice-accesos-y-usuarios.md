# Módulo 30 — Acceso del equipo y auditoría de la organización (`BOF`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md)
> Requisitos: `REQ-BOF-001`, `REQ-BOF-005` · Ruta base: `/backoffice` (guard `web`)
>
> **Reorganización del pivote.** Este módulo se reduce a dos requisitos porque el resto encontró un hogar mejor:
>
> | ID original | Destino |
> |---|---|
> | `REQ-BOF-002` — seeder del superadmin | **Eliminado**; reaparece como seeder de plataforma en [`REQ-PLT-001`](51-backoffice-de-plataforma.md) |
> | `REQ-BOF-003` — invitación de administradores | Trasladado a [`REQ-ORG-004`](04-tenancy-y-organizaciones.md) (invitación de equipo con tope de plan) |
> | `REQ-BOF-004` — desactivación de administradores | Trasladado a [`REQ-ORG-005`](04-tenancy-y-organizaciones.md) |
> | `REQ-BOF-006` — ajustes globales | Trasladado a [`REQ-ORG-006`](04-tenancy-y-organizaciones.md) (ajustes por organización) |
>
> Los IDs trasladados **no se reutilizan**: quedan como huecos permanentes en la numeración, conforme a la convención del índice.

## Contratos compartidos del módulo

```php
/** Usuario del equipo de una organización. */
final class User extends Authenticatable
{
    use BelongsToOrganization;

    // organization_id, name, email, password, role, disabled_at, must_change_password, invited_at

    public function isOwner(): bool { /* … */ }
    public function isActive(): bool { /* … */ }
}
```

```ts
export interface AuthenticatedTeamMember {
  readonly name: string;
  readonly email: string;
  readonly role: 'owner' | 'member';
  readonly mustChangePassword: boolean;
  readonly organization: OrganizationBrand;
}
```

---

### REQ-BOF-001 · Acceso protegido al backoffice de la organización

**Historia de usuario**
> Como miembro del equipo de un negocio, quiero entrar con mi email y contraseña y caer directamente en el panel de mi negocio, sin tener que elegir de qué organización soy.

**Contratos de datos**
- Rutas `GET /backoffice/login`, `POST /backoffice/login`, `POST /backoffice/logout`, y el flujo de cambio de contraseña obligatorio.
- Middleware `auth:web` + `ResolveTenant` + `EnsureAdminIsActive` + `EnsureOrganizationIsActive` + `EnsurePasswordIsChanged`.
- `routes/backoffice.php` con el grupo completo.

**Reglas de negocio y precondiciones**
- RB-1 El guard es `web` sobre `users`, independiente de `customer`, `account` y `platform` (`REQ-IDN-004` RB-1).
- RB-2 **El login no pide la organización.** Como `users.email` es único en toda la plataforma (`REQ-ORG-001` RB-3), la organización se deriva del usuario autenticado y `ResolveTenant` la fija (`REQ-ORG-002` RB-4). Es la razón por la que ese email es único a nivel global y no por tenant.
- RB-3 Rate limit: **5 intentos** por (email + IP) cada 15 minutos; al superarse, `429` con `Retry-After`.
- RB-4 Un usuario con `disabled_at` no puede iniciar ni mantener sesión: el middleware la invalida en la siguiente petición.
- RB-5 Con `must_change_password = true`, toda navegación se redirige al formulario de cambio de contraseña.
- RB-6 Contraseñas con `Password::min(12)->mixedCase()->numbers()->uncompromised()`.
- RB-7 La sesión se regenera al autenticar y se invalida al cerrar; sesiones en Redis.
- RB-8 Los intentos fallidos se registran en log (no en `activity_log`) con email e IP, nunca la contraseña.
- RB-9 **Si la organización está `suspended`**, el login funciona pero el acceso queda **restringido a lectura de cobranza y aprobación de comprobantes** (`REQ-ORG-008` RB-3, `REQ-SUB-007` RB-4), con un aviso persistente y el enlace de pago. No se expulsa al equipo: se le deja cobrar y pagar.
- RB-10 Si la organización está `closed`, el login se rechaza con el motivo.
- RB-11 `/backoffice` no se enlaza desde el catálogo ni desde el portal, y se excluye del sitemap.
- RB-12 Recuperación de contraseña por email: **disponible**, porque en un SaaS self-serve no hay nadie a quien pedirle un restablecimiento. Enlace firmado de un solo uso, TTL 60 minutos, con la misma mecánica de `REQ-IDN-003`.

**Happy path**
1. El miembro abre `/backoffice/login` e introduce sus credenciales.
2. `ResolveTenant` fija su organización y aterriza en el dashboard de su negocio.
3. Todas sus consultas quedan filtradas por su `organization_id` sin que ningún controlador escriba un `where`.

**Sad paths y edge cases**
- SP-1 Credenciales inválidas → mensaje genérico, sin distinguir email inexistente de contraseña errónea.
- SP-2 Sexto intento fallido → `429`.
- SP-3 Usuario desactivado con sesión viva → cierre inmediato en la siguiente petición con aviso.
- SP-4 Cliente autenticado (`customer` o `account`) abriendo `/backoffice` → formulario de login, y sus rutas internas dan `403`.
- SP-5 `PlatformUser` intentando entrar con sus credenciales → falla: el guard consulta otra tabla (`REQ-PLT-001` RB-1).
- SP-6 Petición Inertia con sesión caducada → `409` que fuerza recarga completa al login.
- SP-7 Contraseña comprometida al cambiarla → `422` con la explicación.
- SP-8 Organización `suspended` → entra en modo restringido (RB-9), no se le niega el acceso.
- SP-9 Organización `closed` → login rechazado con el motivo (RB-10).
- SP-10 Enlace de recuperación caducado o ya usado → pantalla con CTA para pedir otro.

**Fuera de alcance inmediato**
- 2FA / TOTP.
- SSO corporativo.
- Restricción por IP o VPN.
- Pertenencia de un mismo usuario a varias organizaciones (y por tanto, selector de organización).

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/Auth/LoginController.php`, `app/Http/Controllers/Backoffice/Auth/PasswordResetController.php`, `app/Http/Middleware/EnsureAdminIsActive.php`, `app/Http/Middleware/EnsureOrganizationIsActive.php`, `app/Http/Middleware/EnsurePasswordIsChanged.php`, `routes/backoffice.php`, `config/auth.php`.
- Tipos esperados: `User`, `AuthenticatedTeamMember`, `AdminDisabledException`, `OrganizationNotActiveException`.
- Anti-`SHALLOW`: `LoginController::store()` (rate limit, autenticación, comprobación de usuario y de organización activos, regeneración de sesión) y `EnsureOrganizationIsActive::handle()` (modo restringido en `suspended`, rechazo en `closed`) superan 4 líneas.
- Tests: login válido e inválido con mensaje genérico; `429` al sexto intento; desactivado pierde la sesión; **el tenant se fija desde el usuario y las consultas quedan filtradas** (test de tenancy con dos organizaciones); `PlatformUser` no entra por este guard; cliente no accede; `suspended` permite aprobar comprobantes y no crear productos; `closed` rechaza; recuperación de contraseña de un solo uso.

---

### REQ-BOF-005 · Auditoría de acciones sensibles

**Historia de usuario**
> Como dueño, quiero saber quién aprobó, canceló o cambió un precio y cuándo, para responder ante un reclamo o un error de mi equipo.

**Contratos de datos**
- `spatie/laravel-activitylog` sobre `activity_log`, **con `organization_id`** y su global scope.
- Concern `LogsSensitiveActivity` usado por las `Action`.
- `AuditEvent` (enum con la lista cerrada de eventos).
- Ruta `GET /backoffice/auditoria` (solo `owner`), con filtros por actor, tipo y rango de fechas.

**Reglas de negocio y precondiciones**
- RB-1 Eventos auditados (lista cerrada):
  - **Cobranza:** aprobación y rechazo de comprobantes; registro y reasignación de pagos; cancelación y reprogramación de apartados; marcado de entrega.
  - **Catálogo:** cambios de precio, inicial, cuotas o frecuencia de un producto; cambio de estado; publicación, despublicación y expiración de catálogos.
  - **Clientes:** fusión de fichas; conflicto de identidad detectado; **actualización del contacto al reutilizar una ficha** (`REQ-IDN-001` RB-4, añadido antes de `T-043`); vinculación de una cuenta de consumidor (`REQ-ACC-004` RB-6).
  - **Organización:** cambios de ajustes, identidad y branding; alta, edición y desactivación de cuentas de cobro; invitación, revocación, cambio de rol, activación y desactivación de miembros.
  - **Suscripción:** cambio de plan, emisión y pago de facturas, aplicación de degradación, suspensión y reactivación.
  - **Plataforma:** **concesión y revocación de un acceso de soporte** (`REQ-PLT-005` RB-4) y cualquier acción que la plataforma ejecute sobre la suscripción del negocio (`REQ-PLT-004` RB-7).
- RB-2 Cada entrada guarda actor (`causer`), sujeto (`subject`), descripción y `properties` con el **antes y el después** de los campos relevantes.
- RB-3 Es **append-only**: la aplicación no expone edición ni borrado de entradas.
- RB-4 Escribir la auditoría **no puede romper la operación**: se registra dentro de la transacción, pero un fallo de serialización de `properties` se degrada a una descripción mínima en lugar de abortar.
- RB-5 Nunca contiene contraseñas, tokens, contenido de archivos, `account_id` ni datos de otras organizaciones (`REQ-ACC-007` RB-5).
- RB-5b **Se redacta después de comparar, nunca antes.** Redactar primero y diferenciar después hace que dos valores distintos se vean iguales —`[redactado]` contra `[redactado]`—, el campo desaparezca del diff por "no cambió", y **un cambio de contraseña no deje rastro ninguno**: justo el evento que más falta hace tener registrado. El antes y el después se calculan sobre los valores reales y se sustituyen al serializar; lo que la auditoría guarda de un campo sensible es **que cambió**, no a qué. Detectado en `T-016`.
- RB-6 **Las acciones de la plataforma sobre la organización son visibles en su auditoría** (RB-1, último punto). Es el mecanismo que hace defendible el modelo de soporte: el negocio siempre puede ver cuándo alguien de Apartar miró o tocó sus datos.
- RB-7 El actor puede ser un `User` de la organización, un `PlatformUser` (marcado como tal) o el sistema (`causer` nulo, con el nombre del proceso en la descripción).
- RB-8 Retención indefinida en v1; la purga queda documentada como extensión.
- RB-9 La vista es de solo lectura, paginada por servidor y exclusiva del `owner`.

> **La lista crece a 36 con `customer.contact_updated`, y el motivo es el que RB-1 existe para cubrir.** `REQ-IDN-001` RB-4 reutiliza la ficha cuando el documento ya existe en esa organización y **sobrescribe nombre, teléfono y correo** con los del formulario. Ese formulario es el del apartado: una superficie **pública y sin sesión**, donde lo único que acredita identidad es saberse el número de documento. Es decir, quien conozca la cédula de un cliente puede cambiar el teléfono y el correo con los que ese negocio lo contacta, y las notificaciones de su apartado dejan de llegarle. No se bloquea —SP-3 es explícito en que la venta no se detiene, y un cliente que se mudó o cambió de número es el caso normal—, así que **la auditoría es la única mitigación que queda**, y por eso no puede ser silenciosa. No se reutiliza `customer.identity_conflict` para esto: ese evento señala un nombre radicalmente distinto y debe seguir siendo raro; dispararlo en cada cambio de teléfono lo convertiría en ruido y dejaría de avisar de lo que avisa. Añadir el evento es el procedimiento que la propia RB-1 prescribe —entrada en la spec, no una constante suelta—, y `AuditEventTest` recorre el enum, así que el emisor tiene que declararse.

**Happy path**
1. Un `member` aprueba un comprobante.
2. Se registra `receipt.approved` con actor, comprobante, apartado y `properties` (`settled_amount`, `rate_applied`, cuotas afectadas).
3. El `owner` lo consulta filtrando por ese miembro y ese día.

**Sad paths y edge cases**
- SP-1 Acción ejecutada por un job sin usuario → `causer` nulo y descripción con el nombre del proceso.
- SP-2 `properties` muy grandes (reasignación de 36 cuotas) → se guarda un resumen acotado.
- SP-3 Fallo al escribir la auditoría → la operación de negocio se conserva y el fallo va al log de errores (RB-4).
- SP-4 `member` accediendo a `/backoffice/auditoria` → `403`.
- SP-5 Sujeto archivado o borrado lógicamente → la entrada sigue legible con el identificador y el nombre guardado.
- SP-6 **Entrada de auditoría de otra organización** → invisible por el global scope; verificado con test de fuga.
- SP-7 Acción de un `PlatformUser` bajo grant de soporte → aparece en la auditoría de la organización identificando que fue la plataforma, no un miembro del equipo.

**Fuera de alcance inmediato**
- Exportación de la auditoría.
- Sellado criptográfico de las entradas.
- Auditoría de lecturas y de accesos (más allá del grant de soporte).

**Gate de validación**
- Superficie esperada: `app/Support/Auditing/LogsSensitiveActivity.php`, `app/Domain/Auditing/AuditEvent.php`, `app/Http/Controllers/Backoffice/AuditLogController.php`, `config/activitylog.php`, y su uso en cada `Action` de la lista de RB-1.
- Tipos esperados: `LogsSensitiveActivity`, `AuditEvent`, `activity_log.organization_id`.
- Anti-`SHALLOW`: `LogsSensitiveActivity::audit()` (actor de tres tipos, sujeto, diff antes/después, degradación ante fallo, `organization_id`) supera 4 líneas junto a `AuditEvent`.
- Tests: cada evento de RB-1 escribe su entrada (un test por evento) **en la tarea que crea su `Action`, no en `T-016`** — `T-016` construye el mecanismo y cablea los diez eventos de Organización, que son los únicos con emisor; los otros veinticinco los cierra cada hito con su acción, y quien lo obliga es el test que recorre el enum exigiendo `LogsSensitiveActivity` y la mención del caso a toda clase emisora **que ya exista**. Por eso `REQ-BOF-005` queda cerrado **en mecanismo y no en cobertura**, y no es deuda oculta: es la única forma de no tener el enum a medias durante setenta tareas. `properties` con antes y después; job sin actor deja `causer` nulo; fallo de auditoría no revierte; `member` recibe `403`; **una organización no ve la auditoría de otra** (fuga); el grant de soporte aparece en la auditoría de la organización (test explícito de RB-6); no se filtran secretos ni `account_id`.
