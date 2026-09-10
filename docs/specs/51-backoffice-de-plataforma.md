# Módulo 51 — Backoffice de plataforma (`PLT`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Decisiones: `D-5`, `Q5` de [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md)
> Requisitos: `REQ-PLT-001` … `REQ-PLT-006` · Ruta base: `/plataforma` (guard `platform`)
>
> Es la única superficie que opera **sin tenant**. Por eso concentra el mayor riesgo de fuga del sistema y por eso cada uno de sus requisitos declara explícitamente qué puede y qué no puede ver.

## Contratos compartidos del módulo

```php
/**
 * Operador de la plataforma. Tabla e identidad completamente separadas de
 * los usuarios de organización: no comparten tabla, así que no existe
 * escalada posible entre ambos dominios.
 */
final class PlatformUser extends Authenticatable
{
    // name, email, password, disabled_at, must_change_password
    public function isActive(): bool { /* … */ }
}

/**
 * Acceso temporal y auditado a los datos de una organización concreta.
 *
 * Sin un grant vigente, el backoffice de plataforma solo ve agregados.
 */
final class SupportAccessGrant extends Model
{
    // platform_user_id, organization_id, reason, granted_at, expires_at, revoked_at

    public function isActive(): bool { /* … */ }
}
```

```ts
export interface OrganizationRow {
  readonly slug: string;
  readonly name: string;
  readonly planCode: string;
  readonly subscriptionStatus: string;
  readonly createdAt: string;
  /** Métricas agregadas: nunca datos personales de clientes finales. */
  readonly counts: {
    readonly users: number;
    readonly products: number;
    readonly publishedCatalogs: number;
    readonly customers: number;
    readonly activeLayaways: number;
    readonly subscribers: number;
  };
  readonly volume: {
    readonly committed: MoneyView;
    readonly collectedLast30Days: MoneyView;
    readonly outstanding: MoneyView;
  };
  readonly lastActivityAt: string | null;
  readonly hasActiveSupportAccess: boolean;
}

export interface PlatformMetrics {
  readonly organizations: { readonly total: number; readonly active: number; readonly suspended: number; readonly newLast30Days: number };
  readonly byPlan: Readonly<Record<string, number>>;
  readonly mrr: MoneyView;
  readonly invoices: { readonly pendingReview: number; readonly overdue: number };
  readonly network: {
    readonly accounts: number;
    readonly subscriptions: number;
    /** Cuentas que siguen catálogos de más de un negocio: el KPI del efecto de red. */
    readonly multiBusinessAccounts: number;
  };
  readonly gmv: { readonly committedLast30Days: MoneyView; readonly collectedLast30Days: MoneyView };
  readonly health: { readonly failedJobs: number; readonly rateAgeHours: number | null; readonly queueBacklog: number };
}
```

---

### REQ-PLT-001 · Acceso al backoffice de plataforma

**Historia de usuario**
> Como operador de Apartar, quiero un panel propio y separado del de los negocios, para operar la plataforma sin riesgo de mezclar identidades.

**Contratos de datos**
- Entidad `PlatformUser` (`platform_users`), guard `platform`, rutas `GET|POST /plataforma/login`, `POST /plataforma/logout`.
- Seeder `PlatformAdminSeeder`, leído de `.env`: `PLATFORM_ADMIN_NAME`, `PLATFORM_ADMIN_EMAIL`, `PLATFORM_ADMIN_PASSWORD`.
- Middleware `EnsurePlatformUserIsActive`.

**Reglas de negocio y precondiciones**
- RB-1 Tabla y guard **completamente separados** de `users` (`D-5`). No comparten tabla, así que un usuario de organización no puede escalar a la plataforma ni al revés, por construcción y no por comprobación.
- RB-2 El primer `PlatformUser` nace por **seeder** desde variables de entorno, idempotente, con `must_change_password = true`. Es el único uso legítimo de un seeder de identidad que sobrevive al pivote (el antiguo `REQ-BOF-002`).
- RB-3 Si falta cualquiera de las tres variables, el seeder **falla con un mensaje explícito**; nunca genera credenciales por defecto.
- RB-4 Misma política de contraseña que el resto (`min(12)`, mayúsculas y minúsculas, números, `uncompromised`) y mismo rate limit de login (5 intentos por 15 min).
- RB-5 `/plataforma` **no se enlaza desde ninguna otra superficie** y se excluye del sitemap.
- RB-6 Las sesiones de plataforma son independientes: tener sesión de plataforma no da acceso a ningún backoffice de organización sin un grant de soporte (`REQ-PLT-005`).
- RB-7 Un `PlatformUser` desactivado pierde la sesión en su siguiente petición.
- RB-8 No hay registro self-serve de operadores de plataforma: se crean por seeder o por otro operador ya existente.

