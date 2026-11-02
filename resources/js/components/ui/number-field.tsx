import { TextField } from '@/components/ui/text-field';
import { useNumberDraft } from '@/hooks/use-number-draft';
import { type ComponentProps } from 'react';

type TextFieldProps = ComponentProps<typeof TextField>;

interface NumberFieldProps extends Omit<TextFieldProps, 'value' | 'onChange' | 'onBlur' | 'type' | 'min' | 'max' | 'inputMode' | 'pattern'> {
    value: number | null;
    onChange: (value: number | null) => void;
    min?: number;
    max?: number;
}

export function NumberField({ value, onChange, min, max, ...props }: NumberFieldProps) {
    const draft = useNumberDraft({ value, onChange, min, max });

    return <TextField type="text" autoComplete="off" {...props} {...draft} />;
}
