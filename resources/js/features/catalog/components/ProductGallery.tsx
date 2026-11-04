import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { cn } from '@/lib/utils';
import { type ImageSet } from '@/types/product';
import { type UIEvent, useState } from 'react';

interface ProductGalleryProps {
    images: readonly ImageSet[];
    name: string;
}

export function ProductGallery({ images, name }: ProductGalleryProps) {
    const [selected, setSelected] = useState(0);
    const [swiped, setSwiped] = useState(0);
    const current = images[selected] ?? images[0];

    if (current === undefined) {
        return <ProductPlaceholder name={name} labelled className="aspect-[620/560] rounded-xl text-8xl" />;
    }

    const track = (event: UIEvent<HTMLUListElement>) => {
        const strip = event.currentTarget;
        setSwiped(Math.round(strip.scrollLeft / Math.max(1, strip.clientWidth)));
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="relative @3xl:hidden">
                <ul
                    aria-label="Fotos del producto: desliza para ver más"
                    onScroll={track}
                    className="flex snap-x snap-mandatory overflow-x-auto rounded-xl [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                >
                    {images.map((image, index) => (
                        <li key={image.thumb} className="w-full shrink-0 snap-center">
                            <img
                                src={image.card}
                                alt={index === 0 ? image.alt : `${image.alt} (foto ${index + 1})`}
                                loading={index === 0 ? 'eager' : 'lazy'}
                                className="bg-base-100 aspect-square w-full object-cover"
                            />
                        </li>
                    ))}
                </ul>
                {images.length > 1 ? (
                    <span className="bg-neutral/75 text-neutral-content absolute right-3 bottom-3 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums">
                        {swiped + 1} / {images.length}
                    </span>
                ) : null}
            </div>

            <div className="hidden flex-col gap-3 @3xl:flex">
                <img
                    src={current.zoom}
                    alt={current.alt}
                    className="bg-base-100 border-line aspect-[620/560] w-full rounded-xl border object-cover"
                />

                {images.length > 1 ? (
                    <ul className="grid grid-cols-5 gap-2.5" aria-label="Fotos del producto">
                        {images.map((image, index) => (
                            <li key={image.thumb}>
                                <button
                                    type="button"
                                    aria-label={`Ver la foto ${index + 1}`}
                                    aria-pressed={index === selected}
                                    className={cn(
                                        'block aspect-square w-full cursor-pointer overflow-hidden rounded-lg border-2',
                                        index === selected ? 'border-primary' : 'border-transparent',
                                    )}
                                    onClick={() => setSelected(index)}
                                >
                                    <img src={image.thumb} alt="" loading="lazy" className="h-full w-full object-cover" />
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
        </div>
    );
}
