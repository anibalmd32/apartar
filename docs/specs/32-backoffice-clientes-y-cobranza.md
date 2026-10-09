# Módulo 32 — Clientes, cobranza y exportaciones (`BOF` 040+)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [20-apartado-y-cronograma.md](20-apartado-y-cronograma.md), [22-comprobantes-y-conciliacion.md](22-comprobantes-y-conciliacion.md), [50-planes-y-suscripciones.md](50-planes-y-suscripciones.md)
> Requisitos: `REQ-BOF-040` … `REQ-BOF-045`

## Contratos compartidos del módulo

```php
/**
 * Filtros operativos de cobranza. Única fuente de verdad de la query:
 * la usan la vista, la exportación y el envío segmentado de notificaciones.
 */
final readonly class CollectionFilters
{
    public function __construct(
        public ?CollectionPreset $preset,
        public ?CarbonImmutable $dueFrom,
        public ?CarbonImmutable $dueTo,
        /** @var list<LayawayStatus> */
        public array $statuses,
        public ?string $search,
        public ?int $categoryId,
        public ?int $catalogId,
    ) {}

    /** @return Builder<Installment> */
    public function apply(Builder $query): Builder { /* … */ }
}

enum CollectionPreset: string
{
    case DueToday = 'due_today';
    case DueWithin7Days = 'due_within_7_days';
    case OverdueOver15Days = 'overdue_over_15_days';
    case FortnightFirstHalf = 'fortnight_first_half';    // días 1–15
    case FortnightSecondHalf = 'fortnight_second_half';  // día 16–fin de mes
    case CompletedNotDelivered = 'completed_not_delivered';

    public function label(): string { /* … */ }
}

enum ExportType: string { case Customers = 'customers'; case Inventory = 'inventory'; case Collections = 'collections'; }
enum ExportStatus: string { case Queued = 'queued'; case Processing = 'processing'; case Done = 'done'; case Failed = 'failed'; }
```

---

### REQ-BOF-040 · Directorio de clientes

**Historia de usuario**
> Como negocio, quiero buscar a cualquiera de mis clientes por su cédula, nombre o teléfono, para atenderlo cuando me escribe o me llama.

**Contratos de datos**
- Ruta `GET /backoffice/clientes`. Tabla server-driven (`REQ-BOF-045`).
- `CustomerDirectoryQuery` con búsqueda normalizada.

**Reglas de negocio y precondiciones**
- RB-1 Búsqueda por documento (normalizado, `REQ-IDN-007` RB-5), nombre, **teléfono** o email; una sola caja que decide el criterio. El teléfono gana relevancia porque ahora es obligatorio y es el canal real de contacto.
- RB-2 Columnas: nombre, documento formateado, teléfono, apartados activos, total comprometido, total pagado, saldo pendiente, cuotas vencidas y próximo vencimiento.
- RB-3 Los agregados se calculan con una consulta agregada sobre los balances materializados (`REQ-PAY-008`), sin `N+1`.
- RB-4 Filtros: con mora, con vencimiento próximo, sin apartados activos, rango de fechas de próximo vencimiento y **catálogo de origen** (útil para medir qué campaña trajo qué clientes).
- RB-5 Los clientes en mora se destacan visualmente.
- RB-6 Paginación y orden de servidor; orden por defecto "cuotas vencidas descendente, luego próximo vencimiento ascendente" (prioridad de cobranza).
- RB-7 **El directorio está acotado a la organización**: solo se ven sus propias fichas. Una persona que es cliente de dos negocios aparece en dos directorios, y ninguno sabe del otro (`REQ-ACC-007` RB-4).
- RB-8 La columna de "cuenta vinculada" muestra solo un indicador booleano (`hasLinkedAccount`); **nunca** el `account_id` ni datos de la cuenta (`REQ-ACC-007` RB-5).
- RB-9 Ambos roles (`owner` y `member`) acceden al directorio.

**Happy path**
1. Un cliente escribe por WhatsApp; el operador busca "0414-1234567" y encuentra la ficha.
2. Ve que tiene 2 apartados, $270 pendientes y una cuota vencida.
3. Abre el perfil para el detalle (`REQ-BOF-041`).

**Sad paths y edge cases**
- SP-1 Búsqueda sin resultados → estado vacío con la sugerencia de revisar el formato del documento o del teléfono.
- SP-2 Documento escrito de formas distintas (`12.345.678`, `V-12345678`) → encuentra la misma ficha.
- SP-3 Teléfono escrito con o sin prefijo → encuentra la misma ficha (normalización a E.164).
- SP-4 Ficha sin apartados → aparece con agregados en cero (caso raro: las fichas nacen del apartado).
- SP-5 Decenas de miles de clientes → paginador simple sin `count(*)`, búsqueda apoyada en `(organization_id, document_number)` y `(organization_id, phone)`.
- SP-6 Fichas duplicadas de la misma persona → se resuelve con la fusión manual (`REQ-IDN-006`).
- SP-7 Cliente con solo apartados terminales → no aparece en los filtros de cobranza, sí en la búsqueda directa.
- SP-8 **Ficha de otra organización** → no existe bajo el global scope; buscar su documento no devuelve nada (test de fuga).

