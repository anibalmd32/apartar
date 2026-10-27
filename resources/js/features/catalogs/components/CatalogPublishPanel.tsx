import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { DateField } from '@/components/ui/date-field';
import { SectionCard } from '@/components/ui/section-card';
import { CatalogShareRow } from '@/features/catalogs/components/CatalogShareButtons';
import { CatalogVisibilityBadge } from '@/features/catalogs/components/CatalogVisibilityBadge';
import { ShareLinkField } from '@/features/catalogs/components/ShareLinkField';
import { type CatalogFormValues, type CatalogPublishBlockers } from '@/types/catalog';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CatalogPublishPanelProps {
    catalog: CatalogFormValues;
    blockers: CatalogPublishBlockers;
}

function asDateInput(value: string | null): string {
    return value === null ? '' : value.slice(0, 10);
}

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
        <>
            <SectionCard title="Publicación" action={<CatalogVisibilityBadge visibility={catalog.visibility} />}>
                {pending.length > 0 ? (
                    <Alert tone="warning" title="Para publicarlo te falta:">
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

                <form className="flex flex-col gap-3" onSubmit={publish} noValidate>
                    <CheckboxField
                        label="Mostrarlo en mi vitrina"
                        name="is_listed"
                        checked={form.data.is_listed}
                        onChange={(event) => form.setData('is_listed', event.target.checked)}
                        error={form.errors.is_listed}
                        hint="Sin esto sigue accesible: cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina."
                    />

                    <DateField
                        label="Caduca el"
                        name="expires_at"
                        value={form.data.expires_at}
                        onChange={(value) => form.setData('expires_at', value)}
                        error={form.errors.expires_at}
                        hint="Opcional. El último día se vende entero; al día siguiente el enlace explica que la campaña terminó, sin que tengas que hacer nada."
                    />

                    <Button type="submit" variant={isDraft ? 'primary' : 'secondary'} block loading={form.processing}>
                        {isDraft ? 'Publicar catálogo' : 'Guardar publicación'}
                    </Button>
                </form>

                {isDraft ? null : (
                    <div className="bg-primary-soft flex flex-col gap-2 rounded-xl p-3.5">
                        <p className="text-sm font-bold">Comparte tu catálogo</p>
                        <ShareLinkField url={catalog.shareUrl} name={catalog.name} visibility={catalog.visibility} />
                        {catalog.visibility === 'expired' ? null : <CatalogShareRow url={catalog.shareUrl} name={catalog.name} />}
                    </div>
                )}
            </SectionCard>

            {isDraft ? null : (
                <section aria-labelledby="retire-heading" className="bg-error-soft border-error/20 flex flex-col gap-2.5 rounded-2xl border p-5">
                    <h2 id="retire-heading" className="text-error text-base font-bold">
                        Despublicar catálogo
                    </h2>

                    {confirming ? null : (
                        <>
                            <p className="text-ink-2 text-[13px]">Los enlaces que ya compartiste dejarán de funcionar.</p>
                            <Button type="button" variant="danger" size="sm" className="self-start" onClick={() => setConfirming(true)}>
                                Despublicar
                            </Button>
                        </>
                    )}

                    {confirming ? (
                        <div className="flex flex-col items-start gap-2.5 text-[13px]">
                            <p>
                                Los enlaces que ya compartiste dejarán de funcionar y quien los abra verá que el catálogo no existe. Las suscripciones
                                no se pierden: quedan inactivas y reviven si vuelves a publicarlo, y los apartados que salieron de aquí siguen igual.
                                El enlace tampoco se libera: seguirá siendo de este catálogo.
                            </p>

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    loading={retire.processing}
                                    onClick={() => retire.delete(`/backoffice/catalogos/${catalog.id}/publicar`)}
                                >
                                    Sí, despublicar
                                </Button>

                                <Button type="button" size="sm" onClick={() => setConfirming(false)}>
                                    Cancelar
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </section>
            )}
        </>
    );
}
