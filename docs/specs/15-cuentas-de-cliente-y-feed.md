# Módulo 15 — Cuentas de consumidor, suscripción a catálogos y feed (`ACC`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Diseño: §4 de [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md)
> Requisitos: `REQ-ACC-001` … `REQ-ACC-007`
>
> Este módulo introduce la **segunda identidad** del cliente final: la cuenta global de consumidor, distinta e independiente de su ficha comercial en cada negocio ([10-identidad-y-autenticacion.md](10-identidad-y-autenticacion.md)). La distinción es la clave de todo el módulo, y de ella se derivan casi todas sus reglas.

## Contratos compartidos del módulo

```php
/**
 * Identidad global de consumidor.
 *
 * Representa a una persona que navega la plataforma y sigue catálogos.
 * NO contiene documento ni teléfono: no es una identidad comercial y no
 * puede, por sí sola, dar acceso a ninguna deuda.
 */
final class Account extends Authenticatable
{
    // display_name, email, avatar_url, locale, last_login_at

    /** @return HasMany<AccountIdentity> */
    public function identities(): HasMany { /* … */ }

    /** @return HasMany<CatalogSubscription> */
    public function subscriptions(): HasMany { /* … */ }

    /**
     * Fichas comerciales vinculadas, una por organización como máximo.
     *
     * @return HasMany<Customer>
     */
    public function customers(): HasMany { /* … */ }
}

enum AccountProvider: string { case Google = 'google'; }

/** Vía por la que una ficha comercial quedó vinculada a una cuenta. Se audita. */
enum LinkOrigin: string
{
    case LayawayCreation = 'layaway_creation';   // tenía sesión al apartar
    case VerifiedClaim = 'verified_claim';       // documento + código de apartado
}

/**
 * Identidad efectiva del portal.
 *
 * Una sesión de cuenta ve todas las fichas vinculadas; una sesión acotada
 * ve exactamente una. El portal nunca consulta fichas fuera de este contexto.
 */
final readonly class PortalContext
{
    public function __construct(
        public ?Account $account,
        public ?Customer $scopedCustomer,
    ) {}

    /** @return Collection<int, Customer> */
    public function customers(): Collection { /* … */ }
    public function isScoped(): bool { /* … */ }
    public function canUseFeed(): bool { /* … */ }
    public function organizationIds(): array { /* … */ }
}
```

```ts
export interface AccountSession {
  readonly displayName: string;
  readonly email: string;
  readonly avatarUrl: string | null;
  readonly subscriptionsCount: number;
  /** Negocios donde tiene ficha comercial vinculada. */
  readonly linkedBusinesses: readonly { readonly slug: string; readonly name: string }[];
}

export interface SubscriptionRow {
  readonly catalogSlug: string;
  readonly catalogName: string;
  readonly businessName: string;
  readonly coverUrl: string | null;
  readonly productsCount: number;
  readonly newProductsCount: number;   // añadidos en los últimos 14 días
  readonly isMuted: boolean;
  readonly isActive: boolean;          // false si el catálogo se despublicó o expiró
}

export interface FeedItem {
  readonly productSlug: string;
  readonly productName: string;
  readonly price: MoneyView;
  readonly downPayment: MoneyView;
  readonly installmentsCount: number;
  readonly image: ImageSet | null;
  readonly catalogSlug: string;
  readonly catalogName: string;
  readonly businessName: string;
  readonly addedAt: string;
  readonly isNew: boolean;
  readonly inStock: boolean;
}
```

---

### REQ-ACC-001 · Cuenta de consumidor con Google

**Historia de usuario**
> Como comprador, quiero una cuenta para seguir los catálogos que me comparten y tener mis compras en un solo lugar, sin llenar otro formulario ni recordar otra contraseña.

**Contratos de datos**
- Entidades `Account` (`accounts`) y `AccountIdentity` (`account_identities`), ambas de **plataforma** (sin `organization_id`).
- `AuthenticateAccountAction(GoogleUserData $data): Account`.
- Rutas `GET /cuenta/google/redirect`, `GET /cuenta/google/callback`, `POST /mi-cuenta/salir`.
- Guard `account` sobre `accounts`.

**Reglas de negocio y precondiciones**
- RB-1 **Google es el único proveedor** en esta iteración, y es **obligatorio** para tener cuenta (así se decidió). El modelo queda preparado con `account_identities` y `AccountProvider` para añadir otros después sin migración.
- RB-2 `accounts` **no tiene documento ni teléfono**. Es una identidad de navegación: por sí sola no da acceso a ningún apartado, saldo ni comprobante (`REQ-ACC-004`).
- RB-3 La cuenta se identifica por `UNIQUE(provider, provider_id)`, **nunca por email**: el email de Google puede cambiar y no es clave.
- RB-4 Solo se solicitan los scopes `openid email profile`. Se exige `email_verified`.
- RB-5 Los datos de perfil (`display_name`, `avatar_url`) se **refrescan** desde Google en cada inicio de sesión; no son editables en la plataforma (no hay perfil que mantener).
- RB-6 Una `Account` y un `User` de organización **pueden compartir el mismo email**: son dominios de identidad distintos (`D-8`). Un dueño de negocio puede además ser comprador en otro negocio.
- RB-7 Los guards `account`, `customer`, `web` y `platform` son mutuamente independientes; tener sesión en uno no otorga nada en otro.
- RB-8 Cerrar sesión no borra la cuenta, ni sus suscripciones, ni sus vínculos, ni el `visitor_id` de telemetría.
- RB-9 La creación de cuenta emite `account_created` (`REQ-TLM-001`).

