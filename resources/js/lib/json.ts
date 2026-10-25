export class JsonRequestError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match?.[1] === undefined ? null : decodeURIComponent(match[1]);
}

async function request<T>(method: 'GET' | 'POST', url: string, body?: unknown): Promise<T> {
    const token = xsrfToken();
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
            ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as { message?: string };

        throw new JsonRequestError(response.status, payload.message ?? `La petición falló (${response.status}).`);
    }

    return (await response.json()) as T;
}

export function getJson<T>(url: string): Promise<T> {
    return request<T>('GET', url);
}

export function postJson<T>(url: string, body: unknown): Promise<T> {
    return request<T>('POST', url, body);
}
