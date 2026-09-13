# Módulo 04 — Tenancy, organizaciones y equipo (`ORG`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Decisiones: `D-1` … `D-7` de [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md)
> Requisitos: `REQ-ORG-001` … `REQ-ORG-008`
>
> **Módulo fundacional.** `REQ-ORG-002` es la invariante más importante del sistema: sin aislamiento correcto, todo lo demás es irrelevante.

## Contratos compartidos del módulo

```php
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    /** Solo el owner gestiona suscripción, facturación, equipo y ajustes globales. */
    public function canManageBilling(): bool { /* … */ }
    public function canManageTeam(): bool { /* … */ }
    public function canManageSettings(): bool { /* … */ }
}

enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';   // impago prolongado: el equipo pierde el backoffice
    case Closed = 'closed';         // baja solicitada; datos conservados
}

/**
 * Organización activa de la petición.
 *
 * La resuelve el middleware desde el subdominio de sesión (backoffice), desde el
 * catálogo (superficie pública) o desde la ficha de cliente (portal acotado).
 */
final class TenantContext
{
    public function organization(): Organization { /* … */ }
    public function organizationId(): int { /* … */ }
    public function hasOrganization(): bool { /* … */ }
    public function setOrganization(Organization $organization): void { /* … */ }
    public function forget(): void { /* … */ }
}

/**
 * Aísla un modelo por organización.
 *
 * Aplica un global scope que filtra por organization_id y rellena la columna
 * al crear. Quitarlo exige withoutTenantScope() explícito.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void { /* … */ }
    public function organization(): BelongsTo { /* … */ }
    public function scopeWithoutTenantScope(Builder $query): Builder { /* … */ }
}
```

```ts
export interface OrganizationBrand {
  readonly name: string;
  readonly slug: string;
  readonly logoUrl: string | null;
  readonly brandColor: string | null;
  /** false en plan Free: se muestra el sello "Hecho con Apartar". */
  readonly brandingEnabled: boolean;
}

export interface TeamMember {
  readonly id: number;
  readonly name: string;
  readonly email: string;
  readonly role: 'owner' | 'member';
  readonly isActive: boolean;
  readonly invitedAt: string | null;
}

export interface PaymentAccountView {
  readonly id: number;
  readonly method: string;
  readonly label: string;          // "Pago móvil · Banesco · 0414-1234567"
  readonly holderName: string;
  readonly holderDocument: string;
  readonly identifier: string;
  readonly instructions: string | null;
}
```

---

### REQ-ORG-001 · Registro self-serve de una organización

**Historia de usuario**
> Como dueño de un negocio, quiero registrarme y empezar a usar la plataforma en minutos sin hablar con nadie, para probar si me sirve antes de pagar.

**Contratos de datos**
- Rutas `GET /registro`, `POST /registro`.
- `RegisterOrganizationAction(RegisterOrganizationData $data): Organization`.
- Crea en una transacción: `Organization` + `User` (`owner`) + `Subscription` en plan Free (`REQ-SUB-002`) + `organization_settings` con los valores por defecto.

```php
final readonly class RegisterOrganizationData
{
    public function __construct(
        public string $organizationName,
        public string $organizationSlug,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPassword,
        public ?string $phone,
    ) {}
}
```

**Reglas de negocio y precondiciones**
- RB-1 El registro crea **cuatro cosas en una sola transacción**: organización, usuario `owner`, suscripción Free y ajustes por defecto. Si algo falla, no queda ninguna organización a medias.
- RB-2 `organizations.slug` es único en toda la plataforma, se autogenera del nombre y es editable por el `owner` mientras no tenga catálogos publicados.
- RB-3 `users.email` es único **en toda la plataforma**, no por organización: una misma persona no puede tener cuentas de equipo en dos negocios con el mismo correo (simplifica el login, que no pide organización).
- RB-4 Contraseña con `Password::min(12)->mixedCase()->numbers()->uncompromised()`.
- RB-5 El email del `owner` requiere **verificación** antes de poder publicar un catálogo; el resto del backoffice funciona sin verificar (no se bloquea la exploración del producto).
- RB-6 El plan inicial es **siempre Free**, sin período de prueba de plan superior: la capa gratuita ya es el período de prueba y evita el acantilado de la expiración.
- RB-7 `RIF/documento` de la organización es **opcional** en el registro y obligatorio solo para emitir su primera factura de suscripción (`REQ-SUB-006`).
- RB-8 Sin invitación previa ni lista de espera: el registro es abierto, con rate limit de 5 registros por hora y por IP.

