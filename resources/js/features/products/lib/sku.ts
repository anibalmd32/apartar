const IGNORED = new Set(['de', 'del', 'la', 'el', 'los', 'las', 'y', 'con', 'para', 'por', 'en', 'un', 'una', 'a']);

export function suggestSku(name: string, sequence: number): string {
    const words = name
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toUpperCase()
        .split(/[^A-Z0-9]+/)
        .filter((word) => word !== '' && !IGNORED.has(word.toLowerCase()))
        .slice(0, 3)
        .map((word) => word.slice(0, 3));

    const stem = words.length === 0 ? 'PRD' : words.join('-');

    return `${stem}-${String(Math.max(sequence, 1)).padStart(3, '0')}`;
}
