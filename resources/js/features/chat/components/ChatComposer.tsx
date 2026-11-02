import { Icon } from '@/components/ui/icon';
import { typesOnTouch } from '@/features/chat/lib/touch-typing';
import { cn } from '@/lib/utils';
import { cilSend } from '@coreui/icons';
import { useLayoutEffect, useRef, useState, type FormEvent } from 'react';

export const MAX_MESSAGE_LENGTH = 2000;

const COUNTER_FROM = 200;

interface ChatComposerProps {
    placeholder?: string;
    onSend: (body: string) => void;
    onType: () => void;
}

export function ChatComposer({ placeholder, onSend, onType }: ChatComposerProps) {
    const [draft, setDraft] = useState('');
    const [touch] = useState(typesOnTouch);
    const field = useRef<HTMLTextAreaElement>(null);
    const remaining = MAX_MESSAGE_LENGTH - draft.length;
    const blocked = draft.trim() === '' || remaining < 0;

    useLayoutEffect(() => {
        const element = field.current;

        if (element === null) {
            return;
        }

        element.style.height = 'auto';

        if (element.scrollHeight > 0) {
            element.style.height = `${element.scrollHeight}px`;
        }
    }, [draft]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (blocked) {
            return;
        }

        onSend(draft);
        setDraft('');
    };

    return (
        <form
            className="border-line bg-base-100 flex items-end gap-2 border-t px-3 pt-2.5 pb-[max(env(safe-area-inset-bottom),0.625rem)] lg:px-5 lg:py-3"
            onSubmit={submit}
        >
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <textarea
                    ref={field}
                    className="bg-surface-2 placeholder:text-ink-3 focus:outline-primary block max-h-40 min-h-11 w-full resize-none overflow-y-auto rounded-[22px] px-4 py-[11px] text-base leading-[22px] focus:outline-2 lg:text-[15px]"
                    aria-label="Mensaje"
                    rows={1}
                    value={draft}
                    placeholder={placeholder ?? 'Escribe tu mensaje'}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        onType();
                    }}
                    onKeyDown={(event) => {
                        if (!touch && event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
                            event.preventDefault();
                            event.currentTarget.form?.requestSubmit();
                        }
                    }}
                    enterKeyHint={touch ? 'enter' : 'send'}
                />
                {remaining < COUNTER_FROM ? (
                    <span className={cn('px-4 text-xs', remaining < 0 ? 'text-error' : 'text-ink-3')}>{remaining}</span>
                ) : null}
            </div>
            <button type="submit" className="btn btn-primary btn-circle size-11 shrink-0" disabled={blocked} aria-label="Enviar">
                <Icon icon={cilSend} size={18} />
            </button>
        </form>
    );
}
