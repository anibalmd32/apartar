export type ToastTone = 'success' | 'error' | 'info';

export interface ToastMessage {
    id: number;
    tone: ToastTone;
    text: string;
}

type Listener = (toasts: ToastMessage[]) => void;

const DURATION: Record<ToastTone, number> = {
    success: 4000,
    info: 5000,
    error: 8000,
};

let toasts: ToastMessage[] = [];
let nextId = 1;
const listeners = new Set<Listener>();

function emit(): void {
    for (const listener of listeners) {
        listener(toasts);
    }
}

export function dismissToast(id: number): void {
    toasts = toasts.filter((toast) => toast.id !== id);
    emit();
}

function push(tone: ToastTone, text: string): number {
    const duplicate = toasts.find((toast) => toast.tone === tone && toast.text === text);

    if (duplicate) {
        return duplicate.id;
    }

    const id = nextId++;
    toasts = [...toasts.slice(-2), { id, tone, text }];
    emit();
    setTimeout(() => dismissToast(id), DURATION[tone]);

    return id;
}

export const toast = {
    success: (text: string) => push('success', text),
    error: (text: string) => push('error', text),
    info: (text: string) => push('info', text),
};

export function subscribeToToasts(listener: Listener): () => void {
    listeners.add(listener);
    listener(toasts);

    return () => {
        listeners.delete(listener);
    };
}

export function resetToasts(): void {
    toasts = [];
    emit();
}
