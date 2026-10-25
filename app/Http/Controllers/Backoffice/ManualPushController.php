<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Notification\ManualPushData;
use App\Actions\Notification\SendManualPushAction;
use App\Domain\Notification\NotifiableResolver;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\SendManualPushRequest;
use App\Models\Customer;
use App\Models\ManualPush;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Queries\CustomerSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ManualPushController extends Controller
{
    public const HISTORY = 10;

    public const SEARCH_LIMIT = 10;

    public function create(Request $request, LimitEnforcer $limits, NotifiableResolver $resolver): Response
    {
        $this->authorizeCustomers($request);

        return Inertia::render('backoffice/notifications/Compose', [
            'available' => $limits->hasFeature(PlanFeature::WebPush),
            'limits' => ['title' => ManualPushData::TITLE_MAX, 'body' => ManualPushData::BODY_MAX],
            'previewUrl' => route('notifications.preview'),
            'sendUrl' => route('notifications.send'),
            'recipientsUrl' => route('notifications.recipients'),
            'preselected' => $this->preselected($request, $resolver),
            'hourlyLimit' => AppServiceProvider::MANUAL_PUSHES_PER_HOUR,
            'history' => ManualPush::query()
                ->with('sender:id,organization_id,name')
                ->latest('id')
                ->limit(self::HISTORY)
                ->get()
                ->map(fn (ManualPush $push): array => [
                    'id' => $push->getKey(),
                    'title' => $push->title,
                    'mode' => $push->mode,
                    'recipients' => $push->recipients,
                    'queued' => $push->queued,
                    'sender' => $push->sender?->name,
                    'sentAt' => $push->created_at->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function recipients(Request $request, NotifiableResolver $resolver): JsonResponse
    {
        $this->authorizeCustomers($request);

        $search = CustomerSearch::from($request->query('q'));

        if (! $search instanceof CustomerSearch) {
            return response()->json(['customers' => []]);
        }

        $customers = $search->apply(Customer::query())
            ->orderBy('full_name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (Customer $customer): array => $this->option($customer, $resolver))
            ->all();

        return response()->json(['customers' => $customers]);
    }

    /**
     * @return array{id: int, name: string, detail: string|null, reachable: bool}|null
     */
    private function preselected(Request $request, NotifiableResolver $resolver): ?array
    {
        $id = filter_var($request->query('cliente'), FILTER_VALIDATE_INT);
        $customer = is_int($id) ? Customer::query()->find($id) : null;

        return $customer instanceof Customer ? $this->option($customer, $resolver) : null;
    }

    /**
     * @return array{id: int, name: string, detail: string|null, reachable: bool}
     */
    private function option(Customer $customer, NotifiableResolver $resolver): array
    {
        return [
            'id' => (int) $customer->getKey(),
            'name' => $customer->full_name,
            'detail' => $customer->phone,
            'reachable' => $resolver->for($customer)->pushSubscriptions()->exists(),
        ];
    }

    public function preview(SendManualPushRequest $request, SendManualPushAction $send): JsonResponse
    {
        return response()->json($send->preview($request->toData()));
    }

    public function store(SendManualPushRequest $request, SendManualPushAction $send): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $preview = $send->preview($request->toData());

        if ($preview['reachable'] === 0) {
            return response()->json(['message' => 'Ninguno de esos clientes tiene activados los avisos en su teléfono.', 'report' => $preview], 422);
        }

        return response()->json(['report' => $send->handle($request->toData(), $actor)], 202);
    }

    private function authorizeCustomers(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role->can(OrganizationCapability::Customers), 403);
    }
}