**Happy path**
1. En un catálogo compartido, la persona pulsa "Seguir este catálogo".
2. Se le explica en una línea qué obtiene y se le redirige a Google.
3. Vuelve del callback: se crea la `Account` con su `AccountIdentity`, se abre sesión en el guard `account`, se completa la suscripción que originó el login (`REQ-ACC-002`) y se la devuelve **al mismo catálogo, en el mismo punto**.

**Sad paths y edge cases**
- SP-1 La persona cancela en Google → vuelve al catálogo con un aviso neutro; sigue pudiendo navegar y apartar sin cuenta.
- SP-2 Google no devuelve `email_verified` → se rechaza el acceso.
- SP-3 `state` inválido o caducado → `419` y reinicio del flujo desde el catálogo de origen.
- SP-4 Email de Google ya presente en otra `Account` con distinto `provider_id` (cuenta migrada) → se trata como cuenta **nueva**: la clave es `provider_id`, no el email (RB-3).
- SP-5 Segunda cuenta de Google de la misma persona → dos `Account` independientes; la plataforma no las une (no hay forma fiable de saber que son la misma persona sin documento, y el documento no vive aquí).
- SP-6 Google caído → el catálogo y el apartado siguen funcionando; solo no se puede seguir catálogos ni entrar al feed. Se informa así, sin error técnico.
- SP-7 Cuenta con sesión abierta en dos dispositivos → ambas válidas; nada que resolver.

**Fuera de alcance inmediato**
- Otros proveedores de identidad (Apple, email con contraseña, magic link para la cuenta).
- Perfil editable, foto propia, preferencias de privacidad.
- Borrado de cuenta autoservicio.
- Fusión de dos `Account` de la misma persona.

**Gate de validación**
- Superficie esperada: `app/Models/Account.php`, `app/Models/AccountIdentity.php`, `app/Domain/Account/AccountProvider.php`, `app/Actions/Account/AuthenticateAccountAction.php`, `app/Http/Controllers/Portal/Auth/GoogleAccountController.php`, `config/auth.php` (guard `account`).
- Tipos esperados: `Account`, `AccountIdentity`, `AccountProvider`, `AccountSession`, `GoogleUserData`.
- Anti-`SHALLOW`: `AuthenticateAccountAction::handle()` (búsqueda por `provider_id`, creación o refresco de perfil, apertura de sesión, continuación de la intención pendiente) supera 4 líneas en su archivo.
- Tests: crea cuenta con identidad; segundo login refresca perfil y no duplica; email repetido con otro `provider_id` crea cuenta nueva; email compartido con un `User` aceptado; `email_verified` ausente rechazado; la tabla `accounts` no tiene columnas de documento ni teléfono (assert de esquema); cancelar en Google no rompe la navegación.

---

### REQ-ACC-002 · Suscripción a un catálogo

**Historia de usuario**
> Como comprador, quiero seguir el catálogo que me compartieron, para enterarme de lo nuevo sin depender de que me vuelvan a mandar el enlace.

**Contratos de datos**
- Entidad `CatalogSubscription` (`catalog_subscriptions`): `account_id`, `catalog_id`, `subscribed_at`, `muted_at?`, `UNIQUE(account_id, catalog_id)`.
- `SubscribeToCatalogAction`, `UnsubscribeFromCatalogAction`, `MuteCatalogAction`.
- Rutas `POST /c/{slug}/seguir`, `DELETE /c/{slug}/seguir`, `PATCH /mi-cuenta/suscripciones/{catalog}`.
- Componente `<FollowCatalogButton />`.

