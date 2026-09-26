# Módulo 10 — Identidad del cliente y autenticación (`IDN`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md), [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md)
> Requisitos: `REQ-IDN-001` … `REQ-IDN-008`
>
> Este módulo cubre la **identidad comercial** del cliente final: la ficha que un negocio tiene de una persona y que sostiene su deuda. La identidad de consumidor (cuenta global de Google) vive en [15](15-cuentas-de-cliente-y-feed.md). Confundirlas es el error que este módulo previene.

## Contratos compartidos del módulo

```php
/** Documento de identidad venezolano: cédula (V, E) o RIF (J, G). */
final readonly class DocumentNumber
{
    private function __construct(public string $value, public DocumentKind $kind) {}

    /** @throws InvalidDocumentNumberException */
    public static function fromInput(string $raw): self { /* … */ }

    public function prefix(): string { /* V|E|J|G */ }
    public function formatted(): string { /* V-12.345.678 · J-12345678-9 */ }
}

enum DocumentKind: string
{
    case Cedula = 'cedula';   // V, E — personas
    case Rif = 'rif';         // J, G — empresas
}

/** Teléfono venezolano en formato E.164. Canal de comunicación obligatorio. */
final readonly class PhoneNumber
{
    private function __construct(public string $e164) {}

    /** @throws InvalidPhoneNumberException */
    public static function fromInput(string $raw): self { /* … */ }

    public function isMobile(): bool { /* … */ }
    public function formatted(): string { /* 0414-123-4567 */ }
}
```

```ts
/** Ficha comercial del cliente, en el contexto de UNA organización. */
export interface CustomerIdentity {
  readonly documentNumber: string;   // "V-12.345.678"
  readonly fullName: string;
  readonly phone: string;            // "0414-123-4567"
  readonly email: string | null;     // opcional
  /** true si la ficha está vinculada a una cuenta de consumidor. */
  readonly hasLinkedAccount: boolean;
  readonly businessName: string;
}
```

---

### REQ-IDN-001 · Ficha de cliente por organización

**Historia de usuario**
> Como negocio, quiero identificar a mis clientes por su cédula o RIF y tener siempre su teléfono, para poder reconocerlos y cobrarles aunque no usen correo.

**Contratos de datos**
- Entidad `Customer` (`customers`): `organization_id`, `document_number`, `full_name`, `phone`, `email?`, `account_id?`, `terms_accepted_at`, `terms_ip_address`, `last_login_at?`.
- Índices: `UNIQUE(organization_id, document_number)`, `UNIQUE(organization_id, account_id)`, `INDEX(organization_id, phone)`.
- `ResolveCustomerAction(CustomerContactData $contact, TermsConsent $consent): Customer`, con el tenant ya fijado. `TermsConsent` es la evidencia de `REQ-IDN-005` —`acceptedAt` y `ipAddress`— y **no admite `null`** (decisión de `T-043`, abajo).

```php
final readonly class CustomerContactData
{
    public function __construct(
        public DocumentNumber $documentNumber,
        public string $fullName,
        public PhoneNumber $phone,       // obligatorio
        public ?string $email,           // opcional
    ) {}
}
```

**Reglas de negocio y precondiciones**
- RB-1 La clave de identidad es `(organization_id, document_number)` (`Q1`). El mismo documento en dos organizaciones son **dos fichas independientes**, con balances, historiales y chats separados. Ninguna organización sabe de la existencia de la otra ficha (`REQ-ACC-007` RB-4).
- RB-2 **`phone` es obligatorio.** Es el canal de comunicación real entre el negocio y su cliente, y por eso deja de ser un dato opcional del formulario.
- RB-3 **`email` es opcional y sin unicidad.** No identifica a nadie y no puede ser la vía única de acceso (`D-8`). Dos fichas de la misma organización pueden compartir email (caso familiar real).
- RB-4 Si el documento ya existe en esa organización, se **reutiliza** la ficha y se actualizan `full_name`, `phone` y `email` con los valores nuevos, dejando el cambio en `activity_log`.
- RB-5 Un `Customer` **nunca tiene contraseña**; la tabla no incluye la columna. El acceso es por documento + código, magic link o cuenta vinculada.
- RB-6 La ficha se crea **dentro de la misma transacción** que el apartado (`REQ-LAY-001`); no existe endpoint público de "crear cliente".
- RB-7 Se admiten RIF (`J`, `G`) además de cédulas: un negocio puede apartar como cliente de otro negocio, caso habitual en el mercado mayorista.
- RB-8 El backoffice **no puede crear fichas a mano** en esta iteración: nacen del apartado, para que no haya clientes sin operación asociada.

> **Corrección de RB-4: un correo omitido no borra el que había.** `T-043` lo leyó al pie de la letra —«con los valores nuevos»— y dejó que un `email` nulo sobrescriba el almacenado. No puede quedarse así, por tres motivos que se refuerzan. **Uno:** este formulario es el **único** camino de escritura de la ficha (RB-8, sin edición desde el backoffice), así que no existe forma de vaciar un correo a propósito — todo nulo es una omisión, nunca una decisión. **Dos:** el daño no es cosmético. `REQ-IDN-003` RB-2 exige **documento + correo coincidentes** para mandar el enlace de acceso, así que borrar el correo le quita al cliente una vía de entrada al portal, en silencio y sin que él haya pedido nada. **Tres:** «los valores nuevos» son los que el formulario **mandó**; un campo que no viene no es un valor nuevo, es la ausencia de uno. La regla queda: **ausente ≠ vacío**. Un `email` nulo deja el almacenado intacto; el día que exista una superficie que permita borrarlo de verdad, mandará una cadena vacía explícita, que es una señal distinta de la ausencia y se trata como tal. `full_name` y `phone` son obligatorios (RB-2), así que no tienen esta ambigüedad. Es la misma forma que los ceros a la izquierda de `REQ-IDN-007` RB-7: la lectura ingenua destruye lo que distingue.

