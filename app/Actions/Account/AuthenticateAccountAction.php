<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Auth\AccountSession;
use App\Domain\Account\AccountProvider;
use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use App\Models\Account;
use App\Models\AccountIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class AuthenticateAccountAction
{
    private const ATTEMPTS = 3;

    public function __construct(private readonly AccountSession $session) {}

    public function handle(GoogleUserData $data): Account
    {
        $now = CarbonImmutable::now();

        try {
            $account = $this->findOrCreate($data, $now);
        } catch (UniqueConstraintViolationException) {
            $account = $this->findOrCreate($data, $now);
        }

        $this->session->open($account);

        if ($account->wasRecentlyCreated) {
            ServerTelemetry::record(TelemetryEventName::AccountCreated, payload: ['provider' => AccountProvider::Google->value], accountId: (int) $account->getKey());
        }

        return $account;
    }

    private function findOrCreate(GoogleUserData $data, CarbonImmutable $now): Account
    {
        return DB::transaction(function () use ($data, $now): Account {
            $profile = [
                'display_name' => $data->displayName,
                'email' => $data->email,
                'avatar_url' => $data->avatarUrl,
                'last_login_at' => $now,
            ];

            $identity = AccountIdentity::query()
                ->where('provider', AccountProvider::Google)
                ->where('provider_id', $data->providerId)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof AccountIdentity) {
                $account = Account::query()->lockForUpdate()->findOrFail($identity->account_id);
                $account->forceFill($profile)->save();
                $identity->forceFill(['email' => $data->email])->save();

                return $account;
            }

            $account = new Account;
            $account->forceFill($profile)->save();

            $account->identities()->create([
                'provider' => AccountProvider::Google,
                'provider_id' => $data->providerId,
                'email' => $data->email,
            ]);

            return $account;
        }, self::ATTEMPTS);
    }
}