**Reglas de negocio y precondiciones**
- RB-1 Seguir un catálogo **exige sesión de cuenta**. Si no hay, el botón inicia el flujo de Google guardando la intención, y al volver **completa la suscripción sola** (`REQ-ACC-001` happy path paso 3).
- RB-2 **Navegar y apartar NO exigen cuenta**, y esto no se negocia: el apartado sin registro es el requisito de conversión desde la primera iteración. Un login delante del formulario de apartado mataría la venta. Google se exige **solo** para seguir y para el feed.
- RB-3 Solo se puede seguir un catálogo **accesible** (`Listed` o `Unlisted`, publicado y no expirado). Un catálogo en borrador o expirado no se puede seguir.
- RB-4 La suscripción es **idempotente**: seguir dos veces no duplica ni falla.
- RB-5 Al **apartar con sesión de cuenta**, la persona queda suscrita automáticamente al catálogo de origen, se le informa en la confirmación y puede darse de baja con un clic. Quien acaba de comprometerse a un plan de pagos con ese negocio tiene una relación con él.
- RB-6 **Silenciar** (`muted_at`) conserva la suscripción y el catálogo en el feed, pero excluye a la cuenta de los avisos de novedades (`REQ-ACC-006`).
- RB-7 Darse de baja **elimina** la fila; no se conserva historial de suscripciones (dato de bajo valor y alto peso de privacidad).
- RB-8 Si el catálogo se despublica o expira, la suscripción **no se borra**: se marca inactiva en la UI y sus productos salen del feed. Si vuelve a publicarse, revive sola.
- RB-9 No hay límite de suscripciones por cuenta, ni de suscriptores por catálogo en ningún plan (§5 de [03](03-pivote-saas-impacto.md)): limitar el crecimiento de la audiencia sería limitar el valor de la red.
- RB-10 Se emiten `catalog_subscribed` y `catalog_unsubscribed` (`REQ-TLM-001`).

**Happy path**
1. La persona abre un catálogo por WhatsApp y pulsa "Seguir este catálogo".
2. No tiene sesión: se guarda la intención, entra con Google y vuelve al catálogo ya siguiéndolo, con el botón en estado "Siguiendo".
3. Desde `/mi-cuenta/suscripciones` ve el catálogo, cuántos productos tiene y cuántos son nuevos.

**Sad paths y edge cases**
- SP-1 Seguir un catálogo expirado → `422`; el botón no se muestra en ese estado.
- SP-2 Doble pulsación → idempotente (RB-4).
- SP-3 Intención de suscripción guardada y abandono del flujo de Google → la intención caduca en 15 minutos y no deja rastro.
- SP-4 Apartar sin sesión de cuenta → **no** se crea suscripción ni cuenta; el apartado se completa igual y el portal se accede por documento + código (`REQ-IDN-008`).
- SP-5 Catálogo del que se dio de baja y vuelve a seguir → fila nueva con `subscribed_at` actual.
- SP-6 Suscripción a un catálogo de una organización que luego se suspende → el catálogo se despublica y la suscripción queda inactiva (RB-8), sin borrarse.
- SP-7 Cuenta que sigue 200 catálogos → permitido; el feed pagina (`REQ-ACC-003`).

**Fuera de alcance inmediato**
- Seguir a una **organización** completa en lugar de a un catálogo (la vitrina de `REQ-ORG-006` mitiga la necesidad).
- Historial de suscripciones y bajas.
- Recomendación de catálogos para seguir.

**Gate de validación**
- Superficie esperada: `app/Models/CatalogSubscription.php`, `app/Actions/Account/SubscribeToCatalogAction.php`, `app/Actions/Account/UnsubscribeFromCatalogAction.php`, `app/Http/Controllers/Catalog/CatalogSubscriptionController.php`, `resources/js/features/account/components/FollowCatalogButton.tsx`, `resources/js/features/account/hooks/useFollowCatalog.ts`.
- Tipos esperados: `CatalogSubscription`, `SubscriptionRow`, `SubscribeToCatalogAction`, `CatalogNotSubscribableException`.
- Anti-`SHALLOW`: `SubscribeToCatalogAction::handle()` (validación de accesibilidad, idempotencia, telemetría) y `useFollowCatalog` (intención pendiente, redirección a Google, continuación, estado optimista) superan 4 líneas junto a sus tipos.
- Tests: suscripción idempotente; catálogo expirado rechazado; intención se completa tras el login y devuelve al mismo catálogo; apartar con cuenta suscribe y sin cuenta no; silenciar excluye del digest pero no del feed; despublicar deja la suscripción inactiva y republicar la revive.

---

### REQ-ACC-003 · Feed de novedades de catálogos seguidos

**Historia de usuario**
> Como comprador, quiero ver en un solo lugar lo nuevo de los negocios que sigo, para descubrir qué puedo apartar sin revisar enlaces uno por uno.

**Contratos de datos**
- Ruta `GET /mi-cuenta/novedades`. Props `{ items: Paginated<FeedItem>, subscriptions: SubscriptionRow[], filters }`.
- `FeedQuery::forAccount(Account $account, FeedFilters $filters): CursorPaginator`.
- Tipos `FeedItem`, `FeedFilters` (`catalogSlug?`, `q?`).

