export function focusesOnDesktop(): boolean {
    return typeof window !== 'undefined' && window.matchMedia?.('(min-width: 64rem)').matches === true;
}
