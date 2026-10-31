import { type DownPaymentProgressView, type LayawayPlanView } from '@/types/layaway';
import { type Currency, type MoneyView } from '@/types/money';
import { type PaymentMethod } from '@/types/payment';
import { type LayawayStatus } from '@/types/portal';
import { type PaymentRow } from '@/types/receipt';

export interface BackofficeLayaway {
    readonly code: string;
    readonly status: LayawayStatus;
    readonly statusLabel: string;
    readonly productName: string;
    readonly quantity: number;
    readonly total: MoneyView;
    readonly downPayment: MoneyView;
    readonly downPaymentProgress: DownPaymentProgressView | null;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly createdAt: string | null;
    readonly cancelledAt: string | null;
    readonly deliveredAt: string | null;
    readonly cancellationReason: string | null;
    readonly cancelUrl: string | null;
    readonly deliverUrl: string | null;
}

export interface RescheduleOption {
    readonly url: string;
    readonly earliest: string;
    readonly latest: string | null;
    readonly window: string;
}

export interface BackofficeInstallmentRow {
    readonly number: number;
    readonly dueDate: string;
    readonly dueDateLabel: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly statusLabel: string;
    readonly reschedule: RescheduleOption | null;
}

export interface PaymentFieldOptions {
    readonly methods: readonly {
        readonly value: PaymentMethod;
        readonly label: string;
        readonly defaultCurrency: Currency;
        readonly requiresReference: boolean;
    }[];
    readonly paymentAccounts: readonly {
        readonly id: number;
        readonly methodLabel: string;
        readonly identifier: string;
        readonly isActive: boolean;
    }[];
    readonly currencies: readonly { readonly value: Currency; readonly label: string }[];
    readonly paidAt: { readonly min: string; readonly max: string };
    readonly rate: { readonly rate: string; readonly sourceLabel: string } | null;
    readonly file: { readonly accept: string; readonly maxMb: number };
    readonly readUrl: string;
}

export interface ManualPaymentOptions extends PaymentFieldOptions {
    readonly storeUrl: string;
    readonly previewUrl: string;
}

export interface ReallocationOptions {
    readonly installments: readonly { readonly id: number; readonly number: number; readonly amountCents: number; readonly paidCents: number }[];
    readonly payments: readonly {
        readonly id: number;
        readonly url: string;
        readonly label: string;
        readonly allocations: readonly { readonly installmentId: number; readonly cents: number }[];
    }[];
}

export interface LayawayActivityEvent {
    readonly kind: string;
    readonly title: string;
    readonly detail: string | null;
    readonly actor: string | null;
    readonly at: string;
}

export interface BackofficeLayawayShowProps {
    readonly layaway: BackofficeLayaway;
    readonly customer: {
        readonly fullName: string;
        readonly documentNumber: string;
        readonly phone: string;
        readonly email?: string | null;
        readonly url?: string;
        readonly chatUrl?: string | null;
    } | null;
    readonly activity?: readonly LayawayActivityEvent[];
    readonly installments: readonly BackofficeInstallmentRow[];
    readonly payments: readonly PaymentRow[];
    readonly manualPayment: ManualPaymentOptions | null;
    readonly reallocation: ReallocationOptions | null;
}

export interface LayawayCustomerOption {
    readonly id: number;
    readonly name: string;
    readonly detail: string | null;
}

export interface LayawayProductOption {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly stock: number;
    readonly maxQuantity: number;
}

export type DownPaymentWindowChoice = 'default' | 'hours' | 'days' | 'none';

export interface BackofficeLayawayCreateProps {
    readonly products: readonly LayawayProductOption[];
    readonly selection: { readonly product: number | null; readonly quantity: number };
    readonly plan: (LayawayPlanView & { readonly productName: string }) | null;
    readonly customer: LayawayCustomerOption | null;
    readonly window: { readonly defaultHours: number; readonly maxHours: number; readonly maxDays: number };
    readonly payment: PaymentFieldOptions;
    readonly urls: { readonly store: string; readonly customers: string; readonly paymentPreview: string };
}