> **El consentimiento viaja aparte del contacto, y no se puede omitir** (decidido en `T-043`). El *happy path* crea la ficha «con el consentimiento de términos y su IP», y `CustomerContactData` no tiene dónde ponerlo. **No crece el DTO: la acción recibe un segundo parámetro, `TermsConsent`, obligatorio en los dos caminos.** El motivo es de naturaleza, no de comodidad: el contacto lo **declara la persona** —y con sesión de cuenta, parte lo impone la cuenta (`REQ-IDN-002` RB-2)—, mientras que el consentimiento lo **captura el servidor**, un instante y una IP que solo conoce la petición (`REQ-IDN-005` RB-6). Juntarlos obligaría a fabricar una evidencia cada vez que alguien necesite describir un contacto sin aceptación de por medio. `T-045` la construye con `TermsConsent::acceptedNowFrom($request->ip())` después de validar la casilla.
>
> **En la reutilización, la marca se renueva y la anterior queda en la auditoría.** Las dos respuestas eran defendibles: conservar la primera protege la evidencia más antigua; renovarla hace que la ficha diga bajo qué aceptación se contrajo **la deuda vigente**. Decide `REQ-IDN-005` RB-3, que ya estaba cerrada —«se actualiza en cada nuevo apartado (evidencia por operación, con rastro en auditoría)»—, y la forma del rastro es esta: la ficha guarda la **última** aceptación, y cada una de las anteriores sobrevive como el *antes* de la entrada `customer.contact_updated` que la sobrescribió. Por eso esa entrada sale **en toda reutilización que cambie algo**, también cuando el cliente vuelve con el contacto idéntico: lo sobrescrito entonces es la evidencia de su apartado anterior. Y lleva la IP, que es el dato que el dueño necesita el día que un cliente reclame que sus avisos llegan a otro número (`REQ-BOF-005`, nota bajo RB-9). Solo un reenvío idéntico en el mismo segundo y desde la misma IP no deja entrada, porque no sobrescribe nada.
>
> **Imposible de escribir, en tres capas.** (1) `handle()` no se deja llamar sin `TermsConsent`, y `TermsConsent` no se construye sin una IP válida. (2) La guarda `saving` de `Customer` lanza si una ficha nace sin `terms_accepted_at` o `terms_ip_address`, o si se le vacía una; las columnas admiten `NULL` en la base, la ficha no. (3) `CustomerCreationTest` recorre `app/` y solo admite que cree fichas `ResolveCustomerAction` —ni `new Customer`, ni `create()`, ni un `insert()` del *query builder*, que no dispara la guarda— y que la llame `CreateLayawayAction`. La tercera es también la que hace cumplir RB-6 y RB-8.

**Happy path**
1. Un visitante envía el formulario de apartado en el catálogo de la Ferretería con su cédula, nombre, teléfono y (opcionalmente) email.
2. `ResolveCustomerAction` normaliza el documento y busca por `(organization_id, document_number)` con el tenant de la Ferretería.
3. No existe: crea la ficha con el consentimiento de términos y su IP.
4. Devuelve el `Customer` a `CreateLayawayAction`, que continúa la transacción.

**Sad paths y edge cases**
- SP-1 Documento con formato inválido → `422` sobre el campo `document_number`.
- SP-2 Teléfono ausente o inválido → `422`. No hay flujo que permita omitirlo (RB-2).
- SP-3 Documento existente con nombre radicalmente distinto → se acepta y se actualiza, con auditoría `customer.identity_conflict` para revisión humana; **no** se bloquea la venta. *«Radicalmente distinto»* (`NameDivergence`, `T-043`): los dos nombres **no comparten ni una palabra**, después de quitar tildes, mayúsculas, signos, iniciales sueltas y partículas (`de`, `del`, `la`…), y contando como la misma dos palabras de cinco letras o más que difieren en una (`Gonzales`/`González`). Se equivoca **hacia el silencio** a propósito —dos razones sociales que empiezan por `Inversiones` no saltan—, porque el evento es la alarma y tiene que ser raro; el registro de toda sobrescritura, con el nombre de antes y el de después, ya es `customer.contact_updated`, que sale **además** y no en su lugar.
- SP-4 El mismo documento apartando en dos organizaciones → dos fichas, y es correcto: son dos relaciones comerciales distintas.
- SP-5 Dos requests concurrentes con el mismo documento nuevo → la restricción `UNIQUE(organization_id, document_number)` provoca colisión; la acción la captura y reintenta la lectura una vez. **La relectura va con `FOR UPDATE`, y la primera lectura sin él** (`T-043`). Lo primero está **medido en el motor**: dentro de la transacción del apartado, en `REPEATABLE READ`, una lectura normal usa la foto tomada antes de que la otra confirmase y devuelve «no existe» justo después de la colisión (`ResolveCustomerConcurrencyTest` falla sin el bloqueo). Lo segundo es el comportamiento documentado de InnoDB: un `FOR UPDATE` sobre un documento inexistente toma un bloqueo de hueco, dos peticiones lo toman a la vez, y sus dos inserciones acaban en *deadlock*, que deshace el apartado entero en lugar de dar una colisión capturable. La inserción va en su propio *savepoint* para que la colisión no arrastre la transacción exterior.
- SP-6 Email con dominio inexistente → solo se valida el formato; el fallo se detecta al enviar el magic link, que es una vía secundaria.
- SP-7 Ficha sin email → válida y frecuente; el acceso al portal es por documento + código (`REQ-IDN-008`).
- SP-8 Tenant no fijado al invocar la acción → excepción `MissingTenantContextException` (`REQ-ORG-002` RB-5): jamás se crea una ficha sin organización.

