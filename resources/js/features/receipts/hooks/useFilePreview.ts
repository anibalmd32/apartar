import { useEffect, useState } from 'react';

export type FilePreview =
    | { readonly kind: 'image'; readonly name: string; readonly size: string; readonly url: string | null }
    | { readonly kind: 'pdf' | 'other'; readonly name: string; readonly size: string };

const BYTES_PER_KB = 1024;
export const BYTES_PER_MB = 1024 * 1024;

const sizeFormat = new Intl.NumberFormat('es-VE', { maximumFractionDigits: 1 });

export function formatFileSize(bytes: number): string {
    const kilobytes = Math.max(1, Math.round(bytes / BYTES_PER_KB));

    if (kilobytes < 1024) {
        return `${kilobytes} KB`;
    }

    return `${sizeFormat.format(bytes / BYTES_PER_MB)} MB`;
}

function kindOf(file: File): 'image' | 'pdf' | 'other' {
    const name = file.name.toLowerCase();

    if (file.type.startsWith('image/') || (file.type === '' && /\.(jpe?g|png|webp)$/.test(name))) {
        return 'image';
    }

    return file.type === 'application/pdf' || name.endsWith('.pdf') ? 'pdf' : 'other';
}

export function useFilePreview(file: File | null): FilePreview | null {
    const [image, setImage] = useState<{ file: File; url: string } | null>(null);

    useEffect(() => {
        if (file === null || kindOf(file) !== 'image') {
            return;
        }

        const url = URL.createObjectURL(file);
        setImage({ file, url });

        return () => URL.revokeObjectURL(url);
    }, [file]);

    if (file === null) {
        return null;
    }

    const size = formatFileSize(file.size);
    const kind = kindOf(file);

    if (kind !== 'image') {
        return { kind, name: file.name, size };
    }

    return { kind, name: file.name, size, url: image !== null && image.file === file ? image.url : null };
}
