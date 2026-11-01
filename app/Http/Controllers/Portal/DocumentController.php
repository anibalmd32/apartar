<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Customer\CompleteCustomerDocumentAction;
use App\Domain\Customer\CustomerAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Rules\ValidDocumentNumber;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class DocumentController extends Controller
{
    public const TYPED_DOCUMENT = 'portal.typed_document';

    public const TAKEN_MESSAGE = 'No pudimos guardar esa cédula. Revisa que esté bien escrita; si lo está, escríbele a %s para que la agregue a tu ficha.';

    public function create(Request $request): Response|RedirectResponse
    {
        $customer = $this->customer();

        if ($customer === null || $customer->document_number !== null) {
            return redirect()->intended(route('portal.dashboard'));
        }

        $typed = $request->session()->get(self::TYPED_DOCUMENT);

        return Inertia::render('portal/Document', [
            'customerName' => $customer->full_name,
            'businessName' => $this->businessName($customer),
            'typed' => is_string($typed) ? $typed : '',
            'storeUrl' => route('portal.document.store'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request, CompleteCustomerDocumentAction $complete): RedirectResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return redirect()->route('portal.access');
        }

        $validated = $request->validate(
            ['document' => ['required', 'string', 'max:20', new ValidDocumentNumber]],
            ['document.required' => 'Escribe tu cédula o RIF para continuar.'],
        );

        try {
            Auth::guard('customer')->setUser($complete->handle($customer, DocumentNumber::fromInput((string) $validated['document'])));
        } catch (CustomerAlreadyExistsException) {
            Log::notice('portal.document.taken', ['customer' => $customer->getKey()]);

            throw ValidationException::withMessages(['document' => sprintf(self::TAKEN_MESSAGE, $this->businessName($customer))]);
        }

        $request->session()->forget(self::TYPED_DOCUMENT);

        return redirect()->intended(route('portal.dashboard'));
    }

    private function customer(): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }

    private function businessName(Customer $customer): string
    {
        return (string) Organization::query()->whereKey($customer->organization_id)->value('name');
    }
}
