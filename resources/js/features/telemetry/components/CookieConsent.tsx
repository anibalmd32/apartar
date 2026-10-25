import { Button } from '@/components/ui/button';

import { reopenConsent, useConsent } from '../hooks/useConsent';

export function CookieConsent() {
    const { asking, choose } = useConsent();

    if (!asking) {
        return null;
    }

    return (
        <section
            aria-labelledby="cookie-consent-title"
            className="card bg-base-100 border-base-300 fixed inset-x-4 bottom-4 z-40 mx-auto max-w-xl border shadow-lg"
        >
            <div className="card-body gap-3 p-4">
                <h2 id="cookie-consent-title" className="font-semibold">
                    ¿Nos dejas medir cómo se usa el catálogo?
                </h2>
                <p className="text-sm opacity-80">
                    Contamos visitas, búsquedas y tiempo en cada producto para que los negocios sepan qué interesa. No guardamos tu IP ni lo
                    compartimos con nadie. Lo esencial —apartar, subir un comprobante, seguir un catálogo— se registra siempre.
                </p>
                <div className="grid grid-cols-2 gap-2">
                    <Button variant="secondary" onClick={() => choose('essential')}>
                        Solo lo esencial
                    </Button>
                    <Button variant="secondary" onClick={() => choose('all')}>
                        Aceptar todo
                    </Button>
                </div>
            </div>
        </section>
    );
}

export function CookiePreferencesLink({ className = 'link link-hover' }: { className?: string }) {
    return (
        <button type="button" className={className} onClick={reopenConsent}>
            Preferencias de cookies
        </button>
    );
}