**Happy path**
1. El dueño entra a `/registro`, escribe el nombre del negocio (el slug se sugiere solo), su nombre, email y contraseña.
2. `RegisterOrganizationAction` crea todo en una transacción y autentica la sesión en el guard `web`.
3. Aterriza en `/backoffice` con un checklist de arranque: verificar email, crear cuenta de cobro, cargar el primer producto, armar el primer catálogo.

**Sad paths y edge cases**
- SP-1 Slug ya tomado → se sugiere una variante libre (`ferreteria-el-tornillo-2`) en lugar de solo rechazar.
- SP-2 Email ya registrado como usuario de otra organización → `422` con la indicación de usar otro correo o iniciar sesión.
- SP-3 Email ya registrado como `Account` de cliente final → **permitido**: son dominios de identidad distintos (`D-8`, `REQ-ACC-001`).
- SP-4 Contraseña comprometida → `422` explicando el motivo.
- SP-5 Fallo a mitad de la transacción → rollback total: sin organización huérfana ni suscripción sin dueño.
- SP-6 Rate limit excedido → `429`.
- SP-7 Nombre de negocio que produce un slug vacío (solo emojis o signos) → se exige un slug manual válido.

**Fuera de alcance inmediato**
- Registro con Google para el usuario del negocio (el `owner` usa email y contraseña).
- Onboarding guiado paso a paso más allá del checklist.
- Importación de inventario en el registro.

**Gate de validación**
- Superficie esperada: `app/Actions/Organization/RegisterOrganizationAction.php`, `app/Http/Controllers/Marketing/RegisterController.php`, `app/Http/Requests/Marketing/RegisterOrganizationRequest.php`, `app/Models/Organization.php`, `resources/js/pages/marketing/Register.tsx`.
- Tipos esperados: `Organization`, `OrganizationStatus`, `OrganizationRole`, `RegisterOrganizationData`, `SlugTakenException`.
- Anti-`SHALLOW`: `RegisterOrganizationAction::handle()` (cuatro creaciones en transacción, generación de slug con desambiguación, autenticación) supera 4 líneas en su archivo.
- Tests: crea las cuatro entidades en una transacción; rollback total ante fallo; slug duplicado sugiere variante; email de equipo duplicado rechazado; email que existe como `Account` aceptado; plan inicial Free; rate limit al sexto registro.

---

### REQ-ORG-002 · Resolución del tenant y aislamiento de datos

**Historia de usuario**
> Como dueño de un negocio, quiero certeza de que ningún otro negocio de la plataforma puede ver mi inventario, mis clientes ni mi cobranza, para confiarle mi cartera a esta herramienta.

**Contratos de datos**
- `TenantContext` (singleton por petición), `BelongsToOrganization` (trait + global scope), `TenantResolver`.
- Middleware `ResolveTenant` (backoffice, desde la sesión), `ResolveCatalogTenant` (superficie pública, desde el catálogo), `ResolvePortalTenant` (portal, desde la ficha de cliente).

**Reglas de negocio y precondiciones**
- RB-1 **Ninguna consulta de una tabla del tenant se ejecuta sin filtro por `organization_id`.** El global scope del trait lo garantiza por defecto; saltárselo requiere `withoutTenantScope()` explícito, permitido **solo** en jobs de plataforma y comandos de mantenimiento.
- RB-2 El trait también **rellena `organization_id` al crear**: ningún código de aplicación asigna el tenant a mano, para que no pueda equivocarse.
- RB-3 **Todo índice único de negocio incluye `organization_id`.** Las tres excepciones son deliberadas y están documentadas: `users.email` (login sin organización), `catalogs.slug` (URL pública global) y `layaways.code` (prueba de acceso al portal).
- RB-4 Vías de resolución del tenant, en este orden y sin ambigüedad:
  | Superficie | Fuente del tenant |
  |---|---|
  | `/backoffice/*` | `user->organization_id` de la sesión |
  | `/c/{slug}` y `/{org-slug}` | el catálogo o la organización de la URL |
  | `/mi-cuenta/*` | la ficha de cliente en contexto (`PortalContext`) |
  | `/plataforma/*` | **ninguno**: opera sin tenant, con `withoutTenantScope()` |
  | Jobs | el tenant se serializa **explícitamente** en el payload del job |
