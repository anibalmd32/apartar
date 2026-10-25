export type MessageSenderType = 'customer' | 'team';

export interface ChatMessage {
    readonly id: number;
    readonly conversationId: number;
    readonly sender: MessageSenderType;
    readonly senderName: string;
    readonly body: string;
    readonly sentAt: string;
    readonly readAt?: string | null;
}

export interface ChatThread {
    readonly channel: string | null;
    readonly messages: readonly ChatMessage[];
    readonly sendUrl: string;
    readonly messagesUrl: string;
    readonly typingUrl: string;
}

export interface PortalChatProps {
    readonly businessName: string;
    readonly thread: ChatThread;
}

export interface PortalChatIndexProps {
    readonly threads: readonly {
        readonly businessName: string;
        readonly url: string;
        readonly unread: number;
        readonly lastMessageAt: string | null;
    }[];
}

export interface ConversationRow {
    readonly id: number;
    readonly customerName: string;
    readonly customerDocument: string;
    readonly excerpt: string;
    readonly lastMessageAt: string | null;
    readonly unread: number;
    readonly url: string;
}

export interface ThreadContext {
    readonly customerName: string;
    readonly phone: string;
    readonly activeLayaways: number;
    readonly remaining: string;
    readonly overdueInstallments: number;
    readonly overdueAmount?: string | null;
    readonly nextDueDate?: string | null;
    readonly layaways?: readonly {
        readonly code: string;
        readonly productName: string;
        readonly status: string;
        readonly statusLabel: string;
        readonly url: string;
    }[];
    readonly profileUrl: string;
}

export interface BackofficeChatProps {
    readonly threads: readonly ConversationRow[];
    readonly nextPageUrl: string | null;
    readonly search: string;
    readonly unreadOnly?: boolean;
    readonly active: (ChatThread & { readonly id: number; readonly hiddenHistory: boolean; readonly context: ThreadContext }) | null;
    readonly historyDays: number | null;
}

export interface CatalogChatState {
    readonly canWrite: boolean;
    readonly sendUrl: string;
    readonly threadUrl: string;
}
