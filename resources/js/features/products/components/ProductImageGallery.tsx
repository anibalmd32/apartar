import { Badge } from '@/components/ui/badge';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { SectionCard } from '@/components/ui/section-card';
import { SortableList } from '@/components/ui/sortable';
import { GalleryPhotoControls } from '@/features/products/components/GalleryPhotoControls';
import { GalleryThumb } from '@/features/products/components/GalleryThumb';
import { ImageUploader } from '@/features/products/components/ImageUploader';
import { type GalleryDraft } from '@/features/products/hooks/useGalleryDraft';
import { bo } from '@/lib/surfaces';
import { type ProductImageView } from '@/types/product';
import { useState } from 'react';

interface ProductImageGalleryProps {
    productId: number;
    images: readonly ProductImageView[];
    maxImages: number;
    errors: Partial<Record<string, string>>;
    gallery: GalleryDraft;
}

export function ProductImageGallery({ productId, images, maxImages, errors, gallery }: ProductImageGalleryProps) {
    const url = bo(`/productos/${productId}/imagenes`);

    const byId = new Map(images.map((image) => [image.id, image]));

    const [removing, setRemoving] = useState<number | null>(null);

    const { order } = gallery;

    const [selected, setSelected] = useState<number | null>(null);

    const found = order.findIndex((entry) => entry.id === selected);
    const current = found === -1 ? 0 : found;
    const chosen = order[current];

    return (
        <SectionCard
            title="Imágenes"
            subtitle="La primera es la principal. El orden y los textos se guardan con el producto."
            action={gallery.dirty ? <Badge tone="warning">Sin guardar</Badge> : undefined}
        >
            <ImageUploader url={url} remaining={maxImages - images.length} error={errors.images} />

            {order.length === 0 ? (
                <p className="text-ink-3 text-sm">
                    Todavía no tiene fotos. La primera que subas será la que vean tus clientes en el catálogo y en el enlace que compartas.
                </p>
            ) : (
                <>
                    <ul className="grid grid-cols-4 gap-2 sm:grid-cols-5 lg:grid-cols-6" aria-label="Fotos del producto">
                        <SortableList
                            layout="grid"
                            ids={order.map((entry) => entry.id)}
                            onReorder={gallery.move}
                            labelOf={(id) => `la imagen ${order.findIndex((entry) => entry.id === id) + 1}`}
                        >
                            {order.map((entry, index) => {
                                const image = byId.get(entry.id);

                                return image === undefined ? null : (
                                    <GalleryThumb
                                        key={entry.id}
                                        image={image}
                                        index={index}
                                        selected={index === current}
                                        onSelect={() => setSelected(entry.id)}
                                    />
                                );
                            })}
                        </SortableList>
                    </ul>

                    {chosen === undefined ? null : (
                        <GalleryPhotoControls
                            key={chosen.id}
                            entry={chosen}
                            index={current}
                            count={order.length}
                            error={errors[`gallery.${current}.alt`]}
                            onMove={(from, to) => {
                                setSelected(chosen.id);
                                gallery.move(from, to);
                            }}
                            onMakeMain={() => {
                                setSelected(chosen.id);
                                gallery.makeMain(chosen.id);
                            }}
                            onDescribe={(alt) => gallery.describe(chosen.id, alt)}
                            onRemove={() => setRemoving(chosen.id)}
                        />
                    )}
                </>
            )}

            <p className="text-ink-3 text-xs">
                Toca una foto para ordenarla, hacerla principal o describirla. Mientras aparezca «Procesando» se sirve la original, así que tu ficha
                funciona igual.
            </p>
            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                title="¿Quitar esta imagen?"
                description="Se borra del producto y de sus catálogos. Si es la única, el producto se queda sin foto."
                confirmLabel="Quitar imagen"
                url={removing !== null ? `${url}/${removing}` : ''}
            />
        </SectionCard>
    );
}
