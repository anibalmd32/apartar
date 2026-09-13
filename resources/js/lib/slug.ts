/**
 * Sugerencia del enlace público del negocio (`REQ-ORG-001` RB-2).
 *
 * El slug se propone solo mientras se escribe el nombre y sigue siendo
 * editable. Ese detalle es lo que hace que SP-1 —el choque de nombres— casi
 * nunca se dispare: quien ve `ferreteria-el-tornillo` sugerido no escribe
 * `tienda`, que es el que ya tienen otros nueve.
 *
 * **El servidor no se fía de esto.** `Str::slug()` vuelve a normalizar en
 * `RegisterOrganizationData` y la base tiene el índice único: esto es
 * comodidad de escritura, no validación.
 */

/** Lo que admite un segmento de URL de la vitrina. */
const INVALID_CHARACTERS = /[^a-z0-9]+/g;

/** `organizations.slug` admite cien caracteres. */
const MAX_LENGTH = 100;

/**
 * Convierte un texto libre en un segmento de URL.
 *
 * Devuelve cadena vacía cuando no queda nada que convertir —un nombre de solo
 * emojis o de solo signos—, y ese vacío es información: SP-7 pide entonces un
 * enlace escrito a mano en vez de inventarle uno al negocio.
 */
export function toSlug(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(INVALID_CHARACTERS, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, MAX_LENGTH)
        .replace(/-+$/g, '');
}

/**
 * Enlace que debe mostrarse al cambiar el nombre del negocio.
 *
 * Deja de sugerir en cuanto la persona toca el campo: sobrescribir lo que
 * alguien acaba de escribir es la clase de ayuda que se recuerda con rabia.
 *
 * @param name nombre del negocio tal como se está escribiendo
 * @param currentSlug lo que hay ahora en el campo del enlace
 * @param edited si la persona ya editó el enlace a mano
 */
export function suggestSlug(name: string, currentSlug: string, edited: boolean): string {
    if (edited) {
        return currentSlug;
    }

    return toSlug(name);
}
