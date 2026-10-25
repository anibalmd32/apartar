<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Subscription\SubscriptionStatus;
use Illuminate\Http\Request;

final readonly class OrganizationDirectoryFilters
{
    public function __construct(
        public ?string $plan = null,
        public ?SubscriptionStatus $status = null,
        public bool $inactive = false,
        public bool $overdue = false,
        public bool $atLimit = false,
        public bool $includeClosed = false,
        public ?string $search = null,
        public int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $plan = $request->query('plan');
        $search = $request->query('q');

        return new self(
            plan: is_string($plan) && preg_match('/^[a-z0-9_-]{1,32}$/', $plan) === 1 ? $plan : null,
            status: SubscriptionStatus::tryFrom((string) $request->query('estado')),
            inactive: $request->boolean('inactivas'),
            overdue: $request->boolean('vencidas'),
            atLimit: $request->boolean('al_limite'),
            includeClosed: $request->boolean('cerradas'),
            search: is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 80) : null,
            page: max(1, min(1000, $request->integer('pagina', 1))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'estado' => $this->status?->value,
            'inactivas' => $this->inactive,
            'vencidas' => $this->overdue,
            'al_limite' => $this->atLimit,
            'cerradas' => $this->includeClosed,
            'q' => $this->search,
        ];
    }

    public function cacheKey(): string
    {
        return 'platform:directory:'.md5((string) json_encode([$this->toArray(), $this->page]));
    }
}