**Fuera de alcance inmediato**
- Registro global de personas por documento (`Q1`, opción B).
- Verificación del documento contra registros oficiales.
- Verificación del teléfono por OTP.
- Creación manual de clientes desde el backoffice.

**Gate de validación**
- Superficie esperada: `app/Actions/Customer/ResolveCustomerAction.php`, `app/Models/Customer.php`, `app/Support/DocumentNumber.php`, `app/Support/PhoneNumber.php`, `database/migrations/*_create_customers_table.php`.
- Tipos esperados: `Customer`, `CustomerContactData`, `TermsConsent`, `NameDivergence`, `DocumentNumber`, `DocumentKind`, `PhoneNumber`, `InvalidDocumentNumberException`.
- Anti-`SHALLOW`: `ResolveCustomerAction::handle()` (resolución por tenant, reutilización, actualización auditada, manejo de colisión) supera 4 líneas; `DocumentNumber::fromInput()` junto a su clase.
- Tests: el mismo documento en dos organizaciones crea dos fichas aisladas (test de fuga); teléfono obligatorio; email opcional y no único; reutilización actualiza contacto con auditoría; concurrencia produce una sola ficha; sin tenant lanza excepción; RIF aceptado; `customers` no tiene columna `password` (assert de esquema).

---

### REQ-IDN-002 · Interacción entre la ficha comercial y la cuenta de consumidor

**Historia de usuario**
> Como comprador con cuenta, quiero que el formulario de apartado ya sepa quién soy y no me haga escribir todo otra vez, pero quiero que mis datos de cédula y teléfono sean los que yo declare.

**Contratos de datos**
- Campo `customers.account_id`; `LinkAccountAction` y `LinkOrigin` (`REQ-ACC-004`).
- Precarga del formulario desde `AccountSession` (`REQ-ACC-001`).

**Reglas de negocio y precondiciones**
- RB-1 Reglas de **precedencia** en el formulario de apartado cuando hay sesión de cuenta:
  | Campo | Origen | ¿Editable? |
  |---|---|---|
  | `full_name` | precargado de Google | **sí** (el nombre de Google puede no ser el legal) |
  | `email` | precargado de Google y **bloqueado** | no |
  | `document_number` | lo escribe la persona | sí |
  | `phone` | lo escribe la persona | sí |
- RB-2 Con sesión de cuenta, el servidor **ignora** cualquier email enviado en la petición y usa el de la identidad autenticada. El email es el único campo que la cuenta impone.
- RB-3 La cuenta **nunca aporta ni sustituye** el documento ni el teléfono: no los tiene (`REQ-ACC-001` RB-2). Son siempre declaración de la persona en el contexto de ese negocio.
- RB-4 Al completarse el apartado con sesión de cuenta, la ficha resultante queda vinculada con `LinkOrigin::LayawayCreation` (`REQ-ACC-004` RB-1).
- RB-5 Si la ficha de ese documento ya está vinculada a **otra** cuenta, el apartado **se completa igualmente** y el vínculo **no se cambia**; se registra la anomalía para el negocio (`REQ-ACC-004` SP-7). No se bloquea una venta por un conflicto de vínculo.
- RB-6 Si la cuenta ya tiene otra ficha en esa organización (documento distinto), el apartado se completa sobre la ficha del documento declarado y **no** se vincula, porque `UNIQUE(organization_id, account_id)` lo impide; se registra la anomalía.
- RB-7 **Tener cuenta no es requisito para apartar** (`REQ-ACC-002` RB-2). Es una comodidad de precarga, nada más.
- RB-8 El negocio ve en la ficha si está vinculada a una cuenta (`hasLinkedAccount`), pero **nunca** el `account_id` ni nada de la cuenta (`REQ-ACC-007` RB-5).

**Happy path**
1. Una persona con sesión de cuenta abre el formulario de apartado desde un catálogo.
2. Nombre y email vienen precargados; el email aparece bloqueado con el icono de Google.
3. Escribe su cédula y su teléfono, envía, y su ficha nueva queda vinculada a su cuenta.
4. Su portal pasa a mostrar los apartados de este negocio junto a los de los demás.

**Sad paths y edge cases**
- SP-1 Persona con cuenta que escribe un documento distinto al de su ficha previa en ese negocio → se crea o resuelve la ficha del documento declarado; no se vincula (RB-6) y se registra la anomalía.
- SP-2 Petición manipulada que envía otro email con sesión de cuenta → se ignora (RB-2).
- SP-3 Sesión de cuenta expirada a mitad del formulario → el apartado sigue siendo posible sin cuenta; el email deja de estar bloqueado y se pide.
- SP-4 Nombre de Google que es un apodo → editable (RB-1), porque el nombre en la ficha debe poder ser el legal.
- SP-5 Ficha ya vinculada a la misma cuenta → operación nula, sin error.
- SP-6 Persona sin cuenta → el formulario funciona completo, con los cuatro campos editables.

