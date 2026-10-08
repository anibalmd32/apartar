# Módulo 21 — Portal privado del cliente (`LAY` 020+)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [10-identidad-y-autenticacion.md](10-identidad-y-autenticacion.md), [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md), [20-apartado-y-cronograma.md](20-apartado-y-cronograma.md)
> Requisitos: `REQ-LAY-020` … `REQ-LAY-023` · Ruta base: `/mi-cuenta`
>
> **Efecto del pivote:** el portal deja de pertenecer a una tienda y pasa a ser **de la persona**. Opera en dos modos de sesión (`REQ-ACC-005`) y, en el modo de cuenta, muestra apartados de **varios negocios agrupados**. La portada combina deliberadamente "lo que debo" con "novedades", porque el ciclo de cobranza es lo que trae a la persona de vuelta y el feed es lo que monetiza esa visita (§4.6 de [03](03-pivote-saas-impacto.md)).

## Contratos compartidos del módulo

```ts
export interface PortalDashboard {
  readonly session: { readonly mode: 'account' | 'scoped'; readonly displayName: string };
  /** Un bloque por negocio donde la persona tiene ficha visible. */
  readonly businesses: readonly BusinessBlock[];
  /** Agregado de TODOS los negocios. Solo en modo cuenta. */
  readonly totals: PortalTotals | null;
  readonly alerts: readonly PortalAlert[];
  /** Primeros ítems del feed. null en sesión acotada. */
  readonly feedPreview: readonly FeedItem[] | null;
  readonly subscriptionsCount: number | null;
}

export interface BusinessBlock {
  readonly business: { readonly name: string; readonly slug: string; readonly logoUrl: string | null };
  readonly layaways: readonly LayawaySummary[];
  readonly totals: PortalTotals;
  readonly unreadMessages: number;
  readonly pendingReceipts: number;
}

export interface PortalTotals {
  readonly activeLayaways: number;
  readonly committed: MoneyView;
  readonly paid: MoneyView;
  readonly remaining: MoneyView;
  readonly overdueInstallments: number;
  readonly nextDueDate: string | null;
}

export interface InstallmentRow {
  readonly number: number;
  readonly dueDate: string;
  readonly amount: MoneyView;
  readonly paid: MoneyView;
  readonly status: 'pending' | 'partially_paid' | 'paid' | 'overdue';
  readonly daysUntilDue: number;   // negativo si está vencida
}

export interface PaymentRow {
  readonly settledAt: string;
  readonly amountUsd: MoneyView;
  readonly declaredAmount: string;
  readonly rateApplied: string | null;
  readonly rateSource: 'bcv' | 'parallel' | 'manual';
  readonly method: string;
  readonly appliedTo: readonly number[];
  readonly registeredByBusiness: boolean;
}
```

---

### REQ-LAY-020 · Portada del portal: lo que debo y las novedades

**Historia de usuario**
> Como cliente con apartados en varios negocios, quiero ver de un golpe cuánto debo y qué vence pronto, y de paso enterarme de lo nuevo de los negocios que sigo, para no perder el control y descubrir qué más puedo apartar.

**Contratos de datos**
- Ruta `GET /mi-cuenta`. Props: `PortalDashboard`.
- `PortalDashboardController`, `CustomerBalanceQuery`, `PortalAlertsQuery`, `FeedQuery` (`REQ-ACC-003`).

