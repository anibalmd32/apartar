import { BrandMark } from '@/components/shell/brand';

export function PushPreview({ businessName, title, body }: { businessName: string; title: string; body: string }) {
    return (
        <section aria-label="Vista previa" className="bg-neutral text-neutral-content shadow-card flex flex-col gap-3 rounded-2xl p-5">
            <h2 className="text-xs font-bold opacity-70">Vista previa</h2>
            <div className="bg-base-100/95 text-base-content flex gap-3 rounded-[18px] p-3.5">
                <BrandMark className="size-9 rounded-[10px] text-[22px]" />
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <p className="text-ink-2 flex justify-between gap-2 text-xs">
                        <span className="truncate">{businessName} · Apartar</span>
                        <span className="text-ink-3 shrink-0">ahora</span>
                    </p>
                    <p className="truncate text-sm font-bold">{title.trim() === '' ? 'El título de tu aviso' : title}</p>
                    <p className="text-ink-2 line-clamp-3 text-[13px] leading-snug">{body.trim() === '' ? 'Y aquí, el texto.' : body}</p>
                </div>
            </div>
        </section>
    );
}