- RB-5 Un job **nunca** hereda el tenant del proceso que lo encoló: lo recibe como parámetro y lo fija con `TenantContext::setOrganization()` al arrancar. Un job sin tenant fijado que toque una tabla del tenant **debe fallar**, no operar sin filtro.
- RB-6 Toda ruta que reciba un modelo del tenant por *route binding* lo resuelve **ya filtrado**: un `id` de otra organización produce `404`, nunca `403` (no se revela la existencia del recurso ajeno).
- RB-7 Las relaciones entre tablas del tenant nunca cruzan organizaciones: se valida en la `Action` que los modelos relacionados comparten `organization_id`.

**Happy path**
1. Un `member` de la Ferretería entra a `/backoffice/productos`.
2. `ResolveTenant` fija el tenant desde su sesión.
3. `Product::query()` devuelve solo los productos de la Ferretería, sin que el controlador escriba un solo `where`.

**Sad paths y edge cases**
- SP-1 Petición al backoffice sin tenant resoluble (sesión corrupta) → cierre de sesión y redirección al login, **nunca** consulta sin filtro.
- SP-2 URL con el `id` de un producto de otra organización → `404`.
- SP-3 Job encolado sin tenant que toca una tabla del tenant → excepción `MissingTenantContextException` y job fallido; es preferible un job caído a una fuga.
- SP-4 Consulta de plataforma que necesita ver todo → exclusión explícita del scope, permitida solo en la **lista cerrada de `REQ-QAX-008` RB-4b**; verificado por revisión y por ese gate.
- SP-5 Relación cruzada intentada (asignar a un catálogo un producto de otra organización) → `422` por la validación de RB-7, además del `404` del binding.
- SP-6 Tenant fijado y luego cambiado a mitad de la petición → `TenantContext::setOrganization()` es idempotente para el mismo valor y lanza excepción si intenta cambiar a otro dentro de la misma petición.
- SP-7 Cola compartida procesando jobs de dos organizaciones seguidas → el tenant se fija y se limpia por job (`forget()` en el `finally`), sin fuga entre ejecuciones.

**Fuera de alcance inmediato**
- Base de datos por tenant.
- Cifrado por organización de datos en reposo.
- Regiones de datos por país.

**Gate de validación**
- Superficie esperada: `app/Domain/Tenancy/TenantContext.php`, `app/Domain/Tenancy/BelongsToOrganization.php`, `app/Domain/Tenancy/TenantResolver.php`, `app/Http/Middleware/ResolveTenant.php`, `app/Http/Middleware/ResolveCatalogTenant.php`, `app/Jobs/Concerns/RunsInTenantContext.php`.
- Tipos esperados: `TenantContext`, `BelongsToOrganization`, `TenantResolver`, `MissingTenantContextException`, `CrossTenantRelationException`.
- Anti-`SHALLOW`: `BelongsToOrganization::bootBelongsToOrganization()` (global scope + relleno al crear + macro de exclusión) y `TenantResolver::resolve()` (las cuatro vías de RB-4) superan 4 líneas junto a sus tipos.
- Tests: el global scope filtra en **todos** los modelos del tenant (test parametrizado sobre el listado real, no sobre una cifra fija); crear rellena `organization_id`; binding ajeno da `404`; job sin tenant falla; `withoutTenantScope` solo aparece en las rutas permitidas (test de arquitectura por búsqueda); relación cruzada rechazada; el tenant se limpia entre jobs.

---

### REQ-ORG-003 · Roles del equipo: `owner` y `member`

**Historia de usuario**
> Como dueño, quiero que mis empleados operen el día a día sin poder tocar la facturación ni dar de alta usuarios, para delegar sin perder el control.

**Contratos de datos**
- `users.role` (`OrganizationRole`), `OrganizationRole::canManageBilling()/canManageTeam()/canManageSettings()`.
- `OrganizationPolicy`, `UserPolicy`, `SubscriptionPolicy`.

