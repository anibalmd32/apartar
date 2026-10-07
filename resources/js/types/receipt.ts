import { type OrganizationBrand } from '@/types/backoffice';
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