**Reglas de negocio y precondiciones**
- RB-1 Exige una de las dos sesiones (`REQ-ACC-005`); sin ninguna, redirección a `/mi-cuenta/acceso` preservando `intended`.
- RB-2 **Los apartados se agrupan por negocio**, en bloques con el nombre y el logo del negocio. Nunca se mezclan apartados de dos negocios en una misma lista: cada deuda es con una empresa distinta y confundirlas sería un error de fondo, no de presentación.
- RB-3 En **modo cuenta**, `businesses` contiene un bloque por cada ficha vinculada y `totals` agrega todos. En **modo acotado**, hay exactamente un bloque y `totals` es `null` (no hay nada que agregar).
- RB-4 Los bloques se ordenan por **vencimiento más próximo**: quien vence antes aparece arriba. Es la información por la que la persona entró.
- RB-5 Cada bloque separa apartados no terminales de los terminales, que van en un "historial" colapsado.
- RB-6 Los agregados se calculan desde los **balances materializados** (`REQ-PAY-008`), no recorriendo pagos: una consulta agregada por organización.
- RB-7 Todos los importes se proyectan en bimoneda respetando el toggle (`REQ-FX-004`), y cada bloque usa la **fuente de tasa de su negocio** (`REQ-FX-003` RB-1). Dos bloques pueden mostrar tasas distintas, y cada uno lleva su sello.
- RB-8 **La portada combina deuda y novedades**: primero las alertas (`REQ-LAY-023`), luego los bloques de negocio, y al final `feedPreview` con hasta 6 ítems y enlace al feed completo. En modo acotado no hay `feedPreview`, y en su lugar se propone conectar Google.
- RB-9 Toda consulta se acota por `organization_id` de la ficha correspondiente; leer N negocios son N consultas acotadas, nunca una sin filtro (`REQ-ACC-005` RB-6).
- RB-10 El portal **no** expone `id` numéricos de cliente ni `account_id`.
- RB-11 Mobile-first: en 375 px es una pila de tarjetas con el resumen del negocio fijo en la cabecera de su bloque.
- RB-12 Funciona con negocios suspendidos: sus apartados se muestran con normalidad (`REQ-SUB-007` RB-3), sin exponer que el negocio no le paga a la plataforma.

**Happy path**
1. La persona entra con Google y aterriza en `/mi-cuenta`.
2. Arriba: "Tienes 1 cuota vencida por $30,00". Debajo, el bloque de la Ferretería (vence en 2 días) y el de la Boutique (vence en 11).
3. Al final ve 6 novedades de los 4 catálogos que sigue y pulsa "ver todas".

**Sad paths y edge cases**
- SP-1 Sesión de cuenta sin ninguna ficha vinculada → sin bloques, con estado vacío que explica cómo reclamar (`REQ-ACC-004`) y con el feed presente si sigue catálogos.
- SP-2 Sesión de cuenta sin fichas y sin suscripciones → estado vacío completo que explica que los catálogos llegan por el enlace del negocio (`REQ-ACC-003` SP-1).
- SP-3 Solo apartados terminales → agregados en cero y el historial expandido.
- SP-4 Sin tasa de cambio → todo en USD (`REQ-FX-005`).
- SP-5 Intento de ver datos de otra persona manipulando la URL → las rutas no aceptan identificador de cliente; el detalle se resuelve por `code` acotado a `PortalContext::customers()` → `404`.
- SP-6 Ficha fusionada durante la sesión → el contexto se reapunta a la superviviente (`REQ-IDN-004` SP-2).
- SP-7 Balance materializado inconsistente → se muestra el valor materializado; la corrección es de `layaways:reconcile` (`REQ-PAY-008`).
- SP-8 Persona con fichas en 8 negocios → los bloques se paginan tras los 5 primeros, con "ver todos mis negocios".
- SP-9 Negocio cerrado (`closed`) → su bloque aparece en solo lectura, indicando que el negocio ya no opera en la plataforma.

