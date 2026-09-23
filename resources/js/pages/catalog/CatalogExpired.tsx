import { type CatalogExpiredProps } from '@/types/catalog-view';
import { Head } from '@inertiajs/react';

/**
 * La campaña terminó (`REQ-CAT-021` SP-3), y la respuesta es un `410`.
 *
 * Quien llega aquí pulsó un enlace bueno que llegó tarde. La página se lo dice
 * con esas palabras —existió, y ya no está— en lugar de un «no encontrado»
 * que le haría pensar que lo copió mal.
 *
 * El enlace a la vitrina del negocio que SP-3 pide «si está activada» entra con
 * la vitrina (`T-036`): hoy apuntaría a una página que no existe.
 */

export default function CatalogExpired({ catalogName, businessName }: CatalogExpiredProps) {
    return (
        <div className="bg-base-200 flex min-h-screen items-center justify-center px-4">
            <Head title={`${catalogName} terminó`} />

            <div className="bg-base-100 rounded-box max-w-md p-8 text-center shadow-sm">
                <h1 className="text-xl font-bold">Esta campaña ya terminó</h1>
                <p className="mt-3 opacity-80">
                    El enlace es correcto: «{catalogName}» de {businessName} estuvo disponible, pero su plazo terminó. Si te interesaba algo,
                    pregúntale al negocio: puede que tenga una campaña nueva.
                </p>
            </div>
        </div>
    );
}
