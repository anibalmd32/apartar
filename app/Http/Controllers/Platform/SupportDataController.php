<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\Layaway\LayawayStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use Inertia\Inertia;
use Inertia\Response;

final class SupportDataController extends Controller
{
    public const LIMIT = 50;

    public function layaways(Organization $organization): Response
    {
        $rows = Layaway::query()->with('customer:id,full_name')
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Layaway $layaway): array => [
                'code' => $layaway->code,
                'customer' => $layaway->customer->full_name ?? '—',
                'product' => $layaway->product_name_snapshot,
                'status' => $layaway->status->value,
                'statusLabel' => $layaway->status->label(),
                'totalCents' => $layaway->total_cents->cents,
                'paidCents' => $layaway->paid_cents->cents,
                'remainingCents' => in_array($layaway->status, [LayawayStatus::Cancelled, LayawayStatus::Expired], true) ? null : $layaway->remaining_cents->cents,
                'createdAt' => $layaway->created_at?->toIso8601String(),
            ])->all();

        return Inertia::render('platform/support/Layaways', ['organization' => $this->summary($organization), 'layaways' => $rows]);
    }

    public function customers(Organization $organization): Response
    {
        $rows = Customer::query()->latest()->limit(self::LIMIT)->get(['id', 'full_name', 'document_number', 'phone', 'email', 'created_at'])
            ->map(fn (Customer $customer): array => [
                'fullName' => $customer->full_name,
                'documentNumber' => $customer->document_number,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'createdAt' => $customer->created_at?->toIso8601String(),
            ])->all();

        return Inertia::render('platform/support/Customers', ['organization' => $this->summary($organization), 'customers' => $rows]);
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function summary(Organization $organization): array
    {
        return ['id' => (int) $organization->getKey(), 'name' => $organization->name, 'slug' => $organization->slug];
    }
}
