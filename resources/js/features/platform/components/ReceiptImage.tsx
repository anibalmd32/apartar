import { cn } from '@/lib/utils';
import { useState } from 'react';

interface ReceiptImageProps {
    src: string;
    alt: string;
    onError: () => void;
}

export function ReceiptImage({ src, alt, onError }: ReceiptImageProps) {
    const [loaded, setLoaded] = useState(false);

    return (
        <div className="relative flex aspect-[3/4] w-full max-w-[360px] items-center justify-center lg:max-h-[520px]">
            {loaded ? null : (
                <div
                    role="status"
                    aria-label="Cargando el comprobante"
                    className="bg-base-100/70 border-line absolute inset-0 animate-pulse rounded-xl border motion-reduce:animate-none"
                />
            )}
            <img
                src={src}
                alt={alt}
                onLoad={() => setLoaded(true)}
                onError={onError}
                className={cn('shadow-pop max-h-full max-w-full rounded-xl object-contain transition-opacity', loaded ? 'opacity-100' : 'opacity-0')}
            />
        </div>
    );
}