**Reglas de negocio y precondiciones**
- RB-1 El feed contiene **exclusivamente** productos publicados de catálogos que la cuenta sigue y que están accesibles. **No expone nada que el enlace del catálogo no expusiera ya**: es contenido público reagrupado, no información privilegiada.
- RB-2 Orden **cronológico descendente** por incorporación del producto al catálogo (`catalog_product.created_at`), acotado por `products.published_at` cuando este es posterior. Sin motor de recomendación: el orden es explicable.
- RB-3 Un producto presente en varios catálogos **de la misma organización** aparece **una vez**, atribuido al catálogo donde entró más recientemente. Si está en catálogos de organizaciones distintas, aparece una vez por organización (son ofertas comerciales distintas, con precios y planes distintos).
- RB-4 `isNew` es verdadero si entró al catálogo en los últimos **14 días**.
- RB-5 Filtros del feed: **por catálogo** y **por búsqueda de texto**. **No hay filtro por categoría**, y es deliberado: las categorías pertenecen a cada organización, así que "Motores" de un negocio no es comparable con "Motores" de otro. El filtro por categoría se mantiene **dentro** de cada catálogo (`REQ-CAT-006`), donde sí significa algo.
- RB-6 Paginación por cursor, 20 ítems por lote, con "cargar más".
- RB-7 Los importes se proyectan en bimoneda con la tasa vigente y la preferencia de la organización **dueña de cada producto** (`REQ-FX-003`): dos ítems del feed pueden estar proyectados con fuentes de tasa distintas, y cada uno lleva su sello.
- RB-8 Cada ítem enlaza a la ficha del producto **dentro de su catálogo**, preservando el contexto de la organización (`REQ-CAT-003`).
- RB-9 El feed exige sesión de cuenta; una sesión acotada (`REQ-IDN-008`) no lo ve y recibe la propuesta de conectar Google.
- RB-10 Se emiten `feed_viewed` y `feed_product_clicked` (`REQ-TLM-001`).
- RB-11 Consultar el feed **no** ejecuta consultas sin filtro de tenant: se acota por los `catalog_id` seguidos, que es un conjunto conocido y explícito (`withoutTenantScope()` restringido a esos ids, `REQ-ORG-002` RB-1).

**Happy path**
1. La persona entra a `/mi-cuenta` y ve primero sus apartados y, debajo, "Novedades" con 6 ítems.
2. Pulsa "ver todas" y llega al feed completo de sus 4 catálogos seguidos.
3. Filtra por un catálogo, abre un producto y aparta desde ahí.

**Sad paths y edge cases**
- SP-1 Cuenta sin suscripciones → estado vacío que explica que los catálogos se siguen desde el enlace que comparte el negocio. **No** ofrece "explorar catálogos", porque no existe descubrimiento global: es la consecuencia asumida en §4.8 de [03](03-pivote-saas-impacto.md).
- SP-2 Suscripciones cuyos catálogos están todos despublicados → estado vacío que lo explica y lista las suscripciones inactivas.
- SP-3 Producto agotado → aparece con badge "Agotado"; no se oculta.
- SP-4 Producto despublicado tras entrar al catálogo → desaparece del feed en la siguiente carga.
- SP-5 Sin tasa de cambio → solo USD (`REQ-FX-005`).
- SP-6 Cuenta con 200 catálogos y 20.000 productos → paginación por cursor sin `count(*)`, con índice `(catalog_id, created_at)`.
- SP-7 Sesión acotada intentando la URL del feed → redirección al dashboard con la propuesta de conectar Google.
- SP-8 Catálogo seguido de una organización suspendida → sus productos no aparecen (el catálogo está despublicado, `REQ-ORG-008` RB-2).

**Fuera de alcance inmediato**
- Recomendación, personalización o ranking por comportamiento (la telemetría se recoge para ello, `REQ-TLM-002`).
- Feed público o sin cuenta.
- Guardar productos como favoritos.
- Notificación en tiempo real de novedades en el feed (el aviso es por digest, `REQ-ACC-006`).

**Gate de validación**
- Superficie esperada: `app/Domain/Account/FeedQuery.php`, `app/Http/Controllers/Portal/FeedController.php`, `app/Http/Resources/FeedItemResource.php`, `resources/js/pages/portal/Feed.tsx`, `resources/js/features/account/components/FeedList.tsx`, `resources/js/features/account/hooks/useFeed.ts`.
- Tipos esperados: `FeedQuery`, `FeedItem`, `FeedFilters`, `Paginated<FeedItem>`.
- Anti-`SHALLOW`: `FeedQuery::forAccount()` (acotado por catálogos seguidos, deduplicación por organización, orden cronológico, filtros, cursor) supera 4 líneas junto a `FeedFilters`.
- Tests: solo aparecen productos de catálogos seguidos (test de fuga: un producto de un catálogo no seguido nunca aparece); deduplicación dentro de una organización y no entre organizaciones; `isNew` en el límite de 14 días; sin suscripciones muestra el vacío correcto; despublicar saca el producto; proyección con la fuente de tasa de cada organización; sesión acotada no accede; sin `count(*)` en la paginación.

---

### REQ-ACC-004 · Vinculación de la cuenta con las fichas comerciales

**Historia de usuario**
> Como comprador que ya había apartado antes sin cuenta, quiero recuperar ese historial dentro de mi cuenta, para ver todo junto sin perder lo anterior.

**Contratos de datos**
- Campo `customers.account_id` (nullable), `UNIQUE(organization_id, account_id)`.
- `LinkAccountAction(Customer $customer, Account $account, LinkOrigin $origin)`.
- `ClaimCustomerAction(Account $account, DocumentNumber $document, string $layawayCode): Customer`.
- Rutas `GET /mi-cuenta/reclamar`, `POST /mi-cuenta/reclamar`.

