export interface MailCounts {
    readonly sent: number;
    readonly delivered: number;
    readonly opened: number;
    readonly clicked: number;
    readonly bounced: number;
}

export interface MailMetrics {
    readonly from: string;
    readonly to: string;
    readonly totals: {
        readonly sent: number;
        readonly pending: number;
        readonly failed: number;
        readonly manual: number;
        readonly delivered: number;
        readonly openedUnique: number;
        readonly openedTotal: number;
        readonly clickedUnique: number;
        readonly clickedTotal: number;
        readonly bounced: number;
        readonly softBounced: number;
        readonly spam: number;
        readonly unsubscribed: number;
        readonly rejected: number;
        readonly inbound: number;
    };
    readonly rates: Readonly<Record<'delivery' | 'open' | 'click' | 'clickToOpen' | 'bounce' | 'spam' | 'unsubscribe' | 'failure', number | null>>;
    readonly avgMinutesToOpen: number | null;
    readonly daily: readonly (MailCounts & { readonly date: string })[];
    readonly byCategory: readonly (MailCounts & { readonly category: string; readonly label: string })[];
    readonly byProvider: readonly (MailCounts & { readonly provider: string })[];
    readonly topLinks: readonly { readonly url: string; readonly clicks: number; readonly mails: number }[];
    readonly bounceReasons: readonly { readonly reason: string; readonly hard: boolean; readonly count: number }[];
}

export interface MailSummary {
    readonly sent: number;
    readonly deliveryRate: number | null;
    readonly openRate: number | null;
    readonly bounceRate: number | null;
    readonly unreadThreads: number;
}

export interface SentMailRow {
    readonly uuid: string;
    readonly to: string;
    readonly otherRecipients: number;
    readonly subject: string;
    readonly category: string;
    readonly manual: boolean;
    readonly status: string;
    readonly statusLabel: string;
    readonly problem: boolean;
    readonly opens: number;
    readonly clicks: number;
    readonly createdAt: string;
}

export interface SentMailEventRow {
    readonly id: number;
    readonly type: string;
    readonly label: string;
    readonly occurredAt: string;
    readonly email: string;
    readonly url: string | null;
    readonly reason: string | null;
    readonly responseCode: number | null;
    readonly bounceCategory: string | null;
    readonly ip: string | null;
    readonly userAgent: string | null;
}

export interface SentMailDetail {
    readonly uuid: string;
    readonly subject: string;
    readonly from: string;
    readonly to: readonly string[];
    readonly cc: readonly string[];
    readonly bcc: readonly string[];
    readonly category: string;
    readonly status: string;
    readonly statusLabel: string;
    readonly problem: boolean;
    readonly html: string | null;
    readonly text: string | null;
    readonly headers: Readonly<Record<string, string>>;
    readonly messageId: string | null;
    readonly mailtrapMessageId: string | null;
    readonly opens: number;
    readonly clicks: number;
    readonly createdAt: string;
    readonly sentAt: string | null;
    readonly operator: string | null;
    readonly threadId: number | null;
    readonly organization: { readonly name: string; readonly slug: string } | null;
    readonly events: readonly SentMailEventRow[];
}

export interface MailThreadRow {
    readonly id: number;
    readonly subject: string;
    readonly counterpart: string;
    readonly snippet: string;
    readonly messages: number;
    readonly unread: number;
    readonly lastMessageAt: string;
}

export interface MailAttachmentRow {
    readonly id: number;
    readonly filename: string;
    readonly contentType: string;
    readonly size: number | null;
    readonly stored: boolean;
}

interface ConversationBase {
    readonly key: string;
    readonly from: string;
    readonly to: readonly string[];
    readonly cc: readonly string[];
    readonly subject: string;
    readonly html: string | null;
    readonly text: string | null;
    readonly at: string;
}

export interface InboundMessage extends ConversationBase {
    readonly kind: 'inbound';
    readonly id: number;
    readonly fromAddress: string;
    readonly unread: boolean;
    readonly attachments: readonly MailAttachmentRow[];
}

export interface OutboundMessage extends ConversationBase {
    readonly kind: 'sent';
    readonly uuid: string;
    readonly status: string;
    readonly operator: string | null;
}

export type ConversationMessage = InboundMessage | OutboundMessage;

export interface ComposeSource {
    readonly id: number;
    readonly threadId: number;
    readonly from: string;
    readonly subject: string;
    readonly receivedAt: string;
    readonly excerpt: string;
    readonly attachments: readonly string[];
}