**Fuera de alcance inmediato**
- Estado de cuenta descargable en PDF.
- Edición de los datos de contacto desde el portal.
- Gráficas de evolución del pago.
- Ordenar o filtrar los bloques de negocio.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Portal/DashboardController.php`, `app/Queries/CustomerBalanceQuery.php`, `app/Queries/PortalAlertsQuery.php`, `app/Http/Resources/PortalDashboardResource.php`, `resources/js/pages/portal/Dashboard.tsx`, `resources/js/features/layaway/components/BusinessBlock.tsx`.
- Tipos esperados: `PortalDashboard`, `BusinessBlock`, `PortalTotals`, `LayawaySummary`, `CustomerBalanceQuery`.
- Anti-`SHALLOW`: `CustomerBalanceQuery::forPortalContext()` (agregación por organización, separación de terminales, orden por vencimiento) supera 4 líneas junto a su clase.
- Tests: los apartados se agrupan por negocio y nunca se mezclan (test explícito de RB-2); modo acotado tiene un bloque y `totals` nulo; el orden es por vencimiento próximo; una consulta agregada por organización (assert de conteo); `code` ajeno da `404`; dos bloques con fuentes de tasa distintas; negocio suspendido se muestra con normalidad y sin revelar su estado de pago; sin fichas muestra el vacío correcto.

---

### REQ-LAY-021 · Detalle del apartado y cronograma

**Historia de usuario**
> Como cliente, quiero ver el cronograma completo con fechas, importes y qué llevo pagado de cada cuota, para saber exactamente qué me toca y cuándo.

**Contratos de datos**
- Ruta `GET /mi-cuenta/apartados/{code}`. Props `{ layaway, business, installments, payments, receipts, paymentAccounts, nextPaymentSuggestion }`.
- `InstallmentRow` con `daysUntilDue` calculado en servidor.

**Reglas de negocio y precondiciones**
- RB-1 La resolución es por `code`, **acotada a las fichas de `PortalContext`**; un `code` que no le pertenece da `404`, igual que uno inexistente.
- RB-2 El detalle identifica **de qué negocio** es el apartado, con su nombre y logo: en un portal multi-negocio, omitirlo sería confuso.
- RB-3 Se muestran producto (nombre del snapshot), cantidad, total, inicial, pagado, restante, estado y fechas clave.
- RB-4 El cronograma lista todas las cuotas con número, vencimiento, importe, pagado, estado y días hasta el vencimiento, calculados en la **zona horaria de la organización** (`REQ-LAY-007` RB-1).
- RB-5 Marcado visual: vencida (rojo), vence en ≤ 3 días (ámbar), pagada (verde), pendiente (neutro).
- RB-6 En `pending_down_payment` no hay cronograma: se muestran las instrucciones de pago de la inicial, el importe, el tiempo restante hasta `expires_at` y **las cuentas de cobro del negocio** (`REQ-ORG-007`).
- RB-7 **Las cuentas de cobro se muestran siempre** que el apartado acepte pagos, no solo en la inicial: el cliente necesita saber dónde pagar en cada cuota.
- RB-8 El CTA "Subir comprobante" está presente cuando `status.acceptsPayments()` es verdadero, y ausente en terminales.
- RB-9 Se muestra el importe sugerido del próximo pago: la cuota más antigua no cubierta, o el saldo total si quiere liquidar (`REQ-LAY-008`).
- RB-10 Enlace al chat con **ese** negocio (`REQ-CHT-001`) y, si el catálogo de origen sigue accesible, enlace a él.

**Happy path**
1. La persona abre el detalle de `AP-7K3M9QRT` desde el bloque de la Ferretería.
2. Ve el logo del negocio, 5 cuotas (2 pagadas en verde, la del 25/09 en ámbar con "vence en 2 días", 2 pendientes) y las cuentas de cobro.
3. Pulsa "Subir comprobante" con el importe sugerido de $30 precargado.

**Sad paths y edge cases**
- SP-1 `code` inexistente o de otra persona → `404` idéntico en ambos casos.
- SP-2 `pending_down_payment` con ventana vencida y comprobante en revisión → "en revisión, tu reserva está protegida" (`REQ-LAY-006` RB-2).
- SP-3 Apartado `expired` → detalle en solo lectura con explicación y, si el catálogo sigue publicado, enlace para apartar de nuevo.
- SP-4 Apartado `cancelled` → detalle en solo lectura con un texto **neutro** ("cancelado por el negocio"); el motivo escrito por el negocio **no se expone al cliente** (`REQ-LAY-009` RB-8), y se le invita a escribir por el chat.
- SP-5 Cuota parcialmente pagada → "pagado $10 de $30" con el pendiente exacto.
- SP-6 Cronograma de 36 cuotas en móvil → lista virtualizada, sin scroll horizontal del `body`.
- SP-7 Negocio sin cuentas de cobro activas → se indica escribir por el chat (`REQ-CAT-003` SP-6).
- SP-8 Negocio suspendido → el detalle funciona completo, incluida la subida de comprobantes (`REQ-SUB-007` RB-3).
- SP-9 Catálogo de origen despublicado → no se enlaza; el apartado sigue intacto (`REQ-LAY-002` SP-4).

**Fuera de alcance inmediato**
- Exportar el cronograma a PDF o al calendario (ICS).
- Solicitar reprogramación desde el portal.
- Simulador de "qué pasa si pago X".

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Portal/LayawayShowController.php`, `app/Http/Resources/InstallmentRowResource.php`, `resources/js/pages/portal/LayawayShow.tsx`, `resources/js/features/layaway/components/ScheduleTable.tsx`.
- Tipos esperados: `InstallmentRow`, `LayawaySummary`, `ReceiptRow`, `PaymentAccountView`.
- Anti-`SHALLOW`: `InstallmentRowResource::toArray()` (estado visual, `daysUntilDue` en la zona de la organización, importe sugerido) supera 4 líneas junto a su clase.
- Tests: `code` ajeno da `404`; el detalle identifica el negocio; `pending_down_payment` muestra instrucciones y cuentas de cobro en lugar de cronograma; el motivo de cancelación **no** se filtra al cliente (test explícito de SP-4); `daysUntilDue` correcto en el límite de medianoche de la zona de la organización; importe sugerido coincide con la cuota FIFO; negocio suspendido permite subir comprobante.

