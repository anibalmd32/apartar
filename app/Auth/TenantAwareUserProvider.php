<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Tenancy\TenantScope;
use App\Models\Customer;
use App\Models\CustomerMerge;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Proveedor de usuarios del guard `web`, capaz de encontrarlos **antes** de
 * que exista organización activa.
 *
 * Es la consecuencia directa de poner `BelongsToOrganization` en `User`: el
 * global scope exige un tenant fijado, y en el login todavía no lo hay. El
 * orden real es el contrario —la sesión trae al usuario y del usuario sale la
 * organización (`REQ-ORG-002` RB-4)—, así que la autenticación tiene que
 * consultar `users` sin el filtro.
 *
 * No es un agujero en el aislamiento, y la razón está escrita en la propia
 * lista blanca: `users.email` es **único en toda la plataforma**
 * (`GlobalUniqueExceptions`), precisamente para que un correo resuelva a un
 * único usuario sin saber la organización. Buscar sin filtro no puede
 * devolver el usuario de otro negocio por accidente porque no hay dos.
 *
 * Lo que sigue filtrado es todo lo demás: `User::query()` en el backoffice
 * —la lista del equipo, la asignación de un rol— pasa por el scope como
 * cualquier otra tabla del tenant.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * Consulta base del proveedor, sin el filtro por organización.
     *
     * Cubre las tres vías por las que Laravel llega a un usuario:
     * `retrieveById()` desde la sesión, `retrieveByCredentials()` en el login
     * y `retrieveByToken()` en el "recordarme". El broker de recuperación de
     * contraseña usa este mismo proveedor.
     *
     * @template TModel of Model
     *
     * @param  TModel|null  $model
     * @return Builder<TModel>
     */
    protected function newModelQuery($model = null)
    {
        // Equivale a `User::withoutTenantScope()`, y se escribe con
        // `withoutGlobalScope()` porque el proveedor es genérico sobre el
        // modelo del guard: el scope local solo existe en los modelos que
        // llevan el trait, y aquí el tipo es `Model`. Quitar un scope que no
        // está registrado no hace nada, así que es seguro con cualquier otro.
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }

    /**
     * El usuario de la sesión, y —solo en el guard `customer`— si su ficha se
     * borró al fusionarla, la ficha que la absorbió (`REQ-IDN-004` SP-2, SP-3,
     * `T-065`): la persona sigue dentro, con lo suyo, y nunca un `500`.
     *
     * Se resuelve aquí, sin organización fijada, por la misma razón que el
     * resto del proveedor: la sesión trae el id y del id sale el negocio. El
     * id de una ficha absorbida es único —es la clave de `customers`—, así que
     * buscarlo sin filtro no puede devolver la fusión de otro negocio.
     * `EnsurePortalSession` guarda después el id nuevo en la sesión.
     *
     * @param  mixed  $identifier
     */
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
     * El «recordarme», con la misma vuelta que `retrieveById()`: la cookie de
     * una ficha absorbida lleva a la superviviente si su token es **el que
     * tenía la absorbida** —guardado en su fusión—. Saber el id de una ficha
     * absorbida no abre nada.
     *
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
