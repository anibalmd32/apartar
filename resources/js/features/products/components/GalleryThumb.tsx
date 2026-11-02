import { Badge } from '@/components/ui/badge';
import { SortableHandle, SortableRow } from '@/components/ui/sortable';
import { cn } from '@/lib/utils';
import { type ProductImageView } from '@/types/product';

interface GalleryThumbProps {
    image: ProductImageView;
    index: number;
    selected: boolean;
    onSelect: () => void;
}

export function GalleryThumb({ image, index, selected, onSelect }: GalleryThumbProps) {
    const label = `la imagen ${index + 1}`;

    return (
        <SortableRow id={image.id} label={label}>
            {({ containerProps, handleProps }) => (
                <li ref={containerProps.ref} style={containerProps.style} className={cn('relative min-w-0', containerProps.className)}>
                    <button
                        type="button"
                        onClick={onSelect}
                        aria-pressed={selected}
                        aria-label={`Elegir ${label}`}
                        className={cn(
                            'block w-full overflow-hidden rounded-lg ring-offset-2 ring-offset-[var(--color-base-100)] transition',
                            selected ? 'ring-primary ring-2' : 'ring-line hover:ring-line-strong ring-1',
                        )}
                    >
                        <img src={image.set.thumb} alt={image.set.alt} loading="lazy" className="aspect-square w-full object-cover" />
                    </button>

                    <span className="pointer-events-none absolute top-1 left-1 flex flex-wrap gap-1">
                        {image.isProcessing ? <Badge tone="info">Procesando</Badge> : null}
                        {image.needsReview ? <Badge tone="warning">Revisar</Badge> : null}
                    </span>

                    {index === 0 ? (
                        <span className="bg-primary text-primary-content pointer-events-none absolute inset-x-0 bottom-0 rounded-b-lg py-0.5 text-center text-[11px] font-semibold">
                            Principal
                        </span>
                    ) : null}

                    <span className="bg-base-100/90 absolute top-1 right-1 rounded-md max-lg:hidden">
                        <SortableHandle handleProps={handleProps} />
                    </span>
                </li>
            )}
        </SortableRow>
    );
}
