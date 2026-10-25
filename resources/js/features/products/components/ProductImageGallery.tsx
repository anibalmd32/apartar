import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { TextField } from '@/components/ui/text-field';
import { ImageUploader } from '@/features/products/components/ImageUploader';
import { type ProductImageView } from '@/types/product';
import { cilTrash } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface ProductImageGalleryProps {
    productId: number;
    images: readonly ProductImageView[];
    maxImages: number;
    errors: Partial<Record<string, string>>;
}

interface EditableImage {
    [key: string]: number | string;
    id: number;
    alt: string;
}

export function ProductImageGallery({ productId, images, maxImages, errors }: ProductImageGalleryProps) {
    const url = `/backoffice/productos/${productId}/imagenes`;

    const [order, setOrder] = useState<EditableImage[]>(() => images.map((image) => ({ id: image.id, alt: image.set.alt })));

    useEffect(() => setOrder(images.map((image) => ({ id: image.id, alt: image.set.alt }))), [images]);

    const byId = new Map(images.map((image) => [image.id, image]));

    const move = (from: number, to: number) => {
        setOrder((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);

            if (moved === undefined) {
                return current;
            }

            next.splice(to, 0, moved);

            return next;
        });
    };

    const describe = (id: number, alt: string) => {
        setOrder((current) => current.map((image) => (image.id === id ? { ...image, alt } : image)));
    };

    const save = () => router.patch(`${url}/orden`, { images: order }, { preserveScroll: true });

    const remove = (id: number) => router.delete(`${url}/${id}`, { preserveScroll: true });

    return (
        <SectionCard
            title="Imágenes"
            subtitle="La primera es la principal. Arrastra para ordenar."
            action={
                <Button type="button" size="sm" variant="soft" onClick={save} disabled={order.length === 0}>
                    Guardar galería
                </Button>
            }
        >
            <ImageUploader url={url} remaining={maxImages - images.length} error={errors.images} />

            {order.length === 0 ? (
                <p className="text-ink-3 text-sm">
                    Todavía no tiene fotos. La primera que subas será la que vean tus clientes en el catálogo y en el enlace que compartas.
                </p>
            ) : (
                <ul className="flex flex-col gap-3">
                    <SortableList
                        ids={order.map((entry) => entry.id)}
                        onReorder={move}
                        labelOf={(id) => `la imagen ${order.findIndex((entry) => entry.id === id) + 1}`}
                    >
                        {order.map((entry, index) => {
                            const image = byId.get(entry.id);

                            if (image === undefined) {
                                return null;
                            }

                            return (
                                <SortableRow key={entry.id} id={entry.id} label={`la imagen ${index + 1}`}>
                                    {({ containerProps, handleProps }) => (
                                        <li
                                            ref={containerProps.ref}
                                            style={containerProps.style}
                                            className={`border-line grid gap-x-3 gap-y-2 border-b pb-3 last:border-0 sm:grid-cols-12 ${containerProps.className ?? ''}`}
                                        >
                                            <div className="flex items-center gap-1 sm:col-span-2">
                                                <SortableHandle handleProps={handleProps} />
                                                <img
                                                    src={image.set.thumb}
                                                    alt={image.set.alt}
                                                    loading="lazy"
                                                    className="size-24 rounded-xl object-cover"
                                                />
                                            </div>

                                            <div className="sm:col-span-6">
                                                <TextField
                                                    label="Texto alternativo"
                                                    name={`images[${index}][alt]`}
                                                    value={entry.alt}
                                                    onChange={(event) => describe(entry.id, event.target.value)}
                                                    hint="Lo que lee quien no ve la imagen. Si lo dejas vacío, volvemos al texto por defecto."
                                                    maxLength={255}
                                                    autoComplete="off"
                                                />
                                            </div>

                                            <div className="flex flex-wrap items-end gap-1 sm:col-span-4">
                                                {index === 0 ? <Badge tone="primary">Principal</Badge> : null}

                                                {image.isProcessing ? <Badge tone="info">Procesando</Badge> : null}

                                                {image.needsReview ? <Badge tone="warning">Revisar</Badge> : null}

                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => remove(entry.id)}
                                                    aria-label={`Quitar la imagen ${index + 1}`}
                                                    className="btn-square text-ink-3 hover:text-error"
                                                >
                                                    <Icon icon={cilTrash} />
                                                </Button>
                                            </div>
                                        </li>
                                    )}
                                </SortableRow>
                            );
                        })}
                    </SortableList>
                </ul>
            )}

            <p className="text-ink-3 text-xs">
                La primera es la que se ve en el catálogo y en el enlace que compartes. Mientras aparezca «Procesando» se sirve la original, así que
                tu ficha funciona igual.
            </p>
        </SectionCard>
    );
}
