/**
 * Dónde le pagan al negocio (`REQ-ORG-007`).
 *
 * Espejo de `app/Domain/Payment/`. Va en su propio archivo y no en
 * `backoffice.ts` por lo mismo que `plan.ts`: es un dominio con vida propia
 * —lo comparte H4, que es quien trae los comprobantes— y mezclarlo con el
 * marco del backoffice obligaría a importar medio módulo para pintar una
 * cuenta.
 */

/**
 * Los seis métodos de `PaymentMethod` (contratos compartidos del módulo 22).
 *
 * La unión **nombra** los casos; lo que cada uno significa para la pantalla
 * —cómo se llama, qué va en su `identifier`— lo manda el servidor en
 * `PaymentMethodDefinition`. Repetir aquí las etiquetas sería el segundo censo
 * de los mismos seis casos, y el que se queda viejo es siempre el de la
 * pantalla: la que decide qué se puede escribir.
 */
export type PaymentMethod = 'pago_movil' | 'transferencia' | 'zelle' | 'binance_pay' | 'efectivo_usd' | 'efectivo_ves';

/**
 * Qué es cada método, resuelto por el servidor desde el enum.
 */
export interface PaymentMethodDefinition {
    readonly value: PaymentMethod;
    /** «Pago móvil», no `pago_movil`. */
    readonly label: string;
    /** Qué contiene el `identifier` de este método (RB-2). */
    readonly identifierLabel: string;
    /** El dinero se entrega en mano: no hay banco detrás. */
    readonly isCash: boolean;
}

/**
 * Una cuenta de cobro tal como se pinta.
 *
 * Espejo exacto de `App\Domain\Payment\PaymentAccountView`. Trae
 * `methodLabel` e `identifierLabel` ya resueltos: la misma forma viaja al
 * backoffice y —cuando llegue `REQ-CAT-003`— al cliente que va a pagar, que es
 * lo que garantiza que el dueño vea exactamente lo que ve su cliente.
 *
 * Las desactivadas **viajan igual**, marcadas con `isActive`: esconderlas
 * convertiría la degradación del borrado (RB-6) en un borrado a efectos
 * prácticos.
 */
export interface PaymentAccountView {
    readonly id: number;
    readonly method: PaymentMethod;
    readonly methodLabel: string;
    readonly identifierLabel: string;
    readonly bank: string | null;
    readonly holderName: string;
    readonly holderDocument: string;
    readonly identifier: string;
    readonly instructions: string | null;
    readonly isActive: boolean;
    readonly position: number;
}
