import { type Currency, type MoneyView } from '@/types/money';
import { type PaymentMethod } from '@/types/payment';
import { type LayawayStatus } from '@/types/portal';
import { type PaymentRow } from '@/types/receipt';

/**
 * Un apartado visto por el negocio (`REQ-LAY-009`, `REQ-LAY-010`,
 * `REQ-LAY-011`).
 *
 * A diferencia del portal, aquí sí viaja el motivo de una cancelación: es del
 * negocio y lo ve íntegro (`REQ-LAY-009` RB-8). Las URL de cada acción llegan
 * solo cuando la acción es posible, y su ausencia es la que esconde el botón.
 */
export interface BackofficeLayaway {
    readonly code: string;
    readonly status: LayawayStatus;
    readonly statusLabel: string;
    readonly productName: string;
    readonly quantity: number;
    readonly total: MoneyView;
    readonly downPayment: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly createdAt: string | null;
    readonly cancelledAt: string | null;
    readonly deliveredAt: string | null;
    readonly cancellationReason: string | null;
    readonly cancelUrl: string | null;
    readonly deliverUrl: string | null;
}

/** Entre qué días puede caer la nueva fecha (`REQ-LAY-011` RB-3). */
export interface RescheduleOption {
    readonly url: string;
    readonly earliest: string;
    /** `null` en la última cuota: no tiene tope (SP-4). */
    readonly latest: string | null;
    /** El rango, dicho en palabras. */
    readonly window: string;
}

export interface BackofficeInstallmentRow {
    readonly number: number;
    readonly dueDate: string;
    readonly dueDateLabel: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly statusLabel: string;
    /** `null` si no se puede mover: pagada, o el apartado no se está cobrando. */
    readonly reschedule: RescheduleOption | null;
}

/**
 * Lo que necesita el formulario del pago manual (`REQ-PAY-007`). Las cuentas
 * van todas, con las desactivadas marcadas: un pago pudo llegar a una que ya
 * no se usa. `paidAt` son días del negocio, `Y-m-d`.
 */
export interface ManualPaymentOptions {
    readonly storeUrl: string;
    readonly previewUrl: string;
    readonly methods: readonly { readonly value: PaymentMethod; readonly label: string; readonly defaultCurrency: Currency }[];
    /** Lo justo para elegirla: los datos del titular son de quien gobierna las cuentas. */
    readonly paymentAccounts: readonly {
        readonly id: number;
        readonly methodLabel: string;
        readonly identifier: string;
        readonly isActive: boolean;
    }[];
    readonly currencies: readonly { readonly value: Currency; readonly label: string }[];
    readonly paidAt: { readonly min: string; readonly max: string };
    readonly rate: { readonly rate: string; readonly sourceLabel: string } | null;
}

export interface BackofficeLayawayShowProps {
    readonly layaway: BackofficeLayaway;
    readonly customer: { readonly fullName: string; readonly documentNumber: string; readonly phone: string } | null;
    readonly installments: readonly BackofficeInstallmentRow[];
    /** `REQ-LAY-022` · los pagos, como los ve el cliente. */
    readonly payments: readonly PaymentRow[];
    /** `null` si el apartado ya no cobra. */
    readonly manualPayment: ManualPaymentOptions | null;
}
