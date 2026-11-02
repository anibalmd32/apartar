export type PaymentMethod = 'pago_movil' | 'transferencia' | 'zelle' | 'binance_pay' | 'efectivo_usd' | 'efectivo_ves';

export interface PaymentMethodDefinition {
    readonly value: PaymentMethod;
    readonly label: string;
    readonly identifierLabel: string;
    readonly isCash: boolean;
}

export interface PaymentAccountView {
    readonly id: number;
    readonly method: PaymentMethod;
    readonly methodLabel: string;
    readonly identifierLabel: string;
    readonly bank: string | null;
    readonly bankLogoUrl?: string | null;
    readonly bankCode?: string | null;
    readonly holderName: string;
    readonly holderDocument: string;
    readonly identifier: string;
    readonly instructions: string | null;
    readonly isActive: boolean;
    readonly position: number;
}

export interface PaymentEvidence {
    readonly bankReference: string | null;
    readonly attachmentUrl: string | null;
}

export type SurplusDestinationType = 'team_member' | 'tip' | 'petty_cash' | 'other';

export interface SurplusDestinationOption {
    readonly value: SurplusDestinationType;
    readonly label: string;
    readonly requiresNote: boolean;
}

export interface SurplusTeamMember {
    readonly id: number;
    readonly name: string;
}

export interface SurplusDestinationOptions {
    readonly destinations: readonly SurplusDestinationOption[];
    readonly teamMembers: readonly SurplusTeamMember[];
}

export interface SurplusDestinationView {
    readonly type: SurplusDestinationType;
    readonly label: string;
    readonly teamMemberName: string | null;
    readonly note: string | null;
}