**Fuera de alcance inmediato**
- Edición de los datos del cliente desde el backoffice (se actualizan al apartar de nuevo).
- Notas internas sobre el cliente.
- Etiquetado o segmentación manual de clientes.
- Creación manual de fichas.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/CustomerController.php`, `app/Queries/CustomerDirectoryQuery.php`, `resources/js/pages/backoffice/customers/Index.tsx`.
- Tipos esperados: `CustomerDirectoryQuery`, `CustomerDirectoryRow`, `CustomerDirectoryFilters`.
- Anti-`SHALLOW`: `CustomerDirectoryQuery::search()` (detección de criterio, normalización de documento y teléfono, agregación, orden de prioridad) supera 4 líneas.
- Tests: búsqueda por los cuatro criterios; documento y teléfono con formatos variados; sin `N+1`; orden de prioridad de cobranza; el `Resource` no expone `account_id`; **el documento de un cliente de otra organización no devuelve resultados** (fuga); filtro por catálogo de origen.

> **Cómo quedó, en `T-065`.**
> - **Una caja, `CustomerSearch`**, compartida con la cobranza (`REQ-BOF-042` RB-3), y en este orden: teléfono (E.164, SP-3); **solo cifras** (`12.345.678`, `0414123`, `4567`): el documento **o** el teléfono que las contienen; documento con su letra, exacto (SP-2); correo, exacto sin mayúsculas; nombre, cada palabra en cualquier orden. Las cifras sueltas son un añadido a RB-1: lo que se dicta por teléfono llega a trozos, y una cédula sin letra puede ser también medio teléfono —`0414123` es las dos cosas—. Esa búsqueda es `LIKE '%…%'` y no usa el índice de SP-5: dentro de una organización recorre sus fichas, que es aceptable; si un negocio llega a cientos de miles, se cambia por prefijos por tipo de documento.
> - **Los agregados, como subconsultas correlacionadas** sobre `customers` (RB-3): una consulta por página sea cual sea el número de filas, y cada subconsulta atada a la organización de la fila. Paginación simple (SP-5).
> - **«Vencida» y «en mora» son dos cosas.** La columna «Cuotas vencidas» (RB-2) cuenta como el portal (`CustomerBalanceQuery`): sin cubrir y anterior al «hoy» del negocio. «Con mora» (RB-4), el destacado (RB-5) y la tarjeta «Clientes en mora» del panel son **vencida y pasada la gracia del negocio** (`REQ-LAY-007` RB-1): una cuota de ayer, dentro de la gracia, está vencida pero el cliente no está en mora. La gracia se lee en un solo sitio, `Settings::graceDays()`, el mismo que usan la detección de mora y la cobranza.
> - **«Vencimiento próximo»** llega hasta el primer recordatorio previo (`DueCalendar::lastSoonDay()`, como el portal), y **el rango filtra el próximo vencimiento**, el que pinta la fila —no cualquier cuota dentro del rango—.
> - **Orden** (RB-6): cuotas vencidas descendente, luego el próximo vencimiento —los que no tienen ninguno, al final—, nombre e id para que sea estable.
> - **RB-9**: `CustomerPolicy::viewAny()` —capacidad `Customers` y usuario no deshabilitado—. Los dos roles entran.

---

### REQ-BOF-041 · Perfil de cliente y balance consolidado

**Historia de usuario**
> Como operador, quiero ver en una pantalla todo lo de mi cliente —sus apartados, sus pagos y su chat— para resolver su caso sin navegar entre secciones.

**Contratos de datos**
- Ruta `GET /backoffice/clientes/{customer}`. Props `{ customer, totals, layaways, payments, receipts, conversationId, subscriptions }`.

**Reglas de negocio y precondiciones**
- RB-1 El perfil muestra: datos de contacto, fecha de alta, si aceptó términos y cuándo, e **indicador de cuenta vinculada** (sin exponer la cuenta, RB-8 de `REQ-BOF-040`).
- RB-2 Balance consolidado **de esta organización**: total comprometido, pagado, saldo pendiente, cuotas vencidas y próximo vencimiento, calculados igual que en el portal (`REQ-LAY-020` RB-6).
- RB-3 Lista de apartados con su estado, separando activos de terminales, con el catálogo de origen de cada uno.
- RB-4 Historial completo de pagos con la tasa congelada de cada uno (`REQ-FX-006` RB-5) y de comprobantes con su estado y su cuenta de cobro declarada.
- RB-5 Acciones desde el perfil: registrar pago manual (`REQ-PAY-007`), cancelar apartado (`REQ-LAY-009`), marcar entrega (`REQ-LAY-010`), enviar push puntual (`REQ-NOT-004`), abrir el chat (`REQ-CHT-002`), fusionar fichas (`REQ-IDN-006`) y **reenviar el código de apartado** al cliente que lo perdió (`REQ-IDN-008` SP-5).
- RB-6 El backoffice **sí** ve el motivo íntegro de cancelaciones y rechazos y el nombre del miembro que los ejecutó — a diferencia del portal (`REQ-LAY-021` SP-4).
- RB-7 Los comprobantes se abren con URL firmada de 15 minutos (`REQ-PAY-002` RB-3).
- RB-8 Si el cliente sigue alguno de los catálogos del negocio, se indica cuáles (`REQ-ACC-007` RB-3: al ser cliente, la relación comercial justifica verlo).
- RB-9 **Nunca** se muestra actividad del cliente en otras organizaciones, ni el número de negocios en los que opera.

**Happy path**
1. El operador abre el perfil desde el directorio.
2. Ve dos apartados, el historial de 5 pagos, un comprobante rechazado con su motivo y que sigue 2 de sus catálogos.
3. Registra un pago en efectivo desde el mismo perfil y el balance se actualiza al instante.

**Sad paths y edge cases**
- SP-1 Cliente sin apartados → perfil con datos de contacto y estado vacío en las listas.
- SP-2 Cliente con 40 apartados → listas paginadas por 20 con "ver más".
- SP-3 Ficha absorbida por una fusión → su URL redirige a la superviviente con un aviso.
- SP-4 Archivo de comprobante ausente → la fila se muestra con un aviso, sin romper la vista.
- SP-5 Acción ejecutada por otro miembro mientras el perfil está abierto → los datos se refrescan en la siguiente navegación; las acciones validan estado y devuelven `422` si ya cambió.
- SP-6 Cliente que también es cliente de otro negocio → **nada de eso es visible** (RB-9, test explícito).
- SP-7 Reenvío del código a un cliente sin email → se ofrece copiarlo para dictárselo por teléfono o mandarlo por el chat.
- SP-8 Ficha de otra organización → `404`.

**Fuera de alcance inmediato**
- Línea de tiempo unificada de eventos del cliente.
- Score de riesgo o histórico de comportamiento de pago.
- Exportación del perfil individual.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/CustomerShowController.php`, `app/Queries/CustomerBalanceQuery.php` (compartida con el portal), `resources/js/pages/backoffice/customers/Show.tsx`.
- Tipos esperados: `CustomerProfile`, `CustomerBalanceQuery`.
- Anti-`SHALLOW`: cubierto por `CustomerBalanceQuery` (`REQ-LAY-020`); el controlador aporta la composición de las cinco listas y las acciones disponibles.
- Tests: el balance del perfil coincide con el del portal para la misma ficha (equivalencia); motivos íntegros visibles para el negocio; ficha absorbida redirige; URL firmada de 15 min; **no se filtra actividad en otras organizaciones** (test de RB-9); acción sobre estado ya cambiado da `422`; ficha ajena da `404`.