**Reglas de negocio y precondiciones**
- RB-1 Solo dos roles (`D-4`). `owner`: suscripción, facturación, equipo, ajustes de organización, cuentas de cobro y branding. `member`: productos, catálogos, clientes, cobranza, comprobantes y chat.
- RB-2 Toda organización tiene **al menos un `owner` activo**; la comprobación es transaccional con bloqueo, igual que en `REQ-ORG-005`.
- RB-3 Puede haber varios `owner`. Un `owner` puede promover a un `member` y degradarse a sí mismo, salvo que sea el último.
- RB-4 Las secciones exclusivas del `owner` **no se renderizan** para un `member`, y además están protegidas por policy en el servidor (la ocultación en UI nunca es el control de acceso).
- RB-5 Los usuarios de plataforma (`platform_users`) **no tienen rol de organización** y nunca aparecen en el equipo: son tablas distintas (`D-5`).
- RB-6 El rol se resuelve siempre desde la base de datos en la petición, nunca desde un claim de la sesión, para que una degradación tenga efecto inmediato.

**Happy path**
1. El `owner` invita a un empleado como `member`.
2. El `member` entra y ve productos, catálogos, clientes, cobranza y chat; no ve Suscripción, Equipo ni Ajustes.
3. Intenta la URL de facturación directamente y recibe `403`.

**Sad paths y edge cases**
- SP-1 `member` llamando a un endpoint de `owner` → `403`.
- SP-2 Último `owner` intentando degradarse → `422`.
- SP-3 Dos `owner` degradándose a la vez → el bloqueo serializa y el segundo falla, preservando uno.
- SP-4 `member` promovido a `owner` con la sesión abierta → el permiso aplica en su siguiente petición (RB-6).
- SP-5 `owner` desactivado que era el único → imposible por `REQ-ORG-005` RB-3.

**Fuera de alcance inmediato**
- RBAC granular por recurso o por acción.
- Roles personalizados.
- Permisos temporales.

**Gate de validación**
- Superficie esperada: `app/Domain/Tenancy/OrganizationRole.php`, `app/Policies/OrganizationPolicy.php`, `app/Policies/UserPolicy.php`, `app/Http/Middleware/EnsureIsOwner.php`.
- Tipos esperados: `OrganizationRole`, `LastOwnerException`.
- Anti-`SHALLOW`: `OrganizationRole` declara `canManageBilling()`, `canManageTeam()` y `canManageSettings()` con lógica > 4 líneas en su propio archivo.
- Tests: matriz de los dos roles contra las seis capacidades; `member` recibe `403` en cada endpoint de `owner`; último `owner` protegido con concurrencia; el rol se lee de BD y no de la sesión.

---

### REQ-ORG-004 · Invitación de miembros del equipo

**Historia de usuario**
> Como dueño, quiero invitar a mi equipo por correo para que cada uno tenga su propio acceso, para saber quién aprobó cada pago.

**Contratos de datos**
- `InviteTeamMemberAction(string $name, string $email, OrganizationRole $role, User $actor)`.
- Rutas `GET /backoffice/equipo`, `POST /backoffice/equipo`, `GET /invitacion/{token}`, `POST /invitacion/{token}`.
- Token de un solo uso, TTL **48 h**.

**Reglas de negocio y precondiciones**
- RB-1 Solo el `owner` invita (`REQ-ORG-003` RB-1).
- RB-2 La invitación **consume una unidad del límite `users` del plan** (`REQ-SUB-004`): se cuenta desde que se envía, no desde que se acepta, para que no se puedan enviar 50 invitaciones en plan Free.
- RB-3 El invitado se crea con `invited_at` y sin contraseña utilizable; no puede entrar hasta establecerla.
- RB-4 Enlace de 48 h, de un solo uso, reenviable (el reenvío invalida el anterior).
- RB-5 Al aceptar, el invitado fija su contraseña con la política de `REQ-BOF-001` y queda activo.
- RB-6 Una invitación pendiente se puede **revocar**, lo que libera la unidad del límite.
- RB-7 Toda invitación, aceptación, revocación y cambio de rol se audita (`REQ-BOF-005`).

**Happy path**
1. El `owner` abre Equipo, ve "1 de 5 usuarios" e invita a un `member`.
2. El invitado recibe el correo, abre el enlace, fija su contraseña y entra al backoffice de esa organización.

