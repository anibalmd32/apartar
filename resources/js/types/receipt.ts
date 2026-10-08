import { type OrganizationBrand } from '@/types/backoffice';
import { type Paginated } from '@/types/data-table';
import { type Currency, type MoneyView } from '@/types/money';
import { type PaymentAccountView, type PaymentMethod } from '@/types/payment';
import { type LayawaySummary } from '@/types/portal';

/**
 * Contratos del envío de comprobante desde el portal (`REQ-PAY-001`).
 *
 * Espejo de lo que compone `Portal\ReceiptController::create()`. Como el resto
 * de `types/*.ts`, todo llega resuelto del servidor: las etiquetas de método y
 * de moneda, el rango de fechas en la zona del negocio y las cifras de la
 * sugerencia ya como cadenas. Aquí no se calcula nada.
 */

/** El apartado al que se le reporta el pago: un recorte del `LayawaySummary` del portal. */
export type ReceiptLayawaySummary = Pick<LayawaySummary, 'code' | 'productName' | 'statusLabel' | 'remaining'>;

/**
 * El importe que se sugiere (RB-9): la cuota FIFO o la inicial.
 *
 * `amountUsd` y `amountVes` son las cifras **sin formato** —`30.00`,
 * `1275.00`— con las que se precarga el campo según la moneda; `amount` es la
 * misma cifra ya formateada para enseñarla. `amountVes` es `null` sin tasa.
 */
export interface ReceiptSuggestion {
    readonly kind: 'down_payment' | 'installment';
    readonly installmentNumber: number | null;
    readonly amount: MoneyView;
    readonly amountUsd: string;
    readonly amountVes: string | null;
}

/**
 * Un método de pago tal como lo ofrece el formulario.
 *
 * `requiresReference` es `PaymentMethod::requiresReference()` (RB-2): sin él no
 * se piden banco ni referencia. `defaultCurrency` **sugiere** la moneda, no la
 * impone (SP-10).
 */
export interface ReceiptMethodOption {
    readonly value: PaymentMethod;
    readonly label: string;
    readonly isCash: boolean;
    readonly requiresReference: boolean;
    readonly defaultCurrency: Currency;
}

export interface ReceiptCurrencyOption {
    readonly value: Currency;
    readonly label: string;
}

/** Las props de `pages/portal/ReceiptCreate.tsx`. */
export interface ReceiptCreateProps {
    readonly business: OrganizationBrand;
    readonly layaway: ReceiptLayawaySummary;
    readonly suggestion: ReceiptSuggestion | null;
    /** Solo las activas; vacío es SP-8 y el campo desaparece. */
    readonly paymentAccounts: readonly PaymentAccountView[];
    readonly methods: readonly ReceiptMethodOption[];
    readonly currencies: readonly ReceiptCurrencyOption[];
    /** `Y-m-d` en la zona del negocio: desde la creación del apartado hasta hoy (RB-4). */
    readonly paidAt: { readonly min: string; readonly max: string };
    /** `accept` es la lista de extensiones de `REQ-PAY-002` RB-5. */
    readonly file: { readonly maxMb: number; readonly accept: string };
    readonly storeUrl: string;
    readonly backUrl: string;
}

/**
 * Lo que manda el formulario, multipart y con estos nombres exactos.
 *
 * `paymentAccount` es el id de la cuenta de cobro o `null` (SP-8, o si no se
 * eligió); en el `FormData` el `null` viaja como cadena vacía. `method` vacío
 * es «sin elegir todavía», y lo rechaza el servidor.
 */
export interface ReceiptFormValues {
    [key: string]: string | number | File | null;
    paymentAccount: number | null;
    method: PaymentMethod | '';
    bank: string;
    reference: string;
    paidAt: string;
    amount: string;
    currency: Currency;
    note: string;
    file: File | null;
}

/**
 * Contratos de la bandeja de conciliación (`REQ-PAY-004`).
 *
 * Espejo de lo que compone `Backoffice\ReceiptInboxController`. El orden FIFO,
 * la marca de atrasado (RB-7) y los filtros los resuelve el servidor: aquí no
 * se ordena ni se compara una fecha contra el reloj.
 */

export type ReceiptStatus = 'pending_review' | 'approved' | 'rejected';