---

### REQ-LAY-022 · Historial de pagos y de comprobantes

**Historia de usuario**
> Como cliente, quiero ver todos mis pagos con la tasa que se me aplicó y el estado de cada comprobante, para poder reclamar con datos si algo no cuadra.

**Contratos de datos**
- Props `payments: PaymentRow[]` y `receipts: ReceiptRow[]` dentro del detalle del apartado.

```ts
export interface ReceiptRow {
  readonly id: string;              // identificador público opaco
  readonly submittedAt: string;
  readonly method: string;
  readonly reference: string | null;
  readonly declaredAmount: string;
  readonly paidToAccount: string | null;   // cuenta de cobro a la que dice haber pagado
  readonly status: 'pending_review' | 'approved' | 'rejected';
  readonly rejectionReason: string | null;
  readonly fileUrl: string;         // URL firmada, TTL corto
  readonly replacesReceiptId: string | null;
}
```

**Reglas de negocio y precondiciones**
- RB-1 Cada pago se muestra con fecha de conciliación, monto en USD, monto declarado con su moneda, **tasa aplicada y su procedencia** (`REQ-FX-006`) y las cuotas que amortizó.
- RB-2 Los importes históricos **nunca** se reconvierten con la tasa de hoy; llevan la marca "tasa del día del pago". Esto sigue siendo cierto aunque el negocio haya cambiado de fuente de tasa después (`REQ-FX-003` SP-7).
- RB-3 Los comprobantes se listan con su estado; el rechazado muestra el motivo **tal como lo escribió el negocio** — a diferencia del motivo de cancelación, que es interno (`REQ-LAY-021` SP-4). El rechazo de un comprobante es información que el cliente necesita para corregir.
- RB-4 El cliente puede reabrir su propio comprobante mediante URL firmada de **5 minutos** (`REQ-PAY-002`).
- RB-5 Un comprobante que reemplaza a otro se muestra encadenado ("reintento del comprobante del DD/MM").
- RB-6 El cliente **no** ve identificadores internos, ni el nombre del usuario del negocio que revisó, ni `account_id`.
- RB-7 Los pagos registrados manualmente por el negocio aparecen etiquetados "registrado por el negocio" con su nota (`REQ-PAY-007` RB-5).
- RB-8 Se muestra a qué cuenta de cobro dice haber pagado, para que la conversación con el negocio sea concreta.

**Happy path**
1. La persona abre el historial y ve 3 pagos, uno de ellos "Bs. 850,00 · tasa 42,50 (BCV) · $20.00 · cuotas 1 y 2".
2. Ve un comprobante rechazado con el motivo "la referencia no coincide con el banco".
3. Abre la imagen para revisarla y sube uno nuevo.

**Sad paths y edge cases**
- SP-1 URL firmada caducada → `403` con CTA para volver al historial y regenerarla.
- SP-2 Comprobante de otra persona por manipulación del identificador → `404`; la autorización es por policy, no por conocimiento del id.
- SP-3 Archivo perdido en el storage → `404` con aviso de contactar por el chat; el registro permanece.
- SP-4 Pago manual sin comprobante → `fileUrl` ausente y la UI no muestra el enlace.
- SP-5 Muchos pagos (liquidación en 20 abonos) → paginación por 20 con "ver más".
- SP-6 Comprobante en `pending_review` con más de 48 h → "en revisión desde el DD/MM", sin exponer SLA.
- SP-7 Negocio que cambió de fuente de tasa → los pagos históricos conservan la fuente con la que se conciliaron (RB-2).

**Fuera de alcance inmediato**
- Recibo formal descargable por pago.
- Reclamación estructurada sobre un pago (se hace por chat).

