import { Icon } from '@/components/ui/icon';
import { SelectField } from '@/components/ui/select-field';
import { cilPlus, cilX } from '@coreui/icons';
import { type KeyboardEvent, type ReactNode, useId, useState } from 'react';

const MIN_OFFSET = -30;
const MAX_OFFSET = 90;
const MAX_REMINDERS = 10;

type Moment = 'before' | 'on' | 'after';

export function offsetLabel(offset: number): string {
    if (offset === 0) {
        return 'El día';
    }

    const days = Math.abs(offset);

    return `${days} ${days === 1 ? 'día' : 'días'} ${offset < 0 ? 'antes' : 'después'}`;
}

interface ReminderOffsetsEditorProps {
    label: string;
    value: readonly number[];
    onChange: (offsets: number[]) => void;
    hint?: ReactNode;
    error?: string;
}

export function ReminderOffsetsEditor({ label, value, onChange, hint, error }: ReminderOffsetsEditorProps) {
    const id = useId();
    const [adding, setAdding] = useState(false);
    const [moment, setMoment] = useState<Moment>('before');
    const [days, setDays] = useState('1');

    const unit = days === '1' ? 'día' : 'días';
    const candidate = moment === 'on' ? 0 : (moment === 'before' ? -1 : 1) * Math.trunc(Number(days));
    const valid = Number.isInteger(candidate) && candidate >= MIN_OFFSET && candidate <= MAX_OFFSET && !value.includes(candidate);

    const add = () => {
        if (!valid) {
            return;
        }

        onChange([...value, candidate].sort((a, b) => a - b));
        setAdding(false);
    };

    const onEnter = (event: KeyboardEvent) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            add();
        }
    };

    return (
        <fieldset
            className="fieldset w-full"
            aria-describedby={[hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined}
        >
            <legend className="fieldset-legend">{label}</legend>

            <ul className="flex flex-wrap items-center gap-1.5">
                {value.map((offset) => (
                    <li
                        key={offset}
                        className="bg-primary-soft text-primary-ink inline-flex items-center gap-1.5 rounded-full py-[3px] pr-1.5 pl-2.5 text-xs font-semibold"
                    >
                        {offsetLabel(offset)}
                        <button
                            type="button"
                            className="hover:bg-base-100/60 cursor-pointer rounded-full p-0.5"
                            onClick={() => onChange(value.filter((other) => other !== offset))}
                            aria-label={`Quitar ${offsetLabel(offset).toLowerCase()}`}
                        >
                            <Icon icon={cilX} size={10} />
                        </button>
                    </li>
                ))}

                {!adding && value.length < MAX_REMINDERS ? (
                    <li>
                        <button
                            type="button"
                            className="bg-base-300 text-ink-2 hover:text-base-content inline-flex cursor-pointer items-center gap-1 rounded-full px-2.5 py-[3px] text-xs font-semibold"
                            onClick={() => setAdding(true)}
                        >
                            <Icon icon={cilPlus} size={10} />
                            Agregar
                        </button>
                    </li>
                ) : null}
            </ul>

            {adding ? (
                <div className="mt-1 flex flex-wrap items-center gap-2" onKeyDown={onEnter}>
                    {moment === 'on' ? null : (
                        <input
                            type="number"
                            min={1}
                            max={moment === 'before' ? -MIN_OFFSET : MAX_OFFSET}
                            className="input input-sm w-20"
                            value={days}
                            onChange={(event) => setDays(event.target.value)}
                            aria-label="Días"
                        />
                    )}
                    <SelectField
                        label="Cuándo"
                        hideLabel
                        inline
                        className="select-sm"
                        value={moment}
                        onChange={(event) => setMoment(event.target.value as Moment)}
                        options={[
                            { value: 'before', label: `${unit} antes del vencimiento` },
                            { value: 'on', label: 'el día del vencimiento' },
                            { value: 'after', label: `${unit} después del vencimiento` },
                        ]}
                    />
                    <button type="button" className="btn btn-secondary btn-sm" onClick={add} disabled={!valid}>
                        Añadir
                    </button>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => setAdding(false)}>
                        Cancelar
                    </button>
                </div>
            ) : null}

            {hint ? (
                <p id={`${id}-hint`} className="label text-xs">
                    {hint}
                </p>
            ) : null}
            {error ? (
                <p id={`${id}-error`} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