> **Cómo quedó, en `T-065`.**
> - **El balance es el del portal** (RB-2): `CustomerBalanceQuery::forCustomer()` devuelve el mismo `BusinessBalance` que pinta el cliente, y un test compara los dos para la misma ficha.
> - **Las listas** (RB-3, RB-4, SP-2): apartados activos enteros; historial de terminales, pagos y comprobantes cortados en 20 —los más recientes— y **«Ver más» de 20 en 20** con `?mostrar=` hasta 200 (`CustomerShowController::LIST_MAX`): más que eso es una exportación. Cada lista cortada dice cuántas hay.
> - **RB-5, acciones**: cada apartado enlaza a su vista (`backoffice/layaways/Show`, de `T-053`), que es donde están cancelar, entregar y reprogramar —no se duplican—; registrar pago manual está en la vista del comprobante (`T-062`); **reenviar el código** y **fusionar** están en el perfil. El push puntual es de **`T-083`** y el chat de **`T-080`** (`conversationId: null` hasta entonces); los catálogos que sigue (RB-8, `subscriptions: []`) son de **H6**.
> - **Reenviar el código** (SP-5 de `REQ-IDN-008`, SP-7): `ResendLayawayCodeAction` lo manda **solo al correo de la ficha**, con `LayawayCodeNotification` —una propia, no la del alta: no es un apartado nuevo—; sin correo, el aviso de dictarlo. Con techo: **tres por hora y por apartado** (`throttle:reenvio-codigo`, `AppServiceProvider::CODE_RESENDS_PER_HOUR`); el cuarto es `429`. Sin él, un clic repetido llenaría el buzón del cliente con correos a nombre del negocio.
> - **RB-6**: el motivo íntegro y quién: el revisor del comprobante y, para una cancelación, el causante de su auditoría (`layaway.cancelled`); sin causante, «El sistema».
> - **RB-7**: `SignedFileUrl::forBackoffice()` de 15 minutos. **SP-4 queda abierto**: no se comprueba que el archivo exista —sería una llamada al almacenamiento por fila—; un archivo perdido da el error al abrirlo. Lo cierra quien toque la retención de comprobantes (`REQ-PAY-002`, **`T-086`**, Salud).
> - **SP-3**: la ruta tiene un `missing()` que, si el id es de una ficha absorbida (`customer_merges`, siguiendo la cadena), redirige a la superviviente con un aviso; si no, `404`.
> - **SP-5**: lo cubren las acciones que ya existían —cada una valida estado y devuelve su `422`—; el perfil no añade operaciones propias que puedan pisarse, salvo la fusión, que se bloquea por filas (`REQ-IDN-006` SP-4).
> - **RB-9, SP-6**: todo pasa por el global scope, también un comprobante de otro negocio que apuntara por error a esta ficha (test).

---

### REQ-BOF-042 · Filtros operativos de cobranza

**Historia de usuario**
> Como operador de cobranza, quiero filtros listos por "vencen esta semana" o "corte quincenal", para trabajar mi cartera sin construir consultas a mano.

**Contratos de datos**
- `CollectionFilters` y `CollectionPreset` (ver contratos compartidos).
- Ruta `GET /backoffice/cobranza`.

**Reglas de negocio y precondiciones**
- RB-1 `CollectionFilters` es la **única fuente de verdad de la query**: la usan la vista, la exportación (`REQ-BOF-043`) y el envío segmentado de push (`REQ-NOT-004`). Duplicar la lógica de filtrado está prohibido.
- RB-2 Presets:
  - `due_today`: cuotas con `due_date = hoy`;
  - `due_within_7_days`: entre hoy y hoy+7;
  - `overdue_over_15_days`: vencidas con más de 15 días pasada la gracia;
  - `fortnight_first_half` / `fortnight_second_half`: cuotas del corte 1–15 o 16–fin del mes en curso;
  - `completed_not_delivered`: apartados `completed` sin `delivered_at`.