**Reglas de negocio y precondiciones**
- RB-1 Existen **exactamente tres** vías de vinculación, y ninguna más:
  1. **Automática al apartar** con sesión de cuenta (`LinkOrigin::LayawayCreation`).
  2. **Reclamo verificado** con **documento + código de apartado** (`LinkOrigin::VerifiedClaim`).
  3. Ninguna otra.
- RB-2 **Prohibido vincular por coincidencia de email**, y **prohibido vincular con el documento a solas.** La cédula en Venezuela es semipública: aceptarla como prueba única permitiría reclamar la deuda y el historial de otra persona. El código de apartado es el secreto compartido, y solo lo tiene quien apartó.
- RB-3 Una `Account` se vincula con **como máximo una** ficha por organización (`UNIQUE(organization_id, account_id)`), lo cual es coherente con que el documento ya sea único por organización.
- RB-4 Una ficha ya vinculada a **otra** cuenta **no se puede reclamar**: se rechaza y se indica contactar al negocio por el chat. Nunca se transfiere un vínculo automáticamente.
- RB-5 Rate limit del reclamo: **5 intentos por hora** por cuenta y por IP, con respuesta idéntica en caso de fallo (no se revela si el documento existe).
- RB-6 El reclamo es **transaccional** y se audita en la organización dueña de la ficha: el negocio debe poder ver que su cliente vinculó una cuenta.
- RB-7 La vinculación **no cambia nada** de la ficha: no toca documento, teléfono, nombre ni email. Solo añade `account_id`.
- RB-8 Desvincular no está disponible en autoservicio en esta iteración: se resuelve por soporte, porque desvincular mal deja al cliente sin acceso a su propia deuda.

**Happy path**
1. Pedro apartó hace un mes sin cuenta. Hoy entra con Google desde otro catálogo.
2. El portal le ofrece "¿ya habías apartado antes? Recupera tu historial".
3. Escribe su cédula y el código `AP-7K3M9QRT` de su apartado; se verifica y su ficha en ese negocio queda vinculada.
4. Su dashboard pasa a mostrar los apartados de dos negocios, agrupados.

**Sad paths y edge cases**
- SP-1 Documento y código que no coinciden → respuesta genérica de fallo, sin distinguir qué falló, y consumo de un intento.
- SP-2 Código válido pero de un apartado de otra persona (documento distinto) → fallo; ambos datos deben corresponder a la misma ficha.
- SP-3 Ficha ya vinculada a otra cuenta → `422` con la indicación de contactar al negocio (RB-4).
- SP-4 La cuenta ya tiene ficha en esa organización → `422`: no puede haber dos fichas de la misma cuenta en un negocio.
- SP-5 Rate limit excedido → `429` con `Retry-After`.
- SP-6 Reclamo de una ficha de una organización suspendida → **permitido**: el cliente tiene derecho a ver su deuda aunque el negocio no le pague la suscripción (`REQ-ORG-008` RB-1).
- SP-7 Apartar con sesión de cuenta cuando la ficha del documento ya está vinculada a **otra** cuenta → el apartado **se completa** (no se bloquea una venta por un conflicto de vínculo) y se registra la anomalía para el negocio; el vínculo no se cambia.
- SP-8 Reclamo de un apartado ya completado o cancelado → permitido: el historial también es información del cliente.

**Fuera de alcance inmediato**
- Desvinculación en autoservicio.
- Transferencia de un vínculo entre cuentas.
- Verificación por OTP telefónico como alternativa al código.
- Reclamo iniciado por el negocio ("invita a tu cliente a conectar su cuenta").

**Gate de validación**
- Superficie esperada: `app/Actions/Account/ClaimCustomerAction.php`, `app/Actions/Account/LinkAccountAction.php`, `app/Domain/Account/LinkOrigin.php`, `app/Http/Controllers/Portal/ClaimController.php`, `app/Http/Requests/Portal/ClaimCustomerRequest.php`.
- Tipos esperados: `LinkOrigin`, `ClaimCustomerAction`, `LinkAccountAction`, `CustomerAlreadyLinkedException`, `ClaimVerificationFailedException`.
- Anti-`SHALLOW`: `ClaimCustomerAction::handle()` (verificación conjunta de documento y código, comprobación de vínculo previo, unicidad por organización, transacción y auditoría) supera 4 líneas en su archivo.
- Tests: documento + código correctos vinculan; documento solo **nunca** vincula (test explícito de la regla de RB-2); código de otra persona falla; ficha ya vinculada rechazada; segunda ficha en la misma organización rechazada; rate limit al sexto intento; respuesta indistinguible entre "no existe" y "no coincide"; auditoría escrita en la organización; organización suspendida permite el reclamo.

---

### REQ-ACC-005 · Los dos modos de sesión del portal

**Historia de usuario**
> Como comprador sin Gmail, quiero poder ver mi apartado y subir mi comprobante igual, para no quedar fuera de mi propia información por no tener una cuenta de Google.

