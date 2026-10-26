import { type PlanLimit } from '@/types/plan';

export interface PlanLimitView {
    readonly key: string;
    readonly label: string;
    readonly limit: PlanLimit;
}

export interface PricingPlan {
    readonly code: string;
    readonly name: string;
    readonly priceLabel: string;
    readonly isFree: boolean;
    readonly limits: readonly PlanLimitView[];
    readonly features: readonly string[];
}

export interface PricingProps {
    readonly plans: readonly PricingPlan[];
}

export interface LegalDocument {
    readonly key: 'terms' | 'privacy';
    readonly title: string;
    readonly updatedOn: string;
    readonly html: string;
}

export interface LegalProps {
    readonly document: LegalDocument;
}
