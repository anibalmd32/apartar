import { dayKey } from '@/features/chat/lib/day-label';
import { type ChatMessage } from '@/types/chat';

export interface MessagePlacement {
    readonly newDay: boolean;
    readonly startsGroup: boolean;
    readonly endsGroup: boolean;
}

const GROUP_WINDOW_MS = 10 * 60 * 1000;

function together(previous: ChatMessage | undefined, next: ChatMessage | undefined): boolean {
    if (previous === undefined || next === undefined) {
        return false;
    }

    return (
        previous.sender === next.sender &&
        previous.senderName === next.senderName &&
        dayKey(previous.sentAt) === dayKey(next.sentAt) &&
        Date.parse(next.sentAt) - Date.parse(previous.sentAt) <= GROUP_WINDOW_MS
    );
}

export function placeMessages(messages: readonly ChatMessage[]): MessagePlacement[] {
    return messages.map((message, index) => {
        const previous = messages[index - 1];

        return {
            newDay: previous === undefined || dayKey(previous.sentAt) !== dayKey(message.sentAt),
            startsGroup: !together(previous, message),
            endsGroup: !together(message, messages[index + 1]),
        };
    });
}