**Sad paths y edge cases**
- SP-1 Límite del plan alcanzado → `422` con el plan que lo desbloquea y enlace a Suscripción (`REQ-SUB-004`).
- SP-2 Email ya usado en cualquier organización → `422` (`REQ-ORG-001` RB-3).
- SP-3 Enlace caducado o ya usado → pantalla con CTA para pedir reenvío al `owner`; el token se marca consumido.
- SP-4 `member` intentando invitar → `403`.
- SP-5 Invitación aceptada después de que la organización quedara suspendida por impago → el usuario se crea, pero no puede entrar al backoffice hasta regularizar (`REQ-SUB-007`).
- SP-6 Reenvío múltiple → solo el último enlace funciona.
- SP-7 Bajada de plan con más miembros que el nuevo límite → los existentes **se conservan** y solo se bloquea invitar (`REQ-SUB-008`).

**Fuera de alcance inmediato**
- Invitación masiva por CSV.
- Dominios de correo permitidos por organización.
- SSO.

**Gate de validación**
- Superficie esperada: `app/Actions/Organization/InviteTeamMemberAction.php`, `app/Http/Controllers/Backoffice/TeamController.php`, `app/Http/Controllers/Auth/AcceptInvitationController.php`, `app/Notifications/TeamInvitationNotification.php`.
- Tipos esperados: `InviteTeamMemberAction`, `TeamInvitation`, `TeamMember`, `PlanLimitReachedException`.
- Anti-`SHALLOW`: `InviteTeamMemberAction::handle()` (autorización, comprobación de límite, creación, token, invalidación del anterior, notificación, auditoría) supera 4 líneas.
- Tests: solo `owner` invita; la invitación pendiente cuenta para el límite; revocar la libera; token de 48 h de un solo uso; reenvío invalida el anterior; bajada de plan no expulsa a los existentes.

---

### REQ-ORG-005 · Desactivación y reactivación de miembros

**Historia de usuario**
> Como dueño, quiero cortar el acceso de alguien que dejó el equipo sin borrar el rastro de lo que hizo, para preservar la auditoría de la cobranza.

**Contratos de datos**
- `users.disabled_at`; `DisableTeamMemberAction`, `EnableTeamMemberAction`.
- Rutas `POST /backoffice/equipo/{user}/desactivar` y `.../activar`.

**Reglas de negocio y precondiciones**
- RB-1 La baja es **lógica**; nunca se borra un usuario, para preservar `reviewed_by`, `registered_by` y la auditoría.
- RB-2 Al desactivar, la sesión activa de esa persona deja de funcionar en su siguiente petición.
- RB-3 No se puede desactivar ni degradar al **último `owner` activo**; comprobación transaccional con bloqueo.
- RB-4 Nadie se desactiva a sí mismo.
- RB-5 Un usuario desactivado **libera** su unidad del límite `users` del plan.
- RB-6 Reactivar restaura el acceso con `must_change_password = true` por precaución.
- RB-7 Las referencias históricas siguen mostrando su nombre con la marca "inactivo".

**Happy path**
1. El `owner` desactiva a un `member`; su sesión se corta en el siguiente clic.
2. El consumo del plan baja de 3 a 2 usuarios.
3. Los comprobantes que aprobó siguen mostrando su nombre marcado como inactivo.

**Sad paths y edge cases**
- SP-1 Autodesactivación → `422`.
- SP-2 Último `owner` → `422` con el motivo.
- SP-3 Dos desactivaciones concurrentes que dejarían la organización sin `owner` → el bloqueo preserva uno.
- SP-4 Reactivar cuando el límite del plan ya está lleno → `422`: reactivar consume unidad como invitar.
- SP-5 Login de un desactivado → mensaje genérico de credenciales, sin revelar la desactivación.
- SP-6 Miembro con un comprobante abierto sin resolver → se desactiva igual; el comprobante vuelve a la cola.

**Fuera de alcance inmediato**
- Borrado definitivo y anonimización de usuarios.
- Transferencia de trabajo asignado (no hay asignación).

**Gate de validación**
- Superficie esperada: `app/Actions/Organization/DisableTeamMemberAction.php`, `app/Actions/Organization/EnableTeamMemberAction.php`, `app/Http/Middleware/EnsureAdminIsActive.php`, `app/Models/User.php`.
- Tipos esperados: `DisableTeamMemberAction`, `LastOwnerException`, `PlanLimitReachedException`.
- Anti-`SHALLOW`: `DisableTeamMemberAction::handle()` (autorización, autocomprobación, conteo transaccional de `owner`, escritura, ajuste de consumo, auditoría) supera 4 líneas.
- Tests: no se puede autodesactivar; último `owner` protegido con concurrencia; libera unidad del plan; reactivar con límite lleno rechazado; sesión cortada; histórico conserva el nombre.