- RB-3 Combinables con rango de fechas libre, estado del apartado, categoría, **catálogo de origen** y búsqueda de cliente.
- RB-4 Los cálculos de fecha se hacen en la **zona horaria de la organización** sobre fechas, no instantes (`REQ-LAY-007` RB-1), y el período de gracia es el de **esa** organización.
- RB-5 Los presets excluyen por defecto los apartados terminales, salvo `completed_not_delivered`.
- RB-6 El resultado muestra una fila por **cuota** (no por apartado), con cliente, teléfono, apartado, número de cuota, vencimiento, importe, pagado, días de atraso y acciones rápidas: registrar pago, enviar push, abrir chat y **copiar el teléfono**.
- RB-7 El estado de los filtros vive en la query string y es compartible entre miembros del equipo.
- RB-8 La consulta está acotada por el global scope; un preset nunca puede devolver cuotas de otra organización.
- RB-9 **Disponible con la organización `suspended`** en modo lectura (`REQ-SUB-007` RB-4): cobrar lo ya vendido no se bloquea.

**Happy path**
1. El operador abre Cobranza y pulsa "Corte quincenal 1–15".
2. Ve 34 cuotas con vencimiento en la primera quincena, ordenadas por fecha, con el teléfono de cada cliente a la vista.
3. Exporta el resultado a Excel con los mismos filtros (`REQ-BOF-043`).

**Sad paths y edge cases**
- SP-1 Preset sin resultados → estado vacío con la definición exacta del preset.
- SP-2 Rango invertido (`dueFrom > dueTo`) → ~~`422`~~ no se aplica ninguno de los dos extremos, y la tabla dice que se ignoraron (`REQ-BOF-045` SP-4). `CollectionFilters` sí lo rechaza (`InvalidArgumentException`) para quien lo construya a mano —la exportación, el envío segmentado—. *(Corregido en `T-065`: ver la nota de abajo.)*
- SP-3 Preset combinado con un rango que lo contradice → se aplican ambos (intersección) y la UI advierte que puede quedar vacío.
- SP-4 Cambio del ajuste de gracia entre la vista y la exportación → la exportación recalcula con el vigente al ejecutarse y registra el valor usado en la cabecera del archivo.
- SP-5 Cuota de un apartado cancelado → excluida por RB-5.
- SP-6 Miles de filas → paginación de servidor; la exportación completa va por cola.
- SP-7 Dos organizaciones con gracias y zonas distintas → cada preset se evalúa con los parámetros de la suya.
- SP-8 Filtro por un catálogo despublicado → válido: sirve para medir el rendimiento histórico de una campaña.

**Fuera de alcance inmediato**
- Presets definidos por el usuario y guardados.
- Asignación de cartera por operador.
- Marcado de "contactado" y gestión de seguimiento.

**Gate de validación**
- Superficie esperada: `app/Queries/CollectionFilters.php`, `app/Domain/Collections/CollectionPreset.php`, `app/Http/Controllers/Backoffice/CollectionController.php`, `resources/js/pages/backoffice/collections/Index.tsx`, `resources/js/features/collections/hooks/useCollectionFilters.ts`.
- Tipos esperados: `CollectionFilters`, `CollectionPreset`, `CollectionRow`.
- Anti-`SHALLOW`: `CollectionFilters::apply()` (los seis presets, rango, estados, búsqueda, catálogo, con la gracia y la zona de la organización) y `CollectionPreset::label()` superan 4 líneas.
- Tests: un test por preset con datos en los límites exactos de fecha; rango invertido rechazado; terminales excluidos; **la vista y la exportación devuelven el mismo conjunto** (equivalencia, garantía de RB-1); dos organizaciones con parámetros distintos; nunca devuelve cuotas de otra organización (fuga); funciona con `suspended`.

> **Cómo quedó, en `T-065`.**
> - **Las filas son cuotas sin cubrir** (RB-6): una cuota pagada entera no es cartera aunque venza hoy; «Pagado» puede ser parcial. Los presets y la lista sin preset ven los apartados que cobran —`active` y `overdue`; `pending_down_payment` aún no tiene cuotas que cobrar— (RB-5); el filtro de estado elige uno.
> - **`completed_not_delivered` lista apartados, no cuotas**: un apartado pagado sin entregar puede no tener cuotas —financiado en cero—, y una lista de cuotas no lo vería. `CollectionFilters::query()` devuelve la forma que toca, `installments()` o `layaways()`, y la pantalla cambia de columnas. El rango libre, con ese preset, filtra `completed_at` en días **del negocio**.
> - **Los bordes, en la zona y con la gracia del negocio** (RB-4, SP-7): `overdue_over_15_days` es `due_date < hoy − gracia − 15`; «en 7 días» es de hoy a hoy + 7, ambos incluidos (ocho días: la tarjeta del panel «Vencen esta semana» es este preset); las quincenas, del 1 al 15 y del 16 al último del mes en curso. Un test por preset en sus bordes exactos, con el negocio en Tokio y el reloj en Caracas para que un «hoy» de la aplicación se note.
> - **SP-2 se separó en dos** (ver arriba): `CollectionFilters` sigue rechazando un rango invertido, pero **la pantalla no responde `422`**: es una página que se abre desde un enlace —el panel, un compañero (RB-7)— y un error de validación en un `GET` no tiene formulario al que volver. Lo que no se puede usar —un preset o una fecha que no existen, una categoría o catálogo de otro negocio, un rango invertido— no se aplica y se anota en `meta.ignoredFilters`, como en el resto de tablas (`REQ-BOF-045` SP-4). Del rango invertido se descartan **los dos extremos**: quedarse con uno sería un filtro que nadie pidió.
> - **RB-1, equivalencia**: la pantalla no filtra por su cuenta. Un test por preset comprueba que sus filas son exactamente las de `CollectionFilters`, en su orden. La exportación (**`T-070`**) y el envío segmentado (**`T-083`**) tienen que construir un `CollectionFilters` y pedirle `query()`; el test de equivalencia de la exportación es de `T-070`.
> - **RB-6, acciones rápidas**: el apartado enlaza a su vista, y el teléfono se copia o se marca. Registrar pago vive en la vista del comprobante (`T-062`); **el push puntual es de `T-083`** y **abrir el chat de `T-080`**.
> - **RB-9**: la ruta está en `during-suspension`; la autoriza `LayawayPolicy::viewAny()` —capacidad `Collections`, usuario no deshabilitado—.
> - Los días de atraso se cuentan en el calendario del negocio, como en el portal.

