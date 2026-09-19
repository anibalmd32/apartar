/**
 * Cómo ordena un negocio su inventario (`REQ-BOF-023`, `REQ-CAT-006`).
 *
 * Va en su propio archivo y no en `backoffice.ts` por lo mismo que
 * `payment.ts`: la taxonomía tiene vida fuera del backoffice —los filtros del
 * catálogo público se construyen con ella (`REQ-CAT-006` RB-3)— y mezclarla
 * con el marco obligaría a importar medio módulo para pintar una categoría.
 *
 * **La taxonomía es de cada organización** (RB-1): no hay categorías
 * compartidas ni sugeridas por la plataforma, así que estos tipos nunca
 * describen un vocabulario común. Dos negocios pueden tener los dos una
 * «Motores» y no son comparables.
 */

/**
 * Una categoría tal como la pinta su pantalla.
 *
 * `productsCount` **incluye los borradores y los archivados** (RB-7 y la
 * decisión de `Category::scopeOrdered()`): es la cifra que explica por qué una
 * categoría se niega a desaparecer, así que esconder parte de ella dejaría el
 * `422` sin justificación visible.
 *
 * `position` viaja aunque la lista ya llegue ordenada, porque es lo que el
 * guardado del orden manda de vuelta: la pantalla reordena en local y envía la
 * lista entera (RB-2).
 */
export interface CategoryView {
    readonly id: number;
    readonly name: string;
    /** Viaja en la query string de los filtros del catálogo; se edita aparte del nombre (RB-6). */
    readonly slug: string;
    readonly position: number;
    readonly productsCount: number;
}

/**
 * Una etiqueta tal como la pinta su pantalla.
 *
 * Sin `position`, y no es un olvido: las etiquetas no se ordenan a mano —se
 * listan por nombre— y esa es la razón de que su tabla no tenga la columna.
 */
export interface TagView {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
    readonly productsCount: number;
}
