import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type SurplusDestinationOption, type SurplusDestinationType, type SurplusTeamMember } from '@/types/payment';

export interface SurplusDestinationValues {
    readonly surplus_destination: string;
    readonly surplus_user_id: string;
    readonly surplus_note: string;
}

interface SurplusDestinationFieldsProps {
    readonly destinations: readonly SurplusDestinationOption[];
    readonly teamMembers: readonly SurplusTeamMember[];
    readonly values: SurplusDestinationValues;
    readonly errors: Partial<Record<keyof SurplusDestinationValues, string>>;
    readonly onChange: (patch: Partial<SurplusDestinationValues>) => void;
}

export function SurplusDestinationFields({ destinations, teamMembers, values, errors, onChange }: SurplusDestinationFieldsProps) {
    const type = destinations.find((option) => option.value === values.surplus_destination);
    const needsMember = type?.value === 'team_member';
    const needsNote = type?.requiresNote === true;

    return (
        <div className="flex flex-col gap-2">
            <SelectField
                label="¿A dónde va lo que sobra?"
                name="surplus_destination"
                options={[{ value: '', label: 'Sin asignar' }, ...destinations.map((option) => ({ value: option.value, label: option.label }))]}
                value={values.surplus_destination}
                onChange={(event) => {
                    const next = event.target.value;
                    const chosen = destinations.find((option) => option.value === next);

                    onChange({
                        surplus_destination: next,
                        surplus_user_id: chosen?.value === 'team_member' ? values.surplus_user_id : '',
                        surplus_note: chosen?.requiresNote === true ? values.surplus_note : '',
                    });
                }}
                error={errors.surplus_destination}
                hint="Opcional. Si lo dejas sin asignar, el sobrante sigue registrado en el historial del pago."
            />

            {needsMember ? (
                <SelectField
                    label="Persona del equipo"
                    name="surplus_user_id"
                    options={[
                        { value: '', label: 'Elige a quién' },
                        ...teamMembers.map((member) => ({ value: String(member.id), label: member.name })),
                    ]}
                    value={values.surplus_user_id}
                    onChange={(event) => onChange({ surplus_user_id: event.target.value })}
                    error={errors.surplus_user_id}
                />
            ) : null}

            {needsNote ? (
                <TextField
                    label="Nota del destino (obligatoria)"
                    name="surplus_note"
                    value={values.surplus_note}
                    onChange={(event) => onChange({ surplus_note: event.target.value })}
                    error={errors.surplus_note}
                    hint="Corta: qué se hizo con ese sobrante."
                    maxLength={500}
                    required
                />
            ) : null}
        </div>
    );
}

export function emptySurplusDestination(): SurplusDestinationValues {
    return { surplus_destination: '', surplus_user_id: '', surplus_note: '' };
}

export function isSurplusDestinationType(value: string): value is SurplusDestinationType {
    return ['team_member', 'tip', 'petty_cash', 'other'].includes(value);
}
