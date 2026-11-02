function matches(query: string): boolean {
    return typeof window.matchMedia === 'function' && window.matchMedia(query).matches;
}

export function typesOnTouch(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return matches('(pointer: coarse)') || (navigator.maxTouchPoints > 0 && !matches('(min-width: 1024px)'));
}