**Happy path**
1. En el despliegue se define el `.env` y se ejecuta `db:seed --class=PlatformAdminSeeder`.
2. El operador entra a `/plataforma/login`, cambia su contraseña y accede al dashboard.

**Sad paths y edge cases**
- SP-1 Variables ausentes en el seeder → aborta listando las que faltan.
- SP-2 Contraseña débil en `.env` → aborta con el motivo.
- SP-3 Segunda ejecución del seeder → idempotente, no duplica ni sobrescribe.
- SP-4 Email que ya existe como `User` de organización o como `Account` → **permitido**: son tablas distintas.
- SP-5 Usuario de organización probando `/plataforma/login` con sus credenciales → falla, porque el guard consulta otra tabla.
- SP-6 Operador desactivado con sesión abierta → cierre en la siguiente petición.
- SP-7 Sexto intento de login → `429`.

**Fuera de alcance inmediato**
- 2FA para operadores de plataforma (recomendado para una iteración temprana, dado el alcance de estos accesos).
- Roles dentro de la plataforma.
- Restricción por IP.

**Gate de validación**
- Superficie esperada: `app/Models/PlatformUser.php`, `database/seeders/PlatformAdminSeeder.php`, `app/Http/Controllers/Platform/Auth/LoginController.php`, `app/Http/Middleware/EnsurePlatformUserIsActive.php`, `config/auth.php` (guard `platform`), `routes/platform.php`.
- Tipos esperados: `PlatformUser`, guard `platform`, `PlatformAdminSeeder`.
- Anti-`SHALLOW`: `PlatformAdminSeeder::run()` (validación de variables, política de contraseña, idempotencia) supera 4 líneas.
- Tests: el seeder crea con `must_change_password`; variables ausentes abortan; idempotente; credenciales de `User` no sirven en el guard `platform` (test explícito de RB-1); email compartido entre los tres dominios permitido; desactivado pierde la sesión.

---

### REQ-PLT-002 · Directorio de organizaciones

**Historia de usuario**
> Como fundador, quiero ver mis clientes —los negocios— con su plan, su actividad y su volumen, para saber quién crece, quién no arranca y quién está a punto de irse.

**Contratos de datos**
- Ruta `GET /plataforma/organizaciones` y `GET /plataforma/organizaciones/{organization}`.
- `OrganizationDirectoryQuery`, tipo `OrganizationRow`.
- Tabla server-driven (`REQ-BOF-045`).

**Reglas de negocio y precondiciones**
- RB-1 El directorio muestra, por organización: nombre, slug, plan, estado de suscripción, fecha de alta, **conteos y volúmenes agregados**, y última actividad.
- RB-2 **Nunca muestra datos personales de clientes finales** sin un grant de soporte activo (`Q5`, `REQ-PLT-005`): ni nombres, ni cédulas, ni teléfonos, ni emails, ni comprobantes. Solo agregados.
- RB-3 Los volúmenes se expresan en USD, calculados desde los balances materializados (`REQ-PAY-008`) por consultas agregadas con `withoutTenantScope()`, que aquí es legítimo y está acotado a este módulo (`REQ-ORG-002` RB-4).
- RB-4 Filtros: por plan, por estado de suscripción, por actividad (sin actividad en 30 días), por tamaño y por facturas vencidas.
- RB-5 Señales de riesgo destacadas visualmente, porque son la razón de existir de esta pantalla: organizaciones **al límite de una métrica** (candidatas a subir de plan), **sin actividad en 30 días** (candidatas a abandono) y con **facturas vencidas**.
- RB-6 Orden por defecto: actividad reciente descendente.
- RB-7 El detalle de una organización muestra su ficha, su suscripción, su historial de facturas, su consumo por métrica y su actividad agregada — de nuevo, **sin datos personales de clientes finales**.
- RB-8 Toda consulta de este módulo que use `withoutTenantScope()` está en `app/Queries/Platform` o `app/Actions/Platform`, y en ningún otro sitio (verificable por búsqueda en el gate de `REQ-QAX-008`).

