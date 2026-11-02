export function compactQuery(params: object, defaults: Readonly<Record<string, unknown>> = {}): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(params) as [string, unknown][]) {
        if (value === true) {
            query[key] = 1;
        } else if ((typeof value === 'string' && value !== '') || typeof value === 'number') {
            if (defaults[key] !== value) {
                query[key] = value;
            }
        }
    }

    return query;
}
