import { Icon } from '@/components/ui/icon';
import { type LegalSection } from '@/types/marketing';
import { cilMinus, cilPlus } from '@coreui/icons';

export function LegalContents({ sections }: { sections: readonly LegalSection[] }) {
    if (sections.length === 0) {
        return null;
    }

    return (
        <details className="group border-line bg-base-100 rounded-2xl border lg:open:pb-2">
            <summary className="flex min-h-12 cursor-pointer list-none items-center gap-3 px-4 text-[15px] font-bold [&::-webkit-details-marker]:hidden">
                <span className="flex-1">Contenido</span>
                <span className="text-ink-3 text-[13px] font-medium">{sections.length} secciones</span>
                <Icon icon={cilPlus} size={16} className="text-ink-3 group-open:hidden" />
                <Icon icon={cilMinus} size={16} className="hidden group-open:block" />
            </summary>
            <nav aria-label="Contenido del documento" className="border-line border-t px-2 py-1.5">
                <ol className="flex flex-col">
                    {sections.map((section) => (
                        <li key={section.id}>
                            <a
                                href={`#${section.id}`}
                                className="text-ink-2 hover:bg-surface-2 hover:text-base-content flex min-h-11 items-center rounded-lg px-2 text-sm"
                            >
                                {section.title}
                            </a>
                        </li>
                    ))}
                </ol>
            </nav>
        </details>
    );
}
