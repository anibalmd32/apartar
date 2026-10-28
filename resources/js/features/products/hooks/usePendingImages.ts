import { useEffect, useRef, useState } from 'react';

export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const;

export const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

export interface PendingImage {
    readonly key: string;
    readonly file: File;
    readonly src: string;
}

function rejectionFor(skippedType: number, skippedSize: number, skippedRoom: number, max: number): string | null {
    const reasons: string[] = [];

    if (skippedType > 0) {
        reasons.push('Solo se admiten fotos JPG, PNG o WebP.');
    }

    if (skippedSize > 0) {
        reasons.push('Cada imagen puede pesar hasta 8 MB.');
    }

    if (skippedRoom > 0) {
        reasons.push(`Un producto admite ${max} imágenes.`);
    }

    return reasons.length === 0 ? null : reasons.join(' ');
}

/**
 * Photos picked before the product exists: kept in memory with previews until the form is sent.
 */
export function usePendingImages(max: number) {
    const [images, setImages] = useState<PendingImage[]>([]);
    const [error, setError] = useState<string | null>(null);
    const counter = useRef(0);
    const latest = useRef<PendingImage[]>([]);

    useEffect(() => {
        latest.current = images;
    }, [images]);

    useEffect(() => () => latest.current.forEach((image) => URL.revokeObjectURL(image.src)), []);

    const add = (files: readonly File[]) => {
        const valid = files.filter((file) => (IMAGE_TYPES as readonly string[]).includes(file.type) && file.size <= MAX_IMAGE_BYTES);
        const skippedType = files.filter((file) => !(IMAGE_TYPES as readonly string[]).includes(file.type)).length;
        const skippedSize = files.filter((file) => (IMAGE_TYPES as readonly string[]).includes(file.type) && file.size > MAX_IMAGE_BYTES).length;
        const accepted = valid.slice(0, Math.max(0, max - images.length));

        setError(rejectionFor(skippedType, skippedSize, valid.length - accepted.length, max));

        if (accepted.length === 0) {
            return;
        }

        const added = accepted.map((file) => {
            counter.current += 1;

            return { key: `pending-${counter.current}`, file, src: URL.createObjectURL(file) };
        });

        setImages((current) => [...current, ...added]);
    };

    const remove = (key: string) => {
        setImages((current) => {
            const removed = current.find((image) => image.key === key);

            if (removed !== undefined) {
                URL.revokeObjectURL(removed.src);
            }

            return current.filter((image) => image.key !== key);
        });
        setError(null);
    };

    return { images, files: images.map((image) => image.file), error, max, add, remove };
}

export type PendingImages = ReturnType<typeof usePendingImages>;
