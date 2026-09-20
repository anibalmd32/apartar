import { type MoneyView } from '@/types/money';

/**
 * Lo que el negocio vende y con qué condiciones lo financia
 * (`REQ-BOF-020`).
 *
 * Espejo de `app/Actions/Product/` y de `App\Domain\Catalog\FinancingPlan`.
 * La regla que gobierna este archivo es la del dinero: **el frontend no hace
 * aritmética de importes**. Ni convierte, ni formatea, ni divide — y aquí lo
 * último es lo que más cuesta resistir, porque la cuota parece una división de
 * una línea.
 */

/** Los tres estados de `ProductStatus`. */
export type ProductStatus = 'draft' | 'published' | 'archived';

/**
 * Qué es cada estado, resuelto por el servidor desde el enum.
 *
 * Las etiquetas viajan en vez de escribirse aquí por lo mismo que los seis
 * métodos de pago: dos censos de los mismos tres casos coinciden hasta el día
 * en que uno cambia, y el que se queda viejo es siempre el de la pantalla.
 */
export interface ProductStatusDefinition {
    readonly value: ProductStatus;
    readonly label: string;
}

/** Una categoría o una etiqueta, tal como las ofrece el formulario. */
export interface TaxonomyOption {
    readonly id: number;
    readonly name: string;
}

/**
 * **La previsualización del plan de pago (RB-7).**
 *
 * Llega con las cuotas **ya repartidas y ya formateadas**, y esa es la
 * decisión que sostiene el requisito: el reparto lo hace
 * `Money::allocateEvenly()` en el servidor, que es el mismo que generará el
 * cronograma real en H3. Si la pantalla dividiera por su cuenta, el negocio
 * vería una cuota en el formulario y su cliente otra en el catálogo — por un
 * céntimo, que es suficiente para que nadie vuelva a fiarse de la cifra.
 *
 * Por eso los importes son `MoneyView` y no números: **no hay ninguna cifra
 * aquí con la que se pueda dividir**. `MoneyView.cents` existe para ordenar y
 * comparar, nunca para calcular una cuota.
 *
 * `null` en lugar de un plan significa que los términos todavía no permiten
 * repartir nada —cero cuotas, o la inicial por encima del precio—, que es el
 * estado normal de un formulario a medio escribir.
 */
export interface FinancingPlanView {
    readonly downPayment: MoneyView;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    /** La cuota que se repite: la que dice la frase de RB-7. */
    readonly installmentAmount: MoneyView;
    /** La última, que puede llevar el residuo del reparto. */
    readonly lastInstallmentAmount: MoneyView;
    /** La última difiere: la UI lo dice con esas palabras en vez de una media. */
    readonly hasUnevenLastInstallment: boolean;
    readonly financedAmount: MoneyView;
    readonly total: MoneyView;
}

/**
 * Una fila de ficha técnica tal como la edita el formulario
 * (`REQ-BOF-021` RB-1).
 *
 * `key` es **local y obligatoria**, y es la pieza que hace que SP-6 funcione:
 * una fila recién añadida todavía no tiene `id` de base, así que si el
 * arrastre y el `key` de React dependieran del `id`, todas las filas nuevas
 * compartirían `undefined` y reordenar las mezclaría. La clave local existe
 * desde que la fila aparece en pantalla y no cambia al moverla.
 *
 * `id` viaja solo como información: **el servidor no lo usa**. El guardado es
 * un reemplazo completo (RB-5), así que las filas se borran y se reinsertan y
 * los identificadores de después no son los de antes.
 */
export interface SpecificationFormRow {
    /** Clave estable en el cliente; no es el `id` de la base (SP-6). */
    readonly key: string;
    readonly id: number | null;
    /** Sección de la ficha; vacío es «General». */
    readonly group: string;
    readonly label: string;
    readonly value: string;
}

/**
 * Una imagen de producto en sus tres tamaños (`REQ-CAT-005`).
 *
 * Espejo exacto del contrato `ImageSet` del módulo [11] y de
 * `App\Domain\Catalog\ImageSet`. **Las tres URL siempre apuntan a algo**: si
 * una conversión todavía no existe —se generan en cola, y a veces fallan— el
 * servidor devuelve la del original (RB-3). Nunca llega `null` ni una cadena
 * vacía, así que ninguna pantalla tiene que decidir qué pintar en un hueco.
 *
 * El `alt` tampoco viaja vacío: cuando el negocio no lo ha escrito, el
 * servidor manda el defecto de RB-4.
 */
export interface ImageSet {
    readonly thumb: string;
    readonly card: string;
    readonly zoom: string;
    readonly alt: string;
}

