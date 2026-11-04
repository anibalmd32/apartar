import { BackToTop } from '@/features/marketing/components/BackToTop';
import { LegalContents } from '@/features/marketing/components/LegalContents';
import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
import { type LegalProps } from '@/types/marketing';
import { Link } from '@inertiajs/react';

const DOCUMENTS = [
    { key: 'terms', label: 'Términos', route: 'legal.terms' },
    { key: 'privacy', label: 'Privacidad', route: 'legal.privacy' },
] as const;

const PROSE = cn(
    'text-pz-ink-2 flex max-w-[70ch] flex-col gap-4 text-[1.0625rem] leading-[1.7]',
    '[&_h2]:text-pz-ink [&_h2]:mt-6 [&_h2]:scroll-mt-6 [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:[font-stretch:85%]',
    '[&_ol]:flex [&_ol]:list-decimal [&_ol]:flex-col [&_ol]:gap-2 [&_ol]:pl-5',
    '[&_ul]:flex [&_ul]:list-disc [&_ul]:flex-col [&_ul]:gap-2 [&_ul]:pl-5',
    '[&_strong]:text-pz-ink [&_a]:text-pz-blue [&_a]:underline [&_code]:text-[0.875rem]',
    '[&_td]:border-pz-rule [&_table]:w-full [&_table]:text-left [&_table]:text-sm [&_td]:border-t [&_td]:py-2 [&_td]:pr-3 [&_th]:py-2 [&_th]:pr-3',
);

export default function Legal({ document }: LegalProps) {
    return (
        <MarketingLayout title={document.title}>
            <article className="mx-auto flex max-w-[880px] flex-col gap-[18px] px-4 pt-6 pb-16 lg:px-6 lg:pt-12 lg:pb-20">
                <nav aria-label="Documentos legales" className="flex gap-2 self-start">
                    {DOCUMENTS.map((item) => {
                        const current = item.key === document.key;

                        return (
                            <Link
                                key={item.key}
                                href={route(item.route)}
                                aria-current={current ? 'page' : undefined}
                                className={cn(
                                    'touch:min-h-11 flex min-h-10 items-center rounded-full px-4 text-[0.9375rem] font-bold',
                                    current ? 'bg-pz-ink text-pz-board' : 'text-pz-ink hover:bg-pz-ghost-ink shadow-[inset_0_0_0_2px_var(--pz-ink)]',
                                )}
                            >
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>

                <header className="flex flex-col gap-2">
                    <h1 className="font-vinyl text-[2.75rem] sm:text-6xl">{document.title}</h1>
                    <p className="font-hand text-pz-ink-3 text-xl">Última actualización: {document.updatedOn}</p>
                </header>

                <LegalContents sections={document.sections} />

                <div className={PROSE} dangerouslySetInnerHTML={{ __html: document.html }} />

                <BackToTop />
            </article>
        </MarketingLayout>
    );
}
