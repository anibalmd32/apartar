import { type CurrencyPageProps } from '@/types/exchange';
import { type CurrencyDisplay } from '@/types/money';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Qué moneda va primero, y cómo se recuerda (`REQ-FX-004` RB-3, RB-4, RB-6).
 *
 * ## El valor inicial llega resuelto del servidor
 *
 * No se descubre al montar: viene en las page props, ya decidido en el orden
 * de RB-5 —cookie válida, ajuste de la organización, default de plataforma—.
 * Si se leyera aquí, la página abriría en dólares y saltaría a bolívares un
 * instante después **en cada visita** de todo el que eligiera bolívares, que
 * es el parpadeo que `ASU-FX-08` existe para evitar.
 *
 * Con una excepción que no parpadea: **la cookie que ya escribió este toggle**
 * gana a la prop, leída de forma síncrona en el primer render. Es la primera
 * regla de RB-5 —la que el servidor aplica igual— y hace falta en el cliente
 * porque la prop puede estar vieja: una recarga parcial (`Cargar más`, un
 * filtro) no trae las props compartidas, y las cards nuevas arrancarían con
 * la moneda de la primera carga al lado de las que ya se cambiaron.
 *
 * ## Todas las cifras de la página cambian a la vez (`T-035`)
 *
 * Cada `MoneyLabel` tiene su instancia de este hook. Hasta `T-035` cada una
 * guardaba su estado por separado, y el toggle solo cambiaba **el propio
 * botón**: ningún importe se reproyectaba. Ahora el cambio se difunde con un
 * evento de `window` a todas las instancias montadas, sin contexto que haya
 * que acordarse de montar en cada layout y sin estado de módulo que viva entre
 * tests.
 *
 * ## Se guarda en dos sitios, y ninguno es obligatorio
 *
 * `localStorage` para la siguiente pestaña y una cookie de un año para el
 * siguiente primer render del servidor (RB-4). Los dos se escriben dentro de
 * `try`: en modo privado `localStorage` **lanza al leer y al escribir**, y un
 * catálogo que no abre porque el navegador no deja guardar una preferencia es
 * un catálogo que no vende (SP-1). Si los dos fallan, la preferencia vive en
 * memoria durante la sesión y el toggle sigue funcionando.
 *
 * ## Cambiar no genera ninguna petición
 *
 * RB-6: `MoneyView` ya trae las dos representaciones, así que el toggle solo
 * cambia cuál se lee primero. Ninguna llamada, ninguna recarga.
 *
 * ## Aquí no se emite telemetría
 *
 * RB-7 dice que cada cambio emite `currency_toggled`, y el bus —
 * `TelemetryEventName`, el buffer, el endpoint— es `T-084`. Lo que **no** hay
 * es un `track()` provisional que se trague los eventos: un emisor silencioso
 * es indistinguible de uno conectado, ningún test se pondría rojo, y el día
 * que llegue el bus nadie sabría si alguna vez estuvo enchufado. El enchufe se
 * nombra donde se ve —aquí, en este párrafo— y `T-084` lo conecta.
 */

const DISPLAYS: readonly CurrencyDisplay[] = ['usd_primary', 'ves_primary'];

/** El mismo nombre que usa el servidor en `CurrencyDisplay::COOKIE`. */
export const CURRENCY_COOKIE = 'currency_display';

/** Un año, como fija RB-4. */
export const CURRENCY_COOKIE_DAYS = 365;

/** La clave de `localStorage`, que es la misma que la de la cookie. */
export const CURRENCY_STORAGE_KEY = CURRENCY_COOKIE;

function isCurrencyDisplay(value: unknown): value is CurrencyDisplay {
    return typeof value === 'string' && DISPLAYS.includes(value as CurrencyDisplay);
}

/**
 * Escribe la preferencia donde se pueda, y no se queja de dónde no.
 *
 * Cada almacén va en su propio `try`: que `localStorage` esté bloqueado no
 * puede impedir que la cookie se escriba, porque la cookie es la que hace
 * correcto el primer render del servidor.
 */
/** El evento con el que una instancia avisa a las demás de la moneda elegida. */
export const CURRENCY_EVENT = 'apartar:currency-display';

/**
 * La cookie de este toggle, si es válida. Dentro de `try` por lo mismo que la
 * escritura: un navegador que no deja leerla no puede romper la página.
 */
function rememberedCookie(): CurrencyDisplay | null {
    try {
        const match = document.cookie.match(new RegExp(`(?:^|; )${CURRENCY_COOKIE}=([^;]*)`));
        const value = match?.[1];

        return isCurrencyDisplay(value) ? value : null;
    } catch {
        return null;
    }
}

function remember(display: CurrencyDisplay): void {
    try {
        window.localStorage.setItem(CURRENCY_STORAGE_KEY, display);
    } catch {
        // Modo privado, almacenamiento bloqueado o cuota llena. La
        // preferencia sigue viva en memoria y, si la cookie entra, también
        // entre visitas.
    }

    try {
        const maxAge = CURRENCY_COOKIE_DAYS * 24 * 60 * 60;

        document.cookie = `${CURRENCY_COOKIE}=${display}; path=/; max-age=${maxAge}; SameSite=Lax`;
    } catch {
        // Sin cookie, el primer render del servidor volverá al default y el
        // cliente lo corregirá al montar. Es peor que con ella y mucho mejor
        // que una excepción.
    }
}

export interface CurrencyDisplayState {
    /** Cuál va primero ahora mismo. */
    display: CurrencyDisplay;
    /** `true` cuando los bolívares van delante. */
    isVesPrimary: boolean;
    /** Cambia al otro modo y lo recuerda. */
    toggle: () => void;
    /** Fija uno concreto y lo recuerda. */
    setDisplay: (next: CurrencyDisplay) => void;
}

export function useCurrencyDisplay(): CurrencyDisplayState {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;

    const [display, setLocalDisplay] = useState<CurrencyDisplay>(
        () => rememberedCookie() ?? (isCurrencyDisplay(currency?.display) ? currency.display : 'usd_primary'),
    );

    // Lo que elija otra instancia —el toggle— lo aplica esta también.
    useEffect(() => {
        const follow = (event: Event) => {
            const next: unknown = (event as CustomEvent<unknown>).detail;

            if (isCurrencyDisplay(next)) {
                setLocalDisplay(next);
            }
        };

        window.addEventListener(CURRENCY_EVENT, follow);

        return () => window.removeEventListener(CURRENCY_EVENT, follow);
    }, []);

    const setDisplay = useCallback((next: CurrencyDisplay) => {
        setLocalDisplay(next);
        remember(next);
        window.dispatchEvent(new CustomEvent(CURRENCY_EVENT, { detail: next }));
    }, []);

    const toggle = useCallback(() => {
        setDisplay(display === 'usd_primary' ? 'ves_primary' : 'usd_primary');
    }, [display, setDisplay]);

    return {
        display,
        isVesPrimary: display === 'ves_primary',
        toggle,
        setDisplay,
    };
}
