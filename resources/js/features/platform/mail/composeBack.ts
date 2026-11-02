import { pf } from '@/lib/surfaces';
import { MAIL_TABS, isCurrentMailTab } from './MailTabs';

export interface ComposeBack {
    href: string;
    label: string;
}

export function composeBack(url: string, threadId: number | null): ComposeBack {
    if (threadId !== null) {
        return { href: pf(`/correos/bandeja/${threadId}`), label: 'Conversación' };
    }

    const from = new URLSearchParams(url.split('?')[1] ?? '').get('volver');

    if (from !== null && (from === pf('/correos') || from.startsWith(`${pf('/correos')}/`) || from.startsWith(`${pf('/correos')}?`))) {
        const tab = MAIL_TABS.find((candidate) => isCurrentMailTab(candidate.href, candidate.exact, from));

        return { href: from, label: tab?.label ?? 'Correos' };
    }

    return { href: pf('/correos/bandeja'), label: 'Bandeja' };
}