/**
 * Una fila de la bandeja (RB-5).
 *
 * `customer` lleva nombre y documento **aquí**, en la respuesta con la sesión
 * del equipo, y nunca en el aviso difundido (RB-2). `paymentAccount` es la que
 * el cliente declaró, con `isActive` para marcar la desactivada (SP-8).
 * `file.url` es la URL firmada del backoffice, de 15 minutos; con `kind`
 * `pdf` se pinta un icono en vez de la miniatura (SP-5).
 */
export interface ReceiptInboxRow {
    readonly id: number;
    /** La pantalla de revisión, desde donde se aprueba (RB-6, `T-061`). */
    readonly reviewUrl: string;
    readonly status: ReceiptStatus;
    readonly statusLabel: string;
    /** RB-7: más de 48 h en revisión. Solo puede serlo un pendiente. */
    readonly overdue: boolean;
    /** Cuándo llegó, ISO 8601. */
    readonly submittedAt: string;
    /** `Y-m-d`: el día que el cliente dice haber pagado. */
    readonly paidAt: string;
    readonly layaway: { readonly code: string; readonly url: string; readonly cancelled: boolean } | null;
    readonly customer: { readonly fullName: string; readonly documentNumber: string } | null;
    readonly method: PaymentMethod;
    readonly methodLabel: string;
    readonly bank: string | null;
    readonly reference: string | null;
    readonly paymentAccount: PaymentAccountView | null;
    /** Sin convertir y sin formato: `850.00`. La conversión es de la aprobación. */
    readonly declaredAmount: string;
    readonly declaredCurrency: Currency;
    readonly file: { readonly url: string; readonly kind: 'image' | 'pdf' };
}

/**
 * Los filtros **aplicados**, tal como vuelven en `meta.filters` (contrato de
 * [22]). `cancelled` es `'1'` cuando se piden también los apartados
 * cancelados (RB-8); `from` y `to` son días del negocio, `Y-m-d`.
 */
export interface ReceiptInboxFilters {
    readonly status: ReceiptStatus | null;
    readonly method: PaymentMethod | null;
    readonly bank: string | null;
    readonly account: string | null;
    readonly from: string | null;
    readonly to: string | null;
    readonly cancelled: '1' | null;
}

export interface ReceiptInboxOption {
    readonly value: string;
    readonly label: string;
}

/** Las props de `pages/backoffice/receipts/Inbox.tsx`. */
export interface ReceiptInboxProps extends Paginated<ReceiptInboxRow> {
    /** El `id` del pendiente más nuevo al pintar: con él se sabe si llegó algo después. */
    readonly newestPending: number | null;
    /** El reloj del servidor con el que se marcaron los atrasados, ISO 8601: la antigüedad se cuenta con él. */
    readonly now: string;
    readonly options: {
        readonly statuses: readonly ReceiptInboxOption[];
        readonly methods: readonly ReceiptInboxOption[];
        readonly banks: readonly string[];
        /** Todas, incluidas las desactivadas (SP-8). */
        readonly paymentAccounts: readonly PaymentAccountView[];
    };
    readonly overdueAfterHours: number;
    /** La zona del negocio, para leer las horas de llegada (`REQ-ORG-006` RB-7). */
    readonly timezone: string;
}

/**
 * Un pago del historial (`REQ-LAY-022` RB-1, RB-2, RB-7), espejo de
 * `PaymentRowResource`: lo ven el cliente en su portal y el negocio en la
 * revisión.
 *
 * `amountUsd.ves` es siempre `null`: un importe histórico **no se reconvierte**
 * con la tasa de hoy (RB-2). Lo que pagó en bolívares está en
 * `declaredAmount`, y la tasa con la que se convirtió, en `rateApplied` —nula
 * en un pago en dólares, que no convirtió nada—. `note` solo en los pagos
 * registrados por el negocio.
 */
export interface PaymentRow {
    readonly settledAt: string;
    readonly amountUsd: MoneyView;
    readonly declaredAmount: string;
    readonly rateApplied: string | null;
    readonly rateSource: 'bcv' | 'parallel' | 'manual';
    readonly rateSourceLabel: string;
    readonly method: string;
    readonly appliedTo: readonly number[];
    /** Lo que pagó la inicial, si este pago la cubrió; formateado. */
    readonly downPayment: string | null;
    /** Lo que sobró sin asignar, si sobró; formateado. */
    readonly unallocated: string | null;
    readonly registeredByBusiness: boolean;
    readonly note: string | null;
}

