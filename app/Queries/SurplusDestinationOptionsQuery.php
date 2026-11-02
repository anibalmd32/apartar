<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Payment\SurplusDestinationType;
use App\Models\User;

final readonly class SurplusDestinationOptionsQuery
{
    /**
     * @return array<string, mixed>
     */
    public function for(): array
    {
        return [
            'destinations' => $this->destinations(),
            'teamMembers' => $this->teamMembers(),
        ];
    }

    /**
     * @return list<array{value: string, label: string, requiresNote: bool}>
     */
    private function destinations(): array
    {
        return array_map(fn (SurplusDestinationType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'requiresNote' => $type->requiresNote(),
        ], SurplusDestinationType::cases());
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function teamMembers(): array
    {
        return User::query()
            ->whereNull('disabled_at')
            ->whereNull('invited_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $member): array => [
                'id' => (int) $member->getKey(),
                'name' => $member->name,
            ])
            ->values()
            ->all();
    }
}