---

### REQ-BOF-043 · Exportación asíncrona a Excel

**Historia de usuario**
> Como negocio, quiero descargar en Excel mi cartera o mi inventario según lo que estoy viendo, sin que la pantalla se quede colgada.

**Contratos de datos**
- Entidad `ExportJob` (`export_jobs`) con `organization_id`.
- Job `GenerateExport` en la cola `exports`; `maatwebsite/excel` en modo `FromQuery` con chunking.
- Rutas `POST /backoffice/exportaciones`, `GET /backoffice/exportaciones`, `GET /backoffice/exportaciones/{export}/descargar` (firmada).
- **Requiere:** función de plan `excel_export`. **Consume:** métrica `storage_mb`.

**Reglas de negocio y precondiciones**
- RB-1 **Es función de plan** (`excel_export`, Pro y Business). En Free el botón se muestra **deshabilitado con el motivo**, no oculto (`REQ-SUB-004` RB-7): el negocio debe ver lo que se está perdiendo.
- RB-2 La exportación **siempre** va por cola; el request solo crea el `ExportJob` en `queued` y responde de inmediato.
- RB-3 Los filtros activos se serializan y se rehidratan en el job como `CollectionFilters` (`REQ-BOF-042` RB-1): el archivo contiene exactamente lo que el operador veía.
- RB-4 Formato `.xlsx`. Tipos: `customers`, `inventory`, `collections`.
- RB-5 El archivo se guarda en disco **privado bajo la ruta de la organización** y se entrega por **URL firmada** con TTL `platform_settings.export_link_ttl_hours` (24 h).
- RB-6 Al terminar se notifica al miembro que la pidió (in-app + email) con el enlace.
- RB-7 Un job de limpieza purga los archivos y marca los `ExportJob` como caducados a los **7 días**, liberando cuota de almacenamiento.
- RB-8 Los importes se exportan en **columnas separadas**: monto USD, monto declarado, moneda y tasa aplicada (`REQ-FX-006` RB-6); nunca texto mezclado en una celda.
- RB-9 **La exportación no incluye `account_id` ni ningún dato de la cuenta de consumidor** (`REQ-ACC-007` RB-5), y **no existe exportación de suscriptores** (`REQ-ACC-007` RB-7).
- RB-10 Límite de **una exportación en curso por organización y tipo**; un segundo intento devuelve el job existente.
- RB-11 Lectura con `chunkById`, sin cargar todas las filas en memoria, y con el tenant fijado explícitamente en el job (`REQ-ORG-002` RB-5).
- RB-12 Un `ExportJob` solo es descargable por miembros de **su** organización, además de la firma.

**Happy path**
1. El operador filtra la cobranza por "corte quincenal 1–15" y pulsa "Exportar a Excel".
2. La UI confirma "estamos generando tu archivo, te avisamos" y aparece la fila en `queued`.
3. El job procesa 34.000 filas en lotes, guarda el `.xlsx` y notifica.
4. El operador descarga con el enlace firmado.

**Sad paths y edge cases**
- SP-1 Plan Free → botón deshabilitado con el motivo y el plan que lo desbloquea; la llamada directa por API da `403`.
- SP-2 Job que falla → `status = failed` con `error_message`; se notifica y se permite reintentar.
- SP-3 Enlace caducado → `403` con CTA para regenerar.
- SP-4 Archivo purgado a los 7 días → `410` con la explicación.
- SP-5 Segunda solicitud del mismo tipo en curso → devuelve el job existente.
- SP-6 Filtros que dejan de ser válidos entre la solicitud y la ejecución (etiqueta borrada) → el job los aplica de forma tolerante e ignora los inexistentes, anotándolo en la cabecera del archivo.
- SP-7 Exportación de 0 filas → se genera con solo la cabecera y se notifica; no es un fallo.
- SP-8 Descarga por otro miembro de la misma organización → permitida (no hay RBAC), con la firma obligatoria.
- SP-9 **Descarga intentada por un miembro de otra organización** con una URL firmada válida → `403` (RB-12, test explícito).
- SP-10 Worker caído → el job permanece en `queued`; la UI muestra su antigüedad.
- SP-11 Cuota de almacenamiento agotada → el job falla con el motivo y sugiere purgar exportaciones antiguas.
- SP-12 Bajada de plan a Free con exportaciones ya generadas → los archivos **siguen descargables** hasta caducar (`REQ-SUB-008` RB-1); solo no se pueden crear nuevas.

**Fuera de alcance inmediato**
- Exportación a CSV o PDF.
- Exportaciones recurrentes programadas.
- Envío del archivo como adjunto.
- Exportación de suscriptores (prohibida por diseño).