**Happy path**
1. El fundador abre el directorio y ordena por facturas vencidas.
2. Ve tres negocios en `past_due` y dos al 95 % de su límite de productos.
3. Abre el detalle de uno: 240 productos, 180 apartados activos, $12.400 comprometidos, $3.100 cobrados en 30 días.

**Sad paths y edge cases**
- SP-1 Organización sin actividad → aparece con conteos en cero, marcada como candidata a abandono.
- SP-2 Organización suspendida → aparece con su estado y sus volúmenes históricos.
- SP-3 Organización cerrada (`closed`) → visible con filtro explícito, no en el listado por defecto.
- SP-4 Miles de organizaciones → paginación de servidor sin `count(*)` total, con las agregaciones calculadas por lote y cacheadas 5 minutos.
- SP-5 Intento de ver un comprobante de un cliente final desde plataforma sin grant → `403` con la indicación de solicitar acceso de soporte.
- SP-6 Agregación que falla para una organización concreta → su fila muestra guiones y el resto del listado carga.

**Fuera de alcance inmediato**
- Edición de datos de una organización desde plataforma (salvo suscripción y estado).
- Notas de CRM y seguimiento comercial.
- Exportación del directorio.

**Gate de validación**
- Superficie esperada: `app/Queries/Platform/OrganizationDirectoryQuery.php`, `app/Http/Controllers/Platform/OrganizationController.php`, `app/Http/Resources/Platform/OrganizationRowResource.php`, `resources/js/pages/platform/organizations/Index.tsx`.
- Tipos esperados: `OrganizationRow`, `OrganizationDirectoryQuery`, `OrganizationDirectoryFilters`.
- Anti-`SHALLOW`: `OrganizationDirectoryQuery::list()` (agregaciones por lote sin `N+1`, señales de riesgo, filtros, caché) supera 4 líneas junto a su clase.
- Tests: el `Resource` **no contiene** ningún campo personal de cliente final (assert de forma, explícito de RB-2); las señales de riesgo se calculan bien en los tres casos; sin `N+1`; ver un comprobante sin grant da `403`; `withoutTenantScope` solo aparece en `app/Queries/Platform` y `app/Actions/Platform`.

---

### REQ-PLT-003 · Métricas de plataforma

**Historia de usuario**
> Como fundador, quiero ver de un golpe cómo va el negocio —cuántos negocios pagan, cuánto se transa en la plataforma y si la red crece—, para decidir dónde poner el esfuerzo.

**Contratos de datos**
- Ruta `GET /plataforma`. Props `PlatformMetrics`.
- `PlatformMetricsQuery::compute(): PlatformMetrics`, cacheada 5 minutos.

**Reglas de negocio y precondiciones**
- RB-1 Bloques de métricas: **organizaciones** (total, activas, suspendidas, nuevas en 30 días), **distribución por plan**, **MRR**, **facturas** (por revisar y vencidas), **red** (cuentas, suscripciones y cuentas multi-negocio), **volumen transado** (comprometido y cobrado en 30 días) y **salud del sistema**.
- RB-2 El **MRR** se calcula sobre suscripciones `active` y `past_due` (una factura vencida sigue siendo ingreso esperado); las `suspended` y `cancelled` se excluyen. La definición se muestra en la UI para que la cifra no se malinterprete.
- RB-3 `multiBusinessAccounts` —cuentas que siguen catálogos de más de un negocio— es **el KPI del efecto de red** y la métrica que justifica la capa de cuentas de [15](15-cuentas-de-cliente-y-feed.md). Si no crece, el feed no está funcionando y conviene saberlo pronto.
- RB-4 El **volumen transado** suma `payments.amount_cents_usd` de todas las organizaciones; es la medida del valor que la plataforma mueve, no de su ingreso.
- RB-5 Todas las métricas son **agregadas y anónimas**: ninguna identifica a un cliente final.
- RB-6 Cálculo por consultas agregadas, una por métrica como máximo, cacheadas 5 minutos, con fallo aislado por métrica (una que falle no rompe el dashboard).
- RB-7 Sin series temporales ni gráficas en esta iteración: cifras y comparación con el período anterior.