**Contratos de datos**
- `PortalContext` (ver contratos compartidos), middleware `EnsurePortalSession`.
- Guards `account` (global) y `customer` (acotado a una organización).
- Página `/mi-cuenta/acceso` con las tres vías: Google, documento + código, magic link por email.

**Reglas de negocio y precondiciones**
- RB-1 El portal admite **dos modos**, ambos en `/mi-cuenta`:
  | | Sesión de **cuenta** (Google) | Sesión **acotada** (documento + código, o magic link) |
  |---|---|---|
  | Apartados visibles | Todos los de sus fichas vinculadas, **agrupados por negocio** | Solo los de **una** organización |
  | Suscripciones y feed | Sí | **No** |
  | Chat | Todos sus hilos, uno por negocio | Solo el hilo de ese negocio |
  | Reclamar fichas | Sí | No |
- RB-2 La sesión acotada es la **red de seguridad**: garantiza que nadie quede sin acceso a su propia información por no tener cuenta de Google (`REQ-IDN-008`).
- RB-3 `PortalContext::customers()` es la **única** fuente de "qué fichas puede ver esta sesión". Ninguna consulta del portal accede a `customers` fuera de este contexto.
- RB-4 Las dos sesiones pueden coexistir en el mismo navegador; si ambas existen, **prevalece la de cuenta** por ser más amplia, y la acotada se ignora.
- RB-5 La sesión acotada muestra de forma persistente y no intrusiva la propuesta de conectar Google, explicando qué gana (ver todo junto y recibir novedades).
- RB-6 Toda consulta del portal se acota además por `organization_id` de la ficha correspondiente: leer los apartados de dos negocios son **dos consultas acotadas**, nunca una consulta sin filtro (`REQ-ORG-002`).
- RB-7 Una sesión de cuenta **sin ninguna ficha vinculada** es válida: puede seguir catálogos y usar el feed, aunque no tenga apartados. Es el caso de quien sigue antes de comprar.
- RB-8 Cerrar sesión cierra **ambas**.

**Happy path**
1. Una persona sin Gmail entra a `/mi-cuenta/acceso`, elige "tengo el código de mi apartado", escribe cédula y código.
2. Abre sesión acotada y ve sus 2 apartados con ese negocio, su cronograma y el botón de subir comprobante.
3. Ve el aviso de que conectando Google podría ver todo junto; lo ignora y opera con normalidad.

**Sad paths y edge cases**
- SP-1 Sesión acotada intentando `/mi-cuenta/novedades` o `/mi-cuenta/suscripciones` → redirección al dashboard con la propuesta de conectar Google, sin error.
- SP-2 Sesión de cuenta sin fichas vinculadas → dashboard con el feed y un estado vacío en apartados que explica cómo reclamar (`REQ-ACC-004`).
- SP-3 Ambas sesiones presentes → prevalece la de cuenta (RB-4), y el apartado de la sesión acotada aparece igualmente **si su ficha está vinculada**; si no lo está, se le ofrece reclamarla.
- SP-4 Ficha fusionada durante la sesión (`REQ-IDN-006`) → el contexto se reapunta a la ficha superviviente.
- SP-5 Sesión acotada de una ficha que después se vincula a otra cuenta → la sesión acotada sigue siendo válida: es un acceso legítimo probado con documento + código.
- SP-6 Intento de leer un apartado de una ficha ajena manipulando el `code` → `404`, porque la resolución pasa por `PortalContext::customers()`.
- SP-7 Sesión de cuenta con fichas en 5 negocios → el dashboard agrupa por negocio, ordenado por vencimiento más próximo.

**Fuera de alcance inmediato**
- Elevar una sesión acotada a sesión de cuenta sin pasar por el reclamo.
- Gestión de dispositivos y cierre remoto de sesiones.
- Sesión acotada para el chat sin apartados.

**Gate de validación**
- Superficie esperada: `app/Domain/Account/PortalContext.php`, `app/Http/Middleware/EnsurePortalSession.php`, `app/Http/Middleware/ResolvePortalTenant.php`, `app/Http/Controllers/Portal/AccessController.php`, `resources/js/features/account/hooks/useAccountSession.ts`, `resources/js/layouts/PortalLayout.tsx`.
- Tipos esperados: `PortalContext`, `AccountSession`, `EnsurePortalSession`.
- Anti-`SHALLOW`: `PortalContext::customers()` y `canUseFeed()`/`isScoped()` (resolución de las fichas visibles según el modo, > 4 líneas) viven junto a la clase; `EnsurePortalSession::handle()` decide entre los dos guards.
- Tests: la sesión acotada no accede al feed ni a suscripciones; la de cuenta ve fichas de N negocios agrupadas; coexistencia prevalece la de cuenta; `code` ajeno da `404`; cuenta sin fichas es válida; cerrar sesión cierra ambas; toda consulta del portal lleva filtro de organización (assert de consultas).

---

### REQ-ACC-006 · Avisos de novedades a los suscriptores

**Historia de usuario**
> Como negocio, quiero que a quienes siguen mi catálogo les llegue un aviso cuando publico algo nuevo, para vender sin tener que reenviar el enlace por WhatsApp cada vez.

