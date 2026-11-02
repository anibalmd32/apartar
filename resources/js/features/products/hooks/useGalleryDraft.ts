import { moved } from '@/lib/reorder';
import { type ProductImageView } from '@/types/product';
import { useEffect, useState } from 'react';

export interface GalleryEntry {
    [key: string]: number | string;
    id: number;
    alt: string;
}

function entriesOf(images: readonly ProductImageView[]): GalleryEntry[] {
    return images.map((image) => ({ id: image.id, alt: image.set.alt }));
}

export function useGalleryDraft(images: readonly ProductImageView[]) {
    const saved = JSON.stringify(entriesOf(images));

    const [order, setOrder] = useState<GalleryEntry[]>(() => entriesOf(images));

    useEffect(() => setOrder(JSON.parse(saved) as GalleryEntry[]), [saved]);

    const dirty = JSON.stringify(order) !== saved;

    const move = (from: number, to: number) => setOrder((current) => moved(current, from, to));

    const makeMain = (id: number) =>
        setOrder((current) =>
            moved(
                current,
                current.findIndex((entry) => entry.id === id),
                0,
            ),
        );

    const describe = (id: number, alt: string) => setOrder((current) => current.map((entry) => (entry.id === id ? { ...entry, alt } : entry)));

    return { order, dirty, move, makeMain, describe };
}

export type GalleryDraft = ReturnType<typeof useGalleryDraft>;