**Happy path**
1. El fundador entra a `/plataforma` y ve: 47 negocios (39 activos), 8 en Pro, MRR $142, 3 facturas por revisar.
2. En Red: 1.230 cuentas, 2.890 suscripciones, **214 cuentas multi-negocio**.
3. En Volumen: $86.400 comprometidos y $19.200 cobrados en 30 días.

**Sad paths y edge cases**
- SP-1 Plataforma recién instalada → todas las cifras en cero con textos neutros.
- SP-2 Redis caído → se calculan sin caché, más lento.
- SP-3 Una métrica que falla → guion en su tarjeta, el resto carga (RB-6).
- SP-4 Sin tasa de cambio → los volúmenes se muestran solo en USD (`REQ-FX-005`).
- SP-5 Organizaciones cerradas → excluidas de los conteos activos, incluidas en el histórico de volumen.
- SP-6 Volumen creciendo mientras el MRR no → señal de que la matriz de planes no captura valor; se muestran juntos para que la comparación salte a la vista.

**Fuera de alcance inmediato**
- Series temporales, cohortes y embudos.
- Métricas de retención y churn calculadas.
- Exportación e integración con herramientas de BI.

**Gate de validación**
- Superficie esperada: `app/Queries/Platform/PlatformMetricsQuery.php`, `app/Http/Controllers/Platform/DashboardController.php`, `resources/js/pages/platform/Dashboard.tsx`.
- Tipos esperados: `PlatformMetrics`, `PlatformMetricsQuery`.
- Anti-`SHALLOW`: `PlatformMetricsQuery::compute()` (siete bloques, definición de MRR, cuentas multi-negocio, caché, aislamiento de fallos) supera 4 líneas junto a su clase.
- Tests: una prueba por bloque con datos sembrados; MRR incluye `past_due` y excluye `suspended` (test explícito de RB-2); `multiBusinessAccounts` cuenta cuentas con catálogos de dos organizaciones; ninguna métrica expone datos personales; fallo aislado por métrica; caché de 5 minutos.

---

### REQ-PLT-004 · Gestión de suscripciones y cobros

**Historia de usuario**
> Como operador, quiero revisar los comprobantes de suscripción y ajustar el plan de un negocio cuando hace falta, para cobrar y resolver casos sin tocar la base de datos.

**Contratos de datos**
- Rutas `GET /plataforma/suscripciones`, `GET /plataforma/facturas`, `POST /plataforma/facturas/{invoice}/aprobar`, `POST .../rechazar`, `POST /plataforma/organizaciones/{organization}/plan`.
- `ApproveSubscriptionReceiptAction`, `RejectSubscriptionReceiptAction`, `ChangePlanAction` (`REQ-SUB-005`, `REQ-SUB-006`).

**Reglas de negocio y precondiciones**
- RB-1 Bandeja de comprobantes de suscripción con la **misma mecánica** que la de comprobantes de clientes (`REQ-PAY-004`): orden FIFO de pendientes, marca de atrasados a las 48 h y alerta en tiempo real. No se reimplementa: comparte el patrón y los colaboradores.
- RB-2 El detalle de revisión muestra el comprobante, la factura, el plan, el histórico de pagos del negocio y el equivalente en bolívares a la tasa vigente.
- RB-3 Aprobar liquida la factura, activa la suscripción, extiende el período y levanta la suspensión (`REQ-SUB-006` RB-6). Rechazar exige motivo.
- RB-4 El operador puede **cambiar el plan de una organización manualmente** (concesión comercial, plan negociado, corrección de un error), con motivo obligatorio y auditoría. No requiere factura.
- RB-5 El operador puede **anular** una factura (`void`) con motivo, y **emitir** una factura manual fuera del ciclo.
- RB-6 El operador puede **conceder cortesía**: extender el período sin pago, con motivo y fecha límite. Es la herramienta de retención más simple y evita tocar la base de datos a mano.
- RB-7 Toda acción de este requisito se audita en `platform_activity_log` **y** es visible para la organización afectada en su historial de facturas: nada de lo que la plataforma hace sobre el dinero de un negocio es invisible para él.
- RB-8 Aprobar un comprobante de suscripción **no** requiere grant de soporte: es una operación sobre datos de la plataforma (facturas y comprobantes de suscripción), no sobre los clientes finales del negocio.