/**
 * Una imagen tal como la ve **el negocio** en su galería
 * (`REQ-BOF-022` RB-3, SP-6).
 *
 * Amplía el contrato público con dos banderas que el cliente final no tiene
 * por qué conocer: si las conversiones se están generando y si alguna falló.
 * Las dos existen para que la pantalla pueda explicar por qué una foto se ve
 * distinta —sirve el original— en lugar de dejar al negocio pensando que subió
 * mal el archivo.
 */
export interface ProductImageView {
    readonly id: number;
    readonly set: ImageSet;
    /** Las conversiones aún no están; se está sirviendo el original (RB-3). */
    readonly isProcessing: boolean;
    /** El job de conversión falló: la imagen sirve igual, pero conviene mirarla (SP-6). */
    readonly needsReview: boolean;
}

/**
 * Una sección de la ficha, tal como se lee (`REQ-CAT-004` RB-2).
 *
 * Espejo de lo que compone `App\Domain\Catalog\SpecificationGroups`. `group`
 * es `null` para las filas sin sección, y **el rótulo «General» lo pone la
 * pantalla**: traducirlo en el servidor haría indistinguible una ficha sin
 * secciones de una con una sección llamada así.
 */
export interface SpecificationGroupView {
    readonly group: string | null;
    readonly items: readonly { readonly label: string; readonly value: string }[];
}

/**
 * Un producto tal como lo carga su formulario.
 *
 * Los importes viajan **en dólares con dos decimales** y como cadena, que es
 * como se escriben (RB-2) y como se vuelven a enviar. Es lo contrario que en
 * el listado, donde son `MoneyView` porque allí se leen y no se editan: un
 * campo de formulario con un importe ya formateado —`$1,234.56`— no se puede
 * reenviar sin deshacer el formato, y deshacerlo en el cliente es la puerta
 * por la que vuelve la coma flotante.
 */
export interface ProductFormValues {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly slug: string;
    readonly description: string | null;
    readonly price: string;
    readonly compareAtPrice: string | null;
    readonly downPayment: string;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly stock: number;
    readonly status: ProductStatus;
    readonly categoryId: number | null;
    readonly tagIds: readonly number[];
    /** Las filas guardadas, por `position` y a igualdad por `id` (RB-4). */
    readonly specifications: readonly { readonly id: number; readonly group: string | null; readonly label: string; readonly value: string }[];
    /** Las fotos en su orden: la primera es la principal (`REQ-BOF-022` RB-2). */
    readonly images: readonly ProductImageView[];
    /** Tope de fotos por producto, para deshabilitar la subida con su motivo. */
    readonly maxImages: number;
    /** Publicado: su URL ya circuló y el enlace deja de ser editable (SP-5). */
    readonly slugIsLocked: boolean;
    /** SP-7 · gana el último, y la marca dice cuándo fue. */
    readonly updatedAt: string | null;
}

/**
 * Los valores por defecto de la organización (RB-4).
 *
 * Llegan **al abrir el formulario** y no se aplican al guardar: un default
 * puesto por el servidor sobre un campo que alguien dejó vacío a propósito es
 * un valor que nadie eligió.
 */
export interface ProductDefaults {
    readonly installmentsCount: number;
    readonly frequencyDays: number;
}

/**
 * Un producto en el listado del backoffice (RB-9).
 *
 * `catalogsCount` es la columna de `REQ-CAT-007` RB-6: un producto puede estar
 * publicado y no verlo nadie porque no está en ningún catálogo, y el
 * backoffice tiene que poder decirlo.
 *
 * `scheduledFor` es SP-5 y llega **resuelto y formateado por el servidor**,
 * igual que el dinero. La pantalla no compara fechas con su propio reloj:
 * quien decide si un producto se lista es `Product::scopePublished()` contra
 * la hora del servidor, y un navegador con la hora corrida pintaría
 * «Programado» sobre algo que ya se ve. `null` es «no está programado», que
 * cubre a la vez el borrador, el archivado y el publicado ya visible.
 */
export interface ProductListItem {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly status: ProductStatus;
    readonly stock: number;
    readonly price: MoneyView;
    readonly categoryName: string | null;
    readonly catalogsCount: number;
    /** Publicado con fecha futura: desde cuándo se verá, ya formateado (SP-5). */
    readonly scheduledFor: string | null;
}

/** Los filtros del listado, tal como viajan en la URL (RB-9). */
export interface ProductFilters {
    readonly status: ProductStatus | null;
    readonly category: number | null;
    readonly lowStock: boolean;
    readonly search: string | null;
}