**Gate de validación**
- Superficie esperada: `app/Jobs/GenerateExport.php`, `app/Exports/CustomersExport.php`, `app/Exports/InventoryExport.php`, `app/Exports/CollectionsExport.php`, `app/Models/ExportJob.php`, `app/Http/Controllers/Backoffice/ExportController.php`, `app/Jobs/PurgeExpiredExports.php`.
- Tipos esperados: `ExportJob`, `ExportType`, `ExportStatus`, `CollectionsExport`, `ExportRequestData`, `PlanFeatureUnavailableException`.
- Anti-`SHALLOW`: `GenerateExport::handle()` (fijación del tenant, rehidratación de filtros, chunking, escritura, notificación, estado) y `CollectionsExport::query()` superan 4 líneas.
- Tests: Free da `403` por API y botón deshabilitado en UI; el request no genera el archivo (job encolado y respuesta inmediata); el contenido coincide con la vista (equivalencia); enlace caducado `403` y purgado `410`; segunda solicitud reutiliza; 0 filas genera cabecera; **el archivo no contiene `account_id`** (assert de columnas); descarga desde otra organización `403`; `chunkById` sin cargar todo; bajar a Free conserva las descargas existentes.

---

### REQ-BOF-044 · Dashboard del negocio

**Historia de usuario**
> Como negocio, quiero ver al entrar qué requiere mi atención hoy y cómo voy con mi plan, para empezar por lo que importa.

**Contratos de datos**
- Ruta `GET /backoffice`. Props `DashboardMetrics`.

```ts
export interface DashboardMetrics {
  readonly pendingReceipts: number;
  readonly overdueReceiptsCount: number;      // en revisión más de 48 h
  readonly collectedThisMonth: MoneyView;
  readonly dueThisWeek: { readonly count: number; readonly amount: MoneyView };
  readonly customersOverdue: number;
  readonly longOverdueLayaways: number;
  readonly completedNotDelivered: number;
  readonly unreadMessages: number;
  readonly rate: RateView;
  /** Consumo del plan y avisos de límite. */
  readonly plan: PlanState;
  /** Alertas operativas que solo el negocio puede resolver. */
  readonly warnings: readonly DashboardWarning[];
  readonly subscribers: { readonly total: number; readonly last7Days: number };
}

export type DashboardWarning =
  | { readonly kind: 'no_active_payment_account' }
  | { readonly kind: 'published_catalog_empty'; readonly catalogSlug: string }
  | { readonly kind: 'product_published_in_no_catalog'; readonly count: number }
  | { readonly kind: 'stale_exchange_rate'; readonly ageHours: number }
  | { readonly kind: 'plan_limit_blocking_sales'; readonly metric: UsageMetric }
  | { readonly kind: 'invoice_overdue'; readonly dueDate: string }
  | { readonly kind: 'email_not_verified' };
```

**Reglas de negocio y precondiciones**
- RB-1 Métricas: comprobantes por revisar (y cuántos atrasados), cobrado del mes en USD, cuotas que vencen esta semana con su importe, clientes en mora, apartados con mora prolongada, completados sin entregar, mensajes sin leer, tasa vigente con su antigüedad, **consumo del plan** y **suscriptores**.
- RB-2 "Cobrado del mes" suma `payments.amount_cents_usd` con `settled_at` en el mes en curso **en la zona de la organización**, no los montos declarados.
- RB-3 Cada tarjeta enlaza al listado ya filtrado que la origina, compartiendo la query (`REQ-BOF-042` RB-1).
- RB-4 **Los `warnings` son la parte más valiosa del dashboard**: recogen las condiciones que hacen que el negocio esté perdiendo ventas o dinero sin darse cuenta, y cada una tiene una acción concreta que solo él puede tomar:
  | Aviso | Origen |
  |---|---|
  | Sin cuenta de cobro activa | `REQ-ORG-007` SP-4 |
  | Catálogo publicado y vacío | `REQ-CAT-021` SP-4 |
  | Productos publicados en 0 catálogos | `REQ-CAT-007` RB-6 |
  | Tasa de cambio obsoleta | `REQ-FX-005` RB-6 |
  | **Un límite del plan está bloqueando ventas** | `REQ-SUB-004` SP-2 |
  | Factura de suscripción vencida | `REQ-SUB-007` RB-2 |
  | Email del `owner` sin verificar | `REQ-ORG-001` RB-5 |
- RB-5 El aviso `plan_limit_blocking_sales` se muestra **solo cuando hay evidencia real de pérdida** (un intento de apartado rechazado por límite en los últimos 7 días), no por proximidad al techo. Avisar cuando de verdad cuesta dinero es honesto y convierte; avisar de forma preventiva y constante es ruido.
- RB-6 El consumo del plan se muestra con las métricas al 80 % o más destacadas (`REQ-SUB-003` RB-7).
- RB-7 Métricas calculadas en una pasada de consultas agregadas (máximo una por métrica), cacheadas **60 segundos**, con fallo aislado por métrica.
- RB-8 Sin gráficas ni series temporales en v1: cifras, avisos y enlaces.
- RB-9 El badge de comprobantes se actualiza en tiempo real (`REQ-PAY-004`).
- RB-10 Con la organización `suspended`, el dashboard se muestra en modo restringido con el aviso de pago en primer lugar.

**Happy path**
1. El negocio entra y ve "6 comprobantes por revisar (2 atrasados)" y, arriba, el aviso "un intento de apartado fue rechazado por tu límite de 15 apartados activos".
2. Pulsa la tarjeta de comprobantes y llega a la bandeja filtrada por pendientes.
3. Ve "Plan Free · 28 de 30 productos" con el aviso del 80 %.