/**
 * Lo que haría aprobar (`ApprovalPreview::toArray()`): la misma aplicación,
 * deshecha. Cifras ya formateadas y la frase que lo resume.
 */
export interface ApprovalPreviewView {
    readonly amount: string;
    readonly rate: string;
    readonly rateSource: string;
    readonly rateSourceLabel: string;
    readonly downPayment: string | null;
    readonly allocations: readonly { readonly number: number; readonly amount: string; readonly covers: boolean }[];
    /** Lo que sobraría sin asignar; con cifra, aprobar exige confirmarlo (SP-5). */
    readonly unallocated: string | null;
    /** La cifra exacta del sobrante, la que se manda al confirmarlo y el servidor compara. */
    readonly unallocatedCents: number;
    readonly statusAfter: string;
    readonly statusAfterLabel: string;
    readonly summary: string;
}

/** Una cuota del cronograma, en la pantalla de revisión. */
export interface ReviewInstallment {
    readonly number: number;
    readonly dueDateLabel: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly status: string;
    readonly statusLabel: string;
}

/** Las props de `pages/backoffice/receipts/Review.tsx` (`REQ-PAY-004` RB-6, `REQ-PAY-005`). */
export interface ReceiptReviewProps {
    readonly receipt: {
        readonly id: number;
        readonly status: ReceiptStatus;
        readonly statusLabel: string;
        readonly submittedAt: string | null;
        readonly paidAt: string;
        readonly method: string;
        readonly bank: string | null;
        readonly reference: string | null;
        readonly note: string | null;
        /** Sin formato, para precargar el formulario: `850.00`. */
        readonly declaredAmount: string;
        readonly declaredCurrency: Currency;
        /** Formateado: `Bs. 850,00`. */
        readonly declared: string;
        readonly paymentAccount: PaymentAccountView | null;
        readonly file: { readonly url: string; readonly kind: 'image' | 'pdf' };
        readonly reviewedBy: string | null;
        readonly rejectionReason: string | null;
    };
    readonly customer: { readonly fullName: string; readonly documentNumber: string; readonly phone: string } | null;
    readonly layaway: {
        readonly code: string;
        readonly url: string;
        readonly status: string;
        readonly statusLabel: string;
        readonly productName: string;
        readonly total: MoneyView;
        readonly downPayment: MoneyView;
        readonly paid: MoneyView;
        readonly remaining: MoneyView;
    };
    readonly installments: readonly ReviewInstallment[];
    readonly payments: readonly PaymentRow[];
    readonly rate: { readonly rate: string; readonly sourceLabel: string; readonly publishedAt: string } | null;
    /** Con lo declarado y la tasa vigente; `null` si no se puede aprobar o no hay vista previa posible. */
    readonly preview: ApprovalPreviewView | null;
    /** Por qué no hay vista previa con lo declarado —sin tasa, por debajo de la inicial—. */
    readonly previewError: string | null;
    /** `null` si ya no espera revisión o el apartado ya no cobra. */
    readonly approval: {
        readonly url: string;
        readonly previewUrl: string;
        readonly currencies: readonly ReceiptCurrencyOption[];
    } | null;
    readonly inboxUrl: string;
}

/**
 * Un comprobante del historial del cliente (`REQ-LAY-022` RB-3 a RB-8),
 * espejo de `ReceiptRowResource`.
 *
 * `id` y `replacesReceiptId` son identificadores **públicos**, opacos: el de
 * la fila no sale nunca (RB-6). `rejectionReason` es el motivo tal como lo
 * escribió el negocio (RB-3). `fileUrl` es una URL firmada de 5 minutos
 * (RB-4). `statusLabel` ya dice «En revisión desde el DD/MM» cuando toca
 * (SP-6).
 */
export interface ReceiptRow {
    readonly id: string;
    /** `Y-m-d`, el día en que llegó, en el calendario del negocio. */
    readonly submittedAt: string;
    readonly method: string;
    readonly reference: string | null;
    readonly declaredAmount: string;
    readonly paidToAccount: string | null;
    readonly status: ReceiptStatus;
    readonly statusLabel: string;
    readonly rejectionReason: string | null;
    readonly fileUrl: string;
    readonly replacesReceiptId: string | null;
}
