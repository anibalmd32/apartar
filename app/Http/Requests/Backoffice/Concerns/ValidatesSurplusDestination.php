<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice\Concerns;

use App\Domain\Payment\SurplusDestination;
use App\Domain\Payment\SurplusDestinationType;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ValidatesSurplusDestination
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function surplusRules(): array
    {
        return [
            'surplus_destination' => ['nullable', 'string', Rule::enum(SurplusDestinationType::class)],
            'surplus_user_id' => ['nullable', 'integer'],
            'surplus_note' => ['nullable', 'string', 'max:'.SurplusDestination::NOTE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function surplusAttributes(): array
    {
        return [
            'surplus_destination' => 'destino del sobrante',
            'surplus_user_id' => 'persona del equipo',
            'surplus_note' => 'nota del destino',
        ];
    }

    /**
     * @throws ValidationException
     */
    protected function surplusDestination(): ?SurplusDestination
    {
        $raw = $this->string('surplus_destination')->trim()->toString();

        if ($raw === '') {
            return null;
        }

        $type = SurplusDestinationType::tryFrom($raw);

        if (! $type instanceof SurplusDestinationType) {
            throw ValidationException::withMessages(['surplus_destination' => 'Elige a dónde va lo que sobra.']);
        }

        $member = $type->requiresUser() ? $this->surplusMember() : null;
        $note = $this->string('surplus_note')->trim()->toString();

        if ($type->requiresNote() && $note === '') {
            throw ValidationException::withMessages(['surplus_note' => 'Escribe una nota corta que diga qué se hizo con el sobrante.']);
        }

        if (! $type->requiresNote() && $note !== '') {
            throw ValidationException::withMessages(['surplus_note' => 'Solo «Otro» lleva nota.']);
        }

        return new SurplusDestination($type, $member, $note === '' ? null : $note);
    }

    /**
     * @throws ValidationException
     */
    private function surplusMember(): ?User
    {
        $id = $this->input('surplus_user_id');

        if (! is_numeric($id)) {
            throw ValidationException::withMessages(['surplus_user_id' => 'Elige a qué persona del equipo va el sobrante.']);
        }

        $member = User::query()->find((int) $id);

        if (! $member instanceof User) {
            throw ValidationException::withMessages(['surplus_user_id' => 'Esa persona no es del equipo de este negocio.']);
        }

        if ($member->disabled_at !== null) {
            throw ValidationException::withMessages(['surplus_user_id' => 'Esa persona ya no tiene acceso: elige otra.']);
        }

        return $member;
    }
}
