import { Badge } from '@/components/ui/badge';
import { FileLink } from '@/components/ui/file-link';
import { Icon } from '@/components/ui/icon';
import { PhotoReviewDecision } from '@/features/platform/components/PhotoReviewDecision';
import PlatformLayout from '@/layouts/platform-layout';
import { cn } from '@/lib/utils';
import { type PendingPhotoReview } from '@/types/platform';
import { cilImage } from '@coreui/icons';
import { useState } from 'react';

export default function PhotoReviewsIndex({ reviews }: { reviews: readonly PendingPhotoReview[] }) {
    const [selectedId, setSelectedId] = useState<number | null>(reviews[0]?.id ?? null);
    const selected = reviews.find((review) => review.id === selectedId) ?? reviews[0] ?? null;

    return (
        <PlatformLayout
            title="Revisión de fotos"
            description="Productos cuyas fotos no parecen mostrar algo a la venta. El negocio no puede publicarlos hasta que los apruebes."
        >
            {selected === null ? (
                <p className="border-line bg-base-100 text-ink-2 rounded-2xl border p-6 text-sm">No hay fotos por revisar.</p>
            ) : (
                <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)_minmax(0,360px)] lg:gap-5">
                    <ul
                        aria-label="Fotos por revisar"
                        className="border-line bg-base-100 shadow-card flex flex-col overflow-hidden rounded-2xl border"
                    >
                        {reviews.map((review) => {
                            const active = review.id === selected.id;
                            const cover = review.photos[0];

                            return (
                                <li key={review.id} className="border-line not-last:border-b">
                                    <button
                                        type="button"
                                        aria-pressed={active}
                                        onClick={() => setSelectedId(review.id)}
                                        className={cn(
                                            'flex w-full cursor-pointer items-center gap-3 p-3.5 text-left',
                                            active ? 'bg-primary-soft' : 'hover:bg-surface-2',
                                        )}
                                    >
                                        {cover ? (
                                            <img src={cover.thumb} alt="" className="size-10 shrink-0 rounded-lg object-cover" />
                                        ) : (
                                            <span className="bg-surface-2 text-ink-3 flex size-10 shrink-0 items-center justify-center rounded-lg">
                                                <Icon icon={cilImage} />
                                            </span>
                                        )}
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-semibold">{review.product.name}</span>
                                            <span className="text-ink-3 block truncate text-xs">{review.organization.name}</span>
                                        </span>
                                        {review.isLate ? <Badge tone="warning">Atrasada</Badge> : null}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="bg-base-300/60 grid min-h-[320px] grid-cols-2 gap-3 rounded-2xl p-4 lg:min-h-[440px]">
                        {selected.photos.length === 0 ? (
                            <p className="text-ink-2 col-span-2 my-auto text-center text-sm">El producto ya no tiene fotos.</p>
                        ) : (
                            selected.photos.map((photo) => (
                                <FileLink key={photo.zoom} url={photo.zoom} kind="image" title={photo.alt || 'Foto del producto'} className="block">
                                    <img src={photo.card} alt={photo.alt} className="aspect-square w-full rounded-xl object-cover" />
                                </FileLink>
                            ))
                        )}
                    </div>

                    <div className="border-line bg-base-100 shadow-card rounded-2xl border p-5">
                        <PhotoReviewDecision key={selected.id} review={selected} />
                    </div>
                </div>
            )}
        </PlatformLayout>
    );
}