**Happy path**
1. Entra un comprobante de suscripción; el operador lo ve en la bandeja con el badge en tiempo real.
2. Verifica la referencia bancaria, ajusta la tasa si el pago fue de otro día y aprueba.
3. La factura queda `paid`, la suscripción `active` y el negocio recibe la notificación.

**Sad paths y edge cases**
- SP-1 Dos operadores aprobando el mismo comprobante → el primero gana; el segundo recibe "ya fue procesado por…", igual que en `REQ-PAY-005` SP-3.
- SP-2 Comprobante por menos del importe → aprobación parcial; la factura queda pendiente por la diferencia (`REQ-SUB-006` SP-2).
- SP-3 Cambio manual de plan sin motivo → `422`.
- SP-4 Cortesía concedida y luego revocada → se recorta el período con motivo y auditoría; nunca deja el período en el pasado sin avisar al negocio.
- SP-5 Anulación de una factura ya pagada → `422`: primero habría que revertir el pago, operación que **no** existe en esta iteración y se documenta como límite.
- SP-6 Aprobación sin tasa disponible para un comprobante en bolívares → `422` (`REQ-FX-005` SP-2).
- SP-7 Cambio de plan a la baja desde plataforma → sigue las reglas de `REQ-SUB-008` (degradación no destructiva), sin atajos.

