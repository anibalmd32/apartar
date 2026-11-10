import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field-error';
import { Icon } from '@/components/ui/icon';
import { formatDateTime } from '@/lib/dates';
import { pf } from '@/lib/surfaces';
import { type PendingPhotoReview } from '@/types/platform';
import { cilCheckAlt, cilX } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { useId, useState } from 'react';

export function PhotoReviewDecision({ review }: { review: PendingPhotoReview }) {
    const [mode, setMode] = useState<'approve' | 'reject'>('approve');
    const reasonId = useId();
    const approve = useForm({ review: '' });
    const reject = useForm({ reason: '' });

    return (
        <section aria-label={`Revisión de ${review.product.name}`} className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-lg font-bold">{review.product.name}</h2>
                <p className="text-ink-3 text-[13px]">
                    {review.organization.name} · pedida el {formatDateTime(review.requestedAt)}
                </p>
            </div>

            <dl className="flex flex-col gap-2.5 text-[13px]">
                <div className="flex flex-col gap-0.5">
                    <dt className="text-ink-3">La IA vio</dt>
                    <dd className="font-semibold">{review.observation ?? `Fotos que parecen mostrar ${review.subject}.`}</dd>
                </div>
                {review.product.description !== '' ? (
                    <div className="flex flex-col gap-0.5">
                        <dt className="text-ink-3">Descripción del negocio</dt>
                        <dd className="text-ink-2 line-clamp-6">{review.product.description}</dd>
                    </div>
                ) : null}
            </dl>

            {approve.errors.review ? <FieldError>{approve.errors.review}</FieldError> : null}

            {mode === 'approve' ? (
                <div className="flex flex-col gap-2">
                    <Button
                        variant="success"
                        block
                        loading={approve.processing}
                        className="h-12 text-[15px]"
                        onClick={() => approve.post(pf(`/revisiones/${review.id}/aprobar`), { preserveScroll: true })}
                    >
                        {approve.processing ? null : <Icon icon={cilCheckAlt} />}
                        Aprobar: se puede vender
                    </Button>
                    <Button variant="secondary" block className="h-12 text-[15px]" onClick={() => setMode('reject')}>
                        <Icon icon={cilX} />
                        Rechazar
                    </Button>
                </div>
            ) : (
                <form
                    aria-label="Rechazar las fotos"
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        reject.post(pf(`/revisiones/${review.id}/rechazar`), { preserveScroll: true });
                    }}
                >
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={reasonId} className="text-[13px] font-semibold">
                            Motivo del rechazo (el negocio lo verá)
                        </label>
                        <textarea
                            id={reasonId}
                            className="textarea w-full"
                            value={reject.data.reason}
                            aria-invalid={reject.errors.reason ? true : undefined}
                            onChange={(event) => reject.setData('reason', event.target.value)}
                        />
                        {reject.errors.reason ? <FieldError>{reject.errors.reason}</FieldError> : null}
                    </div>
                    <div className="mt-1 flex flex-col gap-2">
                        <Button
                            type="submit"
                            variant="danger"
                            block
                            loading={reject.processing}
                            disabled={reject.data.reason.trim() === ''}
                            className="h-12 text-[15px]"
                        >
                            {reject.processing ? null : <Icon icon={cilX} />}
                            Rechazar fotos
                        </Button>
                        <Button variant="ghost" block className="h-12 text-[15px]" onClick={() => setMode('approve')}>
                            Volver a aprobar
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
