import { type CatalogExpiredProps } from '@/types/catalog-view';
import { Head } from '@inertiajs/react';

/**
 * La campaña terminó (`REQ-CAT-021` SP-3), y la respuesta es un `410`.
 *
 * Quien llega aquí pulsó un enlace bueno que llegó tarde. La página se lo dice
 * con esas palabras —existió, y ya no está— en lugar de un «no encontrado»
 * que le haría pensar que lo copió mal.
 *
 * Y si el negocio tiene la vitrina activada, la enlaza (`T-036`): quien llegó
 * tarde a una campaña puede ver las que siguen. Sin vitrina no hay enlace,
 * porque llevaría a un `404`.
 */

export default function CatalogExpired({ catalogName, businessName, storefrontUrl }: CatalogExpiredProps) {
    return (
        <div className="bg-base-200 flex min-h-screen items-center justify-center px-4">
            <Head title={`${catalogName} terminó`} />

            <div className="bg-base-100 rounded-box max-w-md p-8 text-center shadow-sm">
                <h1 className="text-xl font-bold">Esta campaña ya terminó</h1>
                <p className="mt-3 opacity-80">
                    El enlace es correcto: «{catalogName}» de {businessName} estuvo disponible, pero su plazo terminó. Si te interesaba algo,
                    pregúntale al negocio: puede que tenga una campaña nueva.
                </p>

                {storefrontUrl !== null ? (
                    <a href={storefrontUrl} className="btn btn-primary mt-6">
                        Ver los catálogos de {businessName}
                    </a>
                ) : null}
            </div>
        </div>
    );
}
