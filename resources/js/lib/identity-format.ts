/**
 * Máscaras de entrada del documento y del teléfono (`REQ-IDN-007`).
 *
 * **La máscara nunca rechaza.** Da forma a lo que se escribe y deja pasar
 * todo: no recorta, no borra letras ni dígitos, no limita la longitud y no
 * lanza. Quien decide si un documento o un teléfono son válidos es PHP
 * —`DocumentNumber`, `PhoneNumber` y sus reglas—, siempre. Una máscara que
 * rechaza es una segunda validación que nadie mantiene, y su fallo típico es
 * bloquear un documento legítimo sin decir por qué.
 *
 * **Y no es la presentación de lo guardado.** Lo que se enseña de una ficha
 * ya existente viaja formateado desde el servidor (`formatted()`), como el
 * dinero. Esto es solo lo que se ve mientras se teclea.
 *
 * El único censo que queda aquí es qué letras son de un RIF, porque el RIF y
 * la cédula se escriben distinto. Está probado contra el servidor con una tabla
 * de casos que recorren las dos suites (`identity-format.cases.json`): si
 * `DocumentKind` gana una letra y esta lista no, esa tabla se pone roja en
 * uno de los dos lados. Los prefijos de operadora **no** están: la máscara del
 * teléfono no necesita saber qué código es válido para darle forma.
 */

/** Letras del RIF (`DocumentKind::Rif`). Todo lo demás se agrupa como cédula. */
const RIF_PREFIXES = ['J', 'G'];

/**
 * Lo que la máscara vuelve a poner por su cuenta, y por eso puede quitar:
 * espacios, puntos y cualquier guion.
 */
const DOCUMENT_SEPARATORS = /[\s.\p{Pd}]+/gu;

const PHONE_SEPARATORS = /[\s.()\p{Pd}]+/gu;

/**
 * Da forma a un documento mientras se escribe: `v12345678` → `V-12.345.678`,
 * `j123456789` → `J-12345678-9`.
 *
 * Sin letra no se inventa ninguna: se agrupan los dígitos (`12.345.678`) y la
 * `V` la pone el servidor al guardar, que es quien sabe que sin letra es
 * cédula. Si lo escrito no tiene la forma de un documento —una coma, dos
 * letras—, se devuelve **tal cual**, para que el rechazo lo diga el servidor
 * con su motivo en lugar de que la caja se trague lo tecleado.
 */
export function formatDocumentNumber(raw: string): string {
    const compact = raw.replace(DOCUMENT_SEPARATORS, '').toUpperCase();
    const match = /^([A-Z]?)(\d*)$/.exec(compact);

    if (match === null) {
        return raw;
    }

    const letter = match[1] ?? '';
    const digits = match[2] ?? '';

    if (digits === '') {
        return letter;
    }

    const body = RIF_PREFIXES.includes(letter) ? rifBody(digits) : groupThousands(digits);

    return letter === '' ? body : `${letter}-${body}`;
}

/**
 * Da forma a un teléfono mientras se escribe: `04141234567` → `0414-1234567`,
 * `+584141234567` → `+58 414-1234567`.
 *
 * No comprueba el código de operadora ni la longitud: eso es de
 * `PhoneNumber`. Lo que no tiene forma de teléfono se devuelve tal cual.
 */
export function formatPhone(raw: string): string {
    const compact = raw.replace(PHONE_SEPARATORS, '');
    const match = /^(\+?)(\d*)$/.exec(compact);

    if (match === null) {
        return raw;
    }

    const plus = match[1] ?? '';
    const digits = match[2] ?? '';

    if (plus !== '') {
        return digits.startsWith('58') && digits.length > 2 ? `+58 ${splitAt(digits.slice(2), 3)}` : `+${digits}`;
    }

    return splitAt(digits, digits.startsWith('0') ? 4 : 3);
}

/**
 * El código del teléfono —`414`, `212`—, o `null` mientras no esté completo.
 *
 * Existe para el aviso que **no bloquea** del formulario de apartado
 * (`T-045b`): si el código no está en el censo que manda el servidor
 * (`PhoneNumber::recognisedAreaCodes()`), se pregunta «¿es correcto?» y se deja
 * seguir. El censo no está aquí —llega como dato—; lo único que se replica es
 * cómo se lee un teléfono escrito, y eso lo vigila la tabla de casos contra
 * `PhoneNumber::areaCode()`.
 *
 * Lee las mismas escrituras que `PhoneNumber::fromInput()`: nacional con cero,
 * sin él, y con `+58` o `58` delante. Cualquier otra cosa es `null`: no hay
 * código que preguntar, y el rechazo, si toca, lo da el servidor.
 */
export function phoneAreaCode(raw: string): string | null {
    const compact = raw.replace(PHONE_SEPARATORS, '');
    const international = compact.startsWith('+');
    const digits = international ? compact.slice(1) : compact;

    if (!/^\d+$/.test(digits)) {
        return null;
    }

    let national = digits;

    if (international || (digits.length === NATIONAL_DIGITS + 2 && digits.startsWith('58'))) {
        if (!digits.startsWith('58')) {
            return null;
        }

        national = digits.slice(2);
    }

    if (national.startsWith('0')) {
        national = national.slice(1);
    }

    return national.length === NATIONAL_DIGITS ? national.slice(0, 3) : null;
}

/** Dígitos nacionales de un teléfono: código de tres y abonado de siete. */
const NATIONAL_DIGITS = 10;

/**
 * De tres en tres desde la derecha y **sobre la cadena**, como el servidor:
 * `0012345` → `0.012.345`, sin perder un cero (RB-7).
 */
function groupThousands(digits: string): string {
    const groups: string[] = [];

    for (let end = digits.length; end > 0; end -= 3) {
        groups.unshift(digits.slice(Math.max(0, end - 3), end));
    }

    return groups.join('.');
}

/**
 * Con más de ocho dígitos, el último se separa como verificador, igual que en
 * `DocumentNumber::formatted()`. No se comprueba que lo sea (RB-8).
 */
function rifBody(digits: string): string {
    return digits.length > 8 ? `${digits.slice(0, -1)}-${digits.slice(-1)}` : digits;
}

function splitAt(digits: string, position: number): string {
    return digits.length > position ? `${digits.slice(0, position)}-${digits.slice(position)}` : digits;
}
