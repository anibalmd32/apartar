<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Customer\MergeCustomersAction;
use App\Domain\Customer\CannotMergeCustomerException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Queries\CustomerSearch;
use App\Support\DocumentNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CustomerMergeController extends Controller
{
    public const CANDIDATES = 5;

    public function __construct(private readonly MergeCustomersAction $merge) {}

    public function candidates(Request $request, Customer $customer): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $customer);

        $search = CustomerSearch::from($request->query('q'));

        if (! $search instanceof CustomerSearch) {
            return response()->json(['candidates' => []]);
        }

        $candidates = $search->apply(Customer::query())
            ->whereKeyNot($customer->getKey())
            ->orderBy('full_name')
            ->limit(self::CANDIDATES)
            ->get()
            ->map(fn (Customer $candidate): array => [
                'id' => (int) $candidate->getKey(),
                'name' => $candidate->full_name,
                'document' => DocumentNumber::tryFromInput($candidate->document_number)?->formatted() ?? $candidate->document_number,
                'phone' => $candidate->phone,
                'moves' => $this->merge->preview($customer, $candidate)->toArray(),
                'blocked' => $customer->account_id !== null && $candidate->account_id !== null && (int) $customer->account_id !== (int) $candidate->account_id,
            ])
            ->all();

        return response()->json(['candidates' => $candidates]);
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validate([
            'absorbed' => ['required', 'integer'],
            'confirmation' => ['required', 'string', 'max:'.CustomerContactData::MAX_NAME_LENGTH],
        ]);

        $absorbed = Customer::query()->find((int) $validated['absorbed']);

        try {
            if (! $absorbed instanceof Customer) {
                throw CannotMergeCustomerException::gone();
            }

            Gate::forUser($actor)->authorize('merge', [Customer::class, $customer, $absorbed]);

            if (! $this->confirms((string) $validated['confirmation'], $absorbed)) {
                throw CannotMergeCustomerException::unconfirmed();
            }

            $summary = $this->merge->handle($customer, $absorbed, $actor);
        } catch (CannotMergeCustomerException $exception) {
            return back()->withErrors(['merge' => $exception->getMessage()]);
        }

        return to_route('customers.show', $customer)->with('status', sprintf(
            'Fichas fusionadas: %d %s y %d %s pasaron a esta ficha.',
            $summary->layaways,
            $summary->layaways === 1 ? 'apartado' : 'apartados',
            $summary->receipts,
            $summary->receipts === 1 ? 'comprobante' : 'comprobantes',
        ));
    }

    private function confirms(string $typed, Customer $absorbed): bool
    {
        if ($absorbed->document_number === null) {
            return self::comparableName($typed) === self::comparableName($absorbed->full_name);
        }

        return DocumentNumber::tryFromInput($typed)?->value === $absorbed->document_number;
    }

    private static function comparableName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($name))));
    }
}
