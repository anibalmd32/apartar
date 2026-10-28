import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilImage } from '@coreui/icons';
import { useState } from 'react';

const STYLE =
    'body{margin:0;padding:16px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;line-height:1.5;color:#1f2937;background:#fff;word-break:break-word}img{max-width:100%;height:auto}';

export function frameDocument(html: string, images: boolean): string {
    const policy = `default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src ${images ? 'https: data:' : 'data:'}`;

    return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${policy}"><base target="_blank"><style>${STYLE}</style></head><body>${html}</body></html>`;
}

export function hasRemoteImages(html: string): boolean {
    return /<img[^>]+src=["']?https?:/i.test(html) || /url\(\s*["']?https?:/i.test(html);
}

interface MailFrameProps {
    html: string | null;
    text: string | null;
    title: string;
    className?: string;
}

export function MailFrame({ html, text, title, className }: MailFrameProps) {
    const [images, setImages] = useState(false);

    if (html === null || html.trim() === '') {
        return (
            <pre className={cn('bg-surface-2 rounded-xl p-4 text-[13px] break-words whitespace-pre-wrap', className)}>{text ?? 'Sin contenido.'}</pre>
        );
    }

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            {!images && hasRemoteImages(html) ? (
                <p className="text-ink-3 flex flex-wrap items-center gap-2 text-xs">
                    <Icon icon={cilImage} size={14} />
                    Las imágenes externas están bloqueadas para no avisar al remitente.
                    <button type="button" className="link link-primary" onClick={() => setImages(true)}>
                        Mostrar imágenes
                    </button>
                </p>
            ) : null}
            <iframe
                title={title}
                sandbox="allow-popups allow-popups-to-escape-sandbox"
                referrerPolicy="no-referrer"
                srcDoc={frameDocument(html, images)}
                className="border-line h-[60vh] min-h-[360px] w-full rounded-xl border bg-white"
            />
        </div>
    );
}
