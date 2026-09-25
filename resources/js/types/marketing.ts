import { type PlanLimit } from '@/types/plan';

/**
 * Las páginas de la plataforma para quien todavía no es cliente
 * (`REQ-SUB-001`, [05 §7.1]).
 *
 * Espejo de `PricingController` y `LegalController`.
 */

/** Un límite de la matriz, con su nombre ya concordado por el servidor. */
export interface PlanLimitView {
    readonly key: string;
    readonly label: string;
    /** **`null` es ilimitado** (RB-2, SP-2): lo escribe `formatLimit()`, nunca un `?? 0`. */
    readonly limit: PlanLimit;
}

/** Un plan tal como se compara en `/precios`, leído de `plans` (RB-8). */
export interface PricingPlan {
    readonly code: string;
    readonly name: string;
    /** «Gratis» o la cifra con su periodo; lo compone el servidor. */
    readonly priceLabel: string;
    readonly isFree: boolean;
    readonly limits: readonly PlanLimitView[];
    /** Las funciones que incluye, por su nombre. Vacía es «sin funciones de pago» (SP-1). */
    readonly features: readonly string[];
}

export interface PricingProps {
    readonly plans: readonly PricingPlan[];
}

/** Una página legal: montada, y **pendiente de redacción** mientras `drafted` sea `false`. */
export interface LegalDocument {
    readonly key: 'terms' | 'privacy';
    readonly title: string;
    readonly drafted: boolean;
    /** Lo que la spec exige que cubra, para quien la redacte. No son cláusulas. */
    readonly mustCover: readonly string[];
}

export interface LegalProps {
    readonly document: LegalDocument;
}