**Fuera de alcance inmediato**
- Reverso de un pago de suscripción aprobado.
- Notas de crédito y prorrateo.
- Cobro automático y pasarela.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Platform/InvoiceController.php`, `app/Http/Controllers/Platform/SubscriptionController.php`, `app/Actions/Subscription/ApproveSubscriptionReceiptAction.php`, `app/Actions/Platform/GrantCourtesyPeriodAction.php`.
- Tipos esperados: `InvoiceRow`, `SubscriptionStatus`, `GrantCourtesyPeriodAction`, `InvoiceAlreadyPaidException`.
- Anti-`SHALLOW`: `GrantCourtesyPeriodAction::handle()` (extensión con motivo, tope de fecha, auditoría doble) supera 4 líneas; la aprobación reutiliza el método largo de `REQ-SUB-006`.
- Tests: bandeja con orden FIFO y marca a las 48 h; doble aprobación falla; aprobación parcial deja pendiente la diferencia; cambio manual de plan exige motivo y se audita en ambos lados; cortesía extiende y revocar recorta; anular una factura pagada rechazado; la organización ve en su historial toda acción de plataforma.

---

### REQ-PLT-005 · Acceso de soporte auditado

**Historia de usuario**
> Como operador, quiero poder entrar a los datos de un negocio para resolverle un problema; y como dueño de ese negocio, quiero que eso quede registrado y que yo lo vea.

**Contratos de datos**
- Entidad `SupportAccessGrant` (`support_access_grants`): `platform_user_id`, `organization_id`, `reason`, `granted_at`, `expires_at`, `revoked_at?`.
- `GrantSupportAccessAction`, `RevokeSupportAccessAction`; middleware `RequiresSupportAccess`.
- Ajuste `platform_settings.support_access_ttl_hours` (8).

**Reglas de negocio y precondiciones**
- RB-1 Sin un grant **vigente**, el backoffice de plataforma solo ve agregados (`REQ-PLT-002` RB-2). Con grant vigente, el operador accede a los datos de **esa** organización en modo lectura.
- RB-2 Conceder exige **motivo obligatorio** (20–500 caracteres) y caduca solo a las `support_access_ttl_hours`. No hay accesos permanentes.
- RB-3 El grant es **por organización**: acceder a dos negocios exige dos grants, cada uno con su motivo. No existe un "modo dios" global.
- RB-4 **La organización lo ve.** El grant se registra en el `activity_log` de la organización (`REQ-BOF-005`), aparece como aviso en su backoffice mientras esté vigente y se le notifica por email al `owner` al concederse. Este punto es el que hace defendible todo el modelo: el negocio sabe siempre cuándo alguien de la plataforma mira sus datos.
- RB-5 El acceso es de **solo lectura**. El operador **nunca** puede aprobar un comprobante de un cliente final, crear productos, cancelar apartados ni escribir en el chat en nombre del negocio. Actuar por el negocio exigiría suplantación, y eso no existe en esta iteración.
- RB-6 El grant se puede **revocar** antes de caducar, por el propio operador o por otro.
- RB-7 Todo acceso a datos bajo un grant se registra: qué organización, qué operador, qué secciones consultó y cuándo.
- RB-8 El grant **no** permite ver `account_id` de los clientes ni nada de la capa de cuentas de otras organizaciones (`REQ-ACC-007` RB-4): la separación entre negocios se mantiene incluso para el soporte.
- RB-9 Un grant caducado o revocado deja de dar acceso **en la petición siguiente**, sin necesidad de cerrar sesión.

**Happy path**
1. Un negocio reporta que un balance no cuadra.
2. El operador concede un grant con el motivo "revisión de balance reportado por el cliente, ticket 42", válido 8 horas.
3. El `owner` recibe el email y ve el aviso en su backoffice.
4. El operador revisa el apartado y los pagos, encuentra la causa y revoca el grant.
5. En la auditoría del negocio queda el acceso con su motivo y su duración.

**Sad paths y edge cases**
- SP-1 Acceso a datos de una organización sin grant → `403` con el enlace para solicitarlo, nunca datos parciales.
- SP-2 Grant caducado a mitad de la navegación → la siguiente petición da `403` (RB-9).
- SP-3 Motivo demasiado corto → `422`.
- SP-4 Intento de escribir bajo un grant (aprobar un comprobante) → `403`; el acceso es de solo lectura (RB-5).
- SP-5 Grant concedido a una organización cerrada → permitido: puede ser necesario para resolver una incidencia posterior a la baja.
- SP-6 Notificación al `owner` que falla → **no** revierte el grant, pero el aviso en el backoffice y la entrada de auditoría siguen presentes, que son los canales que no dependen del correo.
- SP-7 Dos grants simultáneos del mismo operador para la misma organización → el segundo extiende el vigente en lugar de duplicarlo.
- SP-8 Operador desactivado con grant vigente → pierde el acceso al perder la sesión (`REQ-PLT-001` RB-7).

**Fuera de alcance inmediato**
- Suplantación (actuar como un usuario del negocio).
- Acceso de escritura bajo grant.
- Aprobación del grant por parte del negocio antes de concederse (mejora recomendada para una iteración temprana).
- Grants para varias organizaciones a la vez.

**Gate de validación**
- Superficie esperada: `app/Models/SupportAccessGrant.php`, `app/Actions/Platform/GrantSupportAccessAction.php`, `app/Actions/Platform/RevokeSupportAccessAction.php`, `app/Http/Middleware/RequiresSupportAccess.php`, `app/Notifications/SupportAccessGrantedNotification.php`.
- Tipos esperados: `SupportAccessGrant`, `GrantSupportAccessAction`, `SupportAccessRequiredException`.
- Anti-`SHALLOW`: `GrantSupportAccessAction::handle()` (validación del motivo, TTL, extensión de un grant vigente, auditoría doble, notificación) y `RequiresSupportAccess::handle()` (vigencia, alcance por organización, solo lectura) superan 4 líneas.
- Tests: sin grant da `403` en cada sección de datos; con grant se lee; caducado deja de dar acceso a la petición siguiente; escritura bajo grant rechazada (test por cada operación de escritura); el grant aparece en el `activity_log` de la organización y en su backoffice (test explícito de RB-4); motivo corto rechazado; grant duplicado extiende; no expone `account_id`.

---

### REQ-PLT-006 · Salud del sistema

**Historia de usuario**
> Como operador, quiero enterarme de que algo se rompió antes de que me lo diga un cliente, para no operar a ciegas.

**Contratos de datos**
- Ruta `GET /plataforma/salud`. Props `SystemHealth`.
- `SystemHealthQuery::compute(): SystemHealth`.

```ts
export interface SystemHealth {
  readonly queue: { readonly pending: number; readonly failed: number; readonly oldestPendingMinutes: number | null };
  readonly exchangeRate: { readonly source: string; readonly ageHours: number | null; readonly isStale: boolean };
  readonly scheduledJobs: readonly { readonly command: string; readonly lastRunAt: string | null; readonly isOverdue: boolean }[];
  readonly storage: { readonly usedMb: number; readonly receiptsCount: number };
  readonly realtime: { readonly connected: boolean };
  readonly reconciliation: { readonly discrepancies: number; readonly lastRunAt: string | null };
}
```

**Reglas de negocio y precondiciones**
- RB-1 Señales incluidas: profundidad y antigüedad de la cola, `failed_jobs`, antigüedad de la tasa de cambio, última ejecución de cada comando programado con marca de atrasado, almacenamiento total, estado del WebSocket y discrepancias del último `layaways:reconcile`.
- RB-2 Las **discrepancias de reconciliación** (`REQ-PAY-008` RB-4) son la señal más importante de la pantalla: significan que algún balance no cuadra con sus pagos, y eso afecta a dinero real de clientes finales.
- RB-3 Un comando programado se marca **atrasado** si no corrió en el doble de su intervalo declarado.
- RB-4 La tasa de cambio se marca obsoleta a partir de 24 h (`REQ-FX-005` RB-3).
- RB-5 Solo lectura: esta pantalla no ofrece acciones destructivas. Reintentar jobs fallidos queda fuera de alcance y se hace por consola.
- RB-6 Cálculo directo, sin caché: es una pantalla de diagnóstico y una cifra vieja sería peor que una consulta lenta.
- RB-7 Sin alertas automáticas por email o push en esta iteración; la pantalla se consulta. Se documenta como la mejora más rentable de operación.

**Happy path**
1. El operador abre Salud y ve la cola en 0 pendientes, 2 fallidos, tasa de hace 12 minutos.
2. Los nueve comandos programados aparecen con su última ejecución al día.
3. Reconciliación: 0 discrepancias.

**Sad paths y edge cases**
- SP-1 `failed_jobs` creciendo → visible de inmediato; el detalle se investiga por consola.
- SP-2 Un comando programado sin correr en dos intervalos → marcado atrasado, señal de que el contenedor de colas está caído (`REQ-INF-003` SP-3).
- SP-3 Tasa de cambio de más de 24 h → marcada obsoleta, con el aviso de que las proyecciones en bolívares están desactualizadas para todas las organizaciones.
- SP-4 Discrepancias mayores que cero → se muestra el conteo y el comando exacto para investigarlas con `--fix`.
- SP-5 Redis caído → la pantalla lo refleja en lugar de fallar entera.
- SP-6 Reverb caído → `realtime.connected` en falso; el chat sigue funcionando por polling (`REQ-CHT-003` RB-3) y así se indica.

**Fuera de alcance inmediato**
- Alertas automáticas por email, push o Telegram.
- Reintento de jobs fallidos desde la UI.
- Métricas de latencia y APM.
- Página de estado pública.

**Gate de validación**
- Superficie esperada: `app/Queries/Platform/SystemHealthQuery.php`, `app/Http/Controllers/Platform/HealthController.php`, `resources/js/pages/platform/Health.tsx`.
- Tipos esperados: `SystemHealth`, `SystemHealthQuery`.
- Anti-`SHALLOW`: `SystemHealthQuery::compute()` (seis señales, marca de atrasado por intervalo declarado, tolerancia a componentes caídos) supera 4 líneas junto a su clase.
- Tests: cada señal con datos sembrados; comando atrasado detectado al doble del intervalo; tasa obsoleta a las 24 h; discrepancias reportadas; Redis y Reverb caídos no rompen la pantalla; sin caché.
