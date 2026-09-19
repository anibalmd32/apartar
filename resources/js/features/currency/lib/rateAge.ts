/**
 * Cuándo se publicó la tasa, en palabras y en reloj (`REQ-FX-005` RB-2).
 *
 * **Las dos funciones miden sobre `publishedAt`**, que es cuándo la publicó la
 * fuente y no cuándo corrió nuestra ingesta. Es la corrección que `T-019`
 * llevó al contrato de `RateView`: con la otra fecha, un fin de semana sin
 * publicación diría «hace 5 min» sobre una tasa del viernes, que es justo el
 * engaño que tener dos columnas venía a evitar.
 *
 * Vivían dentro de `ExchangeRateForm.tsx` hasta `T-022`. Se mudaron aquí
 * porque el sello del catálogo necesita lo mismo, y dos copias de «hace 3 h»
 * acaban discrepando sobre la misma fecha en dos pantallas.
 */

/**
 * La antigüedad en palabras: «hace 12 min», «hace 3 h», «hace 2 d».
 *
 * No dice si la tasa es válida —eso lo dice `isStale`, que lo decide el
 * servidor con el mismo predicado que la guarda de la ingesta y la alerta de
 * plataforma—. Esto solo pone la distancia en palabras.
 */
export function publishedAgo(publishedAt: string | null, now: Date = new Date()): string {
    if (publishedAt === null) {
        return 'sin fecha';
    }

    const minutes = Math.floor((now.getTime() - new Date(publishedAt).getTime()) / 60_000);

    if (minutes < 1) {
        return 'hace un momento';
    }

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `hace ${hours} h`;
    }

    return `hace ${Math.floor(hours / 24)} d`;
}

/**
 * La hora del sello: `DD/MM HH:mm`, **en la zona del negocio**.
 *
 * De las tres zonas posibles —la del navegador de quien mira, la de la
 * aplicación y la de la organización— manda la del negocio, y viaja en las
 * page props precisamente para poder usarla aquí. El BCV publica en hora de
 * Venezuela y el catálogo es de un negocio venezolano: quien mire desde Madrid
 * tiene que leer la misma hora que dice el BCV, no las cinco de la tarde. Con
 * la del navegador, el mismo sello diría cosas distintas a dos personas que
 * miran el mismo precio.
 *
 * ## Las piezas las da `Intl`; el orden lo ponemos nosotros
 *
 * Se usa `formatToParts()` y se compone `DD/MM HH:mm` a mano en lugar de dejar
 * que el locale elija el patrón. No es manía: pidiéndole a `es-VE` el día y el
 * mes con dos cifras, la versión de ICU del entorno decide si escribe `18/09`
 * o `18/9`, y el sello del catálogo cambiaría de forma según dónde corra sin
 * que nadie tocara el código. Es el mismo motivo por el que `MoneyFormatter`
 * no usa `NumberFormatter` en el servidor.
 *
 * De `Intl` se toma lo que sí hace bien y no queremos reimplementar: pasar un
 * instante a la zona pedida, con sus reglas de horario de verano.
 */
export function publishedStamp(publishedAt: string | null, timezone: string): string | null {
    if (publishedAt === null) {
        return null;
    }

    const at = new Date(publishedAt);

    if (Number.isNaN(at.getTime())) {
        return null;
    }

    const parts = new Intl.DateTimeFormat('en-US', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: timezone,
    }).formatToParts(at);

    const piece = (type: Intl.DateTimeFormatPartTypes): string => (parts.find((part) => part.type === type)?.value ?? '').padStart(2, '0');

    return `${piece('day')}/${piece('month')} ${piece('hour')}:${piece('minute')}`;
}