---

### REQ-ORG-006 · Ajustes, identidad y vitrina de la organización

**Historia de usuario**
> Como dueño, quiero configurar cómo opera mi negocio —tasa, plazos, frecuencia de cuotas— y cómo se ve mi catálogo, para que la herramienta se adapte a cómo vendo yo.

**Contratos de datos**
- `organization_settings` (§9.1 de la spec maestra), `Settings` con caché por organización, `SettingKey`.
- `UpdateOrganizationAction` (nombre, slug, zona horaria, RIF, logo, color, vitrina).
- Rutas `GET|PUT /backoffice/ajustes`, `GET /{org-slug}` (vitrina pública).

**Reglas de negocio y precondiciones**
- RB-1 Solo el `owner` edita ajustes (`REQ-ORG-003`).
- RB-2 Cada clave valida contra reglas declaradas en `SettingKey`; una clave desconocida se ignora silenciosamente.
- RB-3 La caché de ajustes es **por organización** (`settings:{organization_id}`) y se invalida en cada escritura; sin caché se lee de BD.
- RB-4 Los cambios afectan **solo al futuro**: `grace_period_days` se reevalúa a diario, pero `down_payment_window_hours` no mueve `expires_at` ya fijados. Cada clave declara su alcance en la UI.
- RB-5 **Branding** (logo y color en el catálogo) es función de plan (`REQ-SUB-004`): en Free se ignora y se muestra el sello "Hecho con Apartar".
- RB-6 La **vitrina** en `/{org-slug}` lista **solo los catálogos publicados y listados de esa organización**; está desactivada por defecto (`storefront_enabled`). No es un directorio de la plataforma: no existe una página que liste organizaciones.
- RB-7 La zona horaria de la organización rige todo cálculo de vencimiento y todo horario de notificación de sus clientes.
- RB-8 Todo cambio de ajuste, identidad o branding se audita con antes y después.

**Happy path**
1. El `owner` fija la frecuencia por defecto en 15 días, el período de gracia en 5 y sube su logo.
2. Activa la vitrina y comparte `apartar.store/ferreteria-el-tornillo` en su bio de Instagram.
3. Sus catálogos publicados aparecen ahí con su logo y su color.

**Sad paths y edge cases**
- SP-1 Valor fuera de rango → `422`.
- SP-2 `member` intentando editar → `403`.
- SP-3 Branding editado en plan Free → se guarda pero **no se aplica**, con aviso claro de que requiere Pro; al subir de plan se activa sin volver a configurarlo.
- SP-4 Vitrina activada sin catálogos publicados → página con estado vacío, no `404`.
- SP-5 Slug cambiado con catálogos publicados → `422` (`REQ-ORG-001` RB-2): rompería los enlaces de la vitrina ya compartidos.
- SP-6 Cambio de zona horaria con apartados vigentes → las fechas de vencimiento ya fijadas no se mueven; solo cambia la presentación y el horario de los envíos futuros.
- SP-7 Redis caído → los ajustes se leen de BD.
- SP-8 Colisión entre `/{org-slug}` y una ruta reservada (`registro`, `backoffice`, `plataforma`, `c`, `mi-cuenta`, `api`) → lista negra de slugs reservados validada al registrar y al renombrar.

**Fuera de alcance inmediato**
- Dominio propio (plan Business, iteración siguiente).
- Plantillas de catálogo o temas visuales.
- Ajustes por catálogo.

**Gate de validación**
- Superficie esperada: `app/Actions/Organization/UpdateOrganizationAction.php`, `app/Actions/Settings/UpdateSettingsAction.php`, `app/Support/Settings.php`, `app/Domain/Settings/SettingKey.php`, `app/Http/Controllers/Catalog/OrganizationStorefrontController.php`, `app/Support/ReservedSlugs.php`.
- Tipos esperados: `SettingKey`, `Settings`, `OrganizationBrand`, `ReservedSlugs`, `UnknownSettingException`.
- Anti-`SHALLOW`: `SettingKey::validationRules()` y `defaultValue()` (los 11 mapas) junto al enum; `Settings::get()` (caché por organización, fallback y default) junto a su clase.
- Tests: cada clave valida su rango; caché aislada entre organizaciones (test de fuga); branding no se aplica en Free y sí al subir de plan; slug reservado rechazado; slug bloqueado con catálogos publicados; vitrina lista solo lo propio.

