export const MAIL_ACTIVITY_CHANNEL = 'platform.mail';

export const MAIL_ACTIVITY_EVENT = 'MailActivity';

export type MailActivityKind = 'sent' | 'event' | 'inbound';

export interface MailActivityEvent {
    readonly kind: MailActivityKind;
    readonly mails: readonly string[];
    readonly threads: readonly number[];
}

export interface MailActivityPage {
    readonly component: string;
    readonly props: Record<string, unknown>;
}

const BADGE_KINDS: readonly MailActivityKind[] = ['inbound', 'sent'];

function idOf(value: unknown, key: 'uuid' | 'id'): unknown {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>)[key] : undefined;
}

function pageProps(page: MailActivityPage, event: MailActivityEvent | null): readonly string[] {
    const kind = event?.kind ?? null;
    const outgoing = kind === null || kind === 'sent' || kind === 'event';
    const conversational = kind === null || kind === 'inbound' || kind === 'sent';

    switch (page.component) {
        case 'platform/Dashboard':
            return ['mail'];
        case 'platform/mail/Metrics':
            return ['metrics'];
        case 'platform/mail/Sent':
            return outgoing ? ['mails', 'hasMore'] : [];
        case 'platform/mail/SentShow': {
            const uuid = idOf(page.props.mail, 'uuid');

            return outgoing && (event === null || (typeof uuid === 'string' && event.mails.includes(uuid))) ? ['mail'] : [];
        }
        case 'platform/mail/Inbox':
            return conversational ? ['threads', 'hasMore', 'unreadThreads'] : [];
        case 'platform/mail/Thread': {
            const id = idOf(page.props.thread, 'id');

            return conversational && (event === null || (typeof id === 'number' && event.threads.includes(id))) ? ['thread', 'messages'] : [];
        }
        default:
            return [];
    }
}

export function propsToReload(page: MailActivityPage, event: MailActivityEvent | null): readonly string[] {
    const badge = event === null || BADGE_KINDS.includes(event.kind) ? ['platformOperator'] : [];

    return [...pageProps(page, event), ...badge];
}