**Fuera de alcance inmediato**
- Que la cuenta almacene documento o teléfono reutilizables entre negocios (rompería `Q1` y `REQ-ACC-001` RB-2).
- Autocompletado de documento a partir de fichas de otros negocios.
- Vinculación retroactiva automática al iniciar sesión.

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/CreateLayawayAction.php`, `app/Actions/Account/LinkAccountAction.php`, `app/Http/Requests/Catalog/StoreLayawayRequest.php`, `resources/js/features/layaway/hooks/useLayawayForm.ts`.
- Tipos esperados: `LinkOrigin`, `CustomerIdentity`, `AccountSession`, `LinkAccountAction`.
- Anti-`SHALLOW`: `LinkAccountAction::handle()` (comprobación de unicidad por organización, detección de conflicto, registro de anomalía) supera 4 líneas en su archivo.
- Tests: la matriz de precedencia de RB-1 campo por campo; el email enviado se ignora con sesión de cuenta; la cuenta no aporta documento ni teléfono; apartado con conflicto de vínculo se completa sin cambiar el vínculo; ficha con cuenta de otra persona no se sobrescribe; el `Resource` de la ficha no expone `account_id`.

---

### REQ-IDN-003 · Magic link por email (vía secundaria)

**Historia de usuario**
> Como cliente que dejó su correo, quiero recibir un enlace para entrar a mi cuenta del negocio, para no tener que buscar el código de mi apartado.

**Contratos de datos**
- Entidad `CustomerLoginToken` (`customer_login_tokens`): `organization_id`, `customer_id`, `token_hash`, `expires_at`, `consumed_at?`, `requested_ip`.
- `IssueLoginLinkAction`, `ConsumeLoginTokenAction`.
- Rutas `GET|POST /mi-cuenta/acceso/enlace`, `GET|POST /mi-cuenta/acceso/enlace/{token}`.

> **El token lleva delante el identificador de su organización, y su índice es `(organization_id, token_hash)`.** El contrato decía `UNIQUE(token_hash)` a secas, y eso habría sido la cuarta excepción de `GlobalUniqueExceptions`, que es una lista cerrada en tres ([05 §4.1]). Sobra: RB-3 ya obliga a que cada enlace esté acotado a **una** organización, así que el token puede decir a cuál —como el de la invitación de equipo desde `T-013`, con el mismo formato `7.a8f3…`—, el tenant se fija con ese prefijo antes de tocar la tabla, y el índice se compone. Dos consecuencias para quien lo implemente: SP-2 sigue en pie —un token inexistente, uno caducado y uno con un prefijo que no es de ninguna organización dan **el mismo `404`**, sin distinguirse—, y la comparación del hash se hace **dentro** de la organización ya fijada, que es donde RB-5 invalida los previos del mismo cliente.

**Reglas de negocio y precondiciones**
- RB-1 Es una vía **secundaria** (`Q2`): solo funciona para fichas que tengan email. La vía universal es documento + código (`REQ-IDN-008`).
- RB-2 La solicitud exige **documento + email coincidentes** con una ficha; la respuesta es **idéntica** cuando no coinciden, para no filtrar si la ficha existe.
- RB-3 Como el documento es único **por organización**, la solicitud puede coincidir con fichas de **varias organizaciones**. En ese caso se envía **un enlace por organización**, cada uno acotado a la suya, y el correo lo explica. Nunca se mezclan negocios en una sesión acotada.
- RB-4 TTL de 30 minutos, un solo uso, solo se persiste `sha256` del token.
- RB-5 Emitir tokens nuevos invalida los previos no consumidos del mismo cliente.
- RB-6 Rate limit: 3 solicitudes por 15 minutos por IP + documento.
- RB-7 Al consumirse abre **sesión acotada** a esa organización (`REQ-ACC-005`), con recuerdo de 30 días.
- RB-8 El email **no contiene** saldos, montos ni nombres de productos: solo el enlace, el nombre del negocio y la caducidad.
- RB-9 El `GET` del enlace solo muestra una pantalla de confirmación; el canje es un `POST`, para que el prefetch del cliente de correo no consuma el token.

**Happy path**
1. El cliente entra a `/mi-cuenta/acceso`, elige "enviarme un enlace" y escribe documento y email.
2. Recibe un correo con el enlace y el nombre del negocio.
3. Lo abre, confirma y entra a su portal acotado a esa organización.

**Sad paths y edge cases**
- SP-1 Token caducado o ya consumido → pantalla con CTA para solicitar otro; el token se marca consumido.
- SP-2 Token inexistente → `404` genérico, indistinguible de uno caducado.
- SP-3 Datos no coincidentes → respuesta idéntica al happy path y **no** se envía nada.
- SP-4 Ficha sin email → la vía no está disponible; la UI dirige a documento + código.
- SP-5 Documento presente en tres organizaciones con el mismo email → tres enlaces, uno por negocio (RB-3).
- SP-6 Rate limit excedido → `429` con `Retry-After`.
- SP-7 Prefetch del cliente de correo → el `GET` no consume (RB-9).
- SP-8 Organización suspendida → el enlace **funciona**: el cliente tiene derecho a ver su deuda (`REQ-ORG-008` RB-1).

**Fuera de alcance inmediato**
- Enlace por WhatsApp o SMS.
- Un enlace único que abra sesión en varias organizaciones a la vez.
- Recuperación cuando el cliente perdió el acceso a su email (se resuelve con `REQ-IDN-008` o por el chat).

**Gate de validación**
- Superficie esperada: `app/Actions/Customer/IssueLoginLinkAction.php`, `app/Actions/Customer/ConsumeLoginTokenAction.php`, `app/Models/CustomerLoginToken.php`, `app/Notifications/CustomerLoginLinkNotification.php`.
- Tipos esperados: `CustomerLoginToken`, `LoginLinkResult`, `ExpiredLoginTokenException`, `ConsumedLoginTokenException`.
- Anti-`SHALLOW`: `IssueLoginLinkAction::handle()` (búsqueda multi-organización, invalidación, generación, hash, encolado por negocio) y `ConsumeLoginTokenAction::handle()` superan 4 líneas.
- Tests: token válido autentica una vez y falla la segunda; documento en tres organizaciones genera tres enlaces acotados; datos no coincidentes no envían y responden igual; ficha sin email no ofrece la vía; rate limit a la cuarta; el email no contiene importes; `GET` no consume; organización suspendida permite el acceso.

---

### REQ-IDN-004 · Sesión acotada del cliente y guards del portal

**Historia de usuario**
> Como cliente, quiero que mi sesión del portal sea independiente de la del negocio y de la de otros clientes, para que mis datos no queden expuestos.

**Contratos de datos**
- Guard `customer` sobre `customers` (sesión acotada) y guard `account` sobre `accounts` (sesión global, `REQ-ACC-001`).
- Middleware `EnsurePortalSession`, `ResolvePortalTenant`; `PortalContext` (`REQ-ACC-005`).
- Page prop compartida `auth.portal: PortalSessionView | null`.

**Reglas de negocio y precondiciones**
- RB-1 Cuatro guards mutuamente independientes: `account`, `customer`, `web` (equipo) y `platform`. Tener sesión en uno no otorga nada en otro.
- RB-2 La sesión `customer` es **acotada a una organización**: `ResolvePortalTenant` fija el tenant desde la ficha autenticada, y todas las consultas quedan filtradas (`REQ-ORG-002`).
- RB-3 La sesión `account` **no fija un tenant**: opera sobre el conjunto de fichas de `PortalContext::customers()`, y cada lectura se acota por la organización de la ficha correspondiente. Leer dos negocios son **dos consultas acotadas**, nunca una sin filtro.
- RB-4 Las page props del portal nunca incluyen `id` numéricos de cliente ni `account_id`; se expone `document_number` formateado y el `code` del apartado.
- RB-5 El cierre de sesión invalida **ambas** sesiones y regenera el token CSRF.
- RB-6 Las sesiones viven en Redis; el `visitor_id` de telemetría **sobrevive** al cierre de sesión (`REQ-TLM-004` RB-2).
- RB-7 Un cliente autenticado que intente `/backoffice` o `/plataforma` recibe `403`, sin revelar la existencia del panel.
- RB-8 Sesión caducada durante una navegación Inertia → respuesta `409` que fuerza recarga completa hacia la pantalla de acceso.

**Happy path**
1. El cliente entra con su magic link; se abre sesión `customer` y `ResolvePortalTenant` fija la Ferretería.
2. Navega su portal; toda consulta está filtrada por esa organización.
3. Cierra sesión: ambas sesiones se invalidan y el `visitor_id` permanece.

**Sad paths y edge cases**
- SP-1 Acceso a `/mi-cuenta` sin ninguna sesión → redirección a `/mi-cuenta/acceso` preservando `intended`.
- SP-2 Sesión `customer` de una ficha fusionada (`REQ-IDN-006`) → el middleware la reapunta a la superviviente.
- SP-3 Sesión `customer` de una ficha borrada por una fusión → misma resolución que SP-2; nunca un `500`.
- SP-4 Ambas sesiones presentes → prevalece `account` (`REQ-ACC-005` RB-4).
- SP-5 Cliente intentando el backoffice → `403`.
- SP-6 Petición Inertia con sesión caducada → `409` (RB-8).
- SP-7 Sesión `customer` de una organización cerrada → acceso de solo lectura a su historial (`REQ-ORG-008` SP-5).

**Fuera de alcance inmediato**
- Gestión de dispositivos y cierre remoto de sesiones.
- Bloqueo de una ficha de cliente por el negocio.
- Sesión que abarque varias organizaciones sin cuenta de Google.

**Gate de validación**
- Superficie esperada: `config/auth.php`, `app/Http/Middleware/EnsurePortalSession.php`, `app/Http/Middleware/ResolvePortalTenant.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Controllers/Portal/SessionController.php`.
- Tipos esperados: los cuatro guards declarados, `PortalContext`, `PortalSessionView`.
- Anti-`SHALLOW`: `ResolvePortalTenant::handle()` (resolución del tenant desde la ficha, reapuntado por fusión, ausencia de tenant en sesión de cuenta) y `HandleInertiaRequests::share()` superan 4 líneas.
- Tests: los cuatro guards son independientes (matriz de 4×4); la sesión `customer` filtra por su organización; la sesión `account` no opera sin filtro (assert de consultas); logout invalida ambas; `visitor_id` sobrevive; cliente no accede al backoffice ni a plataforma; `409` en sesión caducada.

---

### REQ-IDN-005 · Consentimiento de términos y privacidad

**Historia de usuario**
> Como negocio, quiero constancia de que mi cliente aceptó las condiciones al apartar, para tener respaldo documental de la obligación de pago.

**Contratos de datos**
- Campos `customers.terms_accepted_at` y `terms_ip_address`.
- Campo `terms_accepted: boolean` en `LayawayFormValues`.
- Páginas `/terminos` y `/privacidad` (de la plataforma), más las condiciones de apartado del negocio si las define.

**Reglas de negocio y precondiciones**
- RB-1 El checkbox de aceptación es **obligatorio** en el formulario de apartado, con validación de servidor (`accepted`).
- RB-2 Se persisten fecha/hora UTC e IP en el momento de la aceptación.
- RB-3 Si la ficha ya tenía consentimiento, se **actualiza** en cada nuevo apartado (evidencia por operación, con rastro en auditoría). El rastro es el *antes* de `customer.contact_updated`, que sale en toda reutilización que sobrescriba algo — también la marca sola (razonado bajo `REQ-IDN-001`, `T-043`).
- RB-4 El consentimiento cubre los términos **de la plataforma** y las condiciones de apartado **del negocio**: el texto enlaza a ambos, y queda claro que el contrato de pago es con el negocio, no con Apartar.
- RB-5 El banner de cookies es independiente y condiciona **solo** la telemetría no esencial (`REQ-TLM-005`).
- RB-6 La IP se toma de `X-Forwarded-For` solo si el proxy es de confianza (`TrustProxies` para Traefik, `REQ-INF-002` RB-5).

**Happy path**
1. El formulario muestra el checkbox con enlaces que abren en pestaña nueva sin perder los datos escritos.
2. La persona lo marca y envía; el `FormRequest` valida `accepted`.
3. Se persisten fecha e IP en la ficha.

**Sad paths y edge cases**
- SP-1 Envío sin marcar → `422` con foco en el checkbox y sin perder los demás campos.
- SP-2 Petición manipulada que omite el campo → misma validación de servidor.
- SP-3 Cliente detrás de proxy no confiable → se registra la IP del proxy; se documenta como limitación.
- SP-4 Negocio sin condiciones propias definidas → se enlazan solo las de la plataforma.

**Fuera de alcance inmediato**
- Versionado de los términos y re-aceptación al cambiar de versión.
- Firma electrónica del contrato de apartado.
- Condiciones de apartado personalizables por catálogo.

**Gate de validación**
- Superficie esperada: `app/Http/Requests/Catalog/StoreLayawayRequest.php`, `app/Actions/Customer/ResolveCustomerAction.php`, `resources/js/features/layaway/components/TermsConsentField.tsx`, `app/Http/Middleware/TrustProxies.php`.
- Tipos esperados: `terms_accepted` en `LayawayFormValues`; las dos columnas en la migración.
- Anti-`SHALLOW`: `StoreLayawayRequest::rules()` (> 4 líneas) junto a su clase.
- Tests: sin aceptación `422`; con aceptación se persisten fecha e IP; la IP respeta el proxy de confianza; el texto enlaza a los términos de la plataforma y del negocio.

---

### REQ-IDN-006 · Fusión manual de fichas duplicadas

**Historia de usuario**
> Como negocio, quiero fusionar dos fichas que resultaron ser la misma persona, para consolidar su balance sin perder trazabilidad.

**Contratos de datos**
- `MergeCustomersAction(Customer $survivor, Customer $absorbed, User $actor)`.
- Ruta `POST /backoffice/clientes/{customer}/fusionar`.
- Auditoría `customer.merged` con el detalle de lo movido.

**Reglas de negocio y precondiciones**
- RB-1 La fusión es **manual y exclusiva de un usuario de la organización**, y **solo entre fichas de la misma organización**. Fusionar entre organizaciones es imposible por construcción: son entidades distintas de negocios distintos.
- RB-2 Se transfieren al superviviente: `layaways`, `receipts`, `payments`, `customer_login_tokens`, los `messages` de la conversación y los `telemetry_events` de la ficha.
- RB-3 Las conversaciones se fusionan preservando el orden cronológico; la absorbida se elimina tras el traslado.
- RB-4 El `account_id`: si solo una de las dos fichas está vinculada, el vínculo pasa al superviviente. Si **ambas** están vinculadas a cuentas distintas, la fusión **se rechaza** y se indica resolverlo con soporte: fusionar borraría el acceso de una persona real a su deuda.
- RB-5 La ficha absorbida se elimina físicamente **solo** cuando ya no tiene filas dependientes; en caso contrario la operación falla íntegra.
- RB-6 Los balances materializados de los apartados **no** se recalculan (pertenecen al apartado); el balance del cliente es agregado en lectura.
- RB-7 No se puede fusionar una ficha consigo misma, ni en cadena dentro de la misma transacción.
- RB-8 La UI exige confirmación escribiendo el documento de la ficha absorbida: la fusión no se puede deshacer.

**Happy path**
1. El negocio detecta dos fichas de la misma persona (una con cédula mal escrita).
2. Abre el perfil, elige "Fusionar con…", ve el resumen de lo que se moverá y confirma escribiendo el documento.
3. La acción mueve todo en una transacción, escribe la auditoría y borra la absorbida.

**Sad paths y edge cases**
- SP-1 Fusión con ficha inexistente, de otra organización o igual → `422` (la de otra organización además da `404` por el binding).
- SP-2 Ambas fichas vinculadas a cuentas distintas → `422` (RB-4).
- SP-3 Sesión activa de la ficha absorbida → el middleware la reapunta a la superviviente (`REQ-IDN-004` SP-2).
- SP-4 Fusión concurrente de la misma pareja → bloqueo pesimista sobre ambas filas evita traslados parciales.
- SP-5 Ficha absorbida con apartados activos → permitido: pasan al superviviente y siguen su curso.
- SP-6 Fallo a mitad del traslado → rollback total; ninguna ficha queda mutilada.

**Fuera de alcance inmediato**
- Deshacer una fusión.
- Detección automática de candidatos a duplicado.
- Fusión de cuentas de consumidor (`REQ-ACC-001` SP-5).

**Gate de validación**
- Superficie esperada: `app/Actions/Customer/MergeCustomersAction.php`, `app/Http/Controllers/Backoffice/CustomerMergeController.php`, `app/Policies/CustomerPolicy.php`.
- Tipos esperados: `MergeCustomersAction`, `MergeSummary`, `CannotMergeCustomerException`.
- Anti-`SHALLOW`: `MergeCustomersAction::handle()` (traslado de seis relaciones, resolución del vínculo de cuenta, transacción, auditoría) supera 4 líneas.
- Tests: mueve las seis relaciones; ambas vinculadas a cuentas distintas se rechaza; fusión entre organizaciones imposible (test de fuga); rechaza consigo misma; concurrencia serializada; rollback total; auditoría escrita.

---

### REQ-IDN-007 · Normalización y validación de documento y teléfono

**Historia de usuario**
> Como operador de cobranza, quiero que los documentos y teléfonos estén guardados de forma uniforme, para encontrar a un cliente sin fallar por cómo lo escribió.

**Contratos de datos**
- Value Objects `DocumentNumber` (con `DocumentKind`) y `PhoneNumber`.
- Reglas `ValidDocumentNumber` y `ValidVenezuelanPhone`.
- Utilidades TS `formatDocumentNumber(raw)` y `formatPhone(raw)` con máscaras de entrada.

> **Qué se reparte entre PHP y TypeScript, y cómo se prueba (decidido en `T-040`).** La máscara **nunca rechaza**: da forma a lo que se escribe y deja pasar todo —no recorta, no limita la longitud, no conoce operadoras ni rangos—, y lo que no tiene forma de documento o de teléfono lo devuelve tal cual. Quien valida es PHP, siempre. Lo que ya está guardado se enseña con `formatted()` desde el servidor, como el dinero. **Queda un solo censo en los dos lados: qué letras son de un RIF** (`J`, `G`), porque cédula y RIF se escriben distinto mientras se teclean. Se prueba con **una tabla de casos compartida**, `resources/js/lib/identity-format.cases.json`: `identity-format.test.ts` la recorre contra la máscara y `IdentityFormatCasesTest.php` contra `formatted()`, así que las dos tienen que enseñar igual toda escritura completa. Y el lado PHP exige además que **cada letra de `DocumentKind` aparezca en algún caso**: si el tipo gana una letra, se pone rojo hasta que la tabla la lleve, y entonces es la máscara la que falla hasta aprenderla. Los prefijos de operadora y los códigos de área están **solo** en `PhoneNumber`; `ValidPaymentIdentifier` (`T-015`) llevaba su propia copia de los cinco móviles y desde `T-040` lee `PhoneNumber::MOBILE_PREFIXES`.
>
> **Decidido: el censo de prefijos deja de ser una puerta y pasa a ser una etiqueta** (supersede RB-4 en lo que tiene de «válidos», y SP-7). `T-040` lo dejó planteado bien: RB-4 lista cinco prefijos móviles y el plan de numeración tiene más —Digitel opera también `0422`, y existe `0415`—, así que hoy un cliente con un `0422` recibe un `422` al apartar. Lo que decide el caso no es cuántos faltan, es **hacia dónde falla la lista**:
>
> - **Rechazar un número real** deja a un cliente sin poder completar su apartado. No hay error en ningún log, no hay queja, no hay síntoma: la persona cierra la página, y el negocio nunca se entera de que perdió la venta.
> - **Aceptar un rango sin asignar** guarda un teléfono con una errata. El negocio llama, no contesta nadie, y usa el correo o el chat. Se nota, se corrige, y no cuesta la venta.
>
> Y la lista no es un hecho sobre este producto: es el plan de numeración de CONATEL, que cambia sin avisarnos y **cambia en la dirección que nos rompe** —los rangos nuevos se asignan, no se retiran—. Una lista blanca de datos ajenos en el camino de una venta es deuda con fecha de caducidad desconocida.
>
> Por tanto, **la aceptación valida la forma**: `+58`, diez dígitos nacionales, y el código empezando por `2` (fijo) o `4` (móvil). `+` distinto de `+58` sigue siendo `422` (SP-5), porque eso sí es forma. `MOBILE_PREFIXES` y los códigos geográficos **se quedan**, pero para responder `isMobile()` —que decide si se puede ofrecer WhatsApp o SMS (`REQ-NOT-*`)— y para que la UI **avise sin bloquear** cuando el prefijo no esté en el censo: «no reconocemos ese prefijo, ¿es correcto?», que es de `T-045`. `0422` y `0415` entran al censo con esto, y la pregunta de si falta un sexto deja de poder impedir una compra.

**Reglas de negocio y precondiciones**
- RB-1 **Cédula** (`DocumentKind::Cedula`): prefijo `V` o `E` (opcional en la entrada, `V` por defecto) + **6 a 9 dígitos**. Se ignoran espacios, puntos y guiones.
- RB-2 **RIF** (`DocumentKind::Rif`): prefijo `J` o `G` + 8 a 9 dígitos + dígito verificador opcional. El prefijo es **obligatorio** en la entrada para un RIF (no hay default), porque confundir una cédula con un RIF cambia la naturaleza del cliente.
- RB-3 Almacenamiento canónico sin separadores y en mayúscula: `V12345678`, `J123456789`. Presentación: `V-12.345.678`, `J-12345678-9`.
- RB-4 **Teléfono**: se acepta `04121234567`, `4121234567`, `+584121234567`; se almacena en E.164 `+584121234567`. Prefijos móviles válidos: `412`, `414`, `416`, `424`, `426`; se aceptan fijos con código de área válido.
- RB-5 La misma normalización se aplica en la búsqueda del backoffice: buscar `12.345.678` encuentra `V12345678` (`REQ-BOF-040` RB-1).
- RB-6 Los VO son inmutables y lanzan excepción en el constructor; no existe estado intermedio inválido.
- RB-7 **Los ceros a la izquierda se conservan** (`V-0012345`): recortarlos colapsaría dos documentos distintos.
- RB-8 No se valida el dígito verificador del RIF en esta iteración: los datos de campo son irregulares y rechazar por eso costaría ventas.

**Happy path**
1. La persona escribe `v-12.345.678` con la máscara del frontend.
2. El `FormRequest` aplica `ValidDocumentNumber`, que delega en `DocumentNumber::fromInput()`.
3. Se persiste `V12345678` con `kind = cedula`, y es lo que se usa para buscar.

**Sad paths y edge cases**
- SP-1 Menos de 6 o más de 9 dígitos en cédula → `422` con el rango explícito.
- SP-2 Prefijo desconocido (`P-123`) → `422`.
- SP-3 Números de RIF sin prefijo → se interpreta como cédula (RB-2); si el cliente es una empresa debe escribir la `J`, y la UI lo explica.
- SP-4 Ceros a la izquierda → se conservan (RB-7).
- SP-5 Teléfono con prefijo internacional distinto de `+58` → `422` en esta iteración.
- SP-6 Búsqueda con cadena no normalizable → se degrada a `LIKE` sobre nombre, email y teléfono, sin error.
- SP-7 Teléfono fijo con código de área inválido → `422`.

**Fuera de alcance inmediato**
- Validación del dígito verificador del RIF.
- Numeración internacional no venezolana.
- Validación de operadora en tiempo real.

**Gate de validación**
- Superficie esperada: `app/Support/DocumentNumber.php`, `app/Support/PhoneNumber.php`, `app/Rules/ValidDocumentNumber.php`, `app/Rules/ValidVenezuelanPhone.php`, `resources/js/lib/identity-format.ts`.
- Tipos esperados: `DocumentNumber`, `DocumentKind`, `PhoneNumber`, `InvalidDocumentNumberException`, `InvalidPhoneNumberException`.
- Anti-`SHALLOW`: `DocumentNumber::fromInput()`, `::formatted()`, `PhoneNumber::fromInput()` e `::isMobile()` viven junto a sus clases con más de 4 líneas.
- Tests: 12 variantes de escritura de cédula → misma forma canónica; RIF con y sin prefijo con el `kind` correcto; ceros a la izquierda preservados; 6 variantes de teléfono → E.164; rechazos esperados; búsqueda normalizada encuentra al cliente.

---

### REQ-IDN-008 · Acceso por documento y código de apartado

**Historia de usuario**
> Como cliente sin correo ni cuenta de Google, quiero entrar a ver mi apartado con mi cédula y el código que me dieron, para consultar mi saldo y subir mi comprobante.

**Contratos de datos**
- `StartScopedSessionAction(DocumentNumber $document, string $layawayCode): Customer`.
- Rutas `GET /mi-cuenta/acceso`, `POST /mi-cuenta/acceso/codigo`.
- `layaways.code` con `UNIQUE` **global** (por eso basta el código para resolver la organización).

**Reglas de negocio y precondiciones**
- RB-1 Es la **vía universal de acceso** (`Q2`): no depende de tener email ni cuenta de Google, y por eso garantiza que nadie quede fuera de su propia información.
- RB-2 Exige **ambos** datos: documento **y** código, y que correspondan a la **misma** ficha. El documento a solas nunca basta: la cédula en Venezuela es semipública (`REQ-ACC-004` RB-2).
- RB-3 El `code` es único global y no adivinable secuencialmente: `AP-` + 8 caracteres en base32 sin caracteres ambiguos (`REQ-LAY-001` RB-7). De él se deriva la organización.
- RB-4 Abre **sesión acotada** a esa organización (`REQ-ACC-005`), con recuerdo de 30 días, y da acceso a **todos** los apartados de esa ficha en ese negocio, no solo al del código usado.
- RB-5 Rate limit: **5 intentos por hora** por IP + documento, con bloqueo temporal y respuesta idéntica en caso de fallo (no se revela si el documento o el código existen).
- RB-6 La misma prueba (documento + código) es la que permite **reclamar** la ficha desde una cuenta de consumidor (`REQ-ACC-004` RB-1, vía 2): un solo mecanismo de verificación para los dos propósitos.
- RB-7 Funciona con apartados en cualquier estado, incluidos `completed`, `cancelled` y `expired`: el historial también es información del cliente.
- RB-8 Funciona aunque la organización esté suspendida o cerrada (`REQ-ORG-008` RB-1).
- RB-9 El código se le entrega al cliente en la confirmación del apartado, en el email de confirmación y en el resumen que puede ver tras apartar, para que pueda anotarlo.

**Happy path**
1. El cliente entra a `/mi-cuenta/acceso` y elige "tengo el código de mi apartado".
2. Escribe `V-12.345.678` y `AP-7K3M9QRT`.
3. Se verifica que ambos corresponden a la misma ficha; se abre sesión acotada a la Ferretería.
4. Ve sus dos apartados con ese negocio, su cronograma y el botón de subir comprobante.

**Sad paths y edge cases**
- SP-1 Documento y código que no corresponden a la misma ficha → fallo genérico y consumo de un intento.
- SP-2 Código válido con documento de otra persona → fallo (RB-2). Es el escenario que la regla protege.
- SP-3 Código inexistente → misma respuesta genérica.
- SP-4 Rate limit excedido → `429` con `Retry-After`.
- SP-5 Cliente que perdió el código → se le indica pedirlo por el chat del negocio o por su email de confirmación; el negocio puede reenviárselo desde el perfil del cliente.
- SP-6 Código de un apartado de una ficha que fue absorbida en una fusión → resuelve a la ficha superviviente (`REQ-IDN-006`).
- SP-7 Confusión de caracteres al transcribir el código → el alfabeto excluye `0`, `O`, `1` e `I` precisamente por esto, y la entrada normaliza a mayúsculas e ignora guiones y espacios.
- SP-8 Cliente con fichas en dos negocios usando el código de uno → sesión acotada a **ese** negocio; para ver el otro necesita su código, o conectar Google y reclamar ambas (`REQ-ACC-004`).

**Fuera de alcance inmediato**
- OTP por WhatsApp o SMS como alternativa (`Q2`, plan pago futuro).
- Acceso solo con el código, sin documento.
- Recuperación del código en autoservicio.

**Gate de validación**
- Superficie esperada: `app/Actions/Customer/StartScopedSessionAction.php`, `app/Http/Controllers/Portal/AccessController.php`, `app/Http/Requests/Portal/StartScopedSessionRequest.php`, `app/Support/LayawayCode.php`.
- Tipos esperados: `StartScopedSessionAction`, `ScopedSessionResult`, `AccessVerificationFailedException`.
- Anti-`SHALLOW`: `StartScopedSessionAction::handle()` (resolución del código sin tenant, verificación conjunta con el documento, apertura de sesión acotada, fijación del tenant, rate limit) supera 4 líneas; `LayawayCode::normalize()` junto a su clase.
- Tests: documento + código correctos abren sesión acotada; código de otra persona falla (test explícito de RB-2); respuesta indistinguible en los tres modos de fallo; rate limit al sexto intento; da acceso a todos los apartados de la ficha; funciona con apartados terminales y con organización suspendida; el alfabeto del código no contiene caracteres ambiguos; normalización de minúsculas y guiones.
