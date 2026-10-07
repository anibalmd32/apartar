import { useEffect, useState } from 'react';

/**
 * La vista previa local del comprobante elegido (`REQ-PAY-001`, paso 2 del
 * happy path: «adjunta la captura y ve la vista previa»).
 *
 * Una imagen se enseña con `URL.createObjectURL()` sobre el archivo que ya está
 * en el navegador, como en `ImageUploader`: no hay que subir nada para ver si se
 * eligió la captura correcta. Un PDF no se pinta: se dice su nombre y su peso,
 * que es lo que permite reconocerlo.
 *
 * Cada `objectURL` se revoca al cambiar de archivo y al desmontar; sin eso,
 * cada intento deja el archivo entero retenido en memoria.
 *
 * La URL se devuelve **solo si es del archivo actual**: entre que cambia el
 * archivo y corre el efecto hay un render en el que la anterior ya se va a
 * revocar, y enseñarla pintaría una imagen rota.
 */

export type FilePreview =
    | { readonly kind: 'image'; readonly name: string; readonly size: string; readonly url: string | null }
    | { readonly kind: 'pdf' | 'other'; readonly name: string; readonly size: string };

const BYTES_PER_KB = 1024;
/** El mega de `maxMb`, el mismo con el que mide el servidor. */
export const BYTES_PER_MB = 1024 * 1024;

const sizeFormat = new Intl.NumberFormat('es-VE', { maximumFractionDigits: 1 });

/** El peso de un archivo para leerlo: `350 KB`, `1,5 MB`. */
export function formatFileSize(bytes: number): string {
    const kilobytes = Math.max(1, Math.round(bytes / BYTES_PER_KB));

    // Por debajo del mega pero redondeado a 1024 KB se lee mejor como «1 MB».
    if (kilobytes < 1024) {
        return `${kilobytes} KB`;
    }

    return `${sizeFormat.format(bytes / BYTES_PER_MB)} MB`;
}

/**
 * Por su tipo y, si el navegador no lo da —pasa en algunos Android y Windows—,
 * por su extensión: una captura sin miniatura no deja comprobar que es la buena.
 */
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
        // Sin imagen no hay URL que crear; la anterior la revocó su limpieza.
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

    // Solo la URL de este archivo: la de otro anterior ya está revocada.
    return { kind, name: file.name, size, url: image !== null && image.file === file ? image.url : null };
}
