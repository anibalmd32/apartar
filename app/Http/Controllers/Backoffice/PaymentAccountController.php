<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Organization\UpsertPaymentAccountAction;
use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\UpsertPaymentAccountRequest;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentAccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('managePaymentAccounts', $this->context->organization());

        return Inertia::render('backoffice/payment-accounts/Index', [
            'accounts' => $this->accounts(),
            'methods' => $this->methods(),
            'usage' => $this->limits->usageOf(UsageMetric::PaymentAccounts)->toArray(),
        ]);
    }

    public function store(UpsertPaymentAccountRequest $request, UpsertPaymentAccountAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $account = $upsert->handle($request->toData(), $actor);

        return $this->back('Cuenta de cobro guardada.', $upsert->warningsFor($account));
    }

    public function update(
        UpsertPaymentAccountRequest $request,
        PaymentAccount $account,
        UpsertPaymentAccountAction $upsert,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $account = $upsert->handle($request->toData(), $actor, $account);

        return $this->back('Cuenta de cobro actualizada.', $upsert->warningsFor($account));
    }

    public function destroy(Request $request, PaymentAccount $account, UpsertPaymentAccountAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $upsert->delete($account, $actor);
        } catch (PaymentAccountInUseException $exception) {
            return $this->back($exception->getMessage(), $upsert->warningsFor($exception->account));
        }

        return $this->back('Cuenta de cobro eliminada.');
    }

    /**
     * @param  list<string>  $warnings
     */
    private function back(string $status, array $warnings = []): RedirectResponse
    {
        return to_route('payment-accounts.index')
            ->with('status', trim($status.' '.implode(' ', $warnings)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        return PaymentAccount::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentAccount $account): array => PaymentAccountView::of($account)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function methods(): array
    {
        return array_map(fn (PaymentMethod $method): array => [
            'value' => $method->value,
            'label' => $method->label(),
            'identifierLabel' => $method->identifierLabel(),
            'isCash' => $method->isCash(),
        ], PaymentMethod::cases());
    }
}