**Contratos de datos**
- `DispatchCatalogDigestAction`; job programado diario `catalogs:dispatch-digests`.
- Entidad `CatalogDigestDispatch` (`catalog_digest_dispatches`): `catalog_id`, `account_id`, `digest_date`, `products_count`, `sent_at`, `UNIQUE(catalog_id, account_id, digest_date)`.
- `NotificationType::CatalogDigest`; `PlanFeature::SubscriberDigest`.

**Reglas de negocio y precondiciones**
- RB-1 Es **función de plan pago** (`subscriber_digest`, Pro y Business). En Free los suscriptores existen sin límite, pero el negocio no puede avisarles. Es la palanca de conversión del módulo: la audiencia se acumula gratis y activarla cuesta.
- RB-2 Formato **digest diario por catálogo**, no un aviso por producto: si el negocio carga 12 productos en una tarde, el suscriptor recibe **un** mensaje ("12 productos nuevos en Ferretería El Tornillo"), no doce.
- RB-3 Como máximo **un digest por catálogo, por cuenta y por día** (`platform_settings.digest_max_per_day`), garantizado por la restricción `UNIQUE` de la BD, no por una comprobación en PHP.
- RB-4 Mínimo de productos nuevos para disparar el digest: `platform_settings.digest_min_products` (1 por defecto).
- RB-5 Canal: **Web Push** a las suscripciones de la `Account` (`REQ-NOT-001`). Sin email en esta iteración: un aviso comercial por correo es una decisión de marketing con implicaciones de reputación de dominio que no quiero tomar en v1.
- RB-6 Las cuentas con la suscripción **silenciada** (`muted_at`) se excluyen (`REQ-ACC-002` RB-6).
- RB-7 El envío ocurre en la **zona horaria de la organización** dueña del catálogo, a una hora fija razonable (10:00), nunca de madrugada.
- RB-8 El digest **no revela** ningún dato del suscriptor al negocio ni del negocio al resto: es un envío ciego (`REQ-ACC-007`).
- RB-9 Un catálogo despublicado o expirado **no** envía digest, aunque tenga productos nuevos.
- RB-10 El aviso enlaza al catálogo, no al feed: la conversión ocurre en el catálogo del negocio.

**Happy path**
1. Un negocio en plan Pro añade 5 productos a su catálogo el lunes.
2. El martes a las 10:00, sus 78 suscriptores no silenciados con Web Push activo reciben "5 productos nuevos en Ferretería El Tornillo".
3. El negocio ve en su analítica cuántos avisos salieron y cuántas visitas generaron.

**Sad paths y edge cases**
- SP-1 Organización en Free → no se envía nada; en su lugar el backoffice muestra cuántos suscriptores **habría** alcanzado, con el enlace para subir de plan.
- SP-2 Job ejecutado dos veces el mismo día → la restricción `UNIQUE` impide el duplicado.
- SP-3 Suscriptor sin Web Push activo → no se le alcanza; cuenta como "no alcanzable" en el reporte, igual que en `REQ-NOT-004`.
- SP-4 Suscripción silenciada → excluida.
- SP-5 Producto añadido y retirado el mismo día → no se cuenta como novedad si al momento del envío ya no está publicado en el catálogo.
- SP-6 Catálogo con 5.000 suscriptores → envío por lotes en la cola `default`, con reporte agregado.
- SP-7 Organización que baja de Pro a Free → deja de enviar digests desde el día siguiente; los suscriptores no se pierden (`REQ-SUB-008`).
- SP-8 Suscripción de push caducada (`410`) → se purga (`REQ-NOT-005` RB-3) y cuenta como fallida.

**Fuera de alcance inmediato**
- Digest por email.
- Frecuencia configurable (semanal, inmediato) por el negocio o por el suscriptor.
- Mensaje personalizado del negocio en el digest (eso es `REQ-NOT-004`, dirigido a clientes, no a suscriptores).
- Segmentación de suscriptores.

**Gate de validación**
- Superficie esperada: `app/Actions/Notification/DispatchCatalogDigestAction.php`, `app/Jobs/DispatchCatalogDigests.php`, `app/Models/CatalogDigestDispatch.php`, `app/Notifications/CatalogDigestNotification.php`.
- Tipos esperados: `CatalogDigestDispatch`, `DispatchCatalogDigestAction`, `NotificationType::CatalogDigest`, `PlanFeature::SubscriberDigest`.
- Anti-`SHALLOW`: `DispatchCatalogDigestAction::handle()` (comprobación de función de plan, conteo de novedades, exclusión de silenciados, registro idempotente, encolado por lotes, zona horaria de la organización) supera 4 líneas.
- Tests: Free no envía y reporta el alcance potencial; agrupación de 12 productos en un mensaje; doble ejecución no duplica; silenciados excluidos; catálogo despublicado no envía; envío a las 10:00 de la zona de la organización; bajada de plan detiene los envíos sin perder suscriptores.

---

### REQ-ACC-007 · Privacidad del suscriptor frente a la organización

