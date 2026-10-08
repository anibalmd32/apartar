import { type OrganizationBrand } from '@/types/backoffice';
import { type MoneyView } from '@/types/money';
import { type PaymentAccountView } from '@/types/payment';
import { type PaymentRow, type ReceiptRow } from '@/types/receipt';

/**
 * Tipos del portal del cliente (`REQ-IDN-004`, `REQ-LAY-020`, `REQ-LAY-021`).
 *
 * Ninguno lleva `id` numéricos de la ficha ni el de su cuenta (`REQ-IDN-004`
 * RB-4): el apartado se nombra por su código y la persona por su documento.
 */

/** La sesión del portal, compartida en `auth.portal`. Hoy solo la acotada. */
export interface PortalSessionView {
    readonly mode: 'scoped';
    readonly fullName: string;
    readonly businessName: string;
}

export interface PortalAccessProps {
    readonly status?: string | null;
}

export type LayawayStatus = 'pending_down_payment' | 'active' | 'overdue' | 'completed' | 'cancelled' | 'expired';

/** Un apartado en el portal (`LayawaySummary`). */
export interface LayawaySummary {
    readonly code: string;
    readonly status: LayawayStatus;
    /** Cómo se le nombra el estado al cliente; sale del enum del servidor. */
    readonly statusLabel: string;
    readonly isTerminal: boolean;
    readonly acceptsPayments: boolean;
    readonly productName: string;
    readonly quantity: number;
    readonly total: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly nextDueDate: string | null;
    readonly nextDueDateLabel: string | null;
    readonly overdueInstallments: number;
}

export interface PortalTotals {
    readonly activeLayaways: number;
    readonly committed: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly overdueInstallments: number;
    readonly nextDueDate: string | null;
    readonly nextDueDateLabel: string | null;
}

/** Lo que la persona tiene con un negocio: nunca se mezcla con otro. */
export interface BusinessBlock {
    readonly business: OrganizationBrand;
    /** El negocio ya no opera en la plataforma (SP-9). La suspensión no se dice. */
    readonly closed: boolean;
    readonly totals: PortalTotals;
    readonly layaways: readonly LayawaySummary[];
    readonly history: readonly LayawaySummary[];
}

/**
 * Una alerta del portal (`REQ-LAY-023`): una unión discriminada por `kind`.
 *
 * Todo viene calculado del servidor, con la zona y la tasa de cada negocio
 * (SP-6): aquí solo se elige la frase. Cada una lleva a la acción que la
 * resuelve (`actionUrl`, RB-7). `claim_available` es de la sesión de cuenta
 * (H6) y hoy no llega nunca.
 */
export type PortalAlert = { readonly actionUrl: string } & (
    | {
          readonly kind: 'down_payment_window';
          readonly hoursLeft: number;
          /** Para decirlo en minutos cuando queda menos de una hora (SP-2). */
          readonly minutesLeft: number;
          readonly layawayCode: string;
          readonly businessName: string;
      }
    /** `businessName` es `null` cuando agrupa varios negocios (RB-3, RB-5). */
    | { readonly kind: 'overdue'; readonly count: number; readonly amount: MoneyView; readonly businessName: string | null }
    | {
          readonly kind: 'due_soon';
          readonly dueDate: string;
          readonly dueDateLabel: string;
          readonly amount: MoneyView;
          readonly businessName: string;
      }
    | { readonly kind: 'receipt_pending'; readonly count: number }
    | { readonly kind: 'receipt_rejected'; readonly layawayCode: string; readonly businessName: string }
    | { readonly kind: 'claim_available'; readonly businessName: string }
);

export interface PortalDashboardProps {
    readonly session: { readonly mode: 'scoped'; readonly displayName: string };
    readonly businesses: readonly BusinessBlock[];
    /** Agregado de todos los negocios: solo con la sesión de cuenta (H6). */
    readonly totals: PortalTotals | null;
    /** Ya en el orden de RB-2; vacío si no hay nada que avisar. */
    readonly alerts: readonly PortalAlert[];
}

export type InstallmentTone = 'paid' | 'overdue' | 'soon' | 'pending';

/** Una fila del cronograma, con sus días contados en el calendario del negocio. */
export interface InstallmentRow {
    readonly number: number;
    readonly dueDate: string;
    readonly dueDateLabel: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly pending: MoneyView;
    readonly status: 'pending' | 'partially_paid' | 'paid' | 'overdue';
    /** Negativo si ya venció. */
    readonly daysUntilDue: number;
    readonly tone: InstallmentTone;
}

export interface NextPaymentSuggestion {
    readonly kind: 'down_payment' | 'installment';
    readonly installmentNumber: number | null;
    readonly amount: MoneyView;
    /** El saldo entero, para quien quiera liquidar. */
    readonly payOff: MoneyView;
}

export interface PortalLayawayShowProps {
    readonly business: OrganizationBrand;
    readonly layaway: LayawaySummary & {
        readonly downPayment: MoneyView;
        readonly expiresAt: string | null;
        readonly activatedAt: string | null;
        readonly statusMessage: string | null;
    };
    readonly installments: readonly InstallmentRow[];
    readonly nextPaymentSuggestion: NextPaymentSuggestion | null;
    readonly paymentAccounts: readonly PaymentAccountView[];
    readonly catalogUrl: string | null;
    /** El formulario del comprobante; `null` si el apartado ya no cobra. */
    readonly receiptUrl: string | null;
    /** `REQ-LAY-022` · los pagos, del más reciente al más antiguo. */
    readonly payments: readonly PaymentRow[];
    /** `REQ-LAY-022` · los comprobantes, del más reciente al más antiguo. */
    readonly receipts: readonly ReceiptRow[];
}

/** La confirmación del enlace por correo (`REQ-IDN-003` RB-9). */
export interface PortalLoginLinkProps {
    readonly token: string;
}

/** El enlace que ya no sirve: un solo mensaje para los tres motivos (SP-2). */
export interface PortalLoginLinkUnusableProps {
    readonly message: string;
}
