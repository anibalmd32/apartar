function lowerCatalog(name: string): string | null {
    return /^cat[aá]logo\b/i.test(name) ? `${name.charAt(0).toLowerCase()}${name.slice(1)}` : null;
}

export function catalogMention(name: string): string {
    const lowered = lowerCatalog(name);

    return lowered === null ? name : `el ${lowered}`;
}

export function towardsCatalog(name: string): string {
    const lowered = lowerCatalog(name);

    return lowered === null ? `a ${name}` : `al ${lowered}`;
}
