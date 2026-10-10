import { type MoneyView } from '@/types/money';

/**
 * Las facturas de la suscripción (`REQ-SUB-006`, `T-069`): espejo de lo que
 * `SubscriptionInvoiceController` envía. `InvoiceRow` es el contrato
 * compartido de [50], con lo que la pantalla necesita para pagar sin decidir
 * nada: si se puede pagar lo dice `payUrl`, no un `if` sobre el estado.
 */

export type InvoiceStatus = 'pending' | 'under_review' | 'paid' | 'overdue' | 'void';

export interface InvoiceRow {
    readonly id: number;
    readonly planName: string;
    /** Por qué se emitió: cambio de plan, renovación… ya con su nombre. */
    readonly kind: string;
    readonly periodStart: string;
    readonly periodEnd: string;
    /** En dólares, con su equivalente en bolívares a la tasa de la plataforma (`null` sin tasa). */
    readonly amount: MoneyView;
    /** Lo que falta tras un pago parcial (SP-2); `null` si no hubo pago parcial. */
    readonly outstanding: MoneyView | null;
    readonly status: InvoiceStatus;
    readonly dueDate: string;
    readonly receiptStatus: 'none' | 'pending_review' | 'rejected';
    readonly rejectionReason: string | null;
    /** Adónde se manda el comprobante; `null` si ya no se paga (pagada o anulada). */
    readonly payUrl: string | null;
}

export interface PaymentMethodOption {
    readonly value: string;
    readonly label: string;
    readonly requiresReference: boolean;
    readonly defaultCurrency: string;
}

export interface CurrencyOption {
    readonly value: string;
    readonly label: string;
}

export interface InvoicesPageProps {
    readonly invoices: readonly InvoiceRow[];
    readonly methods: readonly PaymentMethodOption[];
    readonly currencies: readonly CurrencyOption[];
    readonly file: { readonly maxMb: number; readonly accept: string };
    readonly subscriptionUrl: string;
}