**Gate de validación**
- Superficie esperada: `app/Http/Resources/PaymentRowResource.php`, `app/Http/Resources/ReceiptRowResource.php`, `app/Policies/ReceiptPolicy.php`, `app/Support/SignedFileUrl.php`, `resources/js/features/receipts/components/ReceiptHistory.tsx`.
- Tipos esperados: `PaymentRow`, `ReceiptRow`, `SignedFileUrl`.
- Anti-`SHALLOW`: `PaymentRowResource::toArray()` (par declarado/USD, tasa con su fuente, cuotas amortizadas, etiqueta de pago manual) y `SignedFileUrl::forReceipt()` superan 4 líneas.
- Tests: la tasa mostrada es la del snapshot y no cambia al mover la tasa ni al cambiar de fuente; comprobante ajeno da `404`; URL firmada caduca a los 5 min; pago manual sin `fileUrl`; cadena de reintentos visible; motivo de rechazo íntegro; el `Resource` no expone revisor ni `account_id`.

> **Cómo quedó, en `T-061`.**
> - **`ReceiptRow.id` es el UUID del archivo** (`ReceiptFile::publicIdOf()`), y `replacesReceiptId` el del que reemplaza: ya existía, es aleatorio, no dice cuántos comprobantes hay y no abre nada —el archivo solo se sirve con URL firmada, sesión y policy—. Añadir una columna pública solo para esto habría sido una migración sin otro motivo.
> - **`PaymentRow` lleva cuatro campos más**: `rateSourceLabel` (la fuente en palabras), `note` (solo en los pagos registrados por el negocio, RB-7; la nota de una aprobación es del negocio y no sale), y `downPayment` y `unallocated`: lo que el pago dio a la inicial y lo que sobró. Sin `downPayment`, el primer pago de un apartado salía con `appliedTo` vacío y no decía qué había pagado. La tasa se pinta como se lee (`42,50`), no con los ocho decimales de la columna. `amountUsd.ves` es siempre `null`: proyectarlo sería reconvertir con la tasa de hoy (RB-2). Un pago en dólares lleva `rateApplied` nulo: su tasa `1` no dice nada. `method` es el del comprobante, o «Registrado por el negocio».
> - **`ReceiptRow` lleva `statusLabel`**, que ya dice «En revisión desde el DD/MM» pasadas 48 h (SP-6); `submittedAt` es el día en el calendario del negocio.
> - **SP-5 (paginación por 20) no se aplica**: un apartado tiene como mucho tantos pagos como cuotas más alguno —36 cuotas es el máximo—, y el historial va entero. Si un día un apartado admite abonos sin límite, se pagina aquí.
> - **SP-1** (URL caducada): el archivo responde `403` (`T-059`), y recargar el detalle trae una URL nueva; no hay pantalla propia del `403` con el botón de volver.
> - **`auth.user` lleva solo un `User` del equipo.** En producción ya era así de hecho —`$request->user()` es el guard `web`, y la sesión del portal es `customer`—, pero en los tests `actingAs($customer, 'customer')` cambia el guard por defecto, y entonces la prop serializaba la ficha entera con su `id` y su `account_id`. Se blinda para que no dependa de cuál sea el guard por defecto. **Ojo con eso mismo en los tests del portal**: corren con `customer` como guard por defecto, así que código del portal que use `$request->user()` o un `Gate::authorize` sin guard pasaría en los tests y fallaría en producción; el del portal va por `PortalContext`.
> - **`fileUrl` lleva el `id` numérico del comprobante en la ruta firmada** (`/mi-cuenta/comprobantes/{id}/archivo`), y **se queda así** (decidido en `T-062`). RB-6 protege lo que el historial **dice** —el revisor, la cuenta, los identificadores como dato—, y el `id` de una URL firmada para esa persona, de 5 minutos, detrás de su sesión y de la policy, no abre ni nombra nada que no sea suyo; lo único que deja ver es un número de secuencia. Cerrarlo exigiría un identificador público en columna y enlazar por él la ruta del archivo, y no compensa por sí solo. Si un día el comprobante gana un identificador público por otro motivo, la ruta del portal se enlaza por él.
> - **El historial también lo ve el negocio**: la pantalla de revisión (`REQ-PAY-004` RB-6) usa el mismo `PaymentRowResource` para los pagos previos.

---

### REQ-LAY-023 · Alertas del portal

**Historia de usuario**
> Como cliente, quiero que el portal me avise de lo que está por vencer o vencido en cualquiera de mis negocios, para no acumular mora por descuido.

**Contratos de datos**
- Componente `<PortalAlerts alerts={…} />`, calculado en servidor por `PortalAlertsQuery`.