---

### REQ-ORG-007 · Cuentas de cobro de la organización

**Historia de usuario**
> Como dueño, quiero declarar mis datos bancarios y de pago móvil, para que mis clientes sepan exactamente dónde pagarme sin tener que preguntármelo.

**Contratos de datos**
- Entidad `PaymentAccount` (`payment_accounts`): `organization_id`, `method` (`PaymentMethod`), `bank?`, `holder_name`, `holder_document`, `identifier`, `instructions?`, `is_active`, `position`.
- `UpsertPaymentAccountAction`; rutas `GET|POST|PUT|DELETE /backoffice/cuentas-de-cobro`.
- Tipo `PaymentAccountView`.

**Reglas de negocio y precondiciones**
- RB-1 Concepto nuevo del pivote (`D-7`): en la plataforma cada organización declara dónde le pagan, y el catálogo se lo muestra al cliente al apartar (`REQ-CAT-003`, `REQ-PAY-001`).
- RB-2 El contenido de `identifier` depende del método: teléfono en `pago_movil`, número de cuenta en `transferencia`, correo en `zelle`, wallet en `binance_pay`, y en efectivo la dirección o la nota de retiro.
- RB-3 `holder_name` y `holder_document` son obligatorios: en pago móvil venezolano el titular y su cédula son parte del dato de pago.
- RB-4 Al menos **una** cuenta activa es requisito para **publicar un catálogo** (`REQ-CAT-022`): sin ella el cliente no sabría dónde pagar.
- RB-5 El número de cuentas está limitado por el plan (`max_payment_accounts`).
- RB-6 Una cuenta **no se borra si tiene comprobantes asociados**: se desactiva (`is_active = false`), conservando la trazabilidad histórica de a dónde se pagó.
- RB-7 `instructions` es texto libre corto (≤ 300 caracteres) que se muestra al cliente ("indica tu cédula en la referencia").
- RB-8 Todo alta, edición y desactivación se audita.

**Happy path**
1. El `owner` crea una cuenta de pago móvil con banco, titular, cédula y teléfono.
2. Publica su catálogo; al apartar, el cliente ve esos datos y a dónde transferir.
3. Al subir el comprobante, el cliente indica **a qué cuenta** pagó (`REQ-PAY-001`).

**Sad paths y edge cases**
- SP-1 Publicar un catálogo sin ninguna cuenta activa → `422` con el enlace para crearla.
- SP-2 Límite del plan alcanzado → `422` con el plan que lo desbloquea.
- SP-3 Borrar una cuenta con comprobantes → se convierte en desactivación, informándolo.
- SP-4 Desactivar la última cuenta activa con catálogos publicados → **permitido con advertencia**: no se despublican catálogos, pero se avisa de que los clientes no verán dónde pagar; aparece como alerta en el dashboard.
- SP-5 `identifier` con formato inválido para su método (teléfono no venezolano en pago móvil) → `422`.
- SP-6 Dos cuentas idénticas → advertencia, permitido (hay negocios con dos cuentas en el mismo banco).

**Fuera de alcance inmediato**
- Verificación de la cuenta contra el banco.
- Conciliación automática por extracto bancario.
- Cuentas de cobro por catálogo.

**Gate de validación**
- Superficie esperada: `app/Models/PaymentAccount.php`, `app/Actions/Organization/UpsertPaymentAccountAction.php`, `app/Http/Controllers/Backoffice/PaymentAccountController.php`, `app/Rules/ValidPaymentIdentifier.php`.
- Tipos esperados: `PaymentAccount`, `PaymentAccountView`, `PaymentMethod`, `ValidPaymentIdentifier`, `PaymentAccountInUseException`.
- Anti-`SHALLOW`: `ValidPaymentIdentifier::validate()` (formato por método, > 4 líneas) y `UpsertPaymentAccountAction::handle()` (límite de plan, validación, orden, auditoría) junto a sus tipos.
- Tests: un caso por método con `identifier` válido e inválido; publicar sin cuenta activa rechazado; límite de plan; borrado con comprobantes se degrada a desactivación; desactivar la última avisa sin despublicar.

---

### REQ-ORG-008 · Suspensión, cierre y conservación de datos

