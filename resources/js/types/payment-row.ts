import { type MoneyView } from '@/types/money';
import { type PaymentEvidence } from '@/types/payment';
import { type SafeguardView } from '@/types/safeguard';

export interface PaymentRow extends PaymentEvidence {
    readonly settledAt: string;
    readonly amountUsd: MoneyView;
    readonly declaredAmount: string;
    readonly rateApplied: string | null;
    readonly rateSource: 'bcv' | 'parallel' | 'manual';
    readonly rateSourceLabel: string;
    readonly method: string;
    readonly appliedTo: readonly number[];
    readonly downPayment: string | null;
    readonly unallocated: string | null;
    readonly registeredByBusiness: boolean;
    readonly note: string | null;
    readonly fromReceipt?: boolean;
    readonly voided?: PaymentVoid | null;
    readonly voidUrl?: string | null;
    readonly correction?: PaymentCorrection | null;
    readonly safeguard?: SafeguardView | null;
}

export interface PaymentCorrection {
    readonly storeUrl: string;
    readonly previewUrl: string;
    readonly targetUrl: string;
    readonly values: {
        readonly method: string;
        readonly payment_account: string;
        readonly amount: string;
        readonly currency: string;
        readonly rate: string;
        readonly paid_at: string;
        readonly bank: string;
        readonly reference: string;
    };
}

export interface PaymentVoid {
    readonly at: string;
    readonly by: string | null;
    readonly reason: string | null;
}
