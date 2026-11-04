interface Surfaces {
    readonly backoffice: string;
    readonly platform: string;
    readonly site: string;
}

declare global {
    interface Window {
        apartarSurfaces?: Partial<Surfaces>;
    }
}

const DEFAULTS: Surfaces = { backoffice: '/backoffice', platform: '/plataforma', site: '' };

function base(surface: keyof Surfaces): string {
    const configured = typeof window === 'undefined' ? undefined : window.apartarSurfaces?.[surface];

    return typeof configured === 'string' ? configured : DEFAULTS[surface];
}

function join(prefix: string, path: string): string {
    const full = `${prefix}${path}`;

    return full === '' ? '/' : full;
}

export function bo(path = ''): string {
    return join(base('backoffice'), path);
}

export function pf(path = ''): string {
    return join(base('platform'), path);
}

export function site(path = '/'): string {
    return join(base('site'), path);
}
