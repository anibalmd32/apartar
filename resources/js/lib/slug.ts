const INVALID_CHARACTERS = /[^a-z0-9]+/g;

const MAX_LENGTH = 100;

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
 * @param name
 * @param currentSlug
 * @param edited
 */
export function suggestSlug(name: string, currentSlug: string, edited: boolean): string {
    if (edited) {
        return currentSlug;
    }

    return toSlug(name);
}
