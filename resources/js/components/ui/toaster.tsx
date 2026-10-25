import { dismissToast, subscribeToToasts, toast, type ToastMessage, type ToastTone } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { cilCheckCircle, cilInfo, cilWarning, cilX } from '@coreui/icons';
import { type Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon } from './icon';

const INLINE_STATUS: readonly RegExp[] = [/^backoffice\/auth\//, /^platform\/auth\//, /^auth\//, /^settings\//, /^portal\/Access$/];

const TONES: Record<ToastTone, { className: string; icon: string[] }> = {
    success: { className: 'alert-success', icon: cilCheckCircle },
    error: { className: 'alert-error', icon: cilWarning },
    info: { className: 'alert-info', icon: cilInfo },
};

const FAILURES: Record<number, string> = {
    403: 'No tienes permiso para hacer esto.',
    404: 'Eso ya no existe o se movió. Recarga la página.',
    413: 'El archivo pesa demasiado. Prueba con uno más liviano.',
    419: 'Tu sesión caducó. Recarga la página y vuelve a intentarlo.',
    429: 'Demasiados intentos seguidos. Espera un minuto y vuelve a probar.',
};

function announceStatus(page: Page): void {
    const status = page.props.status;

    if (typeof status === 'string' && status !== '' && !INLINE_STATUS.some((pattern) => pattern.test(page.component))) {
        toast.success(status);
    }
}

export function validationSummary(errors: Record<string, string>): string {
    const messages = Object.values(errors).filter((message) => message !== '');

    if (messages.length === 1 && messages[0]) {
        return messages[0];
    }

    return `Revisa los ${messages.length} campos marcados en rojo.`;
}

export function failureMessage(status: number): string {
    return FAILURES[status] ?? 'Algo falló de nuestro lado. Inténtalo de nuevo en un momento.';
}

export function Toaster({ initialPage }: { initialPage: Page }) {
    const [items, setItems] = useState<ToastMessage[]>([]);

    useEffect(() => subscribeToToasts(setItems), []);

    useEffect(() => {
        announceStatus(initialPage);

        const offSuccess = router.on('success', (event) => announceStatus(event.detail.page));
        const offError = router.on('error', (event) => {
            if (Object.keys(event.detail.errors).length > 0) {
                toast.error(validationSummary(event.detail.errors as Record<string, string>));
            }
        });
        const offInvalid = router.on('invalid', (event) => {
            const status = event.detail.response.status;

            if (import.meta.env.PROD || status < 500) {
                event.preventDefault();
                toast.error(failureMessage(status));
            }
        });
        const offException = router.on('exception', (event) => {
            event.preventDefault();
            toast.error('No hay conexión con el servidor. Revisa tu internet e inténtalo de nuevo.');
        });

        return () => {
            offSuccess();
            offError();
            offInvalid();
            offException();
        };
    }, [initialPage]);

    return (
        <div
            aria-live="polite"
            className="toast toast-top toast-center sm:toast-bottom sm:toast-end z-[60] w-full max-w-[min(26rem,calc(100vw-1.5rem))] p-3 sm:p-5"
        >
            {items.map((item) => (
                <div
                    key={item.id}
                    role={item.tone === 'error' ? 'alert' : 'status'}
                    className={cn('alert shadow-pop animate-toast-in flex items-start gap-2.5 text-left text-sm', TONES[item.tone].className)}
                >
                    <Icon icon={TONES[item.tone].icon} size={20} className="mt-px shrink-0" />
                    <span className="min-w-0 flex-1 leading-snug break-words whitespace-normal">{item.text}</span>
                    <button
                        type="button"
                        onClick={() => dismissToast(item.id)}
                        className="btn btn-ghost btn-sm btn-square -my-1 -mr-1 shrink-0"
                        aria-label="Cerrar aviso"
                    >
                        <Icon icon={cilX} />
                    </button>
                </div>
            ))}
        </div>
    );
}