**Historia de usuario**
> Como comprador, quiero poder seguir un catálogo sin entregarle mis datos de contacto al negocio, para no acabar en una lista de difusión por haber tenido curiosidad.

**Contratos de datos**
- Ruta `GET /backoffice/suscriptores` (listado y métricas).
- `SubscriberListQuery` y `SubscriberStatsQuery`.

```ts
export interface SubscriberRow {
  /** Nombre público que expone Google. Nunca email ni teléfono. */
  readonly displayName: string;
  readonly avatarUrl: string | null;
  readonly subscribedAt: string;
  readonly catalogName: string;
  /** true si además es cliente del negocio: entonces sí hay ficha completa. */
  readonly isCustomer: boolean;
}
```

**Reglas de negocio y precondiciones**
- RB-1 La organización ve, de un suscriptor que **no** es su cliente: **nombre público y avatar de Google**, la fecha de suscripción y el catálogo. **Nunca** su email ni su teléfono.
- RB-2 La organización sí ve el conteo y la evolución de suscriptores por catálogo, y puede **avisarles** sin acceder a sus datos (`REQ-ACC-006`): el envío es ciego.
- RB-3 Cuando el suscriptor **aparta**, se convierte en `Customer` y el negocio ve su ficha completa —documento, teléfono, email— porque ahí sí existe una relación comercial que lo justifica. La fila del suscriptor se marca `isCustomer` y enlaza al perfil.
- RB-4 **Una organización nunca sabe que su suscriptor o su cliente también sigue u opera con otra organización.** Ninguna consulta del backoffice devuelve `account_id`, ni conteos de suscripciones ajenas, ni nada derivado de ellos.
- RB-5 El `account_id` de un cliente **no se expone nunca** en props de Inertia, respuestas JSON ni exportaciones a Excel del backoffice. Es un identificador de plataforma.
- RB-6 La analítica de suscriptores es función de plan (`full_analytics`): en Free solo el conteo total.
- RB-7 El listado de suscriptores no es exportable a Excel, ni siquiera en plan Business: exportarlo convertiría a un seguidor en un contacto descargable, que es exactamente lo que RB-1 evita.

> **Decisión reversible:** si se prefiere el modelo tipo Instagram, con el negocio viendo la lista completa incluyendo el email, es un cambio de RB-1 y RB-7. La recomendación es empezar cerrado: entregar contactos de gente que solo pulsó "seguir" es un vector de spam y una decisión difícil de deshacer una vez que los datos ya se descargaron.

**Happy path**
1. El negocio abre Suscriptores y ve "78 suscriptores (+12 esta semana)" con el listado de nombres públicos.
2. Uno de ellos aparta; su fila pasa a marcarse como cliente y enlaza a su perfil con la ficha completa.
3. El negocio les avisa de sus novedades sin ver un solo email.

**Sad paths y edge cases**
- SP-1 Petición directa a un endpoint que devolviera el email de un suscriptor → no existe tal endpoint; el `Resource` no incluye el campo.
- SP-2 Exportación de clientes a Excel de un cliente vinculado a una cuenta → el archivo **no** incluye `account_id` ni nada de la cuenta (RB-5).
- SP-3 Suscriptor que se da de baja → desaparece del listado; si era cliente, su ficha y su historial **permanecen intactos** (son cosas distintas).
- SP-4 Cliente que nunca siguió el catálogo → no aparece en Suscriptores, sí en Clientes.
- SP-5 Plan Free consultando la analítica de suscriptores → ve el conteo y la propuesta de subir de plan.
- SP-6 Suscriptor que sigue dos catálogos del mismo negocio → aparece una fila por catálogo, y el conteo de personas únicas se muestra aparte para no inflar la cifra.
- SP-7 `PlatformAdmin` consultando suscriptores → solo agregados, salvo acceso de soporte activo (`REQ-PLT-005`).

**Fuera de alcance inmediato**
- Consentimiento explícito del suscriptor para compartir su contacto con el negocio.
- Mensajería directa del negocio a un suscriptor que no es cliente (el chat exige ficha, `REQ-CHT-003`).
- Exportación de suscriptores.

**Gate de validación**
- Superficie esperada: `app/Queries/SubscriberListQuery.php`, `app/Queries/SubscriberStatsQuery.php`, `app/Http/Resources/SubscriberRowResource.php`, `app/Http/Controllers/Backoffice/SubscriberController.php`.
- Tipos esperados: `SubscriberRow`, `SubscriberListQuery`, `SubscriberStatsQuery`.
- Anti-`SHALLOW`: `SubscriberListQuery::forOrganization()` (unión acotada a los catálogos propios, marcado de `isCustomer`, conteo de personas únicas, sin campos de contacto) supera 4 líneas junto a su clase.
- Tests: el `Resource` no contiene `email`, `phone` ni `account_id` (assert de forma); la exportación de clientes no incluye `account_id`; una organización no ve suscripciones de otra (test de fuga); baja de suscripción no borra la ficha de cliente; Free solo ve conteo; personas únicas frente a filas por catálogo.
