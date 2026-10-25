<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Tenancy\TenantScope;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * @template TModel of Model
     *
     * @param  TModel|null  $model
     * @return Builder<TModel>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @param  mixed  $identifier
     */
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;

        if (is_a($this->model, User::class, true) && is_string($email)) {
            return app(TeamMemberships::class)->preferredFor($email);
        }

        return parent::retrieveByCredentials($credentials);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        $user = parent::retrieveById($identifier);

        if ($user !== null || ! is_a($this->model, Customer::class, true) || ! is_numeric($identifier)) {
            return $user;
        }

        $survivor = CustomerMerge::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('absorbed_customer_id', (int) $identifier)
            ->value('survivor_customer_id');

        return is_numeric($survivor) ? parent::retrieveById((int) $survivor) : null;
    }

    /**
     * @param  mixed  $identifier
     * @param  string  $token
     */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        $user = parent::retrieveByToken($identifier, $token);

        if ($user !== null || ! is_a($this->model, Customer::class, true) || ! is_numeric($identifier) || $token === '') {
            return $user;
        }

        $merge = CustomerMerge::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('absorbed_customer_id', (int) $identifier)
            ->first(['survivor_customer_id', 'absorbed_remember_token']);

        if (! $merge instanceof CustomerMerge || ! is_string($merge->absorbed_remember_token) || ! hash_equals($merge->absorbed_remember_token, $token)) {
            return null;
        }

        return parent::retrieveById($merge->survivor_customer_id);
    }
}
