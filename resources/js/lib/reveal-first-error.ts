const INVALID = '[aria-invalid="true"], .field-error';
const FOCUSABLE =
    'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [contenteditable="true"], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

function isShown(element: HTMLElement): boolean {
    if (element.closest('[hidden], [inert], [aria-hidden="true"]')) {
        return false;
    }

    return typeof element.checkVisibility === 'function' ? element.checkVisibility() : true;
}

function describedControl(message: HTMLElement): HTMLElement | null {
    if (message.id === '') {
        return null;
    }

    const described = Array.from(document.querySelectorAll<HTMLElement>('[aria-describedby]'));

    return described.find((element) => (element.getAttribute('aria-describedby') ?? '').split(/\s+/).includes(message.id)) ?? null;
}

function controlFor(target: HTMLElement): HTMLElement {
    if (target.matches(FOCUSABLE)) {
        return target;
    }

    if (target.classList.contains('field-error')) {
        const control = describedControl(target) ?? target.parentElement?.querySelector<HTMLElement>(FOCUSABLE);

        return control ?? target;
    }

    return target.querySelector<HTMLElement>(FOCUSABLE) ?? target;
}

export function firstInvalidField(root: ParentNode = document): HTMLElement | null {
    const target = Array.from(root.querySelectorAll<HTMLElement>(INVALID)).find(isShown);

    return target ? controlFor(target) : null;
}

export function revealFirstError(root: ParentNode = document): boolean {
    const field = firstInvalidField(root);

    if (!field) {
        return false;
    }

    const smooth = !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    field.scrollIntoView?.({ block: 'center', behavior: smooth ? 'smooth' : 'auto' });
    field.focus({ preventScroll: true });

    return true;
}

export function afterPaint(callback: () => void): void {
    requestAnimationFrame(() => requestAnimationFrame(callback));
}