**Historia de usuario**
> Como dueño, quiero saber qué pasa con mis datos y con los apartados de mis clientes si dejo de pagar o me doy de baja, para no sentir que pierdo mi información.

**Contratos de datos**
- `organizations.status` (`OrganizationStatus`), `suspended_at`, `deleted_at` (`SoftDeletes`).
- `SuspendOrganizationAction`, `ReactivateOrganizationAction`, `CloseOrganizationAction`.

**Reglas de negocio y precondiciones**
- RB-1 **Regla no negociable (Q7):** la suspensión por impago corta el acceso del **equipo** al backoffice; **nunca** interrumpe un apartado en curso, el portal del cliente final, la subida de comprobantes ni el catálogo de los apartados vigentes. Si un negocio deja de pagar, el perjudicado no puede ser el cliente que le debe dinero.
- RB-2 En estado `suspended`: los catálogos se **despublican** (deja de captar nuevos apartados), pero los apartados existentes siguen vivos y visibles para sus clientes, con los datos de cobro de la organización intactos.
- RB-3 En `suspended` el equipo mantiene acceso de **solo lectura** a la cobranza y **sí puede aprobar comprobantes**: cobrar lo que ya le deben no puede quedar bloqueado por deberte una suscripción.
- RB-4 Reactivar (al regularizar) restaura el estado anterior **sin republicar automáticamente** los catálogos: el `owner` decide cuáles vuelven, para no republicar una campaña vencida.
- RB-5 El cierre voluntario (`closed`) lo solicita el `owner`, exige que **no haya apartados activos** y conserva los datos en `SoftDeletes`. La eliminación definitiva es una operación manual de plataforma, no automática.
- RB-6 Un cierre con apartados activos se rechaza con la lista de los que faltan por completar o cancelar: no se puede abandonar a clientes con una deuda en curso.
- RB-7 Toda suspensión, reactivación y cierre se audita en la organización y en la plataforma.

**Happy path**
1. Una organización deja de pagar; tras la gracia pasa a `suspended` (`REQ-SUB-007`).
2. Sus catálogos se despublican; sus 12 clientes con apartados siguen pagando y subiendo comprobantes con normalidad.
3. El `owner` paga, la organización se reactiva y él elige republicar dos catálogos.

**Sad paths y edge cases**
- SP-1 Cliente final intentando apartar en un catálogo de una organización suspendida → el catálogo no está publicado, así que da `410`; sus apartados previos siguen accesibles.
- SP-2 Cierre solicitado con apartados activos → `422` con la lista.
- SP-3 Equipo intentando crear productos en `suspended` → `403` con el mensaje de regularización y el enlace de pago.
- SP-4 Reactivación tras meses → catálogos con `expires_at` pasado quedan `Expired` y el `owner` debe renovar la fecha para republicarlos.
- SP-5 Organización cerrada cuyos clientes conservan sesión de portal → siguen viendo su historial en solo lectura; no pueden subir comprobantes nuevos porque no hay nadie para aprobarlos, y se les informa.
- SP-6 Suspensión durante un job en curso (exportación) → el job termina; los siguientes no se encolan.

**Fuera de alcance inmediato**
- Exportación completa de datos al cerrar (portabilidad).
- Eliminación definitiva automatizada.
- Transferencia de una organización a otro dueño.

**Gate de validación**
- Superficie esperada: `app/Actions/Organization/SuspendOrganizationAction.php`, `app/Actions/Organization/CloseOrganizationAction.php`, `app/Http/Middleware/EnsureOrganizationIsActive.php`, `app/Domain/Tenancy/OrganizationStatus.php`.
- Tipos esperados: `OrganizationStatus`, `SuspendOrganizationAction`, `OrganizationHasActiveLayawaysException`.
- Anti-`SHALLOW`: `SuspendOrganizationAction::handle()` (despublicación de catálogos, preservación de apartados, restricción del equipo a lectura y aprobación, auditoría) supera 4 líneas; `OrganizationStatus::allowsTeamAccess()`/`allowsCustomerAccess()` junto al enum.
- Tests: suspender no toca apartados ni el portal del cliente (test explícito de la regla de RB-1); en `suspended` se puede aprobar comprobantes pero no crear productos; cierre con apartados activos rechazado; reactivar no republica; catálogo de suspendida da `410` pero el portal sigue.
