import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { cn } from '@/lib/utils';
import { type ImageSet } from '@/types/product';
import { useState } from 'react';

interface ProductGalleryProps {
    images: readonly ImageSet[];
    name: string;
}

export function ProductGallery({ images, name }: ProductGalleryProps) {
    const [selected, setSelected] = useState(0);
    const current = images[selected] ?? images[0];

    if (current === undefined) {
        return <ProductPlaceholder name={name} labelled className="aspect-[620/560] rounded-2xl text-8xl" />;
    }

    return (
        <div className="flex flex-col gap-3">
            <img src={current.zoom} alt={current.alt} className="bg-base-100 border-line aspect-[620/560] w-full rounded-2xl border object-contain" />

            {images.length > 1 ? (
                <ul className="grid grid-cols-4 gap-3" aria-label="Fotos del producto">
                    {images.map((image, index) => (
                        <li key={image.thumb}>
                            <button
                                type="button"
                                aria-label={`Ver la foto ${index + 1}`}
                                aria-pressed={index === selected}
                                className={cn(
                                    'block aspect-[150/110] w-full cursor-pointer overflow-hidden rounded-[14px] border-2',
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
    );
}
