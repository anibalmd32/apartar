import { BrandMark } from '@/components/shell/brand';

export function PlatformBadge() {
    return (
        <a href="/registro" className="text-ink-3 hover:text-base-content inline-flex items-center gap-1.5 text-xs">
            <span className="sr-only">Hecho con Apartar · crea tu catálogo gratis</span>
            <span aria-hidden="true">Hecho con</span>
            <span aria-hidden="true" className="text-base-content inline-flex items-center gap-1.5 text-[13px] font-extrabold tracking-[-0.3px]">
                <BrandMark className="size-[18px] rounded-[5px] text-[11px]" />
                apartar
            </span>
        </a>
    );
}