**Sad paths y edge cases**
- SP-1 Instalación nueva sin datos → todas las cifras en cero, con el checklist de arranque en lugar de métricas vacías (`REQ-ORG-001` happy path).
- SP-2 Sin tasa disponible → la tarjeta lo indica y las métricas monetarias se muestran solo en USD.
- SP-3 Redis caído → se calculan sin caché, más lento.
- SP-4 Métrica cuyo cálculo falla → guion en su tarjeta y el resto carga.
- SP-5 Caché de 60 s desactualizando un badge tras aprobar → el badge en tiempo real corrige la percepción.
- SP-6 Sin ningún `warning` → la sección no se renderiza; no se inventan avisos para llenar espacio.
- SP-7 Organización `suspended` → aviso de pago primero, métricas de cobranza visibles, creación bloqueada.

**Fuera de alcance inmediato**
- Gráficas, series temporales y comparativas mes a mes.
- Dashboard configurable.
- Exportación del dashboard.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/DashboardController.php`, `app/Queries/DashboardMetricsQuery.php`, `app/Queries/DashboardWarningsQuery.php`, `resources/js/pages/backoffice/Dashboard.tsx`, `resources/js/features/plan/components/LimitBanner.tsx`.
- Tipos esperados: `DashboardMetrics`, `DashboardWarning`, `DashboardMetricsQuery`, `DashboardWarningsQuery`, `PlanState`.
- Anti-`SHALLOW`: `DashboardMetricsQuery::compute()` (métricas, caché, aislamiento de fallos) y `DashboardWarningsQuery::forOrganization()` (las siete condiciones de RB-4, incluida la evidencia de venta perdida) superan 4 líneas.
- Tests: cada métrica con datos sembrados; "cobrado del mes" usa `amount_cents_usd` y la zona de la organización; los enlaces llevan al filtro equivalente (equivalencia con `CollectionFilters`); **cada uno de los siete `warnings` se dispara en su condición y no fuera de ella**; `plan_limit_blocking_sales` requiere evidencia real (test de RB-5); caché de 60 s; Redis caído funciona; fallo de una métrica no rompe la página.

> **Cómo quedó, en `T-065`.**
> - **Cada tarjeta sale de la consulta de su lista** (RB-3): «Vencen esta semana» es el preset `due_within_7_days` de `CollectionFilters`, con su importe pendiente; «Mora de más de 15 días» cuenta los **apartados** distintos del preset `overdue_over_15_days` —la lista a la que lleva es de cuotas, así que puede mostrar más filas que la cifra—; «Completados sin entregar», su preset; «Clientes en mora», el directorio con «con mora» (mora con gracia, ver `REQ-BOF-040`). Un test compara cifra y lista.
> - **RB-2**: `amount_cents_usd` con `settled_at` en el mes **del negocio**, convertido a la zona de la aplicación para la consulta.
> - **RB-4, seis de los siete avisos**: sin cuenta de cobro activa; catálogo publicado, vigente y sin productos publicados (como mucho tres nombrados); productos publicados en ningún catálogo; tasa obsoleta, con sus horas; factura vencida; correo del dueño sin verificar. **`plan_limit_blocking_sales` es de `T-067`**: RB-5 exige evidencia de una venta perdida —un apartado rechazado por el límite en los últimos 7 días— y nadie registra todavía esos rechazos; `T-067` inserta los límites en las acciones, y con ellos el registro y el aviso. **La factura vencida se lee del estado de la suscripción** (`past_due`, `grace` o `suspended`, con el fin del período como fecha) hasta que existan las facturas (**`T-069`**).
> - **RB-6**: las métricas del plan al 80 % o más, destacadas. **RB-1**: la tasa, con su antigüedad.
> - **RB-7, SP-3, SP-4**: una consulta agregada por métrica, cada una aislada —la que falla es `null`, un guion— y el conjunto en caché 60 s por organización. **Una pasada con una métrica caída no se guarda**: el guion duraría un minuto y, si la que cae es la de avisos, una lista vacía parecería «no hay avisos». Sin caché, se calcula igual.
> - **SP-5**: lo que el negocio arregla —una cuenta de cobro, el correo verificado— puede tardar hasta 60 s en desaparecer del panel; la caché es de la spec y no se invalida por evento. El badge en tiempo real de los comprobantes (RB-9) ya existía.
> - **RB-10, SP-7**: la suspensión la anuncia el banner del layout, y el aviso de factura vencida sale primero en la lista.
> - **Lo que todavía no existe vuelve `null`** y no se pinta: mensajes sin leer (**`T-080`**) y suscriptores (**H6**).
> - **SP-1**: mientras al negocio le falte una cuenta de cobro, un producto o un catálogo publicado, el checklist de arranque va **encima** de las tarjetas, no en su lugar: las cifras en cero también dicen algo, y cuando aparece el primer comprobante tiene que verse.

---

### REQ-BOF-045 · Tablas server-driven

**Historia de usuario**
> Como negocio, quiero tablas que paginen, ordenen y filtren rápido aunque tenga decenas de miles de filas, para trabajar sin esperas.

**Contratos de datos**
- Hook `useServerTable<TRow>(options)` sobre `@tanstack/react-table`.
- Componente `<DataTable />` reutilizable en `resources/js/features/data-table/`.
- Contrato `Paginated<T>` en las page props.

```ts
export interface ServerTableState {
  readonly page: number;
  readonly perPage: number;
  readonly sort: string | null;      // "due_date" | "-due_date"
  readonly filters: Readonly<Record<string, string | string[] | null>>;
}

