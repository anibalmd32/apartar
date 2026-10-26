import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
import { type LegalProps } from '@/types/marketing';
import { Link } from '@inertiajs/react';

const DOCUMENTS = [
    { key: 'terms', label: 'Términos', route: 'legal.terms' },
    { key: 'privacy', label: 'Privacidad', route: 'legal.privacy' },
] as const;

const PROSE = cn(
    'text-ink-2 flex flex-col gap-4 text-[15px] leading-[1.7]',
    '[&_h2]:text-base-content [&_h2]:mt-4 [&_h2]:text-xl [&_h2]:font-bold',
    '[&_ol]:flex [&_ol]:list-decimal [&_ol]:flex-col [&_ol]:gap-2 [&_ol]:pl-5',
    '[&_ul]:flex [&_ul]:list-disc [&_ul]:flex-col [&_ul]:gap-2 [&_ul]:pl-5',
    '[&_strong]:text-base-content [&_a]:text-primary [&_a]:underline [&_code]:text-[13px]',
    '[&_td]:border-base-300 [&_table]:w-full [&_table]:text-left [&_table]:text-sm [&_td]:border-t [&_td]:py-2 [&_td]:pr-3 [&_th]:py-2 [&_th]:pr-3',
);

export default function Legal({ document }: LegalProps) {
    return (
        <MarketingLayout title={document.title}>
            <article className="mx-auto flex max-w-[880px] flex-col gap-[18px] px-4 pt-6 pb-16 lg:px-6 lg:pt-12 lg:pb-20">
                <nav aria-label="Documentos legales" className="bg-surface-2 flex gap-0.5 self-start rounded-[10px] p-[3px]">
                    {DOCUMENTS.map((item) => {
                        const current = item.key === document.key;

                        return (
                            <Link
                                key={item.key}
                                href={route(item.route)}
                                aria-current={current ? 'page' : undefined}
                                className={cn(
                                    'rounded-lg px-3.5 py-2 text-[13px]',
                                    current ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
                                )}
                            >
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>

                <header className="flex flex-col gap-2">
                    <h1 className="text-[32px] leading-tight font-extrabold tracking-[-0.04em] lg:text-[44px]">{document.title}</h1>
                    <p className="text-ink-3 text-sm">Última actualización: {document.updatedOn}</p>
                </header>

                <div className={PROSE} dangerouslySetInnerHTML={{ __html: document.html }} />
            </article>
        </MarketingLayout>
    );
}