```ts
export type PortalAlert =
  | { readonly kind: 'overdue'; readonly count: number; readonly amount: MoneyView; readonly businessName: string | null }
  | { readonly kind: 'due_soon'; readonly dueDate: string; readonly amount: MoneyView; readonly businessName: string }
  | { readonly kind: 'receipt_pending'; readonly count: number }
  | { readonly kind: 'receipt_rejected'; readonly layawayCode: string; readonly businessName: string }
  | { readonly kind: 'down_payment_window'; readonly hoursLeft: number; readonly layawayCode: string; readonly businessName: string }
  | { readonly kind: 'claim_available'; readonly businessName: string };
```

**Reglas de negocio y precondiciones**
- RB-1 Las alertas se calculan **en el servidor** con los mismos umbrales que las notificaciones (`REQ-NOT-002`), para que portal y push nunca se contradigan.
- RB-2 Prioridad de presentación: `down_payment_window` > `overdue` > `receipt_rejected` > `due_soon` > `receipt_pending` > `claim_available`.
- RB-3 **Cada alerta identifica el negocio** al que corresponde, salvo `overdue` cuando agrupa varios, que muestra el total y `businessName: null`.
- RB-4 `due_soon` se activa cuando falta ≤ 3 días para la cuota más próxima (mismo offset que el recordatorio `−3 d`).
- RB-5 `overdue` agrupa las cuotas vencidas: por negocio si es uno solo, y en un total consolidado si son varios, para no saturar con seis alertas iguales.
- RB-6 `claim_available` es una alerta nueva del pivote: en modo cuenta, invita a reclamar el historial si la persona podría tener fichas sin vincular. Se muestra de forma genérica, **sin revelar** si existe una ficha con su documento en algún negocio: eso sería filtrar información. Es una invitación, no una confirmación.
- RB-7 Cada alerta lleva un CTA directo a la acción que la resuelve.
- RB-8 Las alertas **no** se pueden descartar: reflejan estado, no mensajes.
- RB-9 No se muestran alertas de apartados terminales.
- RB-10 En sesión acotada, las alertas se limitan a ese negocio y `claim_available` se sustituye por la propuesta de conectar Google.

**Happy path**
1. La persona entra y ve arriba: "Ferretería El Tornillo: 1 cuota vencida por $30,00 · Subir comprobante".
2. Sube el comprobante; la alerta pasa a "1 comprobante en revisión".
3. Al aprobarse, la alerta desaparece.

**Sad paths y edge cases**
- SP-1 Varias alertas simultáneas → se muestran hasta 3, por prioridad, con enlace "ver todo".
- SP-2 `down_payment_window` con menos de 1 hora → se expresa en minutos.
- SP-3 Ventana de inicial vencida con comprobante en revisión → se muestra `receipt_pending`, no `down_payment_window`.
- SP-4 Cuotas vencidas en tres negocios → una sola alerta consolidada con el total (RB-5).
- SP-5 Sin ninguna condición de alerta → el contenedor no se renderiza, sin espacio vacío.
- SP-6 Zona horaria del navegador distinta de la del negocio → los cálculos son de servidor, con la zona de cada organización, así que el mensaje es consistente.
- SP-7 `claim_available` mostrada a alguien que no tiene fichas sin vincular → aceptable: es genérica por diseño (RB-6) y su coste es una invitación irrelevante.

**Fuera de alcance inmediato**
- Centro de notificaciones con historial dentro del portal.
- Preferencias por tipo de alerta.
- Alertas de novedades del feed (eso es el digest, `REQ-ACC-006`).

**Gate de validación**
- Superficie esperada: `app/Queries/PortalAlertsQuery.php`, `app/Http/Resources/PortalAlertResource.php`, `resources/js/features/layaway/components/PortalAlerts.tsx`.
- Tipos esperados: `PortalAlert` (unión discriminada), `PortalAlertsQuery`.
- Anti-`SHALLOW`: `PortalAlertsQuery::forPortalContext()` (seis condiciones, prioridad, agrupación multi-negocio) supera 4 líneas junto a su clase.
- Tests: prioridad respetada; umbral de 3 días coincide con `reminder_offsets_days`; agrupación consolidada con tres negocios; ventana vencida con comprobante muestra `receipt_pending`; terminales excluidos; sin alertas no renderiza; `claim_available` no revela si existe una ficha (test explícito de RB-6); sesión acotada limita al negocio.