/** Lo que la recarga parcial devuelve junto a las filas (RB-2). */
export interface TableMeta extends ServerTableState {
  readonly total: number | null;     // null en la paginación simple (RB-6): no se contó
  readonly lastPage: number | null;
  readonly hasMore: boolean;
  readonly ignoredFilters: readonly string[]; // SP-4
}

/** Las page props de una tabla: las dos que pide la recarga parcial. */
export interface Paginated<T> {
  readonly rows: readonly T[];
  readonly meta: TableMeta;
}
```

> **`Paginated<T>` es solo esto, y la cara pública no lo usa** (`T-038`). Hasta esta tarea el mismo nombre estaba declarado dos veces con dos formas: aquí, y en `resources/js/types/catalog-view.ts` como `{ data, nextCursor }` para el «Cargar más» del catálogo público (`REQ-CAT-021`) y del feed (`REQ-ACC-003`). Son dos paginaciones distintas —esta con página, orden y, en las tablas pequeñas, total; aquella por cursor, sin total ni número de página, porque en la superficie pública el `count(*)` está prohibido— y con el mismo nombre cualquier import equivocado compilaba. La del cursor **se llama `CursorPage<T>`** desde `T-038`, y los contratos de [12] y [15] lo dicen así. Los filtros aplicados viajan **resueltos** dentro de `meta.filters`, con el texto que vuelve a la query string, y no en una prop aparte: la recarga parcial pide `rows` y `meta` y nada más.

**Reglas de negocio y precondiciones**
- RB-1 `@tanstack/react-table` en **modo manual**: `manualPagination`, `manualSorting`, `manualFiltering`. El cliente **nunca** ordena ni filtra el conjunto completo.
- RB-2 Los datos llegan por Inertia partial reloads con `only: ['rows', 'meta']`, con `preserveState` y `preserveScroll`.
- RB-3 El estado se sincroniza con la query string: la vista es recargable y compartible entre miembros del equipo.
- RB-4 El ordenamiento solo admite columnas **declaradas en una lista blanca del servidor**; un campo arbitrario se ignora y cae al orden por defecto. Es también una defensa: sin lista blanca, `sort` sería un vector de inyección y de fuga de esquema.
- RB-5 `perPage` ∈ {25, 50, 100}; cualquier otro valor cae a 25.
- RB-6 Paginación **simple** (sin `count(*)`) en las tablas grandes: cobranza, clientes, comprobantes y suscriptores. Las pequeñas (equipo, categorías, catálogos, cuentas de cobro) pueden usar paginación completa.
- RB-7 Estados: cargando (skeleton sin salto de layout), vacío (con la definición del filtro aplicado) y error (con reintento).
- RB-8 En móvil las tablas se degradan a **tarjetas apiladas** con las columnas prioritarias; el `body` nunca scrollea en horizontal.
- RB-9 Ningún componente de tabla supera las 200 líneas: `DataTable` compone `TableHeader`, `TableBody`, `TablePagination` y `TableToolbar` (`REQ-QAX-002` RB-1).
- RB-10 El mismo componente sirve al backoffice y al **backoffice de plataforma** (`REQ-PLT-002`), con la diferencia de que allí las consultas usan `withoutTenantScope()`.

**Happy path**
1. El operador abre Cobranza; la tabla carga 25 filas con el orden por defecto.
2. Ordena por "días de atraso"; Inertia recarga solo `rows` y `meta`, y la URL refleja `sort=-days_overdue`.
3. Comparte la URL con un compañero de su equipo, que ve exactamente la misma vista.

**Sad paths y edge cases**
- SP-1 `sort` con una columna no permitida → se ignora y se usa el default, sin error.
- SP-2 `perPage=5000` → cae a 25.
- SP-3 `page` mayor que el total → última página con datos o estado vacío, sin `404`.
- SP-4 Filtro con un valor inválido (id inexistente o de otra organización) → se ignora ese filtro y se avisa en la UI.
- SP-5 Recarga parcial que falla por red → se conserva la tabla anterior y se ofrece reintentar; no se vacía la vista.
- SP-6 Navegación atrás del navegador → restaura el estado desde la query string.
- SP-7 Doble clic rápido en el encabezado → la segunda petición cancela la primera (Inertia lo hace por defecto).
- SP-8 URL compartida con un miembro de **otra** organización → sus filtros se aplican sobre **sus** datos; nunca ve los del remitente (el global scope lo garantiza).

**Fuera de alcance inmediato**
- Columnas configurables y vistas guardadas por usuario.
- Selección múltiple con acciones masivas.
- Edición en línea.

**Gate de validación**
- Superficie esperada: `resources/js/features/data-table/components/DataTable.tsx`, `resources/js/features/data-table/hooks/useServerTable.ts`, `app/Http/Controllers/Concerns/ResolvesTableState.php`, `app/Support/SortWhitelist.php`.
- Tipos esperados: `ServerTableState`, `Paginated<T>`, `useServerTable`, `SortWhitelist`, `ResolvesTableState`.
- Anti-`SHALLOW`: `useServerTable` (sincronización con la query string, recarga parcial, guardas de `perPage` y `sort`) y `SortWhitelist::resolve()` superan 4 líneas.
- Tests: `sort` no permitido cae al default; `perPage` fuera de la lista cae a 25; `page` desbordada no da error; recarga parcial pide solo `rows` y `meta`; estado restaurado desde la URL; **una URL compartida entre organizaciones muestra los datos de cada una** (fuga); ningún componente de tabla supera 200 líneas (regla ESLint en CI).
