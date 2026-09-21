import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { TextField } from '@/components/ui/text-field';
import { CatalogVisibilityBadge } from '@/features/catalogs/components/CatalogVisibilityBadge';
import { ShareLinkField } from '@/features/catalogs/components/ShareLinkField';
import { type CatalogFormValues, type CatalogPublishBlockers } from '@/types/catalog';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

/**
 * Publicar y despublicar un catálogo (`REQ-CAT-022`, `REQ-BOF-024` RB-3, RB-5,
 * RB-9).
 *
 * ## Los requisitos se enseñan antes, no después
 *
 * RB-4 de `REQ-BOF-024` pide que la UI lo explique **antes** de publicar. Los
 * pendientes llegan en `blockers`, compuestos por la misma función del
 * servidor que rechazaría la publicación, así que el aviso y el `422` no
 * pueden discrepar.
 *
 * **El botón no se deshabilita por ellos.** Entre pintar esta pantalla y
 * pulsar puede haberse activado la cuenta de cobro que faltaba, y quien manda
 * es la acción: deshabilitar por una foto vieja dejaría a alguien mirando un
 * botón apagado sin entender por qué. Lo que sí se hace es decirlo.
 *
 * ## Y la advertencia de despublicar es un paso, no un texto
 *
 * RB-9 pide advertir **antes de confirmar** que los enlaces difundidos dejarán
 * de funcionar. Un botón directo no advierte de nada, así que el primero abre
 * la advertencia y el segundo la ejecuta. Se hace aquí y no con el `confirm()`
 * del navegador porque ese diálogo no cabe tres frases y no se puede leer con
 * calma.
 */

interface CatalogPublishPanelProps {
    catalog: CatalogFormValues;
    blockers: CatalogPublishBlockers;
}

/** `YYYY-MM-DD`, que es lo que espera un `<input type="date">`. */
function asDateInput(value: string | null): string {
    return value === null ? '' : value.slice(0, 10);
}

/**
 * Dónde se arregla cada pendiente que **no** se arregla en esta pantalla.
 *
 * SP-2 pide el enlace para crear la cuenta de cobro y SP-4 el reenvío de la
 * verificación: los dos son pendientes que se resuelven en otro sitio, y sin
 * el camino al lado el aviso deja a quien lo lee buscando por el menú. Los
 * otros dos —el catálogo vacío y la fecha pasada— tienen su control unos
 * centímetros más abajo, así que no llevan nada.
 */
const REMEDIES: Readonly<Record<string, () => ReactNode>> = {
    payment_accounts: () => (
        <Link className="link" href="/backoffice/cuentas-de-cobro">
            Ir a Cuentas de cobro
        </Link>
    ),
    owner_email: () => (
        <Link className="link" href="/email/verification-notification" method="post" as="button" type="button">
            Reenviar la verificación
        </Link>
    ),
};

export function CatalogPublishPanel({ catalog, blockers }: CatalogPublishPanelProps) {
    const isDraft = catalog.visibility === 'draft';

    const [confirming, setConfirming] = useState(false);

    const form = useForm({
        is_listed: catalog.isListed,
        expires_at: asDateInput(catalog.expiresAt),
    });

    const retire = useForm({});

    const publish: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(`/backoffice/catalogos/${catalog.id}/publicar`, { preserveScroll: true });
    };

    const pending = Object.entries(blockers);

    return (
        <section className="card bg-base-100 shadow-sm">
            <div className="card-body gap-4">
                <header className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="card-title text-base">Publicación</h2>

                    <CatalogVisibilityBadge visibility={catalog.visibility} />
                </header>

                {pending.length > 0 ? (
                    <Alert tone="warning" className="flex-col items-start gap-1">
                        <span className="font-medium">Para publicarlo te falta:</span>

                        {/* SP-2 y SP-4 · el pendiente que se arregla en otro
                            sitio lleva ahí. «Activa una cuenta de cobro» sin
                            decir dónde deja a quien lo lee buscando por el
                            menú, que es la mitad del trabajo que el aviso
                            venía a ahorrar. */}
                        <ul className="list-disc pl-5 text-sm">
                            {pending.map(([field, message]) => (
                                <li key={field}>
                                    {message} {REMEDIES[field]?.()}
                                </li>
                            ))}
                        </ul>
                    </Alert>
                ) : null}

                {catalog.visibility === 'expired' ? (
                    <Alert tone="warning">
                        La fecha de caducidad ya pasó: el enlace responde que la campaña terminó. Muévela al futuro —o vacíala— y vuelve a publicar.
                    </Alert>
                ) : null}

                {isDraft ? null : <ShareLinkField url={catalog.shareUrl} name={catalog.name} visibility={catalog.visibility} />}

                <form className="flex flex-col gap-3" onSubmit={publish} noValidate>
                    <CheckboxField
                        label="Mostrarlo en mi vitrina"
                        name="is_listed"
                        checked={form.data.is_listed}
                        onChange={(event) => form.setData('is_listed', event.target.checked)}
                        error={form.errors.is_listed}
                        hint="Sin esto sigue accesible: cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina."
                    />

                    <TextField
                        label="Caduca el"
                        type="date"
                        name="expires_at"
                        value={form.data.expires_at}
                        onChange={(event) => form.setData('expires_at', event.target.value)}
                        error={form.errors.expires_at}
                        hint="Opcional. El último día se vende entero; al día siguiente el enlace explica que la campaña terminó, sin que tengas que hacer nada."
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" loading={form.processing}>
                            {isDraft ? 'Publicar catálogo' : 'Guardar publicación'}
                        </Button>

                        {isDraft ? null : (
                            <Button type="button" variant="ghost" onClick={() => setConfirming(true)}>
                                Despublicar
                            </Button>
                        )}
                    </div>
                </form>

                {confirming ? (
                    <Alert tone="warning" className="flex-col items-start gap-2">
                        {/* RB-9 · las tres cosas que hay que saber antes de
                            pulsar: qué deja de funcionar, qué no se pierde y
                            qué sigue igual. */}
                        <p>
                            Los enlaces que ya compartiste dejarán de funcionar y quien los abra verá que el catálogo no existe. Las suscripciones no
                            se pierden: quedan inactivas y reviven si vuelves a publicarlo, y los apartados que salieron de aquí siguen igual. El
                            enlace tampoco se libera: seguirá siendo de este catálogo.
                        </p>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="primary"
                                loading={retire.processing}
                                onClick={() => retire.delete(`/backoffice/catalogos/${catalog.id}/publicar`)}
                            >
                                Sí, despublicar
                            </Button>

                            <Button type="button" variant="ghost" onClick={() => setConfirming(false)}>
                                Cancelar
                            </Button>
                        </div>
                    </Alert>
                ) : null}
            </div>
        </section>
    );
}
