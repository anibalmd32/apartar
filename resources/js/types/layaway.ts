import { type LayawayFollowNotice } from '@/types/account';
import { type CatalogView, type ProductDetail } from '@/types/catalog-view';
import { type MoneyView } from '@/types/money';
import { type PaymentAccountView } from '@/types/payment';

export interface LayawayFormValues {
    [key: string]: string | number | boolean;
    quantity: number;
    fullName: string;
    documentNumber: string;
    phone: string;
    email: string;
    termsAccepted: boolean;
    idempotencyKey: string;
}

export interface LayawayPlanInstallment {
    readonly number: number;
    readonly estimatedDueDate: string;
    readonly amount: MoneyView;
}

export interface LayawayPlanView {
    readonly quantity: number;
    readonly total: MoneyView;
    readonly downPayment: MoneyView;
    readonly requiresDownPayment: boolean;
    readonly financedAmount: MoneyView;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly installmentAmount: MoneyView | null;
    readonly lastInstallmentAmount: MoneyView | null;
    readonly installments: readonly LayawayPlanInstallment[];
}

export interface LayawayConsentView {
    readonly businessName: string;
}

export interface LayawayCreateProps {
    readonly catalog: CatalogView;
    readonly product: ProductDetail;
    readonly plan: LayawayPlanView | null;
    readonly quantity: number;
    readonly consent: LayawayConsentView;
    readonly account: AccountPrefill | null;
}

export interface AccountPrefill {
    readonly fullName: string;
    readonly email: string;
}

export interface LayawayThanksProps {
    readonly catalog: CatalogView;
    readonly layaway: {
        readonly code: string;
        readonly productName: string;
        readonly plan: LayawayPlanView;
        readonly expiresAt: string | null;
        readonly firstDueDate: string | null;
        readonly hasEmail: boolean;
    };
    readonly paymentAccounts: readonly PaymentAccountView[];
    readonly receiptUrl: string | null;
    readonly follow: LayawayFollowNotice | null;
}
