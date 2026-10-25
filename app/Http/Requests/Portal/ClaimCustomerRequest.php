<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Actions\Account\ClaimCustomerAction;
use App\Domain\Account\ClaimVerificationFailedException;
use App\Domain\Account\CustomerAlreadyLinkedException;
use App\Models\Account;
use App\Models\Customer;
use App\Support\DocumentNumber;
use App\Support\InvalidDocumentNumberException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class ClaimCustomerRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 3600;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Escribe tu cédula o RIF.',
            'code.required' => 'Escribe el código de tu apartado.',
        ];
    }

    public function claim(ClaimCustomerAction $claim): Customer
    {
        $account = $this->account();
        $document = $this->document();

        $this->ensureIsNotRateLimited($account);

        try {
            return $claim->handle($account, $document, (string) $this->string('code'));
        } catch (ClaimVerificationFailedException $failure) {
            foreach ($this->throttleKeys($account) as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            Log::warning('portal.claim.failed', ['ip' => $this->ip(), 'reason' => $failure->reason]);

            throw ValidationException::withMessages(['code' => $failure->getMessage()]);
        } catch (CustomerAlreadyLinkedException $conflict) {
            Log::notice('portal.claim.conflict', ['reason' => $conflict->reason]);

            throw ValidationException::withMessages(['code' => $conflict->getMessage()]);
        }
    }

    /**
     * @return list<string>
     */
    public function throttleKeys(Account $account): array
    {
        return ['portal-claim|account:'.$account->getKey(), 'portal-claim|ip:'.($this->ip() ?? 'sin-ip')];
    }

    private function account(): Account
    {
        $account = Auth::guard('account')->user();

        if (! $account instanceof Account) {
            throw new LogicException('ClaimCustomerRequest va detrás de account-session.');
        }

        return $account;
    }

    private function document(): DocumentNumber
    {
        try {
            return DocumentNumber::fromInput((string) $this->string('document'));
        } catch (InvalidDocumentNumberException $invalid) {
            throw ValidationException::withMessages(['document' => $invalid->getMessage()]);
        }
    }

    private function ensureIsNotRateLimited(Account $account): void
    {
        $seconds = 0;

        foreach ($this->throttleKeys($account) as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        if ($seconds === 0) {
            return;
        }

        Log::warning('portal.claim.failed', ['ip' => $this->ip(), 'reason' => 'demasiados intentos']);

        abort(
            Response::HTTP_TOO_MANY_REQUESTS,
            __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ['Retry-After' => (string) $seconds],
        );
    }
}
